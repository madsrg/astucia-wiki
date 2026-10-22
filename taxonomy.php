<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// TAXONOMY — one wiki-wide tree of tags, in PAGES_DIR/taxonomy.json.
//
// A tag is a path through the tree: `Finance/Budget/Q3`, the convention Obsidian uses
// for nested tags. index.json keeps storing tags as plain strings, so the format of
// every Space's index is unchanged; the tree is what gives those strings structure.
//
// Rules, and why:
//  - **One tree for the whole wiki**, not one per Space. Classification is only
//    consistent if every Space means the same thing by `Finance`; a page moved or
//    copied between Spaces keeps tags that are still valid where it lands.
//  - **In PAGES_DIR, not WIKI_SYSTEM_DATA**, so it is versioned with the content and
//    travels with an rsync of it. That makes `taxonomy.json` a **reserved name** at the
//    root: never indexed, never listed, and refused by sanitize_path() and the AI tools,
//    or any editor or AI user could overwrite the tree by writing a "page" there.
//  - **Matching is case-insensitive**, and a tag that names a node takes the node's
//    spelling. `finance` and `Finance` are one term.
//  - **Folders and items.** A folder (stored as `"heading": true`, the key the file format
//    has always used) groups and is never assigned: `Department` holds `Finance` and `HR`.
//    An item is a tag, and may itself hold narrower items (`Finance` and `Finance/Budget`
//    are both tags) — the broader/narrower pattern Obsidian and classification schemes use.
//    A parent created by typing a nested tag is a folder — unless it lands inside an item.
//  - **A folder sits at the top level or inside another folder, never inside an item.**
//    `Finance/Regions/EU` with Regions a folder puts a grouping word in the middle of a
//    real term's path; an item does the same job. Enforced by every operation that could
//    place one there and by import, but **not on load**: a tree saved before the rule must
//    keep working (refusing to load it would stop tagging wiki-wide), so those folders are
//    reported to the admin page instead (wiki_tax_misplaced_folders()).
//  - **The mode** (settings.json `taxonomy_mode`) decides what a *new* tag on a page may
//    be: `free` and `suggest` add an unknown tag to the tree (suggest also asks first, in
//    the client), `controlled` refuses it. Tags a page already has are never refused —
//    switching an existing wiki to controlled must not make its pages unsaveable.
//  - Enforced in PageIndexer::updateTags(), the funnel every tag write goes through
//    (tag field, AI tools, workflows). AI memory and page copies opt out: memory tags
//    are the model's own filing, and a copy carries tags that were already accepted.
//  - **Renaming, moving and merging a node rewrites the tags on every page** in every
//    Space, frozen ones included — a frozen Space freezes content, and a tag is the name
//    of a term, not content. Without that a rename would strand every page using it.
// =================================================================

require_once __DIR__ . '/settings.php';

const WIKI_TAX_FILE      = 'taxonomy.json';
const WIKI_TAX_MODES     = ['free', 'suggest', 'controlled'];
const WIKI_TAX_MAX_NODES = 5000;
const WIKI_TAX_MAX_DEPTH = 8;
const WIKI_TAX_MAX_NAME  = 80;
const WIKI_TAX_MAX_DESC  = 500;

/** Refusal from the taxonomy policy; its message is shown to whoever tagged. */
class WikiTaxonomyException extends Exception {}

function wiki_tax_file(): string {
    return rtrim(PAGES_DIR, '/') . '/' . WIKI_TAX_FILE;
}

/** Is this absolute path the taxonomy file, however it was spelled? */
function wiki_tax_is_reserved(string $abs): bool {
    if (basename($abs) !== WIKI_TAX_FILE) return false;
    $dir  = realpath(dirname($abs));
    $root = realpath(rtrim(PAGES_DIR, '/'));
    return $dir !== false && $root !== false && $dir === $root;
}

function wiki_tax_mode(): string {
    $m = (string)wiki_setting('taxonomy_mode', 'free');
    return in_array($m, WIKI_TAX_MODES, true) ? $m : 'free';
}

// ── Names and paths ──────────────────────────────────────────────────────────

