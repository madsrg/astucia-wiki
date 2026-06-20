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
