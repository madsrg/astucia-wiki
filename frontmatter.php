<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// YAML FRONT MATTER — read, and preserve
//
// A `.md` page may open with a `---` delimited block of metadata, as Obsidian, Hugo,
// Jekyll and most Markdown tooling write it. The wiki does not *author* that block: page
// metadata lives in index.json and stays there. What this file does is let a page that
// already carries one behave correctly — the block is kept out of everything that reads a
// page body, shown on request in the Metadata panel, and **preserved byte-for-byte when
// the page is saved**.
//
// That last part is not optional. The editor is given the body without the block, so a
// save sends back only the body; without re-attaching what was there, editing a page
// would delete its metadata silently. Every writer that replaces a whole body goes
// through wiki_fm_join().
//
// **The invariant everything rests on: `block . body === the original bytes`.** The block
// carries its own delimiters and trailing newline, so re-attaching is concatenation and
// cannot lose or add a byte. There is deliberately no serialiser: the wiki never rewrites
// the block, so it can never reformat someone's YAML, reorder their keys, or churn git.
// =================================================================

/**
 * How many lines a front matter block may occupy before we stop believing in it.
 *
 * A page whose body legitimately opens with a thematic break would otherwise have an
 * arbitrary amount of prose swallowed while looking for a closing `---`.
 */
require_once __DIR__ . '/settings.php';

const WIKI_FM_MAX_LINES = 200;

/** Keys shown first in the Metadata panel, in this order; anything else follows. */
const WIKI_FM_KNOWN_KEYS = ['title', 'author', 'created', 'updated', 'status', 'version', 'tags'];

/**
 * Split a file's bytes into its front matter block and its body.
 *
 * @return array{block:string, body:string, meta:array<string,string|array>}
 *         `block` is '' when there is none, and includes its delimiters and the newline
 *         that ends it. `meta` is the parsed keys, values kept as written.
 */
function wiki_fm_split(string $raw): array {
    $none = ['block' => '', 'body' => $raw, 'meta' => []];

    // A BOM before the delimiter is still a front matter file; leave it on the block so
    // the invariant holds.
    $offset = 0;
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $offset = 3;

    // The opening delimiter must be the first line and nothing but three dashes.
    if (!preg_match('/\G---[ \t]*\r?\n/', $raw, $m, 0, $offset)) return $none;
    $open_len = strlen($m[0]);

    // Find a closing delimiter within the line budget. `...` closes a YAML document too.
    $pos   = $offset + $open_len;
    $lines = 0;
    $close_at = null;
    $close_len = 0;
    $len = strlen($raw);
    while ($pos <= $len && $lines < WIKI_FM_MAX_LINES) {
        $eol  = strpos($raw, "\n", $pos);
        $line = $eol === false ? substr($raw, $pos) : substr($raw, $pos, $eol - $pos);
        if (preg_match('/^(---|\.\.\.)[ \t]*\r?$/', $line)) {
            $close_at  = $pos;
            $close_len = strlen($line) + ($eol === false ? 0 : 1);
            break;
        }
        if ($eol === false) break;
        $pos = $eol + 1;
        $lines++;
    }
    if ($close_at === null) return $none;

    $inner = substr($raw, $offset + $open_len, $close_at - ($offset + $open_len));

    // The decisive test, and the reason a thematic break is safe: the enclosed text has to
    // look like YAML mappings. `---\nSome prose\n---` is a horizontal rule around a line
    // of text, and stays content.
    if (!preg_match('/^[ \t]*[A-Za-z_][A-Za-z0-9_.\- ]*[ \t]*:(\s|$)/m', $inner)) return $none;

    $block = substr($raw, 0, $offset + $open_len + ($close_at - ($offset + $open_len)) + $close_len);
    return [
        'block' => $block,
        'body'  => substr($raw, strlen($block)),
        'meta'  => wiki_fm_parse_block($inner),
    ];
}

/** The body of a page, with any front matter removed. */
function wiki_fm_body(string $raw): string {
    return wiki_fm_split($raw)['body'];
}

/**
 * Put a body back behind the block it came with.
 *
 * Concatenation, deliberately: the block is never re-serialised, so a save cannot reformat
 * a key, change quoting, or reorder anything. A page with no block is returned unchanged.
 */
