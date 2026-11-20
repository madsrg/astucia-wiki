// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Selecting several pages in a wiki folder's listing, and moving, deleting or tagging them
 * together.
 *
 * **A checkbox on every page row, always there** — not a "multi-select" mode. The page's
 * name stays a link that opens it, so nothing changes meaning when a mode is on; the box
 * beside it is the only way to select (Shift extends a range, Space toggles the focused
 * one). Folders are not selectable: a bulk delete of folders is too easy to regret.
 *
 * The selection is by path and outlives paging, sorting and the name filter (a folder holds
 * thousands of pages 100 at a time); it is forgotten when another folder is listed. The bar
 * above the list has "select all on this page" and, once a whole page is ticked, the offer
 * to select every matching page in the folder; its actions appear only when something is
 * selected. Not for readers or a read-only Space (`canUpload()`), where nothing could happen.
 *
 * **The actions are the single-page ones, one page at a time.** Every rule those requests
 * already enforce applies unchanged — a generated page refuses to be moved or deleted, a
 * frozen Space refuses everything, the audit log and the git commit are as for a single
 * page. What fails is collected and listed at the end, and stays selected; what succeeded
 * leaves the selection. Move asks about `[[wikilinks]]` once for the whole batch.
 *
 * The files library keeps its own click-to-mark selection (modules/files_folder/index.js):
 * its rows are not links, so it never had this problem.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal, promptModal, canUpload } from '../core/utils.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const pageName = (path) => path.split('/').pop().replace(/\.(md|drawio|list|chat|search|json)$/, '');

const selected = new Map();      // path → tree item ({ path, id, tags, name, … })
let lastClicked = null;          // path of the last box clicked, for Shift ranges
let busy = false;
let ctx = null;                  // { shown(), matches(), refresh() } from the listing

/** Is this tree item selectable — a page, not a folder or a library? */
export const isSelectable = (item) => !!item && !item.up && item.type !== 'folder' && item.type !== 'filesfolder';

/** Forget the selection — another folder is being listed. */
export const resetBulk = () => { selected.clear(); lastClicked = null; };

const shownPages = () => (ctx?.shown() || []).filter(isSelectable);
const matchingPages = () => (ctx?.matches() || []).filter(isSelectable);

/** The checkbox for one row. `stop` keeps its click from opening the page. */
export const rowCheckbox = (item) => {
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.className = 'ff-bulk-check';
    box.dataset.path = item.path;
    box.checked = selected.has(item.path);
    box.title = t('folder.sel-row');
    box.setAttribute('aria-label', t('folder.sel-row-named', { name: pageName(item.path) }));
    box.addEventListener('click', (e) => {
        e.stopPropagation();
        const on = box.checked;
        if (e.shiftKey && lastClicked && lastClicked !== item.path) {
            const list = shownPages().map(p => p.path);
            const a = list.indexOf(lastClicked), b = list.indexOf(item.path);
            if (a > -1 && b > -1) {
                const [lo, hi] = a < b ? [a, b] : [b, a];
                shownPages().slice(lo, hi + 1).forEach(p => { if (on) selected.set(p.path, p); else selected.delete(p.path); });
            }
        } else if (on) selected.set(item.path, item);
        else selected.delete(item.path);
        lastClicked = item.path;
        syncBulk();
    });
    // Keyboard: Space on the box toggles it natively; keep it from the row's own handlers.
    box.addEventListener('keydown', (e) => e.stopPropagation());
    return box;
};

// ── The bar ──────────────────────────────────────────────────────────────────

let bar = null;

const btn = (cls, label, onClick) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `btn btn-sm ${cls}`;
    b.textContent = label;
    b.addEventListener('click', onClick);
    return b;
};

