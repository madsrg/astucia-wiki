<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PAGE GENERATORS — one Markdown page per row of a .list
//
// A *template* is an ordinary .md page whose front matter names a source list:
//
//   ---
//   generator_source: CRM:87            # Space:page-id of a .list (bare id = this Space)
//   generator_folder: Accounts          # where the pages go, in the template's Space
//   generator_filename: "{{name}}"      # optional; default {{_title}}, the row's Title
//   ---
//   # {{name}}                          # the body is rendered once per row (mustache.php)
//
// Each generated page carries `generated_by: <template id>` and `generated_row: <item id>`.
// The page is tied to the row's item id, never to its name, so a renamed row renames its
// page and the page keeps its id — ?pageid= links and {include:ID} survive.
//
// **Flat keys, not a nested `generator:` mapping**, because the front matter code reads
// and writes flat keys only: wiki_fm_parse_block() keeps a nested mapping as raw text, and
// wiki_fm_set() writes scalars. Flat keys get the existing reader, the surgical writer and
// the Metadata panel for free.
//
// The template lives in the Space it writes to; only the source may be elsewhere. So the
// permission question is always the same pair: can the owner edit the target Space, and
// read the source Space. The owner is the person who last saved the template or pressed
// Re-render (see TRACKING below); every render runs as them and re-checks both, and a
// render whose owner no longer qualifies writes nothing.
//
// Render (wiki_gen_render) compares each row's page with what the file holds now, not with
// a remembered hash, so it is also a repair: a page edited behind the wiki's back is put
// right on the next render. A row that is gone is **archived**: the page stays, its
// `generated_*` keys become `archived_*`, and nothing regenerates it. A page whose
// `generated_by` was removed by hand is treated as detached — the render leaves it alone
// and does not make a new one for its row.
//
// Writes go through wiki_gen_write(), which does what `save` does — index, search, realtime
// (via the indexer), automatic stamping, audit — except mention announcements (data cannot
// mention anyone; a mention typed into the template would otherwise notify on every page).
// Workflows do not fire on these writes (see _wiki_workflow_event), or one template edit
// would raise a run per page. One git commit per render.
//
// State, in WIKI_SYSTEM_DATA/generators.json, keyed "Space:templateId": owner, status,
// counts, the revisions last rendered from (for step 3's drift check) and row → page id.
// All of it can be rebuilt from the pages: an existing page with matching `generated_*`
// keys is adopted.
// =================================================================

require_once __DIR__ . '/mustache.php';
require_once __DIR__ . '/frontmatter.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/space_settings.php';
require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/page_chat.php';

const WIKI_GEN_MAX_ROWS     = 1000;
const WIKI_GEN_NAME_MAX     = 120;           // characters in a generated page's name
const WIKI_GEN_KEYS         = ['generator_source', 'generator_folder', 'generator_filename'];
// Top-level folders of a Space a generator may not write into: AI skills are admin-only,
// AI memory belongs to the AI, `templates/` holds the New-page templates.
const WIKI_GEN_RESERVED_DIRS = ['skills', 'memory', 'templates'];

// -----------------------------------------------------------------
// The definition
// -----------------------------------------------------------------

/** Does this front matter make the page a template? */
function wiki_gen_is_template(array $meta): bool {
    return isset($meta['generator_source']) && is_string($meta['generator_source'])
        && trim($meta['generator_source']) !== '';
}

/**
 * Read and check a template's definition. Returns ['ok' => true, …] or
 * ['ok' => false, 'error' => code, 'detail' => …].
 *
 * @param string $space        the template's Space
 * @param string $template_rel the template's path within it
 */
function wiki_gen_definition(array $meta, string $space, string $template_rel): array {
    if (!wiki_gen_is_template($meta)) return ['ok' => false, 'error' => 'not_template', 'detail' => ''];

    $src = trim((string)$meta['generator_source']);
    if (preg_match('/^(\d+)$/', $src, $m)) {
        $src_space = $space;
        $src_id    = (int)$m[1];
    } elseif (preg_match('/^(.+):(\d+)$/', $src, $m)) {
        $src_space = trim($m[1]);
        $src_id    = (int)$m[2];
    } else {
        return ['ok' => false, 'error' => 'bad_source', 'detail' => $src];
    }
    if (!wiki_gen_space_name_ok($src_space)) return ['ok' => false, 'error' => 'bad_source', 'detail' => $src];

    $folder_raw = array_key_exists('generator_folder', $meta)
        ? (string)(is_array($meta['generator_folder']) ? '' : $meta['generator_folder'])
        : (dirname($template_rel) === '.' ? '' : dirname($template_rel));
    $folder = wiki_gen_clean_folder($folder_raw);
    if ($folder === null) return ['ok' => false, 'error' => 'bad_folder', 'detail' => $folder_raw];

    $filename = isset($meta['generator_filename']) && is_string($meta['generator_filename'])
        && trim($meta['generator_filename']) !== ''
        ? trim($meta['generator_filename']) : '{{_title}}';

    return [
        'ok'           => true,
        'source_space' => $src_space,
        'source_id'    => $src_id,
        'folder'       => $folder,
        'filename'     => $filename,
    ];
}

/** A Space name that may be resolved under PAGES_DIR: one segment, not hidden. */
function wiki_gen_space_name_ok(string $name): bool {
    return $name !== '' && strpos($name, '/') === false && strpos($name, '\\') === false
        && $name[0] !== '.' && $name !== '..';
}

/**
 * A target folder, cleaned, or null when it is not acceptable: no `..`, no hidden segment,
 * nothing inside an attachments folder, not a reserved top-level folder. '' is the Space's
 * root.
 */
function wiki_gen_clean_folder(string $folder): ?string {
    $folder = trim(str_replace('\\', '/', $folder));
    $parts = array_values(array_filter(explode('/', $folder), fn($p) => $p !== ''));
    foreach ($parts as $p) {
        if ($p === '.' || $p === '..' || $p[0] === '.' || preg_match('/[\x00-\x1F\x7F]/', $p)) return null;
        if (str_ends_with(strtolower($p), '.uploads')) return null;
    }
    if ($parts && in_array(strtolower($parts[0]), WIKI_GEN_RESERVED_DIRS, true)) return null;
    return implode('/', $parts);
}

/**
 * A rendered page name made safe as a filename: no separators or characters other systems
 * refuse (the content may be a git checkout on Windows), no control characters, no leading
 * dot (a hidden file is not a page), capped in length. Empty becomes "row-<id>".
 */
function wiki_gen_clean_name(string $name, int $row_id): string {
    $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = ltrim($name, '. ');
    $name = rtrim($name, '. ');
    if (mb_strlen($name, 'UTF-8') > WIKI_GEN_NAME_MAX) {
        $name = rtrim(mb_substr($name, 0, WIKI_GEN_NAME_MAX, 'UTF-8'), '. ');
    }
    if (preg_match('/\.(md|list|chat|json|drawio|search|svg)$/i', $name)) $name .= ' page';
    return $name === '' ? 'row-' . $row_id : $name;
}

/** The name shown as the writer of generated pages, in the index, stamps and git. */
function wiki_gen_system_name(string $template_rel): string {
    return 'Template: ' . basename($template_rel, '.md');
}

// -----------------------------------------------------------------
// The data
// -----------------------------------------------------------------

/**
 * A list's rows as template contexts: one key per field (wiki_tpl_key of its name), the
 * value as the list shows it — lookups resolved in the list's own Space, by lists.php — and
 * `_row`, `_title`, `_source`. Two fields whose keys collide get `_2`, `_3`, … in column
 * order, and a warning says so.
 *
 * @return array{rows: array<int,array>, keys: string[], warnings: array}
 */
