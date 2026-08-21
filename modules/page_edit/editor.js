// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';

const getEditor = () => state.editMode === 'inline'
    ? (document.querySelector('.wiki-block.inline-block-editing textarea') ?? document.getElementById('editor-container'))
    : document.getElementById('editor-container');

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
