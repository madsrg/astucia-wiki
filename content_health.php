<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// CONTENT HEALTH — what in a Space needs attention
//
// Four lists, each a question an editor would otherwise answer by reading every page:
//
//   broken   links that go nowhere: `?pageid=N` to a page that no longer exists (in this
//            Space or the one the link names), `[[wikilinks]]` no page answers to, and
//            `![[photo.png]]` embeds whose file is not in the page's `.uploads/`
//   orphans  pages no other page links to — reachable only through the tree
//   stale    pages whose front matter `review_by:` date has passed, and pages nobody has
//            changed for `stale_days`
//   empty    Markdown pages with (almost) nothing in the body
//
// **No file is read here that the graph has not already read.** Everything comes from
// WikiGraph::scan(), the per-file cache in graph.json keyed by mtime, so a report on an
// unchanged Space costs one index read and one cache read. The only filesystem calls are
// is_file() for attachment embeds — their files change without the page's mtime moving,
// so that answer cannot be cached against the page.
//
// **Space isolation.** A link into another Space is checked only when the actor may read
// that Space (`wiki_space_allowed`). Otherwise it is left out, rather than reported broken
// or fine: either answer would tell a restricted user whether page N exists in a Space they
// cannot see.
// =================================================================

require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/wikilinks.php';

const WIKI_HEALTH_EMPTY_CHARS  = 40;    // visible characters below which a page is a stub
const WIKI_HEALTH_STALE_DAYS   = 180;   // default for the "not updated in" filter
const WIKI_HEALTH_MAX_ROWS     = 500;   // per list; the totals are always exact

/** Page types the report is about. A chat or a saved search is not content anyone curates. */
const WIKI_HEALTH_TYPES = ['md', 'list', 'json', 'drawio'];

/**
 * A front matter date as a Unix timestamp at the start of that day, or null when the value
 * is not a date. Accepts the ISO forms front matter uses (`2026-11-04`, with or without a
 * time) and quoted ones.
 */
