// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

/**
 * Every control in the header that belongs to *an open page*, and the one call that
 * clears them.
 *
 * `loadPage` decides which of these a page gets, per type. Everything else that takes
 * over the main area — a folder listing, the files library, a results list — has to put
 * them all away, because a control left behind is still wired to the page you were on a
 * moment ago and there is nothing on screen that says so.
 *
 * There were three hand-maintained lists doing that, no two of them the same, which is
 * the failure this replaces: the results list did not know about the chat settings gear
 * or the knowledge-graph button, so "My Mentions" opened with the last chat's settings
 * and a button offering to show a page you were no longer looking at in the graph. The
 * tree's folder placeholder did not know about those two, share, git or search-and-
 * replace. A fourth caller would have got a fourth subset.
 *
 * The rule for what belongs here: it is a control whose meaning comes from
 * `state.currentPagePath`. The `…` dropdown's Move, Rename and Delete are deliberately
 * **not** in it — a folder has all three and each caller re-shows what it wants, which
 * is a decision rather than an oversight. `page-actions-group` is not either: the
 * results list hides the whole group, the two folder views show it.
 */
export const PAGE_SCOPED_CONTROLS = [
    // The meta row under the title, and the title's own badges.
    'page-meta-row', 'tags-container', 'attachments-section',
    'page-id-display', 'frontmatter-badge',
    // Editing.
    'edit-btn', 'save-btn', 'cancel-btn', 'editor-mode-group', 'search-btn',
    // Per-type viewers and panels.
    'diagram-edit-btn', 'toc-btn', 'page-chat-btn', 'chat-dock-btn', 'chat-topic-btn',
    // Things that act on this page.
    'share-btn', 'graph-focus-btn', 'copy-btn', 'backlinks-btn', 'metadata-btn',
    'print-btn',
    // Git, which is per page as well as per space.
    'git-history-btn', 'git-commit-toggle-btn', 'git-snapshot-btn',
];

/** Hide every one of them. Callers re-show whatever their own view needs. */
export const hidePageControls = () => {
    PAGE_SCOPED_CONTROLS.forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));
};
