<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// =================================================================
// Workflows — "when this happens to a page, do that".
//
// This file is the half that runs inside a write: the store, the trigger matching and
// the queue. It is loaded by indexer.php, because PageIndexer is the one funnel every
// content write already passes through (realtime push hooks it for the same reason),
// so a workflow cannot miss a write path the way a hook in each api.php action would.
//
// **A write never runs an action.** It only appends to WIKI_SYSTEM_DATA/
// workflow_queue.json; the cron runner (run_ai_agent_jobs.php → workflow_runner.php)
// does the work. A save must not fail, or wait, because an email bounced or an LLM was
// slow — and wiki_workflow_event() swallows every error for the same reason.
//
// Three things exist to stop automation running away with itself:
//  - **Loops.** Writes made while a workflow runs carry its origin chain. A workflow
//    never fires on a write it caused, and a chain stops at WIKI_WF_MAX_DEPTH.
//  - **Bursts.** `update` fires on every save. A queued run for the same workflow and
//    page absorbs later events until the page has been quiet for `debounce` seconds.
//  - **Cost.** `max_per_hour` per workflow; WIKI_WF_FAIL_DISABLE failures in a row
//    switch it off. AI actions also sit under the runner's slot ceiling.
//
// Changes made *outside* the wiki (git pull, rsync → index_sync) never trigger: one
// pull can touch hundreds of files. Neither do bulk index operations — a folder rename,
// a space merge — which do not announce individual pages.
// =================================================================

require_once __DIR__ . '/frontmatter.php';

const WIKI_WF_TRIGGERS = ['page_created', 'page_updated', 'page_deleted', 'page_renamed',
                          'tag_added', 'tag_removed', 'fm_changed'];
const WIKI_WF_ACTIONS  = ['email', 'ai', 'chat', 'tag', 'frontmatter'];
const WIKI_WF_TYPES    = ['md', 'list', 'chat', 'json', 'drawio', 'search'];

const WIKI_WF_MAX_DEPTH        = 3;     // workflow → write → workflow → …
const WIKI_WF_DEFAULT_DEBOUNCE = 120;   // seconds of quiet before a run
const WIKI_WF_MAX_DEBOUNCE     = 86400;
const WIKI_WF_DEFAULT_PER_HOUR = 20;
const WIKI_WF_MAX_PER_HOUR     = 500;
const WIKI_WF_MAX_ACTIONS      = 10;
const WIKI_WF_FAIL_DISABLE     = 5;     // consecutive failed runs before it switches off
const WIKI_WF_HISTORY_DAYS     = 14;
const WIKI_WF_HISTORY_KEEP     = 1000;
const WIKI_WF_RUNNING_TIMEOUT_MIN = 60;

// --- Files ---------------------------------------------------------------------

function wiki_wf_path(string $name): string {
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/' . $name;
}

/**
 * Read-modify-write a JSON file under an exclusive lock — the same shape as
 * agent_job_queue_mutate(), because the web side and the runner both write these files.
 * The callback takes the decoded data by reference and may return a value.
 */
function wiki_wf_locked(string $file, callable $fn, array $empty) {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    $path = wiki_wf_path($file);
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($data)) $data = $empty;
        $before = $data;
        $ret = $fn($data);
        if ($data !== $before) {
            @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return $ret;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Every workflow, enabled or not. Cached per process; the writers refresh it. */
function wiki_workflows_all(bool $reload = false): array {
    static $cache = null;
    if ($reload) $cache = null;
    if ($cache !== null) return $cache;
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return $cache = [];
    $f = wiki_wf_path('workflows.json');
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return $cache = is_array($d['workflows'] ?? null) ? $d['workflows'] : [];
}

function wiki_workflow_get(string $id): ?array {
    foreach (wiki_workflows_all() as $wf) if (($wf['id'] ?? '') === $id) return $wf;
    return null;
}

/** Mutate the workflow list under its lock. The callback gets the list by reference. */
function wiki_workflows_mutate(callable $fn) {
    $ret = wiki_wf_locked('workflows.json', function (array &$d) use ($fn) {
        if (!is_array($d['workflows'] ?? null)) $d['workflows'] = [];
        return $fn($d['workflows']);
    }, ['workflows' => []]);
    wiki_workflows_all(true);
    return $ret;
}

function wiki_wf_queue_read(): array {
    $f = wiki_wf_path('workflow_queue.json');
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d['runs'] ?? null) ? $d['runs'] : [];
}

