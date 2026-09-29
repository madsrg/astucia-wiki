// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

/**
 * Asking for attention from outside the window.
 *
 * The sidebar badge answers "have I been mentioned" for somebody who is looking at the
 * wiki. This module answers it for somebody who is not: the tab title carries the count
 * and the favicon carries a dot, so a mention arriving while the window is behind
 * something else is visible without the window being brought forward.
 *
 * Deliberately the two mechanisms that need **no permission**. A real OS notification is
 * a third layer and a separate decision — it needs a user gesture to request, a grant
 * that is sticky per origin once denied, and a secure context, which a wiki served over
 * plain HTTP on a LAN hostname does not have (the same constraint behind
 * modules/code_copy's execCommand fallback). These two work everywhere, so whatever is
 * built on top of them, they are the floor.
 *
 * Two rules, and they are different on purpose:
 *
 *  - **The title counter is shown only while the window is not focused.** It is a claim
 *    on attention you are giving to something else; once you are looking at the wiki the
 *    sidebar badge is on screen and saying it twice in the browser's own chrome is
 *    noise. `document.hasFocus()`, not `document.hidden` — a window can be fully visible
 *    on a second monitor and still be the thing you are not looking at, and `hidden` is
 *    false there.
 *  - **The favicon dot stays while anything is unread**, focused or not. It is the state
 *    of the tab rather than a claim on attention, and it is the only marker left on a
 *    pinned or very narrow tab, where no title is rendered at all.
 *
 * Nothing here decides *what* is unread. `setUnread(n)` is called by modules/mentions
 * with the same number the badge shows, so the three surfaces cannot disagree.
 */

// The count is read by other modules only through setUnread, so this is the whole state.
let _unread = 0;
let _baseTitle = '';

// The favicon this page shipped with, and the badged version drawn from it. Drawing is
// asynchronous (an <img> has to decode), so it is done once and cached rather than on
// every change of the count.
let _iconLink = null;
let _baseIcon = '';
let _badgedIcon = null;      // data: URL, or null while undrawn
let _badgeFailed = false;    // the icon could not be decoded; title-only from here

const BADGE_COLOR = '#e53e3e';

const iconLink = () => {
    if (_iconLink && _iconLink.isConnected) return _iconLink;
    _iconLink = document.querySelector('link[rel~="icon"]');
    return _iconLink;
};

/**
 * Paint a dot in the corner of the page's own favicon.
 *
 * Composited rather than swapped for a bespoke image: the tab should still be
 * recognisably this wiki, and a wholly different icon reads as having navigated
 * somewhere else. If the icon cannot be decoded — an exotic format, or a `file:`-like
 * context where the canvas would be tainted — the favicon is left exactly as it was and
 * the title carries the whole job. A dot on its own would be worse than no dot: it would
 * replace the site's identity with a red blob.
 */
const drawBadge = (href) => new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
        try {
            const size = 32;
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = size;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, size, size);
            const r = size * 0.28;
            // Punched out of the icon first, so the dot reads as a dot against a busy
            // corner rather than merging into whatever is behind it.
            ctx.beginPath();
            ctx.arc(size - r, r, r * 1.35, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,255,255,0.95)';
            ctx.fill();
            ctx.beginPath();
            ctx.arc(size - r, r, r, 0, Math.PI * 2);
            ctx.fillStyle = BADGE_COLOR;
            ctx.fill();
            resolve(canvas.toDataURL('image/png'));
        } catch {
            resolve(null);
        }
    };
    img.onerror = () => resolve(null);
    img.src = href;
});

const renderTitle = () => {
    if (!_baseTitle) return;
    document.title = (_unread > 0 && !document.hasFocus())
        ? `(${_unread > 99 ? '99+' : _unread}) ${_baseTitle}`
        : _baseTitle;
};

const renderIcon = async () => {
    const link = iconLink();
    if (!link || _badgeFailed) return;
    if (_unread <= 0) {
        if (_baseIcon && link.href !== _baseIcon) link.href = _baseIcon;
        return;
    }
    if (_badgedIcon === null) {
        _badgedIcon = await drawBadge(_baseIcon);
        if (_badgedIcon === null) { _badgeFailed = true; return; }
    }
    // Re-checked after the await: the count can have been cleared while the icon was
    // decoding, and setting it then would leave a dot nothing can remove.
    if (_unread > 0 && link.href !== _badgedIcon) link.href = _badgedIcon;
};

/**
 * How many unread things this user has. Called with the same number the sidebar badge
 * shows; 0 clears both surfaces.
 */
export const setUnread = (n) => {
    const next = Math.max(0, Number(n) || 0);
    if (next === _unread) return;
    _unread = next;
    renderTitle();
    renderIcon();
};

export const init = () => {
    _baseTitle = document.title;
    const link = iconLink();
    _baseIcon = link ? link.href : '';
    if (!_baseIcon) _badgeFailed = true;

    // focus/blur rather than visibilitychange: the rule is about what the user is
    // looking at, and switching to another application fires neither a
    // visibilitychange nor anything else on this document except blur.
    window.addEventListener('focus', renderTitle);
    window.addEventListener('blur', renderTitle);
    renderTitle();
};
