// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from './api.js';

let _cache = null;

export const getUsers = async () => {
    if (_cache !== null) return _cache;
    const res = await api.call('get_user_list');
    // Only cache a successful response. A transient failure (e.g. the auth
    // session isn't ready yet right after a reload) must not poison the cache
    // with an empty list forever — otherwise AI-user lookups such as chat focus
    // routing silently break until an admin action calls invalidateUsers().
    if (!res.success) return [];
    _cache = res.data || [];
    return _cache;
};

export const invalidateUsers = () => { _cache = null; };

// Users that can be #mentioned in chat / comments: humans and AI users, but
// NOT API accounts (is_system) — those are headless inbound service tokens that
// can't post or reply, so they must never appear in a mention autocomplete.
export const getMentionableUsers = async () => (await getUsers()).filter(u => !u.is_system);
