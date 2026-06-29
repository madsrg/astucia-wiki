<?php
// =================================================================
// PHP WIKI - PAGE INDEXER
// Manages the mapping of persistent IDs to file paths and tags.
// =================================================================

class PageIndexer {
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
        $directory = rtrim($directory, '/');
        $allFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $markdownFiles = new RegexIterator($allFiles, '/\.(md|drawio|list)$/');
        $foundPaths = [];

        foreach ($markdownFiles as $file) {
            $relativePath = str_replace($directory . '/', '', $file->getPathname());
            $foundPaths[] = $relativePath;
            if ($this->getId($relativePath) === null) {
                $this->addPage($relativePath);
            }
        }

        $indexedPaths = [];
        foreach($this->indexData as $data) {
            if(isset($data['path'])) {
                $indexedPaths[] = $data['path'];
            }
        }

        $deletedPaths = array_diff($indexedPaths, $foundPaths);
        foreach ($deletedPaths as $path) {
            $this->removePage($path);
        }
        
        $this->saveIndex();
        return count($foundPaths);
    }
}