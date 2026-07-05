/**
 * advanced_search/index.js — viewer for `.search` content files.
 *
 * Chat-like layout: results turns stack above, query box below. The query uses
 * the compact token language parsed server-side by the `advanced_search` action
 * (free text + tag:<v> + updated:<Nd> + src:<slug>). Deterministic, no LLM.
 *
 * A `.search` file stores { query, source, title, lastRun, lastResult }.
 * Opening it restores the saved query/source (without executing) and, if a
 * previous run was saved, shows that result under a "Last run:" label. Running
 * replaces the stored result and timestamp.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { t } from '../i18n/index.js';
import { getMcpServers } from '../core/mcp_servers.js';

const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const canMcp = () => (window.WIKI_ROLE === 'admin' || window.WIKI_ROLE === 'editor');

let _path       = null;   // current .search file path
let _savedQuery = '';     // last-run query
let _savedSource = 'wiki';
let _title      = '';
let _lastRun    = null;   // ISO timestamp of the last executed search
let _lastResult = null;   // { displayQuery, sourceLabel, isLocal, res }
let _sources    = [];     // cached MCP servers [{name, slug, wiki_native}]

const resultsEl = () => document.getElementById('adv-search-results');
const inputEl   = () => document.getElementById('adv-search-input');

// ── Source radios ──────────────────────────────────────────────────────────────
const renderSources = (selected) => {
    const wrap = document.getElementById('adv-search-sources');
    if (!wrap) return;
    let html = `<label class="adv-source-opt"><input type="radio" name="adv-source" value="wiki" ${selected === 'wiki' ? 'checked' : ''}><span>${t('asearch.this-wiki')}</span></label>`;
    if (canMcp()) {
        _sources.forEach(s => {
            html += `<label class="adv-source-opt"><input type="radio" name="adv-source" value="${esc(s.slug)}" ${selected === s.slug ? 'checked' : ''}><span>${esc(s.name)}${s.wiki_native ? '' : ' <em class="adv-source-generic">(text only)</em>'}</span></label>`;
        });
    }
    wrap.innerHTML = html;
};

const selectedSource = () => document.querySelector('input[name="adv-source"]:checked')?.value || 'wiki';

// ── Results rendering ────────────────────────────────────────────────────────────
const scrollDown = () => { const r = resultsEl(); if (r) r.scrollTop = r.scrollHeight; };

const appendQueryTurn = (query, sourceLabel) => {
    const div = document.createElement('div');
    div.className = 'adv-turn adv-turn-query';
    div.innerHTML = `<span class="adv-turn-source">${esc(sourceLabel)}</span><code>${esc(query)}</code>`;
    resultsEl().appendChild(div);
};

const appendResultTurn = (res, isLocal) => {
    const div = document.createElement('div');
    div.className = 'adv-turn adv-turn-result';

    if (!res.success) {
        div.innerHTML = `<p class="adv-error">${esc(res.message || 'Search failed.')}</p>`;
        resultsEl().appendChild(div); scrollDown(); return;
    }

    if (res.mode === 'text') {
        div.innerHTML = `<div class="adv-text-result"><pre>${esc(res.text || '')}</pre></div>`;
        resultsEl().appendChild(div); scrollDown(); return;
    }

    const rows = res.data || [];
    if (!rows.length) {
        div.innerHTML = `<p class="adv-empty">${t('asearch.no-results')}</p>`;
        resultsEl().appendChild(div); scrollDown(); return;
    }

    const list = document.createElement('div');
    list.className = 'adv-result-list';
    rows.forEach(row => {
        const item = document.createElement('div');
        item.className = 'adv-result-item' + (isLocal ? ' adv-result-clickable' : '');
        const title = row.header ? esc(row.header.replace(/^#+\s*/, '')) : esc((row.path || '').split('/').pop());
        const meta  = [row.updated ? new Date(row.updated).toLocaleDateString() : '', row.updatedBy || '', (row.tags || []).map(tg => '#' + tg).join(' ')].filter(Boolean).join(' · ');
        item.innerHTML = `<div class="adv-result-title">${title}</div>`
            + `<div class="adv-result-path">${esc(row.path || '')}</div>`
            + (row.preview ? `<div class="adv-result-preview">${row.preview}</div>` : '')
            + (meta ? `<div class="adv-result-meta">${esc(meta)}</div>` : '');
        if (isLocal && row.path) {
            item.addEventListener('click', async () => {
                const { loadPage } = await import('../page_view/index.js');
                loadPage(row.path, row.id, row.tags || []);
            });
        }
        list.appendChild(item);
    });
    div.appendChild(list);
    resultsEl().appendChild(div);
    scrollDown();
};