export const initBulk = (listingCtx) => {
    ctx = listingCtx;
    const list = document.getElementById('ff-file-list');
    if (!list || document.getElementById('ff-bulk')) return;
    bar = document.createElement('div');
    bar.id = 'ff-bulk';
    bar.className = 'ff-bulk hidden';
    bar.innerHTML = `
        <label class="ff-bulk-all"><input type="checkbox" class="ff-bulk-page"> <span>${esc(t('folder.sel-all-page'))}</span></label>
        <span class="ff-bulk-count"></span>
        <button type="button" class="ff-bulk-everything btn-link hidden"></button>
        <span class="ff-bulk-actions hidden"></span>`;
    const actions = bar.querySelector('.ff-bulk-actions');
    actions.append(
        btn('btn-secondary ff-bulk-move', t('folder.sel-move'), () => moveSelected()),
        btn('btn-secondary ff-bulk-tag', t('folder.sel-tag'), () => tagSelected()),
        btn('btn-danger ff-bulk-delete', t('folder.sel-delete'), () => deleteSelected()),
        btn('btn-link ff-bulk-clear', t('folder.sel-clear'), () => { selected.clear(); syncBulk(); }));
    bar.querySelector('.ff-bulk-page').addEventListener('change', (e) => {
        shownPages().forEach(p => { if (e.target.checked) selected.set(p.path, p); else selected.delete(p.path); });
        syncBulk();
    });
    bar.querySelector('.ff-bulk-everything').addEventListener('click', () => {
        matchingPages().forEach(p => selected.set(p.path, p));
        syncBulk();
    });
    list.parentNode.insertBefore(bar, list);
};

/** Show or hide the bar: a wiki folder's listing, for someone who may change it. */
export const showBulk = (on) => {
    bar?.classList.toggle('hidden', !on || !canUpload());
    if (!on) resetBulk();
};

/** Reflect the selection in the boxes and the bar. */
export const syncBulk = () => {
    document.querySelectorAll('#ff-file-list .ff-bulk-check').forEach(b => { b.checked = selected.has(b.dataset.path); });
    document.querySelectorAll('#ff-file-list .ff-bulk-check').forEach(b =>
        b.closest('.fv-row')?.classList.toggle('ff-bulk-on', b.checked));
    if (!bar) return;
    const shown = shownPages();
    const onPage = shown.filter(p => selected.has(p.path)).length;
    const pageBox = bar.querySelector('.ff-bulk-page');
    pageBox.checked = shown.length > 0 && onPage === shown.length;
    pageBox.indeterminate = onPage > 0 && onPage < shown.length;
    pageBox.disabled = !shown.length || busy;
    const n = selected.size;
    if (!busy) bar.querySelector('.ff-bulk-count').textContent = n ? t('folder.sel-count', { n }) : '';
    bar.querySelector('.ff-bulk-actions').classList.toggle('hidden', !n);
    bar.querySelectorAll('.ff-bulk-actions button').forEach(b => { b.disabled = busy; });
    // A whole page ticked, and more pages match than are shown: offer the rest.
    const all = matchingPages();
    const offer = pageBox.checked && all.length > shown.length;
    const every = bar.querySelector('.ff-bulk-everything');
    every.classList.toggle('hidden', !offer);
    every.textContent = all.every(p => selected.has(p.path))
        ? t('folder.sel-all-folder-done', { n: all.length })
        : t('folder.sel-all-folder', { n: all.length });
    every.disabled = all.every(p => selected.has(p.path));
};

// ── Doing it ─────────────────────────────────────────────────────────────────

/**
 * Run `act(item)` for every selected page, one at a time, with progress in the bar.
 * `act` returns { ok: true } or { ok: false, why }. Failures stay selected and are listed.
 */
const runEach = async (verbKey, act) => {
    const items = [...selected.values()];
    const failed = [];
    busy = true;
    syncBulk();
    const count = bar?.querySelector('.ff-bulk-count');
    for (let i = 0; i < items.length; i++) {
        if (count) count.textContent = t(verbKey, { i: i + 1, n: items.length });
        let res;
        try { res = await act(items[i]); } catch (e) { res = { ok: false, why: e.message }; }
        if (res.ok) selected.delete(items[i].path);
        else failed.push({ name: pageName(items[i].path), why: res.why || '' });
    }
    busy = false;
    return { done: items.length - failed.length, failed };
};

