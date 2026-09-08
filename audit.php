<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// AUDIT LOG — who changed which page, when, and whether it worked.
//
// Off by default; an administrator turns it on in Admin → Audit Log (the flag lives in
// settings.json, not config.php, because it is a runtime decision rather than a deploy).
//
// One JSON object per line in LOG_DIR/audit/YYYY-MM-DD.log. JSON Lines because the point
// of an audit trail is that something else can read it: Splunk indexes it with
// INDEXED_EXTRACTIONS=json and no regex, and a page titled "Q3 | Draft" cannot shift a
// column the way it would in a delimited format. Field names follow Splunk's CIM Change
// data model (user / action / object / object_id / status / src), so the Enterprise
// Security dashboards for "who changed what" work without aliasing.
//
// Append-only, one file per day, never rewritten and never auto-pruned: a file monitor
// tracks its position by offset, so editing a written file makes a forwarder re-read or
// skip events. Rotation is by filename for the same reason.
//
// Two rules that matter more than the format:
//  - Logging must never break a save. Every failure here is swallowed; a wiki with a full
//    disk or a read-only log directory keeps working, just unaudited.
//  - Denials are logged too. "A reader tried to edit a frozen Space" is usually the line
//    someone is looking for, and the guards are at the same choke point as the successes.
// =================================================================

require_once __DIR__ . '/settings.php';

function wiki_audit_enabled(): bool {
    return (bool)wiki_setting('audit_log', false);
}

function wiki_audit_dir(): ?string {
    if (!defined('LOG_DIR') || !LOG_DIR) return null;
    $dir = rtrim(LOG_DIR, '/\\') . '/audit';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    return $dir;
}

// Who is acting, beyond the session. Entry points that are not an ordinary web request
// set this so a line can say "the AI user Bravo, running the job Alice asked for" rather
// than just naming whichever identity happened to hold the token.
$GLOBALS['_wiki_audit_context'] = [];

function wiki_audit_set_context(array $ctx): void {
    $GLOBALS['_wiki_audit_context'] = $ctx;
}

/**
 * Write one line. $fields uses CIM names; ts, user and src are filled in here.
 *
 * @param string $action          create | update | delete | rename | copy | tag | attach | detach | restore
 * @param string $status          success | failure
 */
function wiki_audit_log(string $action, string $status, array $fields = []): void {
    if (!wiki_audit_enabled()) return;
    $dir = wiki_audit_dir();
    if ($dir === null) return;

    $ctx = $GLOBALS['_wiki_audit_context'] ?? [];
    $actor = $ctx['user'] ?? null;
    if ($actor === null && function_exists('get_current_actor')) {
        $a = get_current_actor();
        $actor = $a['name'] ?? null;
        $fields['user_id'] = $fields['user_id'] ?? ($a['uid'] ?? null);
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip !== null) $ip = trim(explode(',', $ip)[0]);

    // Ordered so the timestamp is the first thing on the line: a log reader finds the
    // event time with a short lookahead instead of scanning the whole record.
    $line = array_merge([
        'ts'     => date('c'),                       // ISO 8601 with offset
        'user'   => $actor ?: 'anonymous',
        'action' => $action,
        'status' => $status,
    ], $fields);

    // Default the origin rather than leaving it absent: "how did this reach the wiki"
    // is one of the questions the log exists to answer, and an entry with no `via` reads
    // as missing data rather than as an ordinary browser edit.
    $line['via'] = $ctx['via'] ?? 'web';
    if (!empty($ctx['requested_by'])) $line['requested_by'] = $ctx['requested_by'];
    if (!empty($ctx['user_id']))      $line['user_id'] = $ctx['user_id'];
    if ($ip !== null)                 $line['src'] = $ip;

    $json = json_encode(array_filter($line, fn($v) => $v !== null && $v !== ''),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    // Never let logging break the write it is describing.
    @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $json . "\n", FILE_APPEND | LOCK_EX);
}

// --- Reading, for the admin viewer -------------------------------------------

/** Dates that have a log, newest first. */
function wiki_audit_dates(): array {
    $dir = wiki_audit_dir();
    if ($dir === null) return [];
    $out = [];
    foreach (scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $f) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', $f, $m)) continue;
        $out[] = ['date' => $m[1], 'size' => (int)@filesize($dir . '/' . $f)];
    }
    return $out;
}

/**
 * One day's entries, newest first, optionally narrowed to one user.
 *
 * A malformed line is skipped rather than failing the read: the log is append-only from
 * concurrent requests, and a torn final line should not hide the day.
 */
