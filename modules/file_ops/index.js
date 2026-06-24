import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, promptModal, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';
import { loadPage } from '../page_view/index.js';
import { openCopyLightbox, init as initCopy } from './copy.js';
import { openMoveLightbox, init as initMove } from './move.js';
import { t } from '../i18n/index.js';

export const handleRename = async () => {
    if (!state.currentPagePath) return;
    const oldName = state.currentPagePath.split('/').pop();
    const oldDisplayName = oldName.replace(/\.(md|drawio|list|chat)$/, '');
    const typeIcon = { file: icons.file, diagram: icons.diagram, list: icons.list, chat: icons.chat }[state.currentPageType] || icons.file;
    let newName = await promptModal(t('fileops.rename-title', { name: oldDisplayName }), oldDisplayName, '', typeIcon);
    if (!newName || newName === oldDisplayName) return;

    if (state.currentPageType === 'file') newName += '.md';
    else if (state.currentPageType === 'diagram') newName += '.drawio';
    else if (state.currentPageType === 'list') newName += '.list';
    else if (state.currentPageType === 'chat') newName += '.chat';

    const pathParts = state.currentPagePath.split('/');
    pathParts.pop();
    const newPath = (pathParts.length > 0 ? pathParts.join('/') + '/' : '') + newName;

    const res = await api.call('move', { old_path: state.currentPagePath, new_path: newPath }, 'POST');
    if (res.success) {
        showToast(t('fileops.renamed'), 'success');
        // Update path immediately so any active chat poll stops before it requests the old path
        const savedId   = state.currentPageId;
        const savedTags = state.currentPageTags;
        state.currentPagePath = newPath;
        await refreshFileTree();
        if (state.currentPageType === 'chat') {
            // Reload the chat at the new path to restart polling correctly
            await loadPage(newPath, savedId, savedTags);
        } else {
            document.getElementById('current-page-title').textContent = newPath.replace(/\.(md|drawio|list|chat)$/, '');
        }
        revealAndSelectFile(newPath);
    }
};

