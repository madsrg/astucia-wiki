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
//   number                   a JSON number (or null). `decimals` (0–6) is how many it shows,
//                            `unit` an optional word shown after it ("kr", "kg", "%"). Sorted
//                            as a number; a view filter may compare: "> 10", "<= 5", "= 3".
//   boolean                  Yes/No: true / false (no value reads as No).
//   comments                 an append-only log: [{ id, by, name, at, text }]. The **server**
//                            stamps id (from `nextEntryId`), by (uid), name and at (ISO time)
//                            on save; the browser sends a new comment as { text } only, and
//                            may drop one only if it is the author's or an admin's
//                            (wiki_list_comments_stamp() in lists.php refuses anything else).
//   lookup                   items of another list in the same Space. The value is an array
//                            of that list's item ids; `lookup` = { list: <page id>,
//                            field: <its field shown>, multiple: bool }
//                            `lookup.extras`: 'inline' shows its "also show" fields beside
//                            each value in its own cell; 'columns' (or none) as their columns.
//   lookup_field             read-only: another field of the item(s) a lookup points at —
//                            SharePoint's "also show". { source: <lookup field id>,
//                            field: <target field id> }. A real column, so views, filters,
//                            sorting and export handle it like any other.
//   object                   a "Group of fields": data that belongs to this item alone (an
//                            address, its debit cards). `fields` are its sub-fields (the
//                            GROUP_TYPES only — one level, no lookups), `summary` the ids of
//                            those its one-line text shows, `multiple` = "Allow several".
//                            The value is { <sub-field id>: value } — or, with several, an
//                            array of those, each carrying an `id` from the list's
//                            `nextEntryId`, never reused, so an entry stays itself however
//                            the entries are reordered.
//
// The Title field carries `role: 'title'`: it can be renamed but not deleted, and an item
// must have one. Lists created before the role existed get it on load (normalizeList).
import { state } from '../core/state.js';
import { api } from '../core/api.js';
import { t, getLanguage } from '../i18n/index.js';

// Fields a lookup may display or "also show": plain values. Not another lookup — a chain
// of lists to resolve for one cell is a second feature.
export const PROJECTABLE = ['autoincrement', 'text_single', 'text_multi', 'date', 'choice', 'number', 'boolean'];

// The types a Group of fields may hold. Plain values only: a lookup or a group inside a
// group is the second level we decided against.
export const GROUP_TYPES = ['text_single', 'text_multi', 'date', 'choice'];

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

// The Space lookups resolve in. Normally the open one; while an item of a list in another
// Space is edited from a generated page (modules/generators, {{_edit}}), that list's Space —
// a lookup is same-Space, and page ids mean nothing across Spaces. The cache is keyed by
// Space too, or list 12 of one Space would answer for list 12 of another.
let lookupSpace = null;
export const setLookupSpace = (space) => { lookupSpace = space ?? null; };
const lookupSpaceNow = () => lookupSpace ?? state.currentSpace ?? '';
const foreign = () => lookupSpace !== null && lookupSpace !== (state.currentSpace ?? '');
const ck = (id) => `${lookupSpaceNow()}:${id}`;

const TTL_MS = 30000;
const cache = new Map();        // "space:page id" → { at, name, data } (data null when unreadable)

/** The loaded target list for a page id, or null when it is not (yet) available. */
export const targetList = (id) => cache.get(ck(id))?.data || null;
export const targetName = (id) => cache.get(ck(id))?.name || (foreign() ? null : listById(id)?.name) || null;

const lookupFields = (data) => (data?.columns || []).filter(c => c.type === 'lookup' && c.lookup?.list != null);

/**
 * Fetch the lists `data`'s lookups point at. Returns true when anything new arrived, so a
 * renderer can paint once with what it has and again when the rest is in.
 * @param {string[]} extra  more page ids to load (the properties dialog's chosen target)
 */
export const loadLookupTargets = async (data, { force = false, extra = [] } = {}) => {
    const ids = [...new Set([...lookupFields(data).map(c => String(c.lookup.list)), ...extra.map(String)])];
    const now = Date.now();
    const due = ids.filter(id => force || !cache.has(ck(id)) || now - cache.get(ck(id)).at > TTL_MS);
    if (!due.length) return false;
    const space = lookupSpaceNow();
    const away = foreign();
    await Promise.all(due.map(async (id) => {
        const key = `${space}:${id}`;
        let ref = away ? null : listById(id);
        if (away) {
            // Another Space's tree is not loaded: ask for the path, in that Space only.
            const r = await api.call('get_path_from_id', { pageid: id, space });
            if (r.success && r.path?.endsWith('.list') && (!r.space || r.space === space)) {
                ref = { path: r.path, name: r.path.split('/').pop().replace(/\.list$/, '') };
            }
        }
        if (!ref) { cache.set(key, { at: now, name: null, data: null }); return; }
        const res = await api.call('get', away ? { file: ref.path, space } : { file: ref.path });
        let parsed = null;
        if (res.success) { try { parsed = normalizeList(JSON.parse(res.data)); } catch { parsed = null; } }
        cache.set(key, { at: now, name: ref.name, data: parsed });
    }));
    return true;
};

