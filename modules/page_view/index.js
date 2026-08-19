// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal } from '../core/utils.js';
import { setEditingMode } from '../page_edit/index.js';
import { renderBrowsePane, findItemsByPath } from '../file_tree/index.js';
import { renderTags } from '../tags/index.js';
import { renderAttachments } from '../attachments/index.js';
import { getUsers } from '../core/users.js';
import { t } from '../i18n/index.js';
import { updateBreadcrumb, trackPageVisit, updateFavoriteBtn } from '../nav/index.js';
import { extractHeadings, processTocTag, addHeadingIds, updateTocPanel } from '../toc/index.js';

// ── User comment tag processing ──────────────────────────────────────────────

const COMMENT_PALETTE = ['#4a90d9','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#9333ea','#b45309'];

export const processUserCommentTags = async (content) => {
    if (!content.includes('{user_comment:')) return content;
    const regex = /\{user_comment:(\d+):([A-Za-z0-9+/=]*)(?::[0-9,]*)?\}/g;
    const matches = [...content.matchAll(regex)];
    if (!matches.length) return content;

    const commentUsers = await getUsers();

    let result = content;
    for (const m of matches) {
        const uid  = parseInt(m[1], 10);
        let   text = '';
        try { text = decodeURIComponent(escape(atob(m[2]))); } catch { text = m[2]; }
        const user    = commentUsers.find(u => u.uid === uid);
        const name    = user?.name || `User ${uid}`;
        const initial = name.charAt(0).toUpperCase();
        const color   = COMMENT_PALETTE[uid % COMMENT_PALETTE.length];
        const esc     = (s) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const body    = esc(text).replace(/#(\S+)/g, '<span class="chat-mention">#$1</span>');
        result = result.replace(m[0],
            `<div class="page-comment"><div class="page-comment-header">` +
            `<span class="page-comment-avatar" style="background:${color}">${initial}</span>` +
            `<span class="page-comment-author">${esc(name)}</span></div>` +
            `<div class="page-comment-body">${body}</div></div>`
        );
    }
    return result;
};

// ── Inline diagram embedding ────────────────────────────────────────────────

const initSingleDiagram = async (el) => {
    if (el.dataset.initialized) return;
    el.dataset.initialized = '1';
    const path = el.dataset.path;
    if (!path) return;
    const result = await api.call('get_diagram_svg', { file: path });
    if (!result.success) {
        el.innerHTML = `<p class="inline-diagram-missing">Diagram preview not available — open the diagram and save it to generate a preview.</p>`;
        return;
    }
    const img = document.createElement('img');
    img.src = 'data:image/svg+xml;base64,' + result.svg;
    img.style.cssText = 'max-width:100%;height:auto;display:block;';
    img.alt = path.split('/').pop();
    el.appendChild(img);
};

// Watches the viewer for content that needs a second pass after Markdown rendering:
// embedded .drawio previews, and ```mermaid fenced blocks. Observing beats calling the
// renderers at each site that writes HTML here — page load, in-place refresh, the
// inline editor's preview and {include:} transclusion all go through this one hook.
let diagramObserverSetup = false;
const setupDiagramObserver = () => {
    if (diagramObserverSetup) return;
    diagramObserverSetup = true;
    const viewer = document.getElementById('viewer-content');
    new MutationObserver(mutations => {
        let sawMermaid = false;
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.classList.contains('inline-diagram-viewer')) initSingleDiagram(node);
                node.querySelectorAll('.inline-diagram-viewer:not([data-initialized])').forEach(initSingleDiagram);
                if (!sawMermaid && (node.querySelector?.('code.language-mermaid')
                                    || node.matches?.('code.language-mermaid'))) sawMermaid = true;
            }
        }
        if (sawMermaid) {
            import('../mermaid/index.js').then(m => m.scheduleMermaidRender(viewer)).catch(() => {});
        }
    }).observe(viewer, { childList: true, subtree: true });
};

export const processDiagramTags = async (content) => {
    const regex = /{diagram:(\d+)}/g;
    const matches = [...content.matchAll(regex)];
    if (!matches.length) return content;
    let result = content;
    for (const match of matches) {
        const id = match[1];
        const pathResult = await api.call('get_path_from_id', { pageid: id });
        if (pathResult.success && pathResult.path) {
            result = result.replace(match[0],
                `<div class="inline-diagram-viewer" data-path="${pathResult.path.replace(/"/g, '&quot;')}"></div>`);
        } else {
            result = result.replace(match[0], `[Diagram ${id} not found]`);
        }
    }
    return result;
};

