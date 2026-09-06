// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal, canUpload } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { updateBreadcrumb, updateFavoriteBtn } from '../nav/index.js';


// ── File type icons ───────────────────────────────────────────────────────────

const TYPE_COLORS = {
    pdf: '#e53e3e',
    doc: '#3182ce', docx: '#3182ce',
    xls: '#38a169', xlsx: '#38a169', csv: '#38a169',
    ppt: '#dd6b20', pptx: '#dd6b20',
    jpg: '#00b5d8', jpeg: '#00b5d8', png: '#00b5d8', gif: '#00b5d8',
    webp: '#00b5d8', bmp: '#00b5d8', avif: '#00b5d8', svg: '#9f7aea',
    zip: '#d69e2e', tar: '#d69e2e', gz: '#d69e2e', rar: '#d69e2e', '7z': '#d69e2e',
    txt: '#718096', md: '#718096', log: '#718096',
    json: '#805ad5', xml: '#805ad5', yaml: '#805ad5', yml: '#805ad5',
    html: '#e53e3e', htm: '#e53e3e', css: '#3182ce',
    js: '#d69e2e', ts: '#3182ce', jsx: '#d69e2e', tsx: '#3182ce',
    py: '#48bb78', php: '#9f7aea', rb: '#e53e3e', sh: '#718096', sql: '#3182ce',
    mp4: '#805ad5', avi: '#805ad5', mov: '#805ad5', mkv: '#805ad5', webm: '#805ad5',
    mp3: '#d53f8c', wav: '#d53f8c', flac: '#d53f8c', aac: '#d53f8c', ogg: '#d53f8c',
};

