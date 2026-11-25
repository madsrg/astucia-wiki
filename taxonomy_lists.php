<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// =================================================================
// LIST FIELDS BOUND TO A TAXONOMY FOLDER
//
// A list's Choice field — or a Choice sub-field of a Group of fields — may take its values from
// a taxonomy folder instead of a typed list of choices: `"taxonomy": "Department"` on the
// column (or sub-field), with `"multiple": true` for several values. A value is the **name** of
// an item anywhere inside that folder ("Budget", not "Finance/Budget") — the rule front-matter
// fields bound to a folder already follow (wiki_tax_fields) — and several are a JSON array.
//
// The binding names the folder by path, because taxonomy nodes have no ids. So the tree's
// admin operations follow into the lists, as they do into pages' tags and blocks:
//  - a folder renamed, moved or merged rewrites every column naming it (or a folder below it);
//  - an item renamed or merged rewrites the values that name it, where the new name is still
//    a value of that field;
//  - deleting a term a list uses is refused, as is deleting, or turning into an item, a
//    folder a list field takes its values from (taxonomy.php, wiki_tax_op).
// A rewrite keeps the file's mtime (a vocabulary change is not an edit), leaves frozen Spaces
// as they are, and is committed once per repository — wiki_tax_rewrite_field_value's rules.
//
// Values are checked where they are set: `save` and wiki_update_list_item refuse a value a
// save *adds* that is not an item of the field's folder (wiki_list_taxonomy_check). A value
// already in the file passes — renames are followed, and one moved out of the folder stays
// until someone picks another, shown as "not in the taxonomy".
// =================================================================

/**
 * The items anywhere inside the folder at $path: lower-case name => ['value' => spelling,
 * 'path' => item path], the first of a name winning. Null when $path is not a folder.
 */
function wiki_tax_branch_values(string $path, ?array $nodes = null): ?array {
    $nodes = $nodes ?? wiki_tax_nodes();
    $segs = wiki_tax_split(wiki_tax_normalize($path));
    if (!$segs) return null;
    $list = $nodes;
    $node = null;
    $real = [];
    foreach ($segs as $seg) {
        $node = null;
        foreach ($list as $n) if (wiki_tax_lc($n['name']) === wiki_tax_lc($seg)) { $node = $n; break; }
        if ($node === null) return null;
        $real[] = $node['name'];
        $list = $node['children'] ?? [];
    }
    if (empty($node['heading'])) return null;
    $vals = [];
    $collect = function (array $list, string $parent) use (&$collect, &$vals) {
        foreach ($list as $n) {
            $p = $parent . '/' . $n['name'];
            if (empty($n['heading'])) {
                $k = wiki_tax_lc($n['name']);
                if (!isset($vals[$k])) $vals[$k] = ['value' => $n['name'], 'path' => $p];
            }
            if (!empty($n['children'])) $collect($n['children'], $p);
        }
    };
    $collect($node['children'] ?? [], implode('/', $real));
    return $vals;
}

/** A cell's taxonomy values as a list of non-empty strings. */
function _wiki_tax_cell_values($v): array {
    $vals = is_array($v) ? $v : [$v];
    $out = [];
    foreach ($vals as $x) if (is_scalar($x) && trim((string)$x) !== '') $out[] = trim((string)$x);
    return $out;
}

/** The fields of one decoded list that take their values from a folder: [col id, sub id|null, name, folder, multiple]. */
function wiki_tax_list_fields(array $list): array {
    $out = [];
    foreach ($list['columns'] ?? [] as $c) {
        if (!is_array($c)) continue;
        if (($c['type'] ?? '') === 'choice' && is_string($c['taxonomy'] ?? null) && $c['taxonomy'] !== '') {
            $out[] = [(string)$c['id'], null, (string)($c['name'] ?? $c['id']), $c['taxonomy'], !empty($c['multiple'])];
        }
        if (($c['type'] ?? '') === 'object') {
            foreach ($c['fields'] ?? [] as $sf) {
                if (is_array($sf) && ($sf['type'] ?? '') === 'choice' && is_string($sf['taxonomy'] ?? null) && $sf['taxonomy'] !== '') {
                    $out[] = [(string)$c['id'], (string)$sf['id'], (string)($c['name'] ?? $c['id']) . ' › ' . (string)($sf['name'] ?? $sf['id']),
                              $sf['taxonomy'], !empty($sf['multiple'])];
                }
            }
        }
    }
    return $out;
}