function wiki_fm_join(string $block, string $body): string {
    return $block . $body;
}

/**
 * Re-attach the front matter a file already has to a replacement body.
 *
 * The one call every whole-body writer needs: `save`, `wiki_write_page`, the chat
 * save-to-page append and the wikilink retargeter all hand over a body with no block.
 */
function wiki_fm_preserve(string $abs_path, string $new_body): string {
    if (!is_file($abs_path)) return $new_body;
    $existing = (string)@file_get_contents($abs_path);
    if ($existing === '') return $new_body;
    $block = wiki_fm_split($existing)['block'];
    if ($block === '') return $new_body;
    // A body that already carries its own block replaces it wholesale — that is an
    // explicit act (an upload, or a paste) and must not end up with two.
    if (wiki_fm_split($new_body)['block'] !== '') return $new_body;
    return wiki_fm_join($block, $new_body);
}

/**
 * Parse the subset of YAML a front matter block actually uses.
 *
 * `key: scalar`, `key: [a, b]`, and a `-` list on the following lines. Values are kept as
 * written — no date or number coercion — because this is metadata being passed through for
 * display and portability, not data the wiki computes with. Anything more involved
 * (a nested mapping) is kept as its raw text so the panel still shows it rather than
 * dropping it on the floor.
 *
 * Hand-rolled, and not a YAML library: this is thirty lines against a hard dependency for
 * a wiki that installs by copying a directory, the same trade the realtime JWT makes.
 */
function wiki_fm_parse_block(string $inner): array {
    $meta  = [];
    $lines = preg_split('/\r?\n/', $inner);
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.\- ]*?)[ \t]*:[ \t]*(.*)$/', $line, $m)) continue;
        $key = trim($m[1]);
        $val = trim($m[2]);

        if ($val === '') {
            // A list or a nested mapping on the following, more-indented lines.
            $items = [];
            $raw   = [];
            $j = $i + 1;
            while ($j < $count && (trim($lines[$j]) === '' || preg_match('/^\s+\S/', $lines[$j]))) {
                if (trim($lines[$j]) !== '') {
                    $raw[] = trim($lines[$j]);
                    if (preg_match('/^\s*-\s*(.*)$/', $lines[$j], $lm)) $items[] = wiki_fm_scalar($lm[1]);
                }
                $j++;
            }
            $i = $j - 1;
            if ($items) {
                $meta[$key] = $items;
            } elseif ($raw) {
                $meta[$key] = implode(' ', $raw);   // a nested mapping, shown as-is
            } else {
                $meta[$key] = '';
            }
            continue;
        }

        if (preg_match('/^\[(.*)\]$/s', $val, $fm)) {
            $flow = trim($fm[1]);
            $meta[$key] = $flow === ''
                ? []
                : array_values(array_map('wiki_fm_scalar', preg_split('/\s*,\s*/', $flow)));
            continue;
        }

        $meta[$key] = wiki_fm_scalar($val);
    }
    return $meta;
}

/** One scalar: quotes removed, nothing else interpreted. */
function wiki_fm_scalar(string $v): string {
    $v = trim($v);
    if (strlen($v) >= 2) {
        $a = $v[0];
        if (($a === '"' || $a === "'") && substr($v, -1) === $a) {
            $v = substr($v, 1, -1);
            if ($a === '"') $v = str_replace(['\\"', '\\\\'], ['"', '\\'], $v);
        }
    }
    return $v;
}

/**
 * The parsed keys in display order: the ones the product names first, then the rest as the
 * file had them. Shown in full rather than filtered, so an imported page's `aliases` or
 * `cssclass` stays visible instead of silently disappearing from view.
 */
function wiki_fm_ordered(array $meta): array {
    $out = [];
    foreach (WIKI_FM_KNOWN_KEYS as $k) {
        foreach ($meta as $mk => $mv) {
            if (strcasecmp($mk, $k) === 0 && !array_key_exists($mk, $out)) $out[$mk] = $mv;
        }
    }
    foreach ($meta as $k => $v) {
        if (!array_key_exists($k, $out)) $out[$k] = $v;
    }
    return $out;
}