/** Finished runs older than the history window, or past the cap, are dropped. */
function _wiki_wf_prune(array $runs): array {
    $cutoff = time() - WIKI_WF_HISTORY_DAYS * 86400;
    $live = $done = [];
    foreach ($runs as $r) {
        if (in_array($r['state'] ?? '', ['queued', 'running'], true)) { $live[] = $r; continue; }
        if ((strtotime((string)($r['finished_at'] ?? '')) ?: 0) >= $cutoff) $done[] = $r;
    }
    usort($done, fn($a, $b) => strcmp((string)($b['finished_at'] ?? ''), (string)($a['finished_at'] ?? '')));
    return array_values(array_merge($live, array_slice($done, 0, WIKI_WF_HISTORY_KEEP)));
}

function wiki_wf_queue_mutate(callable $fn) {
    return wiki_wf_locked('workflow_queue.json', function (array &$d) use ($fn) {
        $runs = is_array($d['runs'] ?? null) ? $d['runs'] : [];
        $ret  = $fn($runs);
        $d['runs'] = _wiki_wf_prune($runs);
        return $ret;
    }, ['runs' => []]);
}

// --- Who is acting, and why ------------------------------------------------------

/**
 * The actor of the write in progress. execute_ai_tool() sets it to the AI user — the
 * one point every AI write passes through, inline chat replies included, which would
 * otherwise be attributed to the person whose message started them — and the runner
 * sets it to the workflow for its own writes. Otherwise it is the request's own actor.
 */
function wiki_workflow_set_actor(?array $actor): void {
    $GLOBALS['_wiki_wf_actor'] = $actor;
}

function wiki_workflow_actor(): array {
    $a = $GLOBALS['_wiki_wf_actor'] ?? null;
    if (is_array($a)) return $a + ['uid' => null, 'name' => null, 'is_ai' => false];
    $out = ['uid' => null, 'name' => null, 'is_ai' => false];
    if (function_exists('get_current_actor')) {
        $c = get_current_actor();
        $out['uid']  = $c['uid'] ?? null;
        $out['name'] = $c['name'] ?? null;
        $tok = $GLOBALS['ai_auth_user'] ?? null;
        if (is_array($tok) && !empty($tok['is_ai'])) $out['is_ai'] = true;
    }
    return $out;
}

/** Set by the runner around a workflow's actions; see the loop rule above. */
function wiki_workflow_set_origin(?array $origin): void {
    $GLOBALS['_wiki_wf_origin'] = $origin;
}

function wiki_workflow_origin(): ?array {
    $o = $GLOBALS['_wiki_wf_origin'] ?? null;
    return is_array($o) ? $o : null;
}

/**
 * The wiki's own origin, for links in what a run sends. Read from the request while
 * there is one, because the cron runner has none; APP_BASE_URL (or the OIDC redirect URI)
 * is the fallback, as in run_daily_digest.php.
 */
function wiki_wf_base_url(): string {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    if (defined('APP_BASE_URL') && APP_BASE_URL) return rtrim(APP_BASE_URL, '/');
    if (defined('OIDC_REDIRECT_URI') && OIDC_REDIRECT_URI) return rtrim(dirname(OIDC_REDIRECT_URI), '/');
    return '';
}

// --- Matching -----------------------------------------------------------------------

function wiki_wf_space_dir(string $space): string {
    $root = rtrim(PAGES_DIR, '/');
    return $space === '' ? $root : $root . '/' . $space;
}

