import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, promptModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { renameSpaceInStorage, setAvailableSpaces } from '../nav/index.js';

const STORAGE_KEY = 'wiki_currentSpace';
let _onSpaceChange = null;
let _allSpaces = [];

export const getAllSpaces = () => _allSpaces;

// ── Public API ────────────────────────────────────────────────────────────────

export const initSpaces = async ({ onSpaceChange }) => {
    _onSpaceChange = onSpaceChange;

    const result = await api.call('list_spaces');
    const spaces = result.data || [];
    _allSpaces = spaces;
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
};

const _markActive = (name) => {
    document.querySelectorAll('.space-dropdown-item[data-space]').forEach(el => {
        el.classList.toggle('active', el.dataset.space === name);
    });
};

// ── Render ────────────────────────────────────────────────────────────────────

const _render = (spaces, active) => {
    const container = document.getElementById('space-switcher');
    if (!container) return;

    const role = window.WIKI_ROLE || '';
    const canCreate = role === 'admin' || role === 'editor';

    // Header: icon + current name + chevron
    const header = document.createElement('div');
    header.className = 'space-switcher-header';
    header.innerHTML = `
        <span class="space-switcher-icon">${icons.space}</span>
        <span id="space-current-label" class="space-current-label">${active || t('spaces.empty')}</span>
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
        label.addEventListener('click', () => {
            dropdown.classList.add('hidden');
            switchSpace(name, spaces);
        });
        item.appendChild(label);

        if (canCreate) {
            const renameBtn = document.createElement('button');
            renameBtn.className = 'space-item-rename-btn';
            renameBtn.title = t('spaces.rename-btn');
            renameBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
            renameBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                dropdown.classList.add('hidden');
                const { confirmModal } = await import('../core/utils.js');
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
                    renameSpaceInStorage(name, newName);
                    const refreshed = await api.call('list_spaces');
                    const newSpaces = refreshed.data || [];
                    container.innerHTML = '';
                    const nextActive = name === active ? newName : active;
                    _render(newSpaces, nextActive);
                    if (name === active) {
                        state.currentSpace = newName;
                        localStorage.setItem(STORAGE_KEY, newName);
                        _updateUrl(newName);
                        if (_onSpaceChange) await _onSpaceChange(newName);
                    }
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
                const refreshed = await api.call('list_spaces');
                const newSpaces = refreshed.data || [];
                container.innerHTML = '';
                _render(newSpaces, name);
                await switchSpace(name, newSpaces);
            } else {
                showToast(res.message || t('spaces.failed'), 'error');
            }
        });
        dropdown.appendChild(newBtn);
    }

    // Toggle dropdown on header click
    header.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    container.appendChild(header);
    container.appendChild(dropdown);

    // Close dropdown when clicking elsewhere (attached once on document)
    if (!container.dataset.listenerAttached) {
        container.dataset.listenerAttached = '1';
        document.addEventListener('click', () => {
            document.getElementById('space-dropdown')?.classList.add('hidden');
        });
    }
};