// =================================================================
// WRITING — surgically, one line at a time
//
// Everything above reads. This writes, and it is built to keep the promise the reader
// makes: the wiki does not reformat somebody's YAML.
//
// **It is deliberately not a serialiser.** Parsing the block into a PHP array and emitting
// it again would be far shorter and would destroy exactly the files this feature exists
// for: wiki_fm_parse_block() keeps a nested mapping as joined raw text, drops comments and
// normalises quoting, so a round trip through it corrupts an Obsidian or Hugo page. Instead
// each operation finds the *line* it concerns and rewrites that line alone. Every byte
// nobody asked about — comments, key order, indentation style, nested structures, quoting,
// the line ending — comes out the way it went in.
// =================================================================

/** The line ending the file already uses, so a written line does not mix styles. */
function wiki_fm_line_ending(string $raw): string {
    return strpos($raw, "\r\n") !== false ? "\r\n" : "\n";
}

/** Does this key hold a list or a nested mapping (a value spanning further lines)? */
function wiki_fm_is_structured($value): bool {
    return is_array($value);
}

/**
 * One scalar, quoted only when it has to be.
 *
 * Conservative rather than clever: a value that could be read as something other than the
 * text typed gets double quotes. Unquoted is preferred where it is safe, because that is
 * what a human writes and this block is meant to stay readable.
 */
function wiki_fm_emit_scalar(string $v): string {
    // A line break would end the line and put the rest of the value in the document as
    // stray YAML. The API rejects one before it reaches here with a message the user can
    // act on; this is the last-resort guard, so that no path can produce a broken block.
    // Collapsed rather than escaped, because wiki_fm_scalar() decodes \\" and \\\\ but not
    // \\n — an emitter must not write something its own reader cannot read back.
    $v = preg_replace('/[\r\n\t]+/', ' ', $v);
    if ($v === '') return "''";
    $needs = $v !== trim($v)                       // leading or trailing space
        || strpbrk(substr($v, 0, 1), "#&*!|>%@`'\"[]{},?-") !== false
        || strpos($v, ': ') !== false
        || substr($v, -1) === ':'
        || strpos($v, ' #') !== false;
    if (!$needs) return $v;
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/** The index of the line declaring $key at the top level, or null. */
function _wiki_fm_key_line(array $lines, string $key): ?int {
    foreach ($lines as $i => $line) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.\- ]*?)[ \t]*:/', $line, $m)) continue;
        if (strcasecmp(trim($m[1]), $key) === 0) return $i;
    }
    return null;
}

/** How many lines after $i continue that key's value (a block list, a nested mapping). */
function _wiki_fm_continuation(array $lines, int $i): int {
    $n = 0;
    for ($j = $i + 1; $j < count($lines); $j++) {
        if (trim($lines[$j]) === '') break;          // a blank line ends the value
        if (!preg_match('/^[ \t]+\S/', $lines[$j])) break;
        $n++;
    }
    return $n;
}

/**
 * What kind of value each key holds, as written in the file: 'list' or 'nested'.
 *
 * The distinction decides what the editor may offer. A list round-trips through a
 * comma-separated box and is written back in its own style, so it is editable. A nested
 * mapping has no sensible one-line editor and is left alone.
 *
 * @return array<string,string> key (as written) => 'list' | 'nested'
 */
function wiki_fm_value_kinds(string $raw): array {
    $block = wiki_fm_split($raw)['block'];
    if ($block === '') return [];
    $lines = array_map(fn($l) => rtrim($l, "\r"), explode("\n", rtrim($block, "\n")));
    array_shift($lines);
    array_pop($lines);
    $out = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.\- ]*?)[ \t]*:[ \t]*(.*)$/', $line, $m)) continue;
        $key   = trim($m[1]);
        $value = trim($m[2]);
        if (preg_match('/^\[.*\]$/s', $value)) { $out[$key] = 'list'; continue; }
        if ($value !== '') continue;
        $n = _wiki_fm_continuation($lines, $i);
        if ($n === 0) continue;
        // `- item` on the following lines is a list; `key: value` is a nested mapping.
        $out[$key] = preg_match('/^\s*-\s/', $lines[$i + 1]) ? 'list' : 'nested';
    }
    return $out;
}

