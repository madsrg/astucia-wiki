// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { icons } from '../core/icons.js';
import { state } from '../core/state.js';
import { watch, rtTopic } from '../realtime/index.js';
import { loadFilesFolder, loadFolderView, isFolderViewActive } from '../files_folder/index.js';
import { showToast, canUpload } from '../core/utils.js';
import { t } from '../i18n/index.js';

export const renderTree = (items, parentElement) => {
    const ul = document.createElement('ul');
    items.forEach(item => {
        const li = document.createElement('li');
        li.className = 'file-item';
        const isDiagram = item.name.endsWith('.drawio');
        const isList    = item.name.endsWith('.list');
        const isChat    = item.name.endsWith('.chat');
        const isSearch  = item.name.endsWith('.search');
        const isJson    = item.name.endsWith('.json');
        const displayName = item.name.replace(/\.(md|drawio|list|chat|search|json)$/, '');

        let icon = icons.file;
        if (item.type === 'folder') icon = icons.folder;
        if (item.type === 'filesfolder') icon = icons.filesFolder;
        if (isDiagram) icon = icons.diagram;
        if (isList)    icon = icons.list;
        if (isChat)    icon = icons.chat;
        if (isSearch)  icon = icons.search;
        if (isJson)    icon = icons.json;

        const itemType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : (isSearch ? 'search' : item.type)));
        li.innerHTML = `
            <div class="file-item-content" data-path="${item.path}" data-type="${itemType}" data-id="${item.id || ''}" data-tags='${JSON.stringify(item.tags || [])}'>
                <span class="file-item-name">
                    <span class="folder-icon">${icon}</span>
                    <span>${displayName}</span>
                </span>
            </div>
        `;
        if (item.type === 'folder' && item.children && item.children.length > 0) {
            const childrenUl = renderTree(item.children, li);
            childrenUl.style.display = 'none';
            li.appendChild(childrenUl);
        }
        ul.appendChild(li);
    });
    parentElement.appendChild(ul);
    return ul;
};

export const findItemsByPath = (path) => {
    if (!path) return state.fullFileTree;
    let currentItems = state.fullFileTree;
    const parts = path.split('/');
    for (const part of parts) {
        const found = currentItems.find(item => item.type === 'folder' && item.name === part);
        if (found && found.children) {
            currentItems = found.children;
        } else {
            return [];
        }
    }
    return currentItems;
};

// Where the browse pane currently is. Kept because a file dropped on the pane's empty
// space belongs in the folder being browsed, and only this function is told which.
let _browsePath = '';

export const renderBrowsePane = (items, currentPath) => {
    _browsePath = currentPath || '';
    const fileBrowser = document.getElementById('file-browser');
    fileBrowser.innerHTML = '';
    const ul = document.createElement('ul');

    if (currentPath) {
        const parentPath = currentPath.substring(0, currentPath.lastIndexOf('/'));
        const li = document.createElement('li');
        li.innerHTML = `
            <div class="browse-item-content" data-path="${parentPath}" data-type="up">
                <span class="file-item-name">
                    <span class="folder-icon">${icons.up}</span>
                    <span>..</span>
                </span>
            </div>
        `;
        ul.appendChild(li);
    }

    items.forEach(item => {
        const li = document.createElement('li');
        const isDiagram = item.name.endsWith('.drawio');
        const isList    = item.name.endsWith('.list');
        const isChat    = item.name.endsWith('.chat');
        const isSearch  = item.name.endsWith('.search');
        const isJson    = item.name.endsWith('.json');
        const displayName = item.name.replace(/\.(md|drawio|list|chat|search|json)$/, '');
        const isActive = state.currentPagePath && item.path === state.currentPagePath;

        let icon = icons.file;
        if (item.type === 'folder') icon = icons.folder;
        if (item.type === 'filesfolder') icon = icons.filesFolder;
        if (isDiagram) icon = icons.diagram;
        if (isList)    icon = icons.list;
        if (isChat)    icon = icons.chat;
        if (isJson)    icon = icons.json;
        if (isSearch)  icon = icons.search;

        const bType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : (isSearch ? 'search' : item.type)));
        li.innerHTML = `
            <div class="browse-item-content ${isActive ? 'active' : ''}" data-path="${item.path}" data-type="${bType}" data-id="${item.id || ''}" data-tags='${JSON.stringify(item.tags || [])}'>
                <span class="file-item-name">
                    <span class="folder-icon">${icon}</span>
                    <span>${displayName}</span>
                </span>
                ${item.type === 'folder' ? '<span class="folder-arrow">></span>' : ''}
            </div>
        `;
        ul.appendChild(li);
    });
    fileBrowser.appendChild(ul);
};

