// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// WHAT THE BROWSER ACTUALLY LOADED
//
// Four libraries come from a CDN, and for three of them **the version an install is
// running cannot be read from this repository**: marked and vanilla-jsoneditor are
// unpinned, mermaid floats inside major 11, and each resolves to whatever jsdelivr
// served that browser on that day — changing with no release here. That is the gap this
// registry closes, and the reason Admin → Wiki Info exists at all: a question the source
// tree cannot answer.
//
// Each loader records what it got, once, as it lazy-loads. Nothing is loaded *in order
// to* report it — a panel that forced a megabyte of mermaid onto an admin who only
// wanted a version number would be worse than the missing answer. "Not loaded on this
// page" is a truthful row.

/**
 * The pin as written in the code, beside where it is written.
 *
 * `pin` is deliberately the literal specifier rather than a tidy version: `@11` and
 * "unpinned" are the interesting facts, and showing them next to what they resolved to
 * is what makes a floating dependency visible.
 */
export const CDN_LIBS = [
    { key: 'marked',             label: 'marked',             pin: 'unpinned (latest)', where: 'index.php' },
    { key: 'mermaid',            label: 'mermaid',            pin: '@11',               where: 'modules/mermaid' },
    { key: 'cytoscape',          label: 'cytoscape',          pin: '@3.30.2',           where: 'modules/graph' },
    { key: 'vanilla-jsoneditor', label: 'vanilla-jsoneditor', pin: 'unpinned (latest)', where: 'modules/json_view' },
];

const _loaded = new Map();

/**
 * Record a library's real version as it finishes loading.
 *
 * A library that exposes no version still calls this with '' — "loaded, version not
 * exposed" is a different answer from "not loaded", and only the loader knows which.
 * marked is the case in point: its CDN build carries no version field at all.
 */
export const noteLoaded = (key, version) => {
    _loaded.set(key, typeof version === 'string' && version ? version : '');
};

/** @returns {Array<{key,label,pin,where,loaded:boolean,version:string}>} */
export const cdnVersions = () => CDN_LIBS.map(l => ({
    ...l,
    loaded:  _loaded.has(l.key),
    version: _loaded.get(l.key) || '',
}));
