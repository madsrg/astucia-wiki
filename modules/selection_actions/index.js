// Read-mode text-selection toolbar. When the user selects text inside a rendered
// Markdown page (read mode only), a small floating toolbar appears above the
// selection offering: Quote in Chat, Ask AI, Copy, Search wiki, New page, Explain.
// The toolbar is a fixed-position overlay appended to <body> (mirrors the chat
// reaction picker) so it never affects page layout.

import { state } from '../core/state.js';
import { api } from '../core/api.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { quoteSelectionInChat, askAiAboutSelection } from '../page_chat/index.js';
import { createPageNamed } from '../new_items/index.js';

let _toolbar = null;
let _explainPop = null;
let _lastSel = null; // { text, rect } captured on the last valid selection

// Only markdown pages in read mode (not the editor, lists, diagrams, JSON, search).
const inReadModeMd = () => !state.isEditing && state.currentPageType === 'file';

const ICONS = {
    quote:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    ai:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15l-1.9-4.1L5.5 9l4.6-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/></svg>',
    copy:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    search:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    newpage: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
    explain: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
};

// Returns { text, rect } if there is a non-empty selection wholly inside
// #viewer-content, else null.
const selectionInViewer = () => {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return null;
    const text = sel.toString().trim();
    if (!text) return null;
    const viewer = document.getElementById('viewer-content');
    if (!viewer || !viewer.contains(sel.anchorNode) || !viewer.contains(sel.focusNode)) return null;
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    if (!rect || (rect.width === 0 && rect.height === 0)) return null;
    return { text, rect };
};

const sanitizeTitle = (text) =>
    text.split('\n')[0].replace(/[\/\\:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80);

// ── Actions ───────────────────────────────────────────────────────────────────

const doQuote = (text) => { hideToolbar(); quoteSelectionInChat(text); };
const doAskAi = (text) => { hideToolbar(); askAiAboutSelection(text); };

const doCopy = async (text) => {
    hideToolbar();
    try {
        await navigator.clipboard.writeText(text);
        showToast(t('select.copied'), 'success');
    } catch {
        showToast(t('select.copy-failed'), 'error');
    }
};

const doSearch = (text) => {
    hideToolbar();
    const input = document.getElementById('search-query-input');
    const btn   = document.getElementById('search-query-btn');
    if (!input || !btn) return;
    input.value = text.length > 200 ? text.slice(0, 200) : text;
    btn.click();
};

const doNewPage = (text) => {
    hideToolbar();
    const name = sanitizeTitle(text);
    if (!name) { showToast(t('select.title-empty'), 'error'); return; }
    createPageNamed(name);
};

const doExplain = async (text) => {
    const rect = _lastSel?.rect;
    hideToolbar();
    if (!rect) return;
    showExplainLoading(rect);
    const res = await api.call('ai_explain', { text, page: state.currentPagePath || '' }, 'POST');
    if (res && res.success) showExplainResult(res.reply, res.ai, rect);
    else showExplainError(res?.message || t('select.explain-failed'));
};

// ── Toolbar ─────────────────────────────────────────────────────────────────

const buildToolbar = () => {
    const bar = document.createElement('div');
    bar.className = 'selection-toolbar hidden';
    const mk = (key, label, handler) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'selection-toolbar-btn';
        b.title = label;
        b.setAttribute('aria-label', label);
        b.innerHTML = ICONS[key] + '<span>' + label + '</span>';
        // preventDefault on mousedown keeps the text selection alive while clicking.
        b.addEventListener('mousedown', (e) => e.preventDefault());
        b.addEventListener('click', (e) => { e.preventDefault(); handler(_lastSel ? _lastSel.text : ''); });
        return b;
    };
    bar.appendChild(mk('quote',   t('select.quote'),    doQuote));
    bar.appendChild(mk('ai',      t('select.ask-ai'),   doAskAi));
    bar.appendChild(mk('copy',    t('select.copy'),     doCopy));
    bar.appendChild(mk('search',  t('select.search'),   doSearch));
    bar.appendChild(mk('newpage', t('select.new-page'), doNewPage));
    bar.appendChild(mk('explain', t('select.explain'),  doExplain));
    document.body.appendChild(bar);
    return bar;
};