const report = async (doneKey, { done, failed }) => {
    const { refreshFileTree } = await import('../file_tree/index.js');
    await refreshFileTree();
    ctx?.refresh();
    syncBulk();
    if (!failed.length) { showToast(t(doneKey, { n: done }), 'success'); return; }
    const rows = failed.map(f => `<li><strong>${esc(f.name)}</strong>${f.why ? ` — ${esc(f.why)}` : ''}</li>`).join('');
    await confirmModal(t('folder.sel-failed-title'), {
        messageHtml: `<p>${esc(t('folder.sel-failed-msg', { done, n: failed.length }))}</p><ul class="ff-bulk-failed">${rows}</ul>`,
        confirmLabel: t('btn.close'), hideCancel: true,
    });
};

/** Why a single-page request refused, in words. */
const reason = (res) => res.generated ? t('generators.locked-toast') : (res.message || '');

const deleteSelected = async () => {
    const items = [...selected.values()];
    if (!items.length || busy) return;
    const names = items.slice(0, 8).map(i => `<li>${esc(pageName(i.path))}</li>`).join('')
        + (items.length > 8 ? `<li>${esc(t('folder.sel-and-more', { n: items.length - 8 }))}</li>` : '');
    if (!await confirmModal(t('folder.sel-delete-title', { n: items.length }), {
        messageHtml: `<ul class="ff-bulk-failed">${names}</ul>`,
        confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
    const { forget } = await import('../tabs/index.js');
    const result = await runEach('folder.sel-deleting', async (item) => {
        const res = await api.call('delete', { path: item.path }, 'POST');
        if (res.success) { forget(item.path); return { ok: true }; }
        return { ok: false, why: reason(res) };
    });
    await report('folder.sel-deleted', result);
};

const moveSelected = async () => {
    if (!selected.size || busy) return;
    const { openMoveLightbox } = await import('../file_ops/move.js');
    await openMoveLightbox(async (destFolder, targetSpace) => {
        const cross = targetSpace !== state.currentSpace;
        const { retarget, forget } = await import('../tabs/index.js');
        const moved = [];
        const result = await runEach('folder.sel-moving', async (item) => {
            const newPath = (destFolder ? destFolder + '/' : '') + item.path.split('/').pop();
            if (!cross && newPath === item.path) return { ok: true };
            const params = { old_path: item.path, new_path: newPath };
            if (cross) params.target_space = targetSpace;
            const res = await api.call('move', params, 'POST');
            if (!res.success) return { ok: false, why: reason(res) };
            if (cross) forget(item.path); else { retarget(item.path, newPath); moved.push([item.path, newPath]); }
            return { ok: true };
        });
        await report('folder.sel-moved', result);
        // [[Wikilinks]] name their target: count them for every moved page, ask once.
        if (!moved.length) return;
        // One page may link to several of them, so pages are counted once each.
        let links = 0;
        const linking = new Set();
        const counts = [];
        for (const [from, to] of moved) {
            const r = await api.call('retarget_wikilinks', { old_path: from, new_path: to }, 'POST');
            if (r.success && r.links) {
                links += r.links;
                (r.files || []).forEach(f => linking.add(f.path));
                counts.push([from, to]);
            }
        }
        if (!links) return;
        const pages = linking.size;
        if (!await confirmModal(t('wikilinks.retarget-title'), {
            message: t('folder.sel-retarget-msg', { links, pages, n: counts.length }),
            confirmLabel: t('wikilinks.retarget-confirm') })) return;
        for (const [from, to] of counts) {
            await api.call('retarget_wikilinks', { old_path: from, new_path: to, apply: 1 }, 'POST');
        }
        showToast(t('wikilinks.retargeted', { links, pages }), 'success');
    });
};

const tagSelected = async () => {
    const items = [...selected.values()];
    if (!items.length || busy) return;
    const tag = (await promptModal(t('folder.sel-tag-title', { n: items.length }), '', t('folder.sel-tag-ph'), icons.tag || ''))?.trim();
    if (!tag) return;
    const result = await runEach('folder.sel-tagging', async (item) => {
        const tags = Array.isArray(item.tags) ? item.tags : [];
        if (tags.includes(tag)) return { ok: true };
        if (!item.id) return { ok: false, why: '' };
        const res = await api.call('update_tags', { id: item.id, tags: JSON.stringify([...tags, tag]) }, 'POST');
        return res.success ? { ok: true } : { ok: false, why: res.message || '' };
    });
    await report('folder.sel-tagged', result);
};
