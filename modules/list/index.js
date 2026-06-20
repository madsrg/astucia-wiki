import { openItemModal, saveItem, deleteItem, saveColumn, openItemViewModal, openListPropsModal, saveListProps } from './modals.js';
import { init as initSettings } from './settings.js';
import { init as initExport } from './export.js';
import { renderListView, getActiveColumns } from './render.js';
import { saveListData } from './data.js';
import { state } from '../core/state.js';
import { promptModal, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

export const refreshViewTabs = () => {
    const tabsEl = document.getElementById('view-tabs');
    if (!tabsEl || !state.currentListData) return;
    const views = state.currentListData.views || [];
    tabsEl.innerHTML = '';
    tabsEl.classList.remove('hidden');

    const activeViewId = state.activeListView;

    const makeTab = (label, cls, onClick) => {
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'view-tab' + (cls ? ' ' + cls : '');
        a.textContent = label;
        a.addEventListener('click', e => { e.preventDefault(); onClick(); });
        tabsEl.appendChild(a);
    };

    makeTab(t('list.all-items'), activeViewId === null ? 'active' : '', () => {
        state.activeListView = null;
        renderListView();
        refreshViewTabs();
    });

    views.forEach(view => {
        makeTab(view.name, activeViewId === view.id ? 'active' : '', () => {
            state.activeListView = view.id;
            renderListView();
            refreshViewTabs();
        });
    });

    makeTab(t('list.new-view-tab'), 'view-tab-new', async () => {
        const name = await promptModal(t('list.new-view-prompt'), '', '', icons.list);
        if (!name) return;
        const currentCols = getActiveColumns();
        const newView = {
            id: 'view_' + Date.now(),
            name: name.trim(),
            columns: currentCols.map(c => c.id),
            filters: [],
        };
        if (!state.currentListData.views) state.currentListData.views = [];
        state.currentListData.views.push(newView);
        state.activeListView = newView.id;
        await saveListData();
        refreshViewTabs();
    });
};

export const init = () => {
    initSettings();
    initExport();

    // Delete view button (in settings modal footer)
    document.getElementById('delete-view-btn').addEventListener('click', async () => {
        if (!state.activeListView || !await confirmModal(t('list.delete-view'), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) return;
        state.currentListData.views = (state.currentListData.views || [])
            .filter(v => v.id !== state.activeListView);
        state.activeListView = null;
        await saveListData();
        document.getElementById('view-settings-modal').classList.add('hidden');
        refreshViewTabs();
    });

    document.addEventListener('list-views-changed', refreshViewTabs);

    // Item / column events
    document.getElementById('add-item-btn').addEventListener('click', () => openItemModal());
    document.getElementById('add-column-btn').addEventListener('click', () => {
        document.getElementById('column-name').value = '';
        document.getElementById('column-desc').value = '';
        document.getElementById('column-type').value = 'text_single';
        document.getElementById('column-visible').value = 'true';
        document.getElementById('choice-options').value = '';
        document.getElementById('choice-options-group').classList.add('hidden');
        document.getElementById('column-modal').classList.remove('hidden');
    });
    document.getElementById('item-modal-save-btn').addEventListener('click', saveItem);
    document.getElementById('column-modal-save-btn').addEventListener('click', saveColumn);
    document.getElementById('item-modal-close-btn').addEventListener('click', () =>
        document.getElementById('item-modal').classList.add('hidden'));
    document.getElementById('column-modal-close-btn').addEventListener('click', () =>
        document.getElementById('column-modal').classList.add('hidden'));
    document.getElementById('list-props-btn').addEventListener('click', () => openListPropsModal());
    document.getElementById('list-props-save-btn').addEventListener('click', async () => {
        await saveListProps();
        renderListView();
    });
    document.getElementById('list-props-modal-close-btn').addEventListener('click', () =>
        document.getElementById('list-props-modal').classList.add('hidden'));

    document.getElementById('column-type').addEventListener('change', e => {
        document.getElementById('choice-options-group').classList.toggle('hidden', e.target.value !== 'choice');
    });

    document.getElementById('list-items-table').addEventListener('click', (e) => {
        if (e.target.closest('.edit-item-btn') || e.target.closest('.delete-item-btn')) return;
        const row = e.target.closest('tr[data-id]');
        if (row?.dataset.id) {
            const itemId = parseInt(row.dataset.id);
            const itemData = state.currentListData?.items.find(i => i.id === itemId);
            if (itemData) openItemViewModal(itemData);
        }
    });

    const itemViewModal = document.getElementById('item-view-modal');
    document.getElementById('item-view-modal-close-btn').addEventListener('click', () =>
        itemViewModal.classList.add('hidden'));
    document.getElementById('item-view-modal-edit-btn').addEventListener('click', () => {
        const itemId = parseInt(itemViewModal.dataset.id);
        if (itemId) { itemViewModal.classList.add('hidden'); openItemModal(itemId); }
    });
    document.getElementById('item-view-modal-delete-btn').addEventListener('click', async () => {
        const itemId = parseInt(itemViewModal.dataset.id);
        if (itemId) { await deleteItem(itemId); itemViewModal.classList.add('hidden'); }
    });
};