// ── Inline list embedding ────────────────────────────────────────────────────

const escapeHtml = (str) => String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const applyViewFilters = (items, view) => {
    if (!view.filters?.length) return items;
    return items.filter(item =>
        view.filters.every(f => {
            const val = (item[f.colId] ?? '').toString().toLowerCase();
            return val.includes(f.value.toLowerCase());
        })
    );
};

export const processListTags = async (content) => {
    const regex = /{list:(\d+)(?::([^}]*))?}/g;
    const matches = [...content.matchAll(regex)];
    if (!matches.length) return content;
    let result = content;
    for (const match of matches) {
        const id = match[1];
        const arg = match[2]?.trim() ?? null;
        const pathResult = await api.call('get_path_from_id', { pageid: id });
        if (!pathResult.success || !pathResult.path) {
            result = result.replace(match[0], () => `[List ${id} not found]`);
            continue;
        }
        const contentResult = await api.call('get', { file: pathResult.path });
        if (!contentResult.success || !contentResult.data) {
            result = result.replace(match[0], () => `[Error loading list ${id}]`);
            continue;
        }
        try {
            const listData = JSON.parse(contentResult.data);
            let columns = listData.columns || [];
            let items = listData.items || [];

            // Check if arg matches a named view (no comma = single token, try view name first)
            const views = listData.views || [];
            const matchedView = arg && !arg.includes(',')
                ? views.find(v => v.name.toLowerCase() === arg.toLowerCase())
                : null;

            if (matchedView) {
                columns = matchedView.columns
                    .map(cid => columns.find(c => c.id === cid)).filter(Boolean);
                items = applyViewFilters(items, matchedView);
            } else if (arg) {
                const names = arg.split(',');
                const ordered = [];
                for (const cname of names) {
                    const col = columns.find(c => c.name.toLowerCase() === cname.trim().toLowerCase());
                    if (col) ordered.push(col);
                }
                columns = ordered;
            } else {
                columns = columns.filter(c => c.showInListView !== false);
            }

            let html = '<div class="inline-list-view"><table class="list-table"><thead><tr>';
            for (const col of columns) html += `<th>${escapeHtml(col.name)}</th>`;
            html += '</tr></thead><tbody>';
            for (const item of items) {
                html += '<tr>';
                for (const col of columns) html += `<td>${escapeHtml(item[col.id])}</td>`;
                html += '</tr>';
            }
            html += '</tbody></table></div>';
            result = result.replace(match[0], () => html);
        } catch {
            result = result.replace(match[0], () => `[Error parsing list ${id}]`);
        }
    }
    return result;
};

// ── Include transclusion ─────────────────────────────────────────────────────

export const processIncludes = async (content, processedIds = []) => {
    const includeRegex = /{include:(\d+)}/g;
    let processedContent = content;
    const matches = [...content.matchAll(includeRegex)];

    for (const match of matches) {
        const includeTag = match[0];
        const pageId = match[1];

        if (processedIds.includes(pageId)) {
            processedContent = processedContent.replace(includeTag, `[Error: Circular Reference for page ID ${pageId}]`);
            continue;
        }

        const pathResult = await api.call('get_path_from_id', { pageid: pageId });
        if (pathResult.success && pathResult.path) {
            const contentResult = await api.call('get', { file: pathResult.path });
            if (contentResult.success) {
                let subContent = await processIncludes(contentResult.data, [...processedIds, pageId]);
                // Resolve {filename} and {lastUpdated} relative to the included page, not the parent
                const includedFilename = pathResult.path.split('/').pop().replace(/\.(md|drawio|list)$/, '');
                subContent = subContent.replaceAll('{filename}', includedFilename);
                if (contentResult.lastUpdated) {
                    subContent = subContent.replaceAll('{lastUpdated}', new Date(contentResult.lastUpdated * 1000).toLocaleString());
                }
                processedContent = processedContent.replace(includeTag, () => subContent);
            } else {
                processedContent = processedContent.replace(includeTag, () => `[Error: Could not fetch content for page ID ${pageId}]`);
            }
        } else {
            processedContent = processedContent.replace(includeTag, () => `[Error: Page with ID ${pageId} not found]`);
        }
    }
    return processedContent;
};

