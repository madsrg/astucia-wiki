// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// Browse a space and choose a page or a folder. One lightbox, opened above the admin
// panel, shared by the AI user's prompt page and the workflow editor's path fields.
// Paths are returned relative to the chosen space, which is what every caller stores.

import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/**
 * @param {object}   o
 * @param {string}   o.space      space to open in ('' = the current one)
 * @param {string}   o.path       current value; the picker opens in its folder
 * @param {string}   o.mode       'file' (default) or 'folder'
 * @param {RegExp}   o.match      which files to offer in file mode (default: Markdown)
 * @param {string}   o.title      heading
 * @param {string}   o.empty      text for a folder with nothing to offer
 * @param {boolean}  o.lockSpace  hide the space selector — the caller decides the space
 * @param {function} o.onSelect   (space, path) — path is '' for a folder pick at the root
 */
export const openPagePicker = async ({ space = '', path = '', mode = 'file', match = /\.md$/i,
                                       title = t('admin.ai.prompt-page-choose'),
                                       empty = t('admin.ai.prompt-page-empty'),
                                       lockSpace = false, onSelect }) => {
    let lb = document.getElementById('pp-lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'pp-lightbox';
        lb.className = 'lightbox-overlay hidden';
        lb.innerHTML = `
            <div class="lightbox-content">
                <button type="button" id="pp-close-btn" class="lightbox-close">&times;</button>
                <h3 id="pp-title"></h3>
                <div class="form-group" id="pp-space-group">
                    <label>${t('admin.ai.picker-space')}</label>
                    <select id="pp-space-select" class="form-control"></select>
                </div>
                <div class="form-group">
                    <div id="pp-breadcrumb" style="font-size:0.82rem;margin-bottom:0.4rem;color:var(--accent-gray)"></div>
                    <div id="pp-file-list" class="link-file-tree"></div>
                </div>
                <div id="pp-folder-actions" class="pp-folder-actions hidden">
                    <button type="button" id="pp-choose-folder" class="btn btn-blue btn-sm"></button>
                </div>
            </div>`;
        document.body.appendChild(lb);
        lb.addEventListener('click', (e) => { if (e.target === lb) lb.classList.add('hidden'); });
        lb.querySelector('#pp-close-btn').addEventListener('click', () => lb.classList.add('hidden'));
    }
    const spaceSel = lb.querySelector('#pp-space-select');
    const listEl   = lb.querySelector('#pp-file-list');
    const crumbEl  = lb.querySelector('#pp-breadcrumb');
    const chooseBtn = lb.querySelector('#pp-choose-folder');
    lb.querySelector('#pp-title').textContent = title;
    lb.querySelector('#pp-space-group').classList.toggle('hidden', lockSpace);
    lb.querySelector('#pp-folder-actions').classList.toggle('hidden', mode !== 'folder');

    let tree = [];
    let cwd  = []; // folder-name segments of the current directory

    const childrenAt = (segs) => {
        let nodes = tree;
        for (const seg of segs) {
            const f = nodes.find(n => n.type === 'folder' && n.name === seg);
            if (!f) return [];
            nodes = f.children || [];
        }
        return nodes;
    };
    const renderCrumb = () => {
        const parts = [`<a href="#" data-i="-1">${t('fileops.root')}</a>`];
        cwd.forEach((seg, i) => parts.push(`<a href="#" data-i="${i}">${esc(seg)}</a>`));
        crumbEl.innerHTML = parts.join(' / ');
        // The button names the folder it will choose, so "Choose" at the root is explicit.
        chooseBtn.textContent = t('admin.picker.choose-folder', { folder: cwd.length ? cwd.join('/') : t('fileops.root') });
    };
    const renderList = () => {
        renderCrumb();
        const kids = childrenAt(cwd);
        // `.uploads` folders hold attachments, not pages.
        const folders = kids.filter(n => n.type === 'folder' && !/\.uploads$/i.test(n.name));
        const files   = mode === 'folder' ? [] : kids.filter(n => n.type === 'file' && match.test(n.path || ''));
        let html = '';
        folders.forEach(f => {
            html += `<div class="file-item-content pp-folder" data-name="${esc(f.name)}" style="cursor:pointer"><span class="file-item-name"><span class="folder-icon">${icons.folder}</span><span>${esc(f.name)}</span></span></div>`;
        });
        files.forEach(f => {
            const shown = /\.md$/i.test(f.name) ? f.name.replace(/\.md$/i, '') : f.name;
            html += `<div class="file-item-content pp-file" data-path="${esc(f.path)}" style="cursor:pointer"><span class="file-item-name">${icons.file}<span>${esc(shown)}</span></span></div>`;
        });
        listEl.innerHTML = html || `<p class="admin-empty" style="padding:0.5rem">${esc(empty)}</p>`;
    };
    const loadSpace = async (sp) => {
        listEl.innerHTML = `<p class="admin-loading" style="padding:0.5rem">${t('admin.diag.loading')}</p>`;
        const res = await api.call('list', sp ? { space: sp } : {});
        tree = (res && res.success) ? (res.data || []) : [];
        cwd = [];
        renderList();
    };

    const spacesRes = await api.call('list_spaces');
    const want = space || state.currentSpace;
    spaceSel.innerHTML = '';
    (spacesRes.data || []).forEach(sp => {
        const opt = document.createElement('option');
        opt.value = sp; opt.textContent = sp;
        if (sp === want) opt.selected = true;
        spaceSel.appendChild(opt);
    });

    spaceSel.onchange = () => loadSpace(spaceSel.value);
    crumbEl.onclick = (e) => {
        const a = e.target.closest('a[data-i]'); if (!a) return;
        e.preventDefault();
        const i = parseInt(a.dataset.i, 10);
        cwd = i < 0 ? [] : cwd.slice(0, i + 1);
        renderList();
    };
    listEl.onclick = (e) => {
        const folder = e.target.closest('.pp-folder');
        if (folder) { cwd.push(folder.dataset.name); renderList(); return; }
        const file = e.target.closest('.pp-file');
        if (file) { onSelect(spaceSel.value, file.dataset.path); lb.classList.add('hidden'); }
    };
    chooseBtn.onclick = () => { onSelect(spaceSel.value, cwd.join('/')); lb.classList.add('hidden'); };

    await loadSpace(spaceSel.value);
    // Open where the current value lives: its folder for a page, itself for a folder.
    const segs = (path || '').split('/').filter(Boolean);
    const start = mode === 'folder' ? segs : segs.slice(0, -1);
    if (start.length && childrenAt(start).length) { cwd = start; renderList(); }
    lb.classList.remove('hidden');
};
