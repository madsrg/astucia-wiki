// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// What a list's fields are, and how a value is shown — one place for the table, the item
// dialogs, filters, sorting and the {list:ID} embed, so a Lookup reads the same in each.
//
// The file format keeps its name for a field: `columns`. The UI says "field"; renaming the
// key would break every existing list, both exports and the AI tools that read the JSON.
//
// Field types:
//   autoincrement            the hidden ItemId
//   text_single, text_multi, date, choice
//   lookup                   items of another list in the same Space. The value is an array
//                            of that list's item ids; `lookup` = { list: <page id>,
//                            field: <its field shown>, multiple: bool }
//   lookup_field             read-only: another field of the item(s) a lookup points at —
//                            SharePoint's "also show". { source: <lookup field id>,
//                            field: <target field id> }. A real column, so views, filters,
//                            sorting and export handle it like any other.
//
// The Title field carries `role: 'title'`: it can be renamed but not deleted, and an item
// must have one. Lists created before the role existed get it on load (normalizeList).
import { state } from '../core/state.js';
import { api } from '../core/api.js';
import { t } from '../i18n/index.js';

// Fields a lookup may display or "also show": plain values. Not another lookup — a chain
// of lists to resolve for one cell is a second feature.
export const PROJECTABLE = ['autoincrement', 'text_single', 'text_multi', 'date', 'choice'];

/**
 * Give a list loaded from disk what the current code expects. Idempotent and in memory
 * only — the next save writes it, so opening a list never writes the file.
 */
export const normalizeList = (data) => {
    if (!data || !Array.isArray(data.columns)) return data;
    if (!data.columns.some(c => c.role === 'title')) {
        // A new list has always had Title as col_2; a list built by hand or by an AI may
        // not, and then its first single-line text field is the closest thing.
        const title = data.columns.find(c => c.id === 'col_2' && c.type === 'text_single')
                   || data.columns.find(c => c.type === 'text_single');
        if (title) title.role = 'title';
    }
    if (!Array.isArray(data.items)) data.items = [];
    return data;
};

export const titleField = (data) => (data?.columns || []).find(c => c.role === 'title') || null;

// ── Lookup targets ────────────────────────────────────────────────────────────
//
// A lookup names its target list by page id, and the target must be in the same Space —
// so it is found in the current Space's tree data (`state.fullFileTree`, which holds every
// page even where the sidebar does not draw it). A list in another Space is never fetched,
// whatever id a hand-edited file carries: showing it would show that Space's data to
// people who may not read it.

/** Every .list in the current Space: [{ id, path, name }]. */
export const spaceLists = () => {
    const out = [];
    const walk = (items) => (items || []).forEach(i => {
        if (i.type === 'folder' && i.children) walk(i.children);
        else if (i.name?.endsWith('.list') && i.id != null) {
            out.push({ id: String(i.id), path: i.path, name: i.name.replace(/\.list$/, '') });
        }
    });
    walk(state.fullFileTree);
    return out.sort((a, b) => a.path.localeCompare(b.path, undefined, { sensitivity: 'base', numeric: true }));
};

const listById = (id) => spaceLists().find(l => l.id === String(id)) || null;

const TTL_MS = 30000;
const cache = new Map();        // page id → { at, name, data } (data null when unreadable)

/** The loaded target list for a page id, or null when it is not (yet) available. */
export const targetList = (id) => cache.get(String(id))?.data || null;
export const targetName = (id) => cache.get(String(id))?.name || listById(id)?.name || null;

const lookupFields = (data) => (data?.columns || []).filter(c => c.type === 'lookup' && c.lookup?.list != null);

/**
 * Fetch the lists `data`'s lookups point at. Returns true when anything new arrived, so a
 * renderer can paint once with what it has and again when the rest is in.
 * @param {string[]} extra  more page ids to load (the properties dialog's chosen target)
 */
export const loadLookupTargets = async (data, { force = false, extra = [] } = {}) => {
    const ids = [...new Set([...lookupFields(data).map(c => String(c.lookup.list)), ...extra.map(String)])];
    const now = Date.now();
    const due = ids.filter(id => force || !cache.has(id) || now - cache.get(id).at > TTL_MS);
    if (!due.length) return false;
    await Promise.all(due.map(async (id) => {
        const ref = listById(id);
        if (!ref) { cache.set(id, { at: now, name: null, data: null }); return; }
        const res = await api.call('get', { file: ref.path });
        let parsed = null;
        if (res.success) { try { parsed = normalizeList(JSON.parse(res.data)); } catch { parsed = null; } }
        cache.set(id, { at: now, name: ref.name, data: parsed });
    }));
    return true;
};

/** Is anything a lookup in `data` points at still unknown to the cache? */
export const lookupsPending = (data) => lookupFields(data).some(c => !cache.has(String(c.lookup.list)));

