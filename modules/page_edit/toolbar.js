import { state } from '../core/state.js';
import { insertMarkdown, insertHeading } from './editor.js';
import { openIncludeLightbox, openImageLightbox, openDiagramInsertLightbox, openListInsertLightbox } from './insert_media.js';
import { openCommentLightbox } from './insert_comment.js';
import { openLinkLightbox, openExternalLinkLightbox } from './link_lightbox.js';

const svg = (inner, sw = 2) =>
    `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

export const createEditorToolbar = () => {
    const toolbar = document.getElementById('editor-toolbar');
    if (!toolbar) return;

    const allDropdowns = [];
    const closeAllDropdowns = () => allDropdowns.forEach(d => d.classList.add('hidden'));
    document.addEventListener('click', closeAllDropdowns);

    // ── Helper: plain icon button ───────────────────────────────────────────
    const addBtn = (innerHTML, title, onClick) => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-secondary toolbar-icon-btn';
        btn.innerHTML = innerHTML;
        btn.title = title;
        btn.addEventListener('mousedown', e => e.preventDefault());
        btn.addEventListener('click', (e) => { e.preventDefault(); onClick(); });
        toolbar.appendChild(btn);
        return btn;
    };

    // Shorthand: pull prefix/suffix from hotkeys.json
    const hk = key => state.hotkeys[key] || {};

    // ── Headings ────────────────────────────────────────────────────────────
    addBtn('H1', 'Heading 1 (Alt+1)', () => insertHeading(1));
    addBtn('H2', 'Heading 2 (Alt+2)', () => insertHeading(2));
    addBtn('H3', 'Heading 3 (Alt+3)', () => insertHeading(3));

    // ── Inline formatting ───────────────────────────────────────────────────
    addBtn(
        svg('<path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/>', 2.5),
        'Bold (Alt+B)',
        () => insertMarkdown(hk('alt+b').prefix, hk('alt+b').suffix)
    );
    addBtn(
        svg('<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>', 2.5),
        'Italic (Alt+I)',
        () => insertMarkdown(hk('alt+i').prefix, hk('alt+i').suffix)
    );
    addBtn(
        svg('<line x1="5" y1="12" x2="19" y2="12"/><path d="M16 6.5C14.5 5 12.5 4.5 11 4.5c-2.5 0-4 1.2-4 3 0 1.3.9 2.2 2.5 2.5"/><path d="M8 17.5C9.5 19 11.5 19.5 13 19.5c2.5 0 4-1.2 4-3 0-1.3-.9-2.2-2.5-2.5"/>'),
        'Strikethrough',
        () => insertMarkdown('~~', '~~')
    );
    addBtn(
        svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'),
        'Code block (Alt+C)',
        () => insertMarkdown(hk('alt+c').prefix, hk('alt+c').suffix)
    );

    // ── Lists ───────────────────────────────────────────────────────────────
    addBtn(
        svg('<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/>'),
        'Unordered list',
        () => insertMarkdown('- ')
    );
    addBtn(
        svg('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>'),
        'Ordered list',
        () => insertMarkdown('1. ')
    );

    // ── Block formatting ────────────────────────────────────────────────────
    addBtn(
        svg('<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>'),
        'Blockquote',
        () => insertMarkdown('> ')
    );
    addBtn(
        svg('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>'),
        'Table (Alt+T)',
        () => insertMarkdown(hk('alt+t').prefix, hk('alt+t').suffix)
    );
    addBtn(
        svg('<line x1="3" y1="12" x2="21" y2="12"/>', 2.5),
        'Horizontal rule',
        () => insertMarkdown('\n\n---\n\n')
    );

    // ── New paragraph ───────────────────────────────────────────────────────
    addBtn(
        svg('<path d="M13 4v16"/><path d="M17 4v16"/><path d="M6 4h7a4 4 0 0 1 0 8H6"/>'),
        'New paragraph (3 blank lines, cursor on 2nd)',
        () => {
            const editor = document.getElementById('editor-container');
            if (!editor) return;
            const pos = editor.selectionStart;
            editor.value = editor.value.substring(0, pos) + '\n\n\n' + editor.value.substring(pos);
            editor.setSelectionRange(pos + 2, pos + 2);
            editor.focus();
            editor.dispatchEvent(new Event('input'));
        }
    );

    // ── Delete current line ─────────────────────────────────────────────────
    const deleteBtn = addBtn(
        svg('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>'),
        'Delete current line',
        () => {
            const editor = document.getElementById('editor-container');
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
        }
    );
    deleteBtn.classList.add('toolbar-btn-danger');

    // ── Dropdown helper ─────────────────────────────────────────────────────
    const makeDropdown = (label, buildItems) => {
        const container = document.createElement('div');
        container.className = 'dropdown-container';

        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-secondary';
        btn.innerHTML = `${label} &#9662;`;

        const content = document.createElement('div');
        content.className = 'dropdown-content hidden';
        allDropdowns.push(content);

        const addItem = (text, title, action) => {
            const a = document.createElement('a');
            a.href = '#';
            a.textContent = text;
            a.title = title;
            a.addEventListener('mousedown', e => e.preventDefault());
            a.addEventListener('click', (e) => { e.preventDefault(); action(); content.classList.add('hidden'); });
            content.appendChild(a);
        };

        buildItems(addItem);

        container.appendChild(btn);
        container.appendChild(content);
        toolbar.appendChild(container);

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = !content.classList.contains('hidden');
            closeAllDropdowns();
            if (!isOpen) content.classList.remove('hidden');
        });

        return content;
    };

    // ── Link dropdown ───────────────────────────────────────────────────────
    makeDropdown('Link', add => {
        add('Internal Link', 'Link to another page in the wiki (Alt+L)', openLinkLightbox);
        add('External Link', 'Link to an external URL', openExternalLinkLightbox);
    });

    // ── Metadata dropdown ───────────────────────────────────────────────────
    makeDropdown('Metadata', add => {
        add('Filename', 'Insert {filename} placeholder', () => insertMarkdown('{filename}'));
        add('Last Updated', 'Insert {lastUpdated} placeholder', () => insertMarkdown('{lastUpdated}'));
        add('Table of Contents', 'Insert {toc} tag', () => insertMarkdown('{toc maxLevels:3}'));
        add('Comment', 'Insert Markdown comment', () => {
            const k = hk('alt+k');
            if (k.prefix !== undefined) insertMarkdown(k.prefix, k.suffix);
        });
    });

    // ── Insert dropdown ─────────────────────────────────────────────────────
    makeDropdown('Insert', add => {
        add('Include Page', 'Embed content from another page ({include:ID})', openIncludeLightbox);
        add('Image', 'Insert an image from attachments', openImageLightbox);
        add('Diagram', 'Embed a draw.io diagram ({diagram:ID})', openDiagramInsertLightbox);
        add('List', 'Embed a list as a table ({list:ID:cols})', openListInsertLightbox);
        add('Comment', 'Insert a user comment ({user_comment:uid:text})', openCommentLightbox);
    });

    // ── Help / keyboard shortcuts dropdown ──────────────────────────────────
    const helpContainer = document.createElement('div');
    helpContainer.className = 'dropdown-container';

    const helpButton = document.createElement('button');
    helpButton.className = 'btn btn-sm btn-secondary';
    helpButton.textContent = '?';
    helpButton.title = 'Keyboard shortcuts';

    const helpContent = document.createElement('div');
    helpContent.className = 'dropdown-content editor-help-dropdown hidden';
    allDropdowns.push(helpContent);

    [
        ['Alt+S', 'Save'],
        ['Alt+F', 'Find & Replace'],
        ['Alt+L', 'Insert Link'],
        ['Alt+P', 'Include Page'],
        ['Alt+1', 'Heading 1'],
        ['Alt+2', 'Heading 2'],
        ['Alt+3', 'Heading 3'],
        ['Alt+B', 'Bold'],
        ['Alt+I', 'Italic'],
        ['Alt+C', 'Code Block'],
        ['Alt+T', 'Insert Table'],
        ['Alt+N', 'Filename'],
        ['Alt+K', 'Comment'],
        ['Alt+A', 'Shortcut menu'],
    ].forEach(([key, label]) => {
        const row = document.createElement('div');
        row.className = 'editor-help-row';
        row.innerHTML = `<kbd>${key}</kbd><span>${label}</span>`;
        helpContent.appendChild(row);
    });

    const sep = document.createElement('div');
    sep.style.cssText = 'border-top:1px solid var(--border);margin:6px 0 4px';
    helpContent.appendChild(sep);

    const mdRef = document.createElement('a');
    mdRef.href = 'https://www.markdownguide.org/';
    mdRef.target = '_blank';
    mdRef.rel = 'noopener';
    mdRef.textContent = 'Markdown reference ↗';
    mdRef.style.cssText = 'display:block;padding:4px 8px;font-size:0.8rem';
    helpContent.appendChild(mdRef);

    helpContainer.appendChild(helpButton);
    helpContainer.appendChild(helpContent);
    toolbar.appendChild(helpContainer);

    helpButton.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !helpContent.classList.contains('hidden');
        closeAllDropdowns();
        if (!isOpen) helpContent.classList.remove('hidden');
    });
};