export const revealAndSelectFile = (path) => {
    document.querySelectorAll('#file-navigator .file-item.active').forEach(el => el.classList.remove('active'));
    if (!path) return;

    const activeEl = document.querySelector(`#file-navigator [data-path="${path}"]`);
    if (activeEl) {
        const fileItem = activeEl.closest('.file-item');
        if (fileItem) {
            fileItem.classList.add('active');
            let current = fileItem.parentElement.closest('.file-item');
            while (current) {
                const parentFolderContent = current.querySelector('.file-item-content');
                if (parentFolderContent && parentFolderContent.dataset.type === 'folder') {
                    const childUl = current.querySelector('ul');
                    const iconEl = parentFolderContent.querySelector('.folder-icon');
                    if (childUl) {
                        childUl.style.display = 'block';
                        iconEl.innerHTML = icons.folderOpen;
                    }
                }
                current = current.parentElement.closest('.file-item');
            }
        }
    }
};

/**
 * The main area for a folder selected in the *tree*: an empty viewer under the folder's
 * path. Extracted from the tree click handler because collapsing the sidebar swaps this
 * for the folder listing and expanding it swaps back (see syncFolderPane below).
 */
export const showFolderPlaceholder = (path) => {
    state.currentPagePath = path;
    state.currentPageType = 'folder';
    // The space root has no path and cannot be picked in the tree — only by expanding the
    // sidebar out of the root listing — so name it after the space rather than blank.
    document.getElementById('current-page-title').textContent = path || state.currentSpace || '';
    // Clearing the viewer only reads as "cleared" if the viewer is the pane on screen.
    // Arriving from a files library or a folder listing otherwise left that listing up
    // under the new title.
    document.getElementById('viewer-container').classList.remove('hidden');
    document.getElementById('files-folder-container').classList.add('hidden');
    document.getElementById('viewer-content').innerHTML = '';
    document.getElementById('diagram-viewer').innerHTML = '';
    ['tags-container', 'attachments-section', 'page-id-display', 'edit-btn',
     'diagram-edit-btn', 'page-chat-btn', 'editor-mode-group', 'toc-btn', 'copy-btn',
     'backlinks-btn', 'print-btn'].forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));
    document.getElementById('page-actions-group').classList.remove('hidden');
    document.getElementById('move-btn').classList.remove('hidden');
};

/**
 * Keeps the main area in step with the sidebar while a *folder* is selected. A folder has
 * two presentations — the tree's empty viewer and the collapsed-sidebar folder listing —
 * and which one is right depends on whether the tree is on screen, so toggling the
 * sidebar has to swap between them. Call this after any change to `sidebar-collapsed`.
 * A no-op for anything that is not a folder.
 */
export const syncFolderPane = async (collapsed) => {
    if (state.currentPageType !== 'folder') return;
    const path = state.currentPagePath || '';
    if (collapsed) {
        await loadFolderView(path);
    } else {
        showFolderPlaceholder(path);
        renderBrowsePane(findItemsByPath(path), path);
        revealAndSelectFile(path);
    }
};

// Callbacks injected by script.js to avoid circular imports
let _onGenerateTagCloud = null;
let _onLoadPage = null;

export const refreshFileTree = async () => {
    const fileNavigator = document.getElementById('file-navigator');
    const result = await api.call('list');
    if (result.success) {
        state.fullFileTree = result.data;
        fileNavigator.innerHTML = '';
        renderTree(state.fullFileTree, fileNavigator);
        renderBrowsePane(state.fullFileTree, '');
        if (_onGenerateTagCloud) _onGenerateTagCloud();
        // The folder listing is drawn from this tree, so it has to be repainted with it —
        // otherwise a folder created from the listing's own New menu, or one that appeared
        // from an external change, shows up only after navigating away and back.
        if (isFolderViewActive()) await loadFolderView(state.currentPagePath || '');
    }
};

