// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';

const getEditor = () => state.editMode === 'inline'
    ? (document.querySelector('.wiki-block.inline-block-editing textarea') ?? document.getElementById('editor-container'))
    : document.getElementById('editor-container');

/**
 * Is this element one of the two Markdown textareas the editor drives?
 *
 * Both of them, because Tab meaning one thing in the full-page editor and another in an
 * inline block would make the key change meaning as you switch modes. It has to be the
 * element rather than "a textarea while state.isEditing": a page chat composer is also a
 * textarea and can be focused while a page is open, and Tab there is focus movement.
 */
export const isMarkdownEditor = (el) =>
    !!el && el.tagName === 'TEXTAREA'
    && (el.id === 'editor-container' || el.classList.contains('inline-block-textarea'));

/**
 * What one press of Tab inserts.
 *
 * A real tab, not spaces. One press is one nesting level whatever the list marker is, it
 * survives a round trip through an Obsidian vault, and Shift+Tab can take it back off
 * without guessing how many characters to eat. Both renderers agree on it: marked (the
 * browser) and Parsedown (the static export) each treat a leading tab as one level of list
 * nesting, exactly like two or four spaces.
 *
 * The consequence to know: a tab is four columns to CommonMark, so Tab on a line that is
 * *not* inside a list — a plain paragraph after a blank line — makes it an indented code
 * block. That is both renderers behaving correctly, and it is what the preview is for.
 */
export const INDENT = '\t';
/** One tab, or up to a tab's worth of spaces, from the front of a line. */
const OUTDENT_RE = /^(\t| {1,4})/;

/**
 * Write into the textarea **without destroying the browser's undo stack**.
 *
 * `setRangeText()` — what every other helper in this file uses — is a scripted mutation,
 * and browsers drop the native undo history when one lands. That is tolerable for a
 * toolbar button pressed now and then; Tab is pressed *while typing*, and an editor where
 * Ctrl+Z stops working after every indent is worse than no Tab key at all.
 * `execCommand('insertText')` is deprecated but is still the only way to insert text as if
 * it had been typed, so it is the first choice and setRangeText is the fallback.
 *
 * @returns {boolean} true when the native path was used — in which case the browser has
 *          already fired `input` and the caller must not fire a second one.
 */
const replaceRange = (ed, start, end, text) => {
    ed.focus();
    ed.setSelectionRange(start, end);
    // Not for a pure deletion: insertText('') is not reliably a delete across engines,
    // and the fallback handles that case correctly.
    if (text !== '') {
        try { if (document.execCommand('insertText', false, text)) return true; } catch { /* below */ }
    }
    ed.setRangeText(text, start, end, 'end');
    return false;
};

/**
 * Indent (Tab) or outdent (Shift+Tab) whatever the selection touches.
 *
 * A caret, or a selection inside a single line, indents like typing a character. Anything
 * spanning a line break moves every line it touches as a block, which is the case the key
 * exists for — nesting three bullets at once. Outdent always works on lines, since
 * removing indentation at the caret is not a thing you can ask for.
 *
 * @param {HTMLTextAreaElement} [el] The textarea the key was pressed in. Passed rather
 *        than resolved, because `getEditor()` answers from `state.editMode` — which is
 *        the right answer for a toolbar button and a guess here. The event already knows.
 */
