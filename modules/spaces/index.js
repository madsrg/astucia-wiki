// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, promptModal, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { renameSpaceInStorage, setAvailableSpaces } from '../nav/index.js';
import { openSpaceSettings } from './settings.js';

const STORAGE_KEY = 'wiki_currentSpace';
let _onSpaceChange = null;
let _allSpaces = [];
// Names of the spaces that are frozen. Read from list_spaces, which every client
// calls on load — a reader has to know too, since the flag hides the edit UI.
let _readOnly = new Set();

export const getAllSpaces = () => _allSpaces;
export const isSpaceReadOnly = (name) => _readOnly.has(name);

/**
 * Reflect the active space's read-only flag in `state` and on <body>.
 *
 * Role-based hiding is decided by PHP at page render (`body class="role-reader"`),
 * but a space switch never reloads the page — so the frozen-space equivalent has to
 * be a class this function keeps in step. styles.css hides the same controls for
 * `.space-readonly` as it does for `.role-reader`.
 */
const _applyReadOnly = () => {
    state.spaceReadOnly = !!state.currentSpace && _readOnly.has(state.currentSpace);
    document.body.classList.toggle('space-readonly', state.spaceReadOnly);
};

// ── Public API ────────────────────────────────────────────────────────────────

export const initSpaces = async ({ onSpaceChange }) => {
    _onSpaceChange = onSpaceChange;

    const result = await api.call('list_spaces');
    const spaces = result.data || [];
    _allSpaces = spaces;
    _readOnly  = new Set(result.readonly || []);
    setAvailableSpaces(spaces);

    // Determine active space: URL param → localStorage → first available
    const urlParams = new URLSearchParams(window.location.search);
    const fromUrl   = urlParams.get('space');
    const fromStore = localStorage.getItem(STORAGE_KEY);
    let active = fromUrl || fromStore || spaces[0] || null;
    if (active && !spaces.includes(active)) active = spaces[0] || null;

    state.currentSpace = active;
    _render(spaces, active);
    _updateUrl(active);
    _applyReadOnly();

    return { spaces, activeSpace: active };
};

// ── Space switch ──────────────────────────────────────────────────────────────

const switchSpace = async (name, spaces) => {
    if (name === state.currentSpace) return;
    state.currentSpace = name;
    localStorage.setItem(STORAGE_KEY, name);
    _updateUrl(name);
    _updateLabel(name);
    _markActive(name);
    _applyReadOnly();
    if (_onSpaceChange) await _onSpaceChange(name);
};

// Silent version: updates state/UI without triggering onSpaceChange (used when
// navigating directly to a cross-space page without wanting the start page to load).
export const switchSpaceSilently = (name) => {
    if (!name || name === state.currentSpace) return;
    state.currentSpace = name;
    localStorage.setItem(STORAGE_KEY, name);
    _updateUrl(name);
    _updateLabel(name);
    _markActive(name);
    _applyReadOnly();
};

// ── URL sync ──────────────────────────────────────────────────────────────────

const _updateUrl = (name) => {
    const url = new URL(window.location.href);
    if (name) url.searchParams.set('space', name);
    else url.searchParams.delete('space');
    url.searchParams.delete('pageid');
    window.history.replaceState({}, '', url.toString());
};

// ── DOM helpers ───────────────────────────────────────────────────────────────

const _updateLabel = (name) => {
    const el = document.getElementById('space-current-label');
    if (el) el.textContent = name || t('spaces.empty');
    const lock = document.getElementById('space-current-lock');
    if (lock) lock.classList.toggle('hidden', !name || !_readOnly.has(name));
    // The collapsed rail hides the label, so the tooltip is the name — and switching
    // spaces does not re-render the switcher, only relabel it.
    const header = document.querySelector('.space-switcher-header');
    if (header) header.title = name || t('spaces.empty');
};

const _markActive = (name) => {
    document.querySelectorAll('.space-dropdown-item[data-space]').forEach(el => {
        el.classList.toggle('active', el.dataset.space === name);
    });
};