const fileIcon = (name, large = false) => {
    const ext = name.split('.').pop().toLowerCase();
    const color = TYPE_COLORS[ext] || '#a0aec0';
    const label = ext.toUpperCase().slice(0, 4);
    if (large) {
        return `<svg xmlns="http://www.w3.org/2000/svg" width="44" height="52" viewBox="0 0 44 52">
          <polygon points="0,0 30,0 44,14 44,52 0,52" fill="#f7fafc" stroke="#cbd5e0" stroke-width="1.5"/>
          <polygon points="30,0 44,14 30,14" fill="#cbd5e0"/>
          <rect x="0" y="34" width="44" height="18" fill="${color}"/>
          <text x="22" y="47" text-anchor="middle" fill="white" font-family="Arial,sans-serif" font-size="9" font-weight="bold">${label}</text>
        </svg>`;
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" width="22" height="26" viewBox="0 0 22 26">
      <polygon points="0,0 15,0 22,7 22,26 0,26" fill="#f7fafc" stroke="#cbd5e0" stroke-width="1"/>
      <polygon points="15,0 22,7 15,7" fill="#cbd5e0"/>
      <rect x="0" y="17" width="22" height="9" fill="${color}"/>
      <text x="11" y="24" text-anchor="middle" fill="white" font-family="Arial,sans-serif" font-size="6" font-weight="bold">${label}</text>
    </svg>`;
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtSize = (b) => b < 1024 ? `${b} B` : b < 1048576 ? `${(b/1024).toFixed(1)} KB` : `${(b/1048576).toFixed(1)} MB`;
const fmtDate = (t) => new Date(t * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

let currentViewMode = localStorage.getItem('ff_view') || 'simple';

// Which listing the pane is currently showing. Both use the same container, the same
// three view modes and the same toolbar, so the view-mode buttons need to know which
// one to re-render.
let paneMode   = 'library';   // 'library' (a *.uploads-style files folder) | 'folder' (a wiki folder)
let folderPath = '';          // the wiki folder being listed, when paneMode === 'folder'

const setActiveViewBtn = () => {
    ['simple', 'detailed', 'icons'].forEach(m => {
        const btn = document.getElementById(`ff-view-${m}`);
        if (!btn) return;
        btn.classList.toggle('btn-blue', m === currentViewMode);
        btn.classList.toggle('btn-secondary', m !== currentViewMode);
    });
};

/**
 * Shows the listing pane and hides everything page-scoped. Every other content
 * container is hidden here, not just the two the files library used to know about:
 * they are siblings, so arriving from a .chat or .json page left that page on screen
 * underneath.
 */
const showListingPane = (mode) => {
    ['viewer-container', 'list-view-container', 'chat-view-container',
     'search-view-container', 'json-view-container'].forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));
    document.querySelector('.editor-container-wrapper')?.classList.add('hidden');
    document.getElementById('files-folder-container').classList.remove('hidden');

    ['page-id-display', 'diagram-edit-btn', 'editor-mode-group', 'save-btn', 'cancel-btn',
     'search-btn', 'page-meta-row', 'copy-btn', 'backlinks-btn', 'print-btn', 'toc-btn',
     'page-chat-btn', 'share-btn', 'chat-topic-btn', 'graph-focus-btn', 'git-history-btn',
     'git-commit-toggle-btn', 'git-snapshot-btn'].forEach(id =>
        document.getElementById(id)?.classList.add('hidden'));

    const editBtn = document.getElementById('edit-btn');
    editBtn.classList.add('hidden');
    editBtn.disabled = true;
    document.getElementById('page-actions-group').classList.remove('hidden');
    // A wiki folder is not a file: it can be moved, but there is nothing to upload into
    // it here (pages are dropped on the tree) and nothing to delete row by row. What it
    // does take is new content, which is what the rail hides the sidebar's New button for.
    document.getElementById('move-btn').classList.toggle('hidden', mode !== 'folder');
    document.getElementById('ff-upload-btn').classList.toggle('hidden', mode === 'folder');
    document.getElementById('ff-new')?.classList.toggle('hidden', mode !== 'folder');
    document.getElementById('ff-new-dropdown')?.classList.add('hidden');
    document.getElementById('ff-search')?.classList.toggle('hidden', mode !== 'folder');
    document.getElementById('ff-upload-pages-btn')
        ?.classList.toggle('hidden', mode !== 'folder' || !canUpload());
    document.getElementById('ff-folder-actions')?.classList.toggle('hidden', mode !== 'folder');
    document.getElementById('ff-folder-actions-menu')?.classList.add('hidden');
    // The folder's name is already in the breadcrumb directly above, so the title row is
    // a second copy of it taking a whole row; its actions button moves into the toolbar.
    // Hiding it is left to CSS keyed on this class *and* the pane being visible, because
    // every way out of the listing already hides the pane — loadPage, showBlankPage,
    // displaySearchResults, the tree's folder placeholder. A JS toggle would need undoing
    // in all four, and the row would stay hidden the first time one of them was missed.
    document.getElementById('files-folder-container').classList.toggle('ff-folder-mode', mode === 'folder');
    document.getElementById('page-actions-group').classList.toggle('hidden', mode === 'folder');
};

// ── Render ────────────────────────────────────────────────────────────────────

const renderFiles = (files) => {
    const container = document.getElementById('ff-file-list');
    container.innerHTML = '';

    if (!files.length) {
        container.innerHTML = `<p class="ff-empty">${t('files.empty')}</p>`;
        return;
    }

    const reload = () => loadFilesFolder(state.currentPagePath);

    const deleteFile = async (path, name) => {
        if (!await confirmModal(t('fileops.delete-confirm', { name }), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
        const res = await api.call('delete_folder_file', { path }, 'POST');
        if (res.success) { showToast(t('files.deleted'), 'success'); reload(); }
        else showToast(res.message || t('files.delete-failed'), 'error');
    };

    if (currentViewMode === 'simple') {
        const ul = document.createElement('ul');
        ul.className = 'ff-simple-list';
        files.forEach(f => {
            const li = document.createElement('li');
            li.className = 'ff-simple-item';
            const icon = document.createElement('span');
            icon.className = 'ff-icon-sm';
            icon.innerHTML = fileIcon(f.name);
            const link = document.createElement('a');
            link.href = `getfile.php?path=${encodeURIComponent(f.path)}`;
            link.target = '_blank';
            link.className = 'ff-filename';
            link.textContent = f.name;
            const del = document.createElement('button');
            del.className = 'btn ff-del-btn';
            del.title = t('btn.delete');
            del.textContent = '✕';
            del.addEventListener('click', () => deleteFile(f.path, f.name));
            li.append(icon, link, del);
            ul.appendChild(li);
        });
        container.appendChild(ul);

    } else if (currentViewMode === 'detailed') {
        const wrap = document.createElement('div');
        wrap.className = 'ff-detail-wrap';
        const table = document.createElement('table');
        table.className = 'ff-detail-table';
        const thead = document.createElement('thead');
        thead.innerHTML = `<tr><th>${t('files.col-name')}</th><th>${t('files.col-size')}</th><th>${t('files.col-modified')}</th><th></th></tr>`;
        const tbody = document.createElement('tbody');
        files.forEach(f => {
            const tr = document.createElement('tr');
            const tdName = document.createElement('td');
            tdName.className = 'ff-detail-name';
            const icon = document.createElement('span');
            icon.className = 'ff-icon-sm';
            icon.innerHTML = fileIcon(f.name);
            const link = document.createElement('a');
            link.href = `getfile.php?path=${encodeURIComponent(f.path)}`;
            link.target = '_blank';
            link.textContent = f.name;
            tdName.append(icon, link);
            const tdSize = document.createElement('td');
            tdSize.className = 'ff-detail-meta';
            tdSize.textContent = fmtSize(f.size);
            const tdDate = document.createElement('td');
            tdDate.className = 'ff-detail-meta';
            tdDate.textContent = fmtDate(f.mtime);
            const tdDel = document.createElement('td');
            const del = document.createElement('button');
            del.className = 'btn ff-del-btn';
            del.title = t('btn.delete');
            del.textContent = '✕';
            del.addEventListener('click', () => deleteFile(f.path, f.name));
            tdDel.appendChild(del);
            tr.append(tdName, tdSize, tdDate, tdDel);
            tbody.appendChild(tr);
        });
        table.append(thead, tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);

    } else { // icons
        const grid = document.createElement('div');
        grid.className = 'ff-icons-grid';
        files.forEach(f => {
            const item = document.createElement('div');
            item.className = 'ff-icon-item';
            const link = document.createElement('a');
            link.href = `getfile.php?path=${encodeURIComponent(f.path)}`;
            link.target = '_blank';
            link.className = 'ff-icon-link';
            link.title = f.name;
            const iconEl = document.createElement('span');
            iconEl.className = 'ff-icon-lg';
            iconEl.innerHTML = fileIcon(f.name, true);
            const nameEl = document.createElement('span');
            nameEl.className = 'ff-icon-name';
            nameEl.textContent = f.name.length > 18 ? f.name.slice(0, 17) + '…' : f.name;
            link.append(iconEl, nameEl);
            const del = document.createElement('button');
            del.className = 'ff-icon-del';
            del.title = t('btn.delete');
            del.textContent = '✕';
            del.addEventListener('click', () => deleteFile(f.path, f.name));
            item.append(link, del);
            grid.appendChild(item);
        });
        container.appendChild(grid);
    }
};

// ── Wiki folder listing ───────────────────────────────────────────────────────
//
// The same three view modes as the files library, over the contents of a wiki folder.
// Reached by clicking a breadcrumb segment while the sidebar is collapsed: the browse
// pane is off-screen then, so the folder is shown in the main area instead.

const CONTENT_EXT = /\.(md|drawio|list|chat|search|json)$/;

const KINDS = {
    folder:   { icon: 'folder',      label: 'folder.type-folder' },
    fileslib: { icon: 'filesFolder', label: 'folder.type-fileslib' },
    diagram:  { icon: 'diagram',     label: 'folder.type-diagram' },
    list:     { icon: 'list',        label: 'folder.type-list' },
    chat:     { icon: 'chat',        label: 'folder.type-chat' },
    search:   { icon: 'search',      label: 'folder.type-search' },
    json:     { icon: 'json',        label: 'folder.type-json' },
    page:     { icon: 'file',        label: 'folder.type-page' },
};

const kindOf = (item) => {
    if (item.type === 'folder')      return 'folder';
    if (item.type === 'filesfolder') return 'fileslib';
    if (item.name.endsWith('.drawio')) return 'diagram';
    if (item.name.endsWith('.list'))   return 'list';
    if (item.name.endsWith('.chat'))   return 'chat';
    if (item.name.endsWith('.search')) return 'search';
    if (item.name.endsWith('.json'))   return 'json';
    return 'page';
};

const isDir = (item) => item.type === 'folder' || item.type === 'filesfolder';
const entryName = (item) => item.name.replace(CONTENT_EXT, '');

// Directories first, then files, each A→Z. The server already sorts the tree this way,
// but with strcmp — so "Zebra" sorted before "apple". A listing a person reads wants
// case-insensitive, digit-aware order.
const sortEntries = (items) => [...items].sort((a, b) =>
    (isDir(a) ? 0 : 1) - (isDir(b) ? 0 : 1) ||
    entryName(a).localeCompare(entryName(b), undefined, { sensitivity: 'base', numeric: true }));

const openEntry = async (item) => {
    const kind = kindOf(item);
    const from = folderPath;
    // Dynamic: file_tree imports this module.
    const tree = await import('../file_tree/index.js');

    if (kind === 'folder')   return loadFolderView(item.path);
    if (kind === 'fileslib') { await loadFilesFolder(item.path); tree.revealAndSelectFile(item.path); return; }

    // Dynamic: page_view imports file_tree, which imports this module.
    const { loadPage } = await import('../page_view/index.js');
    await loadPage(item.path, item.id, item.tags || []);
    // Mark the selection in the sidebar even though it is off-screen: it is what the
    // reader sees the moment they expand it, and every other way of opening a page does
    // this. The browse pane only marks the active file when it is re-rendered, so both
    // panes are handled here.
    tree.revealAndSelectFile(item.path);
    tree.renderBrowsePane(tree.findItemsByPath(from), from);
};

/** The ".." row, as a pseudo-entry the three renderers can treat like any other. */
const upEntry = (path) => ({ up: true, name: '..', path: path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '' });

const renderFolder = (items, path) => {
    const container = document.getElementById('ff-file-list');
    container.innerHTML = '';

    // The ".." row is a row like any other, so an empty subfolder still offers a way out
    // — the "folder is empty" note is appended below rather than replacing the listing.
    const rows = path ? [upEntry(path), ...items] : [...items];

    const activate = (entry) => entry.up ? loadFolderView(entry.path) : openEntry(entry);
    const iconFor  = (entry, cls) => {
        const span = document.createElement('span');
        span.className = cls;
        span.innerHTML = entry.up ? icons.up : icons[KINDS[kindOf(entry)].icon];
        return span;
    };
    const labelFor = (entry) => entry.up ? '..' : entryName(entry);

    if (currentViewMode === 'simple') {
        const ul = document.createElement('ul');
        ul.className = 'ff-simple-list';
        rows.forEach(entry => {
            const li = document.createElement('li');
            li.className = 'ff-simple-item fv-row';
            li.title = entry.up ? t('folder.up') : entry.path;
            const name = document.createElement('span');
            name.className = 'ff-filename';
            name.textContent = labelFor(entry);
            li.append(iconFor(entry, 'ff-icon-sm'), name);
            if (entry.up || isDir(entry)) {
                const arrow = document.createElement('span');
                arrow.className = 'fv-arrow';
                arrow.textContent = entry.up ? '' : '\u203A';
                li.appendChild(arrow);
            }
            li.addEventListener('click', () => activate(entry));
            ul.appendChild(li);
        });
        container.appendChild(ul);

    } else if (currentViewMode === 'detailed') {
        const wrap = document.createElement('div');
        wrap.className = 'ff-detail-wrap';
        const table = document.createElement('table');
        table.className = 'ff-detail-table';
        const thead = document.createElement('thead');
        const htr = document.createElement('tr');
        [t('files.col-name'), t('folder.col-type'), t('files.col-modified')].forEach(label => {
            const th = document.createElement('th');
            th.textContent = label;
            htr.appendChild(th);
        });
        thead.appendChild(htr);
        const tbody = document.createElement('tbody');
        rows.forEach(entry => {
            const tr = document.createElement('tr');
            tr.className = 'fv-row';
            tr.title = entry.up ? t('folder.up') : entry.path;
            const tdName = document.createElement('td');
            tdName.className = 'ff-detail-name';
            const name = document.createElement('span');
            name.className = 'ff-filename';
            name.textContent = labelFor(entry);
            tdName.append(iconFor(entry, 'ff-icon-sm'), name);
            const tdType = document.createElement('td');
            tdType.className = 'ff-detail-meta';
            tdType.textContent = entry.up ? '' : t(KINDS[kindOf(entry)].label);
            const tdDate = document.createElement('td');
            tdDate.className = 'ff-detail-meta';
            // Folders carry no stamp in the index, and a page written outside the wiki
            // gets one on the next reconcile — an em dash beats a wrong date.
            tdDate.textContent = entry.up ? '' : (entry.updated ? fmtDate(entry.updated) : '—');
            tr.append(tdName, tdType, tdDate);
            tr.addEventListener('click', () => activate(entry));
            tbody.appendChild(tr);
        });
        table.append(thead, tbody);
        wrap.appendChild(table);
        container.appendChild(wrap);

    } else { // icons
        const grid = document.createElement('div');
        grid.className = 'ff-icons-grid';
        rows.forEach(entry => {
            const item = document.createElement('div');
            item.className = 'ff-icon-item fv-row';
            item.title = entry.up ? t('folder.up') : entry.path;
            const link = document.createElement('span');
            link.className = 'ff-icon-link';
            const name = document.createElement('span');
            name.className = 'ff-icon-name';
            const label = labelFor(entry);
            name.textContent = label.length > 18 ? label.slice(0, 17) + '…' : label;
            link.append(iconFor(entry, 'ff-icon-lg fv-icon-lg'), name);
            item.appendChild(link);
            item.addEventListener('click', () => activate(entry));
            grid.appendChild(item);
        });
        container.appendChild(grid);
    }

    if (!items.length) {
        const note = document.createElement('p');
        note.className = 'ff-empty';
        note.textContent = t('folder.empty');
        container.appendChild(note);
    }
};

/** True while the pane is showing a wiki folder, so a tree refresh knows to repaint it. */
export const isFolderViewActive = () =>
    paneMode === 'folder' && state.currentPageType === 'folder'
    && !document.getElementById('files-folder-container')?.classList.contains('hidden');

/**
 * Lists a wiki folder in the main content area. Reads the folder from the tree already
 * in state rather than refetching, and keeps the sidebar's browse pane pointed at the
 * same folder so expanding it lands where the reader is.
 */
export const loadFolderView = async (path) => {
    const target = path || '';
    // Dynamic: file_tree imports this module.
    const { findItemsByPath, renderBrowsePane, revealAndSelectFile } = await import('../file_tree/index.js');
    const items = findItemsByPath(target) || [];

    paneMode   = 'folder';
    folderPath = target;
    state.currentPagePath = target;
    state.currentPageType = 'folder';
    state.currentPageId   = null;
    state.currentPageTags = [];

    showListingPane('folder');
    updateBreadcrumb(target, state.currentSpace, { folder: true });
    updateFavoriteBtn(null);

    const name = target ? target.split('/').pop() : (state.currentSpace || t('nav.breadcrumb-root'));
    document.getElementById('current-page-title').innerHTML = `${icons.folder} <span>${name}</span>`;

    setActiveViewBtn();
    renderFolder(sortEntries(items), target);

    renderBrowsePane(items, target);
    revealAndSelectFile(target);
};

// ── Public: load a files folder ───────────────────────────────────────────────

export const loadFilesFolder = async (path) => {
    paneMode = 'library';
    state.currentPagePath = path;
    state.currentPageType = 'filesfolder';

    showListingPane('library');

    updateBreadcrumb(path, state.currentSpace, { folder: true });
    updateFavoriteBtn(null);

    const folderName = path.split('/').pop();
    document.getElementById('current-page-title').innerHTML = `${icons.filesFolder} <span>${folderName}</span>`;

    setActiveViewBtn();

    const result = await api.call('list_folder_files', { folder_path: path });
    if (result.success) renderFiles(result.data);
    else showToast(t('files.load-failed'), 'error');
};

// ── "New …" in the folder listing ─────────────────────────────────────────────
//
// The same seven options as the sidebar, and deliberately the *same* handlers: each row
// clicks the sidebar's own hidden <a>, so creation, naming prompts, templates and the
// post-create navigation all live in modules/new_items and cannot drift into a second
// copy. The rows are cloned from that dropdown when the menu opens, which also means the
// labels and icons follow a language change without this module knowing about it.

const NEW_ITEM_IDS = [
    'dropdown-new-page', 'dropdown-new-folder', 'dropdown-new-filesfolder',
    'dropdown-new-diagram', 'dropdown-new-list', 'dropdown-new-chat', 'dropdown-new-search',
];

const buildNewMenu = (menu) => {
    menu.innerHTML = '';
    NEW_ITEM_IDS.forEach(id => {
        const src = document.getElementById(id);
        if (!src) return;                       // role-gated away, or markup changed
        const a = document.createElement('a');
        a.href = '#';
        a.innerHTML = src.innerHTML;            // icon + label, already localised
        a.addEventListener('click', (e) => {
            e.preventDefault();
            menu.classList.add('hidden');
            src.click();
        });
        menu.appendChild(a);
    });
};

const wireNewMenu = () => {
    const btn  = document.getElementById('ff-new-btn');
    const menu = document.getElementById('ff-new-dropdown');
    if (!btn || !menu) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();                    // or the document handler closes it at once
        const opening = menu.classList.contains('hidden');
        if (opening) buildNewMenu(menu);
        menu.classList.toggle('hidden');
    });
    menu.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => menu.classList.add('hidden'));
};

// ── Search, handed to the sidebar's own box ───────────────────────────────────
//
// The rail hides the sidebar's search, so the listing carries one. It does not run a
// search itself: it writes the query into the sidebar's input and clicks its button, so
// `performSearch()` in modules/search stays the only implementation — including the
// "all spaces" checkbox, which keeps whatever the reader last set it to, and the results
// view, which replaces the listing exactly as a sidebar search does.

const wireSearchBox = () => {
    const input = document.getElementById('ff-search-input');
    const btn   = document.getElementById('ff-search-btn');
    if (!input || !btn) return;

    const run = () => {
        const target = document.getElementById('search-query-input');
        const go     = document.getElementById('search-query-btn');
        if (!target || !go || !input.value.trim()) return;
        target.value = input.value;   // leaves the sidebar showing the same query
        go.click();
    };
    btn.addEventListener('click', run);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
};

// ── Uploading pages into the folder being browsed ─────────────────────────────
//
// The same `.md`-only upload the file tree accepts as a drop, reached two ways: the
// toolbar button and a drop anywhere on the listing. Both call `uploadDroppedFiles()` in
// modules/file_tree, so the extension check, the "never overwrite, rename to (1)"
// convention, the per-file request and the summary toast have one implementation.
//
// Unlike the tree, the drop target is not whatever is under the pointer: it is always the
// folder being browsed. The listing's rows carry no path data — they close over their
// entry — and "the folder you are looking at" is the unsurprising answer for a pane that
// shows exactly one folder.

const uploadPagesTo = async (files) => {
    if (!files.length) return;
    const { uploadDroppedFiles } = await import('../file_tree/index.js');
    await uploadDroppedFiles(files, folderPath);
};

const wirePageUpload = () => {
    const btn   = document.getElementById('ff-upload-pages-btn');
    const input = document.getElementById('ff-upload-pages-input');
    const pane  = document.getElementById('files-folder-container');
    if (!btn || !input || !pane) return;

    btn.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
        const files = [...input.files];
        input.value = '';                     // so the same file can be picked again
        await uploadPagesTo(files);
    });

    // The pane is shared with the files library, which takes uploads of its own kind
    // through its own button — so the drop zone is live only while a wiki folder is up.
    const active = () => paneMode === 'folder' && canUpload();
    pane.addEventListener('dragover', (e) => {
        if (!active() || ![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault();                   // without this the browser opens the file
        e.dataTransfer.dropEffect = 'copy';
        pane.classList.add('drop-target-root');
    });
    pane.addEventListener('dragleave', (e) => {
        if (!pane.contains(e.relatedTarget)) pane.classList.remove('drop-target-root');
    });
    pane.addEventListener('drop', async (e) => {
        if (![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault();
        pane.classList.remove('drop-target-root');
        if (!active()) return;
        await uploadPagesTo([...(e.dataTransfer.files || [])]);
    });
};

// ── Folder actions ────────────────────────────────────────────────────────────
//
// Rename / Move / Delete for the folder being browsed, as a proxy for the header's "…"
// menu: each row clicks the original button, so the handlers stay in modules/file_ops and
// there is one implementation of each. A proxy rather than moving the header node here,
// because that node would go with the pane when it hides.
//
// The set is fixed rather than mirroring whatever the header menu currently shows. That
// menu's Rename and Delete entries are not touched when a folder is selected, so they
// carried over from the last page opened and the folder menu differed depending on where
// you had been. Delete is recursive server-side, which is not a thing to leave to chance.

const FOLDER_ACTION_IDS = ['rename-btn', 'move-btn', 'delete-btn'];

const buildFolderActionsMenu = (menu) => {
    menu.innerHTML = '';
    FOLDER_ACTION_IDS.forEach((id, i) => {
        const src = document.getElementById(id);
        if (!src) return;
        if (i && id === 'delete-btn') {
            const sep = document.createElement('div');
            sep.className = 'file-actions-menu-sep';
            menu.appendChild(sep);
        }
        const b = document.createElement('button');
        b.type = 'button';
        b.className = src.className.replace(/\bhidden\b/, '').trim();
        b.innerHTML = src.innerHTML;        // icon + label, already localised
        b.addEventListener('click', () => { menu.classList.add('hidden'); src.click(); });
        menu.appendChild(b);
    });
};

const wireFolderActions = () => {
    const btn  = document.getElementById('ff-folder-actions-btn');
    const menu = document.getElementById('ff-folder-actions-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const opening = menu.classList.contains('hidden');
        if (opening) buildFolderActionsMenu(menu);
        menu.classList.toggle('hidden');
    });
    menu.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => menu.classList.add('hidden'));
};

// ── Init ──────────────────────────────────────────────────────────────────────

export const init = () => {
    const uploadInput = document.getElementById('ff-upload-input');

    document.getElementById('ff-upload-btn').addEventListener('click', () => uploadInput.click());

    uploadInput.addEventListener('change', async () => {
        const files = [...uploadInput.files];
        if (!files.length || state.currentPageType !== 'filesfolder') return;
        let ok = 0;
        for (const file of files) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('folder_path', state.currentPagePath);
            const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
            const resp = await fetch(`api.php?action=upload_to_folder${spaceQs}`, { method: 'POST', body: fd });
            const r = await resp.json();
            if (r.success) ok++;
            else showToast(`Failed to upload ${file.name}`, 'error');
        }
        if (ok > 0) { showToast(`${ok} file${ok > 1 ? 's' : ''} uploaded`, 'success'); loadFilesFolder(state.currentPagePath); }
        uploadInput.value = '';
    });

    wireNewMenu();
    wireSearchBox();
    wirePageUpload();
    wireFolderActions();

    ['simple', 'detailed', 'icons'].forEach(mode => {
        document.getElementById(`ff-view-${mode}`)?.addEventListener('click', () => {
            currentViewMode = mode;
            localStorage.setItem('ff_view', mode);
            setActiveViewBtn();
            if (paneMode === 'folder' && state.currentPageType === 'folder') loadFolderView(folderPath);
            else if (state.currentPageType === 'filesfolder') loadFilesFolder(state.currentPagePath);
        });
    });
};
