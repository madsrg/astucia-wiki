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
// - A `number` reads with its decimals ("12.50") — no unit and no thousands separator, so
//   a spreadsheet takes it as a number; a `boolean` reads "Yes" / "No"; `comments` reads
//   "Name (2026-11-19 14:05): text", joined with "; " (XML writes each as an element).
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

// ── Group of fields (`object`) — the twin of groupEntries() / groupSummary() in fields.js ──

/** A group's value as a list of entries: one (or none) for a single group, every
 *  well-formed entry for one that allows several. */
function wiki_list_group_entries(array $col, $value): array {
    $is_entry = fn($e) => is_array($e) && ($e === [] || !array_is_list($e));
    if (!empty($col['multiple'])) {
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, $is_entry)) : [];
    }
    return $is_entry($value) && $value !== [] ? [$value] : [];
}

/** One entry as one line — "Main St 1, Oslo": its summary sub-fields (those picked, else
 *  the first two), empty ones left out. */
function wiki_list_group_summary(array $col, $entry): string {
    $ids = array_column($col['fields'] ?? [], 'id');
    $picked = array_values(array_intersect($col['summary'] ?? [], $ids));
    $vals = [];
    foreach ($picked ?: array_slice($ids, 0, 2) as $id) {
        $v = is_array($entry) ? ($entry[$id] ?? null) : null;
        if (is_scalar($v) && trim((string)$v) !== '') $vals[] = (string)$v;
    }
    return implode(', ', $vals);
}

/** A number as its field shows it on the server: its decimals, '.' as the point. */
function wiki_list_number_text(array $col, $v): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '';
    $d = max(0, min(6, (int)($col['decimals'] ?? 0)));
    return number_format((float)$v, $d, '.', '');
}

/** A Comments field's well-formed entries, oldest first. */
function wiki_list_comment_entries($value): array {
    if (!is_array($value) || !array_is_list($value)) return [];
    return array_values(array_filter($value, fn($e) => is_array($e) && is_string($e['text'] ?? null)));
}

/** A comment's time as the server writes it: "2026-11-19 14:05", in the server's zone. */
function wiki_list_comment_time($at): string {
    $ts = is_string($at) ? strtotime($at) : false;
    return $ts ? date('Y-m-d H:i', $ts) : '';
}

/** One field of a target item as it reads — a lookup's display or "also show" value. */
function _wiki_list_target_text(array $target, $field, array $hit): string {
    foreach ($target['columns'] ?? [] as $c) {
        if (($c['id'] ?? null) !== $field) continue;
        if (($c['type'] ?? '') === 'number')  return wiki_list_number_text($c, $hit[$field] ?? null);
        if (($c['type'] ?? '') === 'boolean') return ($hit[$field] ?? null) === true ? 'Yes' : 'No';
        break;
    }
    $v = $hit[$field] ?? '';
    return is_scalar($v) ? (string)$v : '';
}