// ── Displaying a value ────────────────────────────────────────────────────────

const lookupIds = (value) => (Array.isArray(value) ? value : (value == null || value === '' ? [] : [value]))
    .map(v => parseInt(v, 10)).filter(Number.isFinite);

/** The plain text of one field of a target item. */
const plainValue = (target, fieldId, item) => {
    const v = item?.[fieldId];
    return v == null ? '' : String(v);
};

/**
 * The text a field shows for an item — the table cell, the item view, filters, sorting,
 * the embed. A lookup is its targets' display field, joined with "; " as SharePoint does;
 * a target item that has since been deleted says so rather than vanishing.
 */
export const cellText = (col, item, columns) => {
    if (!col || !item) return '';
    if (col.type === 'lookup') {
        const ids = lookupIds(item[col.id]);
        if (!ids.length) return '';
        const target = targetList(col.lookup?.list);
        if (!target) return ids.map(id => '#' + id).join('; ');
        const field = col.lookup.field || titleField(target)?.id;
        return ids.map(id => {
            const hit = target.items.find(i => i.id === id);
            return hit ? plainValue(target, field, hit) : t('list.lookup-deleted', { id });
        }).join('; ');
    }
    if (col.type === 'lookup_field') {
        const source = (columns || state.currentListData?.columns || []).find(c => c.id === col.source);
        if (!source) return '';
        const ids = lookupIds(item[source.id]);
        const target = targetList(source.lookup?.list);
        if (!ids.length || !target) return '';
        return ids.map(id => target.items.find(i => i.id === id))
            .filter(Boolean).map(hit => plainValue(target, col.field, hit)).join('; ');
    }
    const v = item[col.id];
    return v == null ? '' : String(v);
};

/**
 * A lookup's values one by one, for renderers that draw them as separate entities rather
 * than one string: [{ text, deleted }], or null for any other kind of field.
 *
 * A `lookup_field` returns one part per id of its *source*, in the same order and including
 * a blank part where the target item is gone or has no value — so its parts line up row for
 * row with the lookup's own, and "Beta Corp" sits beside "Bergen", not beside "Oslo".
 */
export const cellParts = (col, item, columns) => {
    if (!col || !item || (col.type !== 'lookup' && col.type !== 'lookup_field')) return null;
    const source = col.type === 'lookup' ? col
        : (columns || state.currentListData?.columns || []).find(c => c.id === col.source);
    if (!source) return [];
    const ids = lookupIds(item[source.id]);
    const target = targetList(source.lookup?.list);
    return ids.map(id => {
        if (!target) return { text: col.type === 'lookup' ? '#' + id : '', deleted: false };
        const hit = target.items.find(i => i.id === id);
        if (!hit) return { text: col.type === 'lookup' ? t('list.lookup-deleted', { id }) : '', deleted: true };
        const field = col.type === 'lookup' ? (col.lookup.field || titleField(target)?.id) : col.field;
        return { text: plainValue(target, field, hit), deleted: false };
    });
};

/** The parts as DOM: one pill per value, stacked. `projected` for an "also show" field. */
export const partsElement = (parts, projected = false) => {
    const ul = document.createElement('ul');
    ul.className = 'lookup-values' + (projected ? ' is-projected' : '');
    parts.forEach(p => {
        const li = document.createElement('li');
        li.className = 'lookup-value' + (p.deleted ? ' is-deleted' : '') + (p.text ? '' : ' is-blank');
        // A blank still takes its line, or the values below it would shift up a row.
        li.textContent = p.text || '\u00a0';
        ul.appendChild(li);
    });
    return ul;
};

/** The same, as an HTML string for renderers that build markup (the {list:ID} embed). */
export const partsHtml = (parts, escape, projected = false) => `<ul class="lookup-values${projected ? ' is-projected' : ''}">` + parts.map(p =>
    `<li class="lookup-value${p.deleted ? ' is-deleted' : ''}${p.text ? '' : ' is-blank'}">${p.text ? escape(p.text) : '&nbsp;'}</li>`
).join('') + '</ul>';

/** The fields of a target list a lookup may show, as [{ id, name }]. */
export const projectableFields = (target) =>
    (target?.columns || []).filter(c => PROJECTABLE.includes(c.type)).map(c => ({ id: c.id, name: c.name }));

/** The selectable items of a target list for a picker: [{ id, label }], by label. */
export const pickerOptions = (col) => {
    const target = targetList(col.lookup?.list);
    if (!target) return [];
    const field = col.lookup.field || titleField(target)?.id;
    return target.items
        .map(i => ({ id: i.id, label: plainValue(target, field, i) || '#' + i.id }))
        .sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: 'base', numeric: true }));
};
