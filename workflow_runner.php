<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// =================================================================
// Workflows — the half that runs. Called from run_ai_agent_jobs.php on every tick, and
// by the admin Test button through wiki_workflow_preview(), which renders the same
// actions without performing any of them.
//
// Every write an action makes goes through the ordinary functions — PageIndexer,
// wiki_chat_write(), run_agent_job() → execute_ai_tool() — with the workflow set as
// actor and origin, so realtime push, the audit log, git and the read-only guard all
// see it the way they see any other write, and the loop rule in workflows.php sees
// where it came from.
// =================================================================

require_once __DIR__ . '/workflows.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/space_settings.php';
require_once __DIR__ . '/service_auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/mentions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/ai_core.php';

const WIKI_WF_MAX_PER_TICK = 10;
// How much of an AI reply a run keeps in its History detail. The whole reply is in the
// run's log; History is a table, and a page-long review in one cell pushes every other run
// off the screen — and is stored again in workflow_queue.json for every run kept.
const WIKI_WF_DETAIL_BYTES = 1024;

function wiki_wf_users(): array {
    $f = rtrim(WIKI_SYSTEM_DATA, '/') . '/users.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d['users'] ?? null) ? $d['users'] : [];
}

/**
 * A user by uid, of the kind asked for: `$ai` true for an AI user, false for anyone else.
 *
 * The kind is not decoration. A uid is meant to be unique, but a wiki can hold a person
 * and an AI user with the same one — older account code allocated them separately — and
 * the first match in users.json then answered for both: an AI action reported "the AI user
 * no longer exists" because uid 2 found a person first, and the owner check could as
 * easily find an AI editor and switch an admin's workflow off.
 */
function wiki_wf_user(int $uid, bool $ai): ?array {
    foreach (wiki_wf_users() as $u) {
        if ((int)($u['uid'] ?? PHP_INT_MIN) === $uid && !empty($u['is_ai']) === $ai) return $u;
    }
    return null;
}

/** The values a template can name, for one queued run. */
function wiki_wf_vars(array $wf, array $run): array {
    $path  = (string)($run['path'] ?? '');
    $space = (string)($run['space'] ?? '');
    $url   = '';
    $base  = (string)($run['base_url'] ?? '') ?: wiki_wf_base_url();
    // The stable id, which survives a later rename; resolved now if the event did not
    // carry one. A deleted page has none left to resolve.
    $pid = '';
    if (($run['event'] ?? '') !== 'page_deleted') {
        $pid = (string)($run['page_id'] ?? '');
        if ($pid === '' && $path !== '') $pid = (string)(wiki_wf_index_entry($space, $path)['id'] ?? '');
    }
    if ($base !== '' && ($run['event'] ?? '') !== 'page_deleted') {
        $url = $pid !== ''
            ? $base . '/index.php?pageid=' . rawurlencode($pid) . ($space !== '' ? '&space=' . rawurlencode($space) : '')
            : $base . '/';
    }
    $words = ['page_created' => 'created', 'page_updated' => 'updated', 'page_deleted' => 'deleted',
              'page_renamed' => 'renamed', 'tag_added' => 'tagged', 'tag_removed' => 'untagged',
              'fm_changed' => 'changed'];
    return [
        'workflow'  => (string)($wf['name'] ?? ''),
        'event'     => $words[$run['event'] ?? ''] ?? (string)($run['event'] ?? ''),
        'page'      => pathinfo($path, PATHINFO_FILENAME),
        'path'      => $path,
        'page_id'   => $pid,
        // The page's chat thread by the sidecar convention, whether or not it exists yet;
        // empty for anything that is not a Markdown page, which has no page chat.
        'page_chat' => (string)wiki_page_chat_sidecar($path),
        'old_path'  => (string)($run['old_path'] ?? ''),
        'space'     => $space,
        'url'       => $url,
        'actor'     => (string)($run['actor']['name'] ?? '') ?: 'someone',
        'tags'      => implode(', ', $run['tags'] ?? []),
        'field'     => (string)($run['field'] ?? ''),
        'old_value' => (string)($run['old_value'] ?? ''),
        'new_value' => (string)($run['new_value'] ?? ''),
        'date'      => date('Y-m-d H:i'),
    ];
}

/**
 * At most WIKI_WF_DETAIL_BYTES of $text, cut on a character boundary, with "..." when
 * anything was dropped. Bytes rather than characters because the point is the size of
 * what is stored; mb_strcut never splits a multibyte character.
 */
function wiki_wf_clip(string $text): string {
    if (strlen($text) <= WIKI_WF_DETAIL_BYTES) return $text;
    return rtrim(mb_strcut($text, 0, WIKI_WF_DETAIL_BYTES, 'UTF-8')) . '...';
}

