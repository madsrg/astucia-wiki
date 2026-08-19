// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Editor-style page tabs.
 *
 * The feature was inspired by the tabs in Kai-Syuan Tseng's plugin bundle
 * (https://github.com/kaisyuan-tseng/astucia-wiki-plugins) — the behaviour it demonstrates
 * (preview slot, unsaved-work handling, close others/right) is the specification this was
 * written against. The implementation is independent: that bundle caches each tab's rendered
 * DOM, whereas this keeps only identity plus a resume record and re-renders through loadPage
 * (see below for why).
 *
 * A tab stores only *identity* plus a small resume record —
 * `{ path, id, tags, type, title, isPreview, scrollTop, draft }`. Switching tabs
 * re-renders through the ordinary `loadPage()` path and then re-applies that record.
 * Nothing here caches rendered DOM, which is the whole point: every content type
 * (including ones added later) works without a per-type restore branch, no list of
 * container/button ids has to be kept in step with the rest of the app, and event
 * handlers are live by construction rather than needing to be re-bound.
 *
 * This module does not *mediate* page opens. `loadPage()` is already the single
 * funnel all ~20 of its call sites go through, so page_view calls two hooks
 * (registered here via `setTabHooks`) and the tab bar follows along:
 *
 *   beforeLoad(nextPath) → snapshot the outgoing tab. Returns true when that page's
 *       unsaved work is safely held as a draft, which lets loadPage skip its discard
 *       prompt (switching tabs should not interrogate the user).
 *   afterLoad(detail)    → create/activate the tab for the page just rendered.
 *
 * Tabs are per space and kept in memory, so leaving a space and coming back restores
 * that workspace. The tab *list* is persisted in localStorage; drafts are not (see
 * `persist`).
 *
 * Deliberate v1 limits, each chosen so nothing can be silently lost:
 *   - Drafts are kept for the classic Markdown editor only. A dirty inline-mode or
 *     `.json` page still gets loadPage's discard prompt, exactly as it does today,
 *     because reconstructing those editors from text is a separate job.
 *   - Scroll position is restored for Markdown pages. Lists, chats and search
 *     results open at the top, which is what opening them fresh does anyway.
 */
import { loadPage, showBlankPage, setTabHooks } from '../page_view/index.js';
import { state } from '../core/state.js';
import { confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

const BAR_ID       = 'wiki-tabs';
const STORE_PREFIX = 'wikiTabs:';
const MAX_PERSIST  = 30;     // cap the restored list; a runaway session shouldn't slow boot

// space → { tabs: Tab[], activeIdx: number }
const _workspaces = new Map();
// Set while we are driving loadPage ourselves, so afterLoad knows this is an
// activation (the tab already exists) rather than a fresh open from elsewhere.
let _resuming = null;

const EXT_RE = /\.(md|drawio|list|chat|search|json)$/;

const typeForPath = (path) => {
    if (!path) return null;
    if (path.endsWith('.drawio')) return 'diagram';
    if (path.endsWith('.list'))   return 'list';
    if (path.endsWith('.chat'))   return 'chat';
    if (path.endsWith('.search')) return 'search';
    if (path.endsWith('.json'))   return 'json';
    return 'file';
};

const titleForPath = (path) =>
    path ? (path.split('/').filter(Boolean).pop() || '').replace(EXT_RE, '') : '';

const iconForType = (type) => ({
    diagram: icons.diagram, list: icons.list, chat: icons.chat,
    search:  icons.search,  json: icons.json,
}[type] || icons.file);

// ── workspace helpers ────────────────────────────────────────────────────────

const ws = () => {
    const space = state.currentSpace || '';
    if (!_workspaces.has(space)) _workspaces.set(space, { tabs: [], activeIdx: -1 });
    return _workspaces.get(space);
};

const activeTab = () => {
    const w = ws();
    return w.tabs[w.activeIdx] || null;
};

const findIdx = (path) => ws().tabs.findIndex(tb => tb.path === path);

const makeTab = (path, id, tags, isPreview) => ({
    path: path || null,
    id: id ?? null,
    tags: tags || [],
    type: typeForPath(path),
    title: titleForPath(path),
    isPreview: !!isPreview,
    scrollTop: 0,
    draft: null,
});

// ── snapshot / resume ────────────────────────────────────────────────────────

// Only Markdown read-mode scroll is worth restoring: loadPage deliberately starts
// lists, chats and search results at the top, so "restoring" those would differ
// from opening them normally.
const scrollEl = (type) => (type === 'file' ? document.getElementById('viewer-content') : null);

/**
 * Capture the outgoing tab's resume record.
 *
 * Returns true when it is safe for loadPage to skip its discard prompt — i.e. either
 * there is no unsaved work, or the unsaved work is now held in `tab.draft` and will
 * be put back when the user returns to this tab.
 */
const snapshotOutgoing = (nextPath) => {
    const tab = activeTab();
    // A reload of the same page (git revert, a watcher-driven refresh) is not a tab
    // switch: there is no other tab to hold the draft, so let the normal prompt run.
    if (!tab || !tab.path || tab.path === nextPath) return false;

    const sc = scrollEl(tab.type);
    if (sc) tab.scrollTop = sc.scrollTop;

    if (!state.hasUnsavedChanges) { tab.draft = null; return true; }

    const editor = document.getElementById('editor-container');
    const canDraft = state.isEditing && state.editMode !== 'inline'
                     && state.currentPageType === 'file' && editor;
    if (!canDraft) {
        // Dirty inline/json page: we cannot reconstruct it, so fall through to
        // loadPage's prompt. Clear any older draft so a discarded edit cannot
        // reappear later.
        tab.draft = null;
        return false;
    }

    tab.draft = {
        text:     editor.value,
        selStart: editor.selectionStart ?? 0,
        selEnd:   editor.selectionEnd ?? 0,
    };
    // An edited page is no longer a throwaway peek.
    tab.isPreview = false;
    return true;
};

/**
 * Leave the current page for something that is *not* a loadPage (a blank tab).
 *
 * loadPage asks before discarding an edit it cannot keep; showBlankPage does not, so
 * without this an unsaved inline or `.json` edit would disappear when you clicked "+".
 * Returns false if the user chose to keep editing.
 */
const leaveCurrent = async (nextPath) => {
    if (snapshotOutgoing(nextPath)) return true;
    if (!state.hasUnsavedChanges) return true;
    return confirmModal(t('edit.discard-confirm'), {
        message: t('edit.discard-nav'), confirmLabel: t('btn.discard'),
        dangerous: true, icon: icons.warning,
    });
};

const resumeTab = async (tab) => {
    if (tab.draft) {
        const { setEditingMode } = await import('../page_edit/index.js');
        await setEditingMode(true, { silent: true });
        const editor = document.getElementById('editor-container');
        if (editor) {
            editor.value = tab.draft.text;
            try {
                editor.selectionStart = tab.draft.selStart;
                editor.selectionEnd   = tab.draft.selEnd;
            } catch { /* value shorter than the stored offsets */ }
        }
        state.hasUnsavedChanges = true;
        const saveBtn = document.getElementById('save-btn');
        if (saveBtn) saveBtn.disabled = false;
        // The draft has been handed back to the editor, so the editor owns it again.
        // Keeping a copy here would outlive the edit and leave the tab marked unsaved
        // even after a save; leaving this tab re-captures it from the editor anyway.
        tab.draft = null;
        render();
        return;
    }
    if (!tab.scrollTop) return;
    const sc = scrollEl(tab.type);
    // The freshly rendered content has no layout yet, so scrollTop would be clamped
    // to 0 if set in this tick.
    if (sc) requestAnimationFrame(() => requestAnimationFrame(() => { sc.scrollTop = tab.scrollTop; }));
};

// ── persistence ──────────────────────────────────────────────────────────────

// The list only — never drafts. Unsaved text in localStorage would outlive the
// session that produced it and could be restored over a file edited since.
const persist = () => {
    const w = ws();
    const payload = {
        activeIdx: w.activeIdx,
        tabs: w.tabs.filter(tb => tb.path).slice(0, MAX_PERSIST)
            .map(tb => ({ path: tb.path, id: tb.id, tags: tb.tags, isPreview: tb.isPreview })),
    };
    try {
        localStorage.setItem(STORE_PREFIX + (state.currentSpace || ''), JSON.stringify(payload));
    } catch { /* private mode or quota — tabs simply don't persist */ }
};

const flattenTree = (items, out = new Set()) => {
    for (const item of items || []) {
        if (item.path) out.add(item.path);
        if (item.children) flattenTree(item.children, out);
    }
    return out;
};

// Restore the persisted list, dropping anything that no longer exists on disk —
// otherwise a deleted page leaves a tab that renders nothing when clicked.
const restore = () => {
    const w = ws();
    if (w.tabs.length) return;          // already populated this session
    let saved = null;
    try {
        saved = JSON.parse(localStorage.getItem(STORE_PREFIX + (state.currentSpace || '')) || 'null');
    } catch { saved = null; }
    if (!saved || !Array.isArray(saved.tabs)) return;

    const known = flattenTree(state.fullFileTree);
    const live  = saved.tabs.filter(tb => tb.path && known.has(tb.path));
    if (!live.length) return;

    w.tabs = live.map(tb => makeTab(tb.path, tb.id, tb.tags, tb.isPreview));
    w.activeIdx = Math.min(Math.max(saved.activeIdx ?? 0, 0), w.tabs.length - 1);
};

/**
 * The tab the browser was last on, for script.js to open instead of the start page.
 * Returns null when there is nothing to resume.
 */
export const resumeTarget = () => {
    const tab = activeTab();
    return tab && tab.path ? { path: tab.path, id: tab.id, tags: tab.tags } : null;
};

// ── rendering ────────────────────────────────────────────────────────────────

const ensureBar = () => {
    let bar = document.getElementById(BAR_ID);
    if (bar) return bar;
    bar = document.createElement('div');
    bar.id = BAR_ID;
    bar.className = 'wiki-tabs';
    // Above the page header: tabs choose which page the header describes, so they
    // sit one level up from it.
    document.querySelector('.main-content')?.insertAdjacentElement('afterbegin', bar);
    bar.addEventListener('contextmenu', onBarContextMenu);
    return bar;
};

// Two tabs can hold the same filename in different folders — show the parent folder
// on both so they can be told apart.
const labelFor = (tab, all) => {
    const base = tab.title || t('tabs.new-tab');
    if (!tab.path) return base;
    if (!all.some(other => other !== tab && other.title === base)) return base;
    const parts = tab.path.split('/').filter(Boolean);
    parts.pop();
    return parts.length ? `${parts[parts.length - 1]} / ${base}` : base;
};

const render = () => {
    const bar = ensureBar();
    const w = ws();
    bar.classList.toggle('hidden', w.tabs.length === 0);
    bar.innerHTML = '';

    const strip = document.createElement('div');
    strip.className = 'wiki-tabs-strip';
    bar.appendChild(strip);

    w.tabs.forEach((tab, idx) => {
        const dirty = idx === w.activeIdx && state.hasUnsavedChanges;
        const el = document.createElement('div');
        el.className = 'wiki-tab'
            + (idx === w.activeIdx ? ' active' : '')
            + (tab.isPreview ? ' preview' : '')
            + (dirty || tab.draft ? ' dirty' : '');
        el.draggable = true;
        el.dataset.idx = String(idx);
        el.title = tab.path || t('tabs.new-tab');

        const icon = document.createElement('span');
        icon.className = 'wiki-tab-icon';
        icon.innerHTML = tab.path ? iconForType(tab.type) : icons.file;
        el.appendChild(icon);

        const name = document.createElement('span');
        name.className = 'wiki-tab-name';
        name.textContent = labelFor(tab, w.tabs);
        el.appendChild(name);

        const close = document.createElement('button');
        close.className = 'wiki-tab-close';
        close.type = 'button';
        close.title = t('tabs.close');
        close.setAttribute('aria-label', t('tabs.close'));
        // Both glyphs are always present; CSS shows the dot for unsaved work and swaps
        // back to the × on hover, so a dirty tab stays closable in one click.
        close.innerHTML = '<span class="tab-x">&times;</span><span class="tab-dot">&bull;</span>';
        close.addEventListener('click', (e) => { e.stopPropagation(); closeAt(idx); });
        el.appendChild(close);

        el.addEventListener('click', () => activate(idx));
        // Double-click keeps a peeked page around, matching editors.
        el.addEventListener('dblclick', () => {
            if (!tab.isPreview) return;
            tab.isPreview = false;
            render(); persist();
        });
        // Middle-click closes, as everywhere else with tabs.
        el.addEventListener('auxclick', (e) => {
            if (e.button !== 1) return;
            e.preventDefault();
            closeAt(idx);
        });
        strip.appendChild(el);
    });

    const add = document.createElement('button');
    add.className = 'wiki-tab-add';
    add.type = 'button';
    add.title = t('tabs.new-tab');
    add.setAttribute('aria-label', t('tabs.new-tab'));
    add.textContent = '+';
    add.addEventListener('click', () => openBlank());
    strip.appendChild(add);

    wireDrag(strip);
    scrollActiveIntoView(strip);
};

const scrollActiveIntoView = (strip) => {
    const el = strip.querySelector('.wiki-tab.active');
    if (!el) return;
    const left  = el.offsetLeft;
    const right = left + el.offsetWidth;
    if (left < strip.scrollLeft) strip.scrollLeft = Math.max(0, left - 8);
    else if (right > strip.scrollLeft + strip.clientWidth) strip.scrollLeft = right - strip.clientWidth + 8;
};

// ── reordering ───────────────────────────────────────────────────────────────

let _dragFrom = -1;

const wireDrag = (strip) => {
    strip.addEventListener('dragstart', (e) => {
        const el = e.target.closest('.wiki-tab');
        if (!el) return;
        _dragFrom = Number(el.dataset.idx);
        el.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox refuses to start a drag without payload.
        try { e.dataTransfer.setData('text/plain', el.dataset.idx); } catch { /* ignore */ }
    });
    strip.addEventListener('dragend', () => {
        strip.querySelector('.wiki-tab.dragging')?.classList.remove('dragging');
        _dragFrom = -1;
    });
    strip.addEventListener('dragover', (e) => {
        if (_dragFrom < 0) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });
    strip.addEventListener('drop', (e) => {
        if (_dragFrom < 0) return;
        e.preventDefault();
        const over = e.target.closest('.wiki-tab');
        if (!over) return;
        const w = ws();
        let to = Number(over.dataset.idx);
        const rect = over.getBoundingClientRect();
        if (e.clientX - rect.left > rect.width / 2) to += 1;
        if (to > _dragFrom) to -= 1;
        if (to === _dragFrom) return;
        // Track the active tab through the move by identity, not index.
        const active = activeTab();
        const [moved] = w.tabs.splice(_dragFrom, 1);
        w.tabs.splice(to, 0, moved);
        w.activeIdx = active ? w.tabs.indexOf(active) : -1;
        render(); persist();
    });
};

// ── context menu ─────────────────────────────────────────────────────────────

let _menu = null;

const closeMenu = () => { _menu?.remove(); _menu = null; };

const onBarContextMenu = (e) => {
    const el = e.target.closest('.wiki-tab');
    if (!el) return;
    e.preventDefault();
    const idx = Number(el.dataset.idx);
    const w = ws();
    const items = [
        { label: t('tabs.close'),        run: () => closeAt(idx) },
        { label: t('tabs.close-others'), run: () => closeMany(i => i !== idx), when: w.tabs.length > 1 },
        { label: t('tabs.close-right'),  run: () => closeMany(i => i > idx),   when: idx < w.tabs.length - 1 },
        { label: t('tabs.close-all'),    run: () => closeMany(() => true) },
    ].filter(it => it.when !== false);

    closeMenu();
    _menu = document.createElement('div');
    _menu.className = 'wiki-tab-menu';
    items.forEach(it => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = it.label;
        b.addEventListener('click', () => { closeMenu(); it.run(); });
        _menu.appendChild(b);
    });
    document.body.appendChild(_menu);
    // Keep the menu on screen when the tab is near the right/bottom edge.
    const r = _menu.getBoundingClientRect();
    _menu.style.left = `${Math.min(e.clientX, window.innerWidth  - r.width  - 4)}px`;
    _menu.style.top  = `${Math.min(e.clientY, window.innerHeight - r.height - 4)}px`;
};

// ── open / activate / close ──────────────────────────────────────────────────

export const activate = async (idx) => {
    const w = ws();
    const tab = w.tabs[idx];
    if (!tab || idx === w.activeIdx) return;

    if (!tab.path) {                       // an empty "+" tab
        if (!await leaveCurrent(null)) return;
        w.activeIdx = idx;
        render(); persist();
        await showBlankPage();
        return;
    }

    _resuming = tab;
    try {
        // beforeLoad snapshots the outgoing tab; afterLoad marks this one active.
        await loadPage(tab.path, tab.id, tab.tags);
        // loadPage aborts (returns without loading) only if its discard prompt was
        // declined, in which case afterLoad never ran and we are still on the old tab.
        if (w.tabs[w.activeIdx] === tab) await resumeTab(tab);
    } finally {
        _resuming = null;
    }
};

export const openBlank = async () => {
    const w = ws();
    if (!await leaveCurrent(null)) return;
    const tab = makeTab(null, null, [], false);
    w.tabs.splice(w.activeIdx + 1, 0, tab);
    w.activeIdx = w.tabs.indexOf(tab);
    render(); persist();
    await showBlankPage();
};

export const closeAt = async (idx) => {
    const w = ws();
    const tab = w.tabs[idx];
    if (!tab) return;

    // Closing is the only action that destroys work, so it is the only one that asks.
    const dirty = tab.draft || (idx === w.activeIdx && state.hasUnsavedChanges);
    if (dirty && !await confirmModal(t('edit.discard-confirm'), {
        message: t('tabs.close-discard'), confirmLabel: t('btn.discard'),
        dangerous: true, icon: icons.warning,
    })) return;

    const wasActive = idx === w.activeIdx;
    w.tabs.splice(idx, 1);
    if (!wasActive) {
        if (idx < w.activeIdx) w.activeIdx -= 1;
        render(); persist();
        return;
    }

    // Closing the active tab: the neighbour on the right, else on the left.
    w.activeIdx = -1;
    const next = w.tabs[idx] ? idx : idx - 1;
    if (w.tabs[next]) {
        // hasUnsavedChanges still describes the page we just discarded; clear it so
        // the new tab's load isn't blocked by a prompt about work that is gone.
        state.hasUnsavedChanges = false;
        await activate(next);
        return;
    }
    state.hasUnsavedChanges = false;
    render(); persist();
    await showBlankPage();
    document.querySelectorAll('#file-navigator .file-item.active')
        .forEach(el => el.classList.remove('active'));
};

const closeMany = async (predicate) => {
    const w = ws();
    // Collect the paths first: indices shift as tabs are removed.
    const doomed = w.tabs.filter((_, i) => predicate(i));
    for (const tab of doomed) {
        const at = w.tabs.indexOf(tab);
        if (at !== -1) await closeAt(at);
    }
};

// ── hooks called by page_view ────────────────────────────────────────────────

const afterLoad = ({ path, id, tags, type, intent }) => {
    const w = ws();
    const existing = findIdx(path);

    if (existing !== -1) {
        const tab = w.tabs[existing];
        tab.id = id ?? tab.id;
        if (tags && tags.length) tab.tags = tags;
        tab.type = type || tab.type;
        if (intent === 'permanent') tab.isPreview = false;
        w.activeIdx = existing;
        render(); persist();
        return;
    }
    if (_resuming) return;   // activation of a tab that was just removed underneath us

    const preview = intent !== 'permanent';
    const active  = activeTab();
    // What counts as a slot to reuse rather than another tab to accumulate:
    //   - an empty "+" tab, whatever the intent — that is what it is there for;
    //   - the preview tab, but only for another peek. "Open in new tab" must never
    //     consume the preview slot, or the page you were peeking at disappears.
    // A tab holding a draft is never reused. (An outgoing page that was dirty but
    // could not be drafted has already been discarded through loadPage's prompt.)
    const reusable = active && !active.draft && (!active.path || (preview && active.isPreview));

    if (reusable) {
        active.path      = path;
        active.id        = id ?? null;
        active.tags      = tags || [];
        active.type      = type || typeForPath(path);
        active.title     = titleForPath(path);
        active.isPreview = preview;
        active.scrollTop = 0;
        active.draft     = null;
        render(); persist();
        return;
    }

    const tab = makeTab(path, id, tags, preview);
    tab.type = type || tab.type;
    w.tabs.splice(w.activeIdx + 1, 0, tab);   // next to the tab it was opened from
    w.activeIdx = w.tabs.indexOf(tab);
    render(); persist();
};

// ── external file changes ────────────────────────────────────────────────────

/** A rename or move keeps the tab (and its draft) pointed at the same file. */
export const retarget = (oldPath, newPath) => {
    if (!oldPath || !newPath || oldPath === newPath) return;
    const idx = findIdx(oldPath);
    if (idx === -1) return;
    const tab = ws().tabs[idx];
    tab.path  = newPath;
    tab.type  = typeForPath(newPath);
    tab.title = titleForPath(newPath);
    render(); persist();
};

/** A deleted page must not leave a tab that opens nothing. */
export const forget = (path) => {
    const w = ws();
    const idx = findIdx(path);
    if (idx === -1) return;
    w.tabs.splice(idx, 1);
    if (idx < w.activeIdx) w.activeIdx -= 1;
    else if (idx === w.activeIdx) w.activeIdx = Math.min(idx, w.tabs.length - 1);
    render(); persist();
};

/**
 * Called by script.js after the space has changed and its file tree is loaded.
 * Returns the tab to reopen in the new space, or null to fall back to its start page.
 */
export const onSpaceChange = () => {
    restore();
    render();
    return resumeTarget();
};

// ── keyboard ─────────────────────────────────────────────────────────────────

const typingTarget = () => {
    const el = document.activeElement;
    const tag = el?.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || !!el?.isContentEditable;
};

const wireKeys = () => {
    document.addEventListener('keydown', (e) => {
        const w = ws();
        if (!w.tabs.length) return;
        // Ctrl+Alt+arrow works while typing (it cannot be confused with text input);
        // the bare Alt+digit shortcuts must not steal keys from the editor, whose own
        // Alt+1..3 insert headings.
        if (e.ctrlKey && e.altKey && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
            e.preventDefault();
            const step = e.key === 'ArrowRight' ? 1 : -1;
            activate((w.activeIdx + step + w.tabs.length) % w.tabs.length);
            return;
        }
        if (typingTarget() || e.ctrlKey || e.metaKey || e.shiftKey || !e.altKey) return;
        if (e.key >= '1' && e.key <= '9') {
            const idx = Number(e.key) - 1;
            if (idx < w.tabs.length) { e.preventDefault(); activate(idx); }
        } else if (e.key.toLowerCase() === 'w' && w.activeIdx >= 0) {
            e.preventDefault();
            closeAt(w.activeIdx);
        }
    });
};

// ── init ─────────────────────────────────────────────────────────────────────

export const init = () => {
    setTabHooks({ beforeLoad: snapshotOutgoing, afterLoad });
    restore();
    render();
    wireKeys();
    // The dirty marker tracks the active tab, so re-render whenever edit state moves.
    document.addEventListener('wiki:pagestate', () => {
        const tab = activeTab();
        // Typing in a peeked page makes it a real one.
        if (tab && tab.isPreview && state.hasUnsavedChanges) { tab.isPreview = false; persist(); }
        render();
    });
    window.addEventListener('wiki:languagechange', render);
    document.addEventListener('click', closeMenu);
    window.addEventListener('blur', closeMenu);
};