function wiki_tax_clean_name($s): string {
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

/** A tag string as path segments: trimmed, empty segments dropped. */
function wiki_tax_split(string $tag): array {
    $out = [];
    foreach (explode('/', $tag) as $seg) {
        $seg = wiki_tax_clean_name($seg);
        if ($seg !== '') $out[] = $seg;
    }
    return $out;
}

function wiki_tax_normalize(string $tag): string {
    return implode('/', wiki_tax_split($tag));
}

function wiki_tax_lc(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Is $tag the node $path or anything below it? Case-insensitive. */
function wiki_tax_under(string $tag, string $path): bool {
    $t = wiki_tax_lc($tag); $p = wiki_tax_lc($path);
    return $t === $p || strncmp($t, $p . '/', strlen($p) + 1) === 0;
}

// ── Validation ───────────────────────────────────────────────────────────────

/**
 * Clean a node list, collecting every problem rather than stopping at the first, so an
 * import can report the whole file. Returns the cleaned list; $errors is filled.
 */
function wiki_tax_validate($nodes, array &$errors, string $parent = '', int $depth = 1, ?int &$count = null): array {
    if ($count === null) $count = 0;
    if ($parent === '' && $depth === 1) $GLOBALS['_wiki_tax_seen_fields'] = [];   // field names are unique per tree
    if (!is_array($nodes) || ($nodes !== [] && array_keys($nodes) !== range(0, count($nodes) - 1))) {
        $errors[] = ($parent === '' ? 'The top level' : '"' . $parent . '"') . ': children must be a list.';
        return [];
    }
    if ($depth > WIKI_TAX_MAX_DEPTH) {
        $errors[] = '"' . $parent . '": deeper than ' . WIKI_TAX_MAX_DEPTH . ' levels.';
        return [];
    }
    $out = []; $seen = [];
    foreach ($nodes as $i => $n) {
        $where = $parent === '' ? 'Top-level item ' . ($i + 1) : '"' . $parent . '", item ' . ($i + 1);
        if (!is_array($n)) { $errors[] = $where . ': not an object.'; continue; }
        $name = wiki_tax_clean_name($n['name'] ?? '');
        if ($name === '')                         { $errors[] = $where . ': has no name.'; continue; }
        if (strpos($name, '/') !== false)         { $errors[] = $where . ': "' . $name . '" contains "/".'; continue; }
        if (mb_strlen($name) > WIKI_TAX_MAX_NAME) { $errors[] = $where . ': name longer than ' . WIKI_TAX_MAX_NAME . ' characters.'; continue; }
        $path = $parent === '' ? $name : $parent . '/' . $name;
        $lc = wiki_tax_lc($name);
        if (isset($seen[$lc]))                    { $errors[] = '"' . $path . '" appears twice.'; continue; }
        $seen[$lc] = true;
        if (++$count > WIKI_TAX_MAX_NODES)        { $errors[] = 'More than ' . WIKI_TAX_MAX_NODES . ' terms.'; return $out; }
        $node = ['name' => $name];
        if (!empty($n['heading'])) $node['heading'] = true;
        $desc = trim((string)($n['description'] ?? ''));
        if ($desc !== '') $node['description'] = mb_substr($desc, 0, WIKI_TAX_MAX_DESC);
        $field = trim((string)($n['field'] ?? ''));
        if ($field !== '' && empty($n['heading'])) {
            $errors[] = '"' . $path . '": ' . WIKI_TAX_FIELD_ON_ITEM;
        } elseif ($field !== '') {
            $why = wiki_tax_field_name_problem($field);
            if ($why !== '') { $errors[] = '"' . $path . '": ' . $why; }
            else {
                $flc = strtolower($field);
                if (isset($GLOBALS['_wiki_tax_seen_fields'][$flc])) {
                    $errors[] = '"' . $path . '": the field "' . $field . '" is already bound to "'
                              . $GLOBALS['_wiki_tax_seen_fields'][$flc] . '".';
                } else {
                    $GLOBALS['_wiki_tax_seen_fields'][$flc] = $path;
                    $node['field'] = $field;
                    if (!empty($n['multiple'])) $node['multiple'] = true;
                }
            }
        }
        $kids = wiki_tax_validate($n['children'] ?? [], $errors, $path, $depth + 1, $count);
        if ($kids) $node['children'] = $kids;
        $out[] = $node;
    }
    return $out;
}

// ── Fields bound to a branch ─────────────────────────────────────────────────
//
// A folder can be bound to a front-matter field (`"field": "department"`), and
// then that field's values are the names of the items inside it — `department: Finance`,
// the plain name, as the user decided, not the path. On the node rather than in a separate
// list, so the binding follows the branch through a rename or a move and travels with an
// export. `multiple` allows a list (`audience: [Staff, Managers]`).
//
// Enforced where a person sets a value through the wiki — the Metadata panel
// (`set_frontmatter`) and a workflow's front-matter action. Not on a body save or an
// upload: a file that arrives with its own block is somebody else's, and refusing it would
// refuse the import. The Metadata panel shows such a value as not in the taxonomy instead.

/**
 * Only a folder can be bound: a field is a set of choices, and a folder is the group that
 * holds them. Bound to an item, the item would be a tag whose own name is not one of the
 * field's values — only what is inside it — which reads as a mistake. Strict everywhere,
 * load included: bindings were never released on items, so there is nothing to tolerate.
 */
const WIKI_TAX_FIELD_ON_ITEM = 'only a folder can be bound to a front-matter field — its items are the values.';

/** Fields the wiki owns or the search language uses, which cannot be bound. */
const WIKI_TAX_RESERVED_FIELDS = ['tags', 'tag', 'src', 'updated', 'created', 'createdby', 'updatedby'];

function wiki_tax_field_name_problem(string $f): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]{0,49}$/', $f)) {
        return 'a field name is letters, digits, "_", "." or "-", starting with a letter (no spaces).';
    }
    if (in_array(strtolower($f), WIKI_TAX_RESERVED_FIELDS, true)) return '"' . $f . '" is reserved.';
    return '';
}

/**
 * Every bound field. lower-case field => ['field', 'path', 'multiple',
 * 'values' => lower-case name => ['value' => spelled name, 'path' => item path]].
 * Values are the items anywhere inside the branch; folders are grouping, not values.
 */
function wiki_tax_fields(?array $nodes = null): array {
    $nodes = $nodes ?? wiki_tax_nodes();
    $out = [];
    $collect = function (array $list, string $parent, array &$vals) use (&$collect) {
        foreach ($list as $n) {
            $p = $parent === '' ? $n['name'] : $parent . '/' . $n['name'];
            if (empty($n['heading'])) {
                $k = wiki_tax_lc($n['name']);
                if (!isset($vals[$k])) $vals[$k] = ['value' => $n['name'], 'path' => $p];
            }
            if (!empty($n['children'])) $collect($n['children'], $p, $vals);
        }
    };
    $walk = function (array $list, string $parent) use (&$walk, &$out, $collect) {
        foreach ($list as $n) {
            $p = $parent === '' ? $n['name'] : $parent . '/' . $n['name'];
            if (!empty($n['field'])) {
                $vals = [];
                $collect($n['children'] ?? [], $p, $vals);
                $out[strtolower($n['field'])] = ['field' => $n['field'], 'path' => $p,
                    'multiple' => !empty($n['multiple']), 'values' => $vals];
            }
            if (!empty($n['children'])) $walk($n['children'], $p);
        }
    };
    $walk($nodes, '');
    return $out;
}

