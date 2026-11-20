// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { renderListView } from './render.js';
import { t } from '../i18n/index.js';

/** A refusal by the server's check of a list (lists.php), in words: what and where. */
export const listInvalidText = (why) => {
    const where = [why.item != null ? t('list.invalid.item', { item: why.item }) : '',
                   why.field != null ? t('list.invalid.field', { field: why.field }) : ''].filter(Boolean).join(', ');
    return (where ? where + ': ' : '') + t(`list.invalid.${why.code}`, { detail: why.detail ?? '' });
};

/**
 * Save the open list. Returns true when it was written. When the server stamped something
 * (a new comment's id, author and time — lists.php) it sends the list back as written, and
 * that replaces ours, or the next save would post the comment again without its stamp.
 */
export const saveListData = async () => {
    const path = state.currentPagePath;
    try {
        const { postPageSave } = await import('../core/save_conflict.js');
        const { ok, conflict, data: result } = await postPageSave(
            path, JSON.stringify(state.currentListData, null, 4), { contentType: 'application/json' });
        if (conflict) {
            // A list saves after every single change, so a dialog per cell would be too much.
            // The change just made is the one at stake: show the list as it is now and say
            // so, rather than write it over rows somebody else added or edited.
            const { rebaselineFileWatch } = await import('../page_view/index.js');
            rebaselineFileWatch(path, result.lastUpdated, result.size, result.rev);
            try { state.currentListData = JSON.parse(result.data); } catch { /* keep ours on screen */ }
            renderListView();
            showToast(result.modifiedBy
                ? t('conflict.list-by', { name: result.modifiedBy })
                : t('conflict.list'), 'info');
            return false;
        }
        if (!ok && result.list_invalid) throw new Error(listInvalidText(result.list_invalid));
        if (!ok) throw new Error(result.message || t('list.save-failed'));
        if (result.list) state.currentListData = result.list;
        showToast(t('list.saved'), 'success');
        // Our own write — re-baseline the watcher so it is not reported as external.
        const { rebaselineFileWatch } = await import('../page_view/index.js');
        rebaselineFileWatch(path, result.lastUpdated, result.size, result.rev);
        renderListView();
        return true;
    } catch (error) {
        showToast(t('edit.save-failed', { error: error.message }), 'error');
        return false;
    }
};
