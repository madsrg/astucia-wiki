// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// List properties: the one place a list's fields are managed — add, rename, describe,
// delete, a Choice field's choices, and a Lookup's settings. It replaced the toolbar's
// separate "Add column" dialog, which made adding a field a different place from every
// other thing you do to one.
//
// The dialog edits a **draft**, and Save writes it in one go: a field added and then
// removed again before saving never reaches the file, and Cancel (closing) leaves the list
// exactly as it was.
//
// - A field's **type is fixed once it exists**: there is no safe conversion of a Choice
//   with data into a Date, and SharePoint allows only a few for the same reason.
// - **Title** (`role: 'title'`) is renamed here but never deleted.
// - A Lookup's **"also show"** fields are real columns (`lookup_field`) generated on Save:
//   views, filters, sorting and export then need nothing special for them. They are not
//   rows in this dialog — they are ticked on their lookup — and follow its renames.
// - Reordering stays in View settings, which already did it for All items.
import { state } from '../core/state.js';
import { saveListData } from './data.js';
import { confirmModal, showToast } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { normalizeList, spaceLists, loadLookupTargets, targetList, targetName, projectableFields, titleField } from './fields.js';

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const TYPES = ['text_single', 'text_multi', 'date', 'choice', 'lookup'];
const typeLabel = (type) => t({
    autoincrement: 'list.type-auto', text_single: 'list.type-text', text_multi: 'list.type-multi',
    date: 'list.type-date', choice: 'list.type-choice', lookup: 'list.type-lookup',
}[type] || 'list.type-text');

const xIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

let draft = [];          // the editable fields, in order; see openListPropsModal
let seq = 0;

const newId = () => `col_${Date.now()}_${++seq}`;

/** The lookup settings block of a row. Re-drawn when its target list changes. */
const lookupConfig = (d, box) => {
    box.innerHTML = '';
    const lists = spaceLists().filter(l => l.path !== state.currentPagePath);

    if (d._new) {
        const g = el('div', 'list-props-field');
        g.appendChild(el('label', 'list-props-desc-label', t('list.lookup-list')));
        if (!lists.length) {
            g.appendChild(el('p', 'field-helptext', t('list.lookup-no-lists')));
            box.appendChild(g);
            return;
        }
        const sel = el('select', 'form-control list-props-lookup-list');
        const none = el('option', '', t('list.lookup-pick-list'));
        none.value = '';
        sel.appendChild(none);
        lists.forEach(l => {
            const o = el('option', '', l.path.replace(/\.list$/, ''));
            o.value = l.id;
            sel.appendChild(o);
        });
        sel.value = d.lookup.list != null ? String(d.lookup.list) : '';
        sel.addEventListener('change', async () => {
            d.lookup.list = sel.value || null;
            d.lookup.field = null;
            d._show = [];
            if (d.lookup.list) await loadLookupTargets(null, { extra: [d.lookup.list] });
            lookupConfig(d, box);
        });
        g.appendChild(sel);
        box.appendChild(g);
    } else {
        box.appendChild(el('p', 'field-helptext list-props-lookup-summary',
            targetName(d.lookup.list) ? t('list.lookup-summary', { list: targetName(d.lookup.list) })
                                      : t('list.lookup-list-missing')));
    }
    if (d.lookup.list == null) return;

    const target = targetList(d.lookup.list);
    const fields = projectableFields(target);
    if (!fields.length) return;
    if (!d.lookup.field) d.lookup.field = titleField(target)?.id || fields[0].id;

    const g2 = el('div', 'list-props-field');
    g2.appendChild(el('label', 'list-props-desc-label', t('list.lookup-display')));
    const fsel = el('select', 'form-control list-props-lookup-field');
    fields.forEach(f => {
        const o = el('option', '', f.name);
        o.value = f.id;
        fsel.appendChild(o);
    });
    fsel.value = d.lookup.field;
    fsel.addEventListener('change', () => { d.lookup.field = fsel.value; });
    g2.appendChild(fsel);
    box.appendChild(g2);

    const multi = el('label', 'space-settings-switch-row list-props-check');
    const mbox = el('input', 'list-props-lookup-multiple');
    mbox.type = 'checkbox';
    mbox.checked = !!d.lookup.multiple;
    mbox.addEventListener('change', () => { d.lookup.multiple = mbox.checked; });
    multi.append(mbox, el('span', '', t('list.lookup-multiple')));
    box.appendChild(multi);

    const also = el('div', 'list-props-field');
    also.appendChild(el('label', 'list-props-desc-label', t('list.lookup-also')));
    const grid = el('div', 'list-props-also');
    fields.forEach(f => {
        const row = el('label', 'space-settings-switch-row list-props-check');
        const cb = el('input', 'list-props-lookup-also');
        cb.type = 'checkbox';
        cb.value = f.id;
        cb.checked = d._show.includes(f.id);
        cb.addEventListener('change', () => {
            d._show = cb.checked ? [...new Set([...d._show, f.id])] : d._show.filter(x => x !== f.id);
        });
        row.append(cb, el('span', '', f.name));
        grid.appendChild(row);
    });
    also.appendChild(grid);
    box.appendChild(also);
};

