// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, promptModal, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';
import { loadPage, showBlankPage, updateFrontmatterBadge, rebaselineFileWatch } from '../page_view/index.js';
import { retarget as retargetTab, forget as forgetTab } from '../tabs/index.js';
import { offerRetarget } from '../wikilinks/index.js';
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

    if (state.currentPageType === 'md') newName += '.md';
    else if (state.currentPageType === 'diagram') newName += '.drawio';
    else if (state.currentPageType === 'list') newName += '.list';
    else if (state.currentPageType === 'chat') newName += '.chat';

    const pathParts = state.currentPagePath.split('/');
    pathParts.pop();
    const newPath = (pathParts.length > 0 ? pathParts.join('/') + '/' : '') + newName;

    const oldPath = state.currentPagePath;
    const res = await api.call('move', { old_path: state.currentPagePath, new_path: newPath }, 'POST');
    if (res.success) {
        showToast(t('fileops.renamed'), 'success');
        // Update path immediately so any active chat poll stops before it requests the old path
        const savedId   = state.currentPageId;
        const savedTags = state.currentPageTags;
        // Before the path moves: the open tab follows the file (keeping any draft) rather
        // than being left pointing at a name that no longer exists.
        retargetTab(state.currentPagePath, newPath);
        state.currentPagePath = newPath;
        await refreshFileTree();
        if (state.currentPageType === 'chat') {
            // Reload the chat at the new path to restart polling correctly
            await loadPage(newPath, savedId, savedTags);
        } else {
            document.getElementById('current-page-title').textContent = newPath.replace(/\.(md|drawio|list|chat)$/, '');
        }
        revealAndSelectFile(newPath);
        // Wikilinks name their target, so a rename leaves them pointing at nothing. Ask before
        // rewriting them — this is the one place wikilink source text changes.
        await offerRetarget(oldPath, newPath);
    }
};