/** `{{name}}` → value. Unknown names are left as written, so a typo is visible. */
function wiki_wf_render(string $tpl, array $vars): string {
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars) {
        $k = strtolower($m[1]);
        return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
    }, $tpl);
}

/** Plain text to email HTML: escaped, line breaks kept, bare URLs made links. */
function wiki_wf_text_html(string $text): string {
    $h = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('#\bhttps?://[^\s<]+#', '<a href="$0">$0</a>', $h);
    return '<div style="font-family:sans-serif;font-size:14px;line-height:1.5">' . nl2br($h) . '</div>';
}

/** Who an email action reaches: [email => name], deduplicated. */
function wiki_wf_email_recipients(array $a, array $run): array {
    // People only, keyed by uid: an AI user sharing a uid must not shadow the person
    // (see wiki_wf_user), and an AI user is never mailed anyway.
    $users = [];
    foreach (wiki_wf_users() as $u) if (empty($u['is_ai'])) $users[(int)($u['uid'] ?? 0)] = $u;
    $uids = array_map('intval', $a['to_users'] ?? []);
    if (!empty($a['to_actor']) && isset($run['actor']['uid']) && $run['actor']['uid'] !== null) {
        $uids[] = (int)$run['actor']['uid'];
    }
    if (!empty($a['to_author'])) {
        $entry = wiki_wf_index_entry((string)($run['space'] ?? ''), (string)($run['path'] ?? ''));
        if (isset($entry['createdBy']['uid'])) $uids[] = (int)$entry['createdBy']['uid'];
    }
    $out = [];
    foreach (array_unique($uids) as $uid) {
        $u = $users[$uid] ?? null;
        if (!$u || !empty($u['is_ai'])) continue;
        $e = wiki_user_notify_email($u);
        if ($e !== '') $out[strtolower($e)] = [$e, (string)($u['name'] ?? '')];
    }
    foreach (preg_split('/[,;\s]+/', (string)($a['to_emails'] ?? '')) as $e) {
        $e = trim($e);
        if ($e !== '' && !isset($out[strtolower($e)])) $out[strtolower($e)] = [$e, ''];
    }
    return array_values($out);
}

function wiki_wf_index_entry(string $space, string $path): ?array {
    $ix = new PageIndexer(wiki_wf_space_dir($space));
    $id = $ix->getId($path);
    return $id === null ? null : (($ix->getAllPages()[$id] ?? null) + ['id' => $id]);
}

/**
 * The page's own text for an AI instruction — bounded, and the body only unless exposed —
 * plus its id, its page chat, and where the reply is going to be posted.
 *
 * The id is there so the model can write a `?pageid=` link, which survives a rename. The
 * page chat is named whether or not it exists, because the model cannot know the sidecar
 * convention; and when the action already posts the reply, the model is told so, or a
 * capable one also posts it itself with wiki_post_chat and the thread gets it twice.
 */
function wiki_wf_page_context(array $run, array $action = []): string {
    $space = (string)($run['space'] ?? '');
    $path  = (string)($run['path'] ?? '');
    $vars  = wiki_wf_vars(['name' => $run['workflow_name'] ?? ''], $run);
    $out = "This run was started by the wiki workflow \"{$vars['workflow']}\", because the page \"{$path}\""
         . ($space !== '' ? " in the space \"{$space}\"" : '') . " was {$vars['event']} by {$vars['actor']}.";
    // Keyed on what the run carries rather than its event: a run that waited can have
    // absorbed a field change while being named after the save that started it.
    if (($run['old_path'] ?? '') !== '') $out .= " Its previous path was \"{$vars['old_path']}\".";
    if (($run['field'] ?? '') !== '') {
        $out .= " Its front-matter field \"{$vars['field']}\" changed from \"{$vars['old_value']}\" to \"{$vars['new_value']}\".";
    }
    if (!empty($run['tags'])) $out .= " Tags involved: {$vars['tags']}.";
    if ($vars['page_id'] !== '') $out .= " Its page id is {$vars['page_id']}.";
    if ($vars['page_chat'] !== '' && ($run['event'] ?? '') !== 'page_deleted') {
        $chat_abs = wiki_wf_space_dir($space) . '/' . $vars['page_chat'];
        $out .= is_file($chat_abs)
            ? " The page's chat thread is \"{$vars['page_chat']}\"."
            : " The page has no chat thread yet; wiki_post_chat would create \"{$vars['page_chat']}\".";
    }
    if (!empty($action['page_chat']) && $vars['page_chat'] !== '') {
        $out .= " Your final reply will be posted in the page's chat thread \"{$vars['page_chat']}\" for you"
              . " (created if needed), so write it as that message and do not post it there yourself.";
    } elseif ((string)($action['chat'] ?? '') !== '') {
        $to = _wiki_wf_rel(wiki_wf_render((string)$action['chat'], $vars));
        $out .= " Your final reply will be posted in the chat thread \"{$to}\" for you, so do not post it there yourself.";
    }
    $abs = wiki_wf_space_dir($space) . '/' . $path;
    if (($run['event'] ?? '') !== 'page_deleted' && is_file($abs)
        && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'json', 'list'], true)) {
        $raw = (string)@file_get_contents($abs);
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'md' && !wiki_fm_expose_to_ai()) $raw = wiki_fm_body($raw);
        $cut = mb_strlen($raw) > 12000;
        $out .= " Its full path (use this exact value with the wiki tools) is \"{$path}\". Its current content"
              . ($cut ? ' (the first 12000 characters; read the page for the rest)' : '') . ":\n\n```\n"
              . mb_substr($raw, 0, 12000) . "\n```\n";
    }
    return $out . "\n\n";
}

