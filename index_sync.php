<?php
// =================================================================
// EXTERNAL CHANGE DETECTION
// =================================================================
//
// Picks up content changes made outside the wiki — an editor writing straight into
// PAGES_DIR, an rsync, a git pull, a script dropping in Markdown — and reconciles
// index.json, the graph cache and the SQLite FTS index with what is actually on disk.
//
// This runs opportunistically from the api.php bootstrap rather than from cron or a
// filesystem watcher:
//
//   - PHP has no built-in watcher. inotify needs a PECL extension or a long-lived
//     daemon (this app has neither), doesn't recurse without a watch per directory,
//     and doesn't work at all when PAGES_DIR is on NFS/SMB.
//   - Hooking the bootstrap means every kind of client triggers it — browsers, AI
//     users and MCP calls over service tokens, API accounts — so an agent reconciles
//     *before* it reads, which is the staleness case a cron would have covered.
//   - No new crontab entry, so existing installs get this on upgrade with no setup.
//
// The file tree needs no separate signal: a reconcile that finds drift rewrites
// index.json, and the tree poll (modules/file_tree, via ?action=tree_mtime) already
// watches that file's mtime.

// What counts as content, which files are skipped, and the flat root-level rule all
// live in PageIndexer::scanContent() / PageIndexer::CONTENT_EXTS — shared with
// rebuildIndex() so an automatic reconcile can never add a type that a later manual
// "?action=indexfiles" would drop again.

// Extensions the SQLite FTS index covers, mirroring SearchIndex::scanAndInsert(). It
// is narrower than PageIndexer::CONTENT_EXTS: .search pages are saved queries, not
// prose. Only these carry full text; the rest are indexed by title alone.
const INDEX_SYNC_FTS_EXTS  = ['md', 'drawio', 'list', 'chat', 'json'];
const INDEX_SYNC_FTS_RAW   = ['md', 'json'];

// How long a scan result is trusted before the filesystem is examined again.
function index_sync_interval(): int {
    $s = defined('INDEX_SYNC_INTERVAL_SECONDS') ? (int)INDEX_SYNC_INTERVAL_SECONDS : 30;
    return $s > 0 ? $s : 30;
}

// Stamps live outside PAGES_DIR on purpose: a stamp written *inside* the watched tree
// would change a directory mtime and so retrigger itself forever.
function index_sync_stamp_path(string $space_dir): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    $dir = rtrim(WIKI_SYSTEM_DATA, '/') . '/index-sync/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $key = rtrim($space_dir, '/') === rtrim(PAGES_DIR, '/')
        ? '_root'
        : preg_replace('/[^a-zA-Z0-9_-]/', '-', basename(rtrim($space_dir, '/')));
    return $dir . $key . '.json';
}

// Cheap fingerprint of a scan — changes on add, remove, rename and external edit.
function index_sync_signature(array $scan): string {
    ksort($scan);
    $buf = '';
    foreach ($scan as $rel => $mtime) $buf .= $rel . ':' . $mtime . "\n";
    return count($scan) . '-' . md5($buf);
}

/**
 * Reconcile one space with the filesystem, if enough time has passed since the last
 * look. Cheap and safe to call on every request.
 *
 * Serialised with an exclusive lock: several clients polling in the same second must
 * not run overlapping reconciles, because PageIndexer::saveIndex() is an unlocked
 * whole-file write and interleaved read-modify-write would lose entries.
 *
 * @return array|null Summary of what changed, or null when nothing was done.
 */
function index_sync_maybe(string $space_dir, PageIndexer $indexer, $search_idx = null, bool $force = false): ?array {
    $stamp_file = index_sync_stamp_path($space_dir);
    if ($stamp_file === null) return null;               // no WIKI_SYSTEM_DATA — nowhere safe to keep state

    $stamp = is_file($stamp_file)
        ? (json_decode((string)@file_get_contents($stamp_file), true) ?: [])
        : [];
    $age = time() - (int)($stamp['checked_at'] ?? 0);
    if (!$force && $age < index_sync_interval()) return null;

    $lock = @fopen($stamp_file . '.lock', 'c');
    // Non-blocking: if another request is already reconciling this space, this one has
    // nothing useful to add — it would just wait to repeat the same work.
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return null;
    }

    try {
        $scan = PageIndexer::scanContent($space_dir);
        $sig  = index_sync_signature($scan);
        $save_stamp = function (array $extra = []) use ($stamp_file, $sig) {
            @file_put_contents($stamp_file, json_encode(
                array_merge(['checked_at' => time(), 'signature' => $sig], $extra), JSON_PRETTY_PRINT));
        };

        if (!$force && ($stamp['signature'] ?? null) === $sig) {
            $save_stamp();                              // unchanged — just push the clock forward
            return null;
        }

        // --- Diff the filesystem against the index ---
        $indexed = [];                                   // relative path => ['id' =>, 'updated' =>]
        foreach ($indexer->getAllPages() as $id => $data) {
            if (!empty($data['path'])) $indexed[$data['path']] = ['id' => $id, 'updated' => (int)($data['updated'] ?? 0)];
        }

        $added   = array_values(array_diff(array_keys($scan), array_keys($indexed)));
        $removed = array_values(array_diff(array_keys($indexed), array_keys($scan)));
        $touched = [];
        foreach ($scan as $rel => $mtime) {
            if (isset($indexed[$rel]) && $mtime > $indexed[$rel]['updated']) $touched[] = $rel;
        }

        if (!$added && !$removed && !$touched) {
            $save_stamp();
            return null;
        }

        $indexer->applyReconcile($added, $removed, $touched, $scan);

        // --- Keep the derived caches in step ---
        if ($added || $removed) {
            try { (new WikiGraph($space_dir, $indexer))->invalidateCache(); } catch (\Throwable $_e) {}
        }
        if ($search_idx) {
            $space_name = basename(rtrim($space_dir, '/'));
            foreach (array_merge($added, $touched) as $rel) {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if (!in_array($ext, INDEX_SYNC_FTS_EXTS, true)) continue;
                // templates/ pages are excluded from every search surface (see
                // wiki_is_template_path); SearchIndex's own scanner skips that folder.
                if (str_starts_with($rel, 'templates/')) continue;
                $raw = in_array($ext, INDEX_SYNC_FTS_RAW, true)
                    ? (string)@file_get_contents(rtrim($space_dir, '/') . '/' . $rel) : '';
                try { $search_idx->upsertPage($space_name, $rel, $raw); } catch (\Throwable $_e) {}
            }
            foreach ($removed as $rel) {
                try { $search_idx->deletePage($space_name, $rel); } catch (\Throwable $_e) {}
            }
        }

        // Stamped with the signature of the scan we just reconciled against, so the next
        // pass compares like with like. Nothing written above can perturb it: the index
        // and graph sidecars are excluded from scanContent(), and the signature covers
        // file paths and mtimes only, not the directory mtimes those writes bump.
        $save_stamp();

        return ['added' => count($added), 'removed' => count($removed), 'touched' => count($touched)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