/**
 * Check a value for a front-matter key. A key that is not bound passes untouched. A bound
 * one must hold allowed names — spelled as the tree spells them — and a list only when the
 * field allows several. An empty value passes (it is a removal).
 *
 * @param string|array $value
 * @return string|array the value as it should be written
 * @throws WikiTaxonomyException
 */
function wiki_tax_field_check(string $key, $value) {
    $f = wiki_tax_fields()[strtolower(trim($key))] ?? null;
    if (!$f) return $value;
    $items = is_array($value) ? $value : [$value];
    $items = array_values(array_filter(array_map(fn($v) => trim((string)$v), $items), fn($v) => $v !== ''));
    if (!$items) return $value;
    if (count($items) > 1 && !$f['multiple']) {
        throw new WikiTaxonomyException('"' . $f['field'] . '" takes one value from "' . $f['path'] . '".');
    }
    $out = []; $bad = [];
    foreach ($items as $v) {
        $hit = $f['values'][wiki_tax_lc($v)] ?? null;
        if ($hit) $out[] = $hit['value']; else $bad[] = $v;
    }
    if ($bad) {
        throw new WikiTaxonomyException('"' . implode('", "', $bad) . '" ' . (count($bad) > 1 ? 'are' : 'is')
            . ' not a value of "' . $f['field'] . '". Its values are the items in "' . $f['path'] . '"'
            . ($f['values'] ? ': ' . implode(', ', array_column($f['values'], 'value')) . '.' : ', and it has none yet.'));
    }
    return is_array($value) ? $out : $out[0];
}

/** For search: a value and every item below it, lower-cased. `department:Finance` finds Payroll too. */
function wiki_tax_field_expand(string $field, string $value): array {
    $f = wiki_tax_fields()[strtolower($field)] ?? null;
    $lv = wiki_tax_lc(trim($value));
    if (!$f || !isset($f['values'][$lv])) return [$lv];
    $root = $f['values'][$lv]['path'];
    $out = [];
    foreach ($f['values'] as $k => $v) if (wiki_tax_under($v['path'], $root)) $out[] = $k;
    return $out;
}

/** Does a block (parsed meta) satisfy every field filter? $filters: field => [lc values]. */
function wiki_tax_meta_matches(array $meta, array $filters): bool {
    foreach ($filters as $field => $want) {
        $have = null;
        foreach ($meta as $k => $v) if (strcasecmp((string)$k, (string)$field) === 0) { $have = $v; break; }
        if ($have === null) return false;
        $have = array_map(fn($x) => wiki_tax_lc(trim((string)$x)), is_array($have) ? $have : explode(',', (string)$have));
        if (!array_intersect($have, $want)) return false;
    }
    return true;
}

/** Every `.md` page in every Space and the root, as [space_dir, rel, abs]. */
function _wiki_tax_md_pages(): array {
    $out = [];
    foreach (wiki_tax_index_dirs() as $space => $dir) {
        foreach (PageIndexer::scanContent($dir) as $rel => $_m) {
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'md') $out[] = [$dir, $rel, $dir . '/' . $rel];
        }
    }
    return $out;
}

/** How many pages hold each value of each bound field: lc field => lc value => n. */
function wiki_tax_field_usage(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $fields = wiki_tax_fields();
    $cache = [];
    if (!$fields) return $cache;
    foreach (_wiki_tax_md_pages() as [, , $abs]) {
        $meta = wiki_fm_split((string)@file_get_contents($abs, false, null, 0, 65536))['meta'];
        foreach ($meta as $k => $v) {
            $lk = strtolower((string)$k);
            if (!isset($fields[$lk])) continue;
            foreach (is_array($v) ? $v : explode(',', (string)$v) as $x) {
                $lx = wiki_tax_lc(trim((string)$x));
                if ($lx !== '') $cache[$lk][$lx] = ($cache[$lk][$lx] ?? 0) + 1;
            }
        }
    }
    return $cache;
}

/** Pages using an item at $path as a field value (the item itself, not what is below it). */
function wiki_tax_value_use(string $path, ?array $fields = null): int {
    $fields = $fields ?? wiki_tax_fields();
    $usage = wiki_tax_field_usage();
    $n = 0;
    foreach ($fields as $lf => $f) {
        foreach ($f['values'] as $lv => $v) {
            if (wiki_tax_under($v['path'], $path)) $n += $usage[$lf][$lv] ?? 0;
        }
    }
    return $n;
}

/**
 * Rename a value in every page's block: `$field: $old` becomes `$new`, in scalars and
 * lists. mtime is kept, as for the tag sync — a vocabulary change is not a page edit. Frozen
 * Spaces are left as they are. Committed once per repository. Returns pages changed.
 */