/**
 * The thread an action posts into: the page's own chat, created if nobody has opened it
 * yet, or the named thread with its `{{…}}` filled in. Returns null — with the reason in
 * `$why` — when the page has no chat to post in: it is not Markdown, or it is gone.
 *
 * @return array{rel: string, abs: string, created: bool}|null
 */
function wiki_wf_thread(array $a, array $run, array $vars, ?array $as, ?string &$why = null): ?array {
    $space = (string)($run['space'] ?? '');
    $sd    = wiki_wf_space_dir($space);
    if (!empty($a['page_chat'])) {
        $path = (string)($run['path'] ?? '');
        if (wiki_page_chat_sidecar($path) === null) { $why = 'The page is not a Markdown page, so it has no page chat.'; return null; }
        if (($run['event'] ?? '') === 'page_deleted' || !is_file($sd . '/' . $path)) { $why = 'The page no longer exists.'; return null; }
        return wiki_page_chat_ensure($sd, $path, new PageIndexer($sd), $as);
    }
    // Rendered, then cleaned again: a value such as {{old_path}} is the page's own text
    // and could carry anything _wiki_wf_rel strips from a stored path.
    $rel = _wiki_wf_rel(wiki_wf_render((string)($a['chat'] ?? ''), $vars));
    if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'chat') throw new Exception("\"{$rel}\" is not a .chat thread.");
    return ['rel' => $rel, 'abs' => $sd . '/' . $rel, 'created' => false];
}

/** Append a message to a .chat thread, the way agent_job_deliver_to_chat() writes one. */
function wiki_wf_post_chat(array $thread, string $text, int $uid, string $name): void {
    wiki_chat_append($thread['abs'], $thread['rel'], $text, $uid, $name, ['workflow' => true]);
}

/**
 * Perform one action. Returns ['status' => ok|skipped, 'detail' => …]; throws on failure.
 * $ctx: wf, run, vars, readonly, log (by reference, for the run log file).
 */