function wiki_gen_rows(array $list, string $source_dir, array $source): array {
    $warnings = [];
    $flat  = wiki_list_flatten($list, $source_dir);
    $title = wiki_list_title_field($list);

    $keymap = [];   // column id => template key
    $used   = [];
    foreach ($list['columns'] ?? [] as $col) {
        $id = (string)($col['id'] ?? '');
        if ($id === '') continue;
        $base = wiki_tpl_key((string)($col['name'] ?? '')) ?: wiki_tpl_key($id);
        if ($base === '' || $base[0] === '_') $base = 'f_' . ltrim($base, '_');
        $key = $base;
        for ($n = 2; isset($used[$key]); $n++) $key = $base . '_' . $n;
        if ($key !== $base) $warnings[] = ['warning' => 'duplicate_field', 'detail' => (string)($col['name'] ?? $id) . ' → ' . $key];
        $used[$key] = true;
        $keymap[$id] = $key;
    }

    // A Group of fields is given as the structure it is, not its text: its sub-fields under
    // keys of their own (the same rule as fields), `_text` its one-line summary, and — for one
    // allowing several — a list of entries, each with `_id`, `_index` (from 1), `_first`, `_last`.
    $groups = [];   // column id => [column, sub-field id => key]
    foreach ($list['columns'] ?? [] as $col) {
        if (($col['type'] ?? '') !== 'object' || !isset($keymap[$col['id'] ?? ''])) continue;
        $subkeys = [];
        $subused = ['id' => true];      // reserved: entries carry `_id`, and `id` would shadow it in a reader's mind
        foreach ($col['fields'] ?? [] as $sf) {
            $base = wiki_tpl_key((string)($sf['name'] ?? '')) ?: wiki_tpl_key((string)($sf['id'] ?? ''));
            if ($base === '') continue;
            $k = $base;
            for ($n = 2; isset($subused[$k]); $n++) $k = $base . '_' . $n;
            $subused[$k] = true;
            $subkeys[(string)$sf['id']] = $k;
        }
        $groups[$col['id']] = [$col, $subkeys];
    }
    $entry_ctx = function (array $col, array $subkeys, array $entry): array {
        $out = [];
        foreach ($subkeys as $sid => $k) {
            $v = $entry[$sid] ?? '';
            $out[$k] = is_scalar($v) ? (string)$v : '';
        }
        $out['_text'] = wiki_list_group_summary($col, $entry);
        return $out;
    };

    $rows = [];
    $raw_items = $list['items'] ?? [];
    foreach ($flat['items'] ?? [] as $i => $item) {
        if (!isset($item['id']) || !is_numeric($item['id'])) continue;
        $rid = (int)$item['id'];
        $ctx = [];
        foreach ($keymap as $cid => $key) {
            if (isset($groups[$cid])) {
                [$gcol, $subkeys] = $groups[$cid];
                $entries = wiki_list_group_entries($gcol, $raw_items[$i][$cid] ?? null);
                if (empty($gcol['multiple'])) {
                    // No value is null, so {{#address}}…{{/address}} shows nothing.
                    $ctx[$key] = $entries ? $entry_ctx($gcol, $subkeys, $entries[0]) : null;
                } else {
                    $n = count($entries);
                    $ctx[$key] = array_map(fn($e, $x) => $entry_ctx($gcol, $subkeys, $e) + [
                        '_id' => (int)($e['id'] ?? 0), '_index' => $x + 1, '_first' => $x === 0, '_last' => $x === $n - 1,
                    ], $entries, array_keys($entries));
                }
                continue;
            }
            $v = $item[$cid] ?? '';
            $ctx[$key] = is_array($v) ? implode('; ', array_map('strval', array_filter($v, 'is_scalar'))) : (string)$v;
        }
        $ctx['_row']    = ['id' => $rid];
        $ctx['_title']  = $title !== null ? (string)($item[$title] ?? '') : '';
        $ctx['_source'] = $source;
        $rows[$rid] = $ctx;
    }
    ksort($rows);
    return ['rows' => $rows, 'keys' => array_values($keymap), 'warnings' => $warnings];
}

// -----------------------------------------------------------------
// People
// -----------------------------------------------------------------

/** A person (not an AI user, not an API account) from users.json. */
function wiki_gen_person(int $uid): ?array {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    $f = rtrim(WIKI_SYSTEM_DATA, '/') . '/users.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    foreach (($d['users'] ?? []) as $u) {
        if ((int)($u['uid'] ?? 0) !== $uid) continue;
        if (!empty($u['is_ai']) || !empty($u['is_system'])) continue;
        return $u;
    }
    return null;
}

/**
 * Why this owner may not run this generator, or '' when they may. They must be a person
 * who can edit the target Space and read the source Space — re-checked on every render,
 * since a role or a Space allowlist can change after the generator was set up.
 * With authentication off everyone is an administrator, so there is nothing to check.
 */
function wiki_gen_owner_problem(?array $owner, string $target_space, string $source_space): string {
    if (!defined('AUTHENTICATION_ENABLED') || !AUTHENTICATION_ENABLED) return '';
    $uid = (int)($owner['uid'] ?? 0);
    $u = $uid > 0 ? wiki_gen_person($uid) : null;
    if (!$u) return 'owner_missing';
    $role = (string)($u['role'] ?? 'reader');
    if (!in_array($role, ['editor', 'admin'], true)) return 'owner_not_editor';
    if ($role === 'admin') return '';
    $spaces = $u['spaces'] ?? null;
    if (!is_array($spaces)) return '';
    if ($target_space !== '' && !in_array($target_space, $spaces, true)) return 'owner_no_target';
    if ($source_space !== '' && !in_array($source_space, $spaces, true)) return 'owner_no_source';
    return '';
}

// -----------------------------------------------------------------
// State
// -----------------------------------------------------------------

function wiki_gen_key(string $space, int $template_id): string {
    return $space . ':' . $template_id;
}