/** Is anything a lookup in `data` points at still unknown to the cache? */
export const lookupsPending = (data) => lookupFields(data).some(c => !cache.has(ck(c.lookup.list)));

// ── Displaying a value ────────────────────────────────────────────────────────

const lookupIds = (value) => (Array.isArray(value) ? value : (value == null || value === '' ? [] : [value]))
    .map(v => parseInt(v, 10)).filter(Number.isFinite);

/** A number or Yes/No value as it reads; anything else as its text. */
export const formatValue = (col, v) => {
    if (col?.type === 'number') {
        if (v == null || v === '' || !Number.isFinite(Number(v))) return '';
        const d = Math.min(6, Math.max(0, parseInt(col.decimals, 10) || 0));
        const text = Number(v).toLocaleString(getLanguage() || undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
        return col.unit ? `${text} ${col.unit}` : text;
    }
    if (col?.type === 'boolean') return v === true ? t('list.yes') : t('list.no');
    return v == null ? '' : String(v);
};

/** The plain text of one field of a target item. */
const plainValue = (target, fieldId, item) => {
    const col = (target?.columns || []).find(c => c.id === fieldId);
    return formatValue(col, item?.[fieldId]);
};

/** A Comments field's entries, oldest first: only well-formed ones. */
export const commentEntries = (value) => (Array.isArray(value) ? value : [])
    .filter(e => e && typeof e === 'object' && typeof e.text === 'string');

/** A comment's time as the reader's locale writes it. */
export const commentTime = (at) => {
    const d = new Date(at);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString(getLanguage() || undefined, { dateStyle: 'medium', timeStyle: 'short' });
};

/** What a field sorts by: a number as a number, Yes before No, else its text. */
export const sortValue = (col, item, columns) => {
    if (col.type === 'number') { const n = Number(item[col.id]); return item[col.id] == null || item[col.id] === '' || !Number.isFinite(n) ? null : n; }
    if (col.type === 'boolean') return item[col.id] === true ? 0 : 1;
    if (col.type === 'comments') return commentEntries(item[col.id]).length;
    return cellText(col, item, columns);
};

/**
 * Does an item pass a view filter on this field? Text "contains", as always — except a
 * Number, where "> 10", ">= 10", "< 10", "<= 10" or "= 10" compares (a bare number is "=").
 */
export const filterMatches = (col, item, value, columns) => {
    const needle = String(value ?? '').trim();
    if (col?.type === 'number') {
        const m = needle.replace(',', '.').match(/^(<=|>=|<|>|=)?\s*(-?\d+(?:\.\d+)?)$/);
        if (m) {
            const n = Number(item[col.id]);
            if (item[col.id] == null || item[col.id] === '' || !Number.isFinite(n)) return false;
            const x = Number(m[2]);
            return { '<': n < x, '<=': n <= x, '>': n > x, '>=': n >= x, '=': n === x }[m[1] || '='];
        }
    }
    const text = col ? cellText(col, item, columns) : String(item?.[col?.id] ?? '');
    return text.toLowerCase().includes(needle.toLowerCase());
};

/**
 * The text a field shows for an item — the table cell, the item view, filters, sorting,
 * the embed. A lookup is its targets' display field, joined with "; " as SharePoint does;
 * a target item that has since been deleted says so rather than vanishing.
 */
export const cellText = (col, item, columns) => {
    if (!col || !item) return '';
    if (col.type === 'object') {
        return groupEntries(col, item[col.id]).map(e => groupSummary(col, e)).join('; ');
    }
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
    // Every comment's text, so a filter finds a word in any of them.
    if (col.type === 'comments') return commentEntries(item[col.id]).map(e => e.text).join('; ');
    return formatValue(col, item[col.id]);
};

// ── Group of fields ───────────────────────────────────────────────────────────

/** A group's value as a list of entries: one (or none) for a single group, every
 *  well-formed entry for one that allows several. */
export const groupEntries = (col, value) => {
    const isEntry = (e) => e != null && typeof e === 'object' && !Array.isArray(e);
    if (col.multiple) return Array.isArray(value) ? value.filter(isEntry) : [];
    return isEntry(value) ? [value] : [];
};

/**
 * Make sure the list has its entry counter (`nextEntryId`), starting past any entry id already
 * in the file — a list written by hand, or before the counter, must not hand out an id twice.
 */
export const ensureEntryCounter = (data) => {
    if (Number.isInteger(data.nextEntryId)) return;
    let max = 0;
    (data.columns || []).filter(c => c.type === 'object' && c.multiple).forEach(c =>
        (data.items || []).forEach(i => groupEntries(c, i[c.id]).forEach(e => { if (Number.isInteger(e.id)) max = Math.max(max, e.id); })));
    data.nextEntryId = max + 1;
};

/** The sub-fields a group's summary shows: those picked, else the first two. */
const summaryFields = (col) => {
    const ids = (col.fields || []).map(f => f.id);
    const picked = (col.summary || []).filter(id => ids.includes(id));
    return picked.length ? picked : ids.slice(0, 2);
};

/** One entry as one line — "Main St 1, Oslo": its summary fields, empty ones left out. */
export const groupSummary = (col, entry) => summaryFields(col)
    .map(id => entry?.[id]).filter(v => v != null && String(v).trim() !== '')
    .map(String).join(', ');

/**
 * A lookup's values one by one, for renderers that draw them as separate entities rather
 * than one string: [{ text, deleted }], or null for any other kind of field.
 *
 * A `lookup_field` returns one part per id of its *source*, in the same order and including
 * a blank part where the target item is gone or has no value — so its parts line up row for
 * row with the lookup's own, and "Beta Corp" sits beside "Bergen", not beside "Oslo".
 */
export const cellParts = (col, item, columns) => {
    if (!col || !item) return null;
    // A group's several entries are entities too — one pill each, like a lookup's values.
    if (col.type === 'object') {
        return col.multiple ? groupEntries(col, item[col.id]).map(e => ({ text: groupSummary(col, e), deleted: false })) : null;
    }
    if (col.type !== 'lookup' && col.type !== 'lookup_field') return null;
    const source = col.type === 'lookup' ? col
        : (columns || state.currentListData?.columns || []).find(c => c.id === col.source);
    if (!source) return [];
    const ids = lookupIds(item[source.id]);
    const target = targetList(source.lookup?.list);
    const inlineExtras = col.type === 'lookup' && col.lookup?.extras === 'inline'
        ? (columns || state.currentListData?.columns || []).filter(c => c.type === 'lookup_field' && c.source === col.id) : [];
    return ids.map(id => {
        if (!target) return { text: col.type === 'lookup' ? '#' + id : '', deleted: false };
        const hit = target.items.find(i => i.id === id);
        if (!hit) return { text: col.type === 'lookup' ? t('list.lookup-deleted', { id }) : '', deleted: true };
        const field = col.type === 'lookup' ? (col.lookup.field || titleField(target)?.id) : col.field;
        const part = { text: plainValue(target, field, hit), deleted: false };
        // Inline extras: the "also show" fields' values for this item, beside its title.
        if (col.type === 'lookup' && inlineExtras.length) {
            part.extras = inlineExtras.map(p => plainValue(target, p.field, hit)).filter(v => v !== '');
        }
        return part;
    });
};

/**
 * The parts as DOM: one pill per value, stacked. `projected` for an "also show" field.
 * `max` caps how many are drawn (the table), the rest summed up as one "+N" pill.
 */
export const partsElement = (parts, projected = false, max = Infinity) => {
    const ul = document.createElement('ul');
    ul.className = 'lookup-values' + (projected ? ' is-projected' : '');
    const pill = (text, cls, extras = []) => {
        const li = document.createElement('li');
        li.className = 'lookup-value' + cls;
        // A blank still takes its line, or the values below it would shift up a row.
        li.textContent = text || '\u00a0';
        if (extras.length) {
            const x = document.createElement('span');
            x.className = 'lookup-extras';
            x.textContent = ' · ' + extras.join(' · ');
            li.appendChild(x);
        }
        return li;
    };
    parts.slice(0, max).forEach(p =>
        ul.appendChild(pill(p.text, (p.deleted ? ' is-deleted' : '') + (p.text ? '' : ' is-blank'), p.extras || [])));
    if (parts.length > max) ul.appendChild(pill(`+${parts.length - max}`, ' is-more'));
    return ul;
};

/** The same, as an HTML string for renderers that build markup (the {list:ID} embed). */
export const partsHtml = (parts, escape, projected = false, group = false) => `<ul class="lookup-values${projected ? ' is-projected' : ''}${group ? ' is-group' : ''}">` + parts.map(p =>
    `<li class="lookup-value${p.deleted ? ' is-deleted' : ''}${p.text ? '' : ' is-blank'}">${p.text ? escape(p.text) : '&nbsp;'}${p.extras?.length ? `<span class="lookup-extras"> · ${p.extras.map(escape).join(' · ')}</span>` : ''}</li>`
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
