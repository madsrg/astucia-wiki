import { api } from './api.js';

let _cache = null;

export const getUsers = async () => {
    if (_cache !== null) return _cache;
    const res = await api.call('get_user_list');
    _cache = res.success ? (res.data || []) : [];
    return _cache;
};

export const invalidateUsers = () => { _cache = null; };

// Users that can be #mentioned in chat / comments: humans and AI users, but
// NOT API accounts (is_system) — those are headless inbound service tokens that
// can't post or reply, so they must never appear in a mention autocomplete.
export const getMentionableUsers = async () => (await getUsers()).filter(u => !u.is_system);
