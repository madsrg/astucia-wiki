// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Content Health — one dialog listing what in the current Space needs attention:
 * broken links, orphans, stale pages and empty pages (see content_health.php).
 *
 * Opened from the sidebar by editors and administrators; readers get no button, since
 * they cannot fix anything it reports. The report is built on request rather than kept
 * up to date: it costs the server one read of the graph cache, and a list that changed
 * while you were fixing it would move rows out from under the cursor. Refresh re-asks.
 *
 * Every value from the server is set as text, never as HTML — paths, wikilink targets
 * and owners are all written by users.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

const TABS = ['broken', 'orphans', 'stale', 'empty'];
const STALE_CHOICES = [30, 90, 180, 365, 730];

let _onNavigate = null;
let _overlay = null;
let _tab = 'broken';
let _staleDays = 180;
let _report = null;
let _seq = 0;       // a slow response for an older request must not replace a newer one

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const fmtDate = (ts) => new Date(ts * 1000)
    .toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

const close = () => _overlay?.classList.add('hidden');

const build = () => {
    _overlay = el('div', 'lightbox-overlay hidden');
    _overlay.id = 'content-health-modal';
    _overlay.innerHTML = `
        <div class="lightbox-content content-health-content" role="dialog" aria-modal="true" aria-labelledby="content-health-title">
            <button class="lightbox-close" data-act="close">&times;</button>
            <h3 id="content-health-title"></h3>
            <p class="content-health-summary"></p>
            <div class="content-health-tabs" role="tablist"></div>
            <div class="content-health-toolbar">
                <p class="content-health-hint"></p>
                <label class="content-health-stale hidden">
                    <span></span>
                    <select></select>
                </label>
            </div>
            <div class="content-health-list"></div>
            <div class="lightbox-footer">
                <button class="btn btn-secondary" data-act="refresh"></button>
                <button class="btn btn-blue" data-act="close"></button>
            </div>
        </div>`;
    document.body.appendChild(_overlay);

    const tabs = _overlay.querySelector('.content-health-tabs');
    for (const name of TABS) {
        const b = el('button', 'content-health-tab');
        b.type = 'button';
        b.dataset.tab = name;
        b.setAttribute('role', 'tab');
        b.addEventListener('click', () => { _tab = name; render(); });
        tabs.appendChild(b);
    }
    const select = _overlay.querySelector('.content-health-stale select');
    for (const n of STALE_CHOICES) {
        const o = el('option', '', '');
        o.value = String(n);
        select.appendChild(o);
    }
    select.addEventListener('change', () => { _staleDays = parseInt(select.value, 10) || 180; load(); });

    _overlay.addEventListener('click', (e) => {
        if (e.target === _overlay) return close();
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (act === 'close') close();
        else if (act === 'refresh') load();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _overlay && !_overlay.classList.contains('hidden')) close();
    });
};

// Labels are applied on every open, so a language switch since the last one is honoured.
const applyLabels = () => {
    _overlay.querySelector('[data-act="refresh"]').textContent = t('health.refresh');
    _overlay.querySelector('.lightbox-footer [data-act="close"]').textContent = t('health.close');
    _overlay.querySelector('.content-health-stale span').textContent = t('health.stale-days');
    _overlay.querySelectorAll('.content-health-stale option').forEach(o => {
        o.textContent = t('health.days-option', { n: o.value });
    });
    _overlay.querySelector('.content-health-stale select').value = String(_staleDays);
};

const setMessage = (text) => {
    const list = _overlay.querySelector('.content-health-list');
    list.replaceChildren(el('p', 'content-health-empty', text));
};

const load = async () => {
    const seq = ++_seq;
    setMessage(t('health.loading'));
    const res = await api.call('content_health', { stale_days: _staleDays });
    if (seq !== _seq) return;
    if (!res || !res.success) {
        _report = null;
        _overlay.querySelector('.content-health-summary').textContent = '';
        setMessage(res?.message || t('health.failed'));
        return;
    }
    _report = res;
    render();
};

