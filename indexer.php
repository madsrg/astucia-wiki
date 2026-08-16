<?php
// =================================================================
// PHP WIKI - PAGE INDEXER
// Manages the mapping of persistent IDs to file paths and tags.
// =================================================================

class PageIndexer {
    // Content types that get a stable page id. Defined once because two paths walk the
    // tree — rebuildIndex() behind ?action=indexfiles, and index_sync.php's automatic
    // external-change detection — and if the two sets disagreed, one would index what
    // the other then deleted on its next pass.
    const CONTENT_EXTS = ['md', 'drawio', 'list', 'chat', 'search', 'json'];

    /**
     * One recursive pass over a space's content tree.
     *
     * Exclusions match SearchIndex::scanAndInsert() and the `list` action:
     *  - dot-entries (`.git`, `.gitignore`, `.filesfolder`)
     *  - `index.json` / `graph.json` at the space root — the indexer's own sidecar and
     *    the graph cache. Essential now that `.json` is indexed, or the index would
     *    contain an entry for itself.
     *  - `*.uploads` directories (per-page attachments, not pages)
     * `templates/` is deliberately NOT excluded: template pages have always had ids,
     * and the search layers filter them out separately via wiki_is_template_path().
     *
     * @return array [relative path => mtime] for every indexable file.
     */
    public static function scanContent($directory) {
        $root = rtrim($directory, '/');
        // Every immediate subdirectory of PAGES_DIR is a space with its own index.json
        // (see list_spaces), so a root-level scan stays flat — recursing would give the
        // same file an id in two different indexes.
        $isPagesRoot = defined('PAGES_DIR') && $root === rtrim(PAGES_DIR, '/');
        $found = [];

        $walk = function ($dir, $prefix, $descend) use (&$walk, &$found, $root) {
            $entries = @scandir($dir);
            if ($entries === false) return;
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..' || $e[0] === '.') continue;
                if ($dir === $root && ($e === 'index.json' || $e === 'graph.json')) continue;
                $abs = $dir . '/' . $e;
                $rel = $prefix === '' ? $e : $prefix . '/' . $e;
                if (is_dir($abs)) {
                    if ($descend && substr($e, -8) !== '.uploads') $walk($abs, $rel, true);
                    continue;
                }
                if (!in_array(strtolower(pathinfo($e, PATHINFO_EXTENSION)), self::CONTENT_EXTS, true)) continue;
                $found[$rel] = (int)@filemtime($abs);
            }
        };
        $walk($root, '', !$isPagesRoot);
        return $found;
    }

    private $indexFile;
    private $indexData;

    public function __construct($pagesDirectory) {
        $this->indexFile = $pagesDirectory . '/index.json';
        $this->loadIndex();
    }

    private function loadIndex() {
        if (file_exists($this->indexFile)) {
            $json = file_get_contents($this->indexFile);
            $this->indexData = json_decode($json, true) ?: [];
        } else {
            $this->indexData = [];
        }
    }

    private function saveIndex() {
        file_put_contents($this->indexFile, json_encode($this->indexData, JSON_PRETTY_PRINT));
    }

    private function generateUniqueId() {
        do {
            $id = mt_rand(100000, 999999);
        } while (isset($this->indexData[$id]));
        return $id;
    }

    public function addPage($path, $uid = null, $userName = null) {
        if ($this->getId($path) === null) {
            $id  = $this->generateUniqueId();
            $now = time();
            $entry = ['path' => $path, 'tags' => [], 'created' => $now, 'updated' => $now];
            if ($uid !== null) {
                $entry['createdBy'] = ['uid' => (int)$uid, 'name' => $userName];
                $entry['updatedBy'] = ['uid' => (int)$uid, 'name' => $userName];
            }
            $this->indexData[$id] = $entry;
            $this->saveIndex();
            return $id;
        }
        return null;
    }

    public function updateModified($path, $uid = null, $userName = null) {
        $id = $this->getId($path);
        if ($id !== null) {
            $this->indexData[$id]['updated'] = time();
            if ($uid !== null) {
                $this->indexData[$id]['updatedBy'] = ['uid' => (int)$uid, 'name' => $userName];
            }
            $this->saveIndex();
        }
    }

    /**
     * Apply a batch of filesystem drift in a single index write.
     *
     * addPage/removePage/updateModified each rewrite the whole index.json, so applying
     * a bulk external change (someone rsyncs in 500 pages) one call at a time is
     * quadratic. Used by index_sync.php; ids of existing pages are never reassigned,
     * so ?pageid= links survive.
     *
     * Timestamps come from the FILE, never from the clock. Stamping "now" would claim a
     * month-old page had just changed, which the daily digest reads and reports as a
     * change in the last 24 hours. It is also what makes the touched-check stable: with
     * 'updated' set to the file's mtime, `mtime > updated` is false on the next pass.
     *
     * @param string[] $addPaths    Paths on disk with no index entry.
     * @param string[] $removePaths Indexed paths no longer on disk.
     * @param string[] $touchPaths  Indexed paths whose file is newer than 'updated'.
     * @param array    $mtimes      [path => mtime] from the scan; falls back to now.
     * @return array{added:int,removed:int,touched:int}
     */
    public function applyReconcile(array $addPaths, array $removePaths, array $touchPaths, array $mtimes = []) {
        $now = time();
        $stamp = fn($path) => (int)($mtimes[$path] ?? 0) ?: $now;
        $added = 0;
        foreach ($addPaths as $path) {
            if ($this->getId($path) !== null) continue;
            $ts = $stamp($path);
            $this->indexData[$this->generateUniqueId()] =
                ['path' => $path, 'tags' => [], 'created' => $ts, 'updated' => $ts];
            $added++;
        }
        $removed = 0;
        foreach ($removePaths as $path) {
            $id = $this->getId($path);
            if ($id === null) continue;
            unset($this->indexData[$id]);
            $removed++;
        }
        $touched = 0;
        foreach ($touchPaths as $path) {
            $id = $this->getId($path);
            if ($id === null) continue;
            $this->indexData[$id]['updated'] = $stamp($path);
            $touched++;
        }
        if ($added || $removed || $touched) $this->saveIndex();
        return ['added' => $added, 'removed' => $removed, 'touched' => $touched];
    }

    public function removePage($path) {
        $id = $this->getId($path);
        if ($id !== null) {
            unset($this->indexData[$id]);
            $this->saveIndex();
        }
    }

    public function updatePath($oldPath, $newPath) {
        $id = $this->getId($oldPath);
        if ($id !== null) {
            $this->indexData[$id]['path'] = $newPath;
            $this->saveIndex();
        }
        // The 'else' block that called addPage() was removed.
        // This prevents creating a new page with a new ID and empty tags
        // if the old path wasn't found, thus preserving data integrity.
    }

    public function updateFolderPath($oldFolderPath, $newFolderPath) {
        foreach ($this->indexData as $id => $data) {
            // Check if the page path starts with the old folder path
            if (strpos($data['path'], $oldFolderPath . '/') === 0) {
                // Replace the old folder part with the new one
                $this->indexData[$id]['path'] = str_replace($oldFolderPath . '/', $newFolderPath . '/', $data['path']);
            }
        }
        $this->saveIndex();
    }

    public function updateTags($id, $tags) {
        if (isset($this->indexData[$id])) {
            // Ensure tags are unique and clean
            $cleanedTags = array_unique(array_filter(array_map('trim', $tags)));
            $this->indexData[$id]['tags'] = array_values($cleanedTags); // Re-index array
            $this->saveIndex();
            return true;
        }
        return false;
    }

    public function getPageData($id) {
        return isset($this->indexData[$id]) ? $this->indexData[$id] : null;
    }

    public function getPath($id) {
        return isset($this->indexData[$id]['path']) ? $this->indexData[$id]['path'] : null;
    }
    
    public function getTags($id) {
        return isset($this->indexData[$id]['tags']) ? $this->indexData[$id]['tags'] : [];
    }

    public function getId($path) {
        foreach ($this->indexData as $id => $data) {
            if (isset($data['path']) && $data['path'] === $path) {
                return $id;
            }
        }
        return null;
    }

    public function getAllPages() {
        return $this->indexData;
    }

    public function getGitCommit($path, $default = true) {
        $id = $this->getId($path);
        if ($id !== null && array_key_exists('git_commit', $this->indexData[$id])) {
            return (bool)$this->indexData[$id]['git_commit'];
        }
        return $default;
    }

    public function setGitCommit($path, $enabled) {
        $id = $this->getId($path);
        if ($id !== null) {
            $this->indexData[$id]['git_commit'] = (bool)$enabled;
            $this->saveIndex();
            return true;
        }
        return false;
    }

    public function rebuildIndex($directory) {
        // Shares scanContent() with index_sync.php so both paths agree on what counts as
        // content. Existing ids are never reassigned, so ?pageid= links survive a rebuild.
        $scan       = self::scanContent($directory);
        $foundPaths = array_keys($scan);

        $indexedPaths = [];
        foreach($this->indexData as $data) {
            if(isset($data['path'])) {
                $indexedPaths[] = $data['path'];
            }
        }

        $this->applyReconcile(
            array_values(array_diff($foundPaths, $indexedPaths)),
            array_values(array_diff($indexedPaths, $foundPaths)),
            [],
            $scan
        );
        return count($foundPaths);
    }
}