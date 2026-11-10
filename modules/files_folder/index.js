// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { hidePageControls } from '../core/page_chrome.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal, promptModal, canUpload } from '../core/utils.js';
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

    hidePageControls();

    const editBtn = document.getElementById('edit-btn');
    editBtn.classList.add('hidden');
    editBtn.disabled = true;
    document.getElementById('page-actions-group').classList.remove('hidden');
    // A wiki folder is not a file: it can be moved, but there is nothing to upload into
    // it here (pages are dropped on the tree) and nothing to delete row by row. What it
    // does take is new content, which is what the rail hides the sidebar's New button for.
    document.getElementById('move-btn').classList.toggle('hidden', mode !== 'folder');
    document.getElementById('ff-upload-btn').classList.toggle('hidden', mode === 'folder');
    document.getElementById('ff-newfolder-btn')?.classList.toggle('hidden', mode === 'folder');
    document.getElementById('ff-selection')?.classList.toggle('hidden', mode === 'folder');
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

// A files library holds files and plain subfolders. `libPath` is the library (the wiki
// path that carries the `.filesfolder` marker, and what state.currentPagePath stays at, so
// the breadcrumb, the tree selection and everything keyed on the open item are unchanged
// by browsing inside it); `libSub` is the subfolder being shown, library-relative.
let libPath = '';
let libSub  = '';

const parentOf = (sub) => sub.includes('/') ? sub.slice(0, sub.lastIndexOf('/')) : '';

// ── Selection and the file actions ────────────────────────────────────────────
//
// A file row is not a link: a click marks or unmarks it, and what happens to the marked
// files is chosen in the toolbar — Open, Download, Delete. Double-click (or Enter) is the
// shortcut for Open. Folders are not selectable; a click opens them, as before.
//
// "Open" hands the file to the browser inline, which shows what it can (PDF, images, text,
// audio, video) and downloads the rest — a web page cannot launch a desktop application,
// so for a .docx that download is how it reaches the associated app. "Download" always
// saves, whatever the type, through getfile.php's `download=1`.

const selected = new Set();      // rel paths of the marked files in the folder shown
let shownFiles = new Map();      // rel → entry, for the files currently listed

const fileUrl = (entry, download = false) =>
    `getfile.php?path=${encodeURIComponent(entry.path)}${download ? '&download=1' : ''}`;

const selectedEntries = () => [...selected].map(rel => shownFiles.get(rel)).filter(Boolean);

const openFile = (entry) => window.open(fileUrl(entry), '_blank', 'noopener');

const downloadFiles = (entries) => {
    // One hidden <a download> per file. Browsers may ask once before allowing several
    // downloads from one page; that prompt is theirs to show.
    entries.forEach((entry, i) => setTimeout(() => {
        const a = document.createElement('a');
        a.href = fileUrl(entry, true);
        a.download = entry.name;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }, i * 250));
};