const drawRow = (d, container) => {
    const row = el('div', 'list-props-row');
    row.dataset.colId = d.id;
    if (d._new) row.classList.add('is-new');

    const head = el('div', 'list-props-header-row');
    const name = el('input', 'form-control list-props-name-input');
    name.type = 'text';
    name.value = d.name;
    name.placeholder = t('list.field-name-ph');
    name.addEventListener('input', () => { d.name = name.value; name.classList.remove('is-invalid'); });
    head.appendChild(name);

    const config = el('div', 'list-props-config');
    const drawConfig = () => {
        config.innerHTML = '';
        if (d.type === 'choice') {
            const g = el('div', 'list-props-field');
            g.appendChild(el('label', 'list-props-desc-label', t('list.choices-label')));
            const ta = el('textarea', 'form-control list-props-choices');
            ta.rows = 3;
            ta.value = (d.options || []).join('\n');
            ta.addEventListener('input', () => {
                d.options = ta.value.split('\n').map(s => s.trim()).filter(Boolean);
            });
            g.appendChild(ta);
            config.appendChild(g);
        } else if (d.type === 'lookup') {
            lookupConfig(d, config);
        }
    };

    if (d._new) {
        const sel = el('select', 'form-control list-props-type-select');
        TYPES.forEach(type => {
            const o = el('option', '', typeLabel(type));
            o.value = type;
            sel.appendChild(o);
        });
        sel.value = d.type;
        sel.addEventListener('change', () => {
            d.type = sel.value;
            if (d.type === 'lookup' && !d.lookup) { d.lookup = { list: null, field: null, multiple: false }; d._show = []; }
            if (d.type === 'choice' && !d.options) d.options = [];
            drawConfig();
        });
        head.appendChild(sel);
    } else {
        head.appendChild(el('span', 'list-props-type-badge', typeLabel(d.type)));
    }

    if (d.role === 'title') {
        // Renamed, never deleted: it is what an item is called, and what a Lookup in
        // another list shows of it.
        const badge = el('span', 'list-props-type-badge list-props-title-badge', t('list.title-badge'));
        badge.title = t('list.title-badge-tip');
        head.appendChild(badge);
    } else {
        const del = el('button', 'btn btn-sm btn-danger list-props-delete-btn');
        del.type = 'button';
        del.title = t('list.delete-field');
        del.innerHTML = xIcon;
        del.addEventListener('click', async () => {
            if (!d._new && !await confirmModal(t('list.delete-field-confirm', { name: d.name }),
                { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
            draft = draft.filter(x => x !== d);
            row.remove();
        });
        head.appendChild(del);
    }
    row.appendChild(head);
    row.appendChild(config);

    const descWrap = el('div', 'list-props-field');
    descWrap.appendChild(el('label', 'list-props-desc-label', t('col.desc-label')));
    const desc = el('textarea', 'form-control list-props-desc-input');
    desc.rows = 2;
    desc.placeholder = t('col.desc-ph');
    desc.value = d.description || '';
    desc.addEventListener('input', () => { d.description = desc.value; });
    descWrap.appendChild(desc);
    row.appendChild(descWrap);

    drawConfig();
    container.appendChild(row);
    return row;
};

export const openListPropsModal = async () => {
    const data = normalizeList(state.currentListData);
    await loadLookupTargets(data);
    const projections = data.columns.filter(c => c.type === 'lookup_field');
    draft = data.columns
        .filter(c => c.type !== 'autoincrement' && c.type !== 'lookup_field')
        .map(c => ({
            ...structuredClone(c),
            _orig: c.name,
            _show: c.type === 'lookup' ? projections.filter(p => p.source === c.id).map(p => p.field) : [],
        }));

    const container = document.getElementById('list-props-columns');
    container.innerHTML = '';
    draft.forEach(d => drawRow(d, container));
    document.getElementById('list-props-modal').classList.remove('hidden');
};

export const addDraftField = () => {
    const d = { id: newId(), name: '', type: 'text_single', description: '', showInListView: true, _new: true, _show: [] };
    draft.push(d);
    const row = drawRow(d, document.getElementById('list-props-columns'));
    row.scrollIntoView({ block: 'nearest' });
    row.querySelector('.list-props-name-input').focus();
};

/** The first problem with the draft, as [message, row id], or null. */
const validate = () => {
    const seen = new Map();
    for (const d of draft) {
        const name = (d.name || '').trim();
        if (!name) return [t('list.field-name-required'), d.id];
        const key = name.toLocaleLowerCase();
        if (seen.has(key)) return [t('list.field-name-taken', { name }), d.id];
        seen.set(key, d.id);
        if (d.type === 'choice' && !(d.options || []).length) return [t('list.choices-required', { name }), d.id];
        if (d.type === 'lookup' && d.lookup?.list == null) return [t('list.lookup-target-required', { name }), d.id];
    }
    return null;
};

const clean = (d) => {
    const c = { id: d.id, name: d.name.trim(), type: d.type, description: (d.description || '').trim() };
    if (d.role) c.role = d.role;
    c.showInListView = d.showInListView !== false;
    if (d.type === 'choice') c.options = [...(d.options || [])];
    if (d.type === 'lookup') c.lookup = { list: parseInt(d.lookup.list, 10), field: d.lookup.field, multiple: !!d.lookup.multiple };
    return c;
};

export const saveListProps = async () => {
    const problem = validate();
    if (problem) {
        showToast(problem[0], 'error');
        const input = document.querySelector(`#list-props-columns .list-props-row[data-col-id="${CSS.escape(problem[1])}"] .list-props-name-input`);
        input?.classList.add('is-invalid');
        input?.focus();
        return false;
    }

    const data = state.currentListData;
    const byId = new Map(draft.map(d => [d.id, d]));
    const out = [];
    // Existing fields keep their place (View settings orders All items); deleted ones
    // drop out, with the "also show" fields of a lookup that went or was unticked.
    for (const c of data.columns) {
        if (c.type === 'autoincrement') { out.push(c); continue; }
        if (c.type === 'lookup_field') {
            const src = byId.get(c.source);
            if (!src || !src._show.includes(c.field)) continue;
            // Named after their lookup ("Customer: City"), so a rename carries over —
            // unless somebody renamed the field to something else of their own.
            const prefix = src._orig + ': ';
            out.push(c.name.startsWith(prefix) && src.name.trim() !== src._orig
                ? { ...c, name: src.name.trim() + ': ' + c.name.slice(prefix.length) } : c);
            continue;
        }
        const d = byId.get(c.id);
        if (d && !d._new) out.push(clean(d));
    }
    draft.filter(d => d._new).forEach(d => out.push(clean(d)));

    // "Also show" fields that do not exist yet go right after their lookup.
    draft.filter(d => d.type === 'lookup').forEach(d => {
        const target = targetList(d.lookup.list);
        d._show.forEach(fieldId => {
            if (out.some(c => c.type === 'lookup_field' && c.source === d.id && c.field === fieldId)) return;
            const fname = projectableFields(target).find(f => f.id === fieldId)?.name || fieldId;
            let at = out.findIndex(c => c.id === d.id);
            while (out[at + 1]?.type === 'lookup_field' && out[at + 1].source === d.id) at++;
            out.splice(at + 1, 0, { id: newId(), name: `${d.name.trim()}: ${fname}`, type: 'lookup_field',
                                    source: d.id, field: fieldId, showInListView: true });
        });
    });

    // A deleted field takes its values with it, and leaves no trace in a view.
    const kept = new Set(out.map(c => c.id));
    const gone = data.columns.filter(c => !kept.has(c.id)).map(c => c.id);
    if (gone.length) {
        data.items.forEach(item => gone.forEach(id => { delete item[id]; }));
        (data.views || []).forEach(v => {
            v.columns = (v.columns || []).filter(id => kept.has(id));
            v.filters = (v.filters || []).filter(f => kept.has(f.colId));
        });
    }
    data.columns = out;

    await loadLookupTargets(data);
    await saveListData();
    document.getElementById('list-props-modal').classList.add('hidden');
    return true;
};