// Re-read the space list from the server and repaint the switcher. Used after every
// change that can add, remove, rename or freeze a space.
const _reload = async (activeName) => {
    const refreshed = await api.call('list_spaces');
    _allSpaces = refreshed.data || [];
    _readOnly  = new Set(refreshed.readonly || []);
    setAvailableSpaces(_allSpaces);
    const container = document.getElementById('space-switcher');
    if (container) {
        container.innerHTML = '';
        _render(_allSpaces, activeName);
    }
    _applyReadOnly();
    return _allSpaces;
};

// Shared by the per-row rename pencil and the Space settings dialog.
const _afterRename = async (oldName, newName) => {
    renameSpaceInStorage(oldName, newName);
    const wasActive = oldName === state.currentSpace;
    if (wasActive) {
        state.currentSpace = newName;
        localStorage.setItem(STORAGE_KEY, newName);
        _updateUrl(newName);
    }
    await _reload(state.currentSpace);
    if (wasActive && _onSpaceChange) await _onSpaceChange(newName);
};

// The source space no longer exists: its content, and anyone looking at it, moves on.
const _afterMerge = async (source, target) => {
    // Recents and favourites are repointed rather than dropped. A page the merge had
    // to rename ("todo (1).md") will 404 when clicked, which is visible and fixable;
    // silently deleting someone's favourites would not be.
    renameSpaceInStorage(source, target);
    try { localStorage.removeItem(`wikiTabs:${source}`); } catch {}
    const wasActive = source === state.currentSpace;
    if (wasActive) {
        state.currentSpace = target;
        localStorage.setItem(STORAGE_KEY, target);
        _updateUrl(target);
    }
    await _reload(state.currentSpace);
    if (wasActive && _onSpaceChange) await _onSpaceChange(target);
};

// ── Render ────────────────────────────────────────────────────────────────────

