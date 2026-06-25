<?php
// =================================================================
// PHP WIKI - SQLITE FTS5 SEARCH INDEX
// =================================================================

class SearchIndex {
    private PDO $pdo;

    public function __construct() {
        $db_path = WIKI_SYSTEM_DATA . 'search.sqlite';
        $this->pdo = new PDO('sqlite:' . $db_path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA journal_mode=WAL");
        $this->pdo->exec("PRAGMA synchronous=NORMAL");
        // Retry for up to 5 s when another writer holds the lock, instead of
        // immediately returning SQLITE_BUSY (which our catch blocks would silently discard).
        $this->pdo->exec("PRAGMA busy_timeout=5000");
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        // Content table: holds metadata + full text for FTS external content.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                space   TEXT    NOT NULL,
                path    TEXT    NOT NULL,
                title   TEXT    NOT NULL DEFAULT '',
                content TEXT    NOT NULL DEFAULT '',
                preview TEXT    NOT NULL DEFAULT '',
                updated INTEGER NOT NULL DEFAULT 0,
                UNIQUE(space, path)
            )
        ");
        // FTS5 virtual table backed by the pages content table.
        $this->pdo->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS pages_fts USING fts5(
                title,
                content,
                content=pages,
                content_rowid=id,
                tokenize='unicode61'
            )
        ");
    }

    // Extract title, indexable content and short preview from a file.
    private function extractInfo(string $ext, string $filename, string $raw): array {
        $title   = pathinfo($filename, PATHINFO_FILENAME);
        $content = '';
        $preview = '';

        if ($ext === 'md') {
            $content = $raw;
            if (preg_match('/^#\s+(.+)/m', $raw, $m)) {
                $title = trim($m[1]);
            }
            $chars = 0;
            $parts = [];
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^#+\s/', $line)) continue;
                $parts[] = $line;
                $chars += strlen($line);
                if ($chars >= 200) break;
            }
            $preview = implode(' ', $parts);
            if (strlen($preview) > 220) $preview = substr($preview, 0, 217) . '…';
        }

