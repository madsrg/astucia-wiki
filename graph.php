<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// ASTUCIA WIKI — KNOWLEDGE GRAPH BUILDER
// Builds a node/edge graph of a space from three relationship types:
//   - reference   (directed): explicit  ?pageid=<id>  links in page bodies
//   - containment (undirected): folder hierarchy derived from file paths
//   - affinity    (undirected): pages sharing one or more tags
//
// Reference edges are the only ones that need to read file contents, so an
// outbound-link map is cached in  <space>/graph.json  keyed by page id, with a
// per-file mtime. On each build only files whose mtime changed are re-scanned;
// everything else (nodes, containment, tags) is derived from index.json for
// free. This makes the graph cheap to serve and self-maintaining — no need to
// hook every save/move/delete site.
// =================================================================

require_once __DIR__ . '/wikilinks.php';
require_once __DIR__ . '/frontmatter.php';

class WikiGraph {
    // Bumped whenever the body scanner learns a new link form, so existing caches are
    // discarded once instead of keeping their old edges until each file happens to change.
    // v2 added `[[wikilink]]` and `![[embed]]` references. v3 keeps wikilink targets
    // unresolved, separates links into other Spaces, adds `{include:N}`, skips code, and
    // records what content health needs (body length, owner / review_by / reviewed).
    private const SCAN_VERSION = 3;

    private $spaceDir;
    private $indexer;
    private $cacheFile;

    public function __construct($spaceDir, PageIndexer $indexer) {
        $this->spaceDir  = rtrim($spaceDir, '/');
        $this->indexer   = $indexer;
        $this->cacheFile = $this->spaceDir . '/graph.json';
    }

    // Drop the cached link map — call after a full reindex for a clean rebuild.
    public function invalidateCache() {
        if (file_exists($this->cacheFile)) @unlink($this->cacheFile);
    }

    private function loadCache(): array {
        if (file_exists($this->cacheFile)) {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            // A cache written by an older scanner is dropped wholesale: its entries look valid
            // (the mtimes still match) but were built without the link forms added since.
            if (is_array($data) && isset($data['links']) && is_array($data['links'])
                && (int)($data['v'] ?? 1) === self::SCAN_VERSION) return $data['links'];
        }
        return [];
    }

    private function saveCache(array $links) {
        file_put_contents($this->cacheFile,
            json_encode(['v' => self::SCAN_VERSION, 'links' => $links], JSON_PRETTY_PRINT));
    }

    // Absolute path for a page, or null if it no longer exists on disk.
    private function abs(string $rel): ?string {
        $p = $this->spaceDir . '/' . ltrim($rel, '/');
        return (is_file($p)) ? $p : null;
    }

    // Pages under a Space's top-level templates/ folder are page templates, not
    // content — excluded from the graph (mirrors wiki_is_template_path()). A memory/
    // page is excluded for the same reason and one more: a few hundred one-line facts
    // would swamp the graph they are not really part of. A skills/ page is instructions to
    // an AI, not content.
    private function isTemplate(string $path): bool {
        $rel = ltrim($path, '/');
        return str_starts_with($rel, 'templates/') || str_starts_with($rel, 'memory/')
            || str_starts_with(strtolower($rel), 'skills/');
    }

    /** This Space's name as a `?space=` link would carry it; '' for PAGES_DIR itself. */
    public function spaceName(): string {
        $root = realpath(PAGES_DIR);
        $me   = realpath($this->spaceDir);
        if ($root === false || $me === false || rtrim($me, '/') === rtrim($root, '/')) return '';
        return basename($me);
    }