/**
 * Every cell of a bound field in one item: a list of [&value-holder…] is awkward in PHP, so
 * this calls $fn(value) for each cell and stores what it returns. $fn gets the raw value.
 */
function _wiki_tax_item_cells(array &$item, string $cid, ?string $sid, callable $fn): void {
    if ($sid === null) {
        if (array_key_exists($cid, $item)) $item[$cid] = $fn($item[$cid]);
        return;
    }
    $g = $item[$cid] ?? null;
    if (!is_array($g)) return;
    if ($g !== [] && array_is_list($g)) {
        foreach ($g as &$entry) if (is_array($entry) && array_key_exists($sid, $entry)) $entry[$sid] = $fn($entry[$sid]);
        unset($entry);
    } elseif (array_key_exists($sid, $g)) {
        $g[$sid] = $fn($g[$sid]);
    }
    $item[$cid] = $g;
}

/** Every .list in every Space and the root: [dir, rel, abs, space]. */
function _wiki_tax_list_files(): array {
    $out = [];
    foreach (wiki_tax_index_dirs() as $space => $dir) {
        foreach (PageIndexer::scanContent($dir) as $rel => $_m) {
            if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'list') $out[] = [$dir, $rel, $dir . '/' . $rel, (string)$space];
        }
    }
    return $out;
}

/**
 * Every bound field in every list, with its values counted: a list of ['space', 'list', 'field',
 * 'folder', 'counts' => lc value => n]. One read of every list — paid only by the taxonomy's
 * admin operations and its admin pane, like wiki_tax_field_usage().
 */
function wiki_tax_list_bindings(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (_wiki_tax_list_files() as [$dir, $rel, $abs, $space]) {
        $raw = (string)@file_get_contents($abs);
        if (strpos($raw, '"taxonomy"') === false) continue;
        $list = json_decode($raw, true);
        if (!is_array($list)) continue;
        foreach (wiki_tax_list_fields($list) as [$cid, $sid, $name, $folder, $multiple]) {
            $counts = [];
            foreach ($list['items'] ?? [] as $item) {
                if (!is_array($item)) continue;
                _wiki_tax_item_cells($item, $cid, $sid, function ($v) use (&$counts) {
                    foreach (_wiki_tax_cell_values($v) as $x) $counts[wiki_tax_lc($x)] = ($counts[wiki_tax_lc($x)] ?? 0) + 1;
                    return $v;
                });
            }
            $cache[] = ['space' => $space, 'list' => $rel, 'field' => $name, 'folder' => $folder, 'counts' => $counts];
        }
    }
    return $cache;
}

/** List items' values naming an item at or below $path, in the folders they are bound to. */
function wiki_tax_list_value_use(string $path): int {
    $n = 0;
    foreach (wiki_tax_list_bindings() as $b) {
        $vals = wiki_tax_branch_values($b['folder']);
        if (!$vals) continue;
        foreach ($vals as $lv => $v) if (wiki_tax_under($v['path'], $path)) $n += $b['counts'][$lv] ?? 0;
    }
    return $n;
}

/** For the admin pane: lc item path => list values naming it, over every bound field. */
function wiki_tax_list_usage(): array {
    $out = [];
    foreach (wiki_tax_list_bindings() as $b) {
        $vals = wiki_tax_branch_values($b['folder']);
        if (!$vals) continue;
        foreach ($b['counts'] as $lv => $n) {
            if (isset($vals[$lv])) $out[wiki_tax_lc($vals[$lv]['path'])] = ($out[wiki_tax_lc($vals[$lv]['path'])] ?? 0) + $n;
        }
    }
    return $out;
}

/** "Space/List.list › Field" for every list field bound to $path or a folder below it. */
function wiki_tax_list_folder_refs(string $path): array {
    $out = [];
    foreach (wiki_tax_list_bindings() as $b) {
        if (wiki_tax_under($b['folder'], $path)) $out[] = ($b['space'] !== '' ? $b['space'] . '/' : '') . $b['list'] . ' › ' . $b['field'];
    }
    return array_values(array_unique($out));
}

/** For the admin pane: lc folder path => the list fields bound to exactly it. */
function wiki_tax_list_refs(): array {
    $out = [];
    foreach (wiki_tax_list_bindings() as $b) {
        $out[wiki_tax_lc(wiki_tax_normalize($b['folder']))][] = ($b['space'] !== '' ? $b['space'] . '/' : '') . $b['list'] . ' › ' . $b['field'];
    }
    return $out;
}

