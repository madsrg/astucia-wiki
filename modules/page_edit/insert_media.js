import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { insertMarkdown } from './editor.js';
import { showToast } from '../core/utils.js';

const IMAGE_EXTS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif']);
const isImageFile = (name) => IMAGE_EXTS.has(name.split('.').pop().toLowerCase());

// Build a filtered DOM file tree; returns <ul> or null if nothing matches.
const buildFilteredTree = (items, filterFn) => {
    const ul = document.createElement('ul');
    ul.className = 'file-tree';
    let hasAny = false;

    for (const item of items) {
        if (item.type === 'folder') {
            const subUl = buildFilteredTree(item.children || [], filterFn);
            if (!subUl) continue;
            const li = document.createElement('li');
            li.className = 'file-item';
            const div = document.createElement('div');
            div.className = 'file-item-content';
            div.dataset.type = 'folder';
            div.innerHTML = `<span class="file-item-name"><span class="folder-icon">${icons.folder}</span><span>${item.name}</span></span>`;
            subUl.style.display = 'none';
            div.addEventListener('click', () => {
                const collapsed = subUl.style.display === 'none';
                subUl.style.display = collapsed ? '' : 'none';
                div.querySelector('.folder-icon').innerHTML = collapsed ? icons.folderOpen : icons.folder;
            });
            li.appendChild(div);
            li.appendChild(subUl);
            ul.appendChild(li);
            hasAny = true;
        } else if (filterFn(item)) {
            const li = document.createElement('li');
            li.className = 'file-item';
            const div = document.createElement('div');
            div.className = 'file-item-content';
            const isDiagram = item.path?.endsWith('.drawio');
            const isList = item.path?.endsWith('.list');
            div.dataset.type = isDiagram ? 'diagram' : (isList ? 'list' : 'file');
            div.dataset.id = item.id || '';
            div.dataset.path = item.path || '';
            const displayName = (item.name || '').replace(/\.(md|drawio|list)$/, '');
            const icon = isDiagram ? icons.diagram : (isList ? icons.list : icons.file);
            div.innerHTML = `<span class="file-item-name">${icon}<span>${displayName}</span></span>`;
            li.appendChild(div);
            ul.appendChild(li);
            hasAny = true;
        }
    }
    return hasAny ? ul : null;
};

// ── A: Include Page ──────────────────────────────────────────────────────────

export const openIncludeLightbox = async () => {
    const result = await api.call('list');
    if (!result.success) return;
    const container = document.getElementById('include-file-tree');
    container.innerHTML = '';
    const tree = buildFilteredTree(result.data, item => item.path?.endsWith('.md'));
    if (tree) container.appendChild(tree);
    else container.innerHTML = '<p class="insert-empty-msg">No markdown pages found.</p>';
    document.getElementById('include-lightbox').classList.remove('hidden');
};

// ── B: Insert Image ──────────────────────────────────────────────────────────

let selectedImagePath = null;

const selectImage = (path, name) => {
    document.querySelectorAll('.image-attachment-item').forEach(b => {
        b.classList.toggle('selected', b.dataset.path === path);
    });
    selectedImagePath = path;
    document.getElementById('selected-image-name').textContent = name;
    document.getElementById('insert-image-selected').classList.remove('hidden');
    document.getElementById('insert-image-confirm-btn').disabled = false;
};

const renderImageList = async (autoSelectPath = null) => {
    const listEl = document.getElementById('image-attachment-list');
    listEl.innerHTML = '';
    if (!state.currentPagePath) return;
    const result = await api.call('list_attachments', { page_path: state.currentPagePath });
    if (!result.success) return;
    const images = result.data.filter(isImageFile);
    if (images.length === 0) {
        listEl.innerHTML = '<p class="insert-empty-msg">No images attached to this page yet.</p>';
        return;
    }
    images.forEach(filename => {
        const path = state.currentPagePath + '.uploads/' + filename;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'image-attachment-item';
        btn.textContent = filename;
        btn.dataset.path = path;
        btn.addEventListener('click', () => selectImage(path, filename));
        listEl.appendChild(btn);
        if (path === autoSelectPath) selectImage(path, filename);
    });
};

export const openImageLightbox = async () => {
    selectedImagePath = null;
    document.getElementById('insert-image-selected').classList.add('hidden');
    document.getElementById('insert-image-confirm-btn').disabled = true;
    document.getElementById('insert-image-width').value = '';
    document.getElementById('insert-image-height').value = '';
    await renderImageList();
    document.getElementById('insert-image-lightbox').classList.remove('hidden');
};

// ── C: Insert Diagram ────────────────────────────────────────────────────────

export const openDiagramInsertLightbox = async () => {
    const result = await api.call('list');
    if (!result.success) return;
    const container = document.getElementById('insert-diagram-tree');
    container.innerHTML = '';
    const tree = buildFilteredTree(result.data, item => item.path?.endsWith('.drawio'));
    if (tree) container.appendChild(tree);
    else container.innerHTML = '<p class="insert-empty-msg">No diagrams found.</p>';
    document.getElementById('insert-diagram-lightbox').classList.remove('hidden');
};

// ── D: Insert List ───────────────────────────────────────────────────────────

let pendingListId = null;

const showListViewPicker = async (id, path) => {
    pendingListId = id;
    const contentResult = await api.call('get', { file: path });
    if (!contentResult.success || !contentResult.data) return;
    const listData = JSON.parse(contentResult.data);
    const views = listData.views || [];
    const viewsEl = document.getElementById('insert-list-views');
    viewsEl.innerHTML = '';

    const makeOption = (value, label, checked) => {
        const lbl = document.createElement('label');
        lbl.className = 'insert-list-view-item';
        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'insert-list-view';
        radio.value = value;
        radio.checked = checked;
        lbl.appendChild(radio);
        lbl.appendChild(document.createTextNode(' ' + label));
        viewsEl.appendChild(lbl);
    };

    makeOption('', 'All Items', true);
    views.forEach(v => makeOption(v.name, v.name, false));

    document.getElementById('insert-list-step-1').classList.add('hidden');
    document.getElementById('insert-list-step-2').classList.remove('hidden');
};

