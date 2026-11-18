// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Renders ```map fenced blocks in Markdown pages as interactive maps (Leaflet + OpenStreetMap).
//
//     ```map
//     address: Rådhuspladsen 1, 1550 København
//     lat: 55.67566
//     lon: 12.56976
//     zoom: 16
//     height: 400
//     type: streets
//     label: City Hall
//     ```
//
// The block stores settings, not provider HTML, for the reasons mermaid is a block too: the
// page stays readable as text (Obsidian, a diff), survives a change of provider, and needs no
// raw HTML in the page. Coordinates are stored rather than looked up on view, so reading a
// page never sends its addresses anywhere (maps.php, `geocode`). Leaflet (~40 KB) is loaded
// only on a page that has a map.
//
// Keys — anything missing takes its default, anything invalid is reported beside the block:
//   lat, lon      required, decimal degrees
//   address       shown under the map, with a link to the place on openstreetmap.org
//   zoom          1–19 (default 15; the topo layer stops at 17)
//   height        150–1000 px (default 400)
//   width         100% (default), a percentage, or px
//   type          streets | satellite | topo
//   marker        true (default) | false
//   label         the marker's popup text
//   interactive   true (default) | false — false is a still picture: no dragging or zooming
//   caption       true (default) | false — the address line under the map
//
// Scroll-wheel zoom is always off until the map is clicked: otherwise scrolling down a page
// gets caught by the first map under the pointer.
import { t } from '../i18n/index.js';
import { noteLoaded } from '../core/versions.js';

const LEAFLET = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/';

export const MAP_TYPES = ['streets', 'satellite', 'topo'];
export const MAP_DEFAULTS = { zoom: 15, height: 400, width: '100%', type: 'streets', marker: true, interactive: true, caption: true };

const config = () => window.WIKI_MAPS || { enabled: true, layers: {} };
export const mapsEnabled = () => config().enabled !== false;

let _lib = null;

/** Leaflet, loaded once. A page that already has `window.L` (a test stub) uses it. */
const loadLeaflet = () => {
    if (window.L) return Promise.resolve(window.L);
    if (!_lib) {
        _lib = new Promise((resolve, reject) => {
            if (!document.querySelector('link[data-leaflet]')) {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = LEAFLET + 'leaflet.css';
                css.dataset.leaflet = '1';
                document.head.appendChild(css);
            }
            const js = document.createElement('script');
            js.src = LEAFLET + 'leaflet.js';
            js.onload = () => { noteLoaded('leaflet', window.L?.version || ''); resolve(window.L); };
            js.onerror = () => { _lib = null; reject(new Error('Leaflet could not be loaded')); };
            document.head.appendChild(js);
        });
    }
    return _lib;
};

const truthy = (v, dflt) => {
    if (v === undefined) return dflt;
    if (/^(true|yes|on|1)$/i.test(v)) return true;
    if (/^(false|no|off|0)$/i.test(v)) return false;
    return null;
};

/**
 * Parse a block's text. Returns { opts } or { error } — a sentence the author can act on.
 * One `key: value` per line; unknown keys are ignored, so a later version can add some.
 */
export const parseMapBlock = (text) => {
    const raw = {};
    String(text || '').split('\n').forEach(line => {
        const m = line.match(/^\s*([A-Za-z]+)\s*:\s*(.*?)\s*$/);
        if (m) raw[m[1].toLowerCase()] = m[2];
    });
    const lat = parseFloat(raw.lat), lon = parseFloat(raw.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
        return { error: t('map.err-coords') };
    }
    const o = { ...MAP_DEFAULTS, lat, lon, address: raw.address || '', label: raw.label || '' };
    if (raw.zoom !== undefined) {
        const z = parseInt(raw.zoom, 10);
        if (!(z >= 1 && z <= 19)) return { error: t('map.err-zoom') };
        o.zoom = z;
    }
    if (raw.height !== undefined) {
        const h = parseInt(raw.height, 10);
        if (!(h >= 150 && h <= 1000)) return { error: t('map.err-height') };
        o.height = h;
    }
    if (raw.width !== undefined) {
        const w = raw.width.replace(/\s+/g, '');
        const pct = w.match(/^(\d{1,3})%$/), px = w.match(/^(\d{2,4})(px)?$/);
        if (pct && +pct[1] >= 10 && +pct[1] <= 100) o.width = `${+pct[1]}%`;
        else if (px && +px[1] >= 150 && +px[1] <= 2000) o.width = `${+px[1]}px`;
        else return { error: t('map.err-width') };
    }
    if (raw.type !== undefined) {
        if (!MAP_TYPES.includes(raw.type.toLowerCase())) return { error: t('map.err-type', { types: MAP_TYPES.join(', ') }) };
        o.type = raw.type.toLowerCase();
    }
    for (const k of ['marker', 'interactive', 'caption']) {
        const v = truthy(raw[k], MAP_DEFAULTS[k]);
        if (v === null) return { error: t('map.err-flag', { key: k }) };
        o[k] = v;
    }
    return { opts: o };
};

