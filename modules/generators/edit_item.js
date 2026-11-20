// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * `{{_edit}}` in a page generator's template: a button on the generated page that opens the
 * row's item in the list's own add/edit form, over the page — and once it is saved, the
 * page re-rendered by the generator replaces the one on screen.
 *
 * The server writes `<button class="wk-edit" data-space data-list data-item>` with no label
 * (page_generators.php, WikiTplMarkup); everything a person sees is added here, in their
 * language. It is hidden until this module decides the reader may edit the list: not a
 * reader, the list's Space among theirs, and that Space not read-only. The server still
 * decides on Save — this only keeps a button nobody can use off the page.
 *
 * A MutationObserver on the viewer finds the buttons, because a page's content arrives by
 * several routes (loadPage, the in-place refresh, {include:ID} of a generated page), and
 * refreshPageContent() announces the page before it repaints it. `data-ready` marks a button
 * as done, so the observer's own changes cannot trigger it again.
 *
 * The list may be in another Space. It is read and saved with that Space named explicitly
 * (api.call's `space` wins over the open one), and its lookups resolve there (setLookupSpace,
 * through openItemModal's `external`).
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

let readonlySpaces = null;      // Promise<Set>, asked once per page load
const frozen = () => readonlySpaces ??= api.call('list_spaces')
    .then(r => new Set(r.readonly || [])).catch(() => new Set());

const mayEdit = async (space) => {
    if (window.WIKI_ROLE === 'reader') return false;
    const mine = window.WIKI_USER_SPACES;
    if (Array.isArray(mine) && !mine.includes(space)) return false;
    return !(await frozen()).has(space);
};

const wire = async (root) => {
    const buttons = [...root.querySelectorAll('.wk-edit:not([data-ready])')];
    if (!buttons.length) return;
    buttons.forEach(b => { b.dataset.ready = '1'; });
    for (const b of buttons) {
        if (!b.dataset.list || !b.dataset.item || !(await mayEdit(b.dataset.space || ''))) continue;
        b.innerHTML = icons.pencil;
        b.title = t('generators.edit-item');
        b.setAttribute('aria-label', t('generators.edit-item'));
        b.classList.add('is-ready');
    }
};

/** Save a list that is not the open page, against the rev it was read at. */
const saveList = async (space, path, rev, data) => {
    const qs = new URLSearchParams({ action: 'save', file: path, space, base: rev });
    let res, body;
    try {
        res = await fetch(`api.php?${qs}`, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify(data, null, 4) });
        body = await res.json();
    } catch {
        showToast(t('list.save-failed'), 'error');
        return false;
    }
    if (res.status === 409) { showToast(t('generators.edit-item-conflict'), 'error'); return false; }
    if (!res.ok || !body.success) {
        let msg = body.message || t('list.save-failed');
        if (body.list_invalid) msg = (await import('../list/data.js')).listInvalidText(body.list_invalid);
        showToast(t('edit.save-failed', { error: msg }), 'error');
        return false;
    }
    showToast(t('list.saved'), 'success');
    return true;
};

/**
 * After the list is saved the generator re-renders its pages once the response has gone
 * (page_generators.php). Watch the open page for a new revision for a little while, and show
 * it when it comes; an edit that changes nothing on this page simply never does.
 */
const followRender = async (path, before) => {
    for (let i = 0; i < 20; i++) {
        await new Promise(r => setTimeout(r, 500));
        if (state.currentPagePath !== path || state.isEditing) return;
        const r = await api.call('get', { file: path });
        if (r.success && r.rev && r.rev !== before) {
            const { refreshPageContent } = await import('../page_view/index.js');
            await refreshPageContent();
            return;
        }
    }
};

const open = async (b) => {
    const space = b.dataset.space || '';
    const listId = Number(b.dataset.list);
    const itemId = Number(b.dataset.item);
    const where = await api.call('get_path_from_id', { pageid: listId, space });
    if (!where.success || !where.path?.endsWith('.list') || (where.space && where.space !== space)) {
        showToast(t('generators.edit-item-gone'), 'error');
        return;
    }
    const got = await api.call('get', { file: where.path, space });
    let data = null;
    try { data = got.success ? JSON.parse(got.data) : null; } catch { data = null; }
    if (!data || !(data.items || []).some(i => i.id === itemId)) {
        showToast(t('generators.edit-item-gone'), 'error');
        return;
    }
    const page = state.currentPagePath;
    const pageRev = state.currentPageRev;
    const { openItemModal } = await import('../list/modals.js');
    await openItemModal(itemId, {
        data, space,
        save: async (changed) => {
            const ok = await saveList(space, where.path, got.rev, changed);
            if (ok) followRender(page, pageRev);
            return ok;
        },
    });
};

export const initEditButtons = () => {
    const viewer = document.getElementById('viewer-content');
    if (!viewer) return;
    new MutationObserver(() => { wire(viewer); }).observe(viewer, { childList: true, subtree: true });
    viewer.addEventListener('click', (e) => {
        const b = e.target.closest('.wk-edit.is-ready');
        if (!b) return;
        e.preventDefault();
        open(b);
    });
    wire(viewer);
};
