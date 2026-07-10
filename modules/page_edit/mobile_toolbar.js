// Compact editing toolbar for mobile mode. Reuses the same textarea
// (#editor-container, classic mode) and formatting primitives as the desktop
// toolbar, but exposes only the essentials: H1–H3, bold, italic, ordered /
// unordered lists, and delete-current-line.
import { insertMarkdown, insertHeading, prependLines, deleteCurrentLine } from './editor.js';
import { t } from '../i18n/index.js';

const svg = (inner) =>
    `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

export const createMobileEditorToolbar = () => {
    const toolbar = document.getElementById('mobile-editor-toolbar');
    if (!toolbar || toolbar.dataset.built) return;
    toolbar.dataset.built = '1';

    const add = (html, titleKey, onClick, extraClass = '') => {
        const btn = document.createElement('button');
        btn.className = 'med-btn' + (extraClass ? ' ' + extraClass : '');
        btn.type = 'button';
        btn.innerHTML = html;
        btn.title = t(titleKey);
        btn.setAttribute('aria-label', t(titleKey));
        // Keep the textarea selection while tapping the button.
        btn.addEventListener('mousedown', e => e.preventDefault());
        btn.addEventListener('click', e => { e.preventDefault(); onClick(); });
        toolbar.appendChild(btn);
    };

    add('<b>H1</b>', 'mobile.ed.h1', () => insertHeading(1));
    add('<b>H2</b>', 'mobile.ed.h2', () => insertHeading(2));
    add('<b>H3</b>', 'mobile.ed.h3', () => insertHeading(3));
    add('<b>B</b>',  'mobile.ed.bold',   () => insertMarkdown('**', '**'));
    add('<i>I</i>', 'mobile.ed.italic', () => insertMarkdown('*', '*'));
    add(
        svg('<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/>'),
        'mobile.ed.ul', () => prependLines('- ')
    );
    add(
        svg('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>'),
        'mobile.ed.ol', () => prependLines('1. ')
    );
    add(
        svg('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>'),
        'mobile.ed.delline', deleteCurrentLine, 'med-btn-danger'
    );
};
