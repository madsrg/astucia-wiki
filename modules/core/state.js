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
