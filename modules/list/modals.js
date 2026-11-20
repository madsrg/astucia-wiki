// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// The item dialogs: viewing one item, and adding or editing one.
//
// Every value is put into the page with `textContent` / `.value`, never as markup. These
// forms used to be built as HTML strings with the item's values spliced in, so a value of
// `<img src=x onerror=…>` ran script for everybody who opened the item — and a Lookup shows
// values from *another* list, which would have widened that to anyone able to edit any list
// in the Space. Field names and help text are author-written too, and are treated the same.
//
// A **Group of fields** is edited in place: a single group as a box of its sub-fields, one
// that allows several as a stack of entries — each with its summary as a header, ↑ ↓ ✕, and
// collapsed to that header when there are more than COLLAPSE_AFTER. A group's inputs carry
// no `name`, so the form's FormData never sees them; each group editor collects its own
// value (`_collect()`), as the lookup picker keeps its own (`_values`).
//
// A **Comments** field is a log: earlier comments are shown, read-only, with ✕ on those the
// reader may remove (their own; an admin's, any), and a box for a new one. The new comment is
// sent as { text } alone — the server stamps who and when (lists.php), so a comment cannot be
// posted in somebody else's name.
//
// The add/edit form normally edits the open list. `openItemModal(id, external)` edits another
// one — a list behind a generated page, possibly in another Space ({{_edit}},
// modules/generators): `external` = { data, space, save(data) → Promise<bool>, onClose }.
import { state } from '../core/state.js';
import { saveListData } from './data.js';
import { confirmModal, showToast } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { cellText, cellParts, partsElement, loadLookupTargets, pickerOptions, titleField, normalizeList,
         groupEntries, groupSummary, ensureEntryCounter, commentEntries, commentTime, setLookupSpace } from './fields.js';

// The list the add/edit form is working on when it is not the open one (see above).
let external = null;
const formData = () => external ? external.data : state.currentListData;

/** May the reader remove this comment? Their own, or any for an admin (the server decides). */
const mayRemoveComment = (c) => window.WIKI_ROLE === 'admin' || (Number(c.by) > 0 && Number(c.by) === Number(window.WIKI_USER_UID));

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

// ── Viewing an item ───────────────────────────────────────────────────────────

export const openItemViewModal = async (itemData) => {
    const data = state.currentListData;
    setLookupSpace(null);
    await loadLookupTargets(data);
    const content = document.getElementById('item-view-modal-content');
    content.innerHTML = '';
    const inlineLookups = new Set(data.columns.filter(c => c.type === 'lookup' && c.lookup?.extras === 'inline').map(c => c.id));
    data.columns.forEach(col => {
        // Shown beside its lookup's values already.
        if (col.type === 'lookup_field' && inlineLookups.has(col.source)) return;
        const row = el('div', 'view-item-row');
        const value = el('div', 'view-item-value');
        if (col.type === 'object') {
            groupView(col, itemData[col.id], value);
            row.append(el('label', '', col.name), value);
            content.appendChild(row);
            return;
        }
        if (col.type === 'comments') {
            value.appendChild(commentLog(itemData[col.id]));
            row.append(el('label', '', col.name), value);
            content.appendChild(row);
            return;
        }
        const parts = cellParts(col, itemData, data.columns);
        if (parts?.length) { value.classList.add('has-lookup'); value.appendChild(partsElement(parts, col.type === 'lookup_field')); }
        else value.textContent = cellText(col, itemData, data.columns);
        row.append(el('label', '', col.name), value);
        content.appendChild(row);
    });
    const modal = document.getElementById('item-view-modal');
    modal.dataset.id = itemData.id;
    modal.classList.remove('hidden');
};

