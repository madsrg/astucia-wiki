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
//   `lookup.extras` says how they are *shown*: 'inline' — beside each looked-up value in the
//   lookup's own cell ("Beta Corp · Bergen"), their columns hidden from All items — or
//   'columns', each its own column. No setting is 'columns', which every lookup was before
//   the choice existed; a new lookup starts 'inline'.
// - Reordering stays in View settings, which already did it for All items.
// - **One field is open at a time.** Each field is one line — name, type, delete — and its
//   settings (choices, lookup, group, description) fold out below it when opened, closing
//   whichever was open. A list can have many fields; showing every one's settings at once made
//   the dialog a long scroll. A new field, and a field that fails validation, open themselves.
// - A **Group of fields** (`object`) edits its sub-fields inside its own row: the same name,
//   type-fixed-once-it-exists and choices rules, one level only (GROUP_TYPES — no lookups,
//   no groups). Saving applies what the shape change means to the data: a deleted sub-field
//   takes its values with it; "Allow several" switched on makes each value the first entry;
//   switched off is refused while any item has more than one.
import { state } from '../core/state.js';
import { saveListData } from './data.js';
import { confirmModal, showToast } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { normalizeList, spaceLists, loadLookupTargets, targetList, targetName, projectableFields, titleField, GROUP_TYPES, groupEntries, ensureEntryCounter } from './fields.js';

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const TYPES = ['text_single', 'text_multi', 'date', 'choice', 'number', 'boolean', 'comments', 'lookup', 'object'];
const typeLabel = (type) => t({
    autoincrement: 'list.type-auto', text_single: 'list.type-text', text_multi: 'list.type-multi',
    date: 'list.type-date', choice: 'list.type-choice', lookup: 'list.type-lookup', object: 'list.type-group',
    number: 'list.type-number', boolean: 'list.type-boolean', comments: 'list.type-comments',
}[type] || 'list.type-text');

const chevron = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
const xIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

let draft = [];          // the editable fields, in order; see openListPropsModal
let seq = 0;

const newId = () => `col_${Date.now()}_${++seq}`;
const newSubId = () => `sf_${Date.now()}_${++seq}`;
const newSub = () => ({ id: newSubId(), name: '', type: 'text_single', _new: true });

/** A Choice field's choices, one per line — for a field and for a group's sub-field. */
const choicesEditor = (d) => {
    const g = el('div', 'list-props-field');
    g.appendChild(el('label', 'list-props-desc-label', t('list.choices-label')));
    const ta = el('textarea', 'form-control list-props-choices');
    ta.rows = 3;
    ta.value = (d.options || []).join('\n');
    ta.addEventListener('input', () => {
        d.options = ta.value.split('\n').map(s => s.trim()).filter(Boolean);
    });
    g.appendChild(ta);
    return g;
};

/**
 * A Number's settings: how many decimals it shows, and a unit after it. Both only change how
 * a value reads, so unlike its type they stay editable once the field exists.
 */
const numberConfig = (d) => {
    const g = el('div', 'list-props-field list-props-number');
    const dl = el('label', 'list-props-desc-label', t('list.number-decimals'));
    const dec = el('select', 'form-control list-props-decimals');
    for (let i = 0; i <= 6; i++) {
        const o = el('option', '', String(i));
        o.value = String(i);
        dec.appendChild(o);
    }
    dec.value = String(Math.min(6, Math.max(0, parseInt(d.decimals, 10) || 0)));
    dec.addEventListener('change', () => { d.decimals = parseInt(dec.value, 10); });
    const ul = el('label', 'list-props-desc-label', t('list.number-unit'));
    const unit = el('input', 'form-control list-props-unit');
    unit.type = 'text';
    unit.maxLength = 12;
    unit.placeholder = t('list.number-unit-ph');
    unit.value = d.unit || '';
    unit.addEventListener('input', () => { d.unit = unit.value; });
    g.append(dl, dec, ul, unit);
    return g;
};

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

    // How the extra fields are shown. Either way they stay fields — filter, sort and export
    // by them, and View settings can show one as a column.
    const how = el('div', 'list-props-field list-props-extras');
    how.appendChild(el('label', 'list-props-desc-label', t('list.lookup-extras')));
    ['inline', 'columns'].forEach(mode => {
        const row = el('label', 'space-settings-switch-row list-props-check');
        const rb = el('input', 'list-props-lookup-extras');
        rb.type = 'radio';
        rb.name = `extras-${d.id}`;
        rb.value = mode;
        rb.checked = (d.lookup.extras || 'columns') === mode;
        rb.addEventListener('change', () => { if (rb.checked) d.lookup.extras = mode; });
        row.append(rb, el('span', '', t(`list.lookup-extras-${mode}`)));
        how.appendChild(row);
    });
    how.appendChild(el('small', 'field-helptext', t('list.lookup-extras-hint')));
    box.appendChild(how);
};

