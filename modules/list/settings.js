import { state } from '../core/state.js';
import { saveListData } from './data.js';

// ── Filter rows ───────────────────────────────────────────────────────────────

const appendFilterRow = (container, colId, value) => {
    const data = state.currentListData;
    const row = document.createElement('div');
    row.className = 'filter-row';

    const select = document.createElement('select');
    select.className = 'filter-col-select form-control';
    data.columns.forEach(col => {
        const opt = document.createElement('option');
        opt.value = col.id;
        opt.textContent = col.name;
        if (col.id === colId) opt.selected = true;
        select.appendChild(opt);
    });

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'filter-value-input form-control';
    input.placeholder = 'Contains…';
    input.value = value;

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-danger';
    removeBtn.textContent = '✕';
    removeBtn.addEventListener('click', () => row.remove());

    row.append(select, input, removeBtn);
    container.appendChild(row);
};

const buildFiltersSection = (activeView) => {
    const section = document.getElementById('view-filters-section');
    section.innerHTML = '';
    section.classList.remove('hidden');

    const heading = document.createElement('div');
    heading.className = 'filters-section-heading';
    heading.textContent = 'Filters';
    section.appendChild(heading);

    const list = document.createElement('div');
    list.id = 'view-filters-list';
    section.appendChild(list);

    (activeView.filters || []).forEach(f => appendFilterRow(list, f.colId, f.value));

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-secondary';
    addBtn.style.marginTop = '4px';
    addBtn.textContent = '+ Add Filter';
    addBtn.addEventListener('click', () => appendFilterRow(list, state.currentListData.columns[0]?.id || '', ''));
    section.appendChild(addBtn);
};

// ── Column rows ───────────────────────────────────────────────────────────────

const appendSettingsRow = (container, col, isChecked) => {
    const row = document.createElement('div');
    row.className = 'settings-column-row';
    row.dataset.colId = col.id;

    const handle = document.createElement('span');
    handle.className = 'settings-col-handle';

    const upBtn = document.createElement('button');
    upBtn.type = 'button';
    upBtn.className = 'settings-move-btn';
    upBtn.title = 'Move up';
    upBtn.textContent = '↑';
    upBtn.addEventListener('click', () => {
        const prev = row.previousElementSibling;
        if (prev) container.insertBefore(row, prev);
    });

    const downBtn = document.createElement('button');
    downBtn.type = 'button';
    downBtn.className = 'settings-move-btn';
    downBtn.title = 'Move down';
    downBtn.textContent = '↓';
    downBtn.addEventListener('click', () => {
        const next = row.nextElementSibling;
        if (next) next.after(row);
    });

    handle.append(upBtn, downBtn);

    const label = document.createElement('label');
    label.className = 'settings-col-label';

    const cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.dataset.colId = col.id;
    cb.checked = isChecked;

    label.append(cb, document.createTextNode(' ' + col.name));
    row.append(handle, label);
    container.appendChild(row);
};

// ── Build full modal form ─────────────────────────────────────────────────────

const buildSettingsForm = () => {
    const data = state.currentListData;
    const activeViewId = state.activeListView;
    const activeView = activeViewId ? (data.views || []).find(v => v.id === activeViewId) : null;

    document.getElementById('view-settings-title').textContent =
        activeView ? `View: ${activeView.name}` : 'All Items';
    document.getElementById('delete-view-btn').classList.toggle('hidden', !activeView);

    const form = document.getElementById('view-settings-form');
    form.innerHTML = '';

    let orderedCols;
    if (activeView) {
        const inView = activeView.columns
            .map(id => data.columns.find(c => c.id === id)).filter(Boolean);
        const notInView = data.columns.filter(
            c => !activeView.columns.includes(c.id) && c.type !== 'autoincrement'
        );
        orderedCols = [...inView, ...notInView];
    } else {
        orderedCols = data.columns.filter(c => c.type !== 'autoincrement');
    }

    orderedCols.forEach(col => {
        const isChecked = activeView
            ? activeView.columns.includes(col.id)
            : col.showInListView !== false;
        appendSettingsRow(form, col, isChecked);
    });

    const filtersSection = document.getElementById('view-filters-section');
    if (activeView) {
        buildFiltersSection(activeView);
    } else {
        filtersSection.classList.add('hidden');
        filtersSection.innerHTML = '';
    }
};

// ── Public API ────────────────────────────────────────────────────────────────

export const openViewSettingsModal = () => {
    buildSettingsForm();
    document.getElementById('view-settings-modal').classList.remove('hidden');
};

export const init = () => {
    const viewSettingsBtn = document.getElementById('view-settings-btn');
    const viewSettingsModal = document.getElementById('view-settings-modal');
    const viewSettingsCloseBtn = document.getElementById('view-settings-close-btn');
    const viewSettingsSaveBtn = document.getElementById('view-settings-save-btn');

    viewSettingsBtn.addEventListener('click', openViewSettingsModal);
    viewSettingsCloseBtn.addEventListener('click', () => viewSettingsModal.classList.add('hidden'));
    viewSettingsModal.addEventListener('click', e => {
        if (e.target === viewSettingsModal) viewSettingsModal.classList.add('hidden');
    });

    viewSettingsSaveBtn.addEventListener('click', async () => {
        const data = state.currentListData;
        const activeViewId = state.activeListView;
        const activeView = activeViewId ? (data.views || []).find(v => v.id === activeViewId) : null;

        const rows = [...document.querySelectorAll('#view-settings-form .settings-column-row')];
        const checkedIds = rows
            .filter(r => r.querySelector('input[type="checkbox"]').checked)
            .map(r => r.dataset.colId);

        if (activeView) {
            activeView.columns = checkedIds;
            const filterRows = [...document.querySelectorAll('#view-filters-list .filter-row')];
            activeView.filters = filterRows
                .map(r => ({
                    colId: r.querySelector('.filter-col-select').value,
                    value: r.querySelector('.filter-value-input').value.trim(),
                }))
                .filter(f => f.value);
        } else {
            const orderedIds = rows.map(r => r.dataset.colId);
            const autoincCols = data.columns.filter(c => c.type === 'autoincrement');
            const reordered = orderedIds.map(id => data.columns.find(c => c.id === id)).filter(Boolean);
            data.columns = [...autoincCols, ...reordered];
            data.columns.forEach(col => {
                if (col.type !== 'autoincrement') col.showInListView = checkedIds.includes(col.id);
            });
        }

        await saveListData();
        viewSettingsModal.classList.add('hidden');
        document.dispatchEvent(new CustomEvent('list-views-changed'));
    });
};