function wiki_audit_read(string $date, string $user = '', int $limit = 2000): array {
    $dir = wiki_audit_dir();
    if ($dir === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    $file = $dir . '/' . $date . '.log';
    if (!is_file($file)) return [];
    $rows = [];
    foreach (explode("\n", (string)@file_get_contents($file)) as $l) {
        if ($l === '') continue;
        $row = json_decode($l, true);
        if (!is_array($row)) continue;
        if ($user !== '' && strcasecmp((string)($row['user'] ?? ''), $user) !== 0) continue;
        $rows[] = $row;
    }
    $rows = array_reverse($rows);
    return array_slice($rows, 0, $limit);
}

/** Distinct actors in a day's log, for the filter dropdown. */
function wiki_audit_users(string $date): array {
    $names = [];
    foreach (wiki_audit_read($date, '', 100000) as $r) {
        $n = (string)($r['user'] ?? '');
        if ($n !== '') $names[$n] = true;
    }
    $out = array_keys($names);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

// --- What each action touches ------------------------------------------------
//
// The map is the scope of the audit log: page content, attachments and tags. Chat
// messages are deliberately absent — a busy thread would be hundreds of entries a day
// and the thread is already its own readable record — as are derived artefacts like the
// cached .drawio.svg, and Space administration, which is not a page action.
//
// The third element names the request parameter holding the target, so one hook can
// serve every action instead of thirty call sites drifting apart.
/**
 * A bulk deletion of chat messages — auto-purge, or the manual /purge.
 *
 * Individual chat messages are deliberately outside the audit log: a busy thread is
 * hundreds of entries a day and is already its own readable record. A *bulk deletion* is
 * different in kind. "Where did those four hundred messages go" is exactly the question
 * this log exists to answer, and one line per purge costs nothing.
 *
 * @param string $mode 'auto' (a retention policy fired) or 'manual' (someone ran /purge)
 */
function wiki_audit_chat_purge(string $abs_path, int $removed, string $mode): void {
    if (!wiki_audit_enabled() || $removed <= 0) return;
    $rel = $abs_path;
    if (defined('PAGES_DIR')) {
        $root = rtrim(realpath(PAGES_DIR) ?: PAGES_DIR, '/');
        $real = realpath($abs_path) ?: $abs_path;
        if (str_starts_with($real, $root . '/')) $rel = substr($real, strlen($root) + 1);
    }
    wiki_audit_log('delete', 'success', [
        'object'      => $rel,
        'object_type' => 'chat_messages',
        'change_type' => $mode === 'auto' ? 'retention' : 'purge',
        'count'       => $removed,
    ]);
}

const WIKI_AUDIT_ACTIONS = [
    'save'               => ['update',  'page',       'file'],
    'create_file'        => ['create',  'page',       'path'],
    'upload_page'        => ['create',  'page',       null],          // named only after the write
    'save_message_page'  => ['update',  'page',       'path'],
    'create_folder'      => ['create',  'folder',     'path'],
    'create_filesfolder' => ['create',  'folder',     'path'],
    'create_diagram'     => ['create',  'page',       'path'],
    'create_list'        => ['create',  'page',       'path'],
    'create_chat'        => ['create',  'page',       'path'],
    'create_search'      => ['create',  'page',       'path'],
    'delete'             => ['delete',  'page',       'path'],
    'move'               => ['rename',  'page',       'old_path'],
    'copy_page'          => ['copy',    'page',       'source_path'],
    'update_tags'        => ['tag',     'tags',       null],          // identified by id, not path
    'upload_attachment'  => ['attach',  'attachment', 'page_path'],
    'delete_attachment'  => ['detach',  'attachment', 'page_path'],
    'delete_folder_file' => ['delete',  'attachment', 'path'],
    'upload_to_folder'   => ['attach',  'attachment', 'folder_path'],
    'git_restore'        => ['restore', 'page',       'file'],
    'retarget_wikilinks' => ['update',  'page',       'new_path'],
];

/**
 * Describe what this request is about to do, before it does it.
 *
 * Resolved up front because a delete removes the index entry: after dispatch there is no
 * id left to record. Creates are the mirror image — their id does not exist yet — so the
 * caller re-resolves on success (see wiki_audit_finish).
 *
 * @return array|null null when the action is not auditable.
 */
function wiki_audit_begin(string $action, $indexer = null): ?array {
    if (!wiki_audit_enabled() || !isset(WIKI_AUDIT_ACTIONS[$action])) return null;
    [$verb, $category, $param] = WIKI_AUDIT_ACTIONS[$action];

    $rel = $param !== null ? ltrim(str_replace('..', '', (string)($_REQUEST[$param] ?? '')), '/') : '';
    $fields = ['object_category' => $category, 'api_action' => $action];
    if ($rel !== '') $fields['object'] = $rel;

    if ($action === 'update_tags') {
        // Tag changes name a page id rather than a path; resolve the other way round.
        $id = (string)($_REQUEST['id'] ?? '');
        if ($id !== '' && $indexer) {
            $all = $indexer->getAllPages();
            if (isset($all[$id]['path'])) $fields['object'] = $all[$id]['path'];
        }
        if ($id !== '') $fields['object_id'] = $id;
    } elseif ($rel !== '' && $indexer) {
        $id = $indexer->getId($rel);
        if ($id !== null) $fields['object_id'] = (string)$id;
    }
    // Where it is going, for the actions that move something.
    foreach (['new_path', 'target_space'] as $k) {
        $v = trim((string)($_REQUEST[$k] ?? ''));
        if ($v !== '') $fields[$k === 'new_path' ? 'object_new' : 'dest'] = ltrim(str_replace('..', '', $v), '/');
    }
    return ['verb' => $verb, 'fields' => $fields];
}

/** Close out a begun action. $reason is the error text on failure. */
function wiki_audit_finish(?array $begun, string $status, string $reason = '', $indexer = null, string $space = ''): void {
    if ($begun === null) return;
    $f = $begun['fields'];
    if ($space !== '') $f['space'] = $space;
    // A create has no id until it exists; fill it in now that it does.
    if ($status === 'success' && empty($f['object_id']) && $indexer && !empty($f['object'])) {
        $id = $indexer->getId($f['object_new'] ?? $f['object']);
        if ($id !== null) $f['object_id'] = (string)$id;
    }
    if ($reason !== '') $f['reason'] = $reason;
    wiki_audit_log($begun['verb'], $status, $f);
}
