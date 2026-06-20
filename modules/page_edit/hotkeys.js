import { state } from '../core/state.js';
import { insertMarkdown, insertHeading } from './editor.js';
import { savePage } from './index.js';
import { openSearchReplace } from './search.js';
import { openLinkLightbox } from './link_lightbox.js';

export const loadHotkeys = async () => {
    try {
        const response = await fetch('hotkeys.json');
        state.hotkeys = await response.json();
    } catch (error) {
        console.error('Could not load hotkeys.json', error);
    }
};

const handleLightboxHotkey = (e) => {
    e.preventDefault();
    const key = e.key.toLowerCase();

    const actions = {
        's': () => savePage(),
        'f': () => openSearchReplace(),
        'l': () => { state.linkInsertionMode = 'link'; openLinkLightbox(); },
        'p': () => { state.linkInsertionMode = 'include'; openLinkLightbox(); },
        '1': () => insertHeading(1),
        '2': () => insertHeading(2),
        '3': () => insertHeading(3),
        'b': () => insertMarkdown('**', '**'),
        'i': () => insertMarkdown('*', '*'),
        'c': () => insertMarkdown('```\n', '\n```'),
        'n': () => insertMarkdown('{filename}'),
        't': () => insertMarkdown(state.hotkeys['alt+t']?.prefix || ''),
        'k': () => insertMarkdown(state.hotkeys['alt+k']?.prefix || '', state.hotkeys['alt+k']?.suffix || ''),
    };

    if (actions[key]) {
        actions[key]();
        closeHotkeyLightbox();
    }
};

const openHotkeyLightbox = () => {
    const hotkeyList = document.getElementById('hotkey-list');
    hotkeyList.innerHTML = '';
    const actionLabels = {
        'S': 'Save Page', 'F': 'Find & Replace', 'L': 'Insert Link',
        'P': 'Include Page', 'N': 'Include Filename', '1': 'Heading 1',
        '2': 'Heading 2', '3': 'Heading 3', 'B': 'Bold', 'I': 'Italic',
        'C': 'Code Block', 'T': 'Insert Table', 'K': 'Comment',
    };
    for (const [key, action] of Object.entries(actionLabels)) {
        hotkeyList.innerHTML += `
            <a href="#" class="hotkey-action" data-key="${key.toLowerCase()}">
                <kbd>${key}</kbd>
                <span>${action}</span>
            </a>
        `;
    }
    document.getElementById('hotkey-lightbox').classList.remove('hidden');
    document.addEventListener('keydown', handleLightboxHotkey);
};

const closeHotkeyLightbox = () => {
    const lb = document.getElementById('hotkey-lightbox');
    if (!lb.classList.contains('hidden')) {
        lb.classList.add('hidden');
        document.removeEventListener('keydown', handleLightboxHotkey);
    }
};

export const init = () => {
    const hotkeyLightbox = document.getElementById('hotkey-lightbox');
    const hotkeyLightboxCloseBtn = document.getElementById('hotkey-lightbox-close-btn');
    const hotkeyList = document.getElementById('hotkey-list');

    hotkeyLightboxCloseBtn.addEventListener('click', closeHotkeyLightbox);
    hotkeyLightbox.addEventListener('click', (e) => {
        if (e.target === hotkeyLightbox) closeHotkeyLightbox();
    });

    hotkeyList.addEventListener('click', (e) => {
        const actionLink = e.target.closest('.hotkey-action');
        if (actionLink) {
            e.preventDefault();
            handleLightboxHotkey(new KeyboardEvent('keydown', { key: actionLink.dataset.key }));
        }
    });

    // Global keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (!hotkeyLightbox.classList.contains('hidden')) return;

        const activeTag = document.activeElement.tagName;

        if (activeTag === 'TEXTAREA') {
            if (!state.isEditing) return;
            const key = e.key.toLowerCase();

            if (e.altKey) {
                if (key === 's') { e.preventDefault(); if (!document.getElementById('save-btn').disabled) savePage(); return; }
                if (key === 'l') { e.preventDefault(); state.linkInsertionMode = 'link'; openLinkLightbox(); return; }
                if (key === 'p') { e.preventDefault(); state.linkInsertionMode = 'include'; openLinkLightbox(); return; }
                if (key === 'f') { e.preventDefault(); openSearchReplace(); return; }
                if (key === 'a') { e.preventDefault(); openHotkeyLightbox(); return; }

                if (key >= '1' && key <= '3' && state.hotkeys[`alt+${key}`]) {
                    e.preventDefault(); insertHeading(parseInt(key, 10));
                } else {
                    const hotkey = state.hotkeys[`alt+${key}`];
                    if (hotkey) { e.preventDefault(); insertMarkdown(hotkey.prefix, hotkey.suffix); }
                }
            }
        } else if (activeTag !== 'INPUT') {
            if (!state.isEditing && state.currentPagePath && e.key.toLowerCase() === 'e') {
                e.preventDefault();
                const { setEditingMode } = import('../page_edit/index.js');
                // Dynamic import to avoid circular — use event for simplicity
                document.getElementById('edit-btn').click();
            }
        }
    });
};
