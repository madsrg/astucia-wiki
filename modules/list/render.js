import { state } from '../core/state.js';

export const getFilteredItems = () => {
    const data = state.currentListData;
    const activeViewId = state.activeListView;
    if (!activeViewId) return data.items;
    const view = (data.views || []).find(v => v.id === activeViewId);
    if (!view?.filters?.length) return data.items;
    return data.items.filter(item =>
        view.filters.every(f => {
            const val = (item[f.colId] ?? '').toString().toLowerCase();
            return val.includes(f.value.toLowerCase());
        })
    );
};

export const getActiveColumns = () => {
    const data = state.currentListData;
    const activeViewId = state.activeListView;
    if (activeViewId) {
        const view = (data.views || []).find(v => v.id === activeViewId);
        if (view?.columns?.length) {
            return view.columns.map(id => data.columns.find(c => c.id === id)).filter(Boolean);
        }
    }
    return data.columns.filter(c => c.showInListView !== false);
};

export const sortListItems = () => {
    const { colId, direction } = state.sortState;
    if (!colId) return;

    const col = state.currentListData.columns.find(c => c.id === colId);
    if (!col) return;

    state.currentListData.items.sort((a, b) => {
        let valA = a[colId] || '';
        let valB = b[colId] || '';
        let comparison = 0;

        if (col.type === 'autoincrement') {
            comparison = valA - valB;
        } else if (col.type === 'date') {
            comparison = new Date(valA) - new Date(valB);
        } else {
            comparison = valA.toString().localeCompare(valB.toString(), undefined, { numeric: true });
        }

        return direction === 'asc' ? comparison : -comparison;
    });
};

export const renderListView = () => {
    if (!state.currentListData) return;

    const visibleColumns = getActiveColumns();
    const listItemsTable = document.getElementById('list-items-table');
    listItemsTable.innerHTML = '';

    const table = document.createElement('table');
    table.className = 'list-table';

    const thead = table.createTHead();
    const headerRow = thead.insertRow();
    visibleColumns.forEach(col => {
        const th = document.createElement('th');
        th.textContent = col.name;
        th.dataset.colId = col.id;
        th.className = 'sortable-header';
        if (state.sortState.colId === col.id) {
            th.classList.add(state.sortState.direction === 'asc' ? 'sort-asc' : 'sort-desc');
        }
        headerRow.appendChild(th);
    });

    const tbody = table.createTBody();
    getFilteredItems().forEach(item => {
        const row = tbody.insertRow();
        row.dataset.id = item.id;
        visibleColumns.forEach(col => {
            const cell = row.insertCell();
            cell.textContent = item[col.id] || '';
        });
    });

    listItemsTable.appendChild(table);

    thead.addEventListener('click', (e) => {
        const header = e.target.closest('.sortable-header');
        if (header) {
            const colId = header.dataset.colId;
            if (state.sortState.colId === colId) {
                state.sortState.direction = state.sortState.direction === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortState.colId = colId;
                state.sortState.direction = 'asc';
            }
            sortListItems();
            renderListView();
        }
    });
};
