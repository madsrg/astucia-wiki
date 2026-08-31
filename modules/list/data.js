// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { renderListView } from './render.js';
import { t } from '../i18n/index.js';

export const saveListData = async () => {
    try {
        const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const response = await fetch(`api.php?action=save&file=${encodeURIComponent(state.currentPagePath)}${spaceQs}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(state.currentListData, null, 4),
        });
        if (!response.ok) throw new Error(t('list.save-failed'));
        const result = await response.json();
        if (result.success) {
            showToast(t('list.saved'), 'success');
            // Our own write — re-baseline the watcher so it is not reported as external.
            const { rebaselineFileWatch } = await import('../page_view/index.js');
            rebaselineFileWatch(state.currentPagePath, result.lastUpdated, result.size);
            renderListView();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        showToast(`Error: ${error.message}`, 'error');
    }
};