/** A Comments field's entries, oldest first: who and when above each text. `remove(c)` adds ✕. */
const commentLog = (value, remove = null) => {
    const ul = el('ul', 'comment-log');
    commentEntries(value).forEach(c => {
        const li = el('li', 'comment-entry');
        li.dataset.id = c.id ?? '';
        const head = el('div', 'comment-head');
        head.append(el('span', 'comment-author', c.name || ''), el('span', 'comment-time', commentTime(c.at)));
        if (remove && mayRemoveComment(c)) {
            const x = el('button', 'btn btn-sm btn-secondary comment-remove', '✕');
            x.type = 'button';
            x.title = t('list.comment-remove');
            x.addEventListener('click', () => remove(c, li));
            head.appendChild(x);
        }
        li.append(head, el('div', 'comment-text', c.text));
        ul.appendChild(li);
    });
    return ul;
};

/** The form's Comments editor: the log (removable where allowed) and a box for a new one. */
const commentsEditor = (col, initial) => {
    const wrap = el('div', 'comments-editor');
    wrap.dataset.colId = col.id;
    let kept = commentEntries(initial);
    wrap.appendChild(commentLog(kept, (c, li) => {
        kept = kept.filter(k => k !== c);
        li.remove();
    }));
    const box = el('textarea', 'form-control comment-new');
    box.id = `field-${col.id}`;
    box.rows = 2;
    box.placeholder = t('list.comment-add-ph');
    wrap.appendChild(box);
    // Kept entries exactly as they were (the server compares them), the new one as text only.
    wrap._collect = () => {
        const text = box.value.trim();
        return text ? [...kept, { text }] : [...kept];
    };
    return wrap;
};

/** A group in the item view: its sub-fields as label/value pairs, each entry its own block. */
const groupView = (col, value, box) => {
    const entries = groupEntries(col, value);
    if (!entries.length) return;
    box.classList.add('has-group');
    entries.forEach(entry => {
        const block = el('div', 'group-view-entry');
        (col.fields || []).forEach(sf => {
            const v = entry[sf.id];
            if (v == null || String(v) === '') return;
            const line = el('div', 'group-view-line');
            line.append(el('span', 'group-view-label', sf.name), el('span', 'group-view-value', String(v)));
            block.appendChild(line);
        });
        box.appendChild(block);
    });
};

// ── The lookup picker ─────────────────────────────────────────────────────────
//
// A type-ahead over the target list's items: chips for what is chosen, a box to type in,
// and up to MAX_SUGGEST matches under it. One value replaces the chip; several add chips.
// The chosen ids live on the element (`_values`), which saveItem reads — a FormData entry
// per id would have to be reassembled into the array the file stores anyway.

const MAX_SUGGEST = 20;

const lookupPicker = (col, initial) => {
    const options = pickerOptions(col);
    const labelOf = (id) => options.find(o => o.id === id)?.label ?? t('list.lookup-deleted', { id });
    const wrap = el('div', 'lookup-picker');
    wrap.dataset.colId = col.id;
    wrap._values = (Array.isArray(initial) ? initial : (initial == null || initial === '' ? [] : [initial]))
        .map(v => parseInt(v, 10)).filter(Number.isFinite);

    const chips = el('div', 'lookup-chips');
    const input = el('input', 'form-control lookup-input');
    input.type = 'text';
    input.id = `field-${col.id}`;
    input.placeholder = t('list.lookup-find-ph');
    input.autocomplete = 'off';
    const suggest = el('div', 'lookup-suggest hidden');
    wrap.append(chips, input, suggest);

    const drawChips = () => {
        chips.innerHTML = '';
        wrap._values.forEach(id => {
            const chip = el('span', 'lookup-chip');
            chip.dataset.id = id;
            chip.appendChild(el('span', '', labelOf(id)));
            const x = el('button', 'lookup-chip-remove', '×');
            x.type = 'button';
            x.title = t('list.lookup-remove');
            x.addEventListener('click', () => { wrap._values = wrap._values.filter(v => v !== id); drawChips(); });
            chip.appendChild(x);
            chips.appendChild(chip);
        });
    };
    const choose = (id) => {
        wrap._values = col.lookup?.multiple ? [...new Set([...wrap._values, id])] : [id];
        input.value = '';
        suggest.classList.add('hidden');
        drawChips();
        input.focus();
    };
    const drawSuggest = () => {
        const needle = input.value.trim().toLocaleLowerCase();
        const hits = options
            .filter(o => !wrap._values.includes(o.id))
            .filter(o => !needle || o.label.toLocaleLowerCase().includes(needle))
            .slice(0, MAX_SUGGEST);
        suggest.innerHTML = '';
        if (!hits.length) suggest.appendChild(el('div', 'lookup-suggest-empty', t('list.lookup-none-found')));
        hits.forEach(o => {
            const row = el('div', 'lookup-suggest-item', o.label);
            row.dataset.id = o.id;
            // mousedown, not click: the input's blur would hide the list before a click lands.
            row.addEventListener('mousedown', (e) => { e.preventDefault(); choose(o.id); });
            suggest.appendChild(row);
        });
        suggest.classList.remove('hidden');
    };
    input.addEventListener('focus', drawSuggest);
    input.addEventListener('input', drawSuggest);
    input.addEventListener('blur', () => suggest.classList.add('hidden'));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const first = suggest.querySelector('.lookup-suggest-item');
            if (first) choose(parseInt(first.dataset.id, 10));
        } else if (e.key === 'Escape' && !suggest.classList.contains('hidden')) {
            e.stopPropagation();          // close the suggestions, not the dialog
            suggest.classList.add('hidden');
        } else if (e.key === 'Backspace' && !input.value && wrap._values.length) {
            wrap._values = wrap._values.slice(0, -1);
            drawChips();
        }
    });
    drawChips();
    return wrap;
};