export const indentLines = (outdent = false, el = null) => {
    const ed = el || getEditor();
    if (!ed) return;
    const value = ed.value;
    const start = ed.selectionStart;
    const end   = ed.selectionEnd;

    if (!outdent && !value.slice(start, end).includes('\n')) {
        const native = replaceRange(ed, start, end, INDENT);
        const caret = start + INDENT.length;
        ed.setSelectionRange(caret, caret);
        if (!native) ed.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    const blockStart = value.lastIndexOf('\n', start - 1) + 1;
    // A selection that ends exactly on a line break ends on the line *before* it; without
    // this, shift-selecting three whole lines would indent a fourth.
    const scanFrom = (end > start && value[end - 1] === '\n') ? end - 1 : end;
    let blockEnd = value.indexOf('\n', scanFrom);
    if (blockEnd === -1) blockEnd = value.length;

    let firstDelta = 0;
    let totalDelta = 0;
    const block = value.slice(blockStart, blockEnd).split('\n').map((line, i) => {
        let delta = 0;
        let out = line;
        if (outdent) {
            const m = line.match(OUTDENT_RE);
            if (m) { out = line.slice(m[0].length); delta = -m[0].length; }
        } else if (line !== '') {
            // An empty line is left empty. Indenting it would write trailing whitespace
            // into the file — noise in the diff, and nothing at all in the rendering.
            out = INDENT + line;
            delta = INDENT.length;
        }
        if (i === 0) firstDelta = delta;
        totalDelta += delta;
        return out;
    }).join('\n');

    // Nothing was indented far enough to give anything back. The key still belongs to the
    // editor — Shift+Tab at column zero must not fling the focus into the toolbar.
    if (totalDelta === 0) return;

    const native = replaceRange(ed, blockStart, blockEnd, block);
    // Keep the same *text* selected rather than snapping out to whole lines, so pressing
    // Tab twice indents the same thing twice.
    const from = Math.max(blockStart, start + firstDelta);
    ed.setSelectionRange(from, start === end ? from : Math.max(from, end + totalDelta));
    if (!native) ed.dispatchEvent(new Event('input', { bubbles: true }));
};

// Sets heading level at the start of the current line, replacing any existing heading marker.
export const insertHeading = (level) => {
    const prefix = '#'.repeat(level) + ' ';
    const editor = getEditor();
    if (!editor) return;

    const text = editor.value;
    const cursorPos = editor.selectionStart;
    const lineStart = text.lastIndexOf('\n', cursorPos - 1) + 1;

    const existingHeading = text.slice(lineStart).match(/^#{1,6} /);
    const existingLen = existingHeading ? existingHeading[0].length : 0;

    const newText = text.slice(0, lineStart) + prefix + text.slice(lineStart + existingLen);
    const contentOffset = Math.max(0, cursorPos - lineStart - existingLen);

    editor.value = newText;
    editor.setSelectionRange(lineStart + prefix.length + contentOffset, lineStart + prefix.length + contentOffset);
    editor.focus();
    editor.dispatchEvent(new Event('input'));
};

// Prepends prefix to every selected line; inserts prefix at cursor when nothing is selected.
export const prependLines = (prefix) => {
    const editor = getEditor();
    if (!editor) return;
    const start = editor.selectionStart;
    const end   = editor.selectionEnd;
    const selectedText = editor.value.substring(start, end);

    if (start !== end) {
        const lines = selectedText.split('\n');
        const newText = lines.map((line, i) =>
            (line === '' && i === lines.length - 1) ? '' : prefix + line
        ).join('\n');
        editor.setRangeText(newText, start, end, 'end');
    } else {
        editor.setRangeText(prefix, start, start, 'end');
    }

    editor.focus();
    editor.dispatchEvent(new Event('input'));
};

// Removes the line the cursor is on (including its newline).
export const deleteCurrentLine = () => {
    const editor = getEditor();
    if (!editor) return;
    const pos = editor.selectionStart;
    const val = editor.value;
    const lineStart = val.lastIndexOf('\n', pos - 1) + 1;
    const nextNewline = val.indexOf('\n', pos);
    const isLastLine = nextNewline === -1;
    // For the last line there is no trailing \n, so eat the preceding one instead
    const start = isLastLine && lineStart > 0 ? lineStart - 1 : lineStart;
    const end = isLastLine ? val.length : nextNewline + 1;
    editor.value = val.substring(0, start) + val.substring(end);
    editor.setSelectionRange(start, start);
    editor.focus();
    editor.dispatchEvent(new Event('input'));
};

/**
 * Insert a **block** construct, padded so it starts a line and is separated by a blank line.
 *
 * `insertMarkdown` splices at the caret with no idea what a line is, which is right for `**bold**`
 * and wrong for everything block-level: with the caret at the end of "Some text", inserting a
 * callout produced `Some text> [!note] Note`, and a fence, table or `{toc}` tag mid-line does not
 * render either. The fix is context-sensitive rather than a fixed `\n\n` — that would pile up
 * blank lines when the caret is already on an empty line, and indent the top of an empty page.
 *
 * Only as many newlines as are missing are added, on both sides: before, so the block starts
 * cleanly; after, so the text that followed the caret is not absorbed into the block.
 * Newlines are LF — the editor's content is a file, and the repo normalises to LF.
 */
export const insertBlock = (prefix, suffix = '') => {
    const editor = getEditor();
    if (!editor) return;
    const start = editor.selectionStart;
    const end   = editor.selectionEnd;
    const value = editor.value;
    const selected = value.substring(start, end);

    const before = value.slice(0, start);
    const after  = value.slice(end);
    // At the very start or end of the document nothing needs separating.
    const lead  = before === '' ? '' : '\n'.repeat(Math.max(0, 2 - before.match(/\n*$/)[0].length));
    const trail = after  === '' ? '' : '\n'.repeat(Math.max(0, 2 - after.match(/^\n*/)[0].length));

    const text = lead + prefix + selected + suffix + trail;
    editor.setRangeText(text, start, end, 'select');
    // Same caret convention as insertMarkdown: after the prefix when inserting (so you are
    // typing inside the new block), after the whole thing when wrapping a selection.
    const caret = start + lead.length + prefix.length + (selected ? selected.length + suffix.length : 0);
    editor.setSelectionRange(caret, caret);

    editor.focus();
    editor.dispatchEvent(new Event('input'));
};

/**
 * Does this snippet have to start its own line to mean anything?
 *
 * Used for the paths that insert whatever is configured in hotkeys.json, where the caller cannot
 * know: a fence, a table row, a blockquote or callout marker, a heading, or a link-reference
 * definition — plus anything spanning more than one line, which cannot be inline by definition.
 */
export const looksLikeBlock = (prefix) =>
    typeof prefix === 'string' && prefix !== ''
    && (prefix.includes('\n') || /^(```|~~~|\||>|#{1,6}\s|\[\/\/\]:)/.test(prefix));

/** Routes to insertBlock or insertMarkdown depending on what the snippet is. */
export const insertSmart = (prefix, suffix = '') =>
    (looksLikeBlock(prefix) ? insertBlock : insertMarkdown)(prefix, suffix);

// Core text-insertion helper used by toolbar, hotkeys, search/replace, and link lightbox.
export const insertMarkdown = (prefix, suffix = '') => {
    const editor = getEditor();
    const start = editor.selectionStart;
    const end = editor.selectionEnd;
    const selectedText = editor.value.substring(start, end);
    const newText = prefix + selectedText + suffix;

    editor.setRangeText(newText, start, end, 'select');

    if (selectedText) {
        editor.setSelectionRange(start + newText.length, start + newText.length);
    } else {
        editor.setSelectionRange(start + prefix.length, start + prefix.length);
    }

    editor.focus();
    editor.dispatchEvent(new Event('input'));
};
