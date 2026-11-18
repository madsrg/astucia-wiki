// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Insert → Map: find a place, choose how its map looks, and write the ```map block at the
// caret. The address is looked up here, once (api.php `geocode`), and the block keeps the
// coordinates — a reader never causes a lookup. "lat, lon" typed into the box is taken as it
// is, for a place no address describes or a wiki with no lookup service it can reach.
//
// The preview is drawn by the same drawMap() the page uses, so what you see is what the page
// will show.
import { api } from '../core/api.js';
import { icons } from '../core/icons.js';
import { t, getLanguage } from '../i18n/index.js';
import { MAP_TYPES, MAP_DEFAULTS, drawMap, mapBlockText, mapsEnabled } from './index.js';
import { insertBlock, getEditor } from '../page_edit/editor.js';
import { showToast } from '../core/utils.js';

const OVERLAY_ID = 'map-insert-modal';
const COORDS = /^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const field = (labelKey, control) => {
    const g = el('label', 'map-insert-field');
    g.appendChild(el('span', 'map-insert-label', t(labelKey)));
    g.appendChild(control);
    return g;
};

export const openMapInsertDialog = () => {
    if (!mapsEnabled()) { showToast(t('map.disabled'), 'error'); return; }
    document.getElementById(OVERLAY_ID)?.remove();

    // The caret the block goes to, kept now: the dialog takes focus from the editor.
    const editor = getEditor();
    const caret = editor ? editor.selectionEnd : null;

    const overlay = el('div', 'lightbox-overlay');
    overlay.id = OVERLAY_ID;
    const box = el('div', 'lightbox-content map-insert-content');
    overlay.appendChild(box);

    const header = el('div', 'input-modal-header');
    const hIcon = el('span', 'input-modal-icon');
    hIcon.innerHTML = icons.mapPin;
    header.append(hIcon, el('h3', '', t('map.insert-title')));
    box.appendChild(header);

    const body = el('div', 'map-insert-body');
    box.appendChild(body);
    const left = el('div', 'map-insert-left');
    const right = el('div', 'map-insert-right');
    body.append(left, right);

    // ── Where ────────────────────────────────────────────────────────────────
    const findRow = el('div', 'map-insert-find');
    const query = el('input', 'form-control');
    query.id = 'map-insert-query';
    query.type = 'text';
    query.placeholder = t('map.query-ph');
    const findBtn = el('button', 'btn btn-blue', t('map.find-btn'));
    findBtn.id = 'map-insert-find-btn';
    findBtn.type = 'button';
    findRow.append(query, findBtn);
    left.appendChild(findRow);
    left.appendChild(el('p', 'pref-hint map-insert-privacy', t('map.privacy-hint')));
    const results = el('div', 'map-insert-results');
    results.id = 'map-insert-results';
    left.appendChild(results);

    // ── How ──────────────────────────────────────────────────────────────────
    const opts = { ...MAP_DEFAULTS, lat: null, lon: null, address: '', label: '' };
    const grid = el('div', 'map-insert-options');

    const type = el('select', 'form-control');
    type.id = 'map-insert-type';
    MAP_TYPES.forEach(k => { const o = el('option', '', t(`map.type-${k}`)); o.value = k; type.appendChild(o); });
    const zoom = el('input', 'form-control');
    Object.assign(zoom, { id: 'map-insert-zoom', type: 'number', min: 1, max: 19, value: opts.zoom });
    const height = el('input', 'form-control');
    Object.assign(height, { id: 'map-insert-height', type: 'number', min: 150, max: 1000, step: 10, value: opts.height });
    const width = el('select', 'form-control');
    width.id = 'map-insert-width';
    ['100%', '75%', '50%'].forEach(w => { const o = el('option', '', w); o.value = w; width.appendChild(o); });
    const label = el('input', 'form-control');
    Object.assign(label, { id: 'map-insert-label', type: 'text', placeholder: t('map.label-ph') });
    grid.append(field('map.opt-type', type), field('map.opt-zoom', zoom), field('map.opt-height', height),
                field('map.opt-width', width), field('map.opt-label', label));

    const checks = el('div', 'map-insert-checks');
    const check = (key, labelKey, id) => {
        const row = el('label', 'space-settings-switch-row');
        const cb = el('input', '');
        cb.type = 'checkbox'; cb.id = id; cb.checked = opts[key];
        cb.addEventListener('change', () => { opts[key] = cb.checked; refresh(); });
        row.append(cb, el('span', '', t(labelKey)));
        checks.appendChild(row);
    };
    left.appendChild(grid);
    left.appendChild(checks);

    // ── Preview ──────────────────────────────────────────────────────────────
    const preview = el('div', 'map-insert-preview');
    preview.id = 'map-insert-preview';
    const empty = el('div', 'map-insert-empty', t('map.preview-empty'));
    right.append(preview, empty);

    // ── Footer ───────────────────────────────────────────────────────────────
    const footer = el('div', 'lightbox-footer');
    const cancel = el('button', 'btn btn-secondary', t('btn.cancel'));
    cancel.type = 'button';
    const insert = el('button', 'btn btn-blue', t('map.insert-btn'));
    insert.id = 'map-insert-ok';
    insert.type = 'button';
    insert.disabled = true;
    footer.append(cancel, insert);
    box.appendChild(footer);

    let map = null;
    let drawSeq = 0;
    const refresh = async () => {
        insert.disabled = opts.lat == null;
        empty.classList.toggle('hidden', opts.lat != null);
        preview.classList.toggle('hidden', opts.lat == null);
        if (opts.lat == null) return;
        const mine = ++drawSeq;
        try { map?.remove?.(); } catch { /* a stub or a half-made map */ }
        map = null;
        preview.innerHTML = '';
        const canvas = el('div', 'wiki-map-canvas');
        // The preview has its own height; the chosen one is what the page gets.
        canvas.style.height = '100%';
        preview.appendChild(canvas);
        try {
            const m = await drawMap(canvas, opts);
            if (mine === drawSeq) map = m; else m?.remove?.();
        } catch {
            if (mine === drawSeq) preview.textContent = t('map.load-failed');
        }
    };
    check('marker', 'map.opt-marker', 'map-insert-marker');
    check('interactive', 'map.opt-interactive', 'map-insert-interactive');
    check('caption', 'map.opt-caption', 'map-insert-caption');

    type.addEventListener('change', () => { opts.type = type.value; refresh(); });
    zoom.addEventListener('change', () => {
        opts.zoom = Math.max(1, Math.min(19, parseInt(zoom.value, 10) || MAP_DEFAULTS.zoom));
        zoom.value = opts.zoom;
        refresh();
    });
    height.addEventListener('change', () => {
        opts.height = Math.max(150, Math.min(1000, parseInt(height.value, 10) || MAP_DEFAULTS.height));
        height.value = opts.height;
    });
    width.addEventListener('change', () => { opts.width = width.value; });
    label.addEventListener('input', () => { opts.label = label.value.trim(); });

    const choose = (r, row) => {
        opts.lat = r.lat; opts.lon = r.lon; opts.address = r.label;
        results.querySelectorAll('.map-insert-result').forEach(x => x.classList.toggle('active', x === row));
        refresh();
    };

    const find = async () => {
        const q = query.value.trim();
        if (!q) return;
        results.innerHTML = '';
        const m = q.match(COORDS);
        if (m && Math.abs(+m[1]) <= 90 && Math.abs(+m[2]) <= 180) {
            // Coordinates need no lookup — and are the way in when there is no service to ask.
            const r = { label: '', lat: +m[1], lon: +m[2] };
            const row = el('div', 'map-insert-result', `${r.lat}, ${r.lon}`);
            results.appendChild(row);
            choose(r, row);
            return;
        }
        findBtn.disabled = true;
        results.appendChild(el('p', 'pref-hint', t('map.searching')));
        const res = await api.call('geocode', { q, lang: getLanguage() || '' });
        findBtn.disabled = false;
        results.innerHTML = '';
        if (!res.success) { results.appendChild(el('p', 'map-insert-error', res.message || t('map.lookup-failed'))); return; }
        if (!res.results?.length) { results.appendChild(el('p', 'pref-hint', t('map.no-results'))); return; }
        res.results.forEach((r, i) => {
            const row = el('div', 'map-insert-result', r.label);
            row.tabIndex = 0;
            row.addEventListener('click', () => choose(r, row));
            row.addEventListener('keydown', (e) => { if (e.key === 'Enter') choose(r, row); });
            results.appendChild(row);
            if (i === 0) choose(r, row);          // the best match, ready to insert
        });
    };
    findBtn.addEventListener('click', find);
    query.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); find(); } });

    const close = () => {
        document.removeEventListener('keydown', onKey, true);
        try { map?.remove?.(); } catch { /* ignore */ }
        overlay.remove();
    };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
    document.addEventListener('keydown', onKey, true);
    cancel.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    insert.addEventListener('click', () => {
        if (opts.lat == null) return;
        const block = mapBlockText(opts);
        close();
        const ed = getEditor();
        if (ed) {
            // Insert at the caret the dialog opened on, never around a selection.
            const at = caret ?? ed.value.length;
            ed.focus();
            ed.setSelectionRange(at, at);
        }
        insertBlock(block);
    });

    document.body.appendChild(overlay);
    refresh();
    setTimeout(() => query.focus(), 50);
};