// ── Inputs ────────────────────────────────────────────────────────────────────

/**
 * The input for a plain value — a field, or a group's sub-field. `blankChoice` gives a Choice
 * an empty first option, which a group's sub-field needs: an untouched group must stay empty,
 * not be saved holding the first choice.
 */
const plainInput = (type, options, value, { blankChoice = false } = {}) => {
    let input;
    switch (type) {
        case 'text_multi':
            input = el('textarea', 'form-control');
            input.value = value;
            break;
        case 'date':
            input = el('input', 'form-control');
            input.type = 'date';
            input.value = value;
            break;
        case 'number':
            input = el('input', 'form-control');
            input.type = 'number';
            input.step = 'any';
            input.value = value === null || value === undefined ? '' : String(value);
            break;
        case 'boolean':
            input = el('input', 'form-check-input');
            input.type = 'checkbox';
            input.checked = value === true;
            break;
        case 'choice': {
            input = el('select', 'form-control');
            const opts = [...(options || [])];
            // A value no longer among the choices is kept, not silently replaced by the first.
            if (value && !opts.includes(value)) opts.unshift(value);
            if (blankChoice) opts.unshift('');
            opts.forEach(opt => {
                const o = el('option', '', opt);
                o.value = opt;
                input.appendChild(o);
            });
            input.value = blankChoice ? (value || '') : (value || opts[0] || '');
            break;
        }
        default:
            input = el('input', 'form-control');
            input.type = 'text';
            input.value = value;
    }
    return input;
};

// ── The group editor ──────────────────────────────────────────────────────────

const COLLAPSE_AFTER = 3;
let groupSeq = 0;

/** Sub-field rows for one entry (or a single group). Returns [element, read()]. */
const subFieldRows = (col, entry) => {
    const box = el('div', 'group-fields');
    const inputs = [];
    const key = ++groupSeq;
    (col.fields || []).forEach(sf => {
        const row = el('div', 'group-field-row');
        const input = plainInput(sf.type, sf.options, entry?.[sf.id] ?? '', { blankChoice: true });
        input.id = `field-${col.id}-${key}-${sf.id}`;
        input.dataset.subId = sf.id;
        const label = el('label', '', sf.name);
        label.htmlFor = input.id;
        row.append(label, input);
        box.appendChild(row);
        inputs.push([sf.id, input]);
    });
    const read = () => {
        const out = {};
        inputs.forEach(([id, input]) => { if (input.value !== '') out[id] = input.value; });
        return out;
    };
    return [box, read];
};