/** The block text for a set of options — what Insert → Map writes. Defaults are omitted. */
export const mapBlockText = (o) => {
    const lines = [];
    if (o.address) lines.push(`address: ${o.address.replace(/\s*\n\s*/g, ' ')}`);
    lines.push(`lat: ${(+o.lat).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')}`);
    lines.push(`lon: ${(+o.lon).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')}`);
    lines.push(`zoom: ${o.zoom}`);
    lines.push(`height: ${o.height}`);
    if (o.width && o.width !== MAP_DEFAULTS.width) lines.push(`width: ${o.width}`);
    lines.push(`type: ${o.type}`);
    if (o.marker === false) lines.push('marker: false');
    if (o.label) lines.push(`label: ${o.label.replace(/\s*\n\s*/g, ' ')}`);
    if (o.interactive === false) lines.push('interactive: false');
    if (o.caption === false) lines.push('caption: false');
    return '```map\n' + lines.join('\n') + '\n```';
};

export const osmLink = (o) =>
    `https://www.openstreetmap.org/?mlat=${o.lat}&mlon=${o.lon}#map=${o.zoom}/${o.lat}/${o.lon}`;

/**
 * Draw a map into `canvas` (an element with its size already set). Returns the Leaflet map,
 * so the insert dialog can move its preview. Throws if Leaflet cannot be loaded.
 */
export const drawMap = async (canvas, o) => {
    const L = await loadLeaflet();
    const layer = config().layers?.[o.type] || config().layers?.streets;
    const map = L.map(canvas, {
        center: [o.lat, o.lon],
        zoom: Math.min(o.zoom, layer?.max || 19),
        scrollWheelZoom: false,
        dragging: o.interactive, touchZoom: o.interactive, doubleClickZoom: o.interactive,
        boxZoom: o.interactive, keyboard: o.interactive, zoomControl: o.interactive,
    });
    if (layer?.url) {
        L.tileLayer(layer.url, { maxZoom: layer.max || 19, attribution: layer.attribution || '' }).addTo(map);
    }
    if (o.marker) {
        const m = L.marker([o.lat, o.lon]).addTo(map);
        if (o.label) m.bindPopup(document.createTextNode(o.label));   // text, never markup
    }
    // Scroll-wheel zoom only once the reader has clicked into the map, and off again when
    // the pointer leaves — a page scrolling past a map must not be caught by it.
    if (o.interactive) {
        map.on('click', () => map.scrollWheelZoom.enable());
        map.on('mouseout', () => map.scrollWheelZoom.disable());
    }
    // Leaflet measures its box when created; a map drawn into a just-inserted element can
    // measure 0 and show one grey tile until told to look again.
    setTimeout(() => map.invalidateSize?.(), 0);
    return map;
};

const showError = (pre, message) => {
    if (pre.previousElementSibling?.classList.contains('wiki-map-error')) return;
    const box = document.createElement('div');
    box.className = 'wiki-map-error';
    box.textContent = message;
    pre.before(box);
};

const renderOne = async (code) => {
    const pre = code.parentElement;
    pre.dataset.mapReady = '1';
    if (!mapsEnabled()) {
        showError(pre, t('map.disabled'));
        return;
    }
    const { opts, error } = parseMapBlock(code.textContent);
    if (error) { showError(pre, error); return; }

    const fig = document.createElement('figure');
    fig.className = 'wiki-map';
    fig.style.width = opts.width;
    const canvas = document.createElement('div');
    canvas.className = 'wiki-map-canvas';
    canvas.style.height = `${opts.height}px`;
    canvas.dataset.type = opts.type;
    fig.appendChild(canvas);
    if (opts.caption) {
        const cap = document.createElement('figcaption');
        cap.className = 'wiki-map-caption';
        if (opts.address) cap.appendChild(document.createTextNode(opts.address + ' · '));
        const a = document.createElement('a');
        a.href = osmLink(opts);
        a.textContent = t('map.open-osm');
        cap.appendChild(a);
        fig.appendChild(cap);
    }
    // The source stays in the page, hidden, so a failure can put it back and the inline
    // editor's preview still finds the block it came from.
    pre.classList.add('wiki-map-source');
    pre.before(fig);
    try {
        await drawMap(canvas, opts);
    } catch (e) {
        fig.remove();
        pre.classList.remove('wiki-map-source');
        showError(pre, t('map.load-failed'));
    }
};

const SEL = 'pre:not([data-map-ready]) > code.language-map';
let _queued = false;

/**
 * Render every map block under `root` not yet handled. Scheduled on a microtask rather than a
 * frame (as code_copy is): a frame never fires in headless Chrome, which would leave this
 * untestable, and the work is DOM surgery with nothing to wait for.
 */
export const scheduleMapRender = (root) => {
    if (_queued) return;
    _queued = true;
    queueMicrotask(() => {
        _queued = false;
        root.querySelectorAll(SEL).forEach(code => { renderOne(code); });
    });
};

export const MAP_SELECTOR = SEL;