export const refreshPageContent = async () => {
    if (!state.currentPagePath || state.currentPageType !== 'file' || state.isEditing) return;
    const path = state.currentPagePath;
    const result = await api.call('get', { file: path });
    if (!result.success) return;

    state.initialContent = result.data;
    state.currentPageLastUpdated = result.lastUpdated;
    state.currentPageSize = result.size ?? null;
    document.getElementById('editor-container').value = state.initialContent;

    const processedContent = await processIncludes(state.initialContent);
    const withDiagrams = await processDiagramTags(processedContent);
    const withLists = await processListTags(withDiagrams);
    const withComments = await processUserCommentTags(withLists);
    const headings = extractHeadings(withComments);
    const withToc = processTocTag(withComments, headings);
    let renderedHTML = marked.parse(withToc);

    const filename = path.split('/').pop().replace(/\.(md|drawio|list)$/, '');
    renderedHTML = renderedHTML.replaceAll('{filename}', filename);
    if (state.currentPageLastUpdated) {
        renderedHTML = renderedHTML.replaceAll('{lastUpdated}', new Date(state.currentPageLastUpdated * 1000).toLocaleString());
    }

    const viewerContent = document.getElementById('viewer-content');
    viewerContent.innerHTML = renderedHTML;
    addHeadingIds(viewerContent, headings);
    updateTocPanel(headings, viewerContent);
};

// ── On-disk change watcher ───────────────────────────────────────────────────
//
// Reloads the page you are looking at when its file changes underneath — a git pull,
// an rsync, another person, or an AI writing to it. Only for types rendered straight
// from the file: `.chat` already polls itself, and `.drawio` / `.search` are left
// alone (re-initialising the draw.io embed mid-view is jarring, and a `.search` file
// barely changes — its results are computed).
const FILE_WATCH_MS  = 10000;
const WATCHED_TYPES  = ['file', 'list', 'json'];
let _watchTimer  = null;
let _watchPath   = null;
let _watchMtime  = 0;
let _watchSize   = null;   // null = not sampled yet
let _watchWarned = 0;

export const stopFileWatch = () => {
    if (_watchTimer) { clearInterval(_watchTimer); _watchTimer = null; }
    _watchPath = null;
    _watchMtime = 0;
    _watchSize = null;
    _watchWarned = 0;
};

// Re-render the current page from disk without the rest of loadPage's work (no
// scroll reset, no visit tracking, no browse-pane rebuild).
const reloadWatchedPage = async (path) => {
    if (state.currentPageType === 'file') {
        await refreshPageContent();
        return true;
    }
    const result = await api.call('get', { file: path });
    if (!result.success) return false;
    state.currentPageLastUpdated = result.lastUpdated;
    state.currentPageSize = result.size ?? null;
    if (state.currentPageType === 'json') {
        const { renderJsonView } = await import('../json_view/index.js');
        await renderJsonView(result.data, path);
        return true;
    }
    try {
        state.currentListData = JSON.parse(result.data);
    } catch {
        return false;                       // invalid JSON mid-write — try again next tick
    }
    const { renderListView }  = await import('../list/render.js');
    const { refreshViewTabs } = await import('../list/index.js');
    renderListView();
    refreshViewTabs();
    return true;
};