/**
 * After the node at $from became $to (rename, move or merge), follow it into every list:
 * columns bound to $from or a folder below it name the new path, and — when $from was an item
 * whose name changed — values naming it take the new name where it is still a value of that
 * field. $nodes_before is the tree before the operation. Returns list files changed.
 */
function wiki_tax_lists_follow(array $nodes_before, string $from, string $to): int {
    $fromLen = strlen($from);
    $oldName = substr($from, strrpos('/' . $from, '/'));
    $newName = substr($to, strrpos('/' . $to, '/'));
    $index_before = wiki_tax_index($nodes_before);
    $was_item = isset($index_before[wiki_tax_lc($from)]) && !$index_before[wiki_tax_lc($from)]['heading'];
    $changed = 0; $commits = [];
    foreach (_wiki_tax_list_files() as [$dir, $rel, $abs, $space]) {
        if (wiki_space_dir_is_readonly($dir)) continue;
        $raw = (string)@file_get_contents($abs);
        if (strpos($raw, '"taxonomy"') === false) continue;
        $list = json_decode($raw, true);
        if (!is_array($list)) continue;
        $dirty = false;
        foreach ($list['columns'] as &$c) {
            $retarget = function (array &$f) use ($from, $to, $fromLen, &$dirty) {
                if (!is_string($f['taxonomy'] ?? null) || !wiki_tax_under($f['taxonomy'], $from)) return;
                $f['taxonomy'] = $to . substr(wiki_tax_normalize($f['taxonomy']), $fromLen);
                $dirty = true;
            };
            if (!is_array($c)) continue;
            if (($c['type'] ?? '') === 'choice') $retarget($c);
            if (($c['type'] ?? '') === 'object') foreach ($c['fields'] as &$sf) if (is_array($sf) && ($sf['type'] ?? '') === 'choice') $retarget($sf);
            unset($sf);
        }
        unset($c);
        if ($was_item && wiki_tax_lc($oldName) !== '' && $oldName !== $newName) {
            // An item was renamed or merged, so no folder moved: a column names a folder, never
            // an item, and the folder paths above are what they were before the operation.
            foreach (wiki_tax_list_fields($list) as [$cid, $sid, , $folder]) {
                $before = wiki_tax_branch_values($folder, $nodes_before);
                // Only where the old item was a value of this field, and the new name is one now.
                if (!$before || !isset($before[wiki_tax_lc($oldName)])
                    || wiki_tax_lc($before[wiki_tax_lc($oldName)]['path']) !== wiki_tax_lc($from)) continue;
                $after = wiki_tax_branch_values($folder);
                $hit = $after[wiki_tax_lc($newName)] ?? null;
                if (!$hit) continue;
                foreach ($list['items'] as &$item) {
                    if (!is_array($item)) continue;
                    _wiki_tax_item_cells($item, $cid, $sid, function ($v) use ($oldName, $hit, &$dirty) {
                        $swap = function ($x) use ($oldName, $hit, &$dirty) {
                            if (!is_scalar($x) || wiki_tax_lc(trim((string)$x)) !== wiki_tax_lc($oldName)) return $x;
                            $dirty = true;
                            return $hit['value'];
                        };
                        if (is_array($v)) {
                            $nv = [];
                            foreach (array_map($swap, $v) as $x) if (!in_array($x, $nv, true)) $nv[] = $x;
                            return $nv;
                        }
                        return $swap($v);
                    });
                }
                unset($item);
            }
        }
        if (!$dirty) continue;
        $out = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        clearstatcache(true, $abs);
        $mt = @filemtime($abs);
        if (@file_put_contents($abs, $out) === false) continue;
        if ($mt) @touch($abs, $mt);
        $changed++;
        if (function_exists('find_git_root') && ($g = find_git_root($dir))) $commits[$g['root']][] = $g['prefix'] . $rel;
    }
    foreach ($commits as $root => $files) {
        foreach ($files as $f) git_run(['add', '--', $f], $root);
        $actor = function_exists('get_current_actor') ? get_current_actor() : [];
        git_run(['-c', 'user.name=' . ($actor['name'] ?? 'Wiki'), '-c', 'user.email=wiki@localhost',
                 'commit', '-m', "Taxonomy: $from → $to in lists"], $root);
    }
    wiki_tax_list_bindings(true);
    return $changed;
}
