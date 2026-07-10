// Chat "focus" mode — stay in the context of talking to one AI user without
// re-mentioning it in every message. Focus is per-chat and remembered across
// reloads. It reuses the existing #mention routing: when focused, a plain
// message is silently prefixed with `#<AiName> ` before it's posted, so the
// backend AI-mention detection, waiting modal and status polling all work
// unchanged.
import { t } from '../i18n/index.js';

const LS_KEY = 'chatFocusAi';

const _load = () => {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '{}'); }
    catch { return {}; }
};

const _save = (map) => {
    try { localStorage.setItem(LS_KEY, JSON.stringify(map)); } catch { /* ignore quota */ }
};

// The AI user name this chat is focused on, or null.
export const getFocusAi = (chatPath) => (chatPath && _load()[chatPath]) || null;

// Set (or, with a falsy name, clear) the focused AI for a chat.
export const setFocusAi = (chatPath, name) => {
    if (!chatPath) return;
    const map = _load();
    if (name) map[chatPath] = name;
    else delete map[chatPath];
    _save(map);
};

// Decide who should handle a reply and rewrite the outgoing text accordingly.
// An explicit AI mention always wins; otherwise, if the chat is focused on an
// AI, prefix the message with that mention. Slash commands are left untouched.
// Returns { text, mentionedAi }.
export const applyFocus = (text, { chatPath, aiUsers, mentionedAi }) => {
    if (mentionedAi) return { text, mentionedAi };
    if (text.startsWith('/')) return { text, mentionedAi: null };
    const focusName = getFocusAi(chatPath);
    if (!focusName) return { text, mentionedAi: null };
    const ai = aiUsers.find(u => u.name.toLowerCase() === focusName.toLowerCase());
    if (!ai) return { text, mentionedAi: null };
    return { text: '#' + ai.name + ' ' + text, mentionedAi: ai };
};

// Build the focus chip and insert it as its own row directly above the input
// area. Returns a controller whose update(name) shows/hides the chip and swaps
// the input placeholder. `onExit` fires when the user dismisses the chip.
export const createFocusChip = (inputAreaEl, textarea, { onExit }) => {
    const originalPlaceholder = textarea ? textarea.placeholder : '';

    const chip = document.createElement('div');
    chip.className = 'chat-focus-chip hidden';

    const label = document.createElement('span');
    label.className = 'chat-focus-chip-label';

    const closeBtn = document.createElement('button');
    closeBtn.className = 'chat-focus-chip-close';
    closeBtn.type = 'button';
    closeBtn.innerHTML = '&times;';
    closeBtn.title = t('chat.focus.exit');
    closeBtn.addEventListener('click', () => onExit());

    chip.append(label, closeBtn);
    inputAreaEl.parentNode.insertBefore(chip, inputAreaEl);

    return {
        update(name) {
            if (name) {
                label.textContent = t('chat.focus.active', { name });
                chip.classList.remove('hidden');
                if (textarea) textarea.placeholder = t('chat.focus.placeholder', { name });
            } else {
                chip.classList.add('hidden');
                if (textarea) textarea.placeholder = originalPlaceholder;
            }
        },
    };
};
