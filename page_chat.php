<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PAGE CHAT — the `.chat` thread that belongs to a `.md` page
//
// A page chat is not a thread that happens to sit next to a page: it *is* that page's
// thread, addressed by convention rather than by a field. `Notes/Plan.md` has
// `Notes/Plan.chat`, and both the client (modules/page_chat) and the server derive one
// from the other by swapping the extension. Nothing records the pairing, so the
// convention has to hold — which is exactly why a rename has to move both. Before this,
// renaming a page silently orphaned its conversation: the thread stayed under the old
// name, the renamed page opened an empty chat, and the only way back was to know the
// filename the wiki used to use.
//
// The sidecar is not the same kind of thing as `page.md.uploads/` or the cached
// `page.drawio.svg`, which a rename can move with a bare `rename()`. A `.chat` is an
// indexed page in its own right, so moving it means everything moving a page means:
//
//   - **`updatePath`, never remove-and-add.** The thread has its own id, and `?pageid=`
//     links, `{include:ID}` tags and a `/jobs` entry's `chat_id` all point at it.
//   - Its **FTS row** moves with it (`.chat` is indexed, by path — the index holds no
//     full text for a thread).
//   - A **queued one-off job** holds `reply_to.chat` as a path. The cron runner writes
//     its answer into the placeholder message in that file, so a stale path means the
//     answer is written into a thread nobody is looking at, or nowhere at all.
//   - The transient `<chat>.ai-status.<id>` files follow, or a run in flight loses the
//     status line its placeholder is displaying.
// =================================================================

/**
 * The `.chat` counterpart of a Markdown page — absolute or relative, whichever you pass.
 *
 * Returns null for anything that is not a `.md` page, which is what makes this safe to
 * call unconditionally from a move that might be carrying a diagram, a list or a folder.
 */
function wiki_page_chat_sidecar(string $page_path): ?string {
    if (!preg_match('/\.md$/i', $page_path)) return null;
    return preg_replace('/\.md$/i', '.chat', $page_path);
}

/**
 * Would moving this page collide with an existing thread at the destination?
 *
 * Checked *before* the page is moved, by both rename entry points, so the whole operation
 * is refused rather than half-applied. The alternative — move the page and leave the
 * thread — produces exactly the orphan this module exists to prevent, and the space merge's
 * `name (1).ext` convention is wrong here: that is for a bulk operation which cannot stop
 * to ask, while a single rename can simply say no.
 */
function wiki_page_chat_blocks_move(string $old_abs, string $new_abs): bool {
    $old_chat = wiki_page_chat_sidecar($old_abs);
    $new_chat = wiki_page_chat_sidecar($new_abs);
    if ($old_chat === null || $new_chat === null) return false;
    return is_file($old_chat) && file_exists($new_chat);
}

/**
 * Move a page's chat thread along with the page. Call it *after* the page itself has moved.
 *
 * @param string       $old_abs        page path before the move, absolute
 * @param string       $new_abs        page path after the move, absolute
 * @param string       $old_rel        page path before, relative to its space
 * @param string       $new_rel        page path after, relative to *its* space (the target
 *                                     space's, on a cross-space move)
 * @param object       $indexer        PageIndexer for the source space
 * @param string       $space          source space name; '' for content at PAGES_DIR
 * @param object|null  $target_indexer PageIndexer for the target space — cross-space only
 * @param string       $target_space   target space name — cross-space only
 * @param array|null   $actor          who is doing it, for a cross-space addPage
 *
 * @return bool whether a thread was actually moved
 */
function wiki_page_chat_move(string $old_abs, string $new_abs, string $old_rel, string $new_rel,
                             $indexer, string $space,
                             $target_indexer = null, string $target_space = '',
                             ?array $actor = null): bool {
    $old_chat = wiki_page_chat_sidecar($old_abs);
    $new_chat = wiki_page_chat_sidecar($new_abs);
    if ($old_chat === null || $new_chat === null) return false;
    if (!is_file($old_chat) || file_exists($new_chat)) return false;
    if (!@rename($old_chat, $new_chat)) return false;

    $old_chat_rel = (string)wiki_page_chat_sidecar($old_rel);
    $new_chat_rel = (string)wiki_page_chat_sidecar($new_rel);

    if ($target_indexer !== null) {
        // Cross-space, the same dance the page itself does — and the thread gets a fresh
        // id for the same reason the page does: ids are scoped to one index.
        $target_indexer->addPage($new_chat_rel, $actor['uid'] ?? null, $actor['name'] ?? null);
        $indexer->removePage($old_chat_rel);
    } else {
        $indexer->updatePath($old_chat_rel, $new_chat_rel);
    }

    if (defined('SEARCH_ENGINE') && SEARCH_ENGINE === 'sqlite' && class_exists('SearchIndex')) {
        try {
            $si = new SearchIndex();
            if ($target_indexer !== null) $si->movePageCrossSpace($space, $old_chat_rel, $target_space, $new_chat_rel);
            else                          $si->movePage($space, $old_chat_rel, $new_chat_rel);
        } catch (\Throwable $_e) { /* search is a cache; a rename must not fail on it */ }
    }

    // An AI run in flight writes its progress to <chat>.ai-status.<message_id>, which the
    // pending bubble polls. Leave them behind and the bubble falls back to "Working…" for
    // the rest of the run.
    foreach ((array)glob($old_chat . '.ai-status.*') as $status_file) {
        @rename($status_file, $new_chat . substr($status_file, strlen($old_chat)));
    }

    wiki_page_chat_repoint_jobs($space, $old_chat_rel, $target_space !== '' ? $target_space : $space, $new_chat_rel);
    return true;
}

/**
 * Point queued one-off jobs at the thread's new path.
 *
 * Through `agent_job_queue_mutate()`, because the cron runner writes that file too — the
 * same locked read-modify-write the space merge uses for the same field.
 *
 * On a cross-space move the job's `space` moves with it. That is more than the reply
 * address: a job's space is where its wiki tools operate. It is still the right answer —
 * the page the job is about has moved there, and the alternative is an answer the runner
 * cannot deliver at all because it resolves `reply_to.chat` inside the job's own space.
 */
function wiki_page_chat_repoint_jobs(string $old_space, string $old_rel,
                                     string $new_space, string $new_rel): void {
    if (!function_exists('agent_job_queue_mutate')) return;
    try {
        agent_job_queue_mutate(function (array &$jobs) use ($old_space, $old_rel, $new_space, $new_rel) {
            foreach ($jobs as &$j) {
                if ((string)($j['space'] ?? '') !== $old_space) continue;
                if (ltrim((string)($j['reply_to']['chat'] ?? ''), '/') !== $old_rel) continue;
                $j['reply_to']['chat'] = $new_rel;
                if ($new_space !== $old_space) $j['space'] = $new_space;
            }
            unset($j);
        });
    } catch (\Throwable $_e) { /* a queue that cannot be rewritten must not fail the rename */ }
}
