// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { displaySearchResults } from '../search/index.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

// The unread count on the My Mentions button.
//
// "New" is measured from the moment the user last opened the panel (see mentions.php),
// not from login — a badge that cannot be cleared by reading the thing it points at
// stops being read at all. Opening the panel marks everything seen, so the count is
// gone by the time the results are on screen.
//
// The count is polled, because the thing that produces a mention is often not a person
// typing: an agent job can finish an hour into a session and name you. A poll every
// MENTION_POLL_MS therefore updates the badge and, when the count goes *up* while you
// are sitting there, raises a toast naming where it happened.
//
// Two things keep the cost honest:
//  - Nothing is polled while the tab is hidden; a returning tab refreshes immediately,
//    so the badge is current the moment it is looked at rather than up to a minute stale.
//  - It goes through api.background(), not api.call(). The session idle timeout is
//    measured from the last API call, so polling with call() would hold every session
//    open indefinitely and turn the timeout off for anyone who left a tab open.

const MENTION_POLL_MS = 60000;

// Last count this tab knows about, so a toast fires on a rise rather than on every poll.
// It starts at null: the first fetch establishes the baseline silently, because that
// number is "since you last looked", which may be days old and is not news.
let _known = null;
let _timer = null;

const badgeEl = () => document.getElementById('mentions-badge');

const renderBadge = (count) => {
    const btn = document.getElementById('mentions-btn');
    if (!btn) return;
    let badge = badgeEl();
    if (!count) { badge?.remove(); return; }
    if (!badge) {
        badge = document.createElement('span');
        badge.id = 'mentions-badge';
        badge.className = 'sidebar-badge';
        btn.appendChild(badge);
    }
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.title = t('mentions.new-count', { n: count });
};

const me = () => ({ name: window.WIKI_USER_NAME || '', uid: window.WIKI_USER_UID || 0 });

/**
 * @param {boolean} announce  Toast when the count has risen since the last check.
 */
export const refreshMentionCount = async (announce = false) => {
    const { name, uid } = me();
    if (!name && !uid) return;
    const res = await api.background('get_mention_count', { name, uid });
    if (!res.success) return;
    const count = res.count || 0;
    renderBadge(count);

    if (announce && _known !== null && count > _known) {
        const where = res.latest?.header || res.latest?.path || '';
        const fresh = count - _known;
        showToast(
            fresh === 1 && where ? t('mentions.toast-one', { where })
                                 : t('mentions.toast-many', { n: fresh }),
            'info');
    }
    _known = count;
};

const startPolling = () => {
    if (_timer) return;
    _timer = setInterval(() => {
        if (document.hidden) return;          // an unwatched tab needs no badge
        refreshMentionCount(true);
    }, MENTION_POLL_MS);
    // A tab coming back to the foreground may have missed several ticks.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshMentionCount(true);
    });
};

export const init = () => {
    const mentionsBtn  = document.getElementById('mentions-btn');
    const commentsBtn  = document.getElementById('my-comments-btn');

    if (mentionsBtn) {
        mentionsBtn.addEventListener('click', async () => {
            const { name, uid } = me();
            if (!name && !uid) return;
            const result = await api.call('get_mentions', { name, uid });
            if (!result.success) return;
            // Mentions span every space the user can read, so the rows say which one.
            displaySearchResults(t('mentions.my'), result.data, true);
            // Clear the badge first, then persist: the list on screen is what was just
            // seen, and a failed write should not leave a count contradicting it.
            renderBadge(0);
            _known = 0;   // the next arrival is a rise again
            if (uid) await api.call('mark_mentions_seen', { uid }, 'POST');
        });
        refreshMentionCount();
        startPolling();
    }

    if (commentsBtn) {
        commentsBtn.addEventListener('click', async () => {
            const uid = window.WIKI_USER_UID || 0;
            if (!uid) return;
            const result = await api.call('get_my_comments', { uid });
            if (result.success) displaySearchResults(t('comments.my'), result.data);
        });
    }
};
