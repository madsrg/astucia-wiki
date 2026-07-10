// "Save as markdown page" / "Append to markdown page" for chat messages.
// Shows a Space + Folder + Filename picker (same shape as the copy dialog),
// then writes the message text to a page via the `save_message_page` action.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast } from '../core/utils.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';
import { loadPage } from '../page_view/index.js';
import { t } from '../i18n/index.js';

let _text = '';
let _mode = 'create'; // 'create' | 'append'

// Render folders (always) and, when picking a page to append to, the `.md`
// files inside them. Folders carry data-kind="folder", pages data-kind="file".
const renderTree = (items, parent, includeFiles) => {
    items.forEach(item => {
        if (item.type === 'folder') {
            const li = document.createElement('li');
            li.className = 'file-item';
            li.innerHTML = `<div class="file-item-content" data-kind="folder" data-path="${item.path}"><span class="file-item-name"><span class="folder-icon">${icons.folder}</span><span>${item.name}</span></span></div>`;
            if (item.children?.length > 0) {
                const childrenUl = document.createElement('ul');
                childrenUl.style.display = 'none';
                renderTree(item.children, childrenUl, includeFiles);
                if (childrenUl.children.length) li.appendChild(childrenUl);
            }
            parent.appendChild(li);
        } else if (includeFiles && item.type === 'file' && item.path.endsWith('.md')) {
            const li = document.createElement('li');
            li.className = 'file-item';
            li.innerHTML = `<div class="file-item-content" data-kind="file" data-path="${item.path}"><span class="file-item-name"><span class="folder-icon">${icons.file}</span><span>${item.name.replace(/\.md$/i, '')}</span></span></div>`;
            parent.appendChild(li);
        }
    });
};

const loadTree = async (spaceSelect, treeEl, mode) => {
    const params = spaceSelect.value ? { space: spaceSelect.value } : {};
    const result = await api.call('list', params);
    if (!result.success) return;
    treeEl.innerHTML = '';
    const ul = document.createElement('ul');
    // 'create' selects a destination folder, so the space root is a valid,
    // pre-selected target. 'append' selects an existing page — no root option.
    if (mode === 'create') {
        const rootLi = document.createElement('li');
        rootLi.className = 'file-item';
        rootLi.innerHTML = `<div class="file-item-content active" data-kind="folder" data-path=""><span class="file-item-name"><span class="folder-icon">${icons.folderOpen}</span><span>${t('fileops.root')}</span></span></div>`;
        ul.appendChild(rootLi);
    }
    renderTree(result.data, ul, mode === 'append');
    treeEl.appendChild(ul);
};

// text = message content, mode = 'create' | 'append'
export const openSaveMessageDialog = async (text, mode = 'create') => {
    _text = text;
    _mode = mode === 'append' ? 'append' : 'create';

    const lightbox    = document.getElementById('save-msg-lightbox');
    const titleEl     = document.getElementById('save-msg-title');
    const nameGroup   = document.getElementById('save-msg-name-group');
    const nameInput   = document.getElementById('save-msg-name');
    const treeLabel   = document.getElementById('save-msg-tree-label');
    const spaceSelect = document.getElementById('save-msg-space-select');
    const treeEl      = document.getElementById('save-msg-file-tree');
    const confirmBtn  = document.getElementById('save-msg-confirm-btn');
    if (!lightbox) return;

    const isAppend = _mode === 'append';
    titleEl.textContent    = t(isAppend ? 'chat.save.title-append' : 'chat.save.title-save');
    confirmBtn.textContent = t(isAppend ? 'chat.save.confirm-append' : 'chat.save.confirm');
    treeLabel.textContent  = t(isAppend ? 'chat.save.page-label' : 'chat.save.folder-label');
    // Append targets an existing page (picked from the tree); create needs a
    // new filename in a chosen folder.
    nameGroup.classList.toggle('hidden', isAppend);
    nameInput.value = '';

    // Populate spaces, defaulting to the current one.
    const spaces = await api.call('list_spaces');
    spaceSelect.innerHTML = '';
    if (spaces.success && spaces.data?.length) {
        for (const sp of spaces.data) {
            const opt = document.createElement('option');
            opt.value = sp;
            opt.textContent = sp;
            if (sp === state.currentSpace) opt.selected = true;
            spaceSelect.appendChild(opt);
        }
    }

    await loadTree(spaceSelect, treeEl, _mode);
    lightbox.classList.remove('hidden');
    if (!isAppend) nameInput.focus();
};

export const init = () => {
    const lightbox    = document.getElementById('save-msg-lightbox');
    const closeBtn    = document.getElementById('save-msg-close-btn');
    const nameInput   = document.getElementById('save-msg-name');
    const spaceSelect = document.getElementById('save-msg-space-select');
    const treeEl      = document.getElementById('save-msg-file-tree');
    const confirmBtn  = document.getElementById('save-msg-confirm-btn');
    if (!lightbox) return;

    const close = () => lightbox.classList.add('hidden');

    spaceSelect.addEventListener('change', () => loadTree(spaceSelect, treeEl, _mode));

    treeEl.addEventListener('click', (e) => {
        const target = e.target.closest('.file-item-content');
        if (!target) return;
        const isFolder = target.dataset.kind === 'folder';

        // Folders expand/collapse. In 'create' the active folder is the target;
        // in 'append' only a page (file) can be the active target.
        const selectable = _mode === 'create' ? isFolder : !isFolder;
        if (selectable) {
            treeEl.querySelectorAll('.file-item-content.active').forEach(el => el.classList.remove('active'));
            target.classList.add('active');
        }

        if (isFolder) {
            const childUl = target.parentElement.querySelector('ul');
            const iconEl  = target.querySelector('.folder-icon');
            if (childUl) {
                const isHidden = childUl.style.display === 'none';
                childUl.style.display = isHidden ? 'block' : 'none';
                iconEl.innerHTML = isHidden ? icons.folderOpen : icons.folder;
            }
        }
    });

    const submit = async () => {
        const space = spaceSelect.value || state.currentSpace;
        let path;

        if (_mode === 'append') {
            const active = treeEl.querySelector('.file-item-content.active[data-kind="file"]');
            if (!active) { showToast(t('chat.save.no-page'), 'error'); return; }
            path = active.dataset.path;
        } else {
            let name = nameInput.value.trim();
            if (!name) { showToast(t('chat.save.no-name'), 'error'); return; }
            if (!name.endsWith('.md')) name += '.md';
            const destFolder = treeEl.querySelector('.file-item-content.active')?.dataset.path || '';
            path = (destFolder ? destFolder + '/' : '') + name;
        }

        confirmBtn.disabled = true;
        const res = await api.call('save_message_page', { space, path, text: _text, mode: _mode }, 'POST');
        confirmBtn.disabled = false;

        if (!res.success) { showToast(res.message || t('chat.save.failed'), 'error'); return; }

        showToast(t(_mode === 'append' ? 'chat.save.appended' : 'chat.save.saved'), 'success');
        close();

        // If the page landed in the space we're viewing, reveal & open it.
        if (space === state.currentSpace) {
            await refreshFileTree();
            const fileEl = document.querySelector(`[data-path="${res.path}"]`);
            if (fileEl) { revealAndSelectFile(res.path); loadPage(res.path, fileEl.dataset.id, []); }
        }
    };

    confirmBtn.addEventListener('click', submit);
    closeBtn.addEventListener('click', close);
    lightbox.addEventListener('click', (e) => { if (e.target === lightbox) close(); });
    nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
};
