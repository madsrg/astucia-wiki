// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * `{{_edit}}` in a page generator's template: a button on the generated page that opens the
 * row's item in the list's own add/edit form, over the page — and once it is saved, the
 * page re-rendered by the generator (before the save replies) replaces the one on screen. `{{_add.cards}}` is the same
 * button for one group allowing several (`data-group`): the same form, with a new entry of
 * that group already added and open — one form everywhere, the button only saves a click.
 * `{{_edit}}` inside a lookup's item (`wk-linked`, a link icon) is the same button for the
 * *linked* item: its own list, opened in that list's form; to someone who may not edit that
 * list it stays as a disabled icon, so linked data is still marked as such.
 * `{{_comment_form}}` is an empty <div class="wk-comment-form"> filled in here with a box and
 * Add comment, which adds a comment to the row's item and shows the re-rendered page.
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
    const forms = [...root.querySelectorAll('.wk-comment-form:not([data-ready])')];
    forms.forEach(f => { f.dataset.ready = '1'; });
    for (const f of forms) {
        if (f.dataset.list && f.dataset.item && await mayEdit(f.dataset.space || '')) fillCommentForm(f);
    }
    const buttons = [...root.querySelectorAll('.wk-edit:not([data-ready])')];
    if (!buttons.length) return;
    buttons.forEach(b => { b.dataset.ready = '1'; });
    for (const b of buttons) {
        if (!b.dataset.list || !b.dataset.item) continue;
        const linked = b.classList.contains('wk-linked');
        if (!(await mayEdit(b.dataset.space || ''))) {
            // A linked item's icon still says "this is linked data" to someone who cannot
            // edit it — as a mark, not a button.
            if (linked) {
                b.innerHTML = icons.link;
                b.title = t('generators.linked-item');
                b.setAttribute('aria-label', b.title);
                b.disabled = true;
                b.classList.add('is-ready', 'is-static');
            }
            continue;
        }
        const adding = !!b.dataset.group;
        b.innerHTML = adding ? '+' : linked ? icons.link : icons.pencil;
        b.title = t(adding ? 'generators.add-entry' : linked ? 'generators.edit-linked' : 'generators.edit-item');
        b.setAttribute('aria-label', b.title);
        b.classList.add('is-ready');
    }
};

/** Save a list that is not the open page, against the rev it was read at. */
const saveList = async (space, path, rev, data) => {
    // `render`: the server renders this list's generators before it replies (api.php save).
    const qs = new URLSearchParams({ action: 'save', file: path, space, base: rev, render: '1' });
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
 * The save asked the server to render the list's generators before replying, so the open
 * page usually has its new revision already: look at once. When the render ran out of time
 * (a long list) it finishes after the response — keep watching for a little while, and show
 * it when it comes; an edit that changes nothing on this page simply never does.
 */
const followRender = async (path, before) => {
    for (let i = 0; i < 21; i++) {
        if (i) await new Promise(r => setTimeout(r, 500));
        if (state.currentPagePath !== path || state.isEditing) return;
        const r = await api.call('get', { file: path });
        if (r.success && r.rev && r.rev !== before) {
            const { refreshPageContent } = await import('../page_view/index.js');
            await refreshPageContent();
            return;
        }
    }
};

/**
 * Read the list a button or form names, in its own Space: { path, data, rev }, or null (with a
 * toast) when the list or the row's item is gone.
 */
const loadList = async (el) => {
    const space = el.dataset.space || '';
    const itemId = Number(el.dataset.item);
    const where = await api.call('get_path_from_id', { pageid: Number(el.dataset.list), space });
    if (!where.success || !where.path?.endsWith('.list') || (where.space && where.space !== space)) {
        showToast(t('generators.edit-item-gone'), 'error');
        return null;
    }
    const got = await api.call('get', { file: where.path, space });
    let data = null;
    try { data = got.success ? JSON.parse(got.data) : null; } catch { data = null; }
    if (!data || !(data.items || []).some(i => i.id === itemId)) {
        showToast(t('generators.edit-item-gone'), 'error');
        return null;
    }
    return { space, itemId, path: where.path, data, rev: got.rev };
};

/** {{_comment_form}}: a box and Add comment; the comment is saved on the row's item. */
const fillCommentForm = (f) => {
    const box = document.createElement('textarea');
    box.className = 'form-control';
    box.rows = 2;
    box.placeholder = t('list.comment-add-ph');
    box.setAttribute('aria-label', t('list.comment-add'));
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-blue';
    btn.textContent = t('list.comment-add');
    btn.addEventListener('click', async () => {
        const text = box.value.trim();
        if (!text) { box.focus(); return; }
        btn.disabled = true;
        const list = await loadList(f);
        if (list) {
            const page = state.currentPagePath;
            const pageRev = state.currentPageRev;
            const item = list.data.items.find(i => i.id === list.itemId);
            // Text only: the server stamps who and when (lists.php).
            item._comments = [...(Array.isArray(item._comments) ? item._comments : []), { text }];
            // Kept until it is saved, so a refusal loses nothing.
            if (await saveList(list.space, list.path, list.rev, list.data)) {
                box.value = '';
                followRender(page, pageRev);
            }
        }
        btn.disabled = false;
    });
    f.append(box, btn);
};

const open = async (b) => {
    const list = await loadList(b);
    if (!list) return;
    const { space, itemId, data } = list;
    const page = state.currentPagePath;
    const pageRev = state.currentPageRev;
    const ext = {
        data, space,
        save: async (changed) => {
            const ok = await saveList(space, list.path, list.rev, changed);
            if (ok) followRender(page, pageRev);
            return ok;
        },
    };
    const { openItemModal } = await import('../list/modals.js');
    await openItemModal(itemId, ext, { addTo: b.dataset.group || null });
};

export const initEditButtons = () => {
    const viewer = document.getElementById('viewer-content');
    if (!viewer) return;
    new MutationObserver(() => { wire(viewer); }).observe(viewer, { childList: true, subtree: true });
    viewer.addEventListener('click', (e) => {
        const b = e.target.closest('.wk-edit.is-ready:not(.is-static)');
        if (!b) return;
        e.preventDefault();
        open(b);
    });
    wire(viewer);
};