const startFileWatch = (path, mtime, size) => {
    stopFileWatch();
    if (!WATCHED_TYPES.includes(state.currentPageType)) return;
    _watchPath  = path;
    _watchMtime = mtime || 0;
    // Baselined from the load response, so it describes exactly the bytes on screen —
    // a separate stat call here would race with a write landing right after the load,
    // and that poisoned baseline would hide the change for good.
    _watchSize  = size ?? null;

    _watchTimer = setInterval(async () => {
        if (state.currentPagePath !== _watchPath) { stopFileWatch(); return; }
        const res = await api.call('file_mtime', { file: _watchPath });
        if (!res.success || !res.mtime) return;      // 0 = gone; deletion is handled elsewhere
        if (_watchSize === null) _watchSize = res.size ?? 0;   // only if the seed above failed
        if (!_watchMtime) { _watchMtime = res.mtime; return; }
        // mtime has 1-second resolution, so a write in the same second as the baseline
        // would otherwise be invisible — the size check catches it.
        const changed = res.mtime > _watchMtime || (res.size ?? 0) !== _watchSize;
        if (!changed) return;

        // Never discard unsaved work. Saving does not detect a concurrent change, so
        // without this notice an edit in progress would silently overwrite whatever
        // changed on disk. _watchMtime is deliberately left as it was, so the reload
        // happens as soon as the edit is saved or cancelled.
        if (state.isEditing || state.hasUnsavedChanges) {
            if (_watchWarned !== res.mtime) {
                _watchWarned = res.mtime;
                showToast(t('page.changed-on-disk'), 'info');
            }
            return;
        }

        const watched = _watchPath;
        if (await reloadWatchedPage(watched)) {
            if (state.currentPagePath !== watched) return;   // navigated away mid-reload
            _watchMtime = res.mtime;
            _watchSize  = res.size ?? _watchSize;
            showToast(t('page.reloaded-from-disk'), 'info');
        }
    }, FILE_WATCH_MS);
};

/**
 * Empty view for "there is no page to show" — currently only when a space has no
 * Main.md start page. Deliberately does not create anything: the space may simply
 * not have a start page, and the previous behaviour (silently recreating Main.md)
 * made deleting it look broken.
 */
export const showBlankPage = async () => {
    stopFileWatch();
    const chatMod = await import('../chat/index.js');
    chatMod.stopPolling();
    const pageChatMod = await import('../page_chat/index.js');
    pageChatMod.closePanel();

    state.currentPagePath = null;
    state.currentPageId = null;
    state.currentPageTags = [];
    state.currentPageType = null;
    state.initialContent = '';
    state.isEditing = false;
    state.hasUnsavedChanges = false;

    // Leave edit mode FIRST: setEditingMode(false) un-hides the meta row, the tags
    // section and the page-actions group (it assumes there is a page to act on), so
    // anything hidden before it would be shown again.
    await setEditingMode(false);

    document.getElementById('print-lightbox')?.classList.add('hidden');
    document.getElementById('current-page-title').innerHTML = '';
    document.getElementById('page-id-display').classList.add('hidden');
    updateBreadcrumb('', state.currentSpace);
    updateFavoriteBtn(null); // no page id → hides the star

    // Show the ordinary viewer and hide every other content container.
    document.getElementById('viewer-container').classList.remove('hidden');
    ['list-view-container', 'chat-view-container', 'search-view-container',
     'json-view-container', 'files-folder-container'].forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));
    document.querySelector('.editor-container-wrapper')?.classList.add('hidden');
    document.getElementById('diagram-viewer')?.classList.add('hidden');

    // Hide the per-page chrome: the actions dropdown (and its menu, in case it was
    // left open), the attachments/tags meta row, and every page-scoped header button.
    ['page-actions-group', 'file-actions-menu', 'page-meta-row', 'tags-container',
     'attachments-section', 'save-btn', 'cancel-btn', 'search-btn', 'edit-btn',
     'diagram-edit-btn', 'copy-btn', 'move-btn', 'backlinks-btn', 'print-btn',
     'graph-focus-btn', 'page-chat-btn', 'share-btn', 'chat-topic-btn', 'toc-btn',
     'editor-mode-group', 'git-history-btn', 'git-commit-toggle-btn',
     'git-snapshot-btn'].forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));
    document.getElementById('edit-btn').disabled = true;

    document.getElementById('viewer-content').innerHTML =
        `<div class="page-blank-state">${t('page.no-start-page')}</div>`;
    updateTocPanel([], null);
};

/**
 * Optional hooks for the tabs module (modules/tabs). loadPage is the single funnel every
 * page open goes through, so the tab bar observes it here instead of wrapping ~20 call
 * sites. Both are no-ops when tabs are not initialised.
 *
 *   beforeLoad(nextPath) → snapshots the page being navigated away from; returns true
 *       when its unsaved work is now held as a draft and will be restored, which makes
 *       the discard prompt below wrong to show.
 *   afterLoad(detail)    → the page that was just rendered, for the tab bar to follow.
 */
