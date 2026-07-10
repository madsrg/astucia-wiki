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
