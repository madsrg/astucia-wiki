/**
 * nav/index.js — Breadcrumbs, Recently Visited, and Favorites (starred pages).
 *
 * Exports:
 *   updateBreadcrumb(path, space)   — renders breadcrumb nav above page title
 *   trackPageVisit(id, path, space) — adds to recents, re-renders recent pane
 *   updateFavoriteBtn(id)           — updates star filled/unfilled state
 *   init({ onNavigate })            — wires favorite button, renders initial pane state
 */

import { state } from '../core/state.js';
import { t } from '../i18n/index.js';
import { icons } from '../core/icons.js';

const RECENTS_KEY   = 'wiki_recents';
const FAVORITES_KEY = 'wiki_favorites';
const MAX_RECENTS   = 20;

// ── Helpers ──────────────────────────────────────────────────────────────────

const iconForPath = (path) => {
    if (!path) return icons.file;
    if (path.endsWith('.drawio')) return icons.diagram;
    if (path.endsWith('.list'))   return icons.list;
    if (path.endsWith('.chat'))   return icons.chat;
    return icons.file;
};

/**
 * Given a page path like "SpaceName/Folder/Subfolder/PageName.md",
 * strips the folder prefix and extension to return just "PageName".
 */
const pageTitle = (path) => {
    const basename = path.split('/').pop() || path;
    return basename.replace(/\.(md|drawio|list|chat)$/, '');
};

const loadRecents = () => {
    try { return JSON.parse(localStorage.getItem(RECENTS_KEY) || '[]'); } catch { return []; }
};

const saveRecents = (items) => {
    localStorage.setItem(RECENTS_KEY, JSON.stringify(items));
};

const loadFavorites = () => {
    try { return JSON.parse(localStorage.getItem(FAVORITES_KEY) || '[]'); } catch { return []; }
};

const saveFavorites = (items) => {
    localStorage.setItem(FAVORITES_KEY, JSON.stringify(items));
};

// ── Callbacks (set by init) ───────────────────────────────────────────────────
let _onNavigate = null;
let _onRoot     = null;

// ── Breadcrumb ────────────────────────────────────────────────────────────────

/**
 * Renders a breadcrumb nav above the page title.
 * Format: SpaceName / Root / Folder / Subfolder
 * Root navigates to the start page; folder segments switch the browse pane.
 */
export const updateBreadcrumb = (path, space) => {
    const nav = document.getElementById('page-breadcrumb');
    if (!nav) return;

    // Path segments without the last element (the page file itself)
    const segments = path ? path.split('/') : [];
    segments.pop(); // remove the filename

    nav.innerHTML = '';

    if (!space && segments.length === 0) {
        nav.classList.add('hidden');
        return;
    }

    nav.classList.remove('hidden');

    // Space name (non-clickable label)
    if (space) {
        const spaceEl = document.createElement('span');
        spaceEl.className = 'breadcrumb-space';
        spaceEl.textContent = space;
        nav.appendChild(spaceEl);

        // Root — always shown after space, navigates to start page
        const rootSep = document.createElement('span');
        rootSep.className = 'breadcrumb-sep';
        rootSep.textContent = ' / ';
        nav.appendChild(rootSep);

        const rootBtn = document.createElement('button');
        rootBtn.className = 'breadcrumb-seg';
        rootBtn.textContent = t('nav.breadcrumb-root');
        rootBtn.title = t('nav.breadcrumb-root');
        rootBtn.addEventListener('click', () => { if (_onRoot) _onRoot(); });
        nav.appendChild(rootBtn);
    }

    // Folder segments (clickable)
    segments.forEach((seg, idx) => {
        const sep = document.createElement('span');
        sep.className = 'breadcrumb-sep';
        sep.textContent = ' / ';
        nav.appendChild(sep);

        const folderPath = segments.slice(0, idx + 1).join('/');

        const btn = document.createElement('button');
        btn.className = 'breadcrumb-seg';
        btn.textContent = seg;
        btn.title = folderPath;
        btn.addEventListener('click', async () => {
            // Dynamic import to avoid circular dependency (file_tree imports page_view)
            const { renderBrowsePane, findItemsByPath } = await import('../file_tree/index.js');
            renderBrowsePane(findItemsByPath(folderPath), folderPath);
            const pagesTab = document.querySelector('.pane-tab[data-pane="pages"]');
            if (pagesTab) {
                if (!pagesTab.classList.contains('active')) pagesTab.click();
                if (pagesTab.dataset.nav !== 'folder') pagesTab.click();
            }
        });
        nav.appendChild(btn);
    });
};

// ── Recents ───────────────────────────────────────────────────────────────────

/**
 * Adds a page to the recents list (deduplicated by id, most-recent first).
 * Re-renders the recent pane.
 */
export const trackPageVisit = (id, path, space) => {
    if (!id || !path) return;
    const title = pageTitle(path);
    let recents = loadRecents();
    // Remove existing entry for this id
    recents = recents.filter(r => r.id !== id);
    // Prepend new entry
    recents.unshift({ id, path, space: space || '', title });
    // Trim to max
    if (recents.length > MAX_RECENTS) recents = recents.slice(0, MAX_RECENTS);
    saveRecents(recents);
    renderRecentPane();
};