/** Read-modify-write generators.json under its lock (the web side and the runner both write it). */
function wiki_gen_state_mutate(callable $fn) {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    $path = rtrim(WIKI_SYSTEM_DATA, '/') . '/generators.json';
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($data) || !is_array($data['generators'] ?? null)) $data = ['generators' => []];
        $before = $data;
        $ret = $fn($data['generators']);
        if ($data !== $before) {
            @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        return $ret;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function wiki_gen_state_get(string $key): ?array {
    return wiki_gen_state_mutate(fn(array &$g) => $g[$key] ?? null);
}

// -----------------------------------------------------------------
// Rendering
// -----------------------------------------------------------------

/**
 * Render a generator: create, update, rename and archive its pages so they match the list.
 *
 * @param string     $space        the template's (= target) Space
 * @param int        $template_id  the template's page id in that Space
 * @param array|null $owner        ['uid' => …, 'name' => …] to run as and record; null keeps
 *                                 the recorded owner (the cron runner, in step 3)
 * @param PageIndexer|null $indexer the target Space's indexer, when the caller has one —
 *                                 two instances on one index.json would overwrite each other
 * @return array ['status' => 'ok'|'error'|'readonly', 'error'?, 'detail'?, 'line'?,
 *                'counts' => […], 'warnings' => […]]
 */
function wiki_gen_render(string $space, int $template_id, ?array $owner = null, $indexer = null): array {
    $key = wiki_gen_key($space, $template_id);
    $counts = ['created' => 0, 'updated' => 0, 'renamed' => 0, 'archived' => 0,
               'unchanged' => 0, 'detached' => 0, 'skipped' => 0];

    // One render at a time, wiki-wide: the after-save render and the cron runner must not
    // both write one generator's pages, and renders are short enough that one lock is fine.
    $lock_path = rtrim(WIKI_SYSTEM_DATA, '/') . '/generators.render.lock';
    $lock = @fopen($lock_path, 'c');
    if ($lock) flock($lock, LOCK_EX);
    $prev_origin = $GLOBALS['_wiki_gen_origin'] ?? null;
    $GLOBALS['_wiki_gen_origin'] = $key;
    try {
        $result = _wiki_gen_render($space, $template_id, $owner, $indexer, $counts);
    } catch (\Throwable $e) {
        $result = ['status' => 'error', 'error' => 'failed', 'detail' => $e->getMessage()];
    } finally {
        $GLOBALS['_wiki_gen_origin'] = $prev_origin;
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
    $result['counts']   = $counts;
    $result['warnings'] = $result['warnings'] ?? [];

    wiki_gen_state_mutate(function (array &$g) use ($key, $space, $template_id, $owner, $result) {
        // A template that is gone, or no longer one: its pages were archived, so it is
        // forgotten. An id that never was a template leaves no trace at all.
        if ($result['status'] === 'removed') { unset($g[$key]); return; }
        if (!isset($g[$key]) && in_array($result['error'] ?? '', ['template_missing', 'not_template'], true)) return;

        $s = $g[$key] ?? ['space' => $space, 'template_id' => $template_id, 'rows' => []];
        if ($owner !== null) $s['owner'] = ['uid' => $owner['uid'] ?? null, 'name' => $owner['name'] ?? null];
        foreach (['status', 'error', 'detail', 'line', 'counts', 'warnings', 'template_path',
                  'source', 'source_rev', 'template_rev', 'deps'] as $f) {
            if (array_key_exists($f, $result)) $s[$f] = $result[$f];
            elseif (in_array($f, ['error', 'detail', 'line'], true)) unset($s[$f]);
        }
        if (isset($result['rows'])) $s['rows'] = $result['rows'];
        // Anything but a clean render is tried again on the next cron tick: a frozen Space
        // may be thawed, an owner given access, a missing list restored — none of which
        // raises an event here. Each retry stops long before writing anything.
        $s['dirty'] = $result['status'] !== 'ok';
        $s['last_run'] = date('c');
        $g[$key] = $s;
    });
    unset($result['rows'], $result['source_rev'], $result['template_rev'], $result['deps']);
    return $result;
}

/** The render itself; wiki_gen_render() wraps it in the lock, the origin and the state write. */
function _wiki_gen_render(string $space, int $template_id, ?array $owner, $indexer, array &$counts): array {
    if (!wiki_gen_space_name_ok($space)) return ['status' => 'error', 'error' => 'template_missing', 'detail' => ''];
    $target_dir = rtrim(PAGES_DIR, '/') . '/' . $space;
    if (!is_dir($target_dir)) return ['status' => 'error', 'error' => 'template_missing', 'detail' => ''];
    $ix = $indexer ?? new PageIndexer($target_dir);

    // ── The template ─────────────────────────────────────────────────────
    $tpl_rel = $ix->getPath($template_id);
    $tpl_abs = $tpl_rel !== null ? $target_dir . '/' . $tpl_rel : null;
    if ($tpl_rel === null || strtolower(pathinfo($tpl_rel, PATHINFO_EXTENSION)) !== 'md' || !is_file($tpl_abs)) {
        return wiki_gen_retire($ix, $target_dir, $space, $template_id, 'template_missing', (string)$tpl_rel, $counts);
    }
    $tpl_raw = (string)file_get_contents($tpl_abs);
    $split   = wiki_fm_split($tpl_raw);
    $def     = wiki_gen_definition($split['meta'], $space, $tpl_rel);
    $base    = ['template_path' => $tpl_rel, 'template_rev' => sha1($tpl_raw)];
    if (!$def['ok'] && $def['error'] === 'not_template') {
        return wiki_gen_retire($ix, $target_dir, $space, $template_id, 'not_template', $tpl_rel, $counts);
    }
    if (!$def['ok']) return $base + ['status' => 'error', 'error' => $def['error'], 'detail' => $def['detail']];
    $base['source'] = ['space' => $def['source_space'], 'id' => $def['source_id']];

    // ── Who it runs as ───────────────────────────────────────────────────
    $state = wiki_gen_state_get(wiki_gen_key($space, $template_id)) ?? [];
    $run_as = $owner ?? ($state['owner'] ?? null);
    $problem = wiki_gen_owner_problem($run_as, $space, $def['source_space']);
    if ($problem !== '') return $base + ['status' => 'error', 'error' => $problem, 'detail' => (string)($run_as['name'] ?? '')];
    if (wiki_space_is_readonly($space)) return $base + ['status' => 'readonly', 'error' => 'readonly', 'detail' => $space];

    // ── The source list ──────────────────────────────────────────────────
    $src_dir = rtrim(PAGES_DIR, '/') . '/' . $def['source_space'];
    if (!is_dir($src_dir)) return $base + ['status' => 'error', 'error' => 'source_missing', 'detail' => $def['source_space']];
    $src_ix  = $def['source_space'] === $space ? $ix : new PageIndexer($src_dir);
    $src_rel = $src_ix->getPath($def['source_id']);
    if ($src_rel === null || !is_file($src_dir . '/' . $src_rel)) {
        return $base + ['status' => 'error', 'error' => 'source_missing', 'detail' => (string)$def['source_id']];
    }
    if (strtolower(pathinfo($src_rel, PATHINFO_EXTENSION)) !== 'list') {
        return $base + ['status' => 'error', 'error' => 'source_not_list', 'detail' => $src_rel];
    }
    $src_raw = (string)file_get_contents($src_dir . '/' . $src_rel);
    $list = json_decode($src_raw, true);
    if (!is_array($list)) return $base + ['status' => 'error', 'error' => 'source_unreadable', 'detail' => $src_rel];
    $base['source_rev'] = sha1($src_raw);
    // Everything this render read, by Space:id with its revision: the source, and the lists
    // its lookup fields resolve in (a manager renamed in Staff changes every account page).
    // A save of any of them marks the generator dirty; the cron drift check compares them.
    $base['deps'] = [$def['source_space'] . ':' . $def['source_id'] => $base['source_rev']];
    foreach (wiki_list_lookup_targets($list, $src_dir) as $lid => $ldata) {
        $lrel = $src_ix->getPath((int)$lid);
        $base['deps'][$def['source_space'] . ':' . (int)$lid] = $lrel !== null && is_file($src_dir . '/' . $lrel)
            ? sha1_file($src_dir . '/' . $lrel) : '';
    }

    // ── The folder ───────────────────────────────────────────────────────
    $folder_abs = $target_dir . ($def['folder'] === '' ? '' : '/' . $def['folder']);
    if (function_exists('wiki_inside_files_library') && wiki_inside_files_library($folder_abs, true)) {
        return $base + ['status' => 'error', 'error' => 'folder_is_library', 'detail' => $def['folder']];
    }

    // ── The template text ────────────────────────────────────────────────
    try { $body_tree = wiki_tpl_parse($split['body']); }
    catch (WikiTemplateError $e) {
        // The body's line numbers, counted from the top of the file, which is what the
        // author sees in the editor.
        return $base + ['status' => 'error', 'error' => 'template_error', 'detail' => $e->error . ($e->detail !== '' ? ': ' . $e->detail : ''),
                        'line' => $e->templateLine + substr_count($split['block'], "\n")];
    }
    try { $name_tree = wiki_tpl_parse($def['filename']); }
    catch (WikiTemplateError $e) {
        return $base + ['status' => 'error', 'error' => 'filename_error', 'detail' => $e->error . ($e->detail !== '' ? ': ' . $e->detail : '')];
    }

    $source_ctx = [
        'space' => $def['source_space'],
        'title' => basename($src_rel, '.list'),
        'url'   => 'index.php?pageid=' . $def['source_id'] . '&space=' . rawurlencode($def['source_space']),
    ];
    $data = wiki_gen_rows($list, $src_dir, $source_ctx);
    $warnings = $data['warnings'];
    if (count($data['rows']) > WIKI_GEN_MAX_ROWS) {
        return $base + ['status' => 'error', 'error' => 'too_many_rows', 'detail' => (string)count($data['rows'])];
    }
    $known = array_merge($data['keys'], ['_row', '_title', '_source']);
    $unknown = array_values(array_diff(array_merge(wiki_tpl_names($body_tree), wiki_tpl_names($name_tree)), $known));
    if ($unknown) $warnings[] = ['warning' => 'unknown_field', 'detail' => implode(', ', $unknown)];

    // ── Plan: where each row's page is, and where it should be ───────────
    $sys      = wiki_gen_system_name($tpl_rel);
    $prev     = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $rows_out = [];
    $touched  = [];           // absolute paths for the one commit
    $reserved = [];           // relative paths taken by this render

    // Pages already belonging to this template, by row — from the state, and then from the
    // files themselves, so a lost state file is rebuilt rather than duplicated.
    $owned = [];
    foreach ($prev as $rid => $r) {
        $pid = (int)($r['page_id'] ?? 0);
        $p = $pid > 0 ? $ix->getPath($pid) : null;
        if ($p !== null && is_file($target_dir . '/' . $p)) $owned[(int)$rid] = ['page_id' => $pid, 'path' => $p] + $r;
    }
    foreach (wiki_gen_scan_owned($ix, $target_dir, $template_id) as $rid => $hit) {
        if (!isset($owned[$rid])) $owned[$rid] = $hit;
    }

    foreach ($data['rows'] as $rid => $ctx) {
        $cur = $owned[$rid] ?? null;
        if (!empty($prev[$rid]['detached']) || !empty($cur['detached'])) {
            $rows_out[$rid] = ['detached' => true] + (isset($cur['page_id']) ? ['page_id' => $cur['page_id']] : []);
            $counts['detached']++;
            continue;
        }
        // A page whose generated_by was removed by hand has left the generator: leave it
        // alone, and do not make another one for its row.
        if ($cur !== null) {
            $cur_meta = wiki_fm_split((string)file_get_contents($target_dir . '/' . $cur['path']))['meta'];
            if ((string)($cur_meta['generated_by'] ?? '') !== (string)$template_id) {
                $rows_out[$rid] = ['detached' => true, 'page_id' => $cur['page_id']];
                $counts['detached']++;
                continue;
            }
        }

        $body = wiki_tpl_render($body_tree, $ctx);
        $name = wiki_gen_clean_name(wiki_tpl_render($name_tree, $ctx, ['escape' => 'none']), $rid);
        $want = wiki_gen_free_path($ix, $target_dir, $def['folder'], $name, $rid, $template_id,
                                   $reserved, $cur['path'] ?? null);
        if ($want === null) {
            $warnings[] = ['warning' => 'name_taken', 'detail' => $name];
            $counts['skipped']++;
            if ($cur) $rows_out[$rid] = ['page_id' => $cur['page_id']];
            continue;
        }
        $reserved[$want] = true;

        if ($cur !== null && $cur['path'] !== $want) {
            wiki_gen_move($ix, $target_dir, $space, $cur['path'], $want, $touched);
            $counts['renamed']++;
            $cur['path'] = $want;
        }
        $changed = wiki_gen_write($ix, $target_dir, $space, $want, $body, $template_id, $rid, $sys, $touched);
        if ($cur === null)  $counts['created']++;
        elseif ($changed)   $counts['updated']++;
        elseif (!isset($touched[$target_dir . '/' . $want])) $counts['unchanged']++;
        $rows_out[$rid] = ['page_id' => (int)$ix->getId($want)];
    }

    // ── Rows that are gone: archive their pages ──────────────────────────
    foreach ($owned as $rid => $cur) {
        if (isset($data['rows'][$rid])) continue;
        if (!empty($cur['detached'])) continue;
        if (wiki_gen_archive($ix, $target_dir, $space, $cur['path'], $template_id, $rid, $sys, $touched)) {
            $counts['archived']++;
        }
    }

    if ($touched && function_exists('git_commit_paths')) {
        git_commit_paths(array_keys($touched), $sys, 'generator@wiki.localhost',
                         wiki_gen_commit_message($sys, $counts), $target_dir);
    }

    return $base + ['status' => 'ok', 'warnings' => $warnings, 'rows' => $rows_out];
}

/**
 * A template that was deleted, or whose generator keys were removed: archive every page it
 * made, the same as for a removed row, and report 'removed' so its state is forgotten.
 * No owner check — this only releases pages in this Space from a generator, it reads no
 * data. When there is nothing to archive and no state, it is simply not a template.
 */
function wiki_gen_retire($ix, string $target_dir, string $space, int $template_id, string $why,
                         string $detail, array &$counts): array {
    $state = wiki_gen_state_get(wiki_gen_key($space, $template_id));
    $owned = wiki_gen_scan_owned($ix, $target_dir, $template_id);
    foreach (($state['rows'] ?? []) as $rid => $r) {
        $p = isset($r['page_id']) ? $ix->getPath((int)$r['page_id']) : null;
        if ($p !== null && !isset($owned[(int)$rid])) $owned[(int)$rid] = ['page_id' => (int)$r['page_id'], 'path' => $p];
    }
    if ($state === null && !$owned) return ['status' => 'error', 'error' => $why, 'detail' => $detail];
    if (wiki_space_is_readonly($space)) return ['status' => 'readonly', 'error' => 'readonly', 'detail' => $space];

    $last_path = (string)($state['template_path'] ?? '');
    $sys = $last_path !== '' ? wiki_gen_system_name($last_path) : 'Template #' . $template_id;
    $touched = [];
    foreach ($owned as $rid => $cur) {
        if (wiki_gen_archive($ix, $target_dir, $space, $cur['path'], $template_id, (int)$rid, $sys, $touched)) {
            $counts['archived']++;
        }
    }
    if ($touched && function_exists('git_commit_paths')) {
        git_commit_paths(array_keys($touched), $sys, 'generator@wiki.localhost',
                         wiki_gen_commit_message($sys, $counts), $target_dir);
    }
    return ['status' => 'removed', 'error' => $why, 'detail' => $detail];
}

/** "Template: Account — 2 created, 1 updated" */
function wiki_gen_commit_message(string $sys, array $counts): string {
    $parts = [];
    foreach (['created', 'updated', 'renamed', 'archived'] as $k) {
        if (!empty($counts[$k])) $parts[] = $counts[$k] . ' ' . $k;
    }
    return $sys . ' — ' . ($parts ? implode(', ', $parts) : 'no changes');
}

/**
 * Pages in this Space carrying `generated_by: <template id>`, by row. Only Markdown pages
 * whose front matter starts with the key are read in full — a cheap prefix test first —
 * so this stays fast on a large Space.
 */
function wiki_gen_scan_owned($ix, string $target_dir, int $template_id): array {
    $out = [];
    foreach ($ix->getAllPages() as $id => $entry) {
        $rel = (string)($entry['path'] ?? '');
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'md') continue;
        $abs = $target_dir . '/' . $rel;
        $head = @file_get_contents($abs, false, null, 0, 4096);
        if ($head === false || (strpos($head, 'generated_by') === false && strpos($head, 'detached_from') === false)) continue;
        $meta = wiki_fm_split((string)file_get_contents($abs))['meta'];
        // A detached page still names its row, so a lost state file does not turn its row
        // into a new page beside it.
        if ((string)($meta['detached_from'] ?? '') === (string)$template_id) {
            $rid = (int)($meta['detached_row'] ?? 0);
            if ($rid > 0 && !isset($out[$rid])) $out[$rid] = ['page_id' => (int)$id, 'path' => $rel, 'detached' => true];
            continue;
        }
        if ((string)($meta['generated_by'] ?? '') !== (string)$template_id) continue;
        $rid = (int)($meta['generated_row'] ?? 0);
        if ($rid > 0 && !isset($out[$rid])) $out[$rid] = ['page_id' => (int)$id, 'path' => $rel];
    }
    return $out;
}

/**
 * Where a row's page should live: "<folder>/<name>.md", or "<name> (<row id>).md" when
 * that is taken by anything other than this row's own page. Never a path already claimed
 * by this render or by a page this generator does not own. null when both are taken.
 */
function wiki_gen_free_path($ix, string $target_dir, string $folder, string $name, int $rid,
                            int $template_id, array $reserved, ?string $current): ?string {
    $prefix = $folder === '' ? '' : $folder . '/';
    foreach ([$name, $name . ' (' . $rid . ')'] as $candidate) {
        $rel = $prefix . $candidate . '.md';
        if (isset($reserved[$rel])) continue;
        if ($current !== null && $rel === $current) return $rel;
        $abs = $target_dir . '/' . $rel;
        if (!file_exists($abs)) return $rel;
        // The row's own page from an earlier render whose state was lost.
        $meta = wiki_fm_split((string)@file_get_contents($abs))['meta'];
        if ($current === null && (string)($meta['generated_by'] ?? '') === (string)$template_id
            && (int)($meta['generated_row'] ?? 0) === $rid) {
            return $rel;
        }
    }
    return null;
}

/**
 * Write one generated page if its content differs from what is on disk. Its front matter
 * belongs to the page — tags, stamps, an owner someone added — so only the body is
 * replaced, and the two `generated_*` keys are set. Returns whether anything was written.
 */
function wiki_gen_write($ix, string $target_dir, string $space, string $rel, string $body,
                        int $template_id, int $rid, string $sys, array &$touched): bool {
    $abs = $target_dir . '/' . $rel;
    $is_new = !is_file($abs);
    $old = $is_new ? '' : (string)file_get_contents($abs);
    $block = $is_new ? '' : wiki_fm_split($old)['block'];
    // Joined by hand, not through wiki_fm_preserve(): a rendered body that happens to start
    // with a `---` block of its own would otherwise replace the page's.
    $new = $block === ''
        ? "---\ngenerated_by: $template_id\ngenerated_row: $rid\n---\n" . $body
        : wiki_fm_set($block . $body, ['generated_by' => (string)$template_id, 'generated_row' => (string)$rid]);
    if ($new === $old) return false;

    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not create ' . dirname($rel));
    if (file_put_contents($abs, $new) === false) throw new RuntimeException('Could not write ' . $rel);
    $body_changed = $is_new || wiki_fm_body($old) !== wiki_fm_body($new);

    if ($is_new) $ix->addPage($rel, 0, $sys);
    else         $ix->updateModified($rel, 0, $sys);
    if (wiki_space_dir_fm_autostamp($target_dir) === 'on') {
        $entry = $ix->getAllPages()[$ix->getId($rel)] ?? null;
        wiki_fm_autostamp_file($abs, $entry, $sys, $body_changed);
    }
    wiki_gen_search(fn($s) => $s->upsertPage($space, $rel, wiki_fm_body($new)));
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log($is_new ? 'create' : 'update', 'success',
            ['object_category' => 'page', 'object' => $rel, 'space' => $space, 'change_type' => 'generated']);
    }
    $touched[$abs] = true;
    return true;
}

