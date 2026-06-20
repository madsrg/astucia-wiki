import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal } from '../core/utils.js';

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

const setActiveViewBtn = () => {
    ['simple', 'detailed', 'icons'].forEach(m => {
        const btn = document.getElementById(`ff-view-${m}`);
        if (!btn) return;
        btn.classList.toggle('btn-blue', m === currentViewMode);
        btn.classList.toggle('btn-secondary', m !== currentViewMode);
    });
};

// ── Render ────────────────────────────────────────────────────────────────────

const renderFiles = (files) => {
    const container = document.getElementById('ff-file-list');
    container.innerHTML = '';

    if (!files.length) {
        container.innerHTML = '<p class="ff-empty">No files yet. Use Upload to add files.</p>';
        return;
    }

    const reload = () => loadFilesFolder(state.currentPagePath);

    const deleteFile = async (path, name) => {
        if (!await confirmModal(`Delete "${name}"?`, { confirmLabel: 'Delete', dangerous: true, icon: icons.trash })) return;
        const res = await api.call('delete_folder_file', { path }, 'POST');
        if (res.success) { showToast('File deleted', 'success'); reload(); }
        else showToast(res.message || 'Delete failed', 'error');
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
            del.title = 'Delete';
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
        thead.innerHTML = '<tr><th>Name</th><th>Size</th><th>Modified</th><th></th></tr>';
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
            del.title = 'Delete';
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
            del.title = 'Delete';
            del.textContent = '✕';
            del.addEventListener('click', () => deleteFile(f.path, f.name));
            item.append(link, del);
            grid.appendChild(item);
        });
        container.appendChild(grid);
    }
};

// ── Public: load a files folder ───────────────────────────────────────────────

export const loadFilesFolder = async (path) => {
    state.currentPagePath = path;
    state.currentPageType = 'filesfolder';

    document.getElementById('viewer-container').classList.add('hidden');
    document.getElementById('list-view-container').classList.add('hidden');
    document.getElementById('files-folder-container').classList.remove('hidden');

    const folderName = path.split('/').pop();
    document.getElementById('current-page-title').innerHTML = `${icons.filesFolder} <span>${folderName}</span>`;
    document.getElementById('page-id-display').classList.add('hidden');
    document.getElementById('edit-btn').classList.add('hidden');
    document.getElementById('edit-btn').disabled = true;
    document.getElementById('diagram-edit-btn').classList.add('hidden');
    document.getElementById('editor-mode-group')?.classList.add('hidden');
    document.getElementById('save-btn').classList.add('hidden');
    document.getElementById('cancel-btn').classList.add('hidden');
    document.getElementById('search-btn').classList.add('hidden');
    document.getElementById('page-meta-row').classList.add('hidden');
    document.getElementById('page-actions-group').classList.remove('hidden');
    document.getElementById('copy-btn').classList.add('hidden');
    document.getElementById('backlinks-btn').classList.add('hidden');
    document.getElementById('print-btn').classList.add('hidden');
    document.getElementById('move-btn').classList.add('hidden');

    setActiveViewBtn();

    const result = await api.call('list_folder_files', { folder_path: path });
    if (result.success) renderFiles(result.data);
    else showToast('Failed to load folder', 'error');
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

    ['simple', 'detailed', 'icons'].forEach(mode => {
        document.getElementById(`ff-view-${mode}`)?.addEventListener('click', () => {
            currentViewMode = mode;
            localStorage.setItem('ff_view', mode);
            setActiveViewBtn();
            if (state.currentPageType === 'filesfolder') loadFilesFolder(state.currentPagePath);
        });
    });
};