function wiki_wf_lc(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/** A front-matter value as one comparable string — a list joins with ", ". */
function wiki_wf_fm_value(string $space, string $path, string $field): ?string {
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') return null;
    $abs = wiki_wf_space_dir($space) . '/' . $path;
    if (!is_file($abs)) return null;
    $meta = wiki_fm_split((string)@file_get_contents($abs))['meta'] ?? [];
    $want = wiki_wf_lc($field);
    foreach ($meta as $k => $v) {
        if (wiki_wf_lc((string)$k) !== $want) continue;
        return is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string)$v);
    }
    return '';
}

/**
 * Does this workflow's scope cover this page? The trigger type is checked by the caller.
 * Returns '' when it matches, or the name of the first filter that excludes it — the
 * Test button reports that, so an admin sees *why* a page would not trigger.
 */
function wiki_workflow_filter_miss(array $wf, string $space, string $path, array $page_tags, array $actor): string {
    $ws = (string)($wf['space'] ?? '');
    if ($ws !== '*' && $ws !== $space) return 'space';
    $folder = trim((string)($wf['filters']['folder'] ?? ''), '/');
    if ($folder !== '' && strpos($path . '/', $folder . '/') !== 0) return 'folder';
    $types = $wf['filters']['types'] ?? [];
    if ($types && !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $types, true)) return 'types';
    $has = trim((string)($wf['filters']['has_tag'] ?? ''));
    if ($has !== '' && !in_array(wiki_wf_lc($has), array_map('wiki_wf_lc', $page_tags), true)) return 'has_tag';
    if (!empty($wf['filters']['exclude_ai']) && !empty($actor['is_ai'])) return 'exclude_ai';
    return '';
}

/**
 * Something happened to a page. Called by PageIndexer after the index write; never
 * throws, never blocks on anything but two small locked files.
 *
 * @param string $event  create | update | delete | rename | tags
 * @param array  $info   id, tags, old_path (rename), added/removed (tags)
 */
function wiki_workflow_event(string $space, string $event, string $path, array $info = []): void {
    try {
        _wiki_workflow_event($space, $event, ltrim($path, '/'), $info);
    } catch (\Throwable $e) {
        error_log('[workflows] event ' . $event . ' ' . $path . ': ' . $e->getMessage());
    }
}