/** The text a field shows for an item. $targets is wiki_list_lookup_targets()'s result. */
function wiki_list_cell(array $col, array $item, array $columns, array $targets): string {
    $type = $col['type'] ?? '';
    if ($type === 'number')  return wiki_list_number_text($col, $item[$col['id'] ?? ''] ?? null);
    if ($type === 'boolean') return ($item[$col['id'] ?? ''] ?? null) === true ? 'Yes' : 'No';
    if ($type === 'comments') {
        return implode('; ', array_map(fn($c) => trim((string)($c['name'] ?? '') . ' (' . wiki_list_comment_time($c['at'] ?? null) . ')')
            . ': ' . $c['text'], wiki_list_comment_entries($item[$col['id'] ?? ''] ?? null)));
    }
    if ($type === 'object') {
        return implode('; ', array_map(fn($e) => wiki_list_group_summary($col, $e),
                                       wiki_list_group_entries($col, $item[$col['id'] ?? ''] ?? null)));
    }
    if ($type === 'lookup') {
        $ids = _wiki_list_ids($item[$col['id']] ?? null);
        if (!$ids) return '';
        $target = $targets[(string)($col['lookup']['list'] ?? '')] ?? null;
        if (!$target) return implode('; ', array_map(fn($i) => '#' . $i, $ids));
        $field = $col['lookup']['field'] ?? wiki_list_title_field($target);
        return implode('; ', array_map(function ($i) use ($target, $field) {
            $hit = _wiki_list_find_item($target, $i);
            return $hit ? _wiki_list_target_text($target, $field, $hit) : "(deleted #$i)";
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
            if ($hit) $vals[] = _wiki_list_target_text($target, $col['field'] ?? null, $hit);
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

// ── XML export ───────────────────────────────────────────────────────────────────────────

/** A field name as an XML element name: letters, digits and _ only, never a leading digit. */
function wiki_list_xml_tag(string $name): string {
    $tag = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
    if ($tag === '' || is_numeric($tag[0])) $tag = '_' . $tag;
    return $tag;
}

/**
 * The list as XML: one <item> per item, one element per field holding the text it shows —
 * except a Group of fields, which is written as the structure it is:
 * <Address><Street>…</Street><City>…</City></Address>, and for several entries
 * <DebitCards><entry id="4"><Number>…</Number></entry>…</DebitCards>.
 *
 * @param array $raw   the list as stored (a group's sub-values)
 * @param array $flat  wiki_list_flatten()'s result (every other field's text)
 */
function wiki_list_xml(array $raw, array $flat): string {
    $columns = $raw['columns'] ?? [];
    $xml = new SimpleXMLElement('<?xml version="1.0"?><items></items>');
    $raw_items = $raw['items'] ?? [];
    foreach (($flat['items'] ?? []) as $i => $item) {
        $xml_item = $xml->addChild('item');
        foreach ($columns as $col) {
            $tag = wiki_list_xml_tag((string)($col['name'] ?? ''));
            if (($col['type'] ?? '') === 'comments') {
                $log = $xml_item->addChild($tag);
                foreach (wiki_list_comment_entries($raw_items[$i][$col['id']] ?? null) as $c) {
                    $node = $log->addChild('comment', htmlspecialchars($c['text']));
                    $node->addAttribute('id', (string)($c['id'] ?? ''));
                    $node->addAttribute('by', (string)($c['name'] ?? ''));
                    $node->addAttribute('at', (string)($c['at'] ?? ''));
                }
                continue;
            }
            if (($col['type'] ?? '') !== 'object') {
                $xml_item->addChild($tag, htmlspecialchars((string)($item[$col['id']] ?? '')));
                continue;
            }
            $group = $xml_item->addChild($tag);
            $entries = wiki_list_group_entries($col, $raw_items[$i][$col['id']] ?? null);
            foreach ($entries as $entry) {
                $node = $group;
                if (!empty($col['multiple'])) {
                    $node = $group->addChild('entry');
                    if (isset($entry['id'])) $node->addAttribute('id', (string)$entry['id']);
                }
                foreach ($col['fields'] ?? [] as $sub) {
                    $v = $entry[$sub['id']] ?? '';
                    $node->addChild(wiki_list_xml_tag((string)($sub['name'] ?? '')), htmlspecialchars(is_scalar($v) ? (string)$v : ''));
                }
            }
        }
    }
    return $xml->asXML();
}

// ── Checking a list before it is saved ───────────────────────────────────────────────────
//
// A Group of fields has a shape the browser always writes correctly, and that everything
// reading a list — the table, the form, the exports, page generators — relies on. A save that
// breaks it (a hand edit, another tool posting to `save`) is refused rather than "fixed":
// fixing would mean dropping data without saying so. Only what groups, numbers, Yes/No and
// comments add is checked: the file being a list at all, each group's definition, the values'
// shapes, and the entry counter (shared by group entries and comments). Text, date and choice
// fields are as lenient as they always were.

const WIKI_LIST_GROUP_TYPES = ['text_single', 'text_multi', 'date', 'choice'];

/**
 * Why this list must not be saved, or null when it may:
 * ['code' => …, 'item' => item id or null, 'field' => field name or null, 'detail' => …].
 * Codes: not_list, bad_group, group_not_object, group_not_list, entry_no_id, entry_id_taken,
 * unknown_subfield, value_not_text, counter_behind, value_not_number, value_not_boolean,
 * comment_bad. Runs after wiki_list_comments_stamp(), so every comment has its stamp here.
 */
function wiki_list_check(string $raw): ?array {
    $no = fn(string $code, $item = null, $field = null, string $detail = '') =>
        ['code' => $code, 'item' => $item, 'field' => $field, 'detail' => $detail];
    $list = json_decode($raw, true);
    if (!is_array($list) || array_is_list($list) && $list !== []
        || !is_array($list['columns'] ?? null) || !array_is_list($list['columns'])
        || (isset($list['items']) && (!is_array($list['items']) || !array_is_list($list['items'])))) {
        return $no('not_list');
    }

    $groups = [];
    foreach ($list['columns'] as $col) {
        if (!is_array($col) || ($col['type'] ?? '') !== 'object') continue;
        $name = (string)($col['name'] ?? ($col['id'] ?? ''));
        $fields = $col['fields'] ?? null;
        if (!is_array($fields) || !$fields || !array_is_list($fields)) return $no('bad_group', null, $name, 'no fields');
        $ids = [];
        foreach ($fields as $f) {
            $fid = is_array($f) ? ($f['id'] ?? null) : null;
            if (!is_string($fid) || $fid === '' || $fid === 'id' || isset($ids[$fid])) return $no('bad_group', null, $name, 'field ids');
            if (!in_array($f['type'] ?? '', WIKI_LIST_GROUP_TYPES, true)) return $no('bad_group', null, $name, (string)($f['type'] ?? ''));
            $ids[$fid] = true;
        }
        $groups[] = ['id' => (string)($col['id'] ?? ''), 'name' => $name, 'multiple' => !empty($col['multiple']), 'ids' => $ids];
    }
    $typed = [];    // number / boolean / comments columns: [id, name, type]
    foreach ($list['columns'] as $col) {
        $type = is_array($col) ? ($col['type'] ?? '') : '';
        if (in_array($type, ['number', 'boolean', 'comments'], true)) {
            $typed[] = [(string)($col['id'] ?? ''), (string)($col['name'] ?? ($col['id'] ?? '')), $type];
        }
    }
    if (!$groups && !$typed) return null;

    $entry_ids = [];
    $values_ok = function (array $values, array $allowed, $item, string $name, bool $with_id) use ($no) {
        foreach ($values as $k => $v) {
            if ($with_id && $k === 'id') continue;
            if (!isset($allowed[$k])) return $no('unknown_subfield', $item, $name, (string)$k);
            if ($v !== null && !is_scalar($v)) return $no('value_not_text', $item, $name, (string)$k);
        }
        return null;
    };
    foreach (($list['items'] ?? []) as $n => $item) {
        if (!is_array($item)) continue;
        $item_ref = $item['id'] ?? ($n + 1);
        foreach ($typed as [$cid, $cname, $type]) {
            $value = $item[$cid] ?? null;
            if ($value === null) continue;
            // A number is a JSON number; '' (an emptied box from an older client) is no value.
            if ($type === 'number' && !is_int($value) && !is_float($value) && $value !== '') return $no('value_not_number', $item_ref, $cname);
            if ($type === 'boolean' && !is_bool($value)) return $no('value_not_boolean', $item_ref, $cname);
            if ($type === 'comments') {
                if (!is_array($value) || !array_is_list($value)) return $no('comment_bad', $item_ref, $cname);
                foreach ($value as $c) {
                    if (!is_array($c) || !is_int($c['id'] ?? null) || $c['id'] < 1 || !is_string($c['text'] ?? null)
                        || !is_int($c['by'] ?? null) || !is_string($c['name'] ?? null) || !is_string($c['at'] ?? null)) {
                        return $no('comment_bad', $item_ref, $cname);
                    }
                    if (isset($entry_ids[$c['id']])) return $no('entry_id_taken', $item_ref, $cname, (string)$c['id']);
                    $entry_ids[$c['id']] = true;
                }
            }
        }
        foreach ($groups as $g) {
            $value = $item[$g['id']] ?? null;
            if ($value === null) continue;
            if (!$g['multiple']) {
                // {} decodes to [] — an empty group, which is fine; a list is not.
                if (!is_array($value) || ($value !== [] && array_is_list($value))) return $no('group_not_object', $item_ref, $g['name']);
                if ($err = $values_ok($value, $g['ids'], $item_ref, $g['name'], false)) return $err;
                continue;
            }
            if (!is_array($value) || !array_is_list($value)) return $no('group_not_list', $item_ref, $g['name']);
            foreach ($value as $entry) {
                if (!is_array($entry) || ($entry !== [] && array_is_list($entry)) || !is_int($entry['id'] ?? null) || $entry['id'] < 1) {
                    return $no('entry_no_id', $item_ref, $g['name']);
                }
                // Entry ids are numbered list-wide, so they are unique list-wide.
                if (isset($entry_ids[$entry['id']])) return $no('entry_id_taken', $item_ref, $g['name'], (string)$entry['id']);
                $entry_ids[$entry['id']] = true;
                if ($err = $values_ok($entry, $g['ids'], $item_ref, $g['name'], true)) return $err;
            }
        }
    }
    // A counter at or below an id in use would hand that id out again.
    if ($entry_ids && array_key_exists('nextEntryId', $list)
        && (!is_int($list['nextEntryId']) || $list['nextEntryId'] <= max(array_keys($entry_ids)))) {
        return $no('counter_behind', null, null, (string)max(array_keys($entry_ids)));
    }
    return null;
}

/** wiki_list_check()'s reason in English, for logs and API callers; the browser words its own. */
function wiki_list_check_message(array $why): string {
    $where = ($why['item'] !== null ? "item {$why['item']}, " : '') . ($why['field'] !== null ? "field \"{$why['field']}\": " : '');
    $what = [
        'not_list'         => 'this is not a list (columns and items).',
        'bad_group'        => 'the group\'s fields are not valid (' . $why['detail'] . ').',
        'group_not_object' => 'a group\'s value must be one set of sub-fields.',
        'group_not_list'   => 'a group that allows several must hold a list of entries.',
        'entry_no_id'      => 'every entry needs a whole-number id.',
        'entry_id_taken'   => 'entry id ' . $why['detail'] . ' is used twice.',
        'unknown_subfield' => '"' . $why['detail'] . '" is not a field of the group.',
        'value_not_text'   => '"' . $why['detail'] . '" must hold a plain value.',
        'counter_behind'   => 'nextEntryId must be above ' . $why['detail'] . ', the highest entry id.',
        'value_not_number' => 'the value must be a number.',
        'value_not_boolean'=> 'the value must be true or false.',
        'comment_bad'      => 'a comment must have id, by, name, at and text.',
        'comment_changed'  => 'an existing comment cannot be changed — remove it and add a new one.',
        'comment_remove_denied' => 'only its author or an administrator may remove a comment by ' . $why['detail'] . '.',
        'comment_empty'    => 'a new comment needs some text.',
    ][$why['code']] ?? $why['code'];
    return 'The list was not saved: ' . $where . $what;
}

// ── Comments: stamped by the server ──────────────────────────────────────────────────────
//
// A Comments field is a log — who said what, when. The browser saves a list as a whole file,
// so if it wrote the stamps a comment could be posted in anybody's name, or an old one quietly
// reworded. It therefore sends a new comment as { "text": … } alone, and this stamps it: an id
// from the list's `nextEntryId` (shared with group entries, list-wide, never reused), `by` (the
// uid), `name` and `at` (UTC, ISO 8601). Against the file as it is on disk:
//   - an entry carrying an id must be exactly the one stored under that id, in the same item
//     and field — not reworded, re-dated or re-attributed (comment_changed);
//   - an entry no longer there was removed: allowed for its author, or an administrator
//     (comment_remove_denied otherwise). Deleting the whole item or field is not checked —
//     that is an editor's right over the item, not over one comment.
// Called by the `save` action before wiki_list_check(), under the save's lock.

const WIKI_LIST_COMMENT_MAX = 10000;

/**
 * @param string      $new_raw  the list being saved
 * @param string|null $old_raw  the list on disk (null for a new file)
 * @param array       $actor    ['uid' => int, 'name' => string]
 * @return array  ['content' => string, 'stamped' => bool] or ['error' => wiki_list_check()-shaped]
 */
function wiki_list_comments_stamp(string $new_raw, ?string $old_raw, array $actor, bool $is_admin): array {
    $keep = ['content' => $new_raw, 'stamped' => false];
    $new = json_decode($new_raw, true);
    if (!is_array($new) || !is_array($new['columns'] ?? null) || !is_array($new['items'] ?? null)) return $keep;
    $fields = [];
    foreach ($new['columns'] as $col) {
        if (is_array($col) && ($col['type'] ?? '') === 'comments' && is_string($col['id'] ?? null)) $fields[$col['id']] = (string)($col['name'] ?? $col['id']);
    }
    if (!$fields) return $keep;
    $no = fn(string $code, $item, $field, string $detail = '') =>
        ['error' => ['code' => $code, 'item' => $item, 'field' => $field, 'detail' => $detail]];

    // What is on disk, by item id and field: comment id => entry.
    $old = $old_raw !== null ? json_decode($old_raw, true) : null;
    $before = [];
    foreach ((is_array($old) ? ($old['items'] ?? []) : []) as $it) {
        if (!is_array($it) || !isset($it['id'])) continue;
        foreach ($fields as $fid => $_) {
            foreach (wiki_list_comment_entries($it[$fid] ?? null) as $c) {
                if (is_int($c['id'] ?? null)) $before[(string)$it['id']][$fid][$c['id']] = $c;
            }
        }
    }

    // The counter starts past every id in use in the list being saved, group entries included.
    $next = is_int($new['nextEntryId'] ?? null) ? $new['nextEntryId'] : 1;
    $seen = function ($v) use (&$next, &$seen) {
        if (!is_array($v)) return;
        if (isset($v['id']) && is_int($v['id']) && !array_is_list($v)) $next = max($next, $v['id'] + 1);
        foreach ($v as $k => $x) if (is_array($x)) $seen($x);
    };
    foreach ($new['items'] as $it) {
        if (!is_array($it)) continue;
        foreach ($it as $k => $v) if ($k !== 'id' && is_array($v)) $seen($v);
    }

    $stamped = false;
    $now = gmdate('Y-m-d\TH:i:s\Z');
    foreach ($new['items'] as $n => $it) {
        if (!is_array($it)) continue;
        $ref = $it['id'] ?? ($n + 1);
        foreach ($fields as $fid => $fname) {
            $value = $it[$fid] ?? null;
            $was = $before[(string)($it['id'] ?? '')][$fid] ?? [];
            if ($value === null || $value === '') $value = [];
            if (!is_array($value) || !array_is_list($value)) return $no('comment_bad', $ref, $fname);
            $out = [];
            $kept = [];
            foreach ($value as $c) {
                if (!is_array($c)) return $no('comment_bad', $ref, $fname);
                if (array_key_exists('id', $c)) {
                    // An existing comment: exactly as stored, or refused.
                    $orig = is_int($c['id']) ? ($was[$c['id']] ?? null) : null;
                    if ($orig === null) return $no('comment_changed', $ref, $fname, (string)$c['id']);
                    foreach (['by', 'name', 'at', 'text'] as $k) {
                        if (($c[$k] ?? null) !== ($orig[$k] ?? null)) return $no('comment_changed', $ref, $fname, (string)$c['id']);
                    }
                    $kept[$c['id']] = true;
                    $out[] = $orig;
                    continue;
                }
                // A new comment: text only, stamped here.
                $text = is_string($c['text'] ?? null) ? trim($c['text']) : '';
                if ($text === '' || array_diff(array_keys($c), ['text'])) return $no($text === '' ? 'comment_empty' : 'comment_bad', $ref, $fname);
                if (function_exists('mb_substr')) $text = mb_substr($text, 0, WIKI_LIST_COMMENT_MAX);
                $out[] = ['id' => $next++, 'by' => (int)($actor['uid'] ?? 0), 'name' => (string)($actor['name'] ?? ''),
                          'at' => $now, 'text' => $text];
                $stamped = true;
            }
            // Removed since the file was read: the author's own, or an administrator's call.
            foreach ($was as $cid => $orig) {
                if (isset($kept[$cid])) continue;
                if (!$is_admin && (int)($orig['by'] ?? -1) !== (int)($actor['uid'] ?? 0)) {
                    return $no('comment_remove_denied', $ref, $fname, (string)($orig['name'] ?? ''));
                }
            }
            if ($out || array_key_exists($fid, $it)) $new['items'][$n][$fid] = $out;
        }
    }
    if (!$stamped) return $keep;
    $new['nextEntryId'] = $next;
    return ['content' => json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'stamped' => true];
}
