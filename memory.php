<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// AI MEMORY — what an AI User has learned, as ordinary wiki pages
//
// A memory is a page. That is the whole design, and it is the reason this is worth
// building rather than bolting on a vector store: the facts an AI has accumulated are
// readable in the tree, searchable, diffable in git, correctable by hand and deletable by
// anyone who disagrees with them. Nothing is hidden.
//
// Four decisions, each of which the obvious alternative gets wrong:
//
//   1. **Per space, in `memory/` inside the space itself.** A single shared memory space
//      would be a deliberate channel between Spaces — an AI in Alpha writes what it
//      learned, an AI in Bravo reads it — which is the isolation failure of v2026.9.3
//      re-created as a feature. Living inside the space means every existing guard
//      already applies and there is no new ACL surface.
//   2. **One page per fact, in a flat folder.** A single big memory page works perfectly
//      until it is too large to inject, and then there is no graceful path: a single file
//      has no addressable units, so selection becomes truncation, and its FTS row is the
//      whole page — a hit tells you the page matched, not which line. Atomic pages
//      degrade instead: read the index, read the two that matter.
//   3. **No subfolders, and the model never categorises.** A wrong tag is recoverable —
//      the fact is still in the index and still in the search. A wrong *folder* is a fact
//      recall never visits again. Taxonomies invented on the fly also drift ("Decisions"
//      one week, "Choices" the next), invisibly. Grouping is tags, which this wiki
//      already treats as first class.
//   4. **The index is generated, never stored.** `wiki_memory_list()` builds it from
//      index.json at prompt-assembly time, so there is no second write per capture, no
//      index page to desync from the folder, and a memory folder that arrived by rsync is
//      complete the moment index_sync reconciles it. The pages are the truth; the listing
//      is a view. Same relationship index.json itself has to the filesystem.
//
// The store is only reachable when the *space* keeps memories (`wiki_space_memory()`) and
// the *AI User* has learning on (`wiki_ai_memory_enabled()` in ai_core.php).
// =================================================================

require_once __DIR__ . '/frontmatter.php';
require_once __DIR__ . '/space_settings.php';
// Required outright, never behind a function_exists() guard: that is exactly how
// frontmatter_expose_ai came to answer "off" in mcp.php and the cron runner while the
// same setting read "on" in a web request. The wiki-wide learning default has the
// same three readers.
require_once __DIR__ . '/settings.php';

/** Fixed, not configurable: every filter predicate below would have to know the setting. */
const WIKI_MEMORY_DIR = 'memory';

/** How many memories the injected index lists before it stops and says so. */
const WIKI_MEMORY_INDEX_CAP = 200;

/** How much of a remembered fact a single page may hold. A memory is a sentence, not an essay. */
const WIKI_MEMORY_MAX_BYTES = 4096;

/**
 * Does this AI User learn?
 *
 * Tri-state on purpose, the same shape as a chat thread's retention policy: an empty
 * value means *follow the wiki default*, and an explicit `off` means off **even when the
 * default is on**. Without that distinction, switching the house default on would quietly
 * start writing memories for an AI somebody had deliberately exempted — and a memory,
 * unlike a trimmed thread, is then injected into every later run.
 */
function wiki_ai_memory_enabled(array $ai_config): bool {
    $own = strtolower(trim((string)($ai_config['memory'] ?? '')));
    if ($own === 'on')  return true;
    if ($own === 'off') return false;
    return (bool)wiki_setting('ai_memory_default', false);
}

/**
 * Is this space-relative path a memory page?
 *
 * Memory pages are ordinary indexed pages — that is the feature — which also means that
 * without this predicate the first ten of them make search worse for everybody. It is
 * applied wherever `wiki_is_template_path()` is, plus the mention scanner: an `@Alice`
 * inside a remembered fact would otherwise notify Alice every time the file is touched.
 */
function wiki_is_memory_path(string $rel_path): bool {
    $rel = ltrim(str_replace('\\', '/', $rel_path), '/');
    return strncmp($rel, WIKI_MEMORY_DIR . '/', strlen(WIKI_MEMORY_DIR) + 1) === 0;
}

/** Absolute path of a space's memory folder. */
function wiki_memory_dir(string $space_dir): string {
    return rtrim($space_dir, '/') . '/' . WIKI_MEMORY_DIR;
}

