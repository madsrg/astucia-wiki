// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// Shared mutable application state.
// All modules import this object and mutate it directly.
export const state = {
    currentSpace: null,
    currentPagePath: null,
    currentPageId: null,
    currentPageTags: [],
    currentPageType: null,
    hasUnsavedChanges: false,
    initialContent: '',
    isEditing: false,
    hotkeys: {},
    sourcePathToCopy: null,
    sourcePathToMove: null,
    currentListData: null,
    activeListView: null,
    editingItemId: null,
    fullFileTree: [],
    linkInsertionMode: 'link', // 'link' | 'include'
    sortState: { colId: null, direction: 'asc' },
    currentPageLastUpdated: null,
    currentPageSize: null,          // byte size of the loaded file; baseline for the on-disk watcher
    editorLineHeight: 0,
    editMode: localStorage.getItem('wiki_editMode') || 'classic', // 'classic' | 'inline'
    inlineBlocks: [],
    lastApiCallTime: Date.now(),
    currentSpaceHasGit: false,
    pageChatPath: null,
    isMobile: false, // effective mobile layout (from viewport + user override)
    displayMode: localStorage.getItem('wiki_displayMode') || 'auto', // 'auto' | 'desktop' | 'mobile'
};

/**
 * Announce that `isEditing` or `hasUnsavedChanges` just changed.
 *
 * A plain object cannot notify anyone when it is mutated, so anything that has to react
 * to edit state — the tab bar's unsaved marker — listens for `wiki:pagestate` instead of
 * polling this object or intercepting its properties. Call it directly after flipping
 * either flag; it is cheap and listeners are expected to be idempotent.
 */
export const notifyPageState = () => {
    document.dispatchEvent(new CustomEvent('wiki:pagestate'));
};
