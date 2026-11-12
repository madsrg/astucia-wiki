// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Content Health — what in the current Space needs attention: broken links, stale pages
 * and empty pages (see content_health.php). Shown as three tabs of the Wiki Pulse dialog
 * (modules/pulse), which owns the dialog; this module fetches the report and draws rows.
 *
 * For editors and administrators only; readers get no health tabs, since they cannot fix
 * anything it reports. The report is built on request rather than kept up to date: it
 * costs the server one read of the graph cache, and a list that changed while you were
 * fixing it would move rows out from under the cursor. Refresh re-asks.
 *
 * Every value from the server is set as text, never as HTML — paths, wikilink targets
 * and owners are all written by users.
 */
import { api } from '../core/api.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

export const HEALTH_TABS   = ['broken', 'stale', 'empty'];
export const STALE_CHOICES = [30, 90, 180, 365, 730];

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const fmtDate = (ts) => new Date(ts * 1000)
    .toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

let _seq = 0;   // a slow response for an older request must not replace a newer one

/** The report for the current Space; null when a newer request has superseded this one. */
export const loadHealthReport = async (staleDays) => {
    const seq = ++_seq;
    const res = await api.call('content_health', { stale_days: staleDays });
    if (seq !== _seq) return null;
    return res && res.success ? res : { failed: true, message: res?.message || t('health.failed') };
};

const brokenText = (l) => {
    switch (l.kind) {
        case 'page':       return t('health.link-page', { id: l.target });
        case 'space':      return t('health.link-space', { id: l.target, space: l.space });
        case 'attachment': return t('health.link-attachment', { target: l.target });
        default:           return t('health.link-wikilink', { target: l.target });
    }
};

const detailsFor = (row, tab) => {
    if (tab === 'broken') return row.links.map(brokenText);
    if (tab === 'empty')  return [t('health.chars', { n: row.chars })];
    if (tab === 'stale') {
        const out = [row.reason === 'review_overdue'
            ? t('health.overdue', { date: row.review_by, n: row.days })
            : t('health.not-updated', { n: row.days, date: row.updated ? fmtDate(row.updated) : '' })];
        if (row.owner) out.push(t('health.owner', { name: row.owner }));
        if (row.updated_by) out.push(t('health.last-edited', { name: row.updated_by }));
        return out;
    }
    return [];
};

/** One page row; `onOpen(id)` is called when it is clicked. */
export const healthRow = (row, tab, onOpen) => {
    const a = el('a', 'content-health-row');
    a.href = '#';
    a.dataset.id = row.id;
    const head = el('span', 'content-health-row-head');
    head.innerHTML = icons.file;                 // a fixed SVG, not data
    head.appendChild(el('span', 'content-health-row-title', row.title));
    const dir = row.path.includes('/') ? row.path.slice(0, row.path.lastIndexOf('/')) : '';
    if (dir) head.appendChild(el('span', 'content-health-row-path', dir));
    a.appendChild(head);
    const details = detailsFor(row, tab);
    if (details.length) {
        const ul = el('ul', 'content-health-row-details');
        details.forEach(d => ul.appendChild(el('li', '', d)));
        a.appendChild(ul);
    }
    a.addEventListener('click', (e) => { e.preventDefault(); onOpen(row.id); });
    return a;
};
