<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

/**
 * Wikilinks — `[[Page]]`, `[[Page|alias]]`, `[[Page#Heading]]` and `![[Page]]` embeds.
 *
 * Server-side half: resolves a wikilink target to a page id so that **backlinks and the
 * knowledge graph see them**. Both of those scan page bodies for `pageid=`, so without this
 * an imported Obsidian vault would render its links correctly and still show no backlinks
 * and nothing in the graph.
 *
 * Wikilinks are deliberately **within-space only**. `[[Space:Page]]` would look like Obsidian
 * compatibility while being a dialect Obsidian renders as broken, which throws away the only
 * thing the syntax buys us; cross-space links keep the `?pageid=ID&space=Name` form, which
 * addresses another space and survives a rename.
 *
 * THE RESOLUTION RULES BELOW ARE DUPLICATED IN modules/wikilinks/index.js. They must agree, or
 * a link will render pointing at one page while its backlink is recorded against another.
 * Any change here needs the same change there (and both test suites cover the same cases).
 */

// A wikilink: optional leading "!" for an embed, then [[target#heading|alias]]. Target may be
// empty for a same-page [[#Heading]] link.
const WIKILINK_RE = '/(!?)\[\[([^\]\|#]*)(?:#([^\]\|]*))?(?:\|([^\]]*))?\]\]/u';

