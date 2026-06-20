import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { renderListView } from './render.js';

export const saveListData = async () => {
    try {
        const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const response = await fetch(`api.php?action=save&file=${encodeURIComponent(state.currentPagePath)}${spaceQs}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(state.currentListData, null, 4),
        });
        if (!response.ok) throw new Error('Failed to save list data.');
        const result = await response.json();
        if (result.success) {
            showToast('List updated successfully!', 'success');
            renderListView();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        showToast(`Error: ${error.message}`, 'error');
    }
};
