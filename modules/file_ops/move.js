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

const loadFolderTree = async (spaceSelect, moveFileTree) => {
    const selectedSpace = spaceSelect.value;
    const params = selectedSpace ? { space: selectedSpace } : {};
    const result = await api.call('list', params);
    if (!result.success) return;

    moveFileTree.innerHTML = '';
    const ul = document.createElement('ul');
    const rootLi = document.createElement('li');
    rootLi.className = 'file-item';
    rootLi.innerHTML = `<div class="file-item-content active" data-path=""><span class="file-item-name"><span class="folder-icon">${icons.folderOpen}</span><span>${t('fileops.root')}</span></span></div>`;
    ul.appendChild(rootLi);
    renderFolderTree(result.data, ul);
    moveFileTree.appendChild(ul);
};

export const openMoveLightbox = async () => {
    const spaceSelect = document.getElementById('move-space-select');
    const moveFileTree = document.getElementById('move-file-tree');

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

    await loadFolderTree(spaceSelect, moveFileTree);
    document.getElementById('move-lightbox').classList.remove('hidden');
};

export const init = () => {
    const moveLightbox = document.getElementById('move-lightbox');
    const moveLightboxCloseBtn = document.getElementById('move-lightbox-close-btn');
    const moveFileTree = document.getElementById('move-file-tree');
    const moveConfirmBtn = document.getElementById('move-confirm-btn');
    const spaceSelect = document.getElementById('move-space-select');

    const close = () => moveLightbox.classList.add('hidden');

    spaceSelect.addEventListener('change', () => loadFolderTree(spaceSelect, moveFileTree));

    moveFileTree.addEventListener('click', (e) => {
        const contentTarget = e.target.closest('.file-item-content');
        if (!contentTarget) return;
        document.querySelectorAll('#move-file-tree .file-item-content.active').forEach(el => el.classList.remove('active'));
        contentTarget.classList.add('active');
        const childUl = contentTarget.parentElement.querySelector('ul');
        const iconEl = contentTarget.querySelector('.folder-icon');
        if (childUl) {
            const isHidden = childUl.style.display === 'none';
            childUl.style.display = isHidden ? 'block' : 'none';
            iconEl.innerHTML = isHidden ? icons.folderOpen : icons.folder;
        }
    });

    moveConfirmBtn.addEventListener('click', async () => {
        const activeDest = moveFileTree.querySelector('.file-item-content.active');
        const destFolder = activeDest?.dataset.path || '';
        const fileName = state.sourcePathToMove.split('/').pop();
        const newPath = (destFolder ? destFolder + '/' : '') + fileName;
        const targetSpace = spaceSelect.value || state.currentSpace;
        const isCrossSpace = targetSpace !== state.currentSpace;

        if (!isCrossSpace && newPath === state.sourcePathToMove) { close(); return; }

        const params = { old_path: state.sourcePathToMove, new_path: newPath };
        if (isCrossSpace) params.target_space = targetSpace;

        const result = await api.call('move', params, 'POST');
        if (result.success) {
            showToast(t('fileops.moved'), 'success');
            close();
            await refreshFileTree();
            if (isCrossSpace && state.currentPagePath === state.sourcePathToMove) {
                // Item moved away — load start page
                const startResult = await api.call('get_start_page');
                if (startResult.success) {
                    await loadPage(startResult.path, startResult.id, []);
                    revealAndSelectFile(startResult.path);
                }
            } else {
                if (state.currentPagePath === state.sourcePathToMove) state.currentPagePath = newPath;
                revealAndSelectFile(newPath);
            }
        }
    });

    moveLightboxCloseBtn.addEventListener('click', close);
    moveLightbox.addEventListener('click', (e) => { if (e.target === moveLightbox) close(); });
};
