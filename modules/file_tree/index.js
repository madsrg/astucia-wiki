import { api } from '../core/api.js';
import { icons } from '../core/icons.js';
import { state } from '../core/state.js';
import { loadFilesFolder } from '../files_folder/index.js';

export const renderTree = (items, parentElement) => {
    const ul = document.createElement('ul');
    items.forEach(item => {
        const li = document.createElement('li');
        li.className = 'file-item';
        const isDiagram = item.name.endsWith('.drawio');
        const isList    = item.name.endsWith('.list');
        const isChat    = item.name.endsWith('.chat');
        const displayName = item.name.replace(/\.(md|drawio|list|chat)$/, '');

        let icon = icons.file;
        if (item.type === 'folder') icon = icons.folder;
        if (item.type === 'filesfolder') icon = icons.filesFolder;
        if (isDiagram) icon = icons.diagram;
        if (isList)    icon = icons.list;
        if (isChat)    icon = icons.chat;

        const itemType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : item.type));
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

export const renderBrowsePane = (items, currentPath) => {
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
        const displayName = item.name.replace(/\.(md|drawio|list|chat)$/, '');
        const isActive = state.currentPagePath && item.path === state.currentPagePath;

        let icon = icons.file;
        if (item.type === 'folder') icon = icons.folder;
        if (item.type === 'filesfolder') icon = icons.filesFolder;
        if (isDiagram) icon = icons.diagram;
        if (isList)    icon = icons.list;
        if (isChat)    icon = icons.chat;

        const bType = isDiagram ? 'diagram' : (isList ? 'list' : (isChat ? 'chat' : item.type));
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
    }
};

// ── Background tree polling ───────────────────────────────────────────────────

const TREE_POLL_MS = 15000;
let _treeTimer    = null;
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
    if (_treeTimer) { clearInterval(_treeTimer); _treeTimer = null; }
    _lastTreeMtime = 0;
};

export const startTreePolling = (space) => {
    stopTreePolling();
    _treeTimer = setInterval(async () => {
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
    }, TREE_POLL_MS);
};

export const init = ({ onLoadPage, onGenerateTagCloud }) => {
    _onLoadPage = onLoadPage;
    _onGenerateTagCloud = onGenerateTagCloud;

    const fileNavigator = document.getElementById('file-navigator');
    const fileBrowser = document.getElementById('file-browser');

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
            _onLoadPage(path, id, tags);
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

        if (type === 'file' || type === 'diagram' || type === 'list' || type === 'chat') {
            _onLoadPage(path, id, tags);
        } else if (type === 'filesfolder') {
            loadFilesFolder(path);
        } else if (type === 'folder') {
            state.currentPagePath = path;
            state.currentPageType = 'folder';
            document.getElementById('current-page-title').textContent = path;
            document.getElementById('viewer-content').innerHTML = '';
            document.getElementById('diagram-viewer').innerHTML = '';
            document.getElementById('tags-container').classList.add('hidden');
            document.getElementById('attachments-section').classList.add('hidden');
            document.getElementById('page-id-display').classList.add('hidden');
            document.getElementById('edit-btn').classList.add('hidden');
            document.getElementById('diagram-edit-btn').classList.add('hidden');
            document.getElementById('page-chat-btn')?.classList.add('hidden');
            document.getElementById('editor-mode-group')?.classList.add('hidden');
            document.getElementById('toc-btn')?.classList.add('hidden');
            document.getElementById('page-actions-group').classList.remove('hidden');
            document.getElementById('copy-btn').classList.add('hidden');
            document.getElementById('backlinks-btn').classList.add('hidden');
            document.getElementById('print-btn').classList.add('hidden');
            document.getElementById('move-btn').classList.remove('hidden');

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