function wiki_wf_do_action(array $a, array $ctx, string &$log): array {
    $run   = $ctx['run'];
    $vars  = $ctx['vars'];
    $space = (string)($run['space'] ?? '');
    $path  = (string)($run['path'] ?? '');
    $wf_name = (string)($ctx['wf']['name'] ?? 'Workflow');
    $writes  = in_array($a['type'], ['ai', 'chat', 'tag', 'frontmatter'], true);
    if ($writes && $ctx['readonly']) {
        return ['status' => 'skipped', 'detail' => 'The space is read-only.'];
    }

    switch ($a['type']) {
        case 'email': {
            if (!is_mail_configured()) throw new Exception('Email is not configured on this wiki.');
            $to = wiki_wf_email_recipients($a, $run);
            if (!$to) return ['status' => 'skipped', 'detail' => 'No recipient has an email address.'];
            $subject = str_replace(["\r", "\n"], ' ', wiki_wf_render((string)$a['subject'], $vars));
            $body    = wiki_wf_text_html(wiki_wf_render((string)($a['body'] ?? ''), $vars));
            $failed = [];
            foreach ($to as [$email, $name]) {
                if (!send_email($email, $name, $subject, $body)) $failed[] = $email;
            }
            if ($failed) throw new Exception('Sending failed for ' . implode(', ', $failed) . '.');
            return ['status' => 'ok', 'detail' => 'Sent to ' . implode(', ', array_column($to, 0)) . '.'];
        }

        case 'ai': {
            $ai = wiki_wf_user((int)$a['ai_uid'], true);
            if (!$ai) throw new Exception('The AI user no longer exists.');
            $owner = $ctx['wf']['created_by'] ?? [];
            $job = [
                'id'           => $run['id'],
                'prompt'       => wiki_wf_render((string)$a['prompt'], $vars),
                'space'        => $space,
                'page_context' => wiki_wf_page_context($run, $a),
                // Who the run is on behalf of, in the prompt and in the audit log.
                'requested_by' => ['uid' => $owner['uid'] ?? null, 'name' => 'Workflow "' . $wf_name . '"'],
            ];
            $sd = wiki_wf_space_dir($space);
            $beat = function_exists('agent_job_touch_heartbeat') ? 'agent_job_touch_heartbeat' : null;
            $res = run_agent_job($job, $ai, new PageIndexer($sd), $sd, $beat);
            $log .= "\n--- AI action (" . ($ai['name'] ?? 'AI') . ")\nPROMPT:\n" . $job['prompt'] . "\n\n"
                  . (!empty($res['error']) ? "ERROR:\n" . $res['error'] : "RESULT:\n" . (string)($res['reply'] ?? '')) . "\n"
                  . (!empty($res['debug']) ? "\n" . $res['debug'] . "\n" : '');
            if (!empty($res['error'])) throw new Exception((string)$res['error']);
            $reply = trim((string)($res['reply'] ?? ''));
            $detail = wiki_wf_clip($reply !== '' ? $reply : '(no reply)');
            // The thread is resolved only now, once there is something to post, so a run
            // that produced nothing does not leave an empty page chat behind.
            if ((($a['chat'] ?? '') !== '' || !empty($a['page_chat'])) && $reply !== '') {
                $as = ['uid' => (int)$ai['uid'], 'name' => (string)($ai['name'] ?? 'AI')];
                $why = null;
                $thread = wiki_wf_thread($a, $run, $vars, $as, $why);
                if ($thread === null) return ['status' => 'ok', 'detail' => 'Reply not posted: ' . $why . "\n\n" . $detail];
                wiki_wf_post_chat($thread, $reply, $as['uid'], $as['name']);
            }
            return ['status' => 'ok', 'detail' => $detail];
        }

        case 'chat': {
            $text = wiki_wf_render((string)$a['text'], $vars);
            $as = (int)($a['as_uid'] ?? 0);
            $ai = $as !== 0 ? wiki_wf_user($as, true) : null;
            $who = ['uid' => $ai ? (int)$ai['uid'] : 0, 'name' => $ai ? (string)($ai['name'] ?? 'AI') : $wf_name];
            $why = null;
            $thread = wiki_wf_thread($a, $run, $vars, $ai ? $who : null, $why);
            if ($thread === null) return ['status' => 'skipped', 'detail' => $why];
            wiki_wf_post_chat($thread, $text, $who['uid'], $who['name']);
            return ['status' => 'ok', 'detail' => 'Posted in ' . $thread['rel'] . ($thread['created'] ? ' (created it).' : '.')];
        }

        case 'tag': {
            $ix = new PageIndexer(wiki_wf_space_dir($space));
            $id = $ix->getId($path);
            if ($id === null) return ['status' => 'skipped', 'detail' => 'The page no longer exists.'];
            $cur = $ix->getAllPages()[$id]['tags'] ?? [];
            $drop = array_map('wiki_wf_lc', $a['remove'] ?? []);
            $next = array_values(array_filter($cur, fn($t) => !in_array(wiki_wf_lc((string)$t), $drop, true)));
            foreach ($a['add'] ?? [] as $t) {
                if (!in_array(wiki_wf_lc($t), array_map('wiki_wf_lc', $next), true)) $next[] = $t;
            }
            if ($next === array_values($cur)) return ['status' => 'ok', 'detail' => 'Tags were already as asked.'];
            $ix->updateTags($id, $next);
            wiki_audit_log('tag', 'success', ['object_category' => 'tags', 'object' => $path,
                                              'object_id' => (string)$id, 'space' => $space]);
            return ['status' => 'ok', 'detail' => 'Tags: ' . implode(', ', $next)];
        }

        case 'frontmatter': {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') {
                return ['status' => 'skipped', 'detail' => 'Only Markdown pages carry front matter.'];
            }
            $sd  = wiki_wf_space_dir($space);
            $abs = $sd . '/' . $path;
            if (!is_file($abs)) return ['status' => 'skipped', 'detail' => 'The page no longer exists.'];
            if (wiki_space_dir_fm_autostamp($sd) === 'on'
                && in_array(strtolower($a['field']), array_map('strtolower', WIKI_FM_STAMP_KEYS), true)) {
                throw new Exception("\"{$a['field']}\" is maintained by the wiki in this space.");
            }
            if (in_array(strtolower($a['field']), array_map('strtolower', wiki_fm_structured_keys((string)file_get_contents($abs))), true)) {
                throw new Exception("\"{$a['field']}\" holds a list or nested value on this page.");
            }
            $raw = (string)file_get_contents($abs);
            $value = wiki_wf_render((string)$a['value'], $vars);
            // A rendered template may name something the field does not allow.
            if ($value !== '') $value = (string)wiki_tax_field_check((string)$a['field'], $value);
            $new = $value === '' ? wiki_fm_set($raw, [], [$a['field']]) : wiki_fm_set($raw, [$a['field'] => $value]);
            if ($new === $raw) return ['status' => 'ok', 'detail' => 'The field already had that value.'];
            if (file_put_contents($abs, $new) === false) throw new Exception('Could not write the page.');
            $ix = new PageIndexer($sd);
            $ix->updateModified($path, 0, 'Workflow: ' . $wf_name);
            git_auto_commit($abs, 'Workflow: ' . $wf_name, 'workflow@wiki.localhost',
                            'Workflow "' . $wf_name . '": set ' . $a['field'] . ' on ' . basename($path), $sd);
            wiki_audit_log('update', 'success', ['object_category' => 'page', 'object' => $path, 'space' => $space]);
            return ['status' => 'ok', 'detail' => $value === '' ? "Removed {$a['field']}." : "{$a['field']}: {$value}"];
        }
    }
    throw new Exception('Unknown action.');
}