export const openListInsertLightbox = async () => {
    const result = await api.call('list');
    if (!result.success) return;
    const container = document.getElementById('insert-list-tree');
    container.innerHTML = '';
    const tree = buildFilteredTree(result.data, item => item.path?.endsWith('.list'));
    if (tree) container.appendChild(tree);
    else container.innerHTML = '<p class="insert-empty-msg">No lists found.</p>';
    document.getElementById('insert-list-step-1').classList.remove('hidden');
    document.getElementById('insert-list-step-2').classList.add('hidden');
    document.getElementById('insert-list-lightbox').classList.remove('hidden');
};

// ── init ─────────────────────────────────────────────────────────────────────

export const init = () => {
    const closeEl = (id) => document.getElementById(id).classList.add('hidden');

    // A: Include Page
    const includeLightbox = document.getElementById('include-lightbox');
    document.getElementById('include-lightbox-close-btn').addEventListener('click', () => closeEl('include-lightbox'));
    includeLightbox.addEventListener('click', e => { if (e.target === includeLightbox) closeEl('include-lightbox'); });
    document.getElementById('include-file-tree').addEventListener('click', e => {
        const item = e.target.closest('[data-type="file"]');
        if (!item?.dataset.id) return;
        insertMarkdown(`{include:${item.dataset.id}}`);
        closeEl('include-lightbox');
    });

    // B: Insert Image
    const imageLightbox = document.getElementById('insert-image-lightbox');
    document.getElementById('insert-image-close-btn').addEventListener('click', () => closeEl('insert-image-lightbox'));
    imageLightbox.addEventListener('click', e => { if (e.target === imageLightbox) closeEl('insert-image-lightbox'); });

    const imageUploadInput = document.getElementById('image-upload-input');
    document.getElementById('upload-image-btn').addEventListener('click', () => imageUploadInput.click());
    imageUploadInput.addEventListener('change', async () => {
        const file = imageUploadInput.files[0];
        if (!file || !state.currentPagePath) return;
        const formData = new FormData();
        formData.append('file', file);
        formData.append('page_path', state.currentPagePath);
        const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const resp = await fetch(`api.php?action=upload_attachment${spaceQs}`, { method: 'POST', body: formData });
        const result = await resp.json();
        if (result.success) {
            showToast('Image uploaded!', 'success');
            await renderImageList(state.currentPagePath + '.uploads/' + file.name);
        } else {
            showToast(result.message || 'Upload failed.', 'error');
        }
        imageUploadInput.value = '';
    });

    document.getElementById('insert-image-confirm-btn').addEventListener('click', () => {
        if (!selectedImagePath) return;
        const w = document.getElementById('insert-image-width').value.trim();
        const h = document.getElementById('insert-image-height').value.trim();
        const _sp = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const url = `getfile.php?path=${encodeURIComponent(selectedImagePath)}${_sp}`;
        const filename = selectedImagePath.split('/').pop();
        let md;
        if (w || h) {
            const wAttr = w ? ` width="${w}"` : '';
            const hAttr = h ? ` height="${h}"` : '';
            md = `<img src="${url}" alt="${filename}"${wAttr}${hAttr}>`;
        } else {
            md = `![${filename}](${url})`;
        }
        insertMarkdown(md);
        closeEl('insert-image-lightbox');
    });

    // C: Insert Diagram
    const diagramInsertLightbox = document.getElementById('insert-diagram-lightbox');
    document.getElementById('insert-diagram-close-btn').addEventListener('click', () => closeEl('insert-diagram-lightbox'));
    diagramInsertLightbox.addEventListener('click', e => { if (e.target === diagramInsertLightbox) closeEl('insert-diagram-lightbox'); });
    document.getElementById('insert-diagram-tree').addEventListener('click', e => {
        const item = e.target.closest('[data-type="diagram"]');
        if (!item?.dataset.id) return;
        insertMarkdown(`{diagram:${item.dataset.id}}`);
        closeEl('insert-diagram-lightbox');
    });

    // D: Insert List
    const listInsertLightbox = document.getElementById('insert-list-lightbox');
    document.getElementById('insert-list-close-btn').addEventListener('click', () => closeEl('insert-list-lightbox'));
    listInsertLightbox.addEventListener('click', e => { if (e.target === listInsertLightbox) closeEl('insert-list-lightbox'); });
    document.getElementById('insert-list-tree').addEventListener('click', e => {
        const item = e.target.closest('[data-type="list"]');
        if (!item?.dataset.id || !item?.dataset.path) return;
        showListViewPicker(item.dataset.id, item.dataset.path);
    });
    document.getElementById('insert-list-back-btn').addEventListener('click', () => {
        document.getElementById('insert-list-step-1').classList.remove('hidden');
        document.getElementById('insert-list-step-2').classList.add('hidden');
    });
    document.getElementById('insert-list-confirm-btn').addEventListener('click', () => {
        if (!pendingListId) return;
        const selected = document.querySelector('#insert-list-views input[name="insert-list-view"]:checked');
        const viewName = selected?.value ?? '';
        const tag = viewName ? `{list:${pendingListId}:${viewName}}` : `{list:${pendingListId}}`;
        insertMarkdown(tag);
        closeEl('insert-list-lightbox');
    });
};
