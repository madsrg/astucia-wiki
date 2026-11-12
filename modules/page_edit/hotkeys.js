// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';
import { insertHeading, insertSmart, indentLines, newlineKeepIndent, isMarkdownEditor } from './editor.js';
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

// Esc, pressed in the editor, lets the *next* Tab move the focus instead of indenting.
// See the keydown handler for why that exists at all.
let tabMovesFocus = false;
const MODIFIER_KEYS = new Set(['Shift', 'Control', 'Alt', 'Meta']);

export const init = () => {
    // Global keyboard shortcuts. The list of them is the Help dialog (modules/help, Alt+H),
    // which replaced the editor's own Alt+A list.
    document.addEventListener('keydown', (e) => {

        // ── Tab indents; it does not move the focus ──────────────────────────────
        //
        // Nesting a list item is the single most common thing to want in a Markdown
        // editor, and Tab was spending the keystroke on jumping to the next control.
        // INDENT is a real tab — see editor.js for why, and for what it means to press
        // this on a line that is not in a list.
        //
        // **Claiming Tab makes a keyboard trap, so there is an escape hatch.** A textarea
        // whose Tab is swallowed has no keyboard way out, which WCAG 2.1.2 is explicitly
        // about; Esc then Tab moves the focus, which is the convention CodeMirror and Ace
        // established. Esc is not otherwise bound in the editor and is deliberately *not*
        // consumed here, so anything else listening for it still sees it.
        //
        // Gated on the element, not on `state.isEditing`: a chat composer is a textarea
        // too and can be focused while a page is open, and Tab there is focus movement.
        if (isMarkdownEditor(e.target)) {
            if (e.key === 'Escape') { tabMovesFocus = true; return; }
            if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                // The one Tab that Esc bought. Ctrl/Cmd/Alt+Tab are the window manager's
                // and are never ours, which is the same line modules/tabs draws.
                if (tabMovesFocus) { tabMovesFocus = false; return; }
                e.preventDefault();
                indentLines(e.shiftKey, e.target);
                return;
            }
            // Enter continues at the current line's indentation. Plain Enter only:
            // Shift+Enter stays a bare line break (the way out of an indented run), and an
            // IME composition's Enter confirms the composition, not a new line.
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey && !e.isComposing) {
                tabMovesFocus = false;
                if (newlineKeepIndent(e.target)) e.preventDefault();
                return;
            }
            // Any other key cancels the hatch — but not the modifiers themselves, or
            // holding Shift for Shift+Tab would cancel it before the Tab arrived.
            if (!MODIFIER_KEYS.has(e.key)) tabMovesFocus = false;
        }

        const activeTag = document.activeElement.tagName;

        if (activeTag === 'TEXTAREA') {
            if (!state.isEditing) return;
            const key = e.key.toLowerCase();

            // Alt *alone*. On Windows and Linux AltGr reports as Ctrl+Alt, so a layout
            // that needs AltGr for a character — and there are many — had that character
            // swallowed by preventDefault() and a heading or snippet inserted instead,
            // whenever the key underneath it was one of these. macOS is the mirror image:
            // Cmd+Alt+I is how the browser's dev tools open, and that was being answered
            // with a pair of italic markers. modules/tabs already draws the line here for
            // the same reason; this handler did not.
            if (e.altKey && !e.ctrlKey && !e.metaKey) {
                if (key === 's') { e.preventDefault(); if (!document.getElementById('save-btn').disabled) savePage(); return; }
                if (key === 'l') { e.preventDefault(); state.linkInsertionMode = 'link'; openLinkLightbox(); return; }
                if (key === 'p') { e.preventDefault(); state.linkInsertionMode = 'include'; openLinkLightbox(); return; }
                if (key === 'f') { e.preventDefault(); openSearchReplace(); return; }

                if (key >= '1' && key <= '3' && state.hotkeys[`alt+${key}`]) {
                    e.preventDefault(); insertHeading(parseInt(key, 10));
                } else {
                    const hotkey = state.hotkeys[`alt+${key}`];
                    if (hotkey) { e.preventDefault(); insertSmart(hotkey.prefix, hotkey.suffix); }
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