/**
 * The style a list key is written in, and the indent its items use.
 *
 * Preserved rather than normalised: rewriting somebody's block list as a flow list is a
 * reformat of their file, which is the one thing this module does not do.
 *
 * @return array{style:string, indent:string} style is 'flow' or 'block'
 */
function wiki_fm_list_style(string $raw, string $key): array {
    $block = wiki_fm_split($raw)['block'];
    $lines = $block === '' ? [] : array_map(fn($l) => rtrim($l, "\r"), explode("\n", rtrim($block, "\n")));
    if ($lines) { array_shift($lines); array_pop($lines); }
    foreach ($lines as $i => $line) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.\- ]*?)[ \t]*:[ \t]*(.*)$/', $line, $m)) continue;
        if (strcasecmp(trim($m[1]), $key) !== 0) continue;
        if (preg_match('/^\[.*\]$/s', trim($m[2]))) return ['style' => 'flow', 'indent' => '  '];
        if (trim($m[2]) === '' && isset($lines[$i + 1])
            && preg_match('/^(\s+)-\s/', $lines[$i + 1], $im)) {
            return ['style' => 'block', 'indent' => $im[1]];
        }
        break;
    }
    // A key that is new, or was a scalar until now: flow, which is compact and
    // unambiguous, and does not guess at an indentation the file never had.
    return ['style' => 'flow', 'indent' => '  '];
}

/** Just the keys holding a nested mapping — the ones no one-line editor can represent. */
function wiki_fm_nested_keys(string $raw): array {
    $out = [];
    foreach (wiki_fm_value_kinds($raw) as $k => $kind) {
        if ($kind === 'nested') $out[] = $k;
    }
    return $out;
}

/**
 * Keys whose value is a list or a nested mapping, as written in the file.
 *
 * Read from the **block text**, not from the parsed values, and that distinction is the
 * point: wiki_fm_parse_block() hands a nested mapping back as joined raw text, which is
 * indistinguishable from a scalar once parsed. A guard written against the parsed type
 * therefore lets a scalar be written over somebody's nested block — and since the writer
 * correctly drops continuation lines when a key becomes a scalar, their structure would be
 * deleted with no warning. These keys are read-only in the manual editor.
 *
 * @return string[] key names as they appear in the file
 */
function wiki_fm_structured_keys(string $raw): array {
    $block = wiki_fm_split($raw)['block'];
    if ($block === '') return [];
    $lines = array_map(fn($l) => rtrim($l, "\r"), explode("\n", rtrim($block, "\n")));
    array_shift($lines);          // opening delimiter
    array_pop($lines);            // closing delimiter
    $out = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_.\- ]*?)[ \t]*:[ \t]*(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        $structured = ($value === '' && _wiki_fm_continuation($lines, $i) > 0)   // block list / mapping
                   || preg_match('/^\[.*\]$/s', $value);                         // flow list
        if ($structured) $out[] = trim($m[1]);
    }
    return $out;
}

/**
 * One field as the lines it occupies: a scalar is one line, a list may be several.
 *
 * @param string|array $value
 * @return string[] lines, without their line ending
 */
function wiki_fm_emit_field(string $key, $value, array $style): array {
    if (!is_array($value)) return [$key . ': ' . wiki_fm_emit_scalar((string)$value)];
    $items = array_values(array_map(fn($v) => wiki_fm_emit_scalar((string)$v), $value));
    if (!$items) return [$key . ': []'];
    if (($style['style'] ?? 'flow') === 'block') {
        $lines = [$key . ':'];
        foreach ($items as $it) $lines[] = ($style['indent'] ?? '  ') . '- ' . $it;
        return $lines;
    }
    return [$key . ': [' . implode(', ', $items) . ']'];
}

