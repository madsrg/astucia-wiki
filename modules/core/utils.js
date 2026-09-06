// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from './state.js';
// i18n imports only its locale files, so this is a leaf dependency, not a cycle.
import { t } from '../i18n/index.js';

let toastTimeout;

export const showToast = (message, type = 'info') => {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toast-message');
    toastMessage.textContent = message;
    toast.style.backgroundColor = type === 'error' ? '#c53030' : '#2d3748';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
    if (type === 'error') {
        const fd = new FormData();
        fd.append('message', message);
        fd.append('page', state.currentPagePath ?? '');
        fetch('api.php?action=log_client_error', { method: 'POST', body: fd }).catch(() => {});
    }
};

/**
 * A notification that stays until the reader dismisses it.
 *
 * Separate from showToast rather than an option on it: that one is a single element which
 * each new message overwrites after 3 s, which is right for "saved" and wrong for anything
 * you are meant to act on. These stack, in their own container above it, so a transient
 * toast firing underneath cannot cover one and several can be waiting at once.
 *
 * @param {object} o
 * @param {string} o.title    short bold line — the status
 * @param {string} o.body     optional detail, plain text
 * @param {string} o.type     'info' | 'success' | 'error' — colours the left edge
 * @param {string} o.linkText optional action label
 * @param {Function} o.onLink runs when the action is clicked; the toast closes first
 * @param {string} o.key      de-duplication key: re-showing an existing key replaces it
 * @returns {Function} close it programmatically
 */
export const showStickyToast = ({ title, body = '', type = 'info', linkText = '', onLink = null, key = '' } = {}) => {
    const stack = document.getElementById('toast-stack');
    if (!stack) return () => {};
    if (key) stack.querySelector(`[data-key="${CSS.escape(key)}"]`)?.remove();

    const el = document.createElement('div');
    el.className = `sticky-toast sticky-toast-${type}`;
    if (key) el.dataset.key = key;

    const main = document.createElement('div');
    main.className = 'sticky-toast-main';
    const titleEl = document.createElement('div');
    titleEl.className = 'sticky-toast-title';
    titleEl.textContent = title;
    main.appendChild(titleEl);
    if (body) {
        const bodyEl = document.createElement('div');
        bodyEl.className = 'sticky-toast-body';
        bodyEl.textContent = body;
        bodyEl.title = body;                 // the full text, since the line is clamped
        main.appendChild(bodyEl);
    }
    const close = () => el.remove();
    if (linkText && onLink) {
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'sticky-toast-link';
        link.textContent = linkText;
        link.addEventListener('click', () => { close(); onLink(); });
        main.appendChild(link);
    }
    el.appendChild(main);

    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'sticky-toast-close';
    x.setAttribute('aria-label', t('btn.close'));
    x.title = t('btn.close');
    x.innerHTML = '&times;';
    x.addEventListener('click', close);
    el.appendChild(x);

    // Newest on top. The stack is anchored at the bottom and grows upward, so adding at
    // the bottom instead would shove the existing ones up — moving the one the reader was
    // reaching for. Arriving above them leaves everything already on screen where it is.
    stack.prepend(el);
    return close;
};

/**
 * Whether this user may write content here at all: a reader cannot, and neither can anyone
 * into a frozen Space. The server enforces both — this is so the cursor says no before the
 * drop, and so an upload control that could never work is not offered.
 *
 * Lives here rather than in file_tree because files_folder needs it too, and file_tree
 * already imports files_folder.
 */
export const canUpload = () => window.WIKI_ROLE !== 'reader' && !state.spaceReadOnly;

