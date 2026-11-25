// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';
import { taxonomyPending, loadTaxonomy } from './fields.js';
import { cellText, cellParts, partsElement, normalizeList, loadLookupTargets, lookupsPending,
         setLookupSpace, filterMatches, sortValue, commentEntries, commentsOn, COMMENTS } from './fields.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

export const getFilteredItems = () => {
    const data = state.currentListData;
    const activeViewId = state.activeListView;
    if (!activeViewId) return data.items;
    const view = (data.views || []).find(v => v.id === activeViewId);
    if (!view?.filters?.length) return data.items;
    // Matched against what the field *shows*, so a lookup filters on its titles, not its ids
    // — except a Number, which may compare (filterMatches in fields.js).
    return data.items.filter(item =>
        view.filters.every(f => {
            const col = data.columns.find(c => c.id === f.colId);
            if (!col) return (item[f.colId] ?? '').toString().toLowerCase().includes(f.value.toLowerCase());
            return filterMatches(col, item, f.value, data.columns);
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

    const cols = state.currentListData.columns;
    state.currentListData.items.sort((a, b) => {
        let valA = sortValue(col, a, cols);
        let valB = sortValue(col, b, cols);
        let comparison = 0;

        if (['number', 'boolean'].includes(col.type)) {
            // No value sorts last whichever way, as a blank does.
            if (valA === null || valB === null) return valA === valB ? 0 : (valA === null ? 1 : -1);
            comparison = valA - valB;
        } else if (col.type === 'autoincrement') {
            comparison = valA - valB;
        } else if (col.type === 'date') {
            comparison = new Date(valA) - new Date(valB);
        } else {
            comparison = valA.toString().localeCompare(valB.toString(), undefined, { numeric: true });
        }

        return direction === 'asc' ? comparison : -comparison;
    });
};

/**
 * The comments column, at the table's right edge when the list takes comments: a speech
 * bubble and the count on an item that has any — as SharePoint shows it. Clicking the row
 * opens the item, where the comments are.
 */
const commentsCell = (row, item) => {
    const cell = row.insertCell();
    cell.className = 'list-comments-col';
    const n = commentEntries(item[COMMENTS]).length;
    if (!n) return;
    const badge = document.createElement('span');
    badge.className = 'list-comments-badge';
    badge.title = t('list.comments-count', { n });
    badge.innerHTML = icons.chat;
    badge.appendChild(document.createTextNode(String(n)));
    cell.appendChild(badge);
};

export const renderListView = () => {
    if (!state.currentListData) return;
    // The open list's lookups are the open Space's, whatever an edit from a generated page
    // left behind (modals.js clears it on close; a dialog dismissed otherwise may not).
    setLookupSpace(null);
    normalizeList(state.currentListData);
    // Lookups resolve against other lists: paint now with what is cached, and once more
    // when the rest has arrived. Only when something was actually missing, so this
    // cannot loop.
    if (lookupsPending(state.currentListData)) {
        const data = state.currentListData;
        loadLookupTargets(data).then(changed => {
            if (changed && state.currentListData === data) renderListView();
        });
    }
    // A choice from the taxonomy needs the tree for its view filters ("Finance" matches the
    // items below it) — fetched once, then painted again.
    if (taxonomyPending(state.currentListData)) {
        const data = state.currentListData;
        loadTaxonomy(data).then(changed => {
            if (changed && state.currentListData === data) renderListView();
        });
    }

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
    const withComments = commentsOn(state.currentListData);
    if (withComments) {
        const th = document.createElement('th');
        th.className = 'list-comments-col';
        th.title = t('list.comments-title');
        th.setAttribute('aria-label', t('list.comments-title'));
        th.innerHTML = icons.chat;
        headerRow.appendChild(th);
    }

    const tbody = table.createTBody();
    getFilteredItems().forEach(item => {
        const row = tbody.insertRow();
        row.dataset.id = item.id;
        visibleColumns.forEach(col => {
            const cell = row.insertCell();
            // A lookup's values are separate entities — one pill each, stacked so that an
            // "also show" column beside it lines up value for value.
            const parts = cellParts(col, item, state.currentListData.columns);
            if (col.type === 'number') cell.classList.add('is-number');
            if (parts?.length) {
                cell.classList.add('has-lookup');
                // A group's entries are capped (+N); a lookup's are not, or an "also show"
                // column would stop lining up with it.
                const ul = partsElement(parts, col.type === 'lookup_field', col.type === 'object' ? 5 : Infinity);
                if (col.type === 'object') ul.classList.add('is-group');
                cell.appendChild(ul);
            } else {
                cell.textContent = cellText(col, item, state.currentListData.columns);
            }
        });
        if (withComments) commentsCell(row, item);
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
