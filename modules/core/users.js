// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from './api.js';
import { subscribe, rtTopic } from '../realtime/index.js';

let _cache = null;
// What `peekUsers()` hands out while a refetch is in flight: the list from before the
// invalidation. A renderer that cannot await is better served by a list one edit stale
// than by an empty one — empty is what made every AI avatar fall back to the android.
let _stale = [];
let _rev   = '';       // users.json stamp the cache was fetched at ('' = unknown)
let _inflight = null;

let _subscribed = false;

const fetchUsers = async () => {
    // Pushed changes: subscribed on the first fetch, since nothing needs to hear about a
    // list nobody has loaded. With the hub live this is what makes a change show at once;
    // the users_rev on the chat polls below remains the net for when it is not.
    if (!_subscribed) { _subscribed = true; subscribe(rtTopic.users(), () => refresh()); }
    const res = await api.call('get_user_list');
    // Only cache a successful response. A transient failure (e.g. the auth
    // session isn't ready yet right after a reload) must not poison the cache
    // with an empty list forever — otherwise AI-user lookups such as chat focus
    // routing silently break until an admin action calls invalidateUsers().
    if (!res.success) return null;
    _cache = res.data || [];
    _stale = _cache;
    _rev   = typeof res.rev === 'string' ? res.rev : '';
    return _cache;
};

export const getUsers = async () => {
    if (_cache !== null) return _cache;
    if (!_inflight) _inflight = fetchUsers().finally(() => { _inflight = null; });
    return (await _inflight) || [];
};

// Refetch and tell whoever draws from the list (the chat views repaint their avatars on
// `wiki:users`). Used both after an admin edit in this browser and when a chat poll
// reports that users.json has moved since the cache was filled — so a change made in
// somebody else's browser costs one get_user_list here, and an unchanged list costs nothing.
const refresh = async () => {
    _cache = null;
    const list = await getUsers();
    window.dispatchEvent(new CustomEvent('wiki:users', { detail: list }));
};

export const invalidateUsers = () => { refresh(); };

// The cache as it stands, without fetching: for a renderer that cannot await, such as a
// chat bubble. Falls back to the last list held, and is empty only before the first fetch.
export const peekUsers = () => _cache || _stale;

window.addEventListener('wiki:usersrev', (e) => {
    const rev = e.detail || '';
    // Unknown on either side means nothing to compare — the next get_user_list sets it.
    if (!rev || !_rev || rev === _rev || _inflight) return;
    refresh();
});

// Users that can be #mentioned in chat / comments: humans and AI users, but
// NOT API accounts (is_system) — those are headless inbound service tokens that
// can't post or reply, so they must never appear in a mention autocomplete.
export const getMentionableUsers = async () => (await getUsers()).filter(u => !u.is_system);

// The two mention pools. `@` addresses a person, `#` addresses an AI, so a type-ahead
// offers one kind at a time and the sigil already says what the name will reach.
// Kept here so every composer splits them the same way.
export const getPeopleMentionables = async () => (await getMentionableUsers()).filter(u => !u.is_ai);
export const getAiMentionables     = async () => (await getMentionableUsers()).filter(u => !!u.is_ai);