/** Why a workflow may not run at all right now, or ''. Its owner must still be an admin. */
function wiki_wf_owner_problem(array $wf): string {
    if (!defined('AUTHENTICATION_ENABLED') || !AUTHENTICATION_ENABLED) return '';
    $uid = $wf['created_by']['uid'] ?? null;
    if ($uid === null) return '';
    $u = wiki_wf_user((int)$uid, false);
    if (!$u) return 'Its owner no longer exists.';
    if (($u['role'] ?? '') !== 'admin') return 'Its owner is no longer an administrator.';
    return '';
}

/**
 * Run whatever is due. Returns log lines for the cron output.
 */
function wiki_workflow_run_due(int $max = WIKI_WF_MAX_PER_TICK): array {
    $lines = [];
    if (!is_file(wiki_wf_path('workflow_queue.json'))) return $lines;

    // Orphans first: a run left 'running' whose process died.
    wiki_wf_queue_mutate(function (array &$runs) {
        foreach ($runs as &$r) {
            if (($r['state'] ?? '') !== 'running') continue;
            $age = (time() - (int)strtotime((string)($r['started_at'] ?? ''))) / 60;
            if ($age < WIKI_WF_RUNNING_TIMEOUT_MIN) continue;
            $r['state'] = 'error';
            $r['error'] = 'The run did not finish — the runner stopped before it completed.';
            $r['finished_at'] = date('c');
        }
        unset($r);
    });

    $batch = wiki_wf_queue_mutate(function (array &$runs) use ($max) {
        $take = [];
        $now = time();
        usort($runs, fn($a, $b) => strcmp((string)($a['due_at'] ?? ''), (string)($b['due_at'] ?? '')));
        foreach ($runs as &$r) {
            if (count($take) >= $max) break;
            if (($r['state'] ?? '') !== 'queued' || (strtotime((string)($r['due_at'] ?? '')) ?: 0) > $now) continue;
            $r['state'] = 'running';
            $r['started_at'] = date('c');
            $take[] = $r;
        }
        unset($r);
        return $take;
    }) ?? [];

    foreach ($batch as $run) {
        $lines[] = wiki_workflow_execute($run);
    }
    return $lines;
}

/**
 * Tell every administrator that a run failed, so an open browser can say so at once
 * (modules/workflow_watch). A hint only, like every publish: the toast's content comes
 * from `admin_workflow_failures`, which is admin-only, and the poll there finds the
 * failure anyway when no hub is running. Administrators, because workflows are theirs —
 * only they can open the log or change the workflow.
 */
function wiki_wf_announce_failure(array $run): void {
    if (!function_exists('wiki_realtime_publish')) return;
    foreach (wiki_wf_users() as $u) {
        if (($u['role'] ?? '') !== 'admin' || !empty($u['is_ai']) || !empty($u['is_system'])) continue;
        $uid = (int)($u['uid'] ?? 0);
        if ($uid > 0) wiki_realtime_publish(wiki_rt_topic_workflow($uid), ['type' => 'workflow', 'id' => (string)($run['id'] ?? '')]);
    }
}