/**
 * A group's settings: "Allow several", its sub-fields and which of them its one-line
 * summary shows. Drawn inside the group's row; sub-field rows are compact versions of a
 * field row (name, type — fixed once it exists — and a Choice's choices).
 */
const groupConfig = (d, box) => {
    box.innerHTML = '';
    box.appendChild(el('p', 'field-helptext list-props-group-help', t('list.group-help')));

    const several = el('label', 'space-settings-switch-row list-props-check');
    const sbox = el('input', 'list-props-group-several');
    sbox.type = 'checkbox';
    sbox.checked = !!d.multiple;
    sbox.addEventListener('change', () => { d.multiple = sbox.checked; });
    several.append(sbox, el('span', '', t('list.group-several')));
    box.appendChild(several);

    const wrap = el('div', 'list-props-field');
    wrap.appendChild(el('label', 'list-props-desc-label', t('list.group-fields')));
    const subs = el('div', 'list-props-subfields');
    wrap.appendChild(subs);
    box.appendChild(wrap);

    const summaryWrap = el('div', 'list-props-field');
    const drawSummary = () => {
        summaryWrap.innerHTML = '';
        summaryWrap.appendChild(el('label', 'list-props-desc-label', t('list.group-summary')));
        const grid = el('div', 'list-props-also');
        d.fields.forEach(sf => {
            const row = el('label', 'space-settings-switch-row list-props-check');
            const cb = el('input', 'list-props-group-summary');
            cb.type = 'checkbox';
            cb.value = sf.id;
            cb.checked = (d.summary || []).includes(sf.id);
            cb.addEventListener('change', () => {
                d.summary = cb.checked ? [...new Set([...(d.summary || []), sf.id])] : (d.summary || []).filter(x => x !== sf.id);
            });
            row.append(cb, el('span', '', sf.name || t('list.field-name-ph')));
            grid.appendChild(row);
        });
        summaryWrap.appendChild(grid);
        summaryWrap.appendChild(el('small', 'field-helptext', t('list.group-summary-hint')));
    };

    const drawSub = (sf) => {
        const row = el('div', 'list-props-subrow' + (sf._new ? ' is-new' : ''));
        row.dataset.subId = sf.id;
        const head = el('div', 'list-props-header-row');
        const name = el('input', 'form-control list-props-sub-name');
        name.type = 'text';
        name.value = sf.name;
        name.placeholder = t('list.field-name-ph');
        name.addEventListener('input', () => { sf.name = name.value; name.classList.remove('is-invalid'); drawSummary(); });
        head.appendChild(name);

        const extra = el('div', 'list-props-config');
        const drawExtra = () => {
            extra.innerHTML = '';
            if (sf.type === 'choice') extra.appendChild(choicesEditor(sf));
        };
        if (sf._new) {
            const sel = el('select', 'form-control list-props-type-select list-props-sub-type');
            GROUP_TYPES.forEach(type => {
                const o = el('option', '', typeLabel(type));
                o.value = type;
                sel.appendChild(o);
            });
            sel.value = sf.type;
            sel.addEventListener('change', () => {
                sf.type = sel.value;
                if (sf.type === 'choice' && !sf.options) sf.options = [];
                drawExtra();
            });
            head.appendChild(sel);
        } else {
            head.appendChild(el('span', 'list-props-type-badge', typeLabel(sf.type)));
        }
        const del = el('button', 'btn btn-sm btn-danger list-props-delete-btn list-props-sub-delete');
        del.type = 'button';
        del.title = t('list.delete-field');
        del.innerHTML = xIcon;
        del.addEventListener('click', async () => {
            if (!sf._new && !await confirmModal(t('list.delete-field-confirm', { name: sf.name }),
                { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
            d.fields = d.fields.filter(x => x !== sf);
            d.summary = (d.summary || []).filter(x => x !== sf.id);
            row.remove();
            drawSummary();
        });
        head.appendChild(del);
        row.append(head, extra);
        drawExtra();
        subs.appendChild(row);
        return row;
    };
    d.fields.forEach(drawSub);

    const add = el('button', 'btn btn-sm btn-secondary list-props-group-add', t('list.group-add-field'));
    add.type = 'button';
    add.addEventListener('click', () => {
        const sf = newSub();
        d.fields.push(sf);
        drawSub(sf).querySelector('.list-props-sub-name').focus();
        drawSummary();
    });
    wrap.appendChild(add);
    drawSummary();
    box.appendChild(summaryWrap);
};

/** Open one field's settings and close every other's. */
const openRow = (id) => {
    document.querySelectorAll('#list-props-columns .list-props-row').forEach(r => {
        const open = r.dataset.colId === id && !r.classList.contains('is-open');
        r.classList.toggle('is-open', open);
        r.querySelector('.list-props-toggle')?.setAttribute('aria-expanded', String(open));
        // Settings opened near the bottom of a long list would otherwise be out of sight.
        if (open) r.scrollIntoView({ block: 'nearest' });
    });
};

const drawRow = (d, container) => {
    const row = el('div', 'list-props-row');
    row.dataset.colId = d.id;
    if (d._new) row.classList.add('is-new');

    const head = el('div', 'list-props-header-row');
    const toggle = el('button', 'btn btn-icon btn-sm list-props-toggle');
    toggle.type = 'button';
    toggle.title = t('list.field-settings');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = chevron;
    toggle.addEventListener('click', () => openRow(d.id));
    head.appendChild(toggle);
    const name = el('input', 'form-control list-props-name-input');
    name.type = 'text';
    name.value = d.name;
    name.placeholder = t('list.field-name-ph');
    name.addEventListener('input', () => { d.name = name.value; name.classList.remove('is-invalid'); });
    head.appendChild(name);
    // The line itself opens the field too, outside its controls.
    head.addEventListener('click', (e) => { if (e.target === head) openRow(d.id); });

    const config = el('div', 'list-props-config');
    const drawConfig = () => {
        config.innerHTML = '';
        if (d.type === 'choice') {
            config.appendChild(choicesEditor(d));
        } else if (d.type === 'number') {
            config.appendChild(numberConfig(d));
        } else if (d.type === 'comments') {
            config.appendChild(el('p', 'field-helptext', t('list.comments-help')));
        } else if (d.type === 'lookup') {
            lookupConfig(d, config);
        } else if (d.type === 'object') {
            groupConfig(d, config);
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
            if (d.type === 'lookup' && !d.lookup) { d.lookup = { list: null, field: null, multiple: false, extras: 'inline' }; d._show = []; }
            if (d.type === 'choice' && !d.options) d.options = [];
            if (d.type === 'number' && d.decimals == null) d.decimals = 0;
            if (d.type === 'object' && !d.fields) { d.fields = [newSub()]; d.multiple = false; d.summary = []; }
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
    const body = el('div', 'list-props-body');
    body.appendChild(config);
    row.appendChild(body);

    const descWrap = el('div', 'list-props-field');
    descWrap.appendChild(el('label', 'list-props-desc-label', t('col.desc-label')));
    const desc = el('textarea', 'form-control list-props-desc-input');
    desc.rows = 2;
    desc.placeholder = t('col.desc-ph');
    desc.value = d.description || '';
    desc.addEventListener('input', () => { d.description = desc.value; });
    descWrap.appendChild(desc);
    body.appendChild(descWrap);

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
    // How a lookup showed its extra fields, which Save compares against.
    draft.filter(d => d.type === 'lookup').forEach(d => { d._origExtras = d.lookup?.extras || 'columns'; });
    // A group's sub-fields as drafts, and the shape it had, which Save compares against.
    draft.filter(d => d.type === 'object').forEach(d => {
        d.fields = (d.fields || []).map(sf => ({ ...sf, _new: false }));
        d.summary = d.summary || [];
        d._origMultiple = !!d.multiple;
    });

    const container = document.getElementById('list-props-columns');
    container.innerHTML = '';
    draft.forEach(d => drawRow(d, container));
    document.getElementById('list-props-modal').classList.remove('hidden');
};

export const addDraftField = () => {
    const d = { id: newId(), name: '', type: 'text_single', description: '', showInListView: true, _new: true, _show: [] };
    draft.push(d);
    const row = drawRow(d, document.getElementById('list-props-columns'));
    openRow(d.id);
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
        if (d.type === 'object') {
            if (!d.fields.length) return [t('list.group-fields-required', { name }), d.id];
            const subSeen = new Set();
            for (const sf of d.fields) {
                const sub = (sf.name || '').trim();
                if (!sub) return [t('list.group-field-name-required', { name }), d.id];
                if (subSeen.has(sub.toLocaleLowerCase())) return [t('list.group-field-name-taken', { name, sub }), d.id];
                subSeen.add(sub.toLocaleLowerCase());
                if (sf.type === 'choice' && !(sf.options || []).length) return [t('list.choices-required', { name: `${name} › ${sub}` }), d.id];
            }
            // Several → one is safe only while no item has more than one entry.
            if (!d._new && d._origMultiple && !d.multiple) {
                const n = (state.currentListData.items || []).filter(i => groupEntries({ multiple: true }, i[d.id]).length > 1).length;
                if (n) return [t('list.group-several-off-refused', { name, n }), d.id];
            }
        }
    }
    return null;
};

const clean = (d) => {
    const c = { id: d.id, name: d.name.trim(), type: d.type, description: (d.description || '').trim() };
    if (d.role) c.role = d.role;
    c.showInListView = d.showInListView !== false;
    if (d.type === 'choice') c.options = [...(d.options || [])];
    if (d.type === 'number') {
        c.decimals = Math.min(6, Math.max(0, parseInt(d.decimals, 10) || 0));
        const unit = (d.unit || '').trim();
        if (unit) c.unit = unit;
    }
    if (d.type === 'lookup') {
        c.lookup = { list: parseInt(d.lookup.list, 10), field: d.lookup.field, multiple: !!d.lookup.multiple };
        if (d.lookup.extras) c.lookup.extras = d.lookup.extras;
    }
    if (d.type === 'object') {
        c.multiple = !!d.multiple;
        c.fields = d.fields.map(sf => {
            const f = { id: sf.id, name: sf.name.trim(), type: sf.type };
            if (sf.type === 'choice') f.options = [...(sf.options || [])];
            return f;
        });
        const ids = c.fields.map(f => f.id);
        c.summary = (d.summary || []).filter(id => ids.includes(id));
    }
    return c;
};

export const saveListProps = async () => {
    const problem = validate();
    if (problem) {
        showToast(problem[0], 'error');
        const bad = document.querySelector(`#list-props-columns .list-props-row[data-col-id="${CSS.escape(problem[1])}"]`);
        if (bad && !bad.classList.contains('is-open')) openRow(problem[1]);
        const input = bad?.querySelector('.list-props-name-input');
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
            // Shown beside the lookup's values, a new extra field starts hidden from All
            // items — a column of its own as well would show it twice.
            out.splice(at + 1, 0, { id: newId(), name: `${d.name.trim()}: ${fname}`, type: 'lookup_field',
                                    source: d.id, field: fieldId, showInListView: d.lookup.extras !== 'inline' });
        });
    });

    // A lookup switched between the two ways of showing its extra fields takes their columns
    // in All items along: hidden when they move into the lookup's cell, shown when they move
    // out. Named views keep their own choice of columns.
    draft.filter(d => d.type === 'lookup' && !d._new && (d.lookup.extras || 'columns') !== d._origExtras).forEach(d => {
        const show = (d.lookup.extras || 'columns') === 'columns';
        out.forEach((c, i) => { if (c.type === 'lookup_field' && c.source === d.id) out[i] = { ...c, showInListView: show }; });
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
    applyGroupChanges(data, out);
    data.columns = out;

    await loadLookupTargets(data);
    await saveListData();
    document.getElementById('list-props-modal').classList.add('hidden');
    return true;
};

/**
 * What a group's new shape means for the items, before the new columns are stored:
 * deleted sub-fields lose their values, and a switch of "Allow several" rewraps each value.
 * One → several makes the value the first entry, with an id from the list's `nextEntryId`
 * (several → one was refused in validate() unless every item has at most one entry).
 */
const applyGroupChanges = (data, columns) => {
    const before = new Map(data.columns.map(c => [c.id, c]));
    // The counter entries are numbered from, started past any id already in the file.
    if (columns.some(c => c.type === 'object' && c.multiple)) ensureEntryCounter(data);
    columns.filter(c => c.type === 'object').forEach(c => {
        const old = before.get(c.id);
        if (!old) return;
        const kept = new Set(c.fields.map(f => f.id));
        const gone = (old.fields || []).map(f => f.id).filter(id => !kept.has(id));
        data.items.forEach(item => {
            let entries = groupEntries(old, item[c.id]);
            entries.forEach(e => gone.forEach(id => { delete e[id]; }));
            if (!!old.multiple === !!c.multiple) return;
            if (c.multiple) {
                item[c.id] = entries.map(e => ({ id: data.nextEntryId++, ...e }));
            } else {
                const { id: _drop, ...first } = entries[0] || {};
                item[c.id] = entries.length ? first : null;
            }
        });
    });
};
