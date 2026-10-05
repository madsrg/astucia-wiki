// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// A user's avatar: one of the icons vendored in /avatars (see avatars/README.md). An admin
// chooses it for an AI user (Admin → AI Users); a person chooses their own (My
// Preferences). Unset means what every bubble showed before this existed — the android
// glyph for an AI, the initial for a person, on the uid's colour.
//
// Both chat views draw their bubbles synchronously, so the lookup reads the user cache
// rather than awaiting it; the same cache already decides which uids are AI at all, so
// the two are never out of step with each other.
import { icons } from './icons.js';
import { peekUsers } from './users.js';
import { t } from '../i18n/index.js';

export const avatarSrc = (id) => `avatars/${encodeURIComponent(id)}.svg`;

/** The avatar id for this uid, or '' for "none chosen". */
export const avatarFor = (uid) => (peekUsers().find(u => u.uid === uid)?.avatar) || '';

/**
 * Fill an avatar circle. `colour` is the fallback disc: an icon brings its own colours,
 * and drawing it on a saturated disc fights them. The uid and initial are kept on the
 * element so repaintAvatars() can redraw it later without the message in hand.
 */
export const paintAvatar = (el, uid, { isAi = false, colour = '', initial = '?' } = {}) => {
    el.dataset.uid = String(uid);
    el.dataset.initial = initial;
    el.classList.toggle('chat-avatar-ai', isAi);
    const id = avatarFor(uid);
    if (id) {
        el.classList.add('chat-avatar-img');
        el.style.background = '';
        el.innerHTML = `<img src="${avatarSrc(id)}" alt="" draggable="false">`;
        return;
    }
    el.classList.remove('chat-avatar-img');
    el.style.background = colour;
    if (isAi) el.innerHTML = icons.robot;
    else      el.textContent = initial;
};

/**
 * Bring the avatars already drawn under `root` up to date with the user cache. Called on
 * `wiki:users`, so a changed avatar shows without re-rendering the thread — which would
 * lose the reader's scroll position and re-run every bubble's Markdown for one icon.
 */
export const repaintAvatars = (root, isAi, colourFor) => {
    root?.querySelectorAll('.chat-avatar[data-uid]').forEach(el => {
        const uid = Number(el.dataset.uid);
        paintAvatar(el, uid, { isAi: isAi(uid), colour: colourFor(uid), initial: el.dataset.initial || '?' });
    });
};

// ── The picker ────────────────────────────────────────────────────────────────
//
// Shared by the AI user form and My Preferences: a preview circle, a button, and a grid of
// every option that opens under them. The choice lives in a hidden input `<prefix>-avatar`,
// which is what each form's save reads. `fallback` is what "none" looks like for this kind
// of user — the android for an AI, the person's initial otherwise.

const escAttr = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

const previewHtml = (id, fallback) => id ? `<img src="${avatarSrc(id)}" alt="">` : fallback;

export const avatarCircleHtml = (id, fallback) => `<span class="avatar-circle">${previewHtml(id, fallback)}</span>`;

export const avatarPickerHtml = (prefix, current, ids, { fallback, noneLabel }) => {
    const cur = current || '';
    return `
        <div class="avatar-field">
            <button type="button" id="${prefix}-avatar-btn" class="avatar-circle avatar-circle-lg" title="${escAttr(t('avatar.choose'))}">${previewHtml(cur, fallback)}</button>
            <input type="hidden" id="${prefix}-avatar" value="${escAttr(cur)}">
            <button type="button" id="${prefix}-avatar-toggle" class="btn btn-sm btn-secondary">${t('avatar.choose')}</button>
        </div>
        <div id="${prefix}-avatar-grid" class="avatar-grid hidden" role="listbox">
            ${['', ...ids].map((id, i) => `<button type="button" class="avatar-circle${cur === id ? ' selected' : ''}" role="option"
                data-avatar="${escAttr(id)}" aria-selected="${cur === id}"
                title="${escAttr(id ? t('avatar.n', { n: i }) : noneLabel)}">${previewHtml(id, fallback)}</button>`).join('')}
        </div>`;
};

/** Wire a picker built by avatarPickerHtml(): the circle and the button open the grid, a pick closes it. */
export const wireAvatarPicker = (prefix, fallback) => {
    const grid  = document.getElementById(`${prefix}-avatar-grid`);
    const input = document.getElementById(`${prefix}-avatar`);
    const prev  = document.getElementById(`${prefix}-avatar-btn`);
    if (!grid || !input || !prev) return;
    const toggle = () => grid.classList.toggle('hidden');
    prev.addEventListener('click', toggle);
    document.getElementById(`${prefix}-avatar-toggle`)?.addEventListener('click', toggle);
    grid.addEventListener('click', (e) => {
        const b = e.target.closest('[data-avatar]');
        if (!b) return;
        input.value = b.dataset.avatar;
        prev.innerHTML = previewHtml(input.value, fallback);
        grid.querySelectorAll('[data-avatar]').forEach(x => {
            const on = x === b;
            x.classList.toggle('selected', on);
            x.setAttribute('aria-selected', String(on));
        });
        grid.classList.add('hidden');
    });
};