const groupEditor = (col, initial) => {
    const wrap = el('div', 'group-editor');
    wrap.dataset.colId = col.id;

    if (!col.multiple) {
        const [box, read] = subFieldRows(col, groupEntries(col, initial)[0] || null);
        box.classList.add('group-single');
        wrap.appendChild(box);
        // Nothing filled in is no group at all — the cell stays empty, as before it was edited.
        wrap._collect = () => { const v = read(); return Object.keys(v).length ? v : null; };
        return wrap;
    }

    const list = el('div', 'group-entries');
    const entries = [];          // [{ id, block, read }] in display order
    const draw = () => entries.forEach(e => list.appendChild(e.block));
    const move = (e, by) => {
        const i = entries.indexOf(e);
        const j = i + by;
        if (j < 0 || j >= entries.length) return;
        [entries[i], entries[j]] = [entries[j], entries[i]];
        draw();
    };
    const addEntry = (entry, open) => {
        const block = el('div', 'group-entry' + (open ? ' is-open' : ''));
        const head = el('div', 'group-entry-head');
        const title = el('span', 'group-entry-summary');
        const [fields, read] = subFieldRows(col, entry);
        const e = { id: Number.isInteger(entry?.id) ? entry.id : null, block, read };
        const retitle = () => { title.textContent = groupSummary(col, read()) || t('list.group-entry-new'); };
        fields.addEventListener('input', retitle);
        fields.addEventListener('change', retitle);
        title.addEventListener('click', () => block.classList.toggle('is-open'));
        const btn = (label, text, fn) => {
            const b = el('button', 'btn btn-sm btn-secondary group-entry-btn', text);
            b.type = 'button';
            b.title = t(label);
            b.addEventListener('click', fn);
            return b;
        };
        const remove = btn('list.group-entry-remove', '✕', () => { entries.splice(entries.indexOf(e), 1); block.remove(); });
        remove.classList.add('group-entry-remove');
        head.append(title,
            btn('list.group-entry-up', '↑', () => move(e, -1)),
            btn('list.group-entry-down', '↓', () => move(e, 1)),
            remove);
        block.append(head, fields);
        retitle();
        entries.push(e);
        list.appendChild(block);
        return block;
    };
    const initialEntries = groupEntries(col, initial);
    const collapse = initialEntries.length > COLLAPSE_AFTER;
    initialEntries.forEach(entry => addEntry(entry, !collapse));

    const add = el('button', 'btn btn-sm btn-secondary group-add-entry', t('list.group-add-entry'));
    add.type = 'button';
    add.addEventListener('click', () => {
        const block = addEntry(null, true);
        block.querySelector('input, textarea, select')?.focus();
    });
    wrap.append(list, add);
    // Entries in their order; a new one has no id yet (saveItem numbers it), and one left
    // entirely blank is dropped rather than stored as an empty card.
    wrap._collect = () => entries
        .map(e => ({ id: e.id, values: e.read() }))
        .filter(e => Object.keys(e.values).length)
        .map(e => (e.id != null ? { id: e.id, ...e.values } : { ...e.values }));
    return wrap;
};

// ── Adding or editing an item ─────────────────────────────────────────────────

export const openItemModal = async (itemId = null, ext = null) => {
    external = ext;
    setLookupSpace(ext ? ext.space : null);
    const data = normalizeList(formData());
    state.editingItemId = itemId;
    // The picker needs the target lists' items, fresh: somebody may have added the one
    // you are about to pick a moment ago.
    await loadLookupTargets(data, { force: true });

    const form = document.getElementById('item-modal-form');
    form.innerHTML = '';
    const itemData = itemId ? data.items.find(i => i.id === itemId) : null;
    document.getElementById('item-modal-title').textContent = itemData ? t('btn.edit-item') : t('list.add-item');

    data.columns.forEach(col => {
        // ItemId is assigned, and a lookup's "also show" fields follow the lookup.
        if (col.type === 'autoincrement' || col.type === 'lookup_field') return;
        const value = itemData ? itemData[col.id] ?? '' : '';
        const row = el('div', 'form-item-row');
        const label = el('label', '', col.name + (col.role === 'title' ? ' *' : ''));
        label.htmlFor = `field-${col.id}`;
        const field = el('div', 'form-item-field');

        let input;
        if (col.type === 'lookup') input = lookupPicker(col, value);
        else if (col.type === 'object') input = groupEditor(col, itemData ? itemData[col.id] : null);
        else if (col.type === 'comments') input = commentsEditor(col, itemData ? itemData[col.id] : null);
        else input = plainInput(col.type, col.options, value);
        if (!['lookup', 'object', 'comments'].includes(col.type)) {
            input.id = `field-${col.id}`;
            input.name = col.id;
        }
        if (col.type === 'boolean') input.dataset.bool = col.id;
        field.appendChild(input);
        if (col.type === 'number' && col.unit) {
            field.classList.add('has-unit');
            field.appendChild(el('span', 'field-unit', col.unit));
        }
        if (col.description) field.appendChild(el('small', 'field-helptext', col.description));
        row.append(label, field);
        form.appendChild(row);
    });
    document.getElementById('item-modal').classList.remove('hidden');
    form.querySelector('input, textarea, select')?.focus();
};