function wiki_health_date(?string $v): ?int {
    $v = trim((string)$v, " \t\"'");
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Build the report for one Space.
 *
 * @param string       $space_dir  absolute Space directory
 * @param PageIndexer  $indexer    that Space's indexer
 * @param array|null   $allowed    the actor's Space allowlist (actor_spaces_filter), null = all
 * @param array        $opts       `stale_days` (int, default 180), `now` (int, for tests)
 */
function wiki_content_health(string $space_dir, PageIndexer $indexer, ?array $allowed, array $opts = []): array {
    $now        = (int)($opts['now'] ?? time());
    $stale_days = max(1, (int)($opts['stale_days'] ?? WIKI_HEALTH_STALE_DAYS));
    $today      = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
    $space_dir  = rtrim($space_dir, '/');

    $pages   = $indexer->getAllPages();
    $graph   = new WikiGraph($space_dir, $indexer);
    $scan    = $graph->scan($pages);          // only pages that exist, templates etc. excluded
    $self    = $graph->spaceName();
    $resolve = wikilink_resolver($pages);

    $row = function (string $id) use ($pages): array {
        $p = (string)($pages[$id]['path'] ?? '');
        return ['id' => $id, 'path' => $p, 'title' => wiki_health_title($p)];
    };
    $type_of = fn(string $path): string => strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Other Spaces' indexes, loaded once each and only when a link names that Space.
    $other = [];
    $other_ids = function (string $space) use (&$other, $allowed): ?array {
        if (array_key_exists($space, $other)) return $other[$space];
        $ids = null;                                   // null = do not report on this Space
        $ok  = $space !== '' && $space[0] !== '.' && strpos($space, '/') === false
            && strpos($space, '\\') === false && wiki_space_allowed($allowed, $space);
        if ($ok) {
            $dir = rtrim(PAGES_DIR, '/') . '/' . $space;
            if (!is_dir($dir)) {
                $ids = [];                             // a Space that does not exist: every link is broken
            } else {
                $idx = json_decode((string)@file_get_contents($dir . '/index.json'), true);
                $ids = [];
                foreach ((is_array($idx) ? $idx : []) as $oid => $od) {
                    if (!empty($od['path']) && is_file($dir . '/' . ltrim($od['path'], '/'))) $ids[(string)$oid] = true;
                }
            }
        }
        return $other[$space] = $ids;
    };

    // A link target exists when the page is on disk. Not only the scanned pages: a link to a
    // template or a memory page is a working link, even though neither is in the graph.
    $exists = fn(string $t): bool => isset($scan[$t])
        || (!empty($pages[$t]['path']) && is_file($space_dir . '/' . ltrim($pages[$t]['path'], '/')));

    $broken = []; $inbound = [];
    foreach ($scan as $id => $e) {
        $id    = (string)$id;
        $path  = (string)($pages[$id]['path'] ?? '');
        $links = [];
        foreach (($e['pid'] ?? []) as $t) {
            $t = (string)$t;
            if ($exists($t)) { if ($t !== $id) $inbound[$t] = true; continue; }
            $links[] = ['kind' => 'page', 'target' => $t];
        }
        foreach (($e['xs'] ?? []) as [$sp, $t]) {
            $ids = $other_ids((string)$sp);
            if ($ids === null || isset($ids[(string)$t])) continue;
            $links[] = ['kind' => 'space', 'target' => (string)$t, 'space' => (string)$sp];
        }
        foreach (($e['wl'] ?? []) as $target) {
            $rid = $resolve((string)$target);
            if ($rid !== null && $exists((string)$rid)) {
                if ((string)$rid !== $id) $inbound[(string)$rid] = true;
                continue;
            }
            if (wikilink_is_attachment((string)$target)) {
                // Where modules/wikilinks looks for it: beside the page, in <page>.uploads/.
                if (is_file($space_dir . '/' . $path . '.uploads/' . $target)) continue;
                $links[] = ['kind' => 'attachment', 'target' => (string)$target];
                continue;
            }
            $links[] = ['kind' => 'wikilink', 'target' => (string)$target];
        }
        if ($links) $broken[] = $row($id) + ['links' => $links];
    }

    // The start page is reached from the logo on every screen; it is nobody's orphan.
    $start_id = (string)($indexer->getId('Main.md') ?? '');

    $orphans = []; $stale = []; $empty = [];
    foreach ($scan as $id => $e) {
        $id   = (string)$id;
        $data = $pages[$id] ?? [];
        $path = (string)($data['path'] ?? '');
        $type = $type_of($path);
        if (!in_array($type, WIKI_HEALTH_TYPES, true)) continue;

        if (!isset($inbound[$id]) && $id !== $start_id) $orphans[] = $row($id);

        $updated = (int)($data['updated'] ?? $data['created'] ?? $e['mtime'] ?? 0);
        $fm      = $e['fm'] ?? [];
        $info    = $row($id) + [
            'updated'    => $updated ?: null,
            'updated_by' => $data['updatedBy']['name'] ?? null,
            'owner'      => $fm['owner'] ?? null,
            'reviewed'   => $fm['reviewed'] ?? null,
            'review_by'  => $fm['review_by'] ?? null,
        ];
        $due = wiki_health_date($fm['review_by'] ?? null);
        if ($due !== null && $due < $today) {
            $stale[] = $info + ['reason' => 'review_overdue', 'days' => (int)floor(($today - $due) / 86400)];
        } elseif ($due === null && $updated > 0 && $updated < $now - $stale_days * 86400) {
            // A page with a review date still in the future is being looked after, however
            // long ago it last changed — that is the point of setting one.
            $stale[] = $info + ['reason' => 'not_updated', 'days' => (int)floor(($now - $updated) / 86400)];
        }

        if ($type === 'md' && isset($e['chars']) && (int)$e['chars'] < WIKI_HEALTH_EMPTY_CHARS) {
            $empty[] = $row($id) + ['chars' => (int)$e['chars']];
        }
    }

    $by_path = fn($a, $b) => strnatcasecmp($a['path'], $b['path']);
    usort($broken, $by_path);
    usort($orphans, $by_path);
    usort($empty, $by_path);
    // Overdue reviews first (most overdue on top), then the longest-untouched pages.
    usort($stale, fn($a, $b) => [$a['reason'] !== 'review_overdue', -$a['days'], $a['path']]
                            <=> [$b['reason'] !== 'review_overdue', -$b['days'], $b['path']]);

    $cap = fn(array $rows) => array_slice($rows, 0, WIKI_HEALTH_MAX_ROWS);
    return [
        'space'      => $self,
        'stale_days' => $stale_days,
        'counts'     => [
            'pages'   => count(array_filter($scan, fn($e, $id) => in_array($type_of((string)($pages[$id]['path'] ?? '')), WIKI_HEALTH_TYPES, true), ARRAY_FILTER_USE_BOTH)),
            'broken'  => count($broken),
            'links'   => array_sum(array_map(fn($r) => count($r['links']), $broken)),
            'orphans' => count($orphans),
            'stale'   => count($stale),
            'empty'   => count($empty),
        ],
        'broken'  => $cap($broken),
        'orphans' => $cap($orphans),
        'stale'   => $cap($stale),
        'empty'   => $cap($empty),
    ];
}

/** A page's display name: its file name without the extension. */
function wiki_health_title(string $path): string {
    return preg_replace('/\.[^.\/]+$/', '', basename($path));
}

/**
 * The report as plain text, for an AI user or an MCP client — short enough to fit in a
 * prompt, with every list capped at `$per_list` rows and the true totals stated.
 */
function wiki_content_health_text(array $r, int $per_list = 50): string {
    $c   = $r['counts'];
    $out = ['Content health for Space "' . ($r['space'] === '' ? '(root)' : $r['space']) . '": '
          . $c['pages'] . ' pages; ' . $c['links'] . ' broken link(s) on ' . $c['broken'] . ' page(s), '
          . $c['orphans'] . ' orphan(s), ' . $c['stale'] . ' stale, ' . $c['empty'] . ' empty.'];

    $section = function (string $title, array $rows, int $total, callable $line) use (&$out, $per_list) {
        if (!$rows) return;
        $out[] = '';
        $out[] = $title . ' (' . $total . '):';
        foreach (array_slice($rows, 0, $per_list) as $row) $out[] = '- ' . $line($row);
        if ($total > $per_list) $out[] = '- … and ' . ($total - $per_list) . ' more';
    };
    $section('Broken links', $r['broken'], $c['broken'], function ($row) {
        $what = array_map(function ($l) {
            switch ($l['kind']) {
                case 'page':       return 'pageid=' . $l['target'] . ' (no such page)';
                case 'space':      return 'pageid=' . $l['target'] . ' in Space "' . $l['space'] . '" (no such page)';
                case 'attachment': return '![[' . $l['target'] . ']] (attachment not found)';
                default:           return '[[' . $l['target'] . ']] (no page by that name)';
            }
        }, $row['links']);
        return $row['path'] . ' (id ' . $row['id'] . '): ' . implode('; ', $what);
    });
    $section('Orphans — no other page links to these', $r['orphans'], $c['orphans'],
        fn($row) => $row['path'] . ' (id ' . $row['id'] . ')');
    $section('Stale', $r['stale'], $c['stale'], function ($row) use ($r) {
        $why = $row['reason'] === 'review_overdue'
            ? 'review was due ' . $row['review_by'] . ' (' . $row['days'] . ' days ago)'
            : 'not updated for ' . $row['days'] . ' days (threshold ' . $r['stale_days'] . ')';
        return $row['path'] . ' (id ' . $row['id'] . '): ' . $why
             . ($row['owner'] ? '; owner ' . $row['owner'] : '')
             . ($row['updated_by'] ? '; last edited by ' . $row['updated_by'] : '');
    });
    $section('Empty or stub pages', $r['empty'], $c['empty'],
        fn($row) => $row['path'] . ' (id ' . $row['id'] . ', ' . $row['chars'] . ' visible characters)');
    return implode("\n", $out);
}
