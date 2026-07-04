import { api } from './api.js';

let _cache = null;

export const getMcpServers = async () => {
    if (_cache !== null) return _cache;
    const res = await api.call('list_mcp_servers');
    _cache = res.success ? (res.data || []) : [];
    return _cache;
};

export const invalidateMcpServers = () => { _cache = null; };