function _wiki_workflow_event(string $space, string $event, string $path, array $info): void {
    if ($path === '' || !defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return;

    // A rename must carry anything already queued for the old path along with it, and
    // the front-matter snapshots too — whether or not any workflow wants the rename.
    if ($event === 'rename' && !empty($info['old_path'])) {
        wiki_wf_follow_rename($space, (string)$info['old_path'], $path);
    }

    $enabled = array_filter(wiki_workflows_all(), fn($w) => !empty($w['enabled']));
    if (!$enabled) return;

    $actor  = wiki_workflow_actor();
    $origin = wiki_workflow_origin();
    $chain  = $origin['chain'] ?? [];
    $depth  = (int)($origin['depth'] ?? 0);
    $tags   = is_array($info['tags'] ?? null) ? $info['tags'] : [];

    foreach ($enabled as $wf) {
        $id   = (string)($wf['id'] ?? '');
        $type = (string)($wf['trigger']['type'] ?? '');
        if ($id === '') continue;

        $detail = [];
        switch ($type) {
            case 'page_created': if ($event !== 'create') continue 2; break;
            case 'page_updated': if ($event !== 'update') continue 2; break;
            case 'page_deleted': if ($event !== 'delete') continue 2; break;
            case 'page_renamed':
                if ($event !== 'rename') continue 2;
                $detail['old_path'] = (string)($info['old_path'] ?? '');
                break;
            case 'tag_added':
            case 'tag_removed':
                if ($event !== 'tags') continue 2;
                $changed = $info[$type === 'tag_added' ? 'added' : 'removed'] ?? [];
                $want = trim((string)($wf['trigger']['tag'] ?? ''));
                if ($want !== '') {
                    $changed = array_values(array_filter($changed, fn($t) => wiki_wf_lc((string)$t) === wiki_wf_lc($want)));
                }
                if (!$changed) continue 2;
                $detail['tags'] = array_values($changed);
                break;
            case 'fm_changed':
                if (!in_array($event, ['create', 'update', 'delete'], true)) continue 2;
                // The snapshot is kept whatever the filters say, so a page that comes into
                // scope later is compared against what it really was.
                $fm = wiki_wf_fm_transition($wf, $space, $path, $event);
                if ($fm === null) continue 2;
                $detail += $fm;
                break;
            default:
                continue 2;
        }

        if (wiki_workflow_filter_miss($wf, $space, $path, $tags, $actor) !== '') continue;
        // The loop rule: not on a write this workflow caused, however indirectly, and not
        // past the depth limit for chains across workflows.
        if (in_array($id, $chain, true) || $depth >= WIKI_WF_MAX_DEPTH) continue;

        wiki_wf_enqueue($wf, [
            'space'    => $space,
            'path'     => $path,
            'event'    => $type,
            'page_id'  => isset($info['id']) ? (string)$info['id'] : '',
            'actor'    => ['uid' => $actor['uid'], 'name' => $actor['name'], 'is_ai' => !empty($actor['is_ai'])],
            'chain'    => $chain,
            'depth'    => $depth,
            'base_url' => wiki_wf_base_url(),
        ] + $detail);
    }
}

/**
 * Has a watched front-matter field moved? Compares against the value recorded the last
 * time this workflow looked at the page, and records the new one.
 *
 * The previous value cannot be read at this point — the file has already been written —
 * so it is remembered instead, in workflow_state.json. wiki_workflow_seed_fm() fills that
 * in for every existing page when the workflow is saved, so the first edit after creating
 * a workflow is compared against something real. A page the snapshot has never seen (one
 * that arrived from outside the wiki) is recorded silently rather than fired on.
 *
 * @return array|null ['field','old_value','new_value'] when it fires
 */
function wiki_wf_fm_transition(array $wf, string $space, string $path, string $event): ?array {
    $field = trim((string)($wf['trigger']['field'] ?? ''));
    if ($field === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') return null;
    $key   = $space . "\x1f" . $path;
    $wf_id = (string)$wf['id'];
    $now   = $event === 'delete' ? null : wiki_wf_fm_value($space, $path, $field);

    $prev = wiki_wf_locked('workflow_state.json', function (array &$d) use ($wf_id, $key, $now, $event) {
        $known = is_array($d[$wf_id] ?? null) && array_key_exists($key, $d[$wf_id]);
        $old   = $known ? (string)$d[$wf_id][$key] : null;
        if ($event === 'delete') unset($d[$wf_id][$key]);
        else $d[$wf_id][$key] = (string)$now;
        return ['known' => $known, 'old' => $old];
    }, []);
    if ($event === 'delete' || $now === null) return null;

    // A brand-new page starts from "no value"; anything else unseen is only recorded.
    if (!$prev['known'] && $event !== 'create') return null;
    $old = $prev['known'] ? (string)$prev['old'] : '';
    if ($old === $now) return null;

    $to = trim((string)($wf['trigger']['value'] ?? ''));
    if ($to !== '' && wiki_wf_lc($now) !== wiki_wf_lc($to)) return null;
    return ['field' => $field, 'old_value' => $old, 'new_value' => $now];
}

/** Record every in-scope page's current value, so the first real change is seen as one. */
function wiki_workflow_seed_fm(array $wf): int {
    if (($wf['trigger']['type'] ?? '') !== 'fm_changed') return 0;
    $field = trim((string)($wf['trigger']['field'] ?? ''));
    if ($field === '') return 0;
    $spaces = ($wf['space'] ?? '') === '*' ? wiki_wf_list_spaces() : [(string)($wf['space'] ?? '')];
    $snap = [];
    foreach ($spaces as $space) {
        $dir = wiki_wf_space_dir($space);
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (count($snap) >= 20000) break 2;
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'md') continue;
            $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
            // Skip dot-directories (.git) and the per-page upload folders.
            if (preg_match('#(^|/)\.|\.uploads/#', $rel)) continue;
            $snap[$space . "\x1f" . $rel] = (string)wiki_wf_fm_value($space, $rel, $field);
        }
    }
    $id = (string)$wf['id'];
    wiki_wf_locked('workflow_state.json', function (array &$d) use ($id, $snap) { $d[$id] = $snap; }, []);
    return count($snap);
}