// The same link pattern, but preceded by alternatives that match a fenced block and an inline
// code span, so a single pass can recognise and skip both. Ordering matters: the code branches
// come first, so a `[[link]]` inside either is consumed by them and never reaches the link
// branch. This mirrors mapOutsideCode() in modules/core/md_text.js — the client renders those
// examples literally, so counting them as references would show backlinks for text that is not
// a link, and rewriting them would corrupt a page documenting the syntax.
const WIKILINK_SCAN_RE = '/
      (?P<fence> ^[ \t]{0,3} (?P<fchar> `{3,} | ~{3,} ) [^\n]* $ .*? ^[ \t]{0,3} (?P=fchar) [ \t]* $ )
    | (?P<code>  `+ [^`\n]* `+ )
    | (?P<link>  (!?) \[\[ ([^\]\|\#]*) (?: \# ([^\]\|]*) )? (?: \| ([^\]]*) )? \]\] )
/msxu';

/**
 * Walk `$text`, handing every wikilink that is *not* inside code to `$onLink`, and return the
 * text with each replaced by whatever the callback returns. Returning `$m[0]` leaves it alone,
 * which is how the scan-only callers use it.
 *
 * @param callable(array): string $onLink receives the link's match array: [0]=whole,
 *        [1]="!" for an embed, [2]=target, [3]=heading, [4]=alias.
 */
function wikilink_walk(string $text, callable $onLink): string {
    if (strpos($text, '[[') === false) return $text;
    $out = preg_replace_callback(WIKILINK_SCAN_RE, function (array $m) use ($onLink) {
        if (($m['fence'] ?? '') !== '' || ($m['code'] ?? '') !== '') return $m[0];  // code — verbatim
        // Re-match the link alone so the callback sees stable group numbers regardless of
        // where the named groups above landed.
        if (!preg_match(WIKILINK_RE, $m[0], $lm)) return $m[0];
        return $onLink($lm);
    }, $text);
    return $out ?? $text;
}

// Extensions a wikilink target may carry and that resolution should ignore, since Obsidian
// links normally omit them.
const WIKILINK_STRIP_EXTS = ['md', 'drawio', 'list', 'chat', 'search', 'json'];

/** Normalise a target for comparison: no extension, no ./ prefix, forward slashes, lowercase. */
function wikilink_normalise(string $target): string {
    $t = trim(str_replace('\\', '/', $target));
    $t = preg_replace('#^\./#', '', $t);
    $t = trim($t, '/');
    $ext = strtolower(pathinfo($t, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, WIKILINK_STRIP_EXTS, true)) {
        $t = substr($t, 0, -(strlen($ext) + 1));
    }
    return mb_strtolower($t);
}

/**
 * Build a resolver over one space's pages.
 *
 * @param array $pages id => ['path' => 'Folder/Page.md', …] as PageIndexer::getAllPages returns.
 * @return callable(string): ?string  target → page id, or null when it does not resolve.
 */
function wikilink_resolver(array $pages): callable {
    $by_path = [];   // 'folder/page' => id
    $by_name = [];   // 'page'        => [id, …]
    foreach ($pages as $id => $data) {
        $path = (string)($data['path'] ?? '');
        if ($path === '') continue;
        $key = wikilink_normalise($path);
        if (!isset($by_path[$key])) $by_path[$key] = (string)$id;
        $base = wikilink_normalise(basename($path));
        $by_name[$base][] = (string)$id;
    }
    // Deterministic tie-break for a duplicated basename: Obsidian prefers the shortest path,
    // so fewest directory segments first, then the shortest string.
    $shortest = function (array $ids) use ($pages): string {
        usort($ids, function ($a, $b) use ($pages) {
            $pa = (string)($pages[$a]['path'] ?? '');
            $pb = (string)($pages[$b]['path'] ?? '');
            $da = substr_count($pa, '/');
            $db = substr_count($pb, '/');
            return $da <=> $db ?: strlen($pa) <=> strlen($pb) ?: strcmp($pa, $pb);
        });
        return $ids[0];
    };

    return function (string $target) use ($by_path, $by_name, $shortest): ?string {
        $key = wikilink_normalise($target);
        if ($key === '') return null;                       // [[#Heading]] — same page, no target
        if (isset($by_path[$key])) return $by_path[$key];   // exact relative path wins
        if (isset($by_name[$key])) {
            $ids = $by_name[$key];
            return count($ids) === 1 ? $ids[0] : $shortest($ids);
        }
        return null;
    };
}

/**
 * Every page id referenced by a wikilink or embed in `$text`, de-duplicated.
 * Targets that do not resolve are dropped — a broken link is not a graph edge.
 */
function wikilink_ids(string $text, callable $resolve): array {
    $ids = [];
    wikilink_walk($text, function (array $m) use ($resolve, &$ids) {
        $id = $resolve($m[2] ?? '');
        if ($id !== null) $ids[$id] = true;
        return $m[0];
    });
    // Cast back to strings: PHP turns numeric array keys into integers, and graph.php works in
    // the strings preg_match_all yields, so returning ints here would make a strict comparison
    // between a wikilink edge and a `pageid=` edge silently fail to match.
    return array_map('strval', array_keys($ids));
}

/**
 * Rewrite wikilinks that point at `$old_path` so they point at `$new_path`, preserving each
 * link's alias, heading and embed marker. Used by the rename flow, which asks before touching
 * anything — this is the one place wikilink source text is modified.
 *
 * A target is rewritten when it matches the old page by relative path or by basename; the
 * replacement keeps the shape the author used (a path stays a path, a bare name stays a name).
 *
 * @return array{0: string, 1: int} the new text and the number of links changed.
 */
function wikilink_retarget(string $text, string $old_path, string $new_path): array {
    $old_full = wikilink_normalise($old_path);
    $old_base = wikilink_normalise(basename($old_path));
    $new_full = preg_replace('/\.[^.\/]+$/', '', str_replace('\\', '/', trim($new_path, '/')));
    $new_base = preg_replace('/\.[^.\/]+$/', '', basename($new_path));
    $count = 0;

    // Through wikilink_walk, so a link shown as an example inside a code fence or a code span
    // is left exactly as written — rewriting one would silently edit documentation.
    $out = wikilink_walk($text, function (array $m) use (
        $old_full, $old_base, $new_full, $new_base, &$count
    ) {
        $key = wikilink_normalise($m[2] ?? '');
        if ($key !== $old_full && $key !== $old_base) return $m[0];
        $count++;
        $replacement = ($key === $old_full && $old_full !== $old_base) ? $new_full : $new_base;
        return ($m[1] ?? '') . '[[' . $replacement
             . (isset($m[3]) && $m[3] !== '' ? '#' . $m[3] : '')
             . (isset($m[4]) && $m[4] !== '' ? '|' . $m[4] : '')
             . ']]';
    }, $text);

    return [$out, $count];
}
