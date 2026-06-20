import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast } from '../core/utils.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';
import { loadPage } from '../page_view/index.js';
import { t } from '../i18n/index.js';

const renderFolderTree = (items, parent) => {
    items.forEach(item => {
        if (item.type !== 'folder') return;
        const li = document.createElement('li');
        li.className = 'file-item';
        li.innerHTML = `<div class="file-item-content" data-path="${item.path}"><span class="file-item-name"><span class="folder-icon">${icons.folder}</span><span>${item.name}</span></span></div>`;
        if (item.children?.length > 0) {
            const childrenUl = document.createElement('ul');
            childrenUl.style.display = 'none';
            renderFolderTree(item.children, childrenUl);
            li.appendChild(childrenUl);
        }
        parent.appendChild(li);
    });
};

const loadFolderTree = async (spaceSelect, copyFileTree) => {
    const selectedSpace = spaceSelect.value;
    const params = selectedSpace ? { space: selectedSpace } : {};
    const result = await api.call('list', params);
    if (!result.success) return;

    copyFileTree.innerHTML = '';
    const ul = document.createElement('ul');
    const rootLi = document.createElement('li');
    rootLi.className = 'file-item';
    rootLi.innerHTML = `<div class="file-item-content active" data-path=""><span class="file-item-name"><span class="folder-icon">${icons.folderOpen}</span><span>${t('fileops.root')}</span></span></div>`;
    ul.appendChild(rootLi);
    renderFolderTree(result.data, ul);
    copyFileTree.appendChild(ul);
};

export const openCopyLightbox = async () => {
    const spaceSelect = document.getElementById('copy-space-select');
    const copyFileTree = document.getElementById('copy-file-tree');

    // Populate space selector
    const spacesResult = await api.call('list_spaces');
    spaceSelect.innerHTML = '';
    if (spacesResult.success && spacesResult.data?.length > 0) {
        for (const sp of spacesResult.data) {
            const opt = document.createElement('option');
            opt.value = sp;
            opt.textContent = sp;
            if (sp === state.currentSpace) opt.selected = true;
            spaceSelect.appendChild(opt);
        }
    }

    await loadFolderTree(spaceSelect, copyFileTree);
    document.getElementById('copy-lightbox').classList.remove('hidden');
};

export const init = () => {
    const copyLightbox = document.getElementById('copy-lightbox');
    const copyLightboxCloseBtn = document.getElementById('copy-lightbox-close-btn');
    const copyFileTree = document.getElementById('copy-file-tree');
    const copyNewNameInput = document.getElementById('copy-new-name');
    const copyConfirmBtn = document.getElementById('copy-confirm-btn');
    const spaceSelect = document.getElementById('copy-space-select');

    const close = () => copyLightbox.classList.add('hidden');

    spaceSelect.addEventListener('change', () => loadFolderTree(spaceSelect, copyFileTree));

    copyFileTree.addEventListener('click', (e) => {
        const contentTarget = e.target.closest('.file-item-content');
        if (!contentTarget) return;
        document.querySelectorAll('#copy-file-tree .file-item-content.active').forEach(el => el.classList.remove('active'));
        contentTarget.classList.add('active');
        const childUl = contentTarget.parentElement.querySelector('ul');
        const iconEl = contentTarget.querySelector('.folder-icon');
        if (childUl) {
            const isHidden = childUl.style.display === 'none';
            childUl.style.display = isHidden ? 'block' : 'none';
            iconEl.innerHTML = isHidden ? icons.folderOpen : icons.folder;
        }
    });

    copyConfirmBtn.addEventListener('click', async () => {
        const newName = copyNewNameInput.value.trim();
        if (!newName) { showToast(t('copy.no-name'), 'error'); return; }
        const activeDest = copyFileTree.querySelector('.file-item-content.active');
        const destFolder = activeDest?.dataset.path || '';
        const ext = state.sourcePathToCopy.match(/\.(md|drawio|list|chat)$/)?.[0] || '.md';
        const newPath = (destFolder ? destFolder + '/' : '') + newName + ext;
        const targetSpace = spaceSelect.value || state.currentSpace;
        const isCrossSpace = targetSpace !== state.currentSpace;

        const params = { source_path: state.sourcePathToCopy, new_path: newPath };
        if (isCrossSpace) params.target_space = targetSpace;

        const result = await api.call('copy_page', params, 'POST');
        if (result.success) {
            showToast(t('fileops.copied'), 'success');
            close();
            if (!isCrossSpace) {
                await refreshFileTree();
                const newFileEl = document.querySelector(`[data-path="${newPath}"]`);
                if (newFileEl) { revealAndSelectFile(newPath); loadPage(newPath, newFileEl.dataset.id, []); }
            }
        }
    });

    copyLightboxCloseBtn.addEventListener('click', close);
    copyLightbox.addEventListener('click', (e) => { if (e.target === copyLightbox) close(); });
};