let _tabHooks = {};
export const setTabHooks = (hooks) => { _tabHooks = hooks || {}; };

export const loadPage = async (path, id, tags, opts = {}) => {
    setupDiagramObserver();

    // Stop any active chat poll before loading a new page
    const chatMod = await import('../chat/index.js');
    chatMod.stopPolling();

    // With tabs open, switching away keeps the edit in the outgoing tab, so asking to
    // discard it would be a lie. Without tabs (or for an edit that cannot be held as a
    // draft) this falls through to the prompt exactly as before.
    const draftKept = _tabHooks.beforeLoad ? _tabHooks.beforeLoad(path) : false;

    // A .json page edits inline without toggling state.isEditing, so guard it on the page type too.
    if (!draftKept && (state.isEditing || state.currentPageType === 'json') && state.hasUnsavedChanges && !await confirmModal(t('edit.discard-confirm'), { message: t('edit.discard-nav'), confirmLabel: t('btn.discard'), dangerous: true, icon: icons.warning })) {
        return;
    }

    const isDiagram = path.endsWith('.drawio');
    const isList    = path.endsWith('.list');
    const isChat    = path.endsWith('.chat');
    const isSearch  = path.endsWith('.search');
    const isJson    = path.endsWith('.json');

    // Read the content BEFORE touching the DOM, and use it in every branch below.
    //
    // This used to be one fetch per branch, *after* the branch had already hidden the
    // outgoing page's pane and shown the incoming one. That await is a paint boundary, so
    // every navigation rendered twice: first an empty pane (md → list, md → json) or the
    // previously rendered content of the pane being reused (list → md showed the last
    // Markdown page), then the real page. Fetching first collapses a switch into a single
    // paint. It also means a page that cannot be read leaves the current one alone
    // instead of half-switching to it.
    const result = await api.call(isChat ? 'chat_messages' : 'get', { file: path });
    if (!result.success) {
        showToast(t('page.load-failed'), 'error');
        return;
    }

    // Warm the renderer for this content type before the pane swap. A dynamic import that
    // has to hit the network is a paint boundary too, so on the first visit to a type the
    // new pane would appear empty for as long as the module took to arrive. Once loaded it
    // is cached, and the awaits inside the branches below resolve without yielding a frame.
    // (chat is already warm — its module was imported above to stop the poll.)
    if (isJson)        await import('../json_view/index.js');
    else if (isSearch) await import('../advanced_search/index.js');
    else if (isList)   await Promise.all([import('../list/render.js'), import('../list/index.js')]);

    state.currentPagePath = path;
    state.currentPageId = id;
    state.currentPageTags = tags || [];
    state.currentPageType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : (isSearch ? 'search' : (isJson ? 'json' : 'file'))));

    document.getElementById('print-lightbox')?.classList.add('hidden');
    updateBreadcrumb(path, state.currentSpace);
    trackPageVisit(id, path, state.currentSpace);
    updateFavoriteBtn(id);

    const titleText = path.split('/').pop().replace(/\.(md|drawio|list|chat|search|json)$/, '');
    let titleIcon = icons.file;
    if (state.currentPageType === 'diagram') titleIcon = icons.diagram;
    else if (state.currentPageType === 'list') titleIcon = icons.list;
    else if (state.currentPageType === 'chat') titleIcon = icons.chat;
    else if (state.currentPageType === 'search') titleIcon = icons.search;
    else if (state.currentPageType === 'json') titleIcon = icons.json;

    document.getElementById('current-page-title').innerHTML = `${titleIcon} <span>${titleText}</span>`;
    const pageIdDisplay = document.getElementById('page-id-display');
    pageIdDisplay.textContent = `ID: ${id}`;
    pageIdDisplay.classList.remove('hidden');

    // Show mode toggle only for markdown pages
    const modeGroup = document.getElementById('editor-mode-group');
    if (modeGroup) modeGroup.classList.toggle('hidden', isDiagram || isList || isChat || isSearch || isJson);

    document.getElementById('files-folder-container').classList.add('hidden');
    const viewerContainer  = document.getElementById('viewer-container');
    const listViewContainer = document.getElementById('list-view-container');
    const chatViewContainer = document.getElementById('chat-view-container');
    const searchViewContainer = document.getElementById('search-view-container');
    const jsonViewContainer = document.getElementById('json-view-container');
    const editorWrapper = document.querySelector('.editor-container-wrapper');
    const viewerContent = document.getElementById('viewer-content');
    const diagramViewer = document.getElementById('diagram-viewer');
    const saveBtn = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const searchBtn = document.getElementById('search-btn');
    const editBtn = document.getElementById('edit-btn');
    const pageActionsGroup = document.getElementById('page-actions-group');

    let loadedGitCommit = null;

    // The JSON viewer is only shown by the isJson branch below; hide it up-front
    // so navigating away from a .json page always clears it, and tear down the
    // editor instance when we're not rendering another .json page.
    if (jsonViewContainer) jsonViewContainer.classList.add('hidden');
    if (!isJson) import('../json_view/index.js').then(m => m.destroyJsonEditor?.()).catch(() => {});

    if (isJson) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.add('hidden');
        searchViewContainer.classList.add('hidden');
        if (jsonViewContainer) jsonViewContainer.classList.remove('hidden');
        editorWrapper.classList.add('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.add('hidden');
        editBtn.disabled = true;
        pageActionsGroup.classList.remove('hidden');
        state.isEditing = false;
        updateTocPanel([], null);

        loadedGitCommit = false;
        state.initialContent = result.data;
        state.currentPageLastUpdated = result.lastUpdated;   // baseline for the on-disk watcher
        state.currentPageSize = result.size ?? null;
        const { renderJsonView } = await import('../json_view/index.js');
        await renderJsonView(result.data, path);
        renderTags();
    } else if (isSearch) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.add('hidden');
        searchViewContainer.classList.remove('hidden');
        editorWrapper.classList.add('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.add('hidden');
        editBtn.disabled = true;
        pageActionsGroup.classList.remove('hidden');
        state.isEditing = false;
        updateTocPanel([], null);
        loadedGitCommit = false;
        const { renderSearchView } = await import('../advanced_search/index.js');
        renderSearchView(result.data, path);
        document.getElementById('adv-search-results').scrollTop = 0;
    } else if (isChat) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.remove('hidden');
        searchViewContainer.classList.add('hidden');
        editorWrapper.classList.add('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.add('hidden');
        editBtn.disabled = true;
        pageActionsGroup.classList.remove('hidden');
        state.isEditing = false;

        updateTocPanel([], null);
        loadedGitCommit = result.git_commit ?? false;
        state.currentChatData = {
            messages:      result.messages || [],
            topic:         result.topic || '',
            git_commit:    result.git_commit ?? false,
            nextMessageId: result.nextMessageId ?? 1,
        };
        const { renderChatView, startPolling } = await import('../chat/index.js');
        renderChatView(state.currentChatData, result.has_more);
        startPolling(path, result.mtime || 0);
    } else if (isList) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.remove('hidden');
        chatViewContainer.classList.add('hidden');
        searchViewContainer.classList.add('hidden');
        state.sortState = { colId: null, direction: 'asc' };

        loadedGitCommit = result.git_commit ?? false;
        state.currentPageLastUpdated = result.lastUpdated;   // baseline for the on-disk watcher
        state.currentPageSize = result.size ?? null;
        try {
            state.currentListData = JSON.parse(result.data);
            state.activeListView = null;
            const { renderListView } = await import('../list/render.js');
            const { refreshViewTabs } = await import('../list/index.js');
            renderListView();
            refreshViewTabs();
            document.getElementById('list-items-table').scrollTop = 0;
        } catch (e) {
            showToast(t('view.invalid-list'), 'error');
            document.getElementById('list-items-table').innerHTML = `<p style="padding: 1rem;">${t('view.parse-error')}</p>`;
        }
        updateTocPanel([], null);
        editorWrapper.classList.add('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        pageActionsGroup.classList.remove('hidden');
        editBtn.classList.add('hidden');
        editBtn.disabled = true;
        state.isEditing = false;
    } else {
        // Markdown: resolve everything that can yield — `{include:ID}` transclusions and
        // the diagram/list/comment tags, all of which fetch — into finished HTML *before*
        // the pane swap below. Rendering after the swap would let a page with includes
        // show the previously rendered page for the length of those requests.
        let mdHtml = null, mdHeadings = null;
        if (!isDiagram) {
            loadedGitCommit = result.git_commit ?? true;
            state.initialContent = result.data;
            state.currentPageLastUpdated = result.lastUpdated;
            state.currentPageSize = result.size ?? null;   // baseline for the on-disk watcher
            document.getElementById('editor-container').value = state.initialContent;

            const processedContent = await processIncludes(state.initialContent);
            const withDiagrams = await processDiagramTags(processedContent);
            const withLists = await processListTags(withDiagrams);
            const withComments = await processUserCommentTags(withLists);
            mdHeadings = extractHeadings(withComments);
            const withToc = processTocTag(withComments, mdHeadings);
            mdHtml = marked.parse(withToc);

            const filename = path.split('/').pop().replace(/\.(md|drawio|list)$/, '');
            mdHtml = mdHtml.replaceAll('{filename}', filename);
            if (state.currentPageLastUpdated) {
                const updatedDate = new Date(state.currentPageLastUpdated * 1000);
                mdHtml = mdHtml.replaceAll('{lastUpdated}', updatedDate.toLocaleString());
            }
        }

        viewerContainer.classList.remove('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.add('hidden');
        searchViewContainer.classList.add('hidden');
        viewerContent.classList.toggle('hidden', isDiagram);
        diagramViewer.classList.toggle('hidden', !isDiagram);

        if (isDiagram) {
            diagramViewer.innerHTML = `<iframe src="about:blank" frameborder="0"></iframe>`;
            loadedGitCommit = result.git_commit ?? true;
            state.initialContent = result.data; // diagram module reads this on init/edit
            {
                const iframe = diagramViewer.querySelector('iframe');
                iframe.style.opacity = '0';
                iframe.style.transition = 'opacity 0.15s';
                // chrome=0 hides all editing UI; proto=json still handles the init/load handshake
                iframe.src = `https://embed.diagrams.net/?ui=atlas&spin=1&proto=json&embed=1&chrome=0`;
            }
            updateTocPanel([], null);
            renderTags();
            await renderAttachments();
            setEditingMode(false);
            editBtn.disabled = false;
        } else {
            viewerContent.innerHTML = mdHtml;
            // Start a freshly loaded page at the top — replacing innerHTML
            // keeps the old scrollTop when the new page is also tall enough.
            viewerContent.scrollTop = 0;
            addHeadingIds(viewerContent, mdHeadings);
            updateTocPanel(mdHeadings, viewerContent);
            renderTags();
            await renderAttachments();
            setEditingMode(false);
            editBtn.disabled = false;
        }
    }

    document.getElementById('diagram-edit-btn').classList.toggle('hidden', !isDiagram);
    document.getElementById('chat-topic-btn').classList.toggle('hidden', !isChat);

    const isMarkdownPage = !isDiagram && !isList && !isChat && !isSearch && !isJson;
    document.getElementById('page-chat-btn')?.classList.toggle('hidden', !isMarkdownPage);
    document.getElementById('share-btn')?.classList.toggle('hidden', !isMarkdownPage);
    const pageChatMod = await import('../page_chat/index.js');
    pageChatMod.closePanel();
    document.getElementById('copy-btn').classList.remove('hidden');
    document.getElementById('move-btn').classList.remove('hidden');
    document.getElementById('backlinks-btn').classList.toggle('hidden', isSearch);
    document.getElementById('graph-focus-btn')?.classList.toggle('hidden', isSearch);
    document.getElementById('print-btn').classList.toggle('hidden', isChat || isSearch);

    const { updateForPage, updateGitButtons } = await import('../git/index.js');
    updateForPage();
    updateGitButtons(loadedGitCommit ?? (state.currentPageType === 'chat' || state.currentPageType === 'list' ? false : true));

    const parentPath = path.substring(0, path.lastIndexOf('/'));
    renderBrowsePane(findItemsByPath(parentPath), parentPath);

    startFileWatch(path, state.currentPageLastUpdated || 0, state.currentPageSize);

    // Last, so the tab bar only ever reflects a page that finished rendering. `intent`
    // distinguishes a peek (a plain click, which reuses the preview slot) from a page
    // that should get a tab of its own — e.g. one the user just created.
    _tabHooks.afterLoad?.({
        path, id, tags: tags || [], type: state.currentPageType,
        intent: opts.intent || 'preview',
    });
};