        return [$title, $content, $preview];
    }

    // Index or re-index a single page. Called after every write.
    public function upsertPage(string $space, string $path, string $raw = ''): void {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        [$title, $content, $preview] = $this->extractInfo($ext, basename($path), $raw);

        // BEGIN IMMEDIATE acquires the write lock before the SELECT, so two
        // concurrent upserts for the same path cannot both see "no row" and
        // then race to INSERT (which would make the second one fail silently).
        $this->pdo->exec("BEGIN IMMEDIATE");
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, title, content FROM pages WHERE space=? AND path=?"
            );
            $stmt->execute([$space, $path]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Remove old FTS shadow row, update pages, re-add FTS.
                $this->pdo->prepare(
                    "INSERT INTO pages_fts(pages_fts, rowid, title, content) VALUES('delete', ?, ?, ?)"
                )->execute([$existing['id'], $existing['title'], $existing['content']]);

                $this->pdo->prepare(
                    "UPDATE pages SET title=?, content=?, preview=?, updated=? WHERE id=?"
                )->execute([$title, $content, $preview, time(), $existing['id']]);

                $this->pdo->prepare(
                    "INSERT INTO pages_fts(rowid, title, content) VALUES(?, ?, ?)"
                )->execute([$existing['id'], $title, $content]);
            } else {
                $this->pdo->prepare(
                    "INSERT INTO pages(space, path, title, content, preview, updated) VALUES(?,?,?,?,?,?)"
                )->execute([$space, $path, $title, $content, $preview, time()]);
                $id = (int)$this->pdo->lastInsertId();

                $this->pdo->prepare(
                    "INSERT INTO pages_fts(rowid, title, content) VALUES(?, ?, ?)"
                )->execute([$id, $title, $content]);
            }
            $this->pdo->exec("COMMIT");
        } catch (\Throwable $e) {
            try { $this->pdo->exec("ROLLBACK"); } catch (\Throwable $_) {}
        }
    }

    // Remove a page from the index.
    public function deletePage(string $space, string $path): void {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, content FROM pages WHERE space=? AND path=?"
        );
        $stmt->execute([$space, $path]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) return;

        $this->pdo->exec("BEGIN IMMEDIATE");
        try {
            $this->pdo->prepare(
                "INSERT INTO pages_fts(pages_fts, rowid, title, content) VALUES('delete', ?, ?, ?)"
            )->execute([$existing['id'], $existing['title'], $existing['content']]);

            $this->pdo->prepare("DELETE FROM pages WHERE id=?")->execute([$existing['id']]);
            $this->pdo->exec("COMMIT");
        } catch (\Throwable $e) {
            try { $this->pdo->exec("ROLLBACK"); } catch (\Throwable $_) {}
        }
    }

    // Rename/move a page within the same space (content unchanged, FTS rowid unchanged).
    public function movePage(string $space, string $oldPath, string $newPath): void {
        try {
            $this->pdo->prepare(
                "UPDATE pages SET path=? WHERE space=? AND path=?"
            )->execute([$newPath, $space, $oldPath]);
        } catch (\Throwable $e) {}
    }

    // Move a page between spaces.
    public function movePageCrossSpace(
        string $oldSpace, string $oldPath,
        string $newSpace, string $newPath
    ): void {
        try {
            $this->pdo->prepare(
                "UPDATE pages SET space=?, path=? WHERE space=? AND path=?"
            )->execute([$newSpace, $newPath, $oldSpace, $oldPath]);
        } catch (\Throwable $e) {}
    }

    // Batch-update paths when a folder is renamed (same space).
    public function moveFolderPaths(string $space, string $oldPrefix, string $newPrefix): void {
        try {
            $like = addcslashes($oldPrefix, '%_\\') . '%';
            $stmt = $this->pdo->prepare(
                "SELECT id, path FROM pages WHERE space=? AND path LIKE ?"
            );
            $stmt->execute([$space, $like]);
            $upd = $this->pdo->prepare("UPDATE pages SET path=? WHERE id=?");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $newPath = $newPrefix . substr($row['path'], strlen($oldPrefix));
                $upd->execute([$newPath, $row['id']]);
            }
        } catch (\Throwable $e) {}
    }

    // Full-text search.
    //   $allowedSpaces = null  → no restriction (admin, single-space mode passes the one space).
    //   $allSpaces     = false → restrict to $allowedSpaces[0] (current space).
    //   $allSpaces     = true  → search across all $allowedSpaces (null = all spaces).
    public function search(string $query, ?array $allowedSpaces, bool $allSpaces): array {
        $ftsQuery = $this->prepareFtsQuery($query);
        if ($ftsQuery === '') return [];

        try {
            if (!$allSpaces && $allowedSpaces !== null) {
                $space = $allowedSpaces[0] ?? '';
                $sql = "SELECT p.id, p.space, p.path, p.title, p.preview,
                               snippet(pages_fts, 1, '<mark>', '</mark>', '…', 15) AS snippet
                        FROM pages_fts
                        JOIN pages p ON p.id = pages_fts.rowid
                        WHERE pages_fts MATCH ?
                          AND p.space = ?
                        ORDER BY rank LIMIT 500";
                $params = [$ftsQuery, $space];
            } elseif ($allSpaces && $allowedSpaces !== null) {
                $ph  = implode(',', array_fill(0, count($allowedSpaces), '?'));
                $sql = "SELECT p.id, p.space, p.path, p.title, p.preview,
                               snippet(pages_fts, 1, '<mark>', '</mark>', '…', 15) AS snippet
                        FROM pages_fts
                        JOIN pages p ON p.id = pages_fts.rowid
                        WHERE pages_fts MATCH ?
                          AND p.space IN ($ph)
                        ORDER BY rank LIMIT 500";
                $params = array_merge([$ftsQuery], $allowedSpaces);
            } else {
                // Admin, all spaces
                $sql = "SELECT p.id, p.space, p.path, p.title, p.preview,
                               snippet(pages_fts, 1, '<mark>', '</mark>', '…', 15) AS snippet
                        FROM pages_fts
                        JOIN pages p ON p.id = pages_fts.rowid
                        WHERE pages_fts MATCH ?
                        ORDER BY rank LIMIT 500";
                $params = [$ftsQuery];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // Rebuild the index for all spaces from disk.
    public function rebuildAll(): int {
        $this->pdo->exec("DELETE FROM pages");
        $count = 0;
        $base  = rtrim(PAGES_DIR, '/');
        foreach (scandir(PAGES_DIR) as $sp) {
            if ($sp === '.' || $sp === '..' || $sp[0] === '.') continue;
            $dir = $base . '/' . $sp;
            if (!is_dir($dir)) continue;
            $count += $this->bulkInsertSpace($sp, $dir);
        }
        // Rebuild FTS from the freshly populated pages table.
        $this->pdo->exec("INSERT INTO pages_fts(pages_fts) VALUES('rebuild')");
        return $count;
    }

    // Rebuild the index for a single space from disk.
    public function rebuildSpace(string $space): int {
        $this->pdo->prepare("DELETE FROM pages WHERE space=?")->execute([$space]);
        $dir = rtrim(PAGES_DIR, '/') . '/' . $space;
        if (!is_dir($dir)) return 0;
        $count = $this->bulkInsertSpace($space, $dir);
        // Rebuild the entire FTS from pages (affects all spaces but is idempotent).
        $this->pdo->exec("INSERT INTO pages_fts(pages_fts) VALUES('rebuild')");
        return $count;
    }

    // Bulk-insert pages for one space into the pages table (no per-row FTS sync).
    private function bulkInsertSpace(string $space, string $dir): int {
        $count = 0;
        $this->pdo->exec("BEGIN IMMEDIATE");
        try {
            $stmt = $this->pdo->prepare(
                "INSERT OR IGNORE INTO pages(space,path,title,content,preview,updated) VALUES(?,?,?,?,?,?)"
            );
            $count = $this->scanAndInsert($space, $dir, $dir, $stmt);
            $this->pdo->exec("COMMIT");
        } catch (\Throwable $e) {
            try { $this->pdo->exec("ROLLBACK"); } catch (\Throwable $_) {}
        }
        return $count;
    }

    private function scanAndInsert(string $space, string $dir, string $base, \PDOStatement $stmt): int {
        $count = 0;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                if (str_ends_with($item, '.uploads') || $item === 'templates') continue;
                $count += $this->scanAndInsert($space, $full, $base, $stmt);
                continue;
            }
            $ext = pathinfo($item, PATHINFO_EXTENSION);
            if (!in_array($ext, ['md', 'drawio', 'list', 'chat'], true)) continue;
            $rel = ltrim(str_replace($base . '/', '', $full), '/');
            $raw = ($ext === 'md') ? (file_get_contents($full) ?: '') : '';
            [$title, $content, $preview] = $this->extractInfo($ext, $item, $raw);
            $stmt->execute([$space, $rel, $title, $content, $preview, time()]);
            $count++;
        }
        return $count;
    }

    // Sanitize user input and produce an FTS5 MATCH expression.
    private function prepareFtsQuery(string $query): string {
        $query = trim($query);
        if ($query === '') return '';
        // Strip characters that could break FTS5 parsing.
        $query = preg_replace('/["\^\(\)\-]/', ' ', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if ($query === '') return '';
        // Add prefix wildcard to the last token for incremental matching.
        $words = explode(' ', $query);
        $last  = array_pop($words);
        if ($last !== '') $words[] = $last . '*';
        return implode(' ', array_filter($words));
    }
}