// ── Background tree polling ───────────────────────────────────────────────────

const TREE_POLL_MS = 15000;
const TREE_POLL_SLOW_MS = 300000;   // safety net while push is live; see modules/realtime
let _stopTreeWatch    = null;
let _lastTreeMtime = 0;

const getExpandedFolders = () => {
    const paths = new Set();
    document.querySelectorAll('#file-navigator .file-item-content[data-type="folder"]').forEach(el => {
        const childUl = el.parentElement.querySelector('ul');
        if (childUl && childUl.style.display !== 'none') paths.add(el.dataset.path);
    });
    return paths;
};

const restoreExpandedFolders = (paths) => {
    paths.forEach(path => {
        const el = document.querySelector(`#file-navigator [data-path="${CSS.escape(path)}"][data-type="folder"]`);
        if (!el) return;
        const childUl = el.parentElement.querySelector('ul');
        const iconEl  = el.querySelector('.folder-icon');
        if (childUl) { childUl.style.display = 'block'; if (iconEl) iconEl.innerHTML = icons.folderOpen; }
    });
};

export const stopTreePolling = () => {
    if (_stopTreeWatch) { _stopTreeWatch(); _stopTreeWatch = null; }
    _lastTreeMtime = 0;
};

export const startTreePolling = (space) => {
    stopTreePolling();
    const pollOnce = async () => {
        const res = await api.call('tree_mtime', { space: space || '' });
        if (!res.success) return;
        const mtime = res.mtime || 0;
        if (_lastTreeMtime && mtime !== _lastTreeMtime) {
            const expanded = getExpandedFolders();
            await refreshFileTree();
            restoreExpandedFolders(expanded);
            revealAndSelectFile(state.currentPagePath);
        }
        _lastTreeMtime = mtime;
    };
    // The tree event fires for a create, delete, rename or an external reconcile — the same
    // set that moves tree_mtime, which this still reads to decide whether anything changed.
    _stopTreeWatch = watch(rtTopic.tree(space), pollOnce,
                       { fast: TREE_POLL_MS, slow: TREE_POLL_SLOW_MS });
};


// ── Drag-and-drop upload ──────────────────────────────────────────────────────
//
// Drop .md files on a folder to add them as pages. Restricted to Markdown on purpose:
// the server accepts nothing else, so the two ends agree and a mis-drop is refused with
// a reason rather than half-working.
//
// Both panes are wired by delegation on their container, so nodes re-rendered by a tree
// refresh are covered without re-binding anything.

const MD_FILE = /\.md$/i;


/**
 * The folder a drop lands in: a folder under the pointer wins; a file resolves to the
 * folder holding it, so dropping "next to" a page does the obvious thing; otherwise the
 * pane's own location. Returns null for a files library, which takes uploads of its own
 * kind and not pages.
 */
const dropFolderFor = (e, pane) => {
    const el = e.target.closest(pane === 'tree' ? '.file-item-content' : '.browse-item-content');
    if (!el) return pane === 'browse' ? _browsePath : '';
    const path = el.dataset.path || '';
    switch (el.dataset.type) {
        case 'filesfolder': return null;
        case 'folder':
        case 'up':          return path;
        default:            return path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
    }
};

export const uploadDroppedFiles = async (files, folder) => {
    const pages  = files.filter(f => MD_FILE.test(f.name));
    const others = files.length - pages.length;
    if (!pages.length) {
        showToast(t('tree.drop-none'), 'error');
        return;
    }
    showToast(t('tree.drop-uploading', { n: pages.length }), 'info');

    let done = 0, renamed = 0, failed = 0;
    for (const file of pages) {
        // One request per file: it keeps each upload inside PHP's upload_max_filesize
        // rather than summing them into post_max_size, and one failure does not take
        // the rest of the drop with it.
        const res = await api.call('upload_page', { file, folder }, 'POST');
        if (res?.success) { done++; if (res.renamed) renamed++; }
        else failed++;
    }

    await refreshFileTree();

    const parts = [];
    if (done)    parts.push(t('tree.drop-done', { n: done }));
    if (renamed) parts.push(t('tree.drop-renamed', { n: renamed }));
    if (others)  parts.push(t('tree.drop-skipped', { n: others }));
    if (failed)  parts.push(t('tree.drop-failed', { n: failed }));
    showToast(parts.join(' · '), failed ? 'error' : 'info');
};

