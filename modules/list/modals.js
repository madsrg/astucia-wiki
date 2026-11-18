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
import { state } from '../core/state.js';
import { saveListData } from './data.js';
import { confirmModal, showToast } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { cellText, cellParts, partsElement, loadLookupTargets, pickerOptions, titleField, normalizeList } from './fields.js';

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

// ── Viewing an item ───────────────────────────────────────────────────────────

export const openItemViewModal = async (itemData) => {
    const data = state.currentListData;
    await loadLookupTargets(data);
    const content = document.getElementById('item-view-modal-content');
    content.innerHTML = '';
    data.columns.forEach(col => {
        const row = el('div', 'view-item-row');
        const value = el('div', 'view-item-value');
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

// ── Adding or editing an item ─────────────────────────────────────────────────

export const openItemModal = async (itemId = null) => {
    const data = normalizeList(state.currentListData);
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
        switch (col.type) {
            case 'text_multi':
                input = el('textarea', 'form-control');
                input.value = value;
                break;
            case 'date':
                input = el('input', 'form-control');
                input.type = 'date';
                input.value = value;
                break;
            case 'choice': {
                input = el('select', 'form-control');
                const opts = [...(col.options || [])];
                // A value no longer among the choices is kept, not silently replaced by the first.
                if (value && !opts.includes(value)) opts.unshift(value);
                opts.forEach(opt => {
                    const o = el('option', '', opt);
                    o.value = opt;
                    input.appendChild(o);
                });
                input.value = value || opts[0] || '';
                break;
            }
            case 'lookup':
                input = lookupPicker(col, value);
                break;
            default:
                input = el('input', 'form-control');
                input.type = 'text';
                input.value = value;
        }
        if (col.type !== 'lookup') {
            input.id = `field-${col.id}`;
            input.name = col.id;
        }
        field.appendChild(input);
        if (col.description) field.appendChild(el('small', 'field-helptext', col.description));
        row.append(label, field);
        form.appendChild(row);
    });
    document.getElementById('item-modal').classList.remove('hidden');
    form.querySelector('input, textarea, select')?.focus();
};

export const saveItem = async () => {
    const data = state.currentListData;
    const form = document.getElementById('item-modal-form');
    const newItemData = {};
    for (const [key, value] of new FormData(form).entries()) newItemData[key] = value;
    form.querySelectorAll('.lookup-picker').forEach(p => { newItemData[p.dataset.colId] = [...p._values]; });

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

    await saveListData();
    document.getElementById('item-modal').classList.add('hidden');
};

export const deleteItem = async (itemId) => {
    if (!await confirmModal(t('list.item-delete-confirm'), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
    state.currentListData.items = state.currentListData.items.filter(i => i.id !== itemId);
    await saveListData();
};
