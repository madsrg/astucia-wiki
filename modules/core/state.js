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
    editorLineHeight: 0,
    editMode: localStorage.getItem('wiki_editMode') || 'classic', // 'classic' | 'inline'
    inlineBlocks: [],
    lastApiCallTime: Date.now(),
    currentSpaceHasGit: false,
    pageChatPath: null,
};