/** Reflect the selection in the rows and the toolbar. */
const syncSelection = () => {
    document.querySelectorAll('#ff-file-list [data-rel]').forEach(el => {
        const on = selected.has(el.dataset.rel);
        el.classList.toggle('ff-selected', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    const n = selected.size;
    const count = document.getElementById('ff-sel-count');
    if (count) count.textContent = n ? t('files.selected', { n }) : '';
    document.getElementById('ff-sel-clear')?.classList.toggle('hidden', !n);
    const set = (id, enabled) => { const b = document.getElementById(id); if (b) b.disabled = !enabled; };
    set('ff-open-btn', n === 1);
    set('ff-download-btn', n > 0);
    set('ff-delete-sel-btn', n > 0 && canUpload());
};

const toggleSelected = (rel) => {
    if (selected.has(rel)) selected.delete(rel); else selected.add(rel);
    syncSelection();
};

const clearSelection = () => { selected.clear(); syncSelection(); };

const deleteSelected = async () => {
    const entries = selectedEntries();
    if (!entries.length) return;
    const title = entries.length === 1
        ? t('fileops.delete-confirm', { name: entries[0].name })
        : t('files.delete-selected-confirm', { n: entries.length });
    if (!await confirmModal(title, { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
    const lib = libPath, sub = libSub;
    let failed = 0;
    for (const entry of entries) {
        const res = await api.call('delete_folder_file', { folder_path: lib, rel: entry.rel }, 'POST');
        if (!res.success) failed++;
    }
    showToast(failed ? t('files.delete-failed') : t('files.deleted-n', { n: entries.length }), failed ? 'error' : 'success');
    selected.clear();
    if (libPath === lib && libSub === sub) loadFilesFolder(lib, sub);
};

const renderFiles = (entries) => {
    const container = document.getElementById('ff-file-list');
    container.innerHTML = '';

    // Keep the marks that still name a listed file — an upload or a delete reloads the
    // listing, and losing the selection to that would be a surprise.
    shownFiles = new Map(entries.filter(e => e.type !== 'dir').map(e => [e.rel, e]));
    [...selected].forEach(rel => { if (!shownFiles.has(rel)) selected.delete(rel); });

    const reload = () => loadFilesFolder(libPath, libSub);

    const deleteEntry = async (entry) => {
        const isFolder = entry.type === 'dir';
        const title = isFolder ? t('files.folder-delete-confirm', { name: entry.name })
                               : t('fileops.delete-confirm', { name: entry.name });
        if (!await confirmModal(title, { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
        const res = await api.call('delete_folder_file', { folder_path: libPath, rel: entry.rel }, 'POST');
        if (res.success) { showToast(t(isFolder ? 'files.folder-deleted' : 'files.deleted'), 'success'); reload(); }
        else showToast(res.message || t('files.delete-failed'), 'error');
    };

    // The ".." row leads out of a subfolder; it is not offered at the library's own top,
    // where the way out is the breadcrumb, as it always was.
    const rows = libSub ? [{ up: true, type: 'dir', name: '..', rel: parentOf(libSub) }, ...entries] : entries;

    const open = (entry) => loadFilesFolder(libPath, entry.rel);

    /** The row's name. Not a link for either kind: the row decides what a click does. */
    const nameEl = (entry, cls, label = entry.name) => {
        const span = document.createElement('span');
        span.className = [cls, entry.type === 'dir' ? 'ff-dir-link' : 'ff-file-name'].filter(Boolean).join(' ');
        span.textContent = label;
        return span;
    };
    const iconEl = (entry, cls, large = false) => {
        const span = document.createElement('span');
        span.className = cls + (entry.type === 'dir' && !entry.up ? ' ff-folder-icon' : '');
        span.innerHTML = entry.up ? icons.up : entry.type === 'dir' ? icons.folder : fileIcon(entry.name, large);
        return span;
    };
    const delBtn = (entry, cls, text = '✕') => {
        const del = document.createElement('button');
        del.className = cls;
        del.title = t('btn.delete');
        del.textContent = text;
        del.addEventListener('click', (e) => { e.stopPropagation(); deleteEntry(entry); });
        return del;
    };
    /**
     * A folder row opens on click. A file row is marked or unmarked on click and opened on
     * double-click; from the keyboard, Space marks and Enter opens.
     */
    const wireRow = (el, entry) => {
        if (entry.type === 'dir') {
            el.classList.add('fv-row');
            el.title = entry.up ? t('folder.up') : entry.name;
            el.addEventListener('click', () => open(entry));
            return;
        }
        el.classList.add('ff-file-row');
        el.dataset.rel = entry.rel;
        el.title = entry.name;
        el.tabIndex = 0;
        el.setAttribute('role', 'option');
        el.addEventListener('click', () => toggleSelected(entry.rel));
        el.addEventListener('dblclick', (e) => {
            e.preventDefault();
            // The two clicks before it toggled the mark twice; leave this file marked.
            selected.add(entry.rel); syncSelection();
            openFile(entry);
        });
        el.addEventListener('keydown', (e) => {
            if (e.key === ' ')     { e.preventDefault(); toggleSelected(entry.rel); }
            if (e.key === 'Enter') { e.preventDefault(); openFile(entry); }
        });
    };

    if (currentViewMode === 'simple') {
        const ul = document.createElement('ul');
        ul.className = 'ff-simple-list';
        rows.forEach(entry => {
            const li = document.createElement('li');
            li.className = 'ff-simple-item';
            li.append(iconEl(entry, 'ff-icon-sm'), nameEl(entry, 'ff-filename'));
            if (!entry.up) li.appendChild(delBtn(entry, 'btn ff-del-btn'));
            wireRow(li, entry);
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
        rows.forEach(entry => {
            const tr = document.createElement('tr');
            const tdName = document.createElement('td');
            tdName.className = 'ff-detail-name';
            tdName.append(iconEl(entry, 'ff-icon-sm'), nameEl(entry, ''));
            const tdSize = document.createElement('td');
            tdSize.className = 'ff-detail-meta';
            tdSize.textContent = entry.type === 'dir' ? '' : fmtSize(entry.size);
            const tdDate = document.createElement('td');
            tdDate.className = 'ff-detail-meta';
            tdDate.textContent = entry.up ? '' : fmtDate(entry.mtime);
            const tdDel = document.createElement('td');
            if (!entry.up) tdDel.appendChild(delBtn(entry, 'btn ff-del-btn'));
            tr.append(tdName, tdSize, tdDate, tdDel);
            wireRow(tr, entry);
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
            item.className = 'ff-icon-item';
            const label = entry.name.length > 18 ? entry.name.slice(0, 17) + '…' : entry.name;
            const link = document.createElement('span');
            link.className = 'ff-icon-link';
            const nameSpan = document.createElement('span');
            nameSpan.className = 'ff-icon-name';
            nameSpan.textContent = label;
            link.append(iconEl(entry, entry.type === 'dir' ? 'ff-icon-lg fv-icon-lg' : 'ff-icon-lg', true), nameSpan);
            item.appendChild(link);
            if (!entry.up) item.appendChild(delBtn(entry, 'ff-icon-del'));
            wireRow(item, entry);
            grid.appendChild(item);
        });
        container.appendChild(grid);
    }

    container.querySelector('.ff-simple-list, .ff-detail-table, .ff-icons-grid')
        ?.setAttribute('aria-multiselectable', 'true');

    if (!entries.length) {
        const note = document.createElement('p');
        note.className = 'ff-empty';
        note.textContent = t('files.empty');
        container.appendChild(note);
    }
    syncSelection();
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
        span.className = cls + (!entry.up && kindOf(entry) === 'folder' ? ' ff-folder-icon' : '');
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

export const loadFilesFolder = async (path, sub = '') => {
    if (path !== libPath || sub !== libSub) selected.clear();   // a mark belongs to one folder
    paneMode = 'library';
    libPath  = path;
    libSub   = sub;
    state.currentPagePath = path;
    state.currentPageType = 'filesfolder';

    showListingPane('library');

    updateBreadcrumb(path, state.currentSpace, { folder: true });
    updateFavoriteBtn(null);

    // The breadcrumb names the library; the title adds where inside it you are.
    const title = [path.split('/').pop(), ...(sub ? sub.split('/') : [])].join(' / ');
    const titleEl = document.getElementById('current-page-title');
    titleEl.innerHTML = `${icons.filesFolder} <span></span>`;
    titleEl.querySelector('span').textContent = title;

    setActiveViewBtn();

    const result = await api.call('list_folder_files', { folder_path: path, sub });
    // A slow answer for a folder the reader has already left must not repaint the pane.
    if (libPath !== path || libSub !== sub || state.currentPageType !== 'filesfolder') return;
    if (result.success) renderFiles(result.data);
    else if (sub) loadFilesFolder(path, parentOf(sub));   // deleted under us: step out
    else showToast(t('files.load-failed'), 'error');
};

// ── Uploading into a files library ────────────────────────────────────────────
//
// Button and drop share one path. A drop may carry folders: they are walked with the
// File System entries API and each file is sent with the subfolder it sits in, which the
// server creates on the way. One request per file, as for pages — each stays inside
// upload_max_filesize, and one failure does not take the rest with it. Nothing is
// overwritten: a colliding name comes back as "name (1).ext".

/** [{ file, dir }] for everything under a dropped entry; `dir` is relative to the drop. */
const walkEntry = (entry, dir, out) => new Promise(resolve => {
    if (entry.isFile) {
        entry.file(f => { out.push({ file: f, dir }); resolve(); }, () => resolve());
    } else if (entry.isDirectory) {
        const sub = dir ? `${dir}/${entry.name}` : entry.name;
        out.push({ dir: sub, folder: true });
        const reader = entry.createReader();
        const children = [];
        // readEntries returns at most 100 at a time; keep asking until it returns none.
        const next = () => reader.readEntries(async batch => {
            if (!batch.length) {
                for (const c of children) await walkEntry(c, sub, out);
                resolve();
            } else { children.push(...batch); next(); }
        }, () => resolve());
        next();
    } else resolve();
});

const uploadToLibrary = async (items) => {
    const files = items.filter(i => i.file);
    const folders = items.filter(i => i.folder);
    if (!files.length && !folders.length) return;
    const lib = libPath, base = libSub;
    const join = (d) => [base, d].filter(Boolean).join('/');
    if (files.length) showToast(t('files.uploading', { n: files.length }), 'info');

    // Empty dropped folders would be lost by a files-only upload, so folders are made first.
    // One that already exists is fine: the drop merges into it.
    for (const f of folders) {
        const at = f.dir.includes('/') ? f.dir.slice(0, f.dir.lastIndexOf('/')) : '';
        await api.call('create_library_folder',
            { folder_path: lib, sub: join(at), name: f.dir.split('/').pop(), exist_ok: 1 }, 'POST');
    }

    let done = 0, renamed = 0, failed = 0;
    for (const { file, dir } of files) {
        const res = await api.call('upload_to_folder', { file, folder_path: lib, sub: join(dir) }, 'POST');
        if (res?.success) { done++; if (res.renamed) renamed++; }
        else failed++;
    }

    const parts = [];
    if (done)    parts.push(t('files.uploaded', { n: done }));
    if (renamed) parts.push(t('files.upload-renamed', { n: renamed }));
    if (failed)  parts.push(t('files.upload-failed', { n: failed }));
    if (!parts.length && folders.length) parts.push(t('files.folder-created'));
    showToast(parts.join(' · '), failed ? 'error' : 'success');
    if (state.currentPageType === 'filesfolder' && libPath === lib) loadFilesFolder(lib, libSub);
};

const newLibraryFolder = async () => {
    if (state.currentPageType !== 'filesfolder') return;
    const name = (await promptModal(t('files.new-folder-prompt'), '', '', icons.folder))?.trim();
    if (!name) return;
    const res = await api.call('create_library_folder', { folder_path: libPath, sub: libSub, name }, 'POST');
    if (res.success) { showToast(t('files.folder-created'), 'success'); loadFilesFolder(libPath, libSub); }
    else showToast(res.message || t('files.folder-create-failed'), 'error');
};

// ── "New …" in the folder listing ─────────────────────────────────────────────
//
// The same eight options as the sidebar, and deliberately the *same* handlers: each row
// clicks the sidebar's own hidden <a>, so creation, naming prompts, templates and the
// post-create navigation all live in modules/new_items and cannot drift into a second
// copy. The rows are cloned from that dropdown when the menu opens, which also means the
// labels and icons follow a language change without this module knowing about it.

const NEW_ITEM_IDS = [
    'dropdown-new-page', 'dropdown-new-folder', 'dropdown-new-filesfolder',
    'dropdown-new-diagram', 'dropdown-new-list', 'dropdown-new-json', 'dropdown-new-chat', 'dropdown-new-search',
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

    // The pane is shared by both listings, and so is the drop zone: a wiki folder takes
    // Markdown pages, a files library takes any file — and whole folders.
    const active = () => (paneMode === 'folder' || paneMode === 'library') && canUpload()
        && !pane.classList.contains('hidden');
    const isFileDrag = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
    pane.addEventListener('dragover', (e) => {
        if (!active() || !isFileDrag(e)) return;
        e.preventDefault();                   // without this the browser opens the file
        e.dataTransfer.dropEffect = 'copy';
        pane.classList.add('drop-target-root');
    });
    pane.addEventListener('dragleave', (e) => {
        if (!pane.contains(e.relatedTarget)) pane.classList.remove('drop-target-root');
    });
    pane.addEventListener('drop', async (e) => {
        if (!isFileDrag(e)) return;
        e.preventDefault();
        pane.classList.remove('drop-target-root');
        if (!active()) return;
        if (paneMode === 'folder') return uploadPagesTo([...(e.dataTransfer.files || [])]);
        // The entries must be taken now: the DataTransfer is emptied once the handler yields.
        const entries = [...(e.dataTransfer.items || [])]
            .map(i => i.webkitGetAsEntry?.()).filter(Boolean);
        const items = [];
        if (entries.length) {
            for (const en of entries) await walkEntry(en, '', items);
        } else {
            [...(e.dataTransfer.files || [])].forEach(file => items.push({ file, dir: '' }));
        }
        await uploadToLibrary(items);
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
    document.getElementById('ff-newfolder-btn')?.addEventListener('click', newLibraryFolder);
    document.getElementById('ff-open-btn')?.addEventListener('click', () => {
        const [entry] = selectedEntries();
        if (entry && selected.size === 1) openFile(entry);
    });
    document.getElementById('ff-download-btn')?.addEventListener('click', () => downloadFiles(selectedEntries()));
    document.getElementById('ff-delete-sel-btn')?.addEventListener('click', deleteSelected);
    document.getElementById('ff-sel-clear')?.addEventListener('click', clearSelection);
    // There is no data-i18n hook for aria-label, so the toolbar's name is set here.
    document.getElementById('ff-selection')?.setAttribute('aria-label', t('files.actions'));

    uploadInput.addEventListener('change', async () => {
        const files = [...uploadInput.files];
        uploadInput.value = '';               // so the same file can be picked again
        if (!files.length || state.currentPageType !== 'filesfolder') return;
        await uploadToLibrary(files.map(file => ({ file, dir: '' })));
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
            else if (state.currentPageType === 'filesfolder') loadFilesFolder(libPath, libSub);
        });
    });
};