const renderLastRun = (iso) => {
    const div = document.createElement('div');
    div.className = 'adv-lastrun';
    div.textContent = `${t('asearch.last-run')} ${new Date(iso).toLocaleString()}`;
    resultsEl().appendChild(div);
};

// ── Run + persist ────────────────────────────────────────────────────────────────
// Write the whole .search config (query, source, title, last run + result) back
// to the file. Mirrors page_edit: the `save` action reads the raw php://input.
const saveFile = async (cfg) => {
    const body = JSON.stringify(cfg);
    const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
    try {
        await fetch(`api.php?action=save&file=${encodeURIComponent(_path)}${spaceQs}`, {
            method: 'POST', headers: { 'Content-Type': 'text/plain' }, body,
        });
    } catch {}
};

const runSearch = async () => {
    const raw = inputEl().value.trim();
    const src = selectedSource();
    if (!raw && src === 'wiki') return;
    // Compose effective query: prepend src:<slug> when an MCP source radio is
    // chosen and the query doesn't already carry an explicit src:.
    let q = raw;
    if (src !== 'wiki' && !/\bsrc:/i.test(raw)) q = `src:${src} ${raw}`.trim();

    const srcMeta      = _sources.find(s => s.slug === src);
    const isLocal      = src === 'wiki' && !/\bsrc:/i.test(raw);
    const displayQuery = raw || '(all)';
    const sourceLabel  = src === 'wiki' ? t('asearch.this-wiki') : (srcMeta?.name || src);

    resultsEl().innerHTML = '';   // a new run replaces the previous result
    appendQueryTurn(displayQuery, sourceLabel);

    const btn = document.getElementById('adv-search-run-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    const res = await api.call('advanced_search', { q });
    if (btn) { btn.disabled = false; btn.textContent = t('asearch.run'); }
    appendResultTurn(res, isLocal);

    _savedQuery = raw; _savedSource = src;
    _lastRun    = new Date().toISOString();
    _lastResult = { displayQuery, sourceLabel, isLocal, res };
    saveFile({ query: raw, source: src, title: _title, lastRun: _lastRun, lastResult: _lastResult });
};

// ── Public: render a .search file ────────────────────────────────────────────────
export const renderSearchView = async (fileData, path) => {
    _path = path;
    let cfg = {};
    try { cfg = JSON.parse(fileData || '{}') || {}; } catch { cfg = {}; }
    _savedQuery  = cfg.query || '';
    _savedSource = cfg.source || 'wiki';
    _title       = cfg.title || '';
    _lastRun     = cfg.lastRun || null;
    _lastResult  = cfg.lastResult || null;

    _sources = canMcp() ? await getMcpServers() : [];
    renderSources(_savedSource);
    inputEl().value = _savedQuery;
    resultsEl().innerHTML = '';

    // Restore (but do not execute) the saved query/source. If a previous run
    // was saved, show its result under a "Last run:" label; running replaces it.
    if (_lastRun && _lastResult) {
        renderLastRun(_lastRun);
        appendQueryTurn(_lastResult.displayQuery, _lastResult.sourceLabel);
        appendResultTurn(_lastResult.res, _lastResult.isLocal);
    }
};

// ── Help text ────────────────────────────────────────────────────────────────────
const helpHtml = () => `
    <div class="adv-help-inner">
        <strong>${t('asearch.help-heading')}</strong>
        <ul>
            <li><code>onboarding checklist</code> — ${t('asearch.help-text')}</li>
            <li><code>tag:hr</code> — ${t('asearch.help-tag')}</li>
            <li><code>tag:"multi word"</code> — ${t('asearch.help-tag-quoted')}</li>
            <li><code>updated:7d</code> — ${t('asearch.help-updated')}</li>
            <li><code>src:othersite</code> — ${t('asearch.help-src')}</li>
        </ul>
        <p class="form-hint">${t('asearch.help-note')}</p>
    </div>`;

// ── Init (once) ──────────────────────────────────────────────────────────────────
export const init = () => {
    const input = inputEl();
    if (!input) return;

    document.getElementById('adv-search-run-btn')?.addEventListener('click', runSearch);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); runSearch(); }
    });

    const helpBtn = document.getElementById('adv-search-help-btn');
    const helpBox = document.getElementById('adv-search-help');
    helpBtn?.addEventListener('click', () => {
        if (helpBox.classList.contains('hidden')) helpBox.innerHTML = helpHtml();
        helpBox.classList.toggle('hidden');
    });
};