const showToolbar = (rect) => {
    if (!_toolbar) _toolbar = buildToolbar();
    _toolbar.classList.remove('hidden');
    const tb = _toolbar.getBoundingClientRect();
    let top = rect.top - tb.height - 8;
    if (top < 8) top = rect.bottom + 8; // flip below the selection if no room above
    let left = rect.left + rect.width / 2 - tb.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - tb.width - 8));
    _toolbar.style.top = top + 'px';
    _toolbar.style.left = left + 'px';
};

const hideToolbar = () => { if (_toolbar) _toolbar.classList.add('hidden'); };

// ── Explain popover ───────────────────────────────────────────────────────────

const buildExplain = () => {
    const p = document.createElement('div');
    p.className = 'selection-explain hidden';
    p.innerHTML =
        '<div class="selection-explain-head">' +
        '<span class="selection-explain-title"></span>' +
        '<button type="button" class="selection-explain-close" aria-label="Close">&times;</button>' +
        '</div><div class="selection-explain-body"></div>';
    p.querySelector('.selection-explain-close').addEventListener('click', hideExplain);
    document.body.appendChild(p);
    return p;
};

const positionExplain = (rect) => {
    const p = _explainPop;
    p.classList.remove('hidden');
    const pr = p.getBoundingClientRect();
    let top = rect.bottom + 8;
    if (top + pr.height > window.innerHeight - 8) top = Math.max(8, rect.top - pr.height - 8);
    let left = Math.max(8, Math.min(rect.left, window.innerWidth - pr.width - 8));
    p.style.top = top + 'px';
    p.style.left = left + 'px';
};

const showExplainLoading = (rect) => {
    if (!_explainPop) _explainPop = buildExplain();
    _explainPop.querySelector('.selection-explain-title').textContent = t('select.explain');
    _explainPop.querySelector('.selection-explain-body').innerHTML =
        '<span class="chat-spinner"></span> ' + t('select.explaining');
    positionExplain(rect);
};

const showExplainResult = (reply, ai, rect) => {
    if (!_explainPop) return;
    _explainPop.querySelector('.selection-explain-title').textContent =
        ai ? t('select.explain') + ' · ' + ai : t('select.explain');
    _explainPop.querySelector('.selection-explain-body').textContent = reply || '(empty)';
    positionExplain(rect);
};

const showExplainError = (msg) => {
    if (!_explainPop) return;
    _explainPop.querySelector('.selection-explain-body').textContent = '⚠ ' + msg;
};

const hideExplain = () => { if (_explainPop) _explainPop.classList.add('hidden'); };

// ── Init / wiring ───────────────────────────────────────────────────────────

export const init = () => {
    document.addEventListener('mouseup', (e) => {
        if (_toolbar && _toolbar.contains(e.target)) return; // clicking the toolbar itself
        // Let the browser finalize the selection before reading it.
        setTimeout(() => {
            if (!inReadModeMd()) { hideToolbar(); return; }
            const s = selectionInViewer();
            if (!s) { hideToolbar(); return; }
            _lastSel = s;
            showToolbar(s.rect);
        }, 0);
    });

    // Starting a new click/selection anywhere else dismisses the popovers.
    document.addEventListener('mousedown', (e) => {
        if (_toolbar && _toolbar.contains(e.target)) return;
        if (_explainPop && _explainPop.contains(e.target)) return;
        hideToolbar();
        hideExplain();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { hideToolbar(); hideExplain(); }
    });

    // A stale selection rectangle after scrolling would misplace the popovers.
    document.getElementById('viewer-container')
        ?.addEventListener('scroll', () => { hideToolbar(); hideExplain(); }, true);
};
