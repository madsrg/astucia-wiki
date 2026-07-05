/**
 * core/save_page_picker.js — shared "choose folder + page name" modal.
 *
 * pickSavePath(defaultName, titleText) shows the #save-page-picker lightbox
 * populated with the current space's folder tree (via the `list` action) and a
 * name field, and resolves to a relative `.md` path (e.g. "Notes/Copy.md") or
 * null if the user cancels. Callers own the actual create/save.
 */
import { api } from './api.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const flattenFolders = (items) => {
    let out = [];
    (items || []).forEach(it => {
        if (it.type !== 'folder') return;
        out.push(it.path);
        if (it.children?.length) out = out.concat(flattenFolders(it.children));
    });
    return out;
};

export const pickSavePath = async (defaultName = '', titleText = '') => {
    const overlay   = document.getElementById('save-page-picker');
    if (!overlay) return null;
    const titleEl   = document.getElementById('save-page-picker-title');
    const folderSel = document.getElementById('save-page-picker-folder');
    const nameInput = document.getElementById('save-page-picker-name');
    const okBtn     = document.getElementById('save-page-picker-ok');
    const cancelBtn = document.getElementById('save-page-picker-cancel');

    if (titleText) titleEl.textContent = titleText;
    const res = await api.call('list');
    const folders = res.success ? [...new Set(flattenFolders(res.data))].sort() : [];
    folderSel.innerHTML = `<option value="">${esc(t('savepage.root'))}</option>`
        + folders.map(f => `<option value="${esc(f)}">${esc(f)}</option>`).join('');
    folderSel.value = '';
    nameInput.value = defaultName;

    overlay.classList.remove('hidden');
    setTimeout(() => { nameInput.focus(); nameInput.select(); }, 50);

    return new Promise((resolve) => {
        const cleanup = (val) => {
            overlay.classList.add('hidden');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onBackdrop);
            nameInput.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const onOk = () => {
            let name = nameInput.value.trim();
            if (!name) { nameInput.focus(); return; }
            if (!name.endsWith('.md')) name += '.md';
            const folder = folderSel.value;
            cleanup((folder ? folder + '/' : '') + name);
        };
        const onCancel   = () => cleanup(null);
        const onBackdrop = (e) => { if (e.target === overlay) onCancel(); };
        const onKey      = (e) => {
            if (e.key === 'Enter')  { e.preventDefault(); onOk(); }
            if (e.key === 'Escape') { e.stopPropagation(); onCancel(); }
        };
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        overlay.addEventListener('click', onBackdrop);
        nameInput.addEventListener('keydown', onKey);
    });
};
