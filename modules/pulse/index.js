// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Wiki Pulse — one dialog for what needs your attention, behind one sidebar icon:
 *
 *   Mentions · Comments          yours (a signed-in person; data-mine on the button)
 *   Broken links · Stale · Empty the current Space's health (editors and admins; data-health)
 *
 * It replaced three icons — My Mentions, My Comments and Content Health — and so it owns
 * none of their logic: modules/mentions fetches the two lists and keeps the unread badge
 * (now on this button) and its polling; modules/content_health fetches the report and
 * draws its rows. This is the shell: tabs, scope, which tab opens, and navigating away.
 *
 * - One row of tabs, never tabs inside a tab.
 * - The tabs have two scopes, and each says which: Mentions are across the whole wiki,
 *   Comments and the health tabs are the current Space. Otherwise a count read on one tab
 *   is easily taken for the other kind.
 * - It opens on Mentions when there are unread ones — the badge that sent you here — and
 *   otherwise on the tab used last.
 * - Opening Mentions is reading them: modules/mentions clears the badge and tells the
 *   server, as the old My Mentions dialog did.
 * - Closing happens before navigating, or the dialog sits over the page it just opened.
 * - Data is fetched per opening and per tab, on first view: a list kept from an earlier
 *   opening would show mentions as new that were read since.
 */
import { state } from '../core/state.js';
import { t } from '../i18n/index.js';
import { buildResultCard, openResult } from '../search/index.js';
import { fetchMentions, fetchComments, unreadCount } from '../mentions/index.js';
import { HEALTH_TABS, STALE_CHOICES, loadHealthReport, healthRow } from '../content_health/index.js';

const MINE_TABS = ['mentions', 'comments'];
const LAST_TAB_KEY = 'pulse_tab';

let _overlay = null;
let _tabs = [];          // the tabs this person has
let _tab = null;
let _data = {};          // per opening: mentions / comments / health
let _staleDays = 180;
let _onNavigate = null;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};
const $ = (sel) => _overlay.querySelector(sel);
const isHealth = (tab) => HEALTH_TABS.includes(tab);

const close = () => _overlay?.classList.add('hidden');

const build = () => {
    _overlay = el('div', 'lightbox-overlay hidden');
    _overlay.id = 'pulse-modal';
    _overlay.innerHTML = `
        <div class="lightbox-content content-health-content pulse-content" role="dialog" aria-modal="true" aria-labelledby="pulse-title">
            <button class="lightbox-close" data-act="close">&times;</button>
            <h3 id="pulse-title"></h3>
            <div class="content-health-tabs pulse-tabs" role="tablist"></div>
            <p class="pulse-scope"></p>
            <div class="content-health-toolbar">
                <p class="content-health-hint"></p>
                <label class="content-health-stale hidden">
                    <span></span>
                    <select></select>
                </label>
            </div>
            <div class="content-health-list pulse-list"></div>
            <div class="lightbox-footer">
                <button class="btn btn-secondary" data-act="refresh"></button>
                <button class="btn btn-blue" data-act="close"></button>
            </div>
        </div>`;
    document.body.appendChild(_overlay);

    const bar = $('.pulse-tabs');
    for (const name of _tabs) {
        const b = el('button', 'content-health-tab pulse-tab');
        b.type = 'button';
        b.dataset.tab = name;
        b.setAttribute('role', 'tab');
        b.addEventListener('click', () => select(name));
        bar.appendChild(b);
    }
    const sel = $('.content-health-stale select');
    for (const n of STALE_CHOICES) {
        const o = el('option');
        o.value = String(n);
        sel.appendChild(o);
    }
    sel.addEventListener('change', () => {
        _staleDays = parseInt(sel.value, 10) || 180;
        _data.health = null;
        ensure('health');
    });

    // Mention and comment rows are the search module's cards; open them its way, since a
    // mention can be in another Space.
    $('.pulse-list').addEventListener('click', async (e) => {
        const link = e.target.closest('.search-result-link');
        if (!link) return;
        e.preventDefault();
        close();
        await openResult(link.dataset.id, link.dataset.space || null);
    });
    _overlay.addEventListener('click', (e) => {
        if (e.target === _overlay) return close();
        const act = e.target.closest('[data-act]')?.dataset.act;
        if (act === 'close') close();
        else if (act === 'refresh') refresh();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && _overlay && !_overlay.classList.contains('hidden')) close();
    });
};

// Labels are applied on every open, so a language switch since the last one is honoured.
const applyLabels = () => {
    $('#pulse-title').textContent = t('pulse.title');
    $('[data-act="refresh"]').textContent = t('health.refresh');
    $('.lightbox-footer [data-act="close"]').textContent = t('health.close');
    $('.content-health-stale span').textContent = t('health.stale-days');
    _overlay.querySelectorAll('.content-health-stale option').forEach(o => {
        o.textContent = t('health.days-option', { n: o.value });
    });
    $('.content-health-stale select').value = String(_staleDays);
};

const tabLabel = (name) => t(isHealth(name) ? `health.tab-${name}` : `pulse.tab-${name}`);