/** Rename a generated page, keeping its id, attachments and chat thread. */
function wiki_gen_move($ix, string $target_dir, string $space, string $old, string $new, array &$touched): void {
    $old_abs = $target_dir . '/' . $old;
    $new_abs = $target_dir . '/' . $new;
    if (!is_dir(dirname($new_abs))) @mkdir(dirname($new_abs), 0755, true);
    if (wiki_page_chat_blocks_move($old_abs, $new_abs)) throw new RuntimeException('A chat thread is in the way of ' . $new);
    if (!@rename($old_abs, $new_abs)) throw new RuntimeException('Could not rename ' . $old);
    $ix->updatePath($old, $new);
    if (is_dir($old_abs . '.uploads')) @rename($old_abs . '.uploads', $new_abs . '.uploads');
    $chat = wiki_page_chat_move($old_abs, $new_abs, $old, $new, $ix, $space);
    wiki_gen_search(fn($s) => $s->movePage($space, $old, $new));
    $touched[$old_abs] = true;
    $touched[$new_abs] = true;
    if (is_dir($new_abs . '.uploads')) { $touched[$old_abs . '.uploads'] = true; $touched[$new_abs . '.uploads'] = true; }
    if ($chat) {
        $touched[(string)wiki_page_chat_sidecar($old_abs)] = true;
        $touched[(string)wiki_page_chat_sidecar($new_abs)] = true;
    }
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log('rename', 'success', ['object_category' => 'page', 'object' => $new, 'old_path' => $old,
                                             'space' => $space, 'change_type' => 'generated']);
    }
}