/** Close the add/edit form, forgetting the list it was on if that was not the open one. */
export const closeItemModal = () => {
    document.getElementById('item-modal').classList.add('hidden');
    const ext = external;
    external = null;
    setLookupSpace(null);
    ext?.onClose?.();
};

export const saveItem = async () => {
    const data = formData();
    const form = document.getElementById('item-modal-form');
    const newItemData = {};
    for (const [key, value] of new FormData(form).entries()) newItemData[key] = value;
    form.querySelectorAll('.lookup-picker').forEach(p => { newItemData[p.dataset.colId] = [...p._values]; });
    form.querySelectorAll('.group-editor, .comments-editor').forEach(g => { newItemData[g.dataset.colId] = g._collect(); });
    // A box unticked is absent from FormData; Yes/No is true or false, never a string.
    form.querySelectorAll('input[data-bool]').forEach(b => { newItemData[b.dataset.bool] = b.checked; });
    // A number is stored as a number; an empty box is no value.
    for (const col of data.columns.filter(c => c.type === 'number')) {
        if (!(col.id in newItemData)) continue;
        const raw = String(newItemData[col.id]).trim();
        if (raw === '') { newItemData[col.id] = null; continue; }
        const n = Number(raw);
        if (!Number.isFinite(n)) {
            showToast(t('list.number-invalid', { name: col.name }), 'error');
            document.getElementById(`field-${col.id}`)?.focus();
            return;
        }
        newItemData[col.id] = n;
    }
    // New entries are numbered now, from the list's counter: an entry keeps its number
    // however the entries are reordered later, and a removed one's number is never reused.
    if (data.columns.some(c => c.type === 'object' && c.multiple)) {
        ensureEntryCounter(data);
        data.columns.filter(c => c.type === 'object' && c.multiple).forEach(c => {
            (newItemData[c.id] || []).forEach(e => { if (e.id == null) e.id = data.nextEntryId++; });
        });
    }

    // Title is the one field an item must have — it is what a Lookup in another list shows.
    const title = titleField(data);
    if (title && !String(newItemData[title.id] ?? '').trim()) {
        showToast(t('list.title-required', { name: title.name }), 'error');
        document.getElementById(`field-${title.id}`)?.focus();
        return;
    }

    if (state.editingItemId !== null) {
        const index = data.items.findIndex(i => i.id === state.editingItemId);
        if (index > -1) data.items[index] = { ...data.items[index], ...newItemData };
    } else {
        const auto = data.columns.find(c => c.type === 'autoincrement');
        if (auto) {
            newItemData[auto.id] = data.nextItemId;
            data.nextItemId++;
        }
        newItemData.id = newItemData[auto?.id];
        data.items.push(newItemData);
    }

    const saved = external ? await external.save(data) : await saveListData();
    if (saved !== false) closeItemModal();
};

export const deleteItem = async (itemId) => {
    if (!await confirmModal(t('list.item-delete-confirm'), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
    state.currentListData.items = state.currentListData.items.filter(i => i.id !== itemId);
    await saveListData();
};
