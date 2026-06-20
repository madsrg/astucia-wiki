import { state } from '../core/state.js';
import { saveListData } from './data.js';
import { confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';

export const openItemViewModal = (itemData) => {
    const itemViewModalContent = document.getElementById('item-view-modal-content');
    itemViewModalContent.innerHTML = '';
    state.currentListData.columns.forEach(col => {
        const value = itemData[col.id] || '';
        const formGroup = document.createElement('div');
        formGroup.className = 'view-item-row';
        formGroup.innerHTML = `<label>${col.name}</label><div class="view-item-value">${value}</div>`;
        itemViewModalContent.appendChild(formGroup);
    });
    const itemViewModal = document.getElementById('item-view-modal');
    itemViewModal.dataset.id = itemData.id;
    itemViewModal.classList.remove('hidden');
};

export const openItemModal = (itemId = null) => {
    state.editingItemId = itemId;
    const itemModalForm = document.getElementById('item-modal-form');
    const itemModalTitle = document.getElementById('item-modal-title');
    itemModalForm.innerHTML = '';
    const itemData = itemId ? state.currentListData.items.find(i => i.id === itemId) : null;
    itemModalTitle.textContent = itemData ? 'Edit Item' : 'Add Item';

    state.currentListData.columns.forEach(col => {
        if (col.type === 'autoincrement') return;
        const value = itemData ? itemData[col.id] || '' : '';
        const formRow = document.createElement('div');
        formRow.className = 'form-item-row';

        const labelHTML = `<label for="field-${col.id}">${col.name}</label>`;
        let inputHTML = '';
        switch (col.type) {
            case 'text_multi':
                inputHTML = `<textarea id="field-${col.id}" name="${col.id}" class="form-control">${value}</textarea>`;
                break;
            case 'date':
                inputHTML = `<input type="date" id="field-${col.id}" name="${col.id}" value="${value}" class="form-control">`;
                break;
            case 'choice': {
                const optionsHTML = col.options.map(opt => `<option value="${opt}" ${opt === value ? 'selected' : ''}>${opt}</option>`).join('');
                inputHTML = `<select id="field-${col.id}" name="${col.id}" class="form-control">${optionsHTML}</select>`;
                break;
            }
            default:
                inputHTML = `<input type="text" id="field-${col.id}" name="${col.id}" value="${value}" class="form-control">`;
        }
        const descHTML = col.description ? `<small class="field-helptext">${col.description}</small>` : '';
        formRow.innerHTML = labelHTML + `<div class="form-item-field">${inputHTML}${descHTML}</div>`;
        itemModalForm.appendChild(formRow);
    });
    document.getElementById('item-modal').classList.remove('hidden');
};

export const saveItem = async () => {
    const itemModalForm = document.getElementById('item-modal-form');
    const formData = new FormData(itemModalForm);
    const newItemData = {};
    for (const [key, value] of formData.entries()) {
        newItemData[key] = value;
    }

    if (state.editingItemId !== null) {
        const itemIndex = state.currentListData.items.findIndex(i => i.id === state.editingItemId);
        if (itemIndex > -1) {
            state.currentListData.items[itemIndex] = { ...state.currentListData.items[itemIndex], ...newItemData };
        }
    } else {
        const autoincrementCol = state.currentListData.columns.find(c => c.type === 'autoincrement');
        if (autoincrementCol) {
            newItemData[autoincrementCol.id] = state.currentListData.nextItemId;
            state.currentListData.nextItemId++;
        }
        newItemData.id = newItemData[autoincrementCol?.id];
        state.currentListData.items.push(newItemData);
    }

    await saveListData();
    document.getElementById('item-modal').classList.add('hidden');
};

export const deleteItem = async (itemId) => {
    if (!await confirmModal('Delete this item?', { confirmLabel: 'Delete', dangerous: true, icon: icons.trash })) return;
    state.currentListData.items = state.currentListData.items.filter(i => i.id !== itemId);
    await saveListData();
};

export const saveColumn = async () => {
    const columnNameInput = document.getElementById('column-name');
    const columnTypeSelect = document.getElementById('column-type');
    const columnVisibleSelect = document.getElementById('column-visible');
    const choiceOptionsInput = document.getElementById('choice-options');
    const columnDescInput = document.getElementById('column-desc');
    const { showToast } = await import('../core/utils.js');

    const name = columnNameInput.value.trim();
    const type = columnTypeSelect.value;
    const isVisible = columnVisibleSelect.value === 'true';

    if (!name) { showToast('Column name cannot be empty.', 'error'); return; }

    const newColumn = {
        id: `col_${new Date().getTime()}`,
        name,
        type,
        showInListView: isVisible,
        description: columnDescInput?.value.trim() || '',
    };

    if (type === 'choice') {
        newColumn.options = choiceOptionsInput.value.split(',').map(s => s.trim()).filter(Boolean);
    }

    state.currentListData.columns.push(newColumn);
    await saveListData();
    document.getElementById('column-modal').classList.add('hidden');
};

export const openListPropsModal = () => {
    const colsContainer = document.getElementById('list-props-columns');
    colsContainer.innerHTML = '';

    const typeLabels = { autoincrement: 'Auto ID', text_single: 'Text', text_multi: 'Multi-line', date: 'Date', choice: 'Choice' };

    const xIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    state.currentListData.columns.forEach(col => {
        if (col.type === 'autoincrement') return;
        const row = document.createElement('div');
        row.className = 'list-props-row';
        row.dataset.colId = col.id;
        const typeLabel = typeLabels[col.type] || col.type;
        const safeName = col.name.replace(/"/g, '&quot;');
        const safeDesc = (col.description || '').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        row.innerHTML = `
            <div class="list-props-header-row">
                <input class="form-control list-props-name-input" type="text" value="${safeName}">
                <span class="list-props-type-badge">${typeLabel}</span>
                <button type="button" class="btn btn-sm btn-danger list-props-delete-btn" title="Delete column">${xIcon}</button>
            </div>
            <div>
                <label class="list-props-desc-label" data-i18n="col.desc-label">Description</label>
                <textarea class="form-control list-props-desc-input" rows="4" data-i18n-placeholder="col.desc-ph" placeholder="Shown as help text when creating or editing items">${safeDesc}</textarea>
            </div>`;
        colsContainer.appendChild(row);
    });

    colsContainer.querySelectorAll('.list-props-delete-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const row = e.target.closest('.list-props-row');
            const col = state.currentListData.columns.find(c => c.id === row.dataset.colId);
            if (!col) return;
            if (!await confirmModal(`Delete column "${col.name}"?`, { confirmLabel: 'Delete', dangerous: true, icon: icons.trash })) return;
            row.remove();
        });
    });

    document.getElementById('list-props-modal').classList.remove('hidden');
};

export const saveListProps = async () => {
    const colsContainer = document.getElementById('list-props-columns');
    const remainingIds = new Set([...colsContainer.querySelectorAll('.list-props-row')].map(r => r.dataset.colId));

    colsContainer.querySelectorAll('.list-props-row').forEach(row => {
        const col = state.currentListData.columns.find(c => c.id === row.dataset.colId);
        if (!col) return;
        const newName = row.querySelector('.list-props-name-input').value.trim();
        if (newName) col.name = newName;
        col.description = row.querySelector('.list-props-desc-input').value.trim();
    });

    state.currentListData.columns = state.currentListData.columns.filter(c => c.type === 'autoincrement' || remainingIds.has(c.id));
    await saveListData();
    document.getElementById('list-props-modal').classList.add('hidden');
};