export const handleDelete = async () => {
    if (!state.currentPagePath) return;
    const displayName = state.currentPagePath.replace(/\.(md|drawio|json)$/, '');
    if (!await confirmModal(t('fileops.delete-confirm', { name: displayName }), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;

    api.call('delete', { path: state.currentPagePath }, 'POST').then(async res => {
        if (res.success) {
            showToast(t('fileops.deleted'), 'success');
            await refreshFileTree();
            const startResult = await api.call('get_start_page');
            if (startResult.success) {
                await loadPage(startResult.path, startResult.id, []);
                revealAndSelectFile(startResult.path);
            }
        }
    });
};

const handleCopy = () => {
    if (!state.currentPagePath) return;
    state.sourcePathToCopy = state.currentPagePath;
    const currentName = state.currentPagePath.split('/').pop().replace(/\.(md|drawio|list|chat)$/, '');
    document.getElementById('copy-new-name').value = `${currentName} ${t('fileops.copy-suffix')}`;
    openCopyLightbox();
};

const handleMove = () => {
    if (!state.currentPagePath) return;
    state.sourcePathToMove = state.currentPagePath;
    openMoveLightbox();
};

const handleBacklinks = async () => {
    if (!state.currentPageId) return;
    const listEl    = document.getElementById('backlinks-list');
    const subtitleEl = document.getElementById('backlinks-lightbox-subtitle');
    const lb        = document.getElementById('backlinks-lightbox');

    const name = (state.currentPagePath || '').split('/').pop().replace(/\.(md|drawio|list|chat)$/, '');
    subtitleEl.textContent = t('backlinks.subtitle', { name, space: state.currentSpace });
    listEl.innerHTML = `<span class="backlinks-empty">${t('backlinks.loading')}</span>`;
    lb.classList.remove('hidden');

    const res = await api.call('get_backlinks', { pageid: state.currentPageId });
    listEl.innerHTML = '';

    if (!res.success || !res.backlinks.length) {
        listEl.innerHTML = `<span class="backlinks-empty">${t('backlinks.empty')}</span>`;
        return;
    }

    for (const bl of res.backlinks) {
        const a = document.createElement('a');
        a.className = 'backlinks-item';
        a.href = '#';
        a.innerHTML = `${icons.file}<span>${bl.title}</span>`;
        a.addEventListener('click', async (e) => {
            e.preventDefault();
            lb.classList.add('hidden');
            const { loadPage } = await import('../page_view/index.js');
            const { revealAndSelectFile } = await import('../file_tree/index.js');
            await loadPage(bl.path, bl.id, []);
            revealAndSelectFile(bl.path);
        });
        listEl.appendChild(a);
    }
};

const alignPrintLightbox = () => {
    const lb = document.getElementById('print-lightbox');
    const sidebar = document.querySelector('.sidebar');
    const app = document.querySelector('.app-container');
    if (!lb || !sidebar || !app) return;
    const sr = sidebar.getBoundingClientRect();
    const ar = app.getBoundingClientRect();
    lb.style.left = Math.round(sr.width) + 'px';
};

export const closePrintLightbox = () => {
    document.getElementById('print-lightbox').classList.add('hidden');
    const body = document.getElementById('print-lightbox-body');
    if (body) body.innerHTML = '';
};

const handlePrint = async () => {
    if (!state.currentPagePath) return;
    const type = state.currentPageType;

    const lb     = document.getElementById('print-lightbox');
    const body   = document.getElementById('print-lightbox-body');
    const titleEl = document.getElementById('print-lightbox-title');
    if (!lb || !body) return;

    body.innerHTML = '';

    if (type === 'file') {
        const vc = document.getElementById('viewer-content');
        body.innerHTML = vc ? vc.innerHTML : '';
    } else if (type === 'list') {
        const tbl = document.querySelector('#list-items-table .list-table');
        body.innerHTML = tbl ? tbl.outerHTML : '';
    } else if (type === 'diagram') {
        const res = await api.call('get_diagram_svg', { file: state.currentPagePath });
        if (res.success && res.svg) {
            const img = document.createElement('img');
            img.src = 'data:image/svg+xml;base64,' + res.svg;
            img.style.cssText = 'max-width:100%;height:auto;display:block;';
            body.appendChild(img);
        } else {
            body.innerHTML = '<p style="color:#666">No preview available — open the diagram and save it to generate one.</p>';
        }
    } else {
        return;
    }

    const title = state.currentPagePath.split('/').pop().replace(/\.(md|drawio|list)$/, '');
    titleEl.textContent = title;
    alignPrintLightbox();
    lb.classList.remove('hidden');
};

export const init = () => {
    initCopy();
    initMove();

    document.getElementById('copy-btn').addEventListener('click', handleCopy);
    document.getElementById('move-btn').addEventListener('click', handleMove);
    document.getElementById('rename-btn').addEventListener('click', handleRename);
    document.getElementById('backlinks-btn').addEventListener('click', handleBacklinks);
    document.getElementById('print-btn').addEventListener('click', handlePrint);
    document.getElementById('delete-btn').addEventListener('click', handleDelete);

    document.getElementById('backlinks-lightbox-close-btn').addEventListener('click', () => {
        document.getElementById('backlinks-lightbox').classList.add('hidden');
    });

    document.getElementById('print-lightbox-close-btn').addEventListener('click', closePrintLightbox);
    document.getElementById('print-lightbox-print-btn').addEventListener('click', () => window.print());
    window.addEventListener('resize', alignPrintLightbox);

    const menuBtn = document.getElementById('file-actions-menu-btn');
    const menu    = document.getElementById('file-actions-menu');
    menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        menu.classList.toggle('hidden');
    });
    menu.addEventListener('click', () => menu.classList.add('hidden'));
    document.addEventListener('click', () => menu.classList.add('hidden'));
};