/**
 * Archive the page of a row that is gone: it stays, but leaves the generator —
 * `generated_*` become `archived_from` / `archived_row` / `archived_at`, so it is no longer
 * locked and nothing regenerates it.
 */
function wiki_gen_archive($ix, string $target_dir, string $space, string $rel, int $template_id,
                          int $rid, string $sys, array &$touched): bool {
    $abs = $target_dir . '/' . $rel;
    if (!is_file($abs)) return false;
    $old = (string)file_get_contents($abs);
    if ((string)(wiki_fm_split($old)['meta']['generated_by'] ?? '') !== (string)$template_id) return false;
    $new = wiki_fm_set($old, ['archived_from' => (string)$template_id, 'archived_row' => (string)$rid,
                              'archived_at' => date('c')], ['generated_by', 'generated_row']);
    if (file_put_contents($abs, $new) === false) throw new RuntimeException('Could not write ' . $rel);
    $ix->updateModified($rel, 0, $sys);
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log('update', 'success', ['object_category' => 'page', 'object' => $rel, 'space' => $space,
                                             'change_type' => 'generator_archived']);
    }
    $touched[$abs] = true;
    return true;
}

/** Keep the search index in step, never letting it break the write. */
function wiki_gen_search(callable $fn): void {
    if (!defined('SEARCH_ENGINE') || SEARCH_ENGINE !== 'sqlite') return;
    if (!class_exists('SearchIndex')) {
        if (!is_file(__DIR__ . '/search_index.php')) return;
        require_once __DIR__ . '/search_index.php';
    }
    try { $fn(new SearchIndex()); } catch (\Throwable $_e) {}
}

/** The writer the current request is acting for, when it is a generator — workflows ask. */
function wiki_gen_origin(): ?string {
    return $GLOBALS['_wiki_gen_origin'] ?? null;
}

// =================================================================
// TRACKING — rendering again when what a generator reads changes
//
// PageIndexer calls wiki_gen_on_event() from the per-page methods, beside its workflow hook,
// for the same reason: every write in the wiki passes through there — the editor, AI tools,
// MCP, workflows. Nothing is rendered inside the write. The event only marks generators
// dirty; they are rendered after the response has gone (wiki_gen_after_response), so a save
// never waits for three hundred pages, and anything left over is the cron runner's.
//
// What marks a generator dirty:
//  - a save of its template (and the save that *makes* a page a template registers it);
//  - a save or delete of a list it read: its source, or a list its lookups read (`deps`);
//  - its template being deleted, which archives its pages.
//
// What does not raise an event at all — an rsync, a `git pull` (index_sync's reconcile is
// deliberately silent), a template arriving that way — is the cron tick's: it compares the
// revisions each generator last rendered from, and once an hour looks for templates nobody
// has registered (wiki_gen_tick).
//
// **The owner is whoever last saved the template** — any save, not only one that changes
// the generator keys, because the body decides which of the source's fields are published:
// an editor who cannot read the source must not be able to add {{salary}} under somebody
// else's authority. If the saver may not read the source, the generator stops (its pages
// stay as they were); if it was not a person at all — an AI user, an API account, a
// workflow — nobody owns it, and it stops until a person saves it or presses Re-render.
// =================================================================

const WIKI_GEN_INLINE_BUDGET  = 20.0;    // seconds of rendering after a response
const WIKI_GEN_CRON_BUDGET    = 90.0;    // seconds per cron tick
const WIKI_GEN_DISCOVER_EVERY = 3600;    // seconds between scans for unregistered templates

/** Every generator's state. */
function wiki_gen_state_all(): array {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return [];
    $f = rtrim(WIKI_SYSTEM_DATA, '/') . '/generators.json';
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d['generators'] ?? null) ? $d['generators'] : [];
}

/** The ids of a Space's registered templates, as keys — for the file tree's icon. */
function wiki_gen_template_ids(string $space): array {
    $ids = [];
    foreach (wiki_gen_state_all() as $s) {
        if (($s['space'] ?? '') === $space && !empty($s['template_id'])) $ids[(int)$s['template_id']] = true;
    }
    return $ids;
}

/** The generators that read this page: as their template, or as a list they depend on. */
function wiki_gen_dependents(string $space, int $id): array {
    $page = $space . ':' . $id;
    $out = [];
    foreach (wiki_gen_state_all() as $key => $s) {
        if ($key === $page || isset($s['deps'][$page])) $out[] = $key;
    }
    return $out;
}

/** Called by PageIndexer after a page is created, saved or deleted. Never throws. */
function wiki_gen_on_event(string $space, string $event, string $path, int $id): void {
    if ($space === '' || $id <= 0 || wiki_gen_origin() !== null) return;
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA || !defined('PAGES_DIR')) return;
    try {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'md' && $ext !== 'list') return;
        $self  = wiki_gen_key($space, $id);
        $known = wiki_gen_dependents($space, $id);

        $meta = null;
        if ($ext === 'md' && $event !== 'delete') {
            // A cheap look before a parse: most pages are not templates.
            $abs  = rtrim(PAGES_DIR, '/') . '/' . $space . '/' . $path;
            $head = (string)@file_get_contents($abs, false, null, 0, 4096);
            if (strpos($head, 'generator_source') !== false) {
                $meta = wiki_fm_split((string)file_get_contents($abs))['meta'];
                if (!wiki_gen_is_template($meta)) $meta = null;
            }
        }
        if (!$known && $meta === null) return;

        $owner = $meta !== null ? wiki_gen_acting_person() : null;
        wiki_gen_state_mutate(function (array &$g) use ($known, $self, $space, $id, $meta, $owner) {
            foreach ($known as $k) if (isset($g[$k])) $g[$k]['dirty'] = true;
            if ($meta === null) return;
            $s = $g[$self] ?? ['space' => $space, 'template_id' => $id, 'rows' => []];
            $s['owner'] = $owner;
            $s['dirty'] = true;
            $g[$self] = $s;
        });
        foreach (array_unique(array_merge($known, $meta !== null ? [$self] : [])) as $k) {
            $GLOBALS['_wiki_gen_pending'][$k] = true;
        }
        if (empty($GLOBALS['_wiki_gen_shutdown'])) {
            $GLOBALS['_wiki_gen_shutdown'] = true;
            register_shutdown_function('wiki_gen_after_response');
        }
    } catch (\Throwable $e) {
        // A save must never fail because of a generator; the cron tick will catch up.
    }
}

/**
 * The person behind the write in progress, as an owner record — or null when it is an AI
 * user, an API account, a workflow or a generator. With authentication off there are no
 * people to tell apart and every check passes, so null is fine there too.
 */
function wiki_gen_acting_person(): ?array {
    if (!function_exists('wiki_workflow_actor')) return null;
    $a = wiki_workflow_actor();
    if (!empty($a['is_ai'])) return null;
    $uid = (int)($a['uid'] ?? 0);
    if ($uid <= 0 || !wiki_gen_person($uid)) return null;
    return ['uid' => $uid, 'name' => $a['name'] ?? null];
}