/**
 * Add, change and remove keys in a page's front matter, returning the whole file.
 *
 * @param array<string,string> $updates key => scalar. An existing key keeps its position;
 *                                      a new one is appended just before the closing
 *                                      delimiter, which is where a person would add it.
 * @param string[]             $removals keys to delete, with any continuation lines.
 *
 * A file with no block gets one, at the very top (after a BOM if there is one) — that is
 * what lets a page authored here gain metadata at all. A no-op call returns the input
 * unchanged rather than rewriting it, so nothing reaches git for an edit that changed
 * nothing.
 */
function wiki_fm_set(string $raw, array $updates, array $removals = []): string {
    $split = wiki_fm_split($raw);
    $eol   = wiki_fm_line_ending($raw);

    // ── No block yet: create one, or do nothing at all ──────────────────────
    if ($split['block'] === '') {
        if (!$updates) return $raw;
        $bom  = substr($raw, 0, 3) === "\xEF\xBB\xBF" ? "\xEF\xBB\xBF" : '';
        $body = $bom === '' ? $raw : substr($raw, 3);
        $out  = '---' . $eol;
        foreach ($updates as $k => $v) {
            foreach (wiki_fm_emit_field($k, $v, ['style' => 'flow', 'indent' => '  ']) as $line) {
                $out .= $line . $eol;
            }
        }
        $out .= '---' . $eol;
        return $bom . $out . $body;
    }

    // ── Split the existing block into its delimiters and its inner lines ────
    // The block ends with its own line ending, so the final explode() element is ''.
    $block_lines = explode("\n", rtrim($split['block'], "\n"));
    $block_lines = array_map(fn($l) => rtrim($l, "\r"), $block_lines);
    $open  = array_shift($block_lines);
    $close = array_pop($block_lines);
    $inner = $block_lines;

    foreach ($removals as $key) {
        $i = _wiki_fm_key_line($inner, (string)$key);
        if ($i === null) continue;
        array_splice($inner, $i, 1 + _wiki_fm_continuation($inner, $i));
    }

    foreach ($updates as $key => $value) {
        // A list is written back in the style the file already used for that key — turning
        // somebody's block list into a flow list is a reformat, not an edit.
        $lines = wiki_fm_emit_field((string)$key, $value, wiki_fm_list_style($raw, (string)$key));
        $i = _wiki_fm_key_line($inner, (string)$key);
        if ($i === null) {
            foreach ($lines as $l) $inner[] = $l;
            continue;
        }
        // Replacing a multi-line value means its continuation lines go too, or the file
        // keeps orphaned list items under a key that no longer has a list.
        array_splice($inner, $i, 1 + _wiki_fm_continuation($inner, $i), $lines);
    }

    $rebuilt = $open . $eol
             . ($inner ? implode($eol, $inner) . $eol : '')
             . $close . $eol;
    $out = ($split['block'] === $rebuilt) ? $raw : $rebuilt . $split['body'];
    // A BOM lives on the block, which we rebuilt from its own bytes, so it survives.
    return $out;
}

// =================================================================
// AUTOMATIC STAMPING
//
// When a Space has it on, four fields in a page's own block are maintained by the wiki:
// `created`, `createdBy`, `updated`, `updatedBy`. index.json remains the source of truth —
// this is a *projection* of it into the file, so that the metadata travels with the `.md`.
//
// Which means, and the settings page says so: a value hand-edited in one of those four
// fields is overwritten on the next save. The other fields in the block are never touched.
// =================================================================

/** The four fields the wiki maintains. Everything else in a block belongs to its author. */
const WIKI_FM_STAMP_KEYS = ['created', 'createdBy', 'updated', 'updatedBy'];

/** Full ISO-8601 with offset: sortable, unambiguous, and what other tooling expects. */
function wiki_fm_stamp_time(?int $ts = null): string {
    return date('c', $ts ?? time());
}

/**
 * Work out what the stamped fields should say, given what the file and the index hold.
 *
 * The two halves behave differently on purpose:
 *
 *  - `updated` / `updatedBy` are **always rewritten** — that is the point of the feature.
 *  - `created` / `createdBy` are **fill-if-absent**. An imported Obsidian note carries
 *    somebody else's `created`, and replacing it with "when this wiki first saw the file"
 *    destroys real information. The same rule makes a copied page keep its origin.
 *
 * `createdBy` is never invented: `applyReconcile()` records no author for a page that
 * arrived by rsync or git pull, and a guessed author is worse than an absent one — so when
 * the index does not know, the key is left out rather than filled with the person who
 * happened to save next.
 *
 * @param array       $meta        the block's parsed fields (case matters only for display)
 * @param array|null  $index_entry that page's index.json record, if any
 * @param string|null $actor_name  who is saving; null for an actor with no name
 * @return array<string,string> fields to write, possibly empty
 */
