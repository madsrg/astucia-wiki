<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// .list FILES ON THE SERVER — how a field's value reads as text
//
// The PHP twin of modules/list/fields.js, for the two places that render a list without
// the browser: export.php (CSV / XML) and the static-site export. Keep the two in step:
//
// - A `lookup` value is an array of item ids in another list, named by page id in
//   `lookup.list`. It reads as those items' display field (`lookup.field`, else the target's
//   Title), joined with "; "; an item that no longer exists reads "(deleted #12)".
// - A `lookup_field` is another field of the same item(s) — SharePoint's "also show".
// - The target is resolved only through the **same Space's** index.json, which is what
//   keeps a lookup from reading another Space's list: a page id from elsewhere is simply
//   not found there, and the cell falls back to the bare ids.
// =================================================================

/**
 * Load every list the lookups of $list point at.
 *
 * @param string $space_dir  the Space the list lives in (its index.json resolves ids)
 * @return array  page id => decoded list, or null when it could not be read
 */
function wiki_list_lookup_targets(array $list, string $space_dir): array {
    $ids = [];
    foreach ($list['columns'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'lookup' && isset($c['lookup']['list'])) $ids[(string)$c['lookup']['list']] = true;
    }
    if (!$ids) return [];
    $base  = realpath($space_dir);
    $index = $base ? json_decode((string)@file_get_contents($base . '/index.json'), true) : null;
    $out = [];
    foreach (array_keys($ids) as $id) {
        $out[$id] = null;
        $rel = is_array($index) ? ($index[$id]['path'] ?? null) : null;
        if (!is_string($rel) || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'list') continue;
        $abs = realpath($base . '/' . $rel);
        // Inside this Space, with the trailing separator (…/Alpha2 is not inside …/Alpha).
        if (!$abs || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) continue;
        $data = json_decode((string)@file_get_contents($abs), true);
        if (is_array($data)) $out[$id] = $data;
    }
    return $out;
}

/** The Title field of a list: `role: title`, else col_2 if single-line text, else the first. */
function wiki_list_title_field(array $list): ?string {
    $cols = $list['columns'] ?? [];
    foreach ($cols as $c) if (($c['role'] ?? '') === 'title') return $c['id'];
    foreach ($cols as $c) if (($c['id'] ?? '') === 'col_2' && ($c['type'] ?? '') === 'text_single') return 'col_2';
    foreach ($cols as $c) if (($c['type'] ?? '') === 'text_single') return $c['id'];
    return null;
}

function _wiki_list_ids($value): array {
    $vals = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
    $out = [];
    foreach ($vals as $v) if (is_numeric($v)) $out[] = (int)$v;
    return $out;
}

function _wiki_list_find_item(array $list, int $id): ?array {
    foreach ($list['items'] ?? [] as $it) if ((int)($it['id'] ?? -1) === $id) return $it;
    return null;
}

/** The text a field shows for an item. $targets is wiki_list_lookup_targets()'s result. */
function wiki_list_cell(array $col, array $item, array $columns, array $targets): string {
    $type = $col['type'] ?? '';
    if ($type === 'lookup') {
        $ids = _wiki_list_ids($item[$col['id']] ?? null);
        if (!$ids) return '';
        $target = $targets[(string)($col['lookup']['list'] ?? '')] ?? null;
        if (!$target) return implode('; ', array_map(fn($i) => '#' . $i, $ids));
        $field = $col['lookup']['field'] ?? wiki_list_title_field($target);
        return implode('; ', array_map(function ($i) use ($target, $field) {
            $hit = _wiki_list_find_item($target, $i);
            return $hit ? (string)($hit[$field] ?? '') : "(deleted #$i)";
        }, $ids));
    }
    if ($type === 'lookup_field') {
        $source = null;
        foreach ($columns as $c) if (($c['id'] ?? '') === ($col['source'] ?? null)) { $source = $c; break; }
        if (!$source) return '';
        $target = $targets[(string)($source['lookup']['list'] ?? '')] ?? null;
        $ids = _wiki_list_ids($item[$source['id']] ?? null);
        if (!$target || !$ids) return '';
        $vals = [];
        foreach ($ids as $i) {
            $hit = _wiki_list_find_item($target, $i);
            if ($hit) $vals[] = (string)($hit[$col['field']] ?? '');
        }
        return implode('; ', $vals);
    }
    $v = $item[$col['id'] ?? ''] ?? '';
    return is_scalar($v) ? (string)$v : '';
}

/**
 * The list with every item's values replaced by the text they show. For renderers that
 * only ever print values (the static export): their own code — tables, view filters —
 * then works on lookups without knowing they exist.
 */
function wiki_list_flatten(array $list, string $space_dir): array {
    $columns = $list['columns'] ?? [];
    $targets = wiki_list_lookup_targets($list, $space_dir);
    $items = [];
    foreach ($list['items'] ?? [] as $item) {
        $flat = $item;
        foreach ($columns as $col) $flat[$col['id']] = wiki_list_cell($col, $item, $columns, $targets);
        $items[] = $flat;
    }
    $list['items'] = $items;
    return $list;
}