    /**
     * What one file says about its links, as cached in graph.json.
     *
     *  - `pid`   ids this page links to in its own Space: `?pageid=N` with no `space=` (or
     *            this Space's own name), and `{include:N}` transclusions
     *  - `xs`    `[space, id]` pairs for `?pageid=N&space=Other` — a link into another Space,
     *            which is not an edge in this graph and is checked by content health alone
     *  - `wl`    raw `[[wikilink]]` / `![[embed]]` targets, resolved at build time
     *  - `chars` Markdown only: visible length of the body (headings' `#` and whitespace
     *            excluded), for the empty-page check
     *  - `fm`    Markdown only: the `owner`, `review_by` and `reviewed` front matter keys
     *
     * Code blocks and spans are skipped for every form, as wikilink_walk() always did for
     * `[[…]]`: a page documenting the link syntax is not linking to anything.
     */
    private function scanFile(string $path, string $raw, string $self): array {
        $is_md = substr($path, -3) === '.md';
        $meta  = [];
        if ($is_md) {
            // Without stripping, an Obsidian `related: "[[Some Page]]"` in front matter
            // becomes a real graph edge and a backlink on the target.
            $split = wiki_fm_split($raw);
            $body  = $split['body'];
            $meta  = $split['meta'];
        } else {
            $body = $raw;
        }
        $text = wikilink_strip_code($body);

        $pid = [];
        $xs  = [];
        // A link's query string: everything around `pageid=` up to a delimiter that cannot
        // be part of a URL in Markdown or HTML. The Space may come before or after the id.
        if (preg_match_all('/[^\s()\[\]<>"\'`]*pageid=\d+[^\s()\[\]<>"\'`]*/', $text, $m)) {
            foreach ($m[0] as $url) {
                $q = str_replace('&amp;', '&', $url);
                $q = ($p = strpos($q, '?')) !== false ? substr($q, $p + 1) : $q;
                $q = strtok($q, '#');
                parse_str((string)$q, $params);
                $id = (string)($params['pageid'] ?? '');
                if (!ctype_digit($id)) {
                    // A bare "pageid=12" in prose, with no parsable query around it.
                    if (preg_match('/pageid=(\d+)/', $url, $mm)) $id = $mm[1]; else continue;
                }
                $sp = isset($params['space']) && is_string($params['space']) ? $params['space'] : null;
                if ($sp === null || $sp === $self) $pid[$id] = true;
                else $xs[$sp . "\0" . $id] = [$sp, $id];
            }
        }
        if (preg_match_all('/\{include:(\d+)/', $text, $m)) {
            foreach ($m[1] as $id) $pid[$id] = true;
        }

        $entry = [
            'pid' => array_map('strval', array_keys($pid)),
            'xs'  => array_values($xs),
            'wl'  => wikilink_targets($body),
        ];
        if ($is_md) {
            $visible = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]*/m', '', $body);
            $entry['chars'] = mb_strlen(preg_replace('/\s+/u', '', (string)$visible));
            $fm = [];
            foreach (['owner', 'review_by', 'reviewed'] as $k) {
                if (isset($meta[$k]) && is_string($meta[$k]) && trim($meta[$k]) !== '') $fm[$k] = trim($meta[$k]);
            }
            if ($fm) $entry['fm'] = $fm;
        }
        return $entry;
    }

    /**
     * Refresh the per-file cache incrementally against index.json and return it:
     * [pageId => scanFile() entry + 'mtime'] for every page that exists on disk and is not a
     * template, memory or skills page. Only files whose mtime changed are read.
     */
    public function scan(?array $pages = null): array {
        $pages   = $pages ?? $this->indexer->getAllPages();
        $cache   = $this->loadCache();
        $fresh   = [];
        $changed = false;
        $self    = $this->spaceName();

        foreach ($pages as $id => $data) {
            $id = (string)$id;
            if (empty($data['path'])) continue;
            if ($this->isTemplate($data['path'])) continue;   // skip page templates
            $abs = $this->abs($data['path']);
            if ($abs === null) { $changed = true; continue; } // gone → drop from cache

            $mtime = filemtime($abs);
            if (isset($cache[$id]) && (int)($cache[$id]['mtime'] ?? -1) === (int)$mtime) {
                $fresh[$id] = $cache[$id];               // unchanged — reuse
                continue;
            }
            $fresh[$id] = ['mtime' => (int)$mtime]
                        + $this->scanFile($data['path'], (string)file_get_contents($abs), $self);
            $changed    = true;
        }

        // Persist if anything moved (or entries were dropped).
        if ($changed || count($fresh) !== count($cache)) $this->saveCache($fresh);
        return $fresh;
    }

    // ---------------------------------------------------------------
    // Outbound reference targets per page: [pageId => [outTargetId, ...]].
    // Wikilinks are resolved here rather than at scan time — a target is a *name*, and the
    // page it names may have been created, renamed or deleted since the linking file was
    // last read. Resolving once at scan time is how a link to a page created later used to
    // stay missing from the graph until the linking page itself was edited.
    // ---------------------------------------------------------------
    private function refreshLinks(array $pages): array {
        // Built once for the whole space rather than per file: a wikilink names its target, so
        // resolving one needs the index of every page.
        $resolve = wikilink_resolver($pages);
        $out = [];
        foreach ($this->scan($pages) as $id => $e) {
            $ids = $e['pid'] ?? [];
            foreach (($e['wl'] ?? []) as $target) {
                $rid = $resolve($target);
                if ($rid !== null) $ids[] = (string)$rid;
            }
            $out[$id] = array_values(array_unique(array_map('strval', $ids)));
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Build the full graph. Returns ['nodes' => [...], 'edges' => [...]].
    // ---------------------------------------------------------------
    public function build(): array {
        $pages = $this->indexer->getAllPages();
        $links = $this->refreshLinks($pages);

        $nodes = [];              // nodeId => node
        $edges = [];              // edgeKey => edge  (dedup)
        $folderRep = [];          // folderPath => representative nodeId

        // Existing page ids (only link to real nodes).
        $pageExists = [];
        foreach ($pages as $id => $data) {
            if (empty($data['path']) || $this->isTemplate($data['path'])) continue;
            if ($this->abs($data['path']) !== null) $pageExists[(string)$id] = $data;
        }

        // Pass 1 — figure out which folders are represented by a real page
        // (a sibling  Foo.md  next to  Foo/, or an index.md/README.md inside).
        foreach ($pageExists as $id => $data) {
            $path = $data['path'];
            $noExt = preg_replace('/\.[^.\/]+$/', '', $path);     // Handbook/Onboarding
            $folderRep[$noExt] = (string)$id;                     // sibling Foo.md → folder Foo
            $base = strtolower(basename($path));
            if ($base === 'index.md' || $base === 'readme.md') {
                $folderRep[dirname($path)] = (string)$id;         // Foo/index.md → folder Foo
            }
        }

        // folderNode(): return the node id representing a folder path, creating
        // a synthetic folder node on demand when no real page stands in for it.
        $ensureFolder = function(string $dir) use (&$nodes, &$folderRep): ?string {
            if ($dir === '' || $dir === '.' || $dir === '/') return null;
            if (isset($folderRep[$dir])) return $folderRep[$dir];
            $nid = 'dir:' . $dir;
            if (!isset($nodes[$nid])) {
                $nodes[$nid] = [
                    'id'     => $nid,
                    'type'   => 'folder',
                    'label'  => basename($dir),
                    'path'   => $dir,
                    'folder' => explode('/', $dir)[0],
                    'tags'   => [],
                    'degree' => 0,
                ];
            }
            $folderRep[$dir] = $nid;
            return $nid;
        };

        $addEdge = function(string $a, string $b, string $type, bool $directed) use (&$edges) {
            if ($a === $b) return;
            $key = $directed ? "$type|$a>$b" : ($type . '|' . implode('|', [min($a, $b), max($a, $b)]));
            if (isset($edges[$key])) return;
            $edges[$key] = ['source' => $a, 'target' => $b, 'type' => $type, 'directed' => $directed];
        };

        // Pass 2 — page nodes.
        foreach ($pageExists as $id => $data) {
            $path = $data['path'];
            $ext  = pathinfo($path, PATHINFO_EXTENSION);
            $nodes[(string)$id] = [
                'id'     => (string)$id,
                'type'   => 'page',
                'label'  => basename($path, '.' . $ext),
                'path'   => $path,
                'ext'    => $ext,
                'folder' => explode('/', $path)[0],
                'tags'   => $data['tags'] ?? [],
                'degree' => 0,
            ];
        }

        // Pass 3 — reference edges (from cached link map).
        foreach ($links as $id => $targets) {
            if (!isset($pageExists[$id])) continue;
            foreach ($targets as $t) {
                $t = (string)$t;
                if (isset($pageExists[$t])) $addEdge((string)$id, $t, 'reference', true);
            }
        }

        // Pass 4 — containment edges (pages → parent folder, folders → parent).
        foreach ($pageExists as $id => $data) {
            $nid    = (string)$id;
            $parent = $this->parentFolderNode($data['path'], $nid, $ensureFolder);
            if ($parent !== null) $addEdge($nid, $parent, 'containment', false);
        }
        // Chain synthetic folders up to their parents. Snapshot first: creating
        // parent nodes mutates $nodes mid-loop.
        $folderNodes = array_filter($nodes, fn($n) => $n['type'] === 'folder');
        foreach ($folderNodes as $fn) {
            $parentDir = dirname($fn['path']);
            $parent    = $ensureFolder($parentDir);
            if ($parent !== null && $parent !== $fn['id']) $addEdge($fn['id'], $parent, 'containment', false);
        }

        // Pass 5 — affinity edges (shared tags). Bounded: small tags fully
        // connect (pairwise), large tags chain (O(k)) to avoid a hairball.
        $byTag = [];
        foreach ($pageExists as $id => $data) {
            foreach (($data['tags'] ?? []) as $tag) {
                $tag = strtolower(trim($tag));
                if ($tag !== '') $byTag[$tag][] = (string)$id;
            }
        }
        foreach ($byTag as $ids) {
            $ids = array_values(array_unique($ids));
            $k   = count($ids);
            if ($k < 2) continue;
            if ($k <= 6) {
                for ($i = 0; $i < $k; $i++)
                    for ($j = $i + 1; $j < $k; $j++)
                        $addEdge($ids[$i], $ids[$j], 'tag', false);
            } else {
                sort($ids);
                for ($i = 0; $i < $k - 1; $i++) $addEdge($ids[$i], $ids[$i + 1], 'tag', false);
            }
        }

        // Degrees.
        foreach ($edges as $e) {
            if (isset($nodes[$e['source']])) $nodes[$e['source']]['degree']++;
            if (isset($nodes[$e['target']])) $nodes[$e['target']]['degree']++;
        }

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    // Containment parent for a page: its immediate folder, or the grandparent
    // when the page itself represents that folder (e.g. an index.md).
    private function parentFolderNode(string $path, string $selfId, callable $ensureFolder): ?string {
        $dir    = dirname($path);
        $parent = $ensureFolder($dir);
        if ($parent === $selfId) {                 // page IS its folder → go up one
            $parent = $ensureFolder(dirname($dir));
        }
        return $parent;
    }

    // ---------------------------------------------------------------
    // Neighbourhood traversal for focus-mode + "related pages" (MCP).
    // Returns page nodes within $hops of $rootId, each with distance and the
    // edge type(s) linking it, nearest first.
    // ---------------------------------------------------------------
    public function related(string $rootId, int $hops = 1): array {
        $g = $this->build();
        $adj = [];
        $viaOf = [];
        foreach ($g['edges'] as $e) {
            $adj[$e['source']][] = $e['target'];
            $adj[$e['target']][] = $e['source'];
            $viaOf[$e['source'] . '|' . $e['target']] = $e['type'];
            $viaOf[$e['target'] . '|' . $e['source']] = $e['type'];
        }
        $nodeById = [];
        foreach ($g['nodes'] as $n) $nodeById[$n['id']] = $n;
        if (!isset($nodeById[$rootId])) return [];

        // BFS.
        $dist = [$rootId => 0];
        $via  = [$rootId => null];
        $queue = [$rootId];
        while ($queue) {
            $cur = array_shift($queue);
            if ($dist[$cur] >= $hops) continue;
            foreach (($adj[$cur] ?? []) as $nb) {
                if (isset($dist[$nb])) continue;
                $dist[$nb] = $dist[$cur] + 1;
                $via[$nb]  = $viaOf[$cur . '|' . $nb] ?? null;
                $queue[]   = $nb;
            }
        }

        $out = [];
        foreach ($dist as $nid => $d) {
            if ($nid === $rootId || $d === 0) continue;
            $n = $nodeById[$nid] ?? null;
            if (!$n || $n['type'] !== 'page') continue;   // report real pages only
            $out[] = [
                'id'       => $n['id'],
                'path'     => $n['path'],
                'label'    => $n['label'],
                'tags'     => $n['tags'],
                'distance' => $d,
                'via'      => $via[$nid],
            ];
        }
        usort($out, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return $out;
    }
}
