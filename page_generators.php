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
// **HTML templates.** A template whose front matter says `format: html` is parsed in
// mustache.php's HTML mode — each value escaped for the place in the HTML it lands, and the
// template refused where no escaping would be safe — and its pages are written with
// `format: html` too, which the page view shows as HTML. A template switched back to Markdown
// takes the key off its pages. Detach and archive leave it: the page stays HTML.
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

/** Is this page's body HTML (`format: html` in its front matter)? */
function wiki_gen_is_html(array $meta): bool {
    return is_string($meta['format'] ?? null) && strtolower(trim($meta['format'])) === 'html';
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
/**
 * A group's sub-fields as template keys, by sub-field id — the field rule (wiki_tpl_key of the
 * name, `_2` on a clash), with `id` reserved: entries carry `_id`, and a sub-field "Id" as
 * `id` would read as the same thing. Shared by the rows and the New dialog's starter.
 */
function wiki_gen_sub_keys(array $col): array {
    $keys = [];
    $used = ['id' => true];
    foreach ($col['fields'] ?? [] as $sf) {
        $base = wiki_tpl_key((string)($sf['name'] ?? '')) ?: wiki_tpl_key((string)($sf['id'] ?? ''));
        if ($base === '') continue;
        $k = $base;
        for ($n = 2; isset($used[$k]); $n++) $k = $base . '_' . $n;
        $used[$k] = true;
        $keys[(string)$sf['id']] = $k;
    }
    return $keys;
}

/**
 * A list's fields as template keys, by column id: wiki_tpl_key of the name (of the id when the
 * name has none), `f_` in front of one starting with `_` (those are the built-ins'), and `_2`,
 * `_3`, … on a clash, in column order — each clash added to $warnings. One rule for a list's
 * own fields, for the fields of the items its lookups point at, and for the New dialog.
 */
function wiki_gen_field_keys(array $columns, ?array &$warnings = null): array {
    $keymap = [];
    $used   = [];
    foreach ($columns as $col) {
        $id = (string)($col['id'] ?? '');
        if ($id === '') continue;
        $base = wiki_tpl_key((string)($col['name'] ?? '')) ?: wiki_tpl_key($id);
        if ($base === '' || $base[0] === '_') $base = 'f_' . ltrim($base, '_');
        $key = $base;
        for ($n = 2; isset($used[$key]); $n++) $key = $base . '_' . $n;
        if ($key !== $base && $warnings !== null) {
            $warnings[] = ['warning' => 'duplicate_field', 'detail' => (string)($col['name'] ?? $id) . ' → ' . $key];
        }
        $used[$key] = true;
        $keymap[$id] = $key;
    }
    return $keymap;
}

function wiki_gen_rows(array $list, string $source_dir, array $source): array {
    $warnings = [];
    $flat  = wiki_list_flatten($list, $source_dir);
    $title = wiki_list_title_field($list);

    $keymap = wiki_gen_field_keys($list['columns'] ?? [], $warnings);   // column id => template key

    // A Group of fields is given as the structure it is, not its text: its sub-fields under
    // keys of their own (the same rule as fields), `_text` its one-line summary, and — for one
    // allowing several — a list of entries, each with `_id`, `_index` (from 1), `_first`, `_last`.
    $groups = [];   // column id => [column, sub-field id => key]
    foreach ($list['columns'] ?? [] as $col) {
        if (($col['type'] ?? '') !== 'object' || !isset($keymap[$col['id'] ?? ''])) continue;
        $groups[$col['id']] = [$col, wiki_gen_sub_keys($col)];
    }
    // Numbers and Yes/No are given as what they are, not their export text:
    //   a Number   — {{price}} "1250.00 kr" (its decimals and unit), {{price.value}} "1250.00",
    //                {{price.unit}}; no value is null, so {{#price}}…{{/price}} hides;
    //   a Yes/No   — a boolean: {{#paid}}…{{/paid}} / {{^paid}}…{{/paid}}, {{paid}} Yes or No.
    // An item's comments are the built-in {{#_comments}}{{name}} {{at}} {{text}}{{/_comments}},
    // oldest first, each with `_index`, `_first`, `_last`; {{_comments}} alone is all of them on
    // one line. (Comments are the item's, not a field: lists.php.)
    $typed = [];    // column id => column
    foreach ($list['columns'] ?? [] as $col) {
        if (in_array($col['type'] ?? '', ['number', 'boolean'], true) && isset($keymap[$col['id'] ?? ''])) $typed[$col['id']] = $col;
    }
    // A Lookup is given as the items it points at, like a group: each with the target item's
    // own fields under their keys (as that list shows them), `_id`, `_title` (its Title) and
    // `_text` (what the lookup displays), `_edit` (a button editing it). One that allows several is a list of them, with
    // `_index`, `_first`, `_last`; a single one is the item, or null. So {{customer}} is still
    // the text the list shows, joined with "; ", while {{#customer}}…{{customer.city}}… and
    // {{#contacts}}<card>{{/contacts}} reach the items. An item since deleted has empty fields,
    // `_id`, `_text` "(deleted #12)" and `_missing`; a target that cannot be read gives "#12".
    // The source Space, as the edit buttons' data-space (lookups resolve in it too).
    $edit_space = htmlspecialchars((string)($source['space'] ?? ''), ENT_QUOTES);
    $lookups = [];  // column id => [column, target flattened or null, target keymap, display field, title field]
    $lk_targets = null;
    foreach ($list['columns'] ?? [] as $col) {
        if (($col['type'] ?? '') !== 'lookup' || !isset($keymap[$col['id'] ?? ''])) continue;
        $lk_targets ??= wiki_list_lookup_targets($list, $source_dir);
        $target = $lk_targets[(string)($col['lookup']['list'] ?? '')] ?? null;
        $lookups[$col['id']] = $target === null ? [$col, null, [], null, null] : [
            $col, wiki_list_flatten($target, $source_dir), wiki_gen_field_keys($target['columns'] ?? []),
            $col['lookup']['field'] ?? wiki_list_title_field($target), wiki_list_title_field($target),
        ];
    }
    $lookup_ctx = function (array $lk, $value) use ($edit_space) {
        [$col, $target, $tkeys, $show, $tfield] = $lk;
        $tlist = (int)($col['lookup']['list'] ?? 0);
        $entries = [];
        foreach (_wiki_list_ids($value) as $iid) {
            $hit = $target !== null ? _wiki_list_find_item($target, $iid) : null;
            if ($hit === null) {
                // Its fields present and empty: a missing key would be looked up in the row
                // around it, and {{_title}} in a card would print the row's own title.
                $entries[] = array_fill_keys(array_values($tkeys), '') + ['_title' => '', '_edit' => null,
                    '_id' => $iid, '_text' => $target !== null ? "(deleted #$iid)" : '#' . $iid, '_missing' => true];
                continue;
            }
            $e = [];
            foreach ($tkeys as $cid => $k) {
                $v = $hit[$cid] ?? '';
                $e[$k] = is_scalar($v) ? (string)$v : '';
            }
            $e['_id']    = $iid;
            $e['_title'] = $tfield !== null && is_scalar($hit[$tfield] ?? null) ? (string)$hit[$tfield] : '';
            $e['_text']  = $show !== null && is_scalar($hit[$show] ?? null) ? (string)$hit[$show] : '';
            // {{_edit}} inside the item's section: a link icon that opens the *linked* item in
            // its own list's form (same Space — a lookup's target always is). Saving that list
            // re-renders this page too, since the generator reads it (`deps`).
            $e['_edit']  = $tlist > 0 ? new WikiTplMarkup('<button type="button" class="wk-edit wk-linked" data-space="'
                . $edit_space . '" data-list="' . $tlist . '" data-item="' . $iid . '"></button>') : null;
            $entries[] = $e;
        }
        // Single — unless it still holds several, from before "Allow several" was turned off.
        if (empty($col['lookup']['multiple']) && count($entries) <= 1) return $entries[0] ?? null;
        $n = count($entries);
        foreach ($entries as $x => &$e) $e += ['_index' => $x + 1, '_first' => $x === 0, '_last' => $x === $n - 1];
        return $entries;
    };
    $comments_ctx = function ($v): array {
        $entries = wiki_list_comment_entries($v);
        $n = count($entries);
        return array_map(fn($c, $x) => [
            'name' => (string)($c['name'] ?? ''), 'at' => wiki_list_comment_time($c['at'] ?? null), 'text' => $c['text'],
            '_text' => trim((string)($c['name'] ?? '') . ' (' . wiki_list_comment_time($c['at'] ?? null) . ')') . ': ' . $c['text'],
            '_id' => (int)($c['id'] ?? 0), '_index' => $x + 1, '_first' => $x === 0, '_last' => $x === $n - 1,
        ], $entries, array_keys($entries));
    };
    $typed_ctx = function (array $col, $v) {
        switch ($col['type']) {
            case 'number':
                $num = wiki_list_number_text($col, $v);
                if ($num === '') return null;
                $unit = trim((string)($col['unit'] ?? ''));
                return ['_text' => $unit !== '' ? "$num $unit" : $num, 'value' => $num, 'unit' => $unit];
            default:
                return $v === true;
        }
    };
    // {{_edit}}: a button that opens this row's item for editing, over the generated page
    // (modules/generators). The wiki's own markup, carrying only ids, so it is written as it
    // is (WikiTplMarkup); the page view shows it only to someone who may edit the list.
    $edit_list  = (int)($source['id'] ?? 0);
    $comments_allowed = wiki_list_comments_on($list);

    $entry_ctx = function (array $col, array $subkeys, array $entry): array {
        $out = [];
        foreach ($subkeys as $sid => $k) {
            $v = $entry[$sid] ?? '';
            // A choice allowing several values holds a list: printed joined, as the table does.
            if (is_array($v) && array_is_list($v)) $v = implode('; ', array_map('strval', array_filter($v, 'is_scalar')));
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
            if (isset($typed[$cid])) {
                $ctx[$key] = $typed_ctx($typed[$cid], $raw_items[$i][$cid] ?? null);
                continue;
            }
            if (isset($lookups[$cid])) {
                $ctx[$key] = $lookup_ctx($lookups[$cid], $raw_items[$i][$cid] ?? null);
                continue;
            }
            $v = $item[$cid] ?? '';
            $ctx[$key] = is_array($v) ? implode('; ', array_map('strval', array_filter($v, 'is_scalar'))) : (string)$v;
        }
        $ctx['_edit']   = $edit_list > 0
            ? new WikiTplMarkup('<button type="button" class="wk-edit" data-space="' . $edit_space . '" data-list="' . $edit_list . '" data-item="' . $rid . '"></button>')
            : null;
        // {{_add.cards}}: a button that adds an entry to one of the row's groups allowing
        // several — named, since an item may have more than one, and usable anywhere (inside
        // {{#cards}} it would repeat per entry and vanish with none). A group that does not
        // allow several, or a name that is not a group, has no button and writes nothing.
        $ctx['_add'] = [];
        if ($edit_list > 0) {
            foreach ($groups as $gid => [$gcol, $_sub]) {
                if (empty($gcol['multiple'])) continue;
                $ctx['_add'][$keymap[$gid]] = new WikiTplMarkup('<button type="button" class="wk-edit wk-add" data-space="' . $edit_space
                    . '" data-list="' . $edit_list . '" data-item="' . $rid . '" data-group="' . htmlspecialchars((string)$gid, ENT_QUOTES) . '"></button>');
            }
        }
        $ctx['_comments'] = $comments_ctx($raw_items[$i][WIKI_LIST_COMMENTS] ?? null);
        // {{_comment_form}}: a box to add a comment to the row's item, from the generated page
        // (modules/generators/edit_item.js fills it in, for people who may edit the list). Not
        // for a list that has turned comments off.
        $ctx['_comment_form'] = $edit_list > 0 && $comments_allowed
            ? new WikiTplMarkup('<div class="wk-comment-form" data-space="' . $edit_space . '" data-list="' . $edit_list . '" data-item="' . $rid . '"></div>')
            : null;
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
    $html = wiki_gen_is_html($split['meta']);
    try { $body_tree = wiki_tpl_parse($split['body'], ['html' => $html]); }
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
        'id'    => (int)$def['source_id'],
    ];
    $data = wiki_gen_rows($list, $src_dir, $source_ctx);
    $warnings = $data['warnings'];
    if (count($data['rows']) > WIKI_GEN_MAX_ROWS) {
        return $base + ['status' => 'error', 'error' => 'too_many_rows', 'detail' => (string)count($data['rows'])];
    }
    $known = array_merge($data['keys'], ['_row', '_title', '_source', '_edit', '_add', '_comments', '_comment_form']);
    $unknown = array_values(array_diff(array_merge(wiki_tpl_names($body_tree), wiki_tpl_names($name_tree)), $known));
    if ($unknown) $warnings[] = ['warning' => 'unknown_field', 'detail' => implode(', ', $unknown)];

    // ── Plan: where each row's page is, and where it should be ───────────
    $sys      = wiki_gen_system_name($tpl_rel);
    $blocked  = [];           // field => row ids where a link was blocked
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
        // A detached row stays out of the generator while its page exists — wherever it has
        // been moved, found by its id. Once that page is deleted there is nobody's own version
        // left to protect, and the row is the generator's again: a fresh page is made. (A row
        // stayed detached for good before, so detaching pages and deleting them to start over
        // left a generator that made nothing, and said "nothing changed".)
        if (!empty($prev[$rid]['detached']) || !empty($cur['detached'])) {
            $dpid = (int)($cur['page_id'] ?? $prev[$rid]['page_id'] ?? 0);
            $dpath = $dpid > 0 ? $ix->getPath($dpid) : null;
            if ($dpath !== null && is_file($target_dir . '/' . $dpath)) {
                $rows_out[$rid] = ['detached' => true, 'page_id' => $dpid];
                $counts['detached']++;
                continue;
            }
            $cur = null;
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

        wiki_tpl_take_warnings();
        $body = wiki_tpl_render($body_tree, $ctx, ['escape' => $html ? 'html' : 'markdown']);
        foreach (wiki_tpl_take_warnings() as $w) $blocked[$w['detail']][] = $rid;
        $name = wiki_gen_clean_name(wiki_tpl_render($name_tree, $ctx, ['escape' => 'none']), $rid);
        // The page an earlier, deleted generator made for this row, unedited since: take it
        // over (it keeps its id, so links to it keep working) rather than write "Ann (1)"
        // beside it — and drop the "(1)" this generator may already have made.
        $natural = ($def['folder'] === '' ? '' : $def['folder'] . '/') . $name . '.md';
        if (($cur === null || $cur['path'] !== $natural) && !isset($reserved[$natural])
            && wiki_gen_adoptable($ix, $target_dir, $natural, $rid, $def['source_space'] . ':' . $def['source_id'])) {
            if ($cur !== null) wiki_gen_remove_own($ix, $target_dir, $space, $cur['path'], $touched);
            $cur = ['path' => $natural, 'page_id' => (int)$ix->getId($natural)];
            $counts['adopted'] = ($counts['adopted'] ?? 0) + 1;
        }
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
        $changed = wiki_gen_write($ix, $target_dir, $space, $want, $body, $template_id, $rid, $sys, $touched, $html);
        if ($cur === null)  $counts['created']++;
        elseif ($changed)   $counts['updated']++;
        elseif (!isset($touched[$target_dir . '/' . $want])) $counts['unchanged']++;
        $rows_out[$rid] = ['page_id' => (int)$ix->getId($want)];
    }

    // A link the data held that is not http(s), mailto or relative was written as #blocked —
    // said once per field, with the rows it happened in.
    foreach ($blocked as $field => $rids) {
        $rids = array_values(array_unique($rids));
        $warnings[] = ['warning' => 'url_blocked', 'detail' => $field . ' (' . implode(', ', array_slice($rids, 0, 5))
            . (count($rids) > 5 ? ', …' : '') . ')'];
    }

    // ── Rows that are gone: archive their pages ──────────────────────────
    foreach ($owned as $rid => $cur) {
        if (isset($data['rows'][$rid])) continue;
        if (!empty($cur['detached'])) continue;
        if (wiki_gen_archive($ix, $target_dir, $space, $cur['path'], $template_id, $rid, $sys, $touched,
                             'row_removed', basename($src_rel, '.list'), $def['source_space'] . ':' . $def['source_id'])) {
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
    $src = is_array($state['source'] ?? null) && isset($state['source']['space'], $state['source']['id'])
        ? $state['source']['space'] . ':' . $state['source']['id'] : '';
    foreach ($owned as $rid => $cur) {
        if (wiki_gen_archive($ix, $target_dir, $space, $cur['path'], $template_id, (int)$rid, $sys, $touched, 'template_gone', '', $src)) {
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
                        int $template_id, int $rid, string $sys, array &$touched, bool $html = false): bool {
    $abs = $target_dir . '/' . $rel;
    $is_new = !is_file($abs);
    $old = $is_new ? '' : (string)file_get_contents($abs);
    $block = $is_new ? '' : wiki_fm_split($old)['block'];
    // Joined by hand, not through wiki_fm_preserve(): a rendered body that happens to start
    // with a `---` block of its own would otherwise replace the page's.
    // `format: html` follows the template: set on an HTML template's pages, taken off when the
    // template goes back to Markdown.
    $new = $block === ''
        ? "---\ngenerated_by: $template_id\ngenerated_row: $rid\n" . ($html ? "format: html\n" : '') . "---\n" . $body
        : wiki_fm_set($block . $body, ['generated_by' => (string)$template_id, 'generated_row' => (string)$rid]
                      + ($html ? ['format' => 'html'] : []),
                      array_merge($html ? [] : ['format'], WIKI_GEN_ARCHIVE_KEYS));
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
    wiki_gen_search(fn($s) => $s->upsertPage($space, $rel, wiki_fm_search_text($new)));
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log($is_new ? 'create' : 'update', 'success',
            ['object_category' => 'page', 'object' => $rel, 'space' => $space, 'change_type' => 'generated']);
    }
    $touched[$abs] = true;
    return true;
}

/** The keys an archived page carries — taken off again when a generator adopts it. */
const WIKI_GEN_ARCHIVE_KEYS = ['archived_from', 'archived_row', 'archived_at', 'archived_reason',
                               'archived_list', 'archived_source', 'archived_hash'];

/**
 * May this generator take over the archived page at $rel for row $rid? A template deleted and
 * made again (the same list, the same folder) otherwise finds its old pages in the way and
 * writes "Ann (1)" beside "Ann". Only when all of these hold:
 *  - the page was archived from a template that is gone (deleted, or no longer a generator) —
 *    not one whose list item was removed, and not one whose template still generates;
 *  - for the same row, and — where the page says (`archived_source`) — from the same list;
 *  - nobody has edited it since: its text matches `archived_hash`; a page archived before the
 *    hash was recorded must not have been modified since `archived_at`.
 * Anything else stays as it is, and the new page takes a free name as before.
 */
function wiki_gen_adoptable($ix, string $target_dir, string $rel, int $rid, string $source): bool {
    $abs = $target_dir . '/' . $rel;
    if (!is_file($abs)) return false;
    $raw = (string)file_get_contents($abs);
    $meta = wiki_fm_split($raw)['meta'];
    if (isset($meta['generated_by']) || !isset($meta['archived_from'])) return false;
    if ((int)($meta['archived_row'] ?? 0) !== $rid) return false;
    if (($meta['archived_reason'] ?? '') === 'row_removed') return false;
    $was = isset($meta['archived_source']) ? (string)$meta['archived_source'] : '';
    if ($was !== '' && $was !== $source) return false;
    // The template it came from must be gone.
    $tpl = $ix->getPath((int)$meta['archived_from']);
    if ($tpl !== null && is_file($target_dir . '/' . $tpl)
        && isset(wiki_fm_split((string)file_get_contents($target_dir . '/' . $tpl))['meta']['generator_source'])) {
        return false;
    }
    if (isset($meta['archived_hash'])) return hash_equals((string)$meta['archived_hash'], sha1(wiki_fm_body($raw)));
    $at = strtotime((string)($meta['archived_at'] ?? ''));
    clearstatcache(true, $abs);
    return $at !== false && filemtime($abs) <= $at + 2;
}

/** Remove a page this generator wrote — its duplicate of a page it has just adopted. */
function wiki_gen_remove_own($ix, string $target_dir, string $space, string $rel, array &$touched): void {
    $abs = $target_dir . '/' . $rel;
    if (!is_file($abs) || !@unlink($abs)) return;
    $ix->removePage($rel);
    wiki_gen_search(fn($s) => $s->deletePage($space, $rel));
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log('delete', 'success', ['object_category' => 'page', 'object' => $rel, 'space' => $space,
                                             'change_type' => 'generated']);
    }
    $touched[$abs] = true;
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
                          int $rid, string $sys, array &$touched, string $reason = '', string $list = '',
                          string $source = ''): bool {
    $abs = $target_dir . '/' . $rel;
    if (!is_file($abs)) return false;
    $old = (string)file_get_contents($abs);
    if ((string)(wiki_fm_split($old)['meta']['generated_by'] ?? '') !== (string)$template_id) return false;
    // Why, so the banner can say it: a page archived because its *template* went is not one
    // whose list item was deleted, and saying so frightened someone into checking their list.
    $updates = ['archived_from' => (string)$template_id, 'archived_row' => (string)$rid, 'archived_at' => date('c')];
    if ($reason !== '') $updates['archived_reason'] = $reason;
    if ($list !== '')   $updates['archived_list'] = $list;
    // Which list it was made from, and its text as archived: what wiki_gen_adoptable() needs
    // to know a later generator may take the page over (same list, nobody has edited it).
    if ($source !== '') $updates['archived_source'] = $source;
    $updates['archived_hash'] = sha1(wiki_fm_body($old));
    $new = wiki_fm_set($old, $updates, ['generated_by', 'generated_row']);
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
 * The generators this request made dirty, rendered now, before the response — for a save
 * made from a generated page ({{_edit}}, {{_comment_form}}; modules/generators/edit_item.js),
 * whose writer wants to show the re-rendered page as soon as the save returns rather than
 * poll for it. What the budget leaves stays pending for the after-response pass.
 */
function wiki_gen_render_pending_now(): void {
    $keys = array_keys($GLOBALS['_wiki_gen_pending'] ?? []);
    if (!$keys) return;
    @set_time_limit(120);
    wiki_gen_run_dirty($keys, WIKI_GEN_INLINE_BUDGET);
    $left = array_keys(array_filter(wiki_gen_state_all(), fn($s) => !empty($s['dirty'])));
    $GLOBALS['_wiki_gen_pending'] = array_fill_keys(array_values(array_intersect($keys, $left)), true);
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
        $out = ['state' => $state, 'template_id' => (int)$tid, 'template_path' => $path,
                'row' => (int)($meta[$k_row] ?? 0)];
        if ($state === 'archived') {
            $out['reason'] = is_string($meta['archived_reason'] ?? null) ? $meta['archived_reason'] : '';
            $out['list']   = is_string($meta['archived_list'] ?? null) ? $meta['archived_list'] : '';
        }
        return $out;
    }
    return null;
}

/**
 * What an AI in a generated page's chat must know about that page (ai_core.php,
 * wiki_page_context_prompt): that it cannot be written, where its content comes from, and what
 * to do instead — or '' for an ordinary page. Without it the model is told the page's path "to
 * update it", tries, and learns only from the refusal, which names the template but not the
 * list or the row. In this Space the model is pointed at wiki_update_list_item (wiki_ai_tools.php);
 * the source list's path is given only when it is in this Space: the tools of a chat reach no other, and a template's banner names another Space's list only to people
 * who may read that Space — the Space name and page id are in the template's front matter
 * already.
 */
function wiki_gen_ai_page_note(string $page_abs, string $space_dir, $indexer): string {
    $g = wiki_gen_page_generated($page_abs);
    if ($g === null || !$indexer) return '';
    $space = basename(rtrim($space_dir, '/'));
    $tpl_rel = $indexer->getPath($g['template_id']);
    $tpl = $tpl_rel !== null ? "the template \"{$tpl_rel}\" (page id {$g['template_id']})" : "a template (page id {$g['template_id']})";
    $src = 'its source list';
    $read = '';
    $reach = false;
    if ($tpl_rel !== null && is_file(rtrim($space_dir, '/') . '/' . $tpl_rel)) {
        $def = wiki_gen_definition(wiki_fm_split((string)file_get_contents(rtrim($space_dir, '/') . '/' . $tpl_rel))['meta'], $space, $tpl_rel);
        if (!empty($def['ok'])) {
            if ($def['source_space'] === $space && ($lp = $indexer->getPath($def['source_id'])) !== null) {
                $src = "the list \"{$lp}\" (page id {$def['source_id']})";
                $read = ' You can read that list, and so the item, with wiki_read_page.';
                $reach = true;
            } else {
                $src = "a list in the Space \"{$def['source_space']}\" (page id {$def['source_id']}), which your tools here cannot reach";
            }
        }
    }
    return "This page is GENERATED: its content is rendered from item id {$g['row']} of {$src} through {$tpl}, "
         . "and is rewritten whenever that list or the template is saved. It cannot be edited, renamed or appended to "
         . "— wiki_write_page and wiki_rename_page refuse it, and an edit would be lost at the next render anyway. "
         . "To change what the page says, that item in the source list has to change; to change its layout, the template.{$read} "
         . ($reach
            ? "When asked to update this page's content, change the item with wiki_update_list_item (its fields by name, "
              . "or a comment on it) — the page is re-rendered from it. A change it cannot make (a group of fields), "
              . "or one you are not allowed to make, is done in the item's form: the edit button beside the page's title opens it. "
            : "You cannot reach that list, so when asked to update this page, say exactly what should change in which "
              . "field of the item, and that the edit button beside the page's title opens that item's form. ");
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
        // Dirty also marks a generator that failed and is retried every tick; that is not a
        // render about to happen, and saying "Rendering…" would hide why it stopped.
        'pending'  => !empty($st['dirty']) && empty($st['error']),
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

    $html = wiki_gen_is_html($split['meta']);
    try { $body_tree = wiki_tpl_parse($split['body'], ['html' => $html]); }
    catch (WikiTemplateError $e) {
        return ['ok' => false, 'error' => 'template_error', 'detail' => $e->error . ($e->detail !== '' ? ': ' . $e->detail : ''),
                'line' => $e->templateLine + substr_count($split['block'], "\n")];
    }
    try { $name_tree = wiki_tpl_parse($def['filename']); }
    catch (WikiTemplateError $e) { return ['ok' => false, 'error' => 'filename_error', 'detail' => $e->error]; }

    $data = wiki_gen_rows($list, $src_dir, ['space' => $def['source_space'], 'title' => basename($src_rel, '.list'),
        'url' => 'index.php?pageid=' . $def['source_id'] . '&space=' . rawurlencode($def['source_space']),
        'id' => (int)$def['source_id']]);
    $rows = [];
    foreach ($data['rows'] as $rid => $ctx) $rows[] = ['id' => $rid, 'title' => $ctx['_title']];
    if (!$data['rows']) return ['ok' => true, 'rows' => [], 'row' => null, 'name' => '', 'body' => '', 'warnings' => $data['warnings']];
    $rid = ($row !== null && isset($data['rows'][$row])) ? $row : array_key_first($data['rows']);
    $ctx = $data['rows'][$rid];
    $warnings = $data['warnings'];
    $unknown = array_values(array_diff(array_merge(wiki_tpl_names($body_tree), wiki_tpl_names($name_tree)),
                                       array_merge($data['keys'], ['_row', '_title', '_source', '_edit', '_add', '_comments', '_comment_form'])));
    if ($unknown) $warnings[] = ['warning' => 'unknown_field', 'detail' => implode(', ', $unknown)];
    wiki_tpl_take_warnings();
    $body = wiki_tpl_render($body_tree, $ctx, ['escape' => $html ? 'html' : 'markdown']);
    foreach (wiki_tpl_take_warnings() as $w) $warnings[] = $w;
    return ['ok' => true, 'rows' => $rows, 'row' => $rid, 'format' => $html ? 'html' : 'markdown',
            'name' => wiki_gen_clean_name(wiki_tpl_render($name_tree, $ctx, ['escape' => 'none']), $rid),
            'body' => $body, 'warnings' => $warnings];
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
            $keys = wiki_gen_field_keys($list['columns'] ?? []);    // the keys wiki_gen_rows() gives
            $targets = null;
            foreach ($list['columns'] ?? [] as $col) {
                if (!isset($keys[(string)($col['id'] ?? '')])) continue;
                $key = $keys[(string)$col['id']];
                $field = ['name' => (string)($col['name'] ?? $key), 'key' => $key, 'type' => (string)($col['type'] ?? ''),
                          'title' => ($col['id'] ?? null) === wiki_list_title_field($list)];
                // A lookup's items carry their own list's fields: offered like a group's, its
                // Title marked (a card's heading), lookups of its own left out — they read as
                // text there, and a chain of cards was not the point.
                // An "also show" field names its lookup, so a starter drawing that lookup as
                // cards can leave out what the cards already say.
                if ($field['type'] === 'lookup_field' && isset($keys[(string)($col['source'] ?? '')])) {
                    $field['source'] = $keys[(string)$col['source']];
                }
                if ($field['type'] === 'lookup') {
                    $field['multiple'] = !empty($col['lookup']['multiple']);
                    $targets ??= wiki_list_lookup_targets($list, $dir);
                    $target = $targets[(string)($col['lookup']['list'] ?? '')] ?? null;
                    $field['subs'] = [];
                    if ($target !== null) {
                        $tkeys = wiki_gen_field_keys($target['columns'] ?? []);
                        $ttitle = wiki_list_title_field($target);
                        foreach ($target['columns'] ?? [] as $tc) {
                            $tid = (string)($tc['id'] ?? '');
                            if (!isset($tkeys[$tid]) || in_array($tc['type'] ?? '', ['lookup', 'lookup_field'], true)) continue;
                            $field['subs'][] = ['name' => (string)($tc['name'] ?? $tkeys[$tid]), 'key' => $tkeys[$tid],
                                                'title' => $tid === $ttitle];
                        }
                    }
                }
                if ($field['type'] === 'object') {
                    $field['multiple'] = !empty($col['multiple']);
                    $names = array_column($col['fields'] ?? [], 'name', 'id');
                    $field['subs'] = [];
                    foreach (wiki_gen_sub_keys($col) as $sid => $k) $field['subs'][] = ['name' => (string)($names[$sid] ?? $k), 'key' => $k];
                }
                $fields[] = $field;
            }
            $out[] = ['space' => $space, 'id' => (int)$pid, 'path' => $rel, 'title' => basename($rel, '.list'),
                      'fields' => $fields, 'rows' => count($list['items'] ?? []), 'comments' => wiki_list_comments_on($list)];
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
function wiki_gen_starter(string $source, string $folder, string $filename_key, array $fields, bool $html = false,
                          bool $comments = false): string {
    $body = $html ? wiki_gen_starter_html($fields, $comments) : wiki_gen_starter_md($fields, $comments);
    $updates = [];
    if ($html) $updates['format'] = 'html';
    $updates += ['generator_source' => $source, 'generator_folder' => $folder];
    if ($filename_key !== '') $updates['generator_filename'] = '{{' . $filename_key . '}}';
    return wiki_fm_set($body, $updates);
}

/**
 * The Markdown starter: a table of the plain fields (the Title is the heading, with the edit
 * button beside it), then a section per field that repeats — a group allowing several, and a
 * lookup whose list could be read — showing how a template repeats: {{#field}} … {{/field}}
 * once per entry, {{^field}} … {{/field}} when there are none. A group's entry is headed by its
 * number and summary, a lookup's item by what the lookup shows and its link to the item; a
 * lookup's "also show" fields are left out of the table, since its section shows them. Then,
 * when the list takes comments, the item's comments. Field names are text the list's authors
 * wrote: `|` would end a table cell, and braces must not become placeholders.
 */
function wiki_gen_starter_md(array $fields, bool $comments = false): string {
    $label = fn(string $s) => strtr($s, ['|' => '\\|', '{' => '&#123;', '}' => '&#125;']);
    $carded = [];
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'lookup' && !empty($f['subs'])) $carded[$f['key']] = true;
    }
    $table = '';
    $sections = '';
    foreach ($fields as $f) {
        if (!empty($f['title'])) continue;
        $type = $f['type'] ?? '';
        if ($type === 'lookup_field' && isset($carded[$f['source'] ?? ''])) continue;
        $key = $f['key'];
        $several = $type === 'object' && !empty($f['multiple']);
        if ($several || isset($carded[$key])) {
            $lookup = $type === 'lookup';
            $subs = $lookup ? array_values(array_filter($f['subs'], fn($sf) => empty($sf['title']))) : ($f['subs'] ?? []);
            $sections .= "\n## " . $label((string)$f['name']) . ($lookup ? '' : " {{_add.{$key}}}") . "\n\n{{#{$key}}}\n"
                       . ($lookup ? "### {{_text}} {{_edit}}\n\n" : "### {{_index}}. {{_text}}\n\n")
                       . implode('', array_map(fn($sf) => '- **' . $label((string)$sf['name']) . ":** {{" . $sf['key'] . "}}\n", $subs))
                       . "\n{{/{$key}}}\n{{^{$key}}}\n_None._\n{{/{$key}}}\n";
            continue;
        }
        $table .= '| **' . $label((string)$f['name']) . "** | {{{$key}}} |\n";
    }
    $body = "# {{_title}} {{_edit}}\n\n" . ($table !== '' ? "| | |\n|---|---|\n" . $table : '');
    $logs = $comments ? "\n## Comments\n\n{{#_comments}}\n- **{{name}}** · {{at}} — {{text}}\n{{/_comments}}\n\n{{_comment_form}}\n" : '';
    return $body . $sections . $logs . "\n";
}

/**
 * The styles an HTML template starts with: a copy of the wiki's wk-* rules, for the template's
 * author to edit. Copied into every page the template writes, where the page view confines
 * them to the page (modules/core/scoped_css.js) — so they are written plainly, unscoped. A
 * width query, not the wiki's `body.mobile`, makes the phone layout: scoping turns `body` into
 * the page's own box, where a rule about the wiki's body class would never match.
 */
const WIKI_GEN_STARTER_CSS = <<<'CSS'
<style>
/* This template's own styles — edit freely. They apply only to the pages it generates. */
.wk-card   { border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem 1.25rem; margin: 0 0 1rem; background: #fff; }
.wk-card > :last-child { margin-bottom: 0; }
.wk-grid   { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; margin: 0 0 1rem; }
.wk-grid > .wk-card { margin: 0; padding: .75rem 1rem; }
.wk-grid > .wk-card > h3 { margin: 0 0 .5rem; font-size: 1.05rem; }
.wk-fields { display: grid; grid-template-columns: max-content 1fr; gap: .3rem 1.25rem; margin: 0 0 .5rem; }
.wk-fields > dt, .wk-label { color: #718096; font-weight: 600; font-size: .9em; }
.wk-fields > dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
.wk-fields .wk-fields { margin: 0; }
.wk-muted  { color: #718096; }
.wk-comment { margin: 0 0 .6rem; }
.wk-comment > :last-child { white-space: pre-line; }
.wk-edit   { margin-left: .4rem; vertical-align: middle; }
.wk-cols   { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
.wk-cols > .wk-card { margin: 0; }
@media (max-width: 640px) {
  .wk-cols { grid-template-columns: 1fr; }
  .wk-fields { grid-template-columns: 1fr; gap: .1rem; }
  .wk-fields > dd { margin-bottom: .4rem; }
}
</style>

CSS;

/**
 * The HTML starter: a card with the fields as label/value rows (`wk-fields`), a single
 * group as a nested block of its own fields, and a group allowing several — and a lookup —
 * as a stack of full-width cards, one per entry (per item looked up) — a working for-each
 * from the first render. Field names are text the
 * list's authors wrote, so they are escaped here like any value (braces too: a name like
 * "{{x}}" must not become a placeholder).
 */
function wiki_gen_starter_html(array $fields, bool $comments = false): string {
    $label = fn(string $s) => strtr(htmlspecialchars($s, ENT_QUOTES), ['{' => '&#123;', '}' => '&#125;']);
    $rows = fn(array $subs, string $indent) => implode('', array_map(fn($sf) =>
        "$indent<dt>" . $label($sf['name']) . "</dt><dd>{{" . $sf['key'] . "}}</dd>\n", $subs));
    $plain = [];
    $several = [];
    $carded = [];   // lookups drawn as cards, by key
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'lookup' && !empty($f['subs'])) $carded[$f['key']] = true;
    }
    foreach ($fields as $f) {
        if (!empty($f['title'])) continue;
        // A lookup's "also show" fields are in its cards already.
        if (($f['type'] ?? '') === 'lookup_field' && isset($carded[$f['source'] ?? ''])) continue;
        if (($f['type'] ?? '') === 'object' && !empty($f['multiple'])) { $several[] = $f; continue; }
        // A lookup whose list could be read: its items as cards, like a group's entries.
        if (($f['type'] ?? '') === 'lookup' && !empty($f['subs'])) { $several[] = $f; continue; }
        $plain[] = $f;
    }
    // With comments: the item in a card two thirds wide, its comments in one beside it.
    $out = WIKI_GEN_STARTER_CSS . ($comments ? "<div class=\"wk-cols\">\n" : '')
         . "<div class=\"wk-card\">\n  <h1>{{_title}} {{_edit}}</h1>\n  <dl class=\"wk-fields\">\n";
    foreach ($plain as $f) {
        if (($f['type'] ?? '') === 'object') {
            $out .= "    <dt>" . $label($f['name']) . "</dt>\n    <dd>\n      {{#" . $f['key'] . "}}\n"
                  . "      <dl class=\"wk-fields\">\n" . $rows($f['subs'] ?? [], '        ') . "      </dl>\n"
                  . "      {{/" . $f['key'] . "}}\n    </dd>\n";
            continue;
        }
        $out .= "    <dt>" . $label($f['name']) . "</dt><dd>{{" . $f['key'] . "}}</dd>\n";
    }
    $out .= "  </dl>\n";
    foreach ($several as $f) {
        // A lookup's card is headed by what the lookup shows (`_text` — "(deleted #12)" for an
        // item since removed) and the linked item's own {{_edit}} (a link icon), its Title not
        // repeated below; it has no + (an item is picked in the form, not added here).
        $lookup = ($f['type'] ?? '') === 'lookup';
        $subs = $lookup ? array_values(array_filter($f['subs'], fn($sf) => empty($sf['title']))) : ($f['subs'] ?? []);
        $out .= "\n  <h2>" . $label($f['name']) . ($lookup ? '' : " {{_add." . $f['key'] . "}}") . "</h2>\n  <div class=\"wk-grid\">\n    {{#" . $f['key'] . "}}\n"
              . "    <div class=\"wk-card\">\n" . ($lookup ? "      <h3>{{_text}} {{_edit}}</h3>\n" : '')
              . "      <dl class=\"wk-fields\">\n" . $rows($subs, '        ')
              . "      </dl>\n    </div>\n    {{/" . $f['key'] . "}}\n  </div>\n"
              . "  {{^" . $f['key'] . "}}<p class=\"wk-muted\">—</p>{{/" . $f['key'] . "}}\n";
    }
    $out .= "</div>\n";
    if ($comments) {
        $out .= "<aside class=\"wk-card\">\n  <h2>Comments</h2>\n  {{#_comments}}\n"
              . "  <div class=\"wk-comment\"><div class=\"wk-muted\">{{name}} · {{at}}</div><div>{{text}}</div></div>\n"
              . "  {{/_comments}}\n  {{^_comments}}<p class=\"wk-muted\">—</p>{{/_comments}}\n"
              . "  {{_comment_form}}\n</aside>\n</div>\n";
    }
    return $out;
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
