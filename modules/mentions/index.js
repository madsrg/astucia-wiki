// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { watch, rtTopic } from '../realtime/index.js';
import { showToast } from '../core/utils.js';
import { setUnread } from '../attention/index.js';
import { t } from '../i18n/index.js';

// The unread count on the Wiki Pulse button (modules/pulse), whose first tab is My Mentions.
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
// A hidden tab keeps checking, at a slower rate (MENTION_POLL_HIDDEN_MS, a floor
// applied by watch()). It used to stop entirely, on the reasoning that an unwatched tab
// needs no badge — true of the badge, and exactly wrong for modules/attention, whose
// whole job is the tab you are *not* watching. A push event is never throttled: the
// floor is the timer's, and `wiki/user/<uid>/mention` now fires the moment somebody
// writes your name (see wiki_mention_announce in mentions.php), so on an install with a
// hub the timer is the safety net it is everywhere else.
//
// Two things keep the cost honest:
//  - A returning tab refreshes immediately, so the badge is current the moment it is
//    looked at rather than up to five minutes stale.
//  - It goes through api.background(), not api.call(). The session idle timeout is
//    measured from the last API call, so polling with call() would hold every session
//    open indefinitely and turn the timeout off for anyone who left a tab open.

const MENTION_POLL_MS = 60000;
const MENTION_POLL_SLOW_MS = 600000;   // safety net while push is live; see modules/realtime
const MENTION_POLL_HIDDEN_MS = 300000; // floor while the tab is in the background

// Last count this tab knows about, so a toast fires on a rise rather than on every poll.
// It starts at null: the first fetch establishes the baseline silently, because that
// number is "since you last looked", which may be days old and is not news.
let _known = null;
let _timer = null;

const badgeEl = () => document.getElementById('mentions-badge');

const renderBadge = (count) => {
    const btn = document.getElementById('pulse-btn');
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
    // The same number on the tab title and the favicon, so the window says what the
    // sidebar says whether or not anybody is looking at it.
    setUnread(count);

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

/** The unread count as last fetched (null before the first fetch) — the Pulse dialog's default tab. */
export const unreadCount = () => _known;

const startPolling = () => {
    if (_timer) return;
    // No visibility test: a backgrounded tab is the case modules/attention exists for,
    // and stopping here is what made it unreachable. The rate is throttled instead.
    const tick = () => refreshMentionCount(true);
    // The mention topic now fires for every way a mention is created: an AI calling
    // wiki_mention_users, and — since wiki_mention_announce — a person posting a chat
    // message or saving a page. The timer is still slowed less aggressively than the
    // content watchers, because it is also the only thing that finds a mention written
    // by a path nobody has thought to hook (an rsync of somebody's edited Markdown,
    // reconciled by index_sync, produces no mention event).
    _timer = watch(rtTopic.mention(me().uid), tick,
                   { fast: MENTION_POLL_MS, slow: MENTION_POLL_SLOW_MS,
                     hidden: MENTION_POLL_HIDDEN_MS });
    // A tab coming back to the foreground may have missed several ticks.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshMentionCount(true);
    });
};

/**
 * My Mentions, for the Pulse dialog. Opening the list is reading it, so the badge is
 * cleared and the server told before the rows are drawn: the list about to be shown is
 * what was just seen, and a failed write should not leave a count contradicting it.
 * Mentions span every Space the user can read, so the rows say which one.
 */
export const fetchMentions = async () => {
    const { name, uid } = me();
    if (!name && !uid) return null;
    const result = await api.call('get_mentions', { name, uid });
    if (!result.success) return null;
    renderBadge(0);
    setUnread(0);
    _known = 0;   // the next arrival is a rise again
    if (uid) api.call('mark_mentions_seen', { uid }, 'POST');
    return { rows: result.data || [], cutoffDays: result.cutoff_days || 0 };
};

/** My Comments, for the Pulse dialog: pages in the current Space I commented on. */
export const fetchComments = async () => {
    const uid = window.WIKI_USER_UID || 0;
    if (!uid) return null;
    const result = await api.call('get_my_comments', { uid });
    return result.success ? { rows: result.data || [] } : null;
};

export const init = () => {
    // The badge and its polling belong to whoever has mentions: a signed-in person. The
    // Pulse button says so (data-mine); an editor on a wiki without sign-in has none.
    if (document.getElementById('pulse-btn')?.dataset.mine !== '1') return;
    refreshMentionCount();
    startPolling();
};