function wiki_tax_rewrite_field_value(string $field, string $old, string $new): int {
    $lo = wiki_tax_lc($old);
    $changed = 0; $commits = [];
    foreach (_wiki_tax_md_pages() as [$dir, $rel, $abs]) {
        if (wiki_space_dir_is_readonly($dir)) continue;
        $raw = (string)@file_get_contents($abs);
        $meta = wiki_fm_split($raw)['meta'];
        $key = null;
        foreach ($meta as $k => $v) if (strcasecmp((string)$k, $field) === 0) { $key = (string)$k; break; }
        if ($key === null) continue;
        $v = $meta[$key];
        if (is_array($v)) {
            $nv = array_map(fn($x) => wiki_tax_lc(trim((string)$x)) === $lo ? $new : $x, $v);
        } else {
            $parts = array_map('trim', explode(',', (string)$v));
            $hit = false;
            foreach ($parts as &$pp) if (wiki_tax_lc($pp) === $lo) { $pp = $new; $hit = true; }
            unset($pp);
            $nv = $hit ? implode(', ', $parts) : $v;
        }
        if ($nv === $v) continue;
        $out = wiki_fm_set($raw, [$key => $nv]);
        if ($out === $raw) continue;
        clearstatcache(true, $abs);
        $mt = @filemtime($abs);
        if (@file_put_contents($abs, $out) === false) continue;
        if ($mt) @touch($abs, $mt);
        $changed++;
        if (function_exists('find_git_root') && ($g = find_git_root($dir))) {
            $commits[$g['root']][] = $g['prefix'] . $rel;
        }
    }
    foreach ($commits as $root => $files) {
        foreach ($files as $f) git_run(['add', '--', $f], $root);
        $actor = function_exists('get_current_actor') ? get_current_actor() : [];
        git_run(['-c', 'user.name=' . ($actor['name'] ?? 'Wiki'), '-c', 'user.email=wiki@localhost',
                 'commit', '-m', "Taxonomy: $field $old → $new"], $root);
    }
    return $changed;
}

/** After a node at $from is renamed or merged into $to, follow it in field values. */
function _wiki_tax_follow_values(array $fields_before, string $from, string $to): int {
    $n = 0;
    $after = wiki_tax_fields();
    foreach ($fields_before as $lf => $f) {
        foreach ($f['values'] as $lv => $v) {
            if (wiki_tax_lc($v['path']) !== wiki_tax_lc($from)) continue;
            // The value's new name, if it is still a value of the same field.
            $newName = substr($to, strrpos('/' . $to, '/'));
            if (!isset($after[$lf]['values'][wiki_tax_lc($newName)])) continue;
            if (wiki_tax_lc($newName) === $lv && $newName === $v['value']) continue;
            $n += wiki_tax_rewrite_field_value($f['field'], $v['value'], $after[$lf]['values'][wiki_tax_lc($newName)]['value']);
        }
    }
    return $n;
}

// ── Storage ──────────────────────────────────────────────────────────────────

/** The tree's top-level node list. A missing or unreadable file is an empty tree. */
function wiki_tax_nodes(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $f = wiki_tax_file();
    $data = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $errors = [];
    $cache = is_array($data) ? wiki_tax_validate($data['nodes'] ?? [], $errors) : [];
    return $cache;
}

function _wiki_tax_lock_path(): string {
    $dir = (defined('WIKI_SYSTEM_DATA') && WIKI_SYSTEM_DATA) ? rtrim(WIKI_SYSTEM_DATA, '/') : sys_get_temp_dir();
    return $dir . '/taxonomy.lock';
}

/**
 * Read-modify-write the tree under a lock. $fn gets the node list by reference and may
 * throw to abort; its return value is passed back. Written atomically (temp + rename),
 * and committed when PAGES_DIR is a git repository.
 */