function wiki_wf_forget_state(string $id): void {
    wiki_wf_locked('workflow_state.json', function (array &$d) use ($id) { unset($d[$id]); }, []);
}

/** Queued runs and front-matter snapshots follow a page to its new path. */
function wiki_wf_follow_rename(string $space, string $old, string $new): void {
    if (is_file(wiki_wf_path('workflow_queue.json'))) {
        wiki_wf_queue_mutate(function (array &$runs) use ($space, $old, $new) {
            foreach ($runs as &$r) {
                if (($r['state'] ?? '') === 'queued' && ($r['space'] ?? '') === $space && ($r['path'] ?? '') === $old) {
                    $r['path'] = $new;
                }
            }
            unset($r);
        });
    }
    if (is_file(wiki_wf_path('workflow_state.json'))) {
        $ok = $space . "\x1f" . $old;
        $nk = $space . "\x1f" . $new;
        wiki_wf_locked('workflow_state.json', function (array &$d) use ($ok, $nk) {
            foreach ($d as &$snap) {
                if (is_array($snap) && array_key_exists($ok, $snap)) { $snap[$nk] = $snap[$ok]; unset($snap[$ok]); }
            }
            unset($snap);
        }, []);
    }
}

/**
 * Queue a run, or fold this event into one already waiting for the same workflow and
 * page. Each fold pushes the run back to `debounce` seconds after the latest event, so
 * ten saves in a row are one run, made once the page has gone quiet.
 */
function wiki_wf_enqueue(array $wf, array $entry): void {
    $debounce = max(0, (int)($wf['debounce'] ?? WIKI_WF_DEFAULT_DEBOUNCE));
    $now = time();
    wiki_wf_queue_mutate(function (array &$runs) use ($wf, $entry, $debounce, $now) {
        foreach ($runs as &$r) {
            if (($r['state'] ?? '') !== 'queued' || ($r['workflow_id'] ?? '') !== $wf['id']
                || ($r['space'] ?? '') !== $entry['space'] || ($r['path'] ?? '') !== $entry['path']) continue;
            $r['due_at']  = date('c', $now + $debounce);
            $r['events']  = (int)($r['events'] ?? 1) + 1;
            $r['actor']   = $entry['actor'];
            if (!empty($entry['tags'])) {
                $r['tags'] = array_values(array_unique(array_merge($r['tags'] ?? [], $entry['tags'])));
            }
            // Front matter: keep where it started, follow where it ended up.
            if (array_key_exists('new_value', $entry)) $r['new_value'] = $entry['new_value'];
            if (($r['page_id'] ?? '') === '' && $entry['page_id'] !== '') $r['page_id'] = $entry['page_id'];
            if ($entry['base_url'] !== '') $r['base_url'] = $entry['base_url'];
            return;
        }
        unset($r);
        $runs[] = [
            'id'            => 'wr_' . bin2hex(random_bytes(6)),
            'workflow_id'   => $wf['id'],
            'workflow_name' => (string)($wf['name'] ?? ''),
            'state'         => 'queued',
            'events'        => 1,
            'created_at'    => date('c', $now),
            'due_at'        => date('c', $now + $debounce),
        ] + $entry;
    });
}

// --- Definitions: validation ----------------------------------------------------------

function wiki_wf_list_spaces(): array {
    $out = [];
    foreach ((array)@scandir(PAGES_DIR) as $s) {
        if ($s === '' || $s === '.' || $s === '..' || $s[0] === '.') continue;
        if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $s)) $out[] = $s;
    }
    sort($out);
    return $out;
}