function wiki_fm_stamp_fields(array $meta, ?array $index_entry, ?string $actor_name): array {
    $have = [];
    foreach (array_keys($meta) as $k) $have[strtolower($k)] = true;
    $out = [];

    // `created` and `createdBy` are filled as a **pair**, and only when the file asserts
    // neither. A page that carries its own `created: 2019-04-01` is making a claim about
    // its origin; attaching the index's author to that date would invent a (date, author)
    // combination that never existed — the index's author is simply whoever created the
    // page *here*, which for an imported note is nobody.
    if (!isset($have['created']) && !isset($have['createdby'])) {
        $created = (int)($index_entry['created'] ?? 0);
        if ($created > 0) $out['created'] = wiki_fm_stamp_time($created);
        $by = trim((string)($index_entry['createdBy']['name'] ?? ''));
        if ($by !== '') $out['createdBy'] = $by;
    }
    $out['updated'] = wiki_fm_stamp_time();
    if ($actor_name !== null && trim($actor_name) !== '') $out['updatedBy'] = trim($actor_name);

    return $out;
}

/**
 * Stamp a page that has already been written to disk.
 *
 * Returns the file's new byte length when it rewrote the file, or null when it did not —
 * the caller needs that, because the `save` response reports `size` and the open-page
 * watcher re-baselines from it. Reporting the pre-stamp length reloads the page under the
 * author on the watcher's next poll.
 *
 * `$body_changed` is the churn control and the caller is the only one who can answer it:
 * with it false nothing is written at all, so opening a page and saving it untouched does
 * not produce a commit whose entire diff is a new `updated:` line.
 *
 * A stamped key that currently holds a list or a nested mapping is skipped rather than
 * flattened — pathological in these four fields, but the writer would drop its
 * continuation lines and that is not a decision to make on somebody's behalf.
 */
function wiki_fm_autostamp_file(string $abs_path, ?array $index_entry,
                               ?string $actor_name, bool $body_changed = true): ?int {
    if (!$body_changed) return null;
    if (!is_file($abs_path)) return null;
    $raw = (string)@file_get_contents($abs_path);
    if ($raw === '') return null;

    $split  = wiki_fm_split($raw);
    $fields = wiki_fm_stamp_fields($split['meta'], $index_entry, $actor_name);

    $structured = array_map('strtolower', wiki_fm_structured_keys($raw));
    foreach (array_keys($fields) as $k) {
        if (in_array(strtolower($k), $structured, true)) unset($fields[$k]);
    }
    if (!$fields) return null;

    // Written under the key's existing name where it has one, so a file using `Created:`
    // keeps its own capitalisation instead of gaining a second, differently-cased key.
    $renamed = [];
    foreach ($fields as $k => $v) {
        $as = $k;
        foreach (array_keys($split['meta']) as $existing) {
            if (strcasecmp($existing, $k) === 0) { $as = $existing; break; }
        }
        $renamed[$as] = $v;
    }

    $new = wiki_fm_set($raw, $renamed);
    if ($new === $raw) return null;
    if (@file_put_contents($abs_path, $new) === false) return null;
    return strlen($new);
}

/**
 * Whether AI users and MCP clients are shown a page's front matter. Off unless enabled.
 *
 * A block can be written as instructions addressed to a model, which is a feature when it
 * was meant that way and prompt injection when it was not — so it is a decision an
 * administrator makes, not a default.
 *
 * settings.php is required from here rather than relied upon: mcp.php and the cron runner
 * do not include it directly, and a `function_exists()` guard would have answered "off" in
 * exactly those two entry points while the same page exposed the block in chat.
 */
function wiki_fm_expose_to_ai(): bool {
    return (bool)wiki_setting('frontmatter_expose_ai', false);
}