export const handleDelete = async () => {
    if (!state.currentPagePath) return;
    const displayName = state.currentPagePath.replace(/\.(md|drawio|json)$/, '');
    if (!await confirmModal(t('fileops.delete-confirm', { name: displayName }), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;

    api.call('delete', { path: state.currentPagePath }, 'POST').then(async res => {
        if (res.success) {
            showToast(t('fileops.deleted'), 'success');
            forgetTab(state.currentPagePath);   // no tab may outlive the file it opens
            await refreshFileTree();
            // Falls back to an empty view when the space has no Main.md — including
            // when Main.md is the page that was just deleted.
            const startResult = await api.call('get_start_page');
            if (startResult.success && startResult.path) {
                await loadPage(startResult.path, startResult.id, []);
                revealAndSelectFile(startResult.path);
            } else if (startResult.success) {
                await showBlankPage();
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

    if (type === 'md') {
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

/**
 * Show a page's YAML front matter — read-only, or editable where the Space allows it.
 *
 * The wiki does not *author* this block: page metadata lives in index.json, and this is
 * whatever the **file** carries. That is the point of the feature — it is the metadata
 * that travels when the `.md` is copied elsewhere, or read by a tool that never talks to
 * the API. It is read from `state.currentPageFrontmatter`, captured by the page load, so
 * opening the panel costs no request.
 *
 * Every key is shown rather than a fixed list, so an imported Obsidian page's `aliases`
 * or `cssclass` stays visible instead of quietly disappearing. The server puts the named
 * fields first (wiki_fm_ordered) and hands them over in that order.
 *
 * **Editing is per Space** (`state.spaceFmEdit`) and unlike every other page write it is
 * its own save and its own commit: the panel is open in read mode, so there is no page
 * save to ride along with. The server re-checks the Space policy — this only decides
 * whether to draw the inputs.
 *
 * Two kinds of field are shown but **not** editable, and they are distinct:
 *
 *  - `frontmatter_nested` — a nested mapping. There is no sensible one-line editor for
 *    one, and writing a scalar over it would delete its structure. Read from the block
 *    text rather than guessed from the value, since a nested mapping parses to a string.
 *  - `frontmatter_managed` — the four fields the wiki stamps when the Space has automatic
 *    stamping on. Offering a text box over a value that is overwritten on the next save is
 *    a trap, so they are marked as the wiki's. The server refuses them too.
 *
 * A **list** is editable: it is shown comma-separated, split back on save, and written in
 * whichever style the file already used for that key (see wiki_fm_emit_field). A new field
 * becomes a list only when the "multiple values" box is ticked — splitting on commas by
 * default would turn a value like "Lovelace, Ada" into two.
 */
const _fmEsc = (v) => String(v ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

// [key, displayed text, isList]. A list is shown and edited comma-separated; the server
// writes it back in whichever style the file already used for that key.
const _fmRowsOf = (meta) => Object.entries(meta || {}).map(([k, v]) => [
    k,
    Array.isArray(v) ? v.join(', ') : String(v ?? ''),
    Array.isArray(v),
]);
const _fmSplitList = (text) => String(text ?? '')
    .split(',').map(v => v.trim()).filter(v => v !== '');

const showFrontmatter = async () => {
    const editable = !!state.spaceFmEdit && !state.spaceReadOnly
        && !document.body.classList.contains('role-reader');
    const allRows  = _fmRowsOf(state.currentPageFrontmatter);
    const nested  = new Set((state.currentPageFrontmatterNested || [])
        .map(k => String(k).toLowerCase()));
    const managed    = new Set((state.currentPageFrontmatterManaged || [])
        .map(k => String(k).toLowerCase()));

    // The wiki's own fields first, in the order it writes them, then a separator, then
    // the author's. `frontmatter_managed` is the wiki's list, so it also gives the order —
    // created, createdBy, updated, updatedBy — rather than however the file happens to
    // list them.
    const managedOrder = (state.currentPageFrontmatterManaged || []).map(k => String(k).toLowerCase());
    const managedRows  = allRows.filter(([k]) => managed.has(k.toLowerCase()))
        .sort((a, b) => managedOrder.indexOf(a[0].toLowerCase()) - managedOrder.indexOf(b[0].toLowerCase()));
    const ownRows      = allRows.filter(([k]) => !managed.has(k.toLowerCase()));
    const rows         = [...managedRows, ...ownRows];

    if (!rows.length && !editable) {
        await confirmModal(t('fm.title'), {
            messageHtml: `<p class="form-hint">${_fmEsc(t('fm.none'))}</p>`,
            confirmLabel: t('btn.close'), hideCancel: true,
        });
        return;
    }

    const badge = (k) => {
        if (!editable) return '';
        if (managed.has(k.toLowerCase())) return '';
        if (nested.has(k.toLowerCase())) {
            return ` <span class="fm-locked" title="${_fmEsc(t('fm.nested-hint'))}">${_fmEsc(t('fm.nested'))}</span>`;
        }
        return '';
    };
    const isLocked = (k) => nested.has(k.toLowerCase()) || managed.has(k.toLowerCase());
    const valueCell = (k, val, isList) => {
        if (!editable || isLocked(k)) {
            return `<td class="fm-val">${val === '' ? '<em>—</em>' : _fmEsc(val)}${badge(k)}</td>`;
        }
        // data-multi marks a field whose text is split back into a list on save, so a
        // value that legitimately contains a comma stays one value everywhere else.
        return `<td class="fm-val"><input type="text" class="form-control fm-input"`
             + ` data-key="${_fmEsc(k)}"${isList ? ' data-multi="1"' : ''} value="${_fmEsc(val)}">`
             + (isList ? ` <span class="fm-multi-note">${_fmEsc(t('fm.multi-note'))}</span>` : '')
             + `</td>`;
    };
    // A field the wiki maintains cannot be removed either: it would be written straight
    // back on the next save, so the button would look broken rather than refused.
    const removeCell = (k) => editable && !managed.has(k.toLowerCase())
        ? `<td class="fm-rm-cell"><button type="button" class="fm-rm" data-key="${_fmEsc(k)}"`
          + ` title="${_fmEsc(t('fm.remove'))}">×</button></td>`
        : (editable ? '<td class="fm-rm-cell"></td>' : '');

    // What this panel *is* depends on the Space, so the explanation does too: saying "the
    // wiki does not write this block" is false where stamping is on, and "read-only" is
    // false where editing is.
    // A read-only Space with stamping on was still told "the wiki does not write this
    // block", which is false: it writes four of them. The two axes are independent, so the
    // text picks on both.
    const intro = managed.size
        ? (editable ? t('fm.from-file-edit-stamped') : t('fm.from-file-stamped'))
        : (editable ? t('fm.from-file-edit') : t('fm.from-file'));
    const emptyText = editable ? t('fm.none-edit') : t('fm.none');
    const body = `<div class="fm-view">`
        + `<p class="form-hint fm-source">${_fmEsc(intro)}</p>`
        + (!rows.length ? `<p class="form-hint">${_fmEsc(emptyText)}</p>` : '')
        + (managedRows.length && ownRows.length
            ? `<p class="form-hint fm-managed-note">${_fmEsc(t('fm.managed-note'))}</p>` : '')
        // The table is always present while editing, even with no rows: it is what an
        // added field is appended to, and rendering the empty-state message *instead* of
        // it silently swallowed the first field added to a page that had no block.
        + ((rows.length || editable)
            ? `<table class="fm-table"><tbody>`
              + managedRows.map(([k, val, isList], i) =>
                  `<tr class="fm-managed-row${i === managedRows.length - 1 && ownRows.length ? ' fm-group-end' : ''}">`
                  + `<td class="fm-key">${_fmEsc(k)}</td>${valueCell(k, val, isList)}${removeCell(k)}</tr>`
                ).join('')
              // Only between two groups that both exist — a rule above nothing, or below
              // nothing, is a line with no meaning.
              + (managedRows.length && ownRows.length ? `<tr class="fm-sep"><td colspan="3"></td></tr>` : '')
              + ownRows.map(([k, val, isList]) =>
                  `<tr><td class="fm-key">${_fmEsc(k)}</td>${valueCell(k, val, isList)}${removeCell(k)}</tr>`
                ).join('')
              + `</tbody>`
              // The add row lives in the **same table**, as a tfoot, so its boxes line up
              // with the rows above by sharing their columns. As a separate flex row it
              // could only match by keeping a percentage and a cell padding in step with
              // the table's, which is alignment by coincidence.
              + (editable
                  ? `<tfoot><tr class="fm-add-row">
                         <td class="fm-key"><input type="text" id="fm-new-key" class="form-control" placeholder="${_fmEsc(t('fm.new-key'))}"></td>
                         <td class="fm-val"><input type="text" id="fm-new-val" class="form-control" placeholder="${_fmEsc(t('fm.new-value'))}"></td>
                         <td class="fm-rm-cell"><button type="button" id="fm-add-btn" class="btn btn-secondary btn-sm">${_fmEsc(t('fm.add'))}</button></td>
                     </tr></tfoot>`
                  : '')
              + `</table>`
            : '')
        + (editable
            ? `<label class="fm-multi-row" for="fm-new-multi">
                   <input type="checkbox" id="fm-new-multi">
                   <span>${_fmEsc(t('fm.multi'))}</span>
               </label>
               <p class="form-hint fm-save-hint">${_fmEsc(t('fm.save-hint'))}</p>`
            : '')
        + `</div>`;

    // Removals are collected by the × buttons, which strike the row through rather than
    // deleting it, so the change is visible and reversible before Save.
    const host     = document.getElementById('confirm-modal-message');
    const removals = new Set();
    const onClick = (e) => {
        const rm = e.target.closest('.fm-rm');
        if (rm) {
            e.preventDefault();
            const key = rm.dataset.key;
            const tr  = rm.closest('tr');
            if (removals.has(key)) { removals.delete(key); tr?.classList.remove('fm-removed'); }
            else                   { removals.add(key);    tr?.classList.add('fm-removed'); }
            return;
        }
        if (e.target.closest('#fm-add-btn')) {
            e.preventDefault();
            commitNewField();
        }
    };
    // Returns false only when the typed field was rejected; an empty row is "nothing to
    // add" rather than an error.
    const commitNewField = () => {
        const kEl = document.getElementById('fm-new-key');
        const vEl = document.getElementById('fm-new-val');
        const mEl = document.getElementById('fm-new-multi');
        const key = (kEl?.value || '').trim();
        if (!key) return true;
        if (!/^[A-Za-z_][A-Za-z0-9_.\- ]*$/.test(key)) {
            showToast(t('fm.bad-key'), 'error');
            return false;
        }
        const tbody = host?.querySelector('.fm-table tbody');
        if (!tbody) return false;
        const multi = !!mEl?.checked;
        const val   = vEl?.value || '';
        const row = document.createElement('tr');
        row.className = 'fm-new';
        row.innerHTML = `<td class="fm-key">${_fmEsc(key)}</td>`
            + `<td class="fm-val"><input type="text" class="form-control fm-input" data-key="${_fmEsc(key)}"`
            + `${multi ? ' data-multi="1"' : ''} value="${_fmEsc(val)}">`
            + (multi ? ` <span class="fm-multi-note">${_fmEsc(t('fm.multi-note'))}</span>` : '')
            + `</td><td class="fm-rm-cell"></td>`;
        tbody.appendChild(row);
        if (kEl) kEl.value = '';
        if (vEl) vEl.value = '';
        // The checkbox is deliberately left as it was: somebody adding one list is often
        // adding several. Focus returns to the key box so the next field can be typed
        // straight away without reaching for the mouse.
        kEl?.focus();
        return true;
    };

    // Enter inside the add row adds the field and **keeps the dialog open**.
    //
    // confirmModal listens for Enter on `document`, so without stopping the event here
    // tabbing to Add and pressing Enter saved and closed the dialog — which makes entering
    // several fields from the keyboard impossible. Stopped at this element, which the event
    // reaches first on its way up. preventDefault() matters for the button: Enter on a
    // focused button would otherwise also fire a click and add the field twice.
    const onKeydown = (e) => {
        if (e.key !== 'Enter') return;
        if (!e.target.closest('#fm-new-key, #fm-new-val, #fm-add-btn')) return;
        e.preventDefault();
        e.stopPropagation();
        commitNewField();
    };
    host?.addEventListener('click', onClick);
    host?.addEventListener('keydown', onKeydown);

    let confirmed = false;
    try {
        confirmed = await confirmModal(t('fm.title'), editable
            ? { messageHtml: body, confirmLabel: t('btn.save'), cancelLabel: t('btn.cancel') }
            : { messageHtml: body, confirmLabel: t('btn.close'), hideCancel: true });
    } finally {
        host?.removeEventListener('click', onClick);
        host?.removeEventListener('keydown', onKeydown);
    }
    if (!editable || !confirmed) return;

    // A field typed into the add row but never added is still a field the user asked for.
    commitNewField();

    // Only what actually changed: an unchanged field must not be rewritten, or the block
    // gains a diff for a save that changed nothing.
    const original = new Map(rows.map(([k, val]) => [k, val]));
    const updates  = {};
    host?.querySelectorAll('.fm-input').forEach(inp => {
        const key = inp.dataset.key;
        if (removals.has(key)) return;
        if (original.has(key) && original.get(key) === inp.value) return;
        updates[key] = inp.dataset.multi ? _fmSplitList(inp.value) : inp.value;
    });
    if (!Object.keys(updates).length && !removals.size) {
        showToast(t('fm.unchanged'), 'info');
        return;
    }

    const res = await api.call('set_frontmatter', {
        file: state.currentPagePath,
        updates: JSON.stringify(updates),
        removals: JSON.stringify([...removals]),
        // The baseline this panel was drawn from, so a file that moved on since is not
        // blindly overwritten (the server compares and refuses).
        base_mtime: state.currentPageLastUpdated || 0,
        base_size: state.currentPageSize ?? -1,
    }, 'POST');

    if (!res.success) {
        showToast(res.message || t('fm.save-failed'), 'error');
        return;
    }
    state.currentPageFrontmatter = res.frontmatter ?? null;
    state.currentPageFrontmatterNested = res.frontmatter_nested ?? null;
    state.currentPageFrontmatterManaged = res.frontmatter_managed ?? null;
    updateFrontmatterBadge();
    // A metadata write changes the file, so the open-page watcher has to be told — exactly
    // as a page save tells it — or it sees the author's own write as an external change and
    // reloads the page under them.
    state.currentPageLastUpdated = res.lastUpdated ?? state.currentPageLastUpdated;
    state.currentPageSize = res.size ?? state.currentPageSize;
    rebaselineFileWatch(state.currentPagePath, res.lastUpdated, res.size);
    showToast(res.unchanged ? t('fm.unchanged') : t('fm.saved'), 'success');
};

export const init = () => {
    initCopy();
    initMove();

    document.getElementById('copy-btn').addEventListener('click', handleCopy);
    document.getElementById('move-btn').addEventListener('click', handleMove);
    document.getElementById('rename-btn').addEventListener('click', handleRename);
    document.getElementById('backlinks-btn').addEventListener('click', handleBacklinks);
    document.getElementById('print-btn').addEventListener('click', handlePrint);
    document.getElementById('metadata-btn')?.addEventListener('click', showFrontmatter);
    // The title-row indicator is the same action, not a proxy click: it is a shortcut
    // to the panel for people who never open the … menu.
    document.getElementById('frontmatter-badge')?.addEventListener('click', showFrontmatter);
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