function _wiki_wf_str($v, int $max): string {
    return mb_substr(trim(str_replace("\0", '', (string)$v)), 0, $max);
}

function _wiki_wf_tags($v): array {
    if (is_string($v)) $v = preg_split('/[,\s]+/', $v);
    $out = [];
    foreach ((array)$v as $t) {
        $t = ltrim(_wiki_wf_str($t, 100), '#');
        if ($t !== '') $out[] = $t;
    }
    return array_values(array_unique($out));
}

/** A relative path inside a space, or ''. */
function _wiki_wf_rel($v): string {
    $p = trim(str_replace(['\\', "\0"], ['/', ''], (string)$v));
    $p = preg_replace('#/+#', '/', $p);
    $parts = array_filter(explode('/', $p), fn($s) => $s !== '' && $s !== '.' && $s !== '..');
    return implode('/', $parts);
}

/**
 * Turn a posted definition into a stored one, or throw with a message for the admin.
 * Unknown keys are dropped; every limit is enforced here, not trusted from the form.
 */
function wiki_workflow_normalize(array $in, array $ai_uids, array $user_uids): array {
    $name = _wiki_wf_str($in['name'] ?? '', 120);
    if ($name === '') throw new Exception('A workflow needs a name.');

    $space = (string)($in['space'] ?? '');
    if ($space !== '*' && $space !== '' && !in_array($space, wiki_wf_list_spaces(), true)) {
        throw new Exception('Unknown space: ' . $space);
    }

    $tt = (string)($in['trigger']['type'] ?? '');
    if (!in_array($tt, WIKI_WF_TRIGGERS, true)) throw new Exception('Choose what starts the workflow.');
    $trigger = ['type' => $tt];
    if ($tt === 'tag_added' || $tt === 'tag_removed') {
        $trigger['tag'] = ltrim(_wiki_wf_str($in['trigger']['tag'] ?? '', 100), '#');
    }
    if ($tt === 'fm_changed') {
        $field = _wiki_wf_str($in['trigger']['field'] ?? '', 100);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\- ]*$/', $field)) throw new Exception('Name the front-matter field to watch.');
        $trigger['field'] = $field;
        $trigger['value'] = _wiki_wf_str($in['trigger']['value'] ?? '', 500);
    }

    $f = is_array($in['filters'] ?? null) ? $in['filters'] : [];
    $filters = [
        'folder'     => _wiki_wf_rel($f['folder'] ?? ''),
        'types'      => array_values(array_intersect(WIKI_WF_TYPES, array_map('strval', (array)($f['types'] ?? [])))),
        'has_tag'    => ltrim(_wiki_wf_str($f['has_tag'] ?? '', 100), '#'),
        'exclude_ai' => !empty($f['exclude_ai']),
    ];

    $actions = [];
    foreach (array_slice((array)($in['actions'] ?? []), 0, WIKI_WF_MAX_ACTIONS) as $i => $a) {
        if (!is_array($a)) continue;
        $n = $i + 1;
        $at = (string)($a['type'] ?? '');
        switch ($at) {
            case 'email':
                $emails = [];
                foreach (preg_split('/[,;\s]+/', (string)($a['to_emails'] ?? '')) as $e) {
                    $e = trim($e);
                    if ($e === '') continue;
                    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new Exception("Action {$n}: not an email address: {$e}");
                    $emails[] = $e;
                }
                $users = array_values(array_intersect(array_map('intval', (array)($a['to_users'] ?? [])), $user_uids));
                $act = [
                    'type'      => 'email',
                    'to_users'  => $users,
                    'to_author' => !empty($a['to_author']),
                    'to_actor'  => !empty($a['to_actor']),
                    'to_emails' => implode(', ', array_slice(array_unique($emails), 0, 50)),
                    'subject'   => _wiki_wf_str($a['subject'] ?? '', 300),
                    'body'      => mb_substr((string)($a['body'] ?? ''), 0, 20000),
                ];
                if (!$act['to_users'] && !$act['to_author'] && !$act['to_actor'] && !$act['to_emails']) {
                    throw new Exception("Action {$n}: choose at least one recipient.");
                }
                if ($act['subject'] === '') throw new Exception("Action {$n}: the email needs a subject.");
                break;
            case 'ai':
                $uid = (int)($a['ai_uid'] ?? 0);
                if (!in_array($uid, $ai_uids, true)) throw new Exception("Action {$n}: choose an AI user.");
                $prompt = mb_substr(trim((string)($a['prompt'] ?? '')), 0, 20000);
                if ($prompt === '') throw new Exception("Action {$n}: the AI user needs an instruction.");
                $chat = _wiki_wf_rel($a['chat'] ?? '');
                if ($chat !== '' && strtolower(pathinfo($chat, PATHINFO_EXTENSION)) !== 'chat') {
                    throw new Exception("Action {$n}: the reply thread must be a .chat page.");
                }
                $act = ['type' => 'ai', 'ai_uid' => $uid, 'prompt' => $prompt, 'chat' => $chat];
                break;
            case 'chat':
                $chat = _wiki_wf_rel($a['chat'] ?? '');
                if ($chat === '' || strtolower(pathinfo($chat, PATHINFO_EXTENSION)) !== 'chat') {
                    throw new Exception("Action {$n}: choose a .chat page to post in.");
                }
                $text = mb_substr(trim((string)($a['text'] ?? '')), 0, 10000);
                if ($text === '') throw new Exception("Action {$n}: the chat message is empty.");
                $as = (int)($a['as_uid'] ?? 0);
                $act = ['type' => 'chat', 'chat' => $chat, 'text' => $text,
                        'as_uid' => in_array($as, $ai_uids, true) ? $as : 0];
                break;
            case 'tag':
                $act = ['type' => 'tag', 'add' => _wiki_wf_tags($a['add'] ?? []), 'remove' => _wiki_wf_tags($a['remove'] ?? [])];
                if (!$act['add'] && !$act['remove']) throw new Exception("Action {$n}: name a tag to add or remove.");
                break;
            case 'frontmatter':
                $field = _wiki_wf_str($a['field'] ?? '', 100);
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\- ]*$/', $field)) throw new Exception("Action {$n}: name the field to set.");
                $value = _wiki_wf_str($a['value'] ?? '', 2000);
                if (preg_match('/[\r\n]/', $value)) throw new Exception("Action {$n}: a value cannot contain a line break.");
                $act = ['type' => 'frontmatter', 'field' => $field, 'value' => $value];
                break;
            default:
                throw new Exception("Action {$n}: unknown action.");
        }
        $actions[] = $act;
    }
    if (!$actions) throw new Exception('Add at least one action.');

    return [
        'name'         => $name,
        'description'  => _wiki_wf_str($in['description'] ?? '', 1000),
        'enabled'      => !empty($in['enabled']),
        'space'        => $space,
        'trigger'      => $trigger,
        'filters'      => $filters,
        'actions'      => $actions,
        'debounce'     => min(WIKI_WF_MAX_DEBOUNCE, max(0, (int)($in['debounce'] ?? WIKI_WF_DEFAULT_DEBOUNCE))),
        'max_per_hour' => min(WIKI_WF_MAX_PER_HOUR, max(1, (int)($in['max_per_hour'] ?? WIKI_WF_DEFAULT_PER_HOUR))),
    ];
}

/** A space was renamed: workflows scoped to it follow. */
function wiki_workflows_space_renamed(string $old, string $new): void {
    if (!is_file(wiki_wf_path('workflows.json'))) return;
    wiki_workflows_mutate(function (array &$list) use ($old, $new) {
        foreach ($list as &$wf) if (($wf['space'] ?? '') === $old) $wf['space'] = $new;
        unset($wf);
    });
}