/**
 * The generators this request made dirty, rendered once the response has gone. Not from
 * the command line: the cron runner renders in its own tick, and other CLI tools leave the
 * work to it.
 */
function wiki_gen_after_response(): void {
    $keys = array_keys($GLOBALS['_wiki_gen_pending'] ?? []);
    $GLOBALS['_wiki_gen_pending'] = [];
    if (!$keys || PHP_SAPI === 'cli') return;
    // Release the session first: the person who saved is about to make their next request,
    // and PHP's session lock would hold it until these renders finish.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);
    @set_time_limit(120);
    wiki_gen_run_dirty($keys, WIKI_GEN_INLINE_BUDGET);
}

/**
 * Render dirty generators until the budget is spent; what is left stays dirty for the next
 * tick. $only limits it to these keys. Returns one log line per render.
 */
function wiki_gen_run_dirty(?array $only, float $budget, ?callable $progress = null): array {
    $start = microtime(true);
    $lines = [];
    foreach (wiki_gen_state_all() as $key => $s) {
        if (empty($s['dirty'])) continue;
        if ($only !== null && !in_array($key, $only, true)) continue;
        if (microtime(true) - $start > $budget) break;
        $tid = (int)($s['template_id'] ?? 0);
        $space = (string)($s['space'] ?? '');
        if ($tid <= 0 || $space === '') continue;
        $r = wiki_gen_render($space, $tid);
        $c = $r['counts'] ?? [];
        $wrote = array_sum(array_intersect_key($c, array_flip(['created', 'updated', 'renamed', 'archived'])));
        // A generator waiting on something (an owner, a thawed Space) is retried every tick;
        // say so once, not every two minutes.
        if ($wrote === 0 && $r['status'] === ($s['status'] ?? null)) { if ($progress) $progress(); continue; }
        $lines[] = "$key: {$r['status']}" . (isset($r['error']) ? " ({$r['error']})" : '')
                 . ' — ' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys(array_filter($c)), array_filter($c)));
        if ($progress) $progress();
    }
    return $lines;
}

/**
 * Register templates no save ever registered — dropped in by rsync, or there before this
 * feature. Without an owner: a person has to vouch for one before it reads anything (unless
 * authentication is off). Hourly from the cron tick; $force runs it now (the admin overview,
 * which must not miss a template because the hour is not up). Returns the keys it added.
 */