/**
 * A filename for a fact.
 *
 * The title *is* the index entry — the injected listing carries filenames and tags and
 * nothing else, so that it costs one index.json read rather than N file reads per call.
 * That makes a descriptive title load-bearing rather than cosmetic, which is why the
 * tool's description asks for a sentence.
 */
function wiki_memory_slug(string $title): string {
    $slug = trim(preg_replace('/\s+/u', ' ', strip_tags($title)));
    // Everything a path cannot carry, plus the leading dot that would hide the page.
    $slug = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|', "\0"], '-', $slug);
    $slug = ltrim($slug, '.');
    if (function_exists('mb_substr')) $slug = mb_substr($slug, 0, 80);
    else                              $slug = substr($slug, 0, 80);
    return trim($slug) !== '' ? trim($slug) : 'Untitled memory';
}

/**
 * Every memory in a space, newest first, straight out of the index.
 *
 * @return array<int,array{path:string,title:string,tags:array,updated:int}>
 */
function wiki_memory_list($indexer): array {
    $out = [];
    foreach ($indexer->getAllPages() as $id => $data) {
        $rel = (string)($data['path'] ?? '');
        if ($rel === '' || !wiki_is_memory_path($rel)) continue;
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'md') continue;
        $out[] = [
            'id'      => (string)$id,
            'path'    => $rel,
            'title'   => preg_replace('/\.md$/i', '', basename($rel)),
            'tags'    => array_values((array)($data['tags'] ?? [])),
            'updated' => (int)($data['updated'] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => $b['updated'] <=> $a['updated']);
    return $out;
}

/**
 * The block injected into the system prompt.
 *
 * **This is what makes recall happen at all.** Given only a `wiki_recall` tool, a model
 * has to *decide* that searching might help, and frequently does not — it answers from
 * the conversation and never looks. A listing of what it knows costs one line per memory
 * and turns recall from a guess into a lookup.
 *
 * Capped, because it is paid on every single call: past the cap the newest are listed and
 * the rest are reachable through `wiki_recall`, which the text says out loud so the model
 * knows the listing is partial rather than complete.
 */
function wiki_memory_index_prompt($indexer): string {
    $items = wiki_memory_list($indexer);
    if (!$items) {
        return "MEMORY: you have not remembered anything in this space yet.\n"
             . "Use wiki_remember when the user tells you something worth keeping.\n\n";
    }
    $total = count($items);
    $shown = array_slice($items, 0, WIKI_MEMORY_INDEX_CAP);
    $lines = '';
    foreach ($shown as $m) {
        $tags = $m['tags'] ? ' [' . implode(', ', $m['tags']) . ']' : '';
        $lines .= '- ' . $m['title'] . $tags . "\n";
    }
    $head = "MEMORY — what you have already learned in this space ({$total} item"
          . ($total === 1 ? '' : 's') . "):\n";
    $tail = $total > count($shown)
        ? "\n…and " . ($total - count($shown)) . " older items not listed. Use wiki_recall to search them.\n"
        : '';
    return $head . $lines . $tail
         . "Read one with wiki_recall before relying on it — these are titles, not the full text.\n\n";
}

/**
 * Write a fact into the space's memory.
 *
 * The folder is created here, on the first write, rather than when the setting is turned
 * on: switching memory on and straight off again should not leave an empty folder in
 * somebody's tree.
 *
 * Front matter is written by hand rather than through automatic stamping, because that is
 * a separate per-space setting a memory must not depend on — a memory always records when
 * it was formed and who formed it, whatever the space does about stamping.
 *
 * The thread it was learned in is deliberately *not* recorded. The only way to get it here
 * would be a global set by the caller, and the cron runner handles many jobs in one
 * process — the same staleness that had `git_auto_commit()` committing against whichever
 * space ran last. Git records the commit, which is the traceability that matters.
 *
 * @return array{ok:bool,path:string,created:bool,error:string}
 */
function wiki_memory_write(string $space_dir, $indexer, string $title, string $fact,
                           array $tags = [], array $actor = []): array {
    $fail = fn(string $m) => ['ok' => false, 'path' => '', 'created' => false, 'error' => $m];

    $fact = trim($fact);
    if ($fact === '') return $fail('a memory needs something to remember');
    if (strlen($fact) > WIKI_MEMORY_MAX_BYTES) {
        return $fail('that is too long for one memory — keep it to a fact or two');
    }
    $slug = wiki_memory_slug($title !== '' ? $title : $fact);
    $dir  = wiki_memory_dir($space_dir);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return $fail('could not create the memory folder');

    $rel     = WIKI_MEMORY_DIR . '/' . $slug . '.md';
    $abs     = rtrim($space_dir, '/') . '/' . $rel;
    $created = !is_file($abs);

    $now  = date('c');
    $who  = trim((string)($actor['name'] ?? '')) ?: 'AI';
    $keep = [];
    if (!$created) {
        // Keep the original `created` pair: the page is being corrected, not formed again.
        $existing = wiki_fm_split((string)@file_get_contents($abs))['meta'];
        foreach (['created', 'createdBy'] as $k) {
            if (!empty($existing[$k])) $keep[$k] = (string)$existing[$k];
        }
    }
    $fm  = "---\n";
    $fm .= 'created: ' . ($keep['created'] ?? $now) . "\n";
    $fm .= 'createdBy: ' . ($keep['createdBy'] ?? $who) . "\n";
    $fm .= 'updated: ' . $now . "\n";
    $fm .= 'updatedBy: ' . $who . "\n";
    $fm .= "---\n";

    if (@file_put_contents($abs, $fm . $fact . "\n") === false) return $fail('could not write the memory');

    if ($created) $indexer->addPage($rel, $actor['uid'] ?? null, $who);
    else          $indexer->updateModified($rel, $actor['uid'] ?? null, $who);

    if ($tags) {
        $id = $indexer->getId($rel);
        if ($id !== null) {
            $clean = array_values(array_unique(array_filter(array_map('trim', $tags))));
            if ($clean) $indexer->updateTags($id, $clean);
        }
    }
    return ['ok' => true, 'path' => $rel, 'created' => $created, 'error' => ''];
}

/**
 * Search a space's memories, or list them all when the query is empty.
 *
 * Deliberately its own scan rather than `wiki_search_pages()`: that one filters memory
 * pages *out*, which is what keeps them from polluting ordinary search. The bodies are
 * small and there are at most a few hundred, so a direct read is both simpler and more
 * predictable than arranging for FTS to make an exception.
 *
 * @return array<int,array{path:string,title:string,tags:array,text:string}>
 */
function wiki_memory_search(string $space_dir, $indexer, string $query = '', int $limit = 10): array {
    $needle = trim(mb_strtolower($query));
    $out    = [];
    foreach (wiki_memory_list($indexer) as $m) {
        $abs  = rtrim($space_dir, '/') . '/' . $m['path'];
        if (!is_file($abs)) continue;   // index drift; the reconcile will catch up
        $body = trim(wiki_fm_body((string)@file_get_contents($abs)));
        if ($needle !== '') {
            $hay = mb_strtolower($m['title'] . ' ' . implode(' ', $m['tags']) . ' ' . $body);
            if (mb_strpos($hay, $needle) === false) continue;
        }
        $out[] = ['path' => $m['path'], 'title' => $m['title'], 'tags' => $m['tags'], 'text' => $body];
        if (count($out) >= max(1, $limit)) break;
    }
    return $out;
}

/**
 * Forget one memory.
 *
 * Present because superseded facts are the quality problem with a memory, not missing
 * ones: an AI that can only ever add ends up with two contradictory pages and no way to
 * resolve them. Deleting is deleting a page — the index entry goes, the file goes, and
 * git remembers it happened.
 */
function wiki_memory_forget(string $space_dir, $indexer, string $title): array {
    $slug = wiki_memory_slug($title);
    $rel  = WIKI_MEMORY_DIR . '/' . $slug . '.md';
    $abs  = rtrim($space_dir, '/') . '/' . $rel;
    if (!is_file($abs)) return ['ok' => false, 'path' => $rel, 'error' => 'no memory with that title'];
    if (!@unlink($abs))  return ['ok' => false, 'path' => $rel, 'error' => 'could not delete the memory'];
    $indexer->removePage($rel);
    return ['ok' => true, 'path' => $rel, 'error' => ''];
}