/** Run one claimed queue entry to completion and record the outcome. */
function wiki_workflow_execute(array $run): string {
    $wf = wiki_workflow_get((string)$run['workflow_id']);
    $results = [];
    $state = 'ok';
    $error = null;
    $log = '';

    if (!$wf) {
        $state = 'skipped'; $error = 'The workflow was deleted.';
    } elseif (empty($wf['enabled']) && empty($run['manual'])) {
        // A manual run is how an admin tries a workflow before switching it on.
        $state = 'skipped'; $error = 'The workflow is switched off.';
    } elseif (($why = wiki_wf_owner_problem($wf)) !== '') {
        $state = 'skipped'; $error = $why . ' The workflow has been switched off.';
        wiki_wf_record($wf['id'], ['enabled' => false, 'disabled_reason' => $why]);
    } elseif (empty($run['manual'])) {
        // Rate limit: finished runs of this workflow in the last hour.
        $hour = time() - 3600;
        $recent = 0;
        foreach (wiki_wf_queue_read() as $r) {
            if (($r['workflow_id'] ?? '') === $wf['id'] && in_array($r['state'] ?? '', ['ok', 'error'], true)
                && empty($r['manual'])
                && (strtotime((string)($r['finished_at'] ?? '')) ?: 0) >= $hour) $recent++;
        }
        if ($recent >= (int)($wf['max_per_hour'] ?? WIKI_WF_DEFAULT_PER_HOUR)) {
            $state = 'skipped';
            $error = 'Rate limit: ' . $recent . ' runs in the last hour.';
        }
    }

    if ($state === 'ok') {
        $ctx = [
            'wf'       => $wf,
            'run'      => $run,
            'vars'     => wiki_wf_vars($wf, $run),
            'readonly' => wiki_space_is_readonly((string)($run['space'] ?? '')),
        ];
        // Everything written from here on carries this workflow in its origin chain.
        wiki_workflow_set_origin(['chain' => array_merge($run['chain'] ?? [], [$wf['id']]),
                                  'depth' => (int)($run['depth'] ?? 0) + 1]);
        wiki_workflow_set_actor(['uid' => 0, 'name' => 'Workflow: ' . ($wf['name'] ?? ''), 'is_ai' => false]);
        wiki_audit_set_context(['user' => 'Workflow: ' . ($wf['name'] ?? ''), 'via' => 'workflow',
                                'requested_by' => $wf['created_by']['name'] ?? null]);
        try {
            foreach ($wf['actions'] as $i => $a) {
                try {
                    $results[] = ['type' => $a['type']] + wiki_wf_do_action($a, $ctx, $log);
                } catch (\Throwable $e) {
                    $results[] = ['type' => $a['type'], 'status' => 'error', 'detail' => $e->getMessage()];
                    $state = 'error';
                    $error = 'Action ' . ($i + 1) . ' (' . $a['type'] . '): ' . $e->getMessage();
                    break;   // a sequence: what follows may depend on what failed
                }
                // execute_ai_tool() re-points the actor at the AI user; take it back.
                wiki_workflow_set_actor(['uid' => 0, 'name' => 'Workflow: ' . ($wf['name'] ?? ''), 'is_ai' => false]);
            }
        } finally {
            wiki_workflow_set_origin(null);
            wiki_workflow_set_actor(null);
            wiki_audit_set_context([]);
        }
        if ($state === 'ok' && $results && !array_filter($results, fn($r) => $r['status'] !== 'skipped')) {
            $state = 'skipped';
            $error = $results[0]['detail'] ?? null;
        }
    }

    // A failed run always gets a log, because the failure toast links to it — and most
    // actions write nothing to $log, so without this an email or chat action that failed
    // would have only the one-line error to show. The per-action outcome goes last, after
    // whatever the AI action recorded, since that is what the reader came for.
    if ($state === 'error') {
        $log .= "\n--- Outcome\n";
        foreach ($results as $i => $r) {
            $log .= 'Action ' . ($i + 1) . ' (' . $r['type'] . '): ' . $r['status']
                  . (($r['detail'] ?? '') !== '' ? ' — ' . $r['detail'] : '') . "\n";
        }
        $log .= 'ERROR: ' . $error . "\n";
    }

    $log_file = null;
    if ($log !== '' && defined('LOG_DIR') && LOG_DIR) {
        $dir = rtrim(LOG_DIR, '/') . '/workflows/' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$run['workflow_id']) . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $log_file = $dir . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)$run['id']) . '.log';
        @file_put_contents($log_file, '[' . date('c') . '] Workflow: ' . ($wf['name'] ?? '?') . "\n"
            . '[' . date('c') . '] Page: ' . ($run['space'] ?? '') . '/' . ($run['path'] ?? '') . "\n"
            . '[' . date('c') . '] Status: ' . $state . "\n" . $log);
    }

    $finished = date('c');
    wiki_wf_queue_mutate(function (array &$runs) use ($run, $state, $error, $results, $finished, $log_file) {
        foreach ($runs as &$r) {
            if (($r['id'] ?? '') !== $run['id']) continue;
            $r['state'] = $state;
            $r['error'] = $error;
            $r['results'] = $results;
            $r['finished_at'] = $finished;
            if ($log_file) $r['log_file'] = $log_file;
            break;
        }
        unset($r);
    });
    if ($state === 'error') wiki_wf_announce_failure($run);

    // A manual run is a test: it must not push a workflow toward its automatic switch-off.
    if ($wf && $state !== 'skipped' && empty($run['manual'])) {
        $failures = $state === 'error' ? (int)($wf['stats']['failures'] ?? 0) + 1 : 0;
        $patch = ['stats' => ['last_run' => $finished, 'last_status' => $state, 'last_error' => $error,
                              'failures' => $failures]];
        if ($failures >= WIKI_WF_FAIL_DISABLE) {
            $patch['enabled'] = false;
            $patch['disabled_reason'] = "{$failures} failed runs in a row.";
            wiki_wf_alert_disabled($wf, $patch['disabled_reason'], (string)$error);
        }
        wiki_wf_record($wf['id'], $patch);
    }

    return date('c') . " [workflows] '" . ($run['workflow_name'] ?? '?') . "' on " . ($run['path'] ?? '?')
         . ": {$state}" . ($error ? " — {$error}" : '');
}