const brokenText = (l) => {
    switch (l.kind) {
        case 'page':       return t('health.link-page', { id: l.target });
        case 'space':      return t('health.link-space', { id: l.target, space: l.space });
        case 'attachment': return t('health.link-attachment', { target: l.target });
        default:           return t('health.link-wikilink', { target: l.target });
    }
};

const detailsFor = (row) => {
    if (_tab === 'broken') return row.links.map(brokenText);
    if (_tab === 'empty')  return [t('health.chars', { n: row.chars })];
    if (_tab === 'stale') {
        const out = [row.reason === 'review_overdue'
            ? t('health.overdue', { date: row.review_by, n: row.days })
            : t('health.not-updated', { n: row.days, date: row.updated ? fmtDate(row.updated) : '' })];
        if (row.owner) out.push(t('health.owner', { name: row.owner }));
        if (row.updated_by) out.push(t('health.last-edited', { name: row.updated_by }));
        return out;
    }
    return [];
};

const rowFor = (row) => {
    const a = el('a', 'content-health-row');
    a.href = '#';
    a.dataset.id = row.id;
    const head = el('span', 'content-health-row-head');
    head.innerHTML = icons.file;                 // a fixed SVG, not data
    head.appendChild(el('span', 'content-health-row-title', row.title));
    const dir = row.path.includes('/') ? row.path.slice(0, row.path.lastIndexOf('/')) : '';
    if (dir) head.appendChild(el('span', 'content-health-row-path', dir));
    a.appendChild(head);
    const details = detailsFor(row);
    if (details.length) {
        const ul = el('ul', 'content-health-row-details');
        details.forEach(d => ul.appendChild(el('li', '', d)));
        a.appendChild(ul);
    }
    a.addEventListener('click', (e) => {
        e.preventDefault();
        close();
        _onNavigate?.(row.id);
    });
    return a;
};

const render = () => {
    const r = _report;
    if (!r) return;
    const c = r.counts;
    _overlay.querySelector('.content-health-summary').textContent = t('health.summary', { pages: c.pages });

    const totals = { broken: c.broken, orphans: c.orphans, stale: c.stale, empty: c.empty };
    _overlay.querySelectorAll('.content-health-tab').forEach(b => {
        const name = b.dataset.tab;
        b.replaceChildren(el('span', '', t(`health.tab-${name}`)),
                          el('span', 'content-health-count' + (totals[name] ? ' nonzero' : ''), String(totals[name])));
        b.classList.toggle('active', name === _tab);
        b.setAttribute('aria-selected', name === _tab ? 'true' : 'false');
    });
    _overlay.querySelector('.content-health-hint').textContent = t(`health.hint-${_tab}`);
    _overlay.querySelector('.content-health-stale').classList.toggle('hidden', _tab !== 'stale');

    const rows = r[_tab] || [];
    if (!rows.length) return setMessage(t(`health.none-${_tab}`));
    const list = _overlay.querySelector('.content-health-list');
    const frag = document.createDocumentFragment();
    rows.forEach(row => frag.appendChild(rowFor(row)));
    if (totals[_tab] > rows.length) {
        frag.appendChild(el('p', 'content-health-empty',
            t('health.truncated', { shown: rows.length, total: totals[_tab] })));
    }
    list.replaceChildren(frag);
};

export const openContentHealth = () => {
    if (!_overlay) build();
    applyLabels();
    _overlay.querySelector('#content-health-title').textContent = state.currentSpace
        ? t('health.title', { space: state.currentSpace })
        : t('health.title-root');
    _overlay.querySelector('.content-health-summary').textContent = '';
    _overlay.querySelectorAll('.content-health-tab').forEach(b => b.replaceChildren(el('span', '', t(`health.tab-${b.dataset.tab}`))));
    _overlay.querySelector('.content-health-hint').textContent = '';
    _overlay.classList.remove('hidden');
    load();
};

export const init = ({ onNavigate } = {}) => {
    _onNavigate = onNavigate;
    document.getElementById('content-health-btn')?.addEventListener('click', openContentHealth);
};