const renderRecentPane = () => {
    const pane = document.getElementById('recent-pane');
    if (!pane) return;
    const recents = loadRecents();
    if (recents.length === 0) {
        pane.innerHTML = `<p class="nav-pane-empty">${t('nav.recent-empty')}</p>`;
        return;
    }
    const ul = document.createElement('ul');
    ul.className = 'nav-pane-list';
    recents.forEach(entry => {
        const li = document.createElement('li');
        li.className = 'nav-pane-item';
        li.title = entry.path;

        const iconEl = document.createElement('span');
        iconEl.className = 'nav-pane-item-icon';
        iconEl.innerHTML = iconForPath(entry.path);
        li.appendChild(iconEl);

        const nameEl = document.createElement('span');
        nameEl.className = 'nav-pane-item-name';
        nameEl.textContent = entry.title;
        li.appendChild(nameEl);

        // Show space badge when in a different space
        if (entry.space && entry.space !== state.currentSpace) {
            const badge = document.createElement('span');
            badge.className = 'nav-pane-item-space';
            badge.textContent = entry.space;
            li.appendChild(badge);
        }

        li.addEventListener('click', () => {
            if (_onNavigate) _onNavigate(entry.id, entry.space);
        });

        ul.appendChild(li);
    });
    pane.innerHTML = '';
    pane.appendChild(ul);
};

// ── Favorites ─────────────────────────────────────────────────────────────────

/**
 * Updates the star button filled/unfilled state based on whether the current
 * page is in favorites.
 */
export const updateFavoriteBtn = (id) => {
    const btn = document.getElementById('favorite-btn');
    if (!btn) return;

    if (!id) {
        btn.classList.add('hidden');
        return;
    }

    btn.classList.remove('hidden');
    const favorites = loadFavorites();
    const isFaved = favorites.some(f => f.id === id);

    if (isFaved) {
        btn.classList.add('favorited');
        btn.title = t('nav.fav-remove');
    } else {
        btn.classList.remove('favorited');
        btn.title = t('nav.fav-add');
    }
};

const toggleFavorite = () => {
    const id = state.currentPageId;
    if (!id) return;
    const path  = state.currentPagePath;
    const space = state.currentSpace || '';
    const title = pageTitle(path || '');

    let favorites = loadFavorites();
    const idx = favorites.findIndex(f => f.id === id);
    if (idx >= 0) {
        // Remove
        favorites.splice(idx, 1);
    } else {
        // Add
        favorites.push({ id, path, space, title });
    }
    saveFavorites(favorites);
    updateFavoriteBtn(id);
    renderSavedPane();
};

const renderSavedPane = () => {
    const pane = document.getElementById('saved-pane');
    if (!pane) return;
    const favorites = loadFavorites();
    if (favorites.length === 0) {
        pane.innerHTML = `<p class="nav-pane-empty">${t('nav.fav-empty')}</p>`;
        return;
    }
    const ul = document.createElement('ul');
    ul.className = 'nav-pane-list';
    favorites.forEach(entry => {
        const li = document.createElement('li');
        li.className = 'nav-pane-item';
        li.title = entry.path;

        const iconEl = document.createElement('span');
        iconEl.className = 'nav-pane-item-icon';
        iconEl.innerHTML = iconForPath(entry.path);
        li.appendChild(iconEl);

        const nameEl = document.createElement('span');
        nameEl.className = 'nav-pane-item-name';
        nameEl.textContent = entry.title;
        li.appendChild(nameEl);

        // Show space badge when different space
        if (entry.space && entry.space !== state.currentSpace) {
            const badge = document.createElement('span');
            badge.className = 'nav-pane-item-space';
            badge.textContent = entry.space;
            li.appendChild(badge);
        }

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.className = 'nav-pane-remove-btn';
        removeBtn.title = t('nav.fav-remove');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            let favs = loadFavorites();
            favs = favs.filter(f => f.id !== entry.id);
            saveFavorites(favs);
            // If the removed entry is the currently shown page, update btn state
            if (entry.id === state.currentPageId) {
                updateFavoriteBtn(state.currentPageId);
            }
            renderSavedPane();
        });
        li.appendChild(removeBtn);

        li.addEventListener('click', () => {
            if (_onNavigate) _onNavigate(entry.id, entry.space);
        });

        ul.appendChild(li);
    });
    pane.innerHTML = '';
    pane.appendChild(ul);
};

// ── Init ──────────────────────────────────────────────────────────────────────

export const init = ({ onNavigate, onRoot } = {}) => {
    _onNavigate = onNavigate || null;
    _onRoot     = onRoot     || null;

    // Wire favorite button
    const favBtn = document.getElementById('favorite-btn');
    if (favBtn) {
        favBtn.addEventListener('click', toggleFavorite);
    }

    // Render initial pane state
    renderRecentPane();
    renderSavedPane();
};