/** Merge a patch into one stored workflow (stats are merged, not replaced). */
function wiki_wf_record(string $id, array $patch): void {
    wiki_workflows_mutate(function (array &$list) use ($id, $patch) {
        foreach ($list as &$wf) {
            if (($wf['id'] ?? '') !== $id) continue;
            if (isset($patch['stats'])) {
                $wf['stats'] = array_merge($wf['stats'] ?? [], $patch['stats']);
                unset($patch['stats']);
            }
            $wf = array_merge($wf, $patch);
            break;
        }
        unset($wf);
    });
}

function wiki_wf_alert_disabled(array $wf, string $why, string $error): void {
    if (!defined('ADMIN_EMAIL') || !ADMIN_EMAIL || !is_mail_configured()) return;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    send_email(ADMIN_EMAIL, 'Admin', APP_TITLE . ' — Workflow switched off: ' . ($wf['name'] ?? ''),
        '<h2>Workflow switched off</h2>'
        . '<p><strong>Workflow:</strong> ' . $h($wf['name'] ?? '') . '</p>'
        . '<p><strong>Why:</strong> ' . $h($why) . '</p>'
        . '<p><strong>Last error:</strong></p><pre style="background:#fff5f5;padding:0.8rem;border-radius:4px">'
        . $h($error) . '</pre>'
        . '<p>Fix the cause, then switch it back on under Admin → Content → Workflows.</p>');
}

/**
 * The Test button: what this workflow would do for one page, without doing it.
 * $wf may be unsaved (the form's current state). Nothing is written or sent.
 */
/**
 * A queue entry as if the trigger had just fired for this page — what the Test preview
 * renders and what Run now executes. Values the event itself would carry (the old path,
 * the previous field value) do not exist, so they are filled with a visible stand-in.
 */
function wiki_wf_synthetic_run(array $wf, string $space, string $path, array $actor): array {
    $entry = $path !== '' ? wiki_wf_index_entry($space, $path) : null;
    // With several triggers the preview is the first, but carries every chosen trigger's
    // stand-ins, so each variable the actions use shows something rather than nothing.
    $types = wiki_wf_trigger_types($wf) ?: ['page_updated'];
    $run = [
        'id' => 'preview', 'workflow_id' => (string)($wf['id'] ?? ''), 'workflow_name' => $wf['name'] ?? '',
        'space' => $space, 'path' => $path, 'event' => $types[0], 'page_id' => (string)($entry['id'] ?? ''),
        'actor' => $actor, 'base_url' => wiki_wf_base_url(), 'chain' => [], 'depth' => 0,
    ];
    if (in_array('page_renamed', $types, true)) $run['old_path'] = '(previous path)';
    if (array_intersect(['tag_added', 'tag_removed'], $types)) {
        $run['tags'] = [($wf['trigger']['tag'] ?? '') !== '' ? $wf['trigger']['tag'] : 'example'];
    }
    if (in_array('fm_changed', $types, true)) {
        $run['field'] = $wf['trigger']['field'] ?? '';
        $run['old_value'] = '(previous value)';
        $run['new_value'] = ($wf['trigger']['value'] ?? '') !== ''
            ? $wf['trigger']['value'] : (string)wiki_wf_fm_value($space, $path, (string)$run['field']);
    }
    return $run;
}