function wiki_tax_mutate(callable $fn, string $commit_msg = 'Update taxonomy') {
    $lock = @fopen(_wiki_tax_lock_path(), 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $nodes = wiki_tax_nodes(true);
        $ret = $fn($nodes);
        $errors = [];
        $clean = wiki_tax_validate($nodes, $errors);
        if ($errors) throw new WikiTaxonomyException($errors[0]);
        _wiki_tax_write($clean, $commit_msg);
        return $ret;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function _wiki_tax_write(array $nodes, string $commit_msg): void {
    $f = wiki_tax_file();
    $json = json_encode(['version' => 1, 'nodes' => $nodes],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $tmp = $f . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        throw new WikiTaxonomyException('Could not write ' . WIKI_TAX_FILE . ' — is PAGES_DIR writable?');
    }
    wiki_tax_nodes(true);
    $root = rtrim(PAGES_DIR, '/');
    if (is_dir($root . '/.git') && function_exists('git_run')) {
        $actor = function_exists('get_current_actor') ? get_current_actor() : [];
        $email = (!empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
        git_run(['add', WIKI_TAX_FILE], $root);
        git_run(['-c', 'user.name=' . ($actor['name'] ?? 'Wiki'), '-c', 'user.email=' . $email,
                 'commit', '-m', $commit_msg, '--', WIKI_TAX_FILE], $root);
    }
}

// ── Reading the tree ─────────────────────────────────────────────────────────

/** lower-case path => ['path' => spelled path, 'heading' => bool], for every node. */
function wiki_tax_index(?array $nodes = null, string $parent = ''): array {
    $nodes = $nodes ?? wiki_tax_nodes();
    $out = [];
    foreach ($nodes as $n) {
        $path = $parent === '' ? $n['name'] : $parent . '/' . $n['name'];
        $out[wiki_tax_lc($path)] = ['path' => $path, 'heading' => !empty($n['heading'])];
        if (!empty($n['children'])) $out += wiki_tax_index($n['children'], $path);
    }
    return $out;
}

/** Paths of folders that are inside an item — not allowed, but tolerated on load. */
function wiki_tax_misplaced_folders(?array $nodes = null, string $parent = '', bool $in_item = false): array {
    $out = [];
    foreach ($nodes ?? wiki_tax_nodes() as $n) {
        $p = $parent === '' ? $n['name'] : $parent . '/' . $n['name'];
        $folder = !empty($n['heading']);
        if ($folder && $in_item) $out[] = $p;
        if (!empty($n['children'])) {
            $out = array_merge($out, wiki_tax_misplaced_folders($n['children'], $p, $in_item || !$folder));
        }
    }
    return $out;
}

/** Is the node at $path an item? '' (the top level) is not. */
function _wiki_tax_is_item(array $index, string $path): bool {
    return $path !== '' && isset($index[wiki_tax_lc($path)]) && !$index[wiki_tax_lc($path)]['heading'];
}

/** Does the node at $path directly contain a folder? */
function _wiki_tax_has_child_folder(array $index, string $path): bool {
    $p = wiki_tax_lc($path) . '/';
    foreach ($index as $k => $e) {
        if (strncmp($k, $p, strlen($p)) === 0 && strpos(substr($k, strlen($p)), '/') === false && $e['heading']) return true;
    }
    return false;
}

const WIKI_TAX_FOLDER_IN_ITEM = 'A folder can only be at the top level or inside another folder, not inside an item.';

/** Index of the child named $name (case-insensitive) in $list, or -1. */
function _wiki_tax_child(array $list, string $name): int {
    $lc = wiki_tax_lc($name);
    foreach ($list as $i => $n) if (wiki_tax_lc($n['name']) === $lc) return $i;
    return -1;
}

/**
 * Make sure every segment of $segs exists, creating what is missing. Existing segments
 * keep their spelling. Returns the path as spelled in the tree.
 *
 * A parent created on the way is a **folder** (a heading): typing `Project/Apollo` means
 * "Apollo, filed under Project", not "Project is a tag too". The last segment is an item;
 * with $as_item an existing folder there is turned into one — for adopting a tag pages
 * already carry, which must be assignable whatever the tree said.
 */
function wiki_tax_ensure(array &$nodes, array $segs, bool $as_item = false): string {
    $list = &$nodes; $spelled = [];
    $last = count($segs) - 1;
    $in_item = false;   // a parent created inside an item is an item, never a folder
    foreach ($segs as $k => $seg) {
        $i = _wiki_tax_child($list, $seg);
        if ($i < 0) {
            $list[] = ($k < $last && !$in_item) ? ['name' => $seg, 'heading' => true] : ['name' => $seg];
            $i = count($list) - 1;
        } elseif ($k === $last && $as_item) {
            unset($list[$i]['heading']);
        }
        $in_item = $in_item || empty($list[$i]['heading']);
        $spelled[] = $list[$i]['name'];
        if (!isset($list[$i]['children'])) $list[$i]['children'] = [];
        $list = &$list[$i]['children'];
    }
    unset($list);
    return implode('/', $spelled);
}

/** Detach the node at $segs from the tree and return it, or null. */
function _wiki_tax_take(array &$nodes, array $segs): ?array {
    $list = &$nodes;
    $last = array_pop($segs);
    foreach ($segs as $seg) {
        $i = _wiki_tax_child($list, $seg);
        if ($i < 0) return null;
        if (!isset($list[$i]['children'])) return null;
        $list = &$list[$i]['children'];
    }
    $i = _wiki_tax_child($list, $last);
    if ($i < 0) return null;
    $node = $list[$i];
    array_splice($list, $i, 1);
    unset($list);
    return $node;
}

/** A reference to the child list of $segs ([] = top level), or null when it does not exist. */
function &_wiki_tax_children(array &$nodes, array $segs) {
    $null = null;
    $list = &$nodes;
    foreach ($segs as $seg) {
        $i = _wiki_tax_child($list, $seg);
        if ($i < 0) return $null;
        if (!isset($list[$i]['children'])) $list[$i]['children'] = [];
        $list = &$list[$i]['children'];
    }
    return $list;
}

/** Fold $src's children into $dst, merging same-named nodes recursively. */
function _wiki_tax_fold(array &$dst, array $src): void {
    foreach ($src['children'] ?? [] as $kid) {
        $dst['children'] = $dst['children'] ?? [];
        $i = _wiki_tax_child($dst['children'], $kid['name']);
        if ($i < 0) { $dst['children'][] = $kid; continue; }
        _wiki_tax_fold($dst['children'][$i], $kid);
    }
}

// ── Pages: usage and rewriting ───────────────────────────────────────────────

/** Every directory that holds an index.json: PAGES_DIR itself and each Space. */
function wiki_tax_index_dirs(): array {
    $root = rtrim(PAGES_DIR, '/');
    $out = ['' => $root];
    foreach ((@scandir($root) ?: []) as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') continue;
        if (is_dir($root . '/' . $e)) $out[$e] = $root . '/' . $e;
    }
    return $out;
}

/**
 * How many pages carry each tag. lower-case tag => ['tag' => spelling, 'count' => n].
 * $allowed limits it to the Spaces an actor may see (null = all; root always counts).
 */
function wiki_tax_usage(?array $allowed = null): array {
    $out = [];
    foreach (wiki_tax_index_dirs() as $space => $dir) {
        if ($allowed !== null && $space !== '' && !in_array($space, $allowed, true)) continue;
        $f = $dir . '/index.json';
        if (!is_file($f)) continue;
        foreach ((json_decode((string)file_get_contents($f), true) ?: []) as $entry) {
            foreach ((array)($entry['tags'] ?? []) as $tag) {
                $tag = (string)$tag;
                $lc = wiki_tax_lc($tag);
                if (!isset($out[$lc])) $out[$lc] = ['tag' => $tag, 'count' => 0];
                $out[$lc]['count']++;
            }
        }
    }
    return $out;
}

/** Is any page, in any Space, tagged $path or something below it? */
function wiki_tax_in_use(string $path): int {
    $n = 0;
    foreach (wiki_tax_usage() as $lc => $u) if (wiki_tax_under($u['tag'], $path)) $n += $u['count'];
    return $n;
}

/** Rewrite $from (and everything below it) to $to on every page. Returns pages changed. */
function wiki_tax_rewrite(string $from, string $to): int {
    $changed = 0;
    $fromLen = strlen($from);
    foreach (wiki_tax_index_dirs() as $dir) {
        if (!is_file($dir . '/index.json')) continue;
        $ix = new PageIndexer($dir);
        $changed += $ix->rewriteTags(function (string $tag) use ($from, $to, $fromLen) {
            if (!wiki_tax_under($tag, $from)) return $tag;
            return $to . substr($tag, $fromLen);
        });
    }
    return $changed;
}

// ── The policy, applied to one page's tag write ──────────────────────────────

/**
 * Normalise a page's new tag list against the tree and the mode. Tags the page already
 * had pass untouched. A new tag that names a node takes the node's spelling; a heading
 * is refused; an unknown tag is added to the tree, or refused in controlled mode.
 *
 * @throws WikiTaxonomyException
 */
function wiki_tax_apply(array $tags, array $old): array {
    $oldLc = [];
    foreach ($old as $t) $oldLc[wiki_tax_lc(wiki_tax_normalize((string)$t))] = (string)$t;

    $index = wiki_tax_index();
    $mode  = wiki_tax_mode();
    $out = []; $seen = []; $missing = []; $headings = []; $toAdd = [];
    foreach ($tags as $t) {
        $norm = wiki_tax_normalize((string)$t);
        if ($norm === '') continue;
        $lc = wiki_tax_lc($norm);
        if (isset($seen[$lc])) continue;
        $seen[$lc] = true;
        if (isset($oldLc[$lc]))      { $out[] = $oldLc[$lc]; continue; }
        if (isset($index[$lc])) {
            if ($index[$lc]['heading']) { $headings[] = $index[$lc]['path']; continue; }
            $out[] = $index[$lc]['path'];
            continue;
        }
        if ($mode === 'controlled')  { $missing[] = $norm; continue; }
        $toAdd[] = count($out);
        $out[] = $norm;
    }
    if ($headings) {
        throw new WikiTaxonomyException('"' . implode('", "', $headings)
            . '" ' . (count($headings) > 1 ? 'are folders' : 'is a folder') . ' in the taxonomy, which only groups tags and cannot be used as one.');
    }
    if ($missing) {
        throw new WikiTaxonomyException('Only tags from the taxonomy can be used. Not in it: "'
            . implode('", "', $missing) . '".');
    }
    if ($toAdd) {
        $names = array_map(fn($i) => $out[$i], $toAdd);
        $spelled = wiki_tax_mutate(function (array &$nodes) use ($names) {
            $r = [];
            foreach ($names as $n) $r[] = wiki_tax_ensure($nodes, wiki_tax_split($n));
            return $r;
        }, 'Taxonomy: add ' . implode(', ', $names));
        foreach ($toAdd as $k => $i) $out[$i] = $spelled[$k];
    }
    return $out;
}

// ── Operations behind the admin page ─────────────────────────────────────────

/** @throws WikiTaxonomyException */
function wiki_tax_op(string $op, array $in): array {
    $path = wiki_tax_normalize((string)($in['path'] ?? ''));
    $segs = wiki_tax_split($path);
    $msg  = '';
    $rewritten = 0;

    switch ($op) {
        case 'add': {
            $parent = wiki_tax_normalize((string)($in['parent'] ?? ''));
            $name   = wiki_tax_clean_name($in['name'] ?? '');
            if ($name === '' || strpos($name, '/') !== false) throw new WikiTaxonomyException('Give the term a name without "/".');
            wiki_tax_mutate(function (array &$nodes) use ($parent, $name, $in) {
                $list = &_wiki_tax_children($nodes, wiki_tax_split($parent));
                if ($list === null) throw new WikiTaxonomyException('"' . $parent . '" is not in the taxonomy.');
                if (_wiki_tax_child($list, $name) >= 0) throw new WikiTaxonomyException('"' . $name . '" already exists there.');
                if (!empty($in['heading']) && _wiki_tax_is_item(wiki_tax_index($nodes), $parent)) {
                    throw new WikiTaxonomyException(WIKI_TAX_FOLDER_IN_ITEM);
                }
                $node = ['name' => $name];
                if (!empty($in['heading'])) $node['heading'] = true;
                if (trim((string)($in['field'] ?? '')) !== '') {
                    if (empty($in['heading'])) throw new WikiTaxonomyException(ucfirst(WIKI_TAX_FIELD_ON_ITEM));
                    $node['field'] = trim((string)$in['field']);
                    if (!empty($in['multiple'])) $node['multiple'] = true;
                }
                if (trim((string)($in['description'] ?? '')) !== '') $node['description'] = trim((string)$in['description']);
                $list[] = $node;
            }, 'Taxonomy: add ' . ($parent === '' ? $name : $parent . '/' . $name));
            break;
        }

        case 'update': {
            if (!$segs) throw new WikiTaxonomyException('Which term?');
            $index = wiki_tax_index();
            if (!isset($index[wiki_tax_lc($path)])) throw new WikiTaxonomyException('"' . $path . '" is not in the taxonomy.');
            $path = $index[wiki_tax_lc($path)]['path'];
            $segs = wiki_tax_split($path);
            $name = array_key_exists('name', $in) ? wiki_tax_clean_name($in['name']) : end($segs);
            if ($name === '' || strpos($name, '/') !== false) throw new WikiTaxonomyException('Give the term a name without "/".');
            // Absent means "as it is": a rename must not quietly turn a folder into an item.
            $heading = array_key_exists('heading', $in) ? !empty($in['heading']) : $index[wiki_tax_lc($path)]['heading'];
            if ($heading && !$index[wiki_tax_lc($path)]['heading']) {
                $exact = wiki_tax_usage()[wiki_tax_lc($path)]['count'] ?? 0;
                if ($exact > 0) throw new WikiTaxonomyException($exact . ' page(s) are tagged "' . $path
                    . '", and a folder cannot be a tag. Merge it into another item first.');
            }
            $newPath = implode('/', array_merge(array_slice($segs, 0, -1), [$name]));
            if ($heading && !$index[wiki_tax_lc($path)]['heading'] && ($fv = wiki_tax_value_use($path)) > 0) {
                throw new WikiTaxonomyException($fv . ' page(s) use "' . end($segs) . '" as a field value, and a folder cannot be one.');
            }
            if (array_key_exists('field', $in)) {
                $fname = trim((string)$in['field']);
                if ($fname !== '' && ($why = wiki_tax_field_name_problem($fname)) !== '') throw new WikiTaxonomyException($why);
            }
            $was_folder = $index[wiki_tax_lc($path)]['heading'];
            $bound_now = in_array(wiki_tax_lc($path), array_map('wiki_tax_lc', array_column(wiki_tax_fields(), 'path')), true);
            $field_after = array_key_exists('field', $in) ? trim((string)$in['field']) !== '' : $bound_now;
            if (!$heading && $field_after) {
                throw new WikiTaxonomyException('"' . $path . '": ' . ($was_folder && $bound_now && !array_key_exists('field', $in)
                    ? 'it is bound to a front-matter field, and ' . WIKI_TAX_FIELD_ON_ITEM . ' Remove the binding first.'
                    : WIKI_TAX_FIELD_ON_ITEM));
            }
            if ($heading && !$was_folder && _wiki_tax_is_item($index, implode('/', array_slice($segs, 0, -1)))) {
                throw new WikiTaxonomyException(WIKI_TAX_FOLDER_IN_ITEM);
            }
            if (!$heading && $was_folder && _wiki_tax_has_child_folder($index, $path)) {
                throw new WikiTaxonomyException('"' . $path . '" contains folders, and a folder cannot be inside an item. Move them out first.');
            }
            $fields_before = wiki_tax_fields();
            wiki_tax_mutate(function (array &$nodes) use ($segs, $name, $heading, $in) {
                $parent = array_slice($segs, 0, -1);
                $list = &_wiki_tax_children($nodes, $parent);
                $i = _wiki_tax_child($list, end($segs));
                $j = _wiki_tax_child($list, $name);
                if ($j >= 0 && $j !== $i) throw new WikiTaxonomyException('"' . $name . '" already exists there — use Merge.');
                $list[$i]['name'] = $name;
                if ($heading) $list[$i]['heading'] = true; else unset($list[$i]['heading']);
                if (array_key_exists('description', $in)) {
                    $d = trim((string)$in['description']);
                    if ($d !== '') $list[$i]['description'] = $d; else unset($list[$i]['description']);
                }
                if (array_key_exists('field', $in)) {
                    $fname = trim((string)$in['field']);
                    if ($fname !== '') {
                        $list[$i]['field'] = $fname;
                        if (!empty($in['multiple'])) $list[$i]['multiple'] = true; else unset($list[$i]['multiple']);
                    } else {
                        unset($list[$i]['field'], $list[$i]['multiple']);
                    }
                }
            }, $newPath !== $path ? "Taxonomy: rename $path to $newPath" : "Taxonomy: edit $path");
            if ($newPath !== $path) {
                $rewritten = wiki_tax_rewrite($path, $newPath);
                $rewritten += _wiki_tax_follow_values($fields_before, $path, $newPath);
            }
            break;
        }

        case 'move': {
            $index = wiki_tax_index();
            if (!isset($index[wiki_tax_lc($path)])) throw new WikiTaxonomyException('"' . $path . '" is not in the taxonomy.');
            $path = $index[wiki_tax_lc($path)]['path'];
            $segs = wiki_tax_split($path);
            $parent = wiki_tax_normalize((string)($in['parent'] ?? ''));
            if ($parent !== '' && !isset($index[wiki_tax_lc($parent)])) throw new WikiTaxonomyException('"' . $parent . '" is not in the taxonomy.');
            if ($parent !== '') $parent = $index[wiki_tax_lc($parent)]['path'];
            if ($parent !== '' && wiki_tax_under($parent, $path)) throw new WikiTaxonomyException('A term cannot be moved into itself.');
            $newPath = ($parent === '' ? '' : $parent . '/') . end($segs);
            if (wiki_tax_lc($newPath) === wiki_tax_lc($path)) break;
            if (isset($index[wiki_tax_lc($newPath)])) throw new WikiTaxonomyException('"' . $newPath . '" already exists — use Merge.');
            if ($index[wiki_tax_lc($path)]['heading'] && _wiki_tax_is_item($index, $parent)) {
                throw new WikiTaxonomyException(WIKI_TAX_FOLDER_IN_ITEM);
            }
            wiki_tax_mutate(function (array &$nodes) use ($segs, $parent) {
                $node = _wiki_tax_take($nodes, $segs);
                $list = &_wiki_tax_children($nodes, wiki_tax_split($parent));
                $list[] = $node;
            }, "Taxonomy: move $path to $newPath");
            $rewritten = wiki_tax_rewrite($path, $newPath);
            break;
        }

        case 'merge': {
            $index  = wiki_tax_index();
            $target = wiki_tax_normalize((string)($in['target'] ?? ''));
            foreach ([$path, $target] as $p) {
                if (!isset($index[wiki_tax_lc($p)])) throw new WikiTaxonomyException('"' . $p . '" is not in the taxonomy.');
            }
            $path   = $index[wiki_tax_lc($path)]['path'];
            $target = $index[wiki_tax_lc($target)]['path'];
            if (wiki_tax_under($target, $path)) throw new WikiTaxonomyException('A term cannot be merged into itself or one of its own children.');
            if (_wiki_tax_is_item($index, $target) && _wiki_tax_has_child_folder($index, $path)) {
                throw new WikiTaxonomyException('"' . $path . '" contains folders, and merging it into an item would put them inside one. Move them out first.');
            }
            $fields_before = wiki_tax_fields();
            if ($index[wiki_tax_lc($target)]['heading'] && (wiki_tax_usage()[wiki_tax_lc($path)]['count'] ?? 0) > 0) {
                throw new WikiTaxonomyException('"' . $target . '" is a folder, and pages tagged "' . $path . '" would end up tagged with it.');
            }
            wiki_tax_mutate(function (array &$nodes) use ($path, $target) {
                $src = _wiki_tax_take($nodes, wiki_tax_split($path));
                $tsegs = wiki_tax_split($target);
                $list = &_wiki_tax_children($nodes, array_slice($tsegs, 0, -1));
                $i = _wiki_tax_child($list, end($tsegs));
                _wiki_tax_fold($list[$i], $src);
            }, "Taxonomy: merge $path into $target");
            $rewritten = wiki_tax_rewrite($path, $target);
            $rewritten += _wiki_tax_follow_values($fields_before, $path, $target);
            break;
        }

        case 'delete': {
            $index = wiki_tax_index();
            if (!isset($index[wiki_tax_lc($path)])) throw new WikiTaxonomyException('"' . $path . '" is not in the taxonomy.');
            $path = $index[wiki_tax_lc($path)]['path'];
            $used = wiki_tax_in_use($path);
            if ($used > 0) throw new WikiTaxonomyException($used . ' tag(s) on pages use "' . $path
                . '" or a term below it. Merge it into another term instead, which moves those pages with it.');
            $fv = wiki_tax_value_use($path);
            if ($fv > 0) throw new WikiTaxonomyException($fv . ' page(s) use "' . $path
                . '" or an item below it as a field value. Merge it into another item instead, which moves those pages with it.');
            wiki_tax_mutate(function (array &$nodes) use ($path) {
                _wiki_tax_take($nodes, wiki_tax_split($path));
            }, "Taxonomy: delete $path");
            break;
        }

        default:
            throw new WikiTaxonomyException('Unknown operation.');
    }
    return ['rewritten' => $rewritten];
}

/** Put every tag in use that the tree does not have into it. Returns how many. */
function wiki_tax_adopt(): int {
    $index = wiki_tax_index();
    $missing = [];
    foreach (wiki_tax_usage() as $lc => $u) {
        $norm = wiki_tax_normalize($u['tag']);
        if ($norm !== '' && !isset($index[wiki_tax_lc($norm)])) $missing[wiki_tax_lc($norm)] = $norm;
    }
    if (!$missing) return 0;
    wiki_tax_mutate(function (array &$nodes) use ($missing) {
        // Every adopted path is a tag a page carries, so each ends as an item — including
        // a parent another adopted tag would otherwise have created as a folder.
        foreach ($missing as $m) wiki_tax_ensure($nodes, wiki_tax_split($m));
        foreach ($missing as $m) wiki_tax_ensure($nodes, wiki_tax_split($m), true);
    }, 'Taxonomy: adopt ' . count($missing) . ' tag(s) in use');
    return count($missing);
}

/** Tags pages use that are not terms. [['tag' => …, 'count' => n], …] by count. */
function wiki_tax_unclassified(?array $allowed = null): array {
    $index = wiki_tax_index();
    $out = [];
    foreach (wiki_tax_usage($allowed) as $lc => $u) {
        if (!isset($index[wiki_tax_lc(wiki_tax_normalize($u['tag']))])) $out[] = $u;
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['tag'], $b['tag']));
    return $out;
}

/**
 * Validate an import. Accepts an export (`{"format":"astucia-taxonomy","nodes":[…]}`), a
 * bare `{"nodes":[…]}` or a bare list.
 *
 * @return array{nodes:array,errors:array,terms:int,unclassified:int}
 */
function wiki_tax_import_check(string $raw): array {
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['nodes' => [], 'errors' => ['Not a JSON file: ' . json_last_error_msg() . '.'], 'terms' => 0, 'unclassified' => 0];
    }
    if (isset($data['format']) && $data['format'] !== 'astucia-taxonomy') {
        return ['nodes' => [], 'errors' => ['Not a taxonomy export (format "' . $data['format'] . '").'], 'terms' => 0, 'unclassified' => 0];
    }
    $list = array_key_exists('nodes', $data) ? $data['nodes'] : $data;
    $errors = []; $count = 0;
    $nodes = wiki_tax_validate($list, $errors, '', 1, $count);
    // Held to the folder rule a saved tree is only warned about: an import is new.
    foreach (wiki_tax_misplaced_folders($nodes) as $mp) $errors[] = '"' . $mp . '": ' . WIKI_TAX_FOLDER_IN_ITEM;
    $index = wiki_tax_index($nodes);
    $unc = 0;
    foreach (wiki_tax_usage() as $u) if (!isset($index[wiki_tax_lc(wiki_tax_normalize($u['tag']))])) $unc++;
    return ['nodes' => $nodes, 'errors' => array_slice($errors, 0, 50), 'terms' => $count, 'unclassified' => $unc];
}

function wiki_tax_import(array $nodes): void {
    wiki_tax_mutate(function (array &$cur) use ($nodes) { $cur = $nodes; }, 'Taxonomy: import');
}
