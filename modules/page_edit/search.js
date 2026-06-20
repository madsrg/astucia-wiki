import { showToast } from '../core/utils.js';
import { insertMarkdown } from './editor.js';

export const openSearchReplace = () => {
    const editor = document.getElementById('editor-container');
    const searchReplaceBar = document.getElementById('search-replace-bar');
    const searchInput = document.getElementById('search-input');

    const selectedText = editor.value.substring(editor.selectionStart, editor.selectionEnd);
    if (selectedText) searchInput.value = selectedText;

    searchReplaceBar.classList.remove('hidden');
    searchInput.focus();
    searchInput.select();
};

export const init = () => {
    const editor = document.getElementById('editor-container');
    const searchBtn = document.getElementById('search-btn');
    const searchReplaceBar = document.getElementById('search-replace-bar');
    const searchInput = document.getElementById('search-input');
    const replaceInput = document.getElementById('replace-input');
    const replaceBtn = document.getElementById('replace-btn');
    const replaceAllBtn = document.getElementById('replace-all-btn');
    const searchCloseBtn = document.getElementById('search-close-btn');

    searchBtn.addEventListener('click', openSearchReplace);

    searchCloseBtn.addEventListener('click', () => {
        searchReplaceBar.classList.add('hidden');
    });

    replaceBtn.addEventListener('click', () => {
        const searchTerm = searchInput.value.replace(/\\n/g, '\n');
        const replaceTerm = replaceInput.value.replace(/\\n/g, '\n');
        if (!searchTerm) return;

        const text = editor.value;
        const cursorPosition = editor.selectionEnd;
        const nextOccurrence = text.indexOf(searchTerm, cursorPosition);

        if (nextOccurrence !== -1) {
            editor.setSelectionRange(nextOccurrence, nextOccurrence + searchTerm.length);
            insertMarkdown(replaceTerm);
        } else {
            const firstOccurrence = text.indexOf(searchTerm);
            if (firstOccurrence !== -1) {
                editor.setSelectionRange(firstOccurrence, firstOccurrence + searchTerm.length);
                insertMarkdown(replaceTerm);
            } else {
                showToast('No more occurrences found.', 'info');
            }
        }
    });

    replaceAllBtn.addEventListener('click', () => {
        const searchTerm = searchInput.value.replace(/\\n/g, '\n');
        const replaceTerm = replaceInput.value.replace(/\\n/g, '\n');
        if (!searchTerm) return;
        editor.value = editor.value.replaceAll(searchTerm, replaceTerm);
        editor.dispatchEvent(new Event('input'));
    });
};