/**
 * Run now: the saved workflow, for real, against one page.
 *
 * Without an AI action it runs inside this request, so the admin sees the outcome at
 * once. An AI action can take longer than a web request is allowed to, so then it is only
 * queued — due immediately — and the runner picks it up on its next tick. Either way it
 * goes through the queue, so it appears in History like any other run.
 *
 * @return array the run record as it stands afterwards (state queued, or finished)
 */
function wiki_workflow_run_now(array $wf, string $space, string $path, array $actor): array {
    $run = ['id' => 'wr_' . bin2hex(random_bytes(6)), 'manual' => true, 'events' => 1,
            'created_at' => date('c'), 'due_at' => date('c'), 'state' => 'queued']
         + wiki_wf_synthetic_run($wf, $space, _wiki_wf_rel($path), $actor);
    $inline = !in_array('ai', array_column($wf['actions'] ?? [], 'type'), true);
    if ($inline) { $run['state'] = 'running'; $run['started_at'] = date('c'); }
    wiki_wf_queue_mutate(function (array &$runs) use ($run) { $runs[] = $run; });
    if (!$inline) return $run;

    wiki_workflow_execute($run);
    foreach (wiki_wf_queue_read() as $r) if (($r['id'] ?? '') === $run['id']) return $r;
    return $run;
}

/**
 * Which thread an action would post into for the Test preview, and what to warn about —
 * resolved as wiki_wf_thread() resolves it, but never creating anything.
 *
 * @return array{0: string, 1: string[]} the thread's path ('' for none) and warning codes
 */
function wiki_wf_preview_thread(array $a, string $space, string $path, array $vars): array {
    $sd = wiki_wf_space_dir($space);
    if (!empty($a['page_chat'])) {
        $rel = wiki_page_chat_sidecar($path);
        if ($rel === null) return ['', ['no_page_chat']];
        return [$rel, is_file($sd . '/' . $rel) ? [] : ['page_chat_new']];
    }
    if ((string)($a['chat'] ?? '') === '') return ['', []];
    $rel = _wiki_wf_rel(wiki_wf_render((string)$a['chat'], $vars));
    return [$rel, is_file($sd . '/' . $rel) ? [] : ['chat_missing']];
}

function wiki_workflow_preview(array $wf, string $space, string $path, array $actor): array {
    $path  = _wiki_wf_rel($path);
    $abs   = wiki_wf_space_dir($space) . '/' . $path;
    $entry = $path !== '' ? wiki_wf_index_entry($space, $path) : null;
    $run   = wiki_wf_synthetic_run($wf, $space, $path, $actor);
    $vars = wiki_wf_vars($wf, $run);
    $out = [
        'page_exists' => $path !== '' && is_file($abs),
        'filter_miss' => wiki_workflow_filter_miss($wf, $space, $path, $entry['tags'] ?? [], $actor),
        'readonly'    => wiki_space_is_readonly($space),
        'actions'     => [],
    ];
    foreach ($wf['actions'] ?? [] as $a) {
        $p = ['type' => $a['type'], 'warnings' => []];
        switch ($a['type']) {
            case 'email':
                $p['to'] = array_column(wiki_wf_email_recipients($a, $run), 0);
                $p['subject'] = wiki_wf_render((string)($a['subject'] ?? ''), $vars);
                $p['body'] = wiki_wf_render((string)($a['body'] ?? ''), $vars);
                if (!is_mail_configured()) $p['warnings'][] = 'mail_not_configured';
                if (!$p['to']) $p['warnings'][] = 'no_recipients';
                break;
            case 'ai':
                $ai = wiki_wf_user((int)($a['ai_uid'] ?? 0), true);
                $p['ai_user'] = $ai['name'] ?? '';
                $p['prompt'] = wiki_wf_render((string)($a['prompt'] ?? ''), $vars);
                if (!$ai) $p['warnings'][] = 'ai_missing';
                [$p['chat'], $tw] = wiki_wf_preview_thread($a, $space, $path, $vars);
                $p['warnings'] = array_merge($p['warnings'], $tw);
                break;
            case 'chat':
                [$p['chat'], $p['warnings']] = wiki_wf_preview_thread($a, $space, $path, $vars);
                $p['text'] = wiki_wf_render((string)($a['text'] ?? ''), $vars);
                break;
            case 'tag':
                $p['add'] = $a['add'] ?? [];
                $p['remove'] = $a['remove'] ?? [];
                break;
            case 'frontmatter':
                $p['field'] = $a['field'] ?? '';
                $p['value'] = wiki_wf_render((string)($a['value'] ?? ''), $vars);
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') $p['warnings'][] = 'not_markdown';
                break;
        }
        $out['actions'][] = $p;
    }
    return $out;
}