const wireDropZone = (container, pane) => {
    let hovered = null;
    const clear = () => {
        hovered?.classList.remove('drop-target');
        container.classList.remove('drop-target-root');
        hovered = null;
    };

    container.addEventListener('dragover', (e) => {
        // Only file drags, and only when this user could actually write here. Without
        // preventDefault the browser navigates away to the dropped file.
        if (!canUpload() || ![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault();
        const folder = dropFolderFor(e, pane);
        if (folder === null) { e.dataTransfer.dropEffect = 'none'; clear(); return; }
        e.dataTransfer.dropEffect = 'copy';

        const el = e.target.closest('.file-item-content, .browse-item-content');
        const highlight = (el && el.dataset.type === 'folder') ? el : null;
        if (highlight !== hovered) {
            hovered?.classList.remove('drop-target');
            hovered = highlight;
            hovered?.classList.add('drop-target');
        }
        // Nothing specific under the pointer: the whole pane is the target, so say so.
        container.classList.toggle('drop-target-root', !hovered);
    });

    container.addEventListener('dragleave', (e) => {
        if (!container.contains(e.relatedTarget)) clear();
    });

    container.addEventListener('drop', async (e) => {
        if (![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault();
        const folder = dropFolderFor(e, pane);
        clear();
        if (!canUpload()) return;
        if (folder === null) { showToast(t('tree.drop-filesfolder'), 'error'); return; }
        const files = [...(e.dataTransfer.files || [])];
        if (files.length) await uploadDroppedFiles(files, folder);
    });
};

// Ctrl/Cmd+click gives the page a tab of its own instead of reusing the preview slot —
// the same gesture that opens a link in a new tab in the browser around us.
const openIntent = (e) => ((e.ctrlKey || e.metaKey) ? 'permanent' : 'preview');

export const init = ({ onLoadPage, onGenerateTagCloud }) => {
    _onLoadPage = onLoadPage;
    _onGenerateTagCloud = onGenerateTagCloud;

    const fileNavigator = document.getElementById('file-navigator');
    const fileBrowser = document.getElementById('file-browser');

    wireDropZone(fileNavigator, 'tree');
    wireDropZone(fileBrowser, 'browse');

    // --- Browse pane clicks ---
    fileBrowser.addEventListener('click', (e) => {
        const target = e.target.closest('.browse-item-content');
        if (!target) return;
        const path = target.dataset.path;
        const type = target.dataset.type;
        if (type === 'folder' || type === 'up') {
            renderBrowsePane(findItemsByPath(path), path);
            revealAndSelectFile(path);
        } else if (type === 'filesfolder') {
            loadFilesFolder(path);
            revealAndSelectFile(path);
        } else {
            const id = target.dataset.id;
            const tags = JSON.parse(target.dataset.tags || '[]');
            _onLoadPage(path, id, tags, { intent: openIntent(e) });
            revealAndSelectFile(path);
        }
    });

    // --- Tree pane clicks ---
    fileNavigator.addEventListener('click', (e) => {
        const contentTarget = e.target.closest('.file-item-content');
        if (!contentTarget) return;

        const path = contentTarget.dataset.path;
        const type = contentTarget.dataset.type;
        const id = contentTarget.dataset.id;
        const tags = JSON.parse(contentTarget.dataset.tags);

        document.querySelectorAll('#file-navigator .file-item.active').forEach(el => el.classList.remove('active'));
        contentTarget.closest('.file-item').classList.add('active');

        if (type === 'file' || type === 'diagram' || type === 'list' || type === 'chat' || type === 'search') {
            _onLoadPage(path, id, tags, { intent: openIntent(e) });
        } else if (type === 'filesfolder') {
            loadFilesFolder(path);
        } else if (type === 'folder') {
            showFolderPlaceholder(path);

            const childUl = contentTarget.parentElement.querySelector('ul');
            const iconEl = contentTarget.querySelector('.folder-icon');
            if (childUl) {
                const isHidden = childUl.style.display === 'none';
                childUl.style.display = isHidden ? 'block' : 'none';
                iconEl.innerHTML = isHidden ? icons.folderOpen : icons.folder;
            }

            renderBrowsePane(findItemsByPath(path), path);
        }
    });
};
