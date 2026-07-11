<?php
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

class WikiGraph {
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
            if (is_array($data) && isset($data['links']) && is_array($data['links'])) return $data['links'];
        }
        return [];
    }

    private function saveCache(array $links) {
        file_put_contents($this->cacheFile, json_encode(['links' => $links], JSON_PRETTY_PRINT));
    }

    // Absolute path for a page, or null if it no longer exists on disk.
    private function abs(string $rel): ?string {
        $p = $this->spaceDir . '/' . ltrim($rel, '/');
        return (is_file($p)) ? $p : null;
    }

    // Pages under a Space's top-level templates/ folder are page templates, not
    // content — excluded from the graph (mirrors wiki_is_template_path()).
    private function isTemplate(string $path): bool {
        return str_starts_with(ltrim($path, '/'), 'templates/');
    }

    // ---------------------------------------------------------------
    // Refresh the outbound-link cache incrementally against index.json,
    // returning [pageId => [outTargetId, ...]] for currently-existing pages.
    // ---------------------------------------------------------------
    private function refreshLinks(array $pages): array {
        $cache   = $this->loadCache();
        $fresh   = [];
        $changed = false;

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

            // Changed / new — re-scan body for ?pageid=<n> references.
            $out = [];
            if (preg_match_all('/pageid=(\d+)/', file_get_contents($abs), $m)) {
                $out = array_values(array_unique($m[1]));
            }
            $fresh[$id] = ['mtime' => (int)$mtime, 'out' => $out];
            $changed    = true;
        }

        // Persist if anything moved (or entries were dropped).
        if ($changed || count($fresh) !== count($cache)) $this->saveCache($fresh);
        return array_map(fn($e) => $e['out'] ?? [], $fresh);
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
