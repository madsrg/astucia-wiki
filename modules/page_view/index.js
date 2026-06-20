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

let diagramObserverSetup = false;
const setupDiagramObserver = () => {
    if (diagramObserverSetup) return;
    diagramObserverSetup = true;
    new MutationObserver(mutations => {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.classList.contains('inline-diagram-viewer')) initSingleDiagram(node);
                node.querySelectorAll('.inline-diagram-viewer:not([data-initialized])').forEach(initSingleDiagram);
            }
        }
    }).observe(document.getElementById('viewer-content'), { childList: true, subtree: true });
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

export const loadPage = async (path, id, tags) => {
    setupDiagramObserver();

    // Stop any active chat poll before loading a new page
    const chatMod = await import('../chat/index.js');
    chatMod.stopPolling();

    if (state.isEditing && state.hasUnsavedChanges && !await confirmModal(t('edit.discard-confirm'), { message: t('edit.discard-nav'), confirmLabel: t('btn.discard'), dangerous: true, icon: icons.warning })) {
        return;
    }

    const isDiagram = path.endsWith('.drawio');
    const isList    = path.endsWith('.list');
    const isChat    = path.endsWith('.chat');

    state.currentPagePath = path;
    state.currentPageId = id;
    state.currentPageTags = tags || [];
    state.currentPageType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : 'file'));

    document.getElementById('print-lightbox')?.classList.add('hidden');
    updateBreadcrumb(path, state.currentSpace);
    trackPageVisit(id, path, state.currentSpace);
    updateFavoriteBtn(id);

    const titleText = path.replace(/\.(md|drawio|list|chat)$/, '');
    let titleIcon = icons.file;
    if (state.currentPageType === 'diagram') titleIcon = icons.diagram;
    else if (state.currentPageType === 'list') titleIcon = icons.list;
    else if (state.currentPageType === 'chat') titleIcon = icons.chat;

    document.getElementById('current-page-title').innerHTML = `${titleIcon} <span>${titleText}</span>`;
    const pageIdDisplay = document.getElementById('page-id-display');
    pageIdDisplay.textContent = `ID: ${id}`;
    pageIdDisplay.classList.remove('hidden');

    // Show mode toggle only for markdown pages
    const modeGroup = document.getElementById('editor-mode-group');
    if (modeGroup) modeGroup.classList.toggle('hidden', isDiagram || isList || isChat);

    document.getElementById('files-folder-container').classList.add('hidden');
    const viewerContainer  = document.getElementById('viewer-container');
    const listViewContainer = document.getElementById('list-view-container');
    const chatViewContainer = document.getElementById('chat-view-container');
    const editorWrapper = document.querySelector('.editor-container-wrapper');
    const viewerContent = document.getElementById('viewer-content');
    const diagramViewer = document.getElementById('diagram-viewer');
    const saveBtn = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const searchBtn = document.getElementById('search-btn');
    const editBtn = document.getElementById('edit-btn');
    const pageActionsGroup = document.getElementById('page-actions-group');

    let loadedGitCommit = null;

    if (isChat) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.remove('hidden');
        editorWrapper.classList.add('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.add('hidden');
        editBtn.disabled = true;
        pageActionsGroup.classList.remove('hidden');
        state.isEditing = false;

        updateTocPanel([], null);
        const result = await api.call('chat_messages', { file: path });
        if (result.success) {
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
        }
    } else if (isList) {
        viewerContainer.classList.add('hidden');
        listViewContainer.classList.remove('hidden');
        chatViewContainer.classList.add('hidden');
        state.sortState = { colId: null, direction: 'asc' };

        const result = await api.call('get', { file: path });
        if (result.success) {
            loadedGitCommit = result.git_commit ?? false;
            try {
                state.currentListData = JSON.parse(result.data);
                state.activeListView = null;
                const { renderListView } = await import('../list/render.js');
                const { refreshViewTabs } = await import('../list/index.js');
                renderListView();
                refreshViewTabs();
            } catch (e) {
                showToast(t('view.invalid-list'), 'error');
                document.getElementById('list-items-table').innerHTML = `<p style="padding: 1rem;">${t('view.parse-error')}</p>`;
            }
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
        viewerContainer.classList.remove('hidden');
        listViewContainer.classList.add('hidden');
        chatViewContainer.classList.add('hidden');
        viewerContent.classList.toggle('hidden', isDiagram);
        diagramViewer.classList.toggle('hidden', !isDiagram);

        if (isDiagram) {
            diagramViewer.innerHTML = `<iframe src="about:blank" frameborder="0"></iframe>`;
            const result = await api.call('get', { file: path });
            if (result.success) {
                loadedGitCommit = result.git_commit ?? true;
                state.initialContent = result.data; // diagram module reads this on init/edit
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
            const result = await api.call('get', { file: path });
            if (result.success) {
                loadedGitCommit = result.git_commit ?? true;
                state.initialContent = result.data;
                state.currentPageLastUpdated = result.lastUpdated;
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
                    const updatedDate = new Date(state.currentPageLastUpdated * 1000);
                    renderedHTML = renderedHTML.replaceAll('{lastUpdated}', updatedDate.toLocaleString());
                }

                viewerContent.innerHTML = renderedHTML;
                addHeadingIds(viewerContent, headings);
                updateTocPanel(headings, viewerContent);
                renderTags();
                await renderAttachments();
                setEditingMode(false);
                editBtn.disabled = false;
            }
        }
    }

    document.getElementById('diagram-edit-btn').classList.toggle('hidden', !isDiagram);
    document.getElementById('chat-topic-btn').classList.toggle('hidden', !isChat);

    const isMarkdownPage = !isDiagram && !isList && !isChat;
    document.getElementById('page-chat-btn')?.classList.toggle('hidden', !isMarkdownPage);
    const pageChatMod = await import('../page_chat/index.js');
    pageChatMod.closePanel();
    document.getElementById('copy-btn').classList.remove('hidden');
    document.getElementById('move-btn').classList.remove('hidden');
    document.getElementById('backlinks-btn').classList.remove('hidden');
    document.getElementById('print-btn').classList.toggle('hidden', isChat);

    const { updateForPage, updateGitButtons } = await import('../git/index.js');
    updateForPage();
    updateGitButtons(loadedGitCommit ?? (state.currentPageType === 'chat' || state.currentPageType === 'list' ? false : true));

    const parentPath = path.substring(0, path.lastIndexOf('/'));
    renderBrowsePane(findItemsByPath(parentPath), parentPath);
};