function wiki_gen_discover(bool $force): array {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return [];
    $stamp = rtrim(WIKI_SYSTEM_DATA, '/') . '/generators.discover';
    if (!$force && is_file($stamp) && time() - (int)@filemtime($stamp) < WIKI_GEN_DISCOVER_EVERY) return [];
    @touch($stamp);
    $known = wiki_gen_state_all();
    $found = [];
    foreach (glob(rtrim(PAGES_DIR, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $space = basename($dir);
        if (!wiki_gen_space_name_ok($space)) continue;
        $i = new PageIndexer($dir);
        foreach ($i->getAllPages() as $pid => $e) {
            $rel = (string)($e['path'] ?? '');
            if (isset($known[$space . ':' . $pid]) || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'md') continue;
            $head = (string)@file_get_contents($dir . '/' . $rel, false, null, 0, 4096);
            if (strpos($head, 'generator_source') === false) continue;
            $meta = wiki_fm_split((string)file_get_contents($dir . '/' . $rel))['meta'];
            if (wiki_gen_is_template($meta)) $found[$space . ':' . $pid] = [$space, (int)$pid];
        }
    }
    if ($found) {
        wiki_gen_state_mutate(function (array &$g) use ($found) {
            foreach ($found as $k => [$space, $pid]) {
                if (isset($g[$k])) continue;
                $g[$k] = ['space' => $space, 'template_id' => $pid, 'rows' => [],
                          'owner' => null, 'dirty' => true];
            }
        });
    }
    return array_keys($found);
}

/**
 * The cron tick: find what changed without an event, then render what is dirty.
 *  - drift: a template or a list it read whose revision is not the one last rendered from;
 *  - discovery, hourly: a template no save ever registered (it arrived by rsync, or was
 *    there before this feature). Registered without an owner — a person has to vouch for it
 *    before it reads anything, unless authentication is off.
 */
function wiki_gen_tick(?callable $progress = null): array {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return [];
    $lines = [];
    $indexers = [];
    $ix = function (string $space) use (&$indexers) {
        if (!wiki_gen_space_name_ok($space) || !is_dir(rtrim(PAGES_DIR, '/') . '/' . $space)) return null;
        return $indexers[$space] ??= new PageIndexer(rtrim(PAGES_DIR, '/') . '/' . $space);
    };
    $rev = function (string $space_id) use ($ix): string {
        [$sp, $id] = array_pad(explode(':', $space_id, 2), 2, '');
        $i = $ix($sp);
        $rel = $i ? $i->getPath((int)$id) : null;
        $abs = $rel !== null ? rtrim(PAGES_DIR, '/') . '/' . $sp . '/' . $rel : null;
        return $abs !== null && is_file($abs) ? sha1_file($abs) : '';
    };

    // ── Drift ────────────────────────────────────────────────────────────
    $drifted = [];
    foreach (wiki_gen_state_all() as $key => $s) {
        if (!empty($s['dirty'])) continue;
        $changed = ($s['template_rev'] ?? '') !== $rev($key);
        foreach (($s['deps'] ?? []) as $dep => $r) {
            if ($changed) break;
            if ($rev($dep) !== $r) $changed = true;
        }
        if ($changed) $drifted[] = $key;
    }

    if ($drifted) {
        wiki_gen_state_mutate(function (array &$g) use ($drifted) {
            foreach ($drifted as $k) if (isset($g[$k])) $g[$k]['dirty'] = true;
        });
        foreach ($drifted as $k) $lines[] = "$k: changed outside the wiki";
    }

    // ── Discovery, hourly ────────────────────────────────────────────────
    foreach (wiki_gen_discover(false) as $k) $lines[] = "$k: template found";

    return array_merge($lines, wiki_gen_run_dirty(null, WIKI_GEN_CRON_BUDGET, $progress));
}

// =================================================================
// THE LOCK — a generated page's body belongs to its template
//
// A generated page is rewritten on every render, so an edit to its body would be silently
// lost, and an edit that *stuck* would be a page claiming to show the list while it does
// not. So its body is not writable by anyone but its generator. Enforced at the two points
// every write converges on, like a read-only Space: the api.php bootstrap guard (save,
// append-from-chat, git restore, delete, move) and execute_ai_tool() (the AI write tools,
// for REST, MCP and the cron runner alike). The bulk rewriters — wikilink retargeting
// after a rename — skip generated pages instead of refusing: the link to fix is in the
// template, and the next render carries it.
//
// Its front matter stays the page's: tags, an owner, a review date. Removing
// `generated_by` there is a detach, the same as the Detach action, which just does it
// properly — it records `detached_from`, marks the row so it is not rendered again, and
// commits with a message that says what happened.
// =================================================================

/**
 * The generator a page belongs to — ['template_id' => int, 'row' => int] — or null for
 * any other page. A prefix read first; most pages are not generated.
 */
function wiki_gen_page_generated(string $abs): ?array {
    if (strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'md' || !is_file($abs)) return null;
    $head = (string)@file_get_contents($abs, false, null, 0, 4096);
    if (strpos($head, 'generated_by') === false) return null;
    $meta = wiki_fm_split((string)file_get_contents($abs))['meta'];
    $tid = is_string($meta['generated_by'] ?? null) ? trim($meta['generated_by']) : '';
    if ($tid === '' || !ctype_digit($tid)) return null;
    return ['template_id' => (int)$tid, 'row' => (int)($meta['generated_row'] ?? 0)];
}

/**
 * Is this write to $abs refused because the page is generated? The generator's own
 * writes are not. Returns the page's generator info when refused, else null.
 */
function wiki_gen_write_refused(string $abs): ?array {
    if (wiki_gen_origin() !== null) return null;
    return wiki_gen_page_generated($abs);
}

/** The refusal, in English for logs and AI tools; the client words it from `generated`. */
function wiki_gen_locked_message(array $g): string {
    return 'This page is generated from a template (page id ' . $g['template_id'] . ') and cannot be edited directly. '
         . 'Change the template or its source list, or detach the page to edit it by hand.';
}

/**
 * Detach a generated page: it keeps its text and becomes an ordinary page. Its row is
 * marked so the generator does not make a new page for it. Returns null on success, or an
 * error code ('not_generated', 'write_failed').
 */
function wiki_gen_detach(string $space_dir, string $rel, $indexer, array $actor): ?string {
    $abs = rtrim($space_dir, '/') . '/' . $rel;
    $g = wiki_gen_page_generated($abs);
    if ($g === null) return 'not_generated';
    $raw = (string)file_get_contents($abs);
    $new = wiki_fm_set($raw, ['detached_from' => (string)$g['template_id'], 'detached_row' => (string)$g['row']],
                       ['generated_by', 'generated_row']);
    if (file_put_contents($abs, $new) === false) return 'write_failed';
    $indexer->updateModified($rel, $actor['uid'] ?? null, $actor['name'] ?? null);
    $space = basename(rtrim($space_dir, '/'));
    $key = wiki_gen_key($space, $g['template_id']);
    $pid = (int)$indexer->getId($rel);
    wiki_gen_state_mutate(function (array &$gs) use ($key, $g, $pid) {
        if (!isset($gs[$key])) return;
        $gs[$key]['rows'][(string)$g['row']] = ['detached' => true, 'page_id' => $pid];
    });
    if (function_exists('git_auto_commit')) {
        git_auto_commit($abs, (string)($actor['name'] ?? 'Wiki'), 'wiki@localhost',
                        'Detach ' . basename($rel) . ' from its template', $space_dir);
    }
    return null;
}

/**
 * What `get` tells the client about a page's place in a generator, from its parsed front
 * matter: ['state' => 'generated' | 'archived' | 'detached', 'template_id', 'template_path',
 * 'row'], or null for an ordinary page. The template path is looked up in this Space's
 * index, so a deleted template reads as null and the client words it accordingly.
 */
function wiki_gen_get_info(array $meta, $indexer): ?array {
    foreach (['generated' => ['generated_by', 'generated_row'],
              'archived'  => ['archived_from', 'archived_row'],
              'detached'  => ['detached_from', 'detached_row']] as $state => [$k_tpl, $k_row]) {
        $tid = is_string($meta[$k_tpl] ?? null) ? trim($meta[$k_tpl]) : '';
        if ($tid === '' || !ctype_digit($tid)) continue;
        $path = $indexer ? $indexer->getPath((int)$tid) : null;
        return ['state' => $state, 'template_id' => (int)$tid, 'template_path' => $path,
                'row' => (int)($meta[$k_row] ?? 0)];
    }
    return null;
}

// =================================================================
// FOR THE UI — a template's status, a preview, the lists to choose from, a new template
//
// Each takes the requester's Space allowlist ($acl, null = all) and never shows anything
// from a Space outside it: a template's banner names its source list only to someone who
// may read that Space, and a preview renders rows only for them — it is the same data the
// generated pages publish, but a template can be changed to show more than they do.
// =================================================================

/** The banner of a template page: what it reads, where it writes, how its last render went. */
function wiki_gen_template_info(string $space, int $id, array $meta, ?array $acl): ?array {
    if ($space === '' || $id <= 0 || !wiki_gen_is_template($meta)) return null;
    $ix = new PageIndexer(rtrim(PAGES_DIR, '/') . '/' . $space);
    $def = wiki_gen_definition($meta, $space, (string)$ix->getPath($id));
    $st  = wiki_gen_state_get(wiki_gen_key($space, $id)) ?? [];
    $out = [
        'status'   => $st['status'] ?? null,     // null: registered, never rendered yet
        'pending'  => !empty($st['dirty']),
        'error'    => $st['error'] ?? ($def['ok'] ? null : $def['error']),
        'detail'   => $st['detail'] ?? ($def['ok'] ? null : $def['detail']),
        'line'     => $st['line'] ?? null,
        'counts'   => $st['counts'] ?? null,
        'warnings' => $st['warnings'] ?? [],
        'owner'    => $st['owner']['name'] ?? null,
        'last_run' => $st['last_run'] ?? null,
        'pages'    => count(array_filter($st['rows'] ?? [], fn($r) => !empty($r['page_id']) && empty($r['detached']))),
        'folder'   => $def['ok'] ? $def['folder'] : null,
        'source'   => null,
    ];
    if ($def['ok']) {
        $readable = $acl === null || in_array($def['source_space'], $acl, true);
        $src_path = null;
        if ($readable && is_dir(rtrim(PAGES_DIR, '/') . '/' . $def['source_space'])) {
            $src_ix = $def['source_space'] === $space ? $ix : new PageIndexer(rtrim(PAGES_DIR, '/') . '/' . $def['source_space']);
            $src_path = $src_ix->getPath($def['source_id']);
        }
        $out['source'] = ['space' => $def['source_space'], 'id' => $def['source_id'],
                          'path' => $src_path, 'readable' => $readable];
    }
    return $out;
}

/**
 * Render one row of a template without writing anything — the template as saved, or
 * $body / $filename when the client sends a draft. Returns ['ok' => true, 'rows' =>
 * [[id, title]…], 'row', 'name', 'body', 'warnings'] or ['ok' => false, 'error', 'detail', 'line'?].
 */
function wiki_gen_preview(string $space, int $id, ?int $row, ?array $acl): array {
    $dir = rtrim(PAGES_DIR, '/') . '/' . $space;
    if (!wiki_gen_space_name_ok($space) || !is_dir($dir)) return ['ok' => false, 'error' => 'template_missing', 'detail' => ''];
    $ix  = new PageIndexer($dir);
    $rel = $ix->getPath($id);
    if ($rel === null || !is_file($dir . '/' . $rel)) return ['ok' => false, 'error' => 'template_missing', 'detail' => ''];
    $split = wiki_fm_split((string)file_get_contents($dir . '/' . $rel));
    $def = wiki_gen_definition($split['meta'], $space, $rel);
    if (!$def['ok']) return ['ok' => false, 'error' => $def['error'], 'detail' => $def['detail']];
    if ($acl !== null && !in_array($def['source_space'], $acl, true)) return ['ok' => false, 'error' => 'no_source_access', 'detail' => $def['source_space']];

    $src_dir = rtrim(PAGES_DIR, '/') . '/' . $def['source_space'];
    $src_ix  = $def['source_space'] === $space ? $ix : (is_dir($src_dir) ? new PageIndexer($src_dir) : null);
    $src_rel = $src_ix ? $src_ix->getPath($def['source_id']) : null;
    if ($src_rel === null || !is_file($src_dir . '/' . $src_rel)) return ['ok' => false, 'error' => 'source_missing', 'detail' => ''];
    if (strtolower(pathinfo($src_rel, PATHINFO_EXTENSION)) !== 'list') return ['ok' => false, 'error' => 'source_not_list', 'detail' => $src_rel];
    $list = json_decode((string)file_get_contents($src_dir . '/' . $src_rel), true);
    if (!is_array($list)) return ['ok' => false, 'error' => 'source_unreadable', 'detail' => $src_rel];

    try { $body_tree = wiki_tpl_parse($split['body']); }
    catch (WikiTemplateError $e) {
        return ['ok' => false, 'error' => 'template_error', 'detail' => $e->error . ($e->detail !== '' ? ': ' . $e->detail : ''),
                'line' => $e->templateLine + substr_count($split['block'], "\n")];
    }
    try { $name_tree = wiki_tpl_parse($def['filename']); }
    catch (WikiTemplateError $e) { return ['ok' => false, 'error' => 'filename_error', 'detail' => $e->error]; }

    $data = wiki_gen_rows($list, $src_dir, ['space' => $def['source_space'], 'title' => basename($src_rel, '.list'),
        'url' => 'index.php?pageid=' . $def['source_id'] . '&space=' . rawurlencode($def['source_space'])]);
    $rows = [];
    foreach ($data['rows'] as $rid => $ctx) $rows[] = ['id' => $rid, 'title' => $ctx['_title']];
    if (!$data['rows']) return ['ok' => true, 'rows' => [], 'row' => null, 'name' => '', 'body' => '', 'warnings' => $data['warnings']];
    $rid = ($row !== null && isset($data['rows'][$row])) ? $row : array_key_first($data['rows']);
    $ctx = $data['rows'][$rid];
    $warnings = $data['warnings'];
    $unknown = array_values(array_diff(array_merge(wiki_tpl_names($body_tree), wiki_tpl_names($name_tree)),
                                       array_merge($data['keys'], ['_row', '_title', '_source'])));
    if ($unknown) $warnings[] = ['warning' => 'unknown_field', 'detail' => implode(', ', $unknown)];
    return ['ok' => true, 'rows' => $rows, 'row' => $rid,
            'name' => wiki_gen_clean_name(wiki_tpl_render($name_tree, $ctx, ['escape' => 'none']), $rid),
            'body' => wiki_tpl_render($body_tree, $ctx), 'warnings' => $warnings];
}

/**
 * Every list the requester may read, in every Space, with its fields as template keys —
 * what the New → Generated pages dialog offers. Columns only, never rows.
 */
function wiki_gen_sources(?array $acl, int $max = 500): array {
    $out = [];
    foreach (glob(rtrim(PAGES_DIR, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $space = basename($dir);
        if (!wiki_gen_space_name_ok($space) || ($acl !== null && !in_array($space, $acl, true))) continue;
        $ix = new PageIndexer($dir);
        foreach ($ix->getAllPages() as $pid => $e) {
            $rel = (string)($e['path'] ?? '');
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'list' || !is_file($dir . '/' . $rel)) continue;
            $list = json_decode((string)file_get_contents($dir . '/' . $rel), true);
            if (!is_array($list)) continue;
            $fields = [];
            $used = [];
            foreach ($list['columns'] ?? [] as $col) {
                // The same keys wiki_gen_rows() gives the template.
                $base = wiki_tpl_key((string)($col['name'] ?? '')) ?: wiki_tpl_key((string)($col['id'] ?? ''));
                if ($base === '' || $base[0] === '_') $base = 'f_' . ltrim($base, '_');
                $key = $base;
                for ($n = 2; isset($used[$key]); $n++) $key = $base . '_' . $n;
                $used[$key] = true;
                $fields[] = ['name' => (string)($col['name'] ?? $key), 'key' => $key, 'type' => (string)($col['type'] ?? ''),
                             'title' => ($col['id'] ?? null) === wiki_list_title_field($list)];
            }
            $out[] = ['space' => $space, 'id' => (int)$pid, 'path' => $rel, 'title' => basename($rel, '.list'),
                      'fields' => $fields, 'rows' => count($list['items'] ?? [])];
            if (count($out) >= $max) return $out;
        }
    }
    usort($out, fn($a, $b) => [$a['space'], $a['path']] <=> [$b['space'], $b['path']]);
    return $out;
}

/**
 * The text of a new template: its generator keys, and a body that shows every field, so
 * the first render already says something and the author edits rather than starts blank.
 */
function wiki_gen_starter(string $source, string $folder, string $filename_key, array $fields): string {
    $body = "# {{_title}}\n\n| | |\n|---|---|\n";
    foreach ($fields as $f) {
        if (!empty($f['title'])) continue;
        $label = str_replace('|', '\\|', (string)$f['name']);
        $body .= "| **{$label}** | {{" . $f['key'] . "}} |\n";
    }
    $body .= "\n";
    $updates = ['generator_source' => $source, 'generator_folder' => $folder];
    if ($filename_key !== '') $updates['generator_filename'] = '{{' . $filename_key . '}}';
    return wiki_fm_set($body, $updates);
}

// =================================================================
// SPACES RENAMED AND DELETED
//
// A generator names its source Space in its template's front matter ("CRM:87"), and its
// state is keyed by the target Space ("Customers:412"). A Space rename would otherwise
// break the first silently — source_missing, pages frozen — and orphan the second, so the
// rename action calls this beside wiki_workflows_space_renamed(). Ids do not change in a
// rename, so generated pages need nothing.
// =================================================================

function wiki_gen_space_renamed(string $old, string $new): void {
    if ($old === $new || !defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return;
    $templates = [];   // template files whose generator_source names the old Space
    wiki_gen_state_mutate(function (array &$g) use ($old, $new, &$templates) {
        $out = [];
        foreach ($g as $key => $s) {
            if (($s['space'] ?? '') === $old) {
                $s['space'] = $new;
                $key = wiki_gen_key($new, (int)($s['template_id'] ?? 0));
            }
            if (($s['source']['space'] ?? '') === $old) {
                $s['source']['space'] = $new;
                if (!empty($s['template_path'])) $templates[$key] = [$s['space'], (string)$s['template_path']];
            }
            if (is_array($s['deps'] ?? null)) {
                $deps = [];
                foreach ($s['deps'] as $dep => $rev) {
                    [$sp, $id] = array_pad(explode(':', (string)$dep, 2), 2, '');
                    $deps[($sp === $old ? $new : $sp) . ':' . $id] = $rev;
                }
                $s['deps'] = $deps;
            }
            $out[$key] = $s;
        }
        $g = $out;
    });
    foreach ($templates as $key => [$space, $rel]) {
        $abs = rtrim(PAGES_DIR, '/') . '/' . $space . '/' . $rel;
        if (!is_file($abs)) continue;
        $raw = (string)file_get_contents($abs);
        $src = (string)(wiki_fm_split($raw)['meta']['generator_source'] ?? '');
        if (!preg_match('/^(.+):(\d+)$/', trim($src), $m) || trim($m[1]) !== $old) continue;
        $out = wiki_fm_set($raw, ['generator_source' => $new . ':' . $m[2]]);
        if ($out === $raw || file_put_contents($abs, $out) === false) continue;
        // The template changed only by the rename: record its new revision, or the cron
        // drift check would render every page again for nothing.
        wiki_gen_state_mutate(function (array &$g) use ($key, $out) {
            if (isset($g[$key])) $g[$key]['template_rev'] = sha1($out);
        });
        if (function_exists('git_auto_commit')) {
            git_auto_commit($abs, 'Wiki', 'wiki@localhost', 'Space "' . $old . '" renamed to "' . $new . '": update ' . basename($rel),
                            rtrim(PAGES_DIR, '/') . '/' . $space);
        }
    }
}

/** A deleted Space's generators are forgotten. Generators elsewhere that read from it stop
 *  with source_missing and keep their pages, as for any vanished list. */
function wiki_gen_space_deleted(string $name): void {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return;
    wiki_gen_state_mutate(function (array &$g) use ($name) {
        foreach (array_keys($g) as $k) if (($g[$k]['space'] ?? '') === $name) unset($g[$k]);
    });
}

// =================================================================
// ADMIN → CONTENT → GENERATORS
//
// Every generator in the wiki in one table, for an administrator — the only place a
// generator that has stopped (an owner without access, a broken template, a vanished
// list) shows up without opening its template. Read-only: a row's actions are the same
// generator_render the template's banner uses, and opening the template.
// =================================================================

function wiki_gen_admin_list(): array {
    wiki_gen_discover(true);
    $ix = [];
    $index = function (string $space) use (&$ix) {
        if (!wiki_gen_space_name_ok($space) || !is_dir(rtrim(PAGES_DIR, '/') . '/' . $space)) return null;
        return $ix[$space] ??= new PageIndexer(rtrim(PAGES_DIR, '/') . '/' . $space);
    };
    $out = [];
    foreach (wiki_gen_state_all() as $key => $s) {
        $space = (string)($s['space'] ?? '');
        $tid   = (int)($s['template_id'] ?? 0);
        $i     = $index($space);
        $path  = $i ? $i->getPath($tid) : null;
        $abs   = $path !== null ? rtrim(PAGES_DIR, '/') . '/' . $space . '/' . $path : null;
        $def   = null;
        if ($abs !== null && is_file($abs)) {
            $d = wiki_gen_definition(wiki_fm_split((string)file_get_contents($abs))['meta'], $space, $path);
            if ($d['ok']) $def = $d;
        }
        $src = $def ? ['space' => $def['source_space'], 'id' => $def['source_id']]
                    : (is_array($s['source'] ?? null) ? $s['source'] : null);
        if ($src) {
            $si = $index((string)$src['space']);
            $src['path'] = $si ? $si->getPath((int)$src['id']) : null;
        }
        $out[] = [
            'key'           => $key,
            'space'         => $space,
            'template_id'   => $tid,
            'template_path' => $path ?? ($s['template_path'] ?? null),
            'template_exists' => $abs !== null && is_file($abs),
            'source'        => $src,
            'folder'        => $def['folder'] ?? null,
            'pages'         => count(array_filter($s['rows'] ?? [], fn($r) => !empty($r['page_id']) && empty($r['detached']))),
            'owner'         => $s['owner']['name'] ?? null,
            'status'        => $s['status'] ?? null,
            'pending'       => !empty($s['dirty']),
            'error'         => $s['error'] ?? null,
            'detail'        => $s['detail'] ?? null,
            'line'          => $s['line'] ?? null,
            'warnings'      => $s['warnings'] ?? [],
            'counts'        => $s['counts'] ?? null,
            'last_run'      => $s['last_run'] ?? null,
        ];
    }
    usort($out, fn($a, $b) => [$a['space'], (string)$a['template_path']] <=> [$b['space'], (string)$b['template_path']]);
    return $out;
}
