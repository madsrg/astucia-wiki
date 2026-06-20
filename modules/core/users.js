import { api } from './api.js';

let _cache = null;

export const getUsers = async () => {
    if (_cache !== null) return _cache;
    const res = await api.call('get_user_list');
    _cache = res.success ? (res.data || []) : [];
    return _cache;
};

export const invalidateUsers = () => { _cache = null; };
