// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from './api.js';

let _cache = null;

export const getMcpServers = async () => {
    if (_cache !== null) return _cache;
    const res = await api.call('list_mcp_servers');
    _cache = res.success ? (res.data || []) : [];
    return _cache;
};

export const invalidateMcpServers = () => { _cache = null; };
