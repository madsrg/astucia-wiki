// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// The wiki-wide taxonomy, as the tag field, the tag picker and the admin page read it.
// The server (taxonomy.php) is the authority on every rule here; these helpers only make
// the UI agree with it before a request is sent.
import { api } from './api.js';

let _cache = null;
let _at = 0;
const TTL_MS = 30000;   // the tree changes rarely; the picker always asks fresh

/** { mode, nodes, counts } — `fresh` skips the cache. */
export const getTaxonomy = async (fresh = false) => {
    if (!fresh && _cache && Date.now() - _at < TTL_MS) return _cache;
    // space:'' — the tree is wiki-wide, and counts are scoped server-side.
    const r = await api.call('get_taxonomy', { space: '' });
    if (r.success) {
        _cache = { mode: r.mode || 'free', nodes: r.nodes || [], counts: r.counts || {} };
        _at = Date.now();
    }
    return _cache || { mode: 'free', nodes: [], counts: {} };
};

export const invalidateTaxonomy = () => { _cache = null; };

export const lc = (s) => String(s).toLocaleLowerCase();

/** "Finance / Budget " → "Finance/Budget", the way the server normalises a tag. */
export const normalizeTag = (s) => String(s).split('/')
    .map(seg => seg.replace(/\s+/g, ' ').trim()).filter(Boolean).join('/');

/** "Department/Finance" → "Finance": what a tag chip shows; the full path goes in its tooltip. */
export const tagLeaf = (tag) => String(tag).split('/').pop();

/**
 * The tree as a file browser shows it: at every level folders first, then items, each
 * alphabetical — case-insensitive and numeric-aware, so "Q2" comes before "Q10". A copy,
 * for display only: the order stored in taxonomy.json is left as it is, so sorting never
 * rewrites the file or shows up in its history.
 */
const _byName = (a, b) => String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base', numeric: true });
export const sortNodes = (nodes) => [...(nodes || [])]
    .sort((a, b) => (!!b.heading - !!a.heading) || _byName(a, b))
    .map(n => (n.children && n.children.length ? { ...n, children: sortNodes(n.children) } : n));

/** Every node, depth-first: { path, name, heading, description, depth, hasChildren }. */
export const flatten = (nodes, parent = '', depth = 0, out = []) => {
    for (const n of nodes || []) {
        const path = parent ? `${parent}/${n.name}` : n.name;
        out.push({ path, name: n.name, heading: !!n.heading, description: n.description || '',
                   depth, hasChildren: !!(n.children && n.children.length) });
        if (n.children) flatten(n.children, path, depth + 1, out);
    }
    return out;
};

/** lower-case path → flattened node. */
export const indexByPath = (nodes) => {
    const m = new Map();
    for (const n of flatten(nodes)) m.set(lc(n.path), n);
    return m;
};

/**
 * Front-matter fields bound to a branch: lower-case field → { field, path, multiple,
 * values: [names] }. A value is the name of an item anywhere inside the branch; folders
 * group and are not values. Mirrors wiki_tax_fields().
 */
export const boundFields = (nodes) => {
    const out = new Map();
    const collect = (list, vals) => {
        for (const n of list || []) {
            if (!n.heading && !vals.some(v => lc(v) === lc(n.name))) vals.push(n.name);
            collect(n.children, vals);
        }
    };
    const walk = (list, parent) => {
        for (const n of list || []) {
            const path = parent ? `${parent}/${n.name}` : n.name;
            if (n.field) {
                const values = [];
                collect(n.children, values);
                out.set(lc(n.field), { field: n.field, path, multiple: !!n.multiple, values });
            }
            walk(n.children, path);
        }
    };
    walk(nodes, '');
    return out;
};