export const confirmModal = (title, { message = '', messageHtml = '', confirmLabel = 'Confirm', cancelLabel = 'Cancel', dangerous = false, icon = '', hideCancel = false } = {}) => new Promise(resolve => {
    const overlay = document.getElementById('confirm-modal');
    const titleEl = document.getElementById('confirm-modal-title');
    const iconEl = document.getElementById('confirm-modal-icon');
    const messageEl = document.getElementById('confirm-modal-message');
    const okBtn = document.getElementById('confirm-modal-ok');
    const cancelBtn = document.getElementById('confirm-modal-cancel');

    titleEl.textContent = title;
    iconEl.innerHTML = icon;
    iconEl.classList.toggle('hidden', !icon);
    // messageHtml is trusted markup built by callers (values escaped there); plain
    // message stays text-only. messageHtml wins when both are provided.
    if (messageHtml) messageEl.innerHTML = messageHtml;
    else messageEl.textContent = message;
    messageEl.classList.toggle('hidden', !message && !messageHtml);
    okBtn.textContent = confirmLabel;
    okBtn.className = `btn ${dangerous ? 'btn-danger' : 'btn-blue'}`;
    cancelBtn.textContent = cancelLabel;
    cancelBtn.classList.toggle('hidden', hideCancel);
    overlay.classList.remove('hidden');
    setTimeout(() => (dangerous ? cancelBtn : okBtn).focus(), 50);

    const close = value => {
        overlay.classList.add('hidden');
        cancelBtn.classList.remove('hidden'); // restore for next caller
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        document.removeEventListener('keydown', onKeydown);
        resolve(value);
    };

    const onOk = () => close(true);
    const onCancel = () => close(false);
    const onKeydown = e => {
        if (e.key === 'Enter' && !dangerous) { e.preventDefault(); close(true); }
        if (e.key === 'Escape') close(false);
    };

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    // Delay to avoid auto-repeat keydown from the keystroke that triggered the modal
    setTimeout(() => document.addEventListener('keydown', onKeydown), 300);
});

export const promptModal = (title, defaultValue = '', placeholder = '', icon = '') => new Promise(resolve => {
    const overlay = document.getElementById('input-modal');
    const titleEl = document.getElementById('input-modal-title');
    const iconEl = document.getElementById('input-modal-icon');
    const input = document.getElementById('input-modal-input');
    const okBtn = document.getElementById('input-modal-ok');
    const cancelBtn = document.getElementById('input-modal-cancel');

    titleEl.textContent = title;
    iconEl.innerHTML = icon;
    iconEl.classList.toggle('hidden', !icon);
    input.value = defaultValue;
    input.placeholder = placeholder;
    overlay.classList.remove('hidden');
    setTimeout(() => { input.focus(); input.select(); }, 50);

    const close = value => {
        overlay.classList.add('hidden');
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        input.removeEventListener('keydown', onKeydown);
        resolve(value);
    };

    const onOk = () => { const v = input.value.trim(); close(v || null); };
    const onCancel = () => close(null);
    const onKeydown = e => {
        if (e.key === 'Enter') { e.preventDefault(); const v = input.value.trim(); close(v || null); }
        if (e.key === 'Escape') close(null);
    };

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    input.addEventListener('keydown', onKeydown);
});

/**
 * Wrap the mentions in a chunk of HTML for display.
 *
 * Two sigils: `@Name` addresses a person, `#Name` addresses an AI user. Both render
 * the same way — the distinction is which type-ahead offers which list, and chats
 * written before the split (where everyone was `#Name`) must keep rendering as
 * mentions rather than going quietly plain.
 *
 * Order matters in the pattern. The entity branch is first so marked's numeric
 * entities — `&#39;` for an apostrophe — are not mangled into a "#39" mention. `@`
 * requires a boundary in front of it, so `someone@example.com` does not light up as
 * a mention of its domain; `#` needs no such guard because marked has already turned
 * every heading into a tag by the time this runs.
 *
 * Input must already be HTML (escaped text or marked's output) — this never escapes.
 */
const MENTION_RE = /(&#?\w+;)|(^|[\s(>])@([\w.-]*\w)|#([\w.-]*\w)/g;

export const highlightMentions = (html) =>
    String(html ?? '').replace(MENTION_RE, (match, entity, before, person, ai) => {
        if (entity !== undefined) return match;
        if (person !== undefined) return `${before}<span class="chat-mention">@${person}</span>`;
        return `<span class="chat-mention">#${ai}</span>`;
    });