/** A count on a tab: unread mentions until opened, and the health report's totals. */
const tabCount = (name) => {
    if (name === 'mentions') { const n = _data.mentions ? 0 : (unreadCount() || 0); return n || null; }
    if (isHealth(name) && _data.health && !_data.health.failed) return _data.health.counts[name];
    return null;
};

const renderTabs = () => {
    _overlay.querySelectorAll('.pulse-tab').forEach(b => {
        const name = b.dataset.tab;
        const n = tabCount(name);
        const parts = [el('span', '', tabLabel(name))];
        if (n !== null) parts.push(el('span', 'content-health-count' + (n ? ' nonzero' : ''), String(n)));
        b.replaceChildren(...parts);
        b.classList.toggle('active', name === _tab);
        b.setAttribute('aria-selected', name === _tab ? 'true' : 'false');
    });
};

const setMessage = (text) => $('.pulse-list').replaceChildren(el('p', 'content-health-empty', text));

const scopeText = (tab) => tab === 'mentions'
    ? t('pulse.scope-all')
    : (state.currentSpace ? t('pulse.scope-space', { space: state.currentSpace }) : t('pulse.scope-root'));

const renderList = () => {
    const tab = _tab;
    $('.pulse-scope').textContent = scopeText(tab);
    $('.content-health-stale').classList.toggle('hidden', tab !== 'stale');
    const hint = $('.content-health-hint');

    if (!isHealth(tab)) {
        const d = _data[tab];
        hint.textContent = tab === 'mentions' && d?.cutoffDays ? t('mentions.cutoff-note', { days: d.cutoffDays }) : '';
        if (!d) return setMessage(t('health.loading'));
        if (d.failed) return setMessage(t('health.failed'));
        if (!d.rows.length) return setMessage(t('mentions.none'));
        // buildResultCard is the search module's own card markup, as the old dialog used.
        const wrap = el('div', 'search-results mentions-view');
        wrap.innerHTML = d.rows.map(r => buildResultCard(r, tab === 'mentions')).join('');
        $('.pulse-list').replaceChildren(wrap);
        return;
    }

    hint.textContent = t(`health.hint-${tab}`);
    const r = _data.health;
    if (!r) return setMessage(t('health.loading'));
    if (r.failed) return setMessage(r.message);
    const rows = r[tab] || [];
    if (!rows.length) return setMessage(t(`health.none-${tab}`));
    const frag = document.createDocumentFragment();
    frag.appendChild(el('p', 'content-health-summary', t('health.summary', { pages: r.counts.pages })));
    rows.forEach(row => frag.appendChild(healthRow(row, tab, (id) => { close(); _onNavigate?.(id); })));
    if (r.counts[tab] > rows.length) {
        frag.appendChild(el('p', 'content-health-empty', t('health.truncated', { shown: rows.length, total: r.counts[tab] })));
    }
    $('.pulse-list').replaceChildren(frag);
};

/** Fetch what a tab needs, once per opening. `key` is 'mentions' | 'comments' | 'health'. */
const ensure = async (key) => {
    if (_data[key] !== null && _data[key] !== undefined) return;
    _data[key] = undefined;   // in flight
    let res;
    if (key === 'health') res = await loadHealthReport(_staleDays);
    else res = await (key === 'mentions' ? fetchMentions() : fetchComments());
    if (key === 'health' && res === null) return;          // superseded by a newer request
    _data[key] = res || { failed: true, rows: [] };
    renderTabs();
    if ((key === 'health' && isHealth(_tab)) || key === _tab) renderList();
};

const select = (tab) => {
    _tab = tab;
    try { localStorage.setItem(LAST_TAB_KEY, tab); } catch {}
    renderTabs();
    renderList();
    ensure(isHealth(tab) ? 'health' : tab);
};

const refresh = () => {
    const key = isHealth(_tab) ? 'health' : _tab;
    _data[key] = null;
    renderList();
    ensure(key);
};

export const openPulse = (tab = null) => {
    if (!_overlay) build();
    applyLabels();
    _data = { mentions: null, comments: null, health: null };
    let start = tab && _tabs.includes(tab) ? tab : null;
    if (!start && _tabs.includes('mentions') && (unreadCount() || 0) > 0) start = 'mentions';
    if (!start) { try { const last = localStorage.getItem(LAST_TAB_KEY); if (_tabs.includes(last)) start = last; } catch {} }
    start ||= _tabs[0];
    _overlay.classList.remove('hidden');
    select(start);
    // The health counts belong on their tabs from the start, whichever tab opened.
    if (_tabs.includes('broken') && !isHealth(start)) ensure('health');
};

export const init = ({ onNavigate } = {}) => {
    _onNavigate = onNavigate;
    const btn = document.getElementById('pulse-btn');
    if (!btn) return;
    _tabs = [...(btn.dataset.mine === '1' ? MINE_TABS : []), ...(btn.dataset.health === '1' ? HEALTH_TABS : [])];
    if (!_tabs.length) return;
    btn.addEventListener('click', () => openPulse());
};
