// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

/**
 * What a page type's file is called, and what it looks like.
 *
 * One table, because several modules used to answer the same question from a literal of
 * their own and the literals had drifted apart:
 *
 *   file_ops rename   /\.(md|drawio|list|chat)$/   strip, re-append by a chain of ifs
 *   file_ops copy     /\.(md|drawio|list|chat)$/   strip, re-append with `|| '.md'`
 *   file_ops delete   /\.(md|drawio|json)$/        strip, for the confirm prompt
 *   nav               .drawio / .list / .chat      the icon for a recent or favourite
 *   page_view         six extensions               the page header
 *
 * `json` and `search` were missing from most of them. In the two that *compose a
 * filename* that was destructive — renaming `Data.json` wrote back a name with no
 * extension at all, copying it produced a `.md` file holding JSON — because the
 * extension is what decides how the wiki renders and indexes a page, so changing it
 * silently changes what the page is. (The `wiki_rename_page` tool refuses to change it
 * for the same reason.) Elsewhere it was cosmetic and no less confusing: the recents and
 * favourites panes listed a data page as `Data.json` under a plain-page icon, while the
 * tree three centimetres away showed `Data` under the data-page one.
 *
 * Keys are `state.currentPageType` values (the closed set documented in CLAUDE.md). The
 * icon map used to key on `file`, which was `md`'s name before that rename; a dead key
 * that looked harmless only because its fallback happened to be the same icon.
 * `folder` / `filesfolder` are absent: they are not files.
 *
 * Deliberately *not* pulled in here: `file_tree`, `tabs`, `files_folder` and `search`
 * each map a path to a **type name** of their own rather than to an icon or an
 * extension, and all but `search` already list the six. Converging those is a bigger
 * change than this table is, and none of them can lose a byte on disk.
 */
import { icons } from './icons.js';

export const PAGE_TYPES = {
    md:      { ext: '.md',     icon: icons.file },
    diagram: { ext: '.drawio', icon: icons.diagram },
    list:    { ext: '.list',   icon: icons.list },
    chat:    { ext: '.chat',   icon: icons.chat },
    json:    { ext: '.json',   icon: icons.json },
    search:  { ext: '.search', icon: icons.search },
};

const EXTS = Object.values(PAGE_TYPES).map(t => t.ext);

/**
 * The extension this path carries, or '' if it is none of the wiki's.
 *
 * Read off the path rather than looked up from the open page's type, so what a rename
 * puts back is necessarily what it took off — the invariant, rather than two lookups
 * that have to agree. A name with no wiki extension (a folder) passes through untouched.
 */
export const pageExt = (path) => EXTS.find(e => (path || '').endsWith(e)) || '';

/** The name without its extension — what a rename or copy box should offer. */
export const stripExt = (name) => {
    const ext = pageExt(name);
    return ext ? name.slice(0, -ext.length) : name;
};

/** The icon standing for a `state.currentPageType`, falling back to the page icon. */
export const typeIcon = (type) => PAGE_TYPES[type]?.icon || icons.file;

/**
 * The same icon, chosen from a path. For the places that have a path and no page type:
 * a recents or favourites row is a stored `{ path }`, not the open page.
 */
export const pathIcon = (path) => {
    const ext = pageExt(path);
    return Object.values(PAGE_TYPES).find(t => t.ext === ext)?.icon || icons.file;
};