const _render = (spaces, active) => {
    const container = document.getElementById('space-switcher');
    if (!container) return;

    const role = window.WIKI_ROLE || '';
    const canCreate = role === 'admin' || role === 'editor';
    const isAdmin   = role === 'admin';

    // Header: icon + current name + chevron
    const header = document.createElement('div');
    header.className = 'space-switcher-header';
    header.innerHTML = `
        <span class="space-switcher-icon">${icons.space}</span>
        <span id="space-current-label" class="space-current-label">${active || t('spaces.empty')}</span>
        <span id="space-current-lock" class="space-lock${active && _readOnly.has(active) ? '' : ' hidden'}" title="${t('spaces.settings.readonly-label')}">${icons.lock}</span>
        <svg class="space-chevron" xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    `;

    // Dropdown
    const dropdown = document.createElement('div');
    dropdown.id = 'space-dropdown';
    dropdown.className = 'space-dropdown hidden';

    spaces.forEach(name => {
        const item = document.createElement('div');
        item.className = 'space-dropdown-item' + (name === active ? ' active' : '');
        item.dataset.space = name;

        const label = document.createElement('span');
        label.className = 'space-item-label';
        label.textContent = name;
        item.appendChild(label);

        if (_readOnly.has(name)) {
            const lock = document.createElement('span');
            lock.className = 'space-lock';
            lock.title = t('spaces.settings.readonly-label');
            lock.innerHTML = icons.lock;
            item.appendChild(lock);
        }

        // Switch on a click anywhere in the row, not just on the label text — the
        // row's vertical padding isn't covered by the label, so a label-only
        // handler silently ignored clicks near a row's top/bottom edge. The
        // rename button stops propagation, so it won't trigger a switch.
        item.addEventListener('click', () => {
            dropdown.classList.add('hidden');
            switchSpace(name, spaces);
        });

        if (canCreate) {
            const renameBtn = document.createElement('button');
            renameBtn.className = 'space-item-rename-btn';
            renameBtn.title = t('spaces.rename-btn');
            renameBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
            renameBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                dropdown.classList.add('hidden');
                const ok = await confirmModal(t('spaces.rename-warn'), {
                    confirmLabel: t('spaces.rename-confirm-btn'),
                    icon: icons.space,
                });
                if (!ok) return;
                const newName = await promptModal(t('spaces.rename-prompt'), name, '', icons.space);
                if (!newName || newName === name) return;
                const res = await api.call('rename_space', { old_name: name, new_name: newName }, 'POST');
                if (res.success) {
                    showToast(t('spaces.renamed', { name: newName }), 'success');
                    await _afterRename(name, newName);
                } else {
                    showToast(res.message || t('spaces.rename-failed'), 'error');
                }
            });
            item.appendChild(renameBtn);
        }

        dropdown.appendChild(item);
    });

    if (!spaces.length) {
        const empty = document.createElement('div');
        empty.className = 'space-dropdown-empty';
        empty.textContent = t('spaces.none');
        dropdown.appendChild(empty);
    }

    if (canCreate) {
        if (spaces.length) {
            const sep = document.createElement('div');
            sep.className = 'space-dropdown-sep';
            dropdown.appendChild(sep);
        }
        const newBtn = document.createElement('div');
        newBtn.className = 'space-dropdown-item space-dropdown-new';
        newBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> ${t('spaces.new-btn')}`;
        newBtn.addEventListener('click', async () => {
            dropdown.classList.add('hidden');
            const name = await promptModal(t('spaces.prompt'), '', t('spaces.ph'), icons.space);
            if (!name) return;
            const res = await api.call('create_space', { name }, 'POST');
            if (res.success) {
                showToast(t('spaces.created', { name }), 'success');
                const newSpaces = await _reload(name);
                await switchSpace(name, newSpaces);
            } else {
                showToast(res.message || t('spaces.failed'), 'error');
            }
        });
        dropdown.appendChild(newBtn);
    }

    // The label is hidden in the collapsed rail, so the tooltip is the only thing left
    // that says which space you are in.
    header.title = active || t('spaces.empty');

    // Toggle dropdown on header click
    header.addEventListener('click', (e) => {
        e.stopPropagation();
        const opening = dropdown.classList.contains('hidden');
        dropdown.classList.toggle('hidden');
        if (opening) _positionDropdown(header, dropdown);
    });

    container.appendChild(header);

    // Space settings — administrators only. Renaming, freezing and dissolving a
    // space are wiki-shaping operations, so they sit behind their own control
    // rather than in the switcher rows.
    if (isAdmin) {
        const gear = document.createElement('button');
        gear.id = 'space-settings-btn';
        gear.className = 'space-settings-btn';
        gear.title = t('spaces.settings.btn');
        gear.innerHTML = icons.cog;
        gear.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.add('hidden');
            if (!state.currentSpace) return;
            openSpaceSettings(state.currentSpace, {
                onRenamed: (oldName, newName) => _afterRename(oldName, newName),
                onMerged:  (source, target)   => _afterMerge(source, target),
                onReadOnly: async (name, readonly) => {
                    if (readonly) _readOnly.add(name); else _readOnly.delete(name);
                    await _reload(state.currentSpace);
                },
            });
        });
        header.appendChild(gear);
    }

    container.appendChild(dropdown);

    // Close dropdown when clicking elsewhere (attached once on document)
    if (!container.dataset.listenerAttached) {
        container.dataset.listenerAttached = '1';
        document.addEventListener('click', () => {
            const d = document.getElementById('space-dropdown');
            if (!d) return;
            d.classList.add('hidden');
            _clearDropdownPos(d);   // any close drops the rail coordinates
        });
    }
};

// In the collapsed rail the switcher is one icon in a 48px column that clips, so the
// dropdown cannot open inside it. CSS switches it to `fixed`; the coordinates have to come
// from here because the switcher's height off the bottom depends on how many icons the
// user's role puts below it.
const _clearDropdownPos = (d) => { d.style.left = d.style.top = d.style.bottom = ''; };

const _positionDropdown = (header, dropdown) => {
    if (!document.querySelector('.app-container')?.classList.contains('sidebar-collapsed')) {
        _clearDropdownPos(dropdown);
        return;
    }
    // Clear of the *rail*, not of the header: the header is a centred 29px icon inside a
    // wider column, so anchoring to it puts the dropdown back on top of the sidebar.
    const rail = document.querySelector('.sidebar').getBoundingClientRect();
    const r    = header.getBoundingClientRect();
    dropdown.style.left   = `${Math.round(rail.right + 8)}px`;
    dropdown.style.bottom = `${Math.round(window.innerHeight - r.bottom)}px`;
    dropdown.style.top    = 'auto';
};
