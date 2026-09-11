// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';
import { insertMarkdown, insertBlock, insertSmart, insertHeading, prependLines, deleteCurrentLine } from './editor.js';
import { openIncludeLightbox, openImageLightbox, openDiagramInsertLightbox, openListInsertLightbox } from './insert_media.js';
import { openCommentLightbox } from './insert_comment.js';
import { openLinkLightbox, openExternalLinkLightbox } from './link_lightbox.js';
import { t } from '../i18n/index.js';

const svg = (inner, sw = 2) =>
    `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

// Starter blocks for Insert → Mermaid Diagram. Each already renders, so the result is
// visible immediately and can be edited down; the closing fence is passed as the suffix
// so any selected lines end up inside the block.
//
// The new types carry the introductory example from that diagram's page in the mermaid
// documentation rather than one invented here — upstream syntax, and recognisable to
// anyone who arrives from those docs. Two things are dropped from the docs' versions: the
// `---` frontmatter wrappers (`title:`, `config:`, `look:`), which exist to pin the
// documentation site's own rendering, and anything needing assets this wiki does not load
// (noted where it applies). Sequence and Flowchart keep the skeletons they had before the
// submenu existed — they were already working starters, and the docs' opening flowchart
// example is a single node with no edges.
const mermaidBlock = (...lines) => '```mermaid\n' + lines.join('\n');
const FENCE_END = '\n```';

const SEQUENCE_SKELETON = mermaidBlock(
    'sequenceDiagram',
    '    autonumber',
    '    Alice->>Bob: Request',
    '    Bob-->>Alice: Response');

const FLOWCHART_SKELETON = mermaidBlock(
    'flowchart TD',
    '    Start([Start]) --> Check{OK?}',
    '    Check -->|yes| Done([Done])',
    '    Check -->|no| Start');

const CLASS_SKELETON = mermaidBlock(
    'classDiagram',
    '    note "From Duck till Zebra"',
    '    Animal <|-- Duck',
    '    note for Duck "can fly<br>can swim<br>can dive<br>can help in debugging"',
    '    Animal <|-- Fish',
    '    Animal <|-- Zebra',
    '    Animal : +int age',
    '    Animal : +String gender',
    '    Animal: +isMammal()',
    '    Animal: +mate()',
    '    class Duck{',
    '        +String beakColor',
    '        +swim()',
    '        +quack()',
    '    }',
    '    class Fish{',
    '        -int sizeInFeet',
    '        -canEat()',
    '    }',
    '    class Zebra{',
    '        +bool is_wild',
    '        +run()',
    '    }');

const GANTT_SKELETON = mermaidBlock(
    'gantt',
    '    title A Gantt Diagram',
    '    dateFormat YYYY-MM-DD',
    '    section Section',
    '        A task          :a1, 2014-01-01, 30d',
    '        Another task    :after a1, 20d',
    '    section Another',
    '        Task in Another :2014-01-12, 12d',
    '        another task    :24d');

// The docs' full board, shortened: their example carries a deliberately 100-character
// task title and repeats an id, neither of which belongs in a starter.
const KANBAN_SKELETON = mermaidBlock(
    'kanban',
    '  Todo',
    '    [Create Documentation]',
    '    docs[Create Blog about the new diagram]',
    '  [In progress]',
    '    id6[Create renderer so that it works in all cases]',
    '  id9[Ready for deploy]',
    "    id8[Design grammar]@{ assigned: 'knsv' }",
    '  id11[Done]',
    '    id5[define getData]');

// The docs' example also carries a `::icon(fa fa-book)` line; dropped, because it needs
// Font Awesome, which this wiki does not load — it would insert a line that does nothing.
const MINDMAP_SKELETON = mermaidBlock(
    'mindmap',
    '  root((mindmap))',
    '    Origins',
    '      Long history',
    '      Popularisation',
    '        British popular psychology author Tony Buzan',
    '    Research',
    '      On effectiveness<br/>and features',
    '      On Automatic creation',
    '        Uses',
    '            Creative techniques',
    '            Strategic planning',
    '            Argument mapping',
    '    Tools',
    '      Pen and paper',
    '      Mermaid');

const PIE_SKELETON = mermaidBlock(
    'pie title Pets adopted by volunteers',
    '    "Dogs" : 386',
    '    "Cats" : 85',
    '    "Rats" : 15');

const QUADRANT_SKELETON = mermaidBlock(
    'quadrantChart',
    '    title Reach and engagement of campaigns',
    '    x-axis Low Reach --> High Reach',
    '    y-axis Low Engagement --> High Engagement',
    '    quadrant-1 We should expand',
    '    quadrant-2 Need to promote',
    '    quadrant-3 Re-evaluate',
    '    quadrant-4 May be improved',
    '    Campaign A: [0.3, 0.6]',
    '    Campaign B: [0.45, 0.23]',
    '    Campaign C: [0.57, 0.69]',
    '    Campaign D: [0.78, 0.34]',
    '    Campaign E: [0.40, 0.34]',
    '    Campaign F: [0.35, 0.78]');

// `xychart`, not `xychart-beta`: the pinned mermaid@11 detector accepts either
// (/^\s*xychart(-beta)?/) and the docs have moved to the unsuffixed keyword.
const XYCHART_SKELETON = mermaidBlock(
    'xychart',
    '    title "Sales Revenue"',
    '    x-axis [jan, feb, mar, apr, may, jun, jul, aug, sep, oct, nov, dec]',
    '    y-axis "Revenue (in $)" 4000 --> 11000',
    '    bar [5000, 6000, 7500, 8200, 9500, 10500, 11000, 10200, 9200, 8500, 7000, 6000]',
    '    line [5000, 6000, 7500, 8200, 9500, 10500, 11000, 10200, 9200, 8500, 7000, 6000]');

// The submenu's contents. Ordered by the *displayed* label rather than by this list, so
// "alphabetical" holds in every language — same localeCompare convention the file tree
// uses for names the server hands back in strcmp order.
const MERMAID_STARTERS = [
    ['tb.mermaid-class',     CLASS_SKELETON],
    ['tb.insert-flowchart',  FLOWCHART_SKELETON],
    ['tb.mermaid-gantt',     GANTT_SKELETON],
    ['tb.mermaid-kanban',    KANBAN_SKELETON],
    ['tb.mermaid-mindmap',   MINDMAP_SKELETON],
    ['tb.mermaid-pie',       PIE_SKELETON],
    ['tb.mermaid-quadrant',  QUADRANT_SKELETON],
    ['tb.insert-sequence',   SEQUENCE_SKELETON],
    ['tb.mermaid-xychart',   XYCHART_SKELETON],
];

// Callouts. The type word is the only thing that differs, so one builder covers the menu
// entries; a reader can change `note` to any of the supported types by editing that word.
// Appending "-" folds it shut by default and "+" makes it foldable but open.
const callout = (type, title) => `> [!${type}] ${title}\n> `;
const CALLOUT_TYPES = 'note, info, abstract, todo, tip, success, question, warning, '
    + 'failure, danger, bug, important, example, quote';

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
    addBtn('H1', t('tb.h1'), () => insertHeading(1));
    addBtn('H2', t('tb.h2'), () => insertHeading(2));
    addBtn('H3', t('tb.h3'), () => insertHeading(3));

    // ── Inline formatting ───────────────────────────────────────────────────
    addBtn(
        svg('<path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/>', 2.5),
        t('tb.bold'),
        () => insertMarkdown(hk('alt+b').prefix, hk('alt+b').suffix)
    );
    addBtn(
        svg('<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>', 2.5),
        t('tb.italic'),
        () => insertMarkdown(hk('alt+i').prefix, hk('alt+i').suffix)
    );
    addBtn(
        svg('<line x1="5" y1="12" x2="19" y2="12"/><path d="M16 6.5C14.5 5 12.5 4.5 11 4.5c-2.5 0-4 1.2-4 3 0 1.3.9 2.2 2.5 2.5"/><path d="M8 17.5C9.5 19 11.5 19.5 13 19.5c2.5 0 4-1.2 4-3 0-1.3-.9-2.2-2.5-2.5"/>'),
        t('tb.strikethrough'),
        () => insertMarkdown('~~', '~~')
    );
    addBtn(
        svg('<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>'),
        t('tb.code'),
        () => insertSmart(hk('alt+c').prefix, hk('alt+c').suffix)
    );

    // ── Lists ───────────────────────────────────────────────────────────────
    addBtn(
        svg('<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/>'),
        t('tb.ul'),
        () => prependLines('- ')
    );
    addBtn(
        svg('<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>'),
        t('tb.ol'),
        () => prependLines('1. ')
    );
    addBtn(
        svg('<rect x="2" y="5" width="6" height="6" rx="1"/><polyline points="3.5 8 5 9.5 7.5 6.5"/><line x1="11" y1="8" x2="22" y2="8"/><rect x="2" y="14" width="6" height="6" rx="1"/><line x1="11" y1="17" x2="22" y2="17"/>'),
        t('tb.checklist'),
        () => prependLines('- [ ] ')
    );

    // ── Block formatting ────────────────────────────────────────────────────
    addBtn(
        svg('<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>'),
        t('tb.quote'),
        // prependLines, like the list buttons beside it: "> " only quotes when it starts a line,
        // and a multi-line selection needs the marker on every line, not just the first.
        () => prependLines('> ')
    );
    addBtn(
        svg('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>'),
        t('tb.table'),
        () => insertSmart(hk('alt+t').prefix, hk('alt+t').suffix)
    );
    addBtn(
        svg('<line x1="3" y1="12" x2="21" y2="12"/>', 2.5),
        t('tb.hr'),
        () => insertBlock('---')
    );

    // ── New paragraph ───────────────────────────────────────────────────────
    addBtn(
        svg('<path d="M13 4v16"/><path d="M17 4v16"/><path d="M6 4h7a4 4 0 0 1 0 8H6"/>'),
        t('tb.new-para'),
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
        t('tb.del-line'),
        deleteCurrentLine
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

        // Nested menus, for groups too long to sit inline (the mermaid types, the callouts)
        // without burying the rest of Insert.
        const submenus = [];

        const openSubmenu = (only) => {
            for (const { wrap, sub } of submenus) {
                if (wrap !== only) { sub.classList.add('hidden'); continue; }
                if (!sub.classList.contains('hidden')) continue;
                sub.classList.remove('hidden');
                // Opens to the right, unless that would run off the window — the Insert
                // menu sits wherever the toolbar's width puts it. Measured on each open,
                // since the window may have been resized since the last one.
                sub.classList.remove('submenu-flip');
                if (sub.getBoundingClientRect().right > window.innerWidth - 8) {
                    sub.classList.add('submenu-flip');
                }
            }
        };

        // Hover opens, which is what a nested menu is expected to do. The pointer's path
        // from the parent row into the submenu stays inside this subtree, so `mouseleave`
        // on `content` cannot fire on the way across — the CSS deliberately leaves no gap
        // between the two for the same reason. Moving onto any plain row closes whatever
        // was open, so one hover cannot leave two menus on screen.
        content.addEventListener('mouseover', (e) => {
            if (!submenus.length) return;
            const wrap = e.target.closest('.dropdown-submenu');
            openSubmenu(wrap && content.contains(wrap) ? wrap : null);
        });
        content.addEventListener('mouseleave', () => openSubmenu(null));

        const addSubmenu = (text, title, buildSubItems) => {
            const row = document.createElement('a');
            row.href = '#';
            row.className = 'dropdown-submenu-toggle';
            row.title = title;
            row.textContent = text;

            const sub = document.createElement('div');
            sub.className = 'dropdown-content dropdown-submenu-content hidden';
            allDropdowns.push(sub);

            // Choosing from the submenu closes the whole stack, not just the submenu.
            buildSubItems((itemText, itemTitle, action) => {
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = itemText;
                a.title = itemTitle;
                a.addEventListener('mousedown', e => e.preventDefault());
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    action();
                    sub.classList.add('hidden');
                    content.classList.add('hidden');
                });
                sub.appendChild(a);
            });

            const wrap = document.createElement('div');
            wrap.className = 'dropdown-submenu';
            wrap.appendChild(row);
            wrap.appendChild(sub);
            content.appendChild(wrap);
            submenus.push({ wrap, sub });

            row.addEventListener('mousedown', e => e.preventDefault());
            // A touch screen has no hover, so the row still has to respond to a tap. It
            // opens rather than toggles: on a pointer that does hover, the row is already
            // open by the time the click lands, and toggling would shut it again.
            row.addEventListener('click', (e) => {
                e.preventDefault();
                // Without this the document listener closes the parent menu too, and the
                // submenu is unreachable.
                e.stopPropagation();
                openSubmenu(wrap);
            });
        };

        buildItems(addItem, addSubmenu);

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
    makeDropdown(t('tb.menu-link'), add => {
        add(t('tb.link-internal'), t('tb.link-internal-title'), openLinkLightbox);
        add(t('tb.link-external'), t('tb.link-external-title'), openExternalLinkLightbox);
    });

    // ── Metadata dropdown ───────────────────────────────────────────────────
    makeDropdown(t('tb.menu-metadata'), add => {
        add(t('tb.meta-filename'), t('tb.meta-filename-title'), () => insertMarkdown('{filename}'));
        add(t('tb.meta-updated'), t('tb.meta-updated-title'), () => insertMarkdown('{lastUpdated}'));
        add(t('tb.meta-toc'), t('tb.meta-toc-title'), () => insertBlock('{toc maxLevels:3}'));
        add(t('tb.meta-comment'), t('tb.meta-comment-title'), () => {
            const k = hk('alt+k');
            if (k.prefix !== undefined) insertSmart(k.prefix, k.suffix);
        });
    });

    // ── Insert dropdown ─────────────────────────────────────────────────────
    makeDropdown(t('tb.menu-insert'), (add, addSubmenu) => {
        add(t('tb.insert-include'), t('tb.insert-include-title'), openIncludeLightbox);
        add(t('tb.insert-image'), t('tb.insert-image-title'), openImageLightbox);
        add(t('tb.insert-diagram'), t('tb.insert-diagram-title'), openDiagramInsertLightbox);
        add(t('tb.insert-list'), t('tb.insert-list-title'), openListInsertLightbox);
        add(t('tb.insert-comment'), t('tb.insert-comment-title'), openCommentLightbox);
        // Text-defined diagrams: a ```mermaid block, rendered as SVG in read mode. The
        // skeleton is a working diagram, so it renders as soon as the page is saved. One
        // submenu rather than nine more rows here, which would push the callouts out of
        // reach and make Insert a list of mostly mermaid.
        addSubmenu(t('tb.insert-mermaid'), t('tb.insert-mermaid-title'), addItem => {
            MERMAID_STARTERS
                .map(([key, skeleton]) => [t(key), skeleton])
                .sort((a, b) => a[0].localeCompare(b[0], undefined, { sensitivity: 'base', numeric: true }))
                .forEach(([label, skeleton]) => {
                    addItem(label, t('tb.mermaid-item-title'), () => insertBlock(skeleton, FENCE_END));
                });
        });
        // Callouts render as coloured boxes in read mode. Same syntax as Obsidian, GitHub and
        // GitLab, so a page carrying one displays correctly in all of them. Left in their
        // own order rather than sorted: Note → Tip → Warning → Danger is a severity
        // progression, and Foldable is a variant of any of them rather than a sixth type.
        addSubmenu(t('tb.insert-callouts'), t('tb.insert-callouts-title'), addItem => {
            addItem(t('tb.callout-note'), t('tb.callout-note-title', { types: CALLOUT_TYPES }),
                () => insertBlock(callout('note', 'Note')));
            addItem(t('tb.callout-tip'), t('tb.callout-tip-title'), () => insertBlock(callout('tip', 'Tip')));
            addItem(t('tb.callout-warning'), t('tb.callout-warning-title'), () => insertBlock(callout('warning', 'Warning')));
            addItem(t('tb.callout-danger'), t('tb.callout-danger-title'), () => insertBlock(callout('danger', 'Danger')));
            addItem(t('tb.callout-foldable'), t('tb.callout-foldable-title'),
                () => insertBlock('> [!note]- Click to expand\n> '));
        });
    });

    // ── Help / keyboard shortcuts dropdown ──────────────────────────────────
    const helpContainer = document.createElement('div');
    helpContainer.className = 'dropdown-container';

    const helpButton = document.createElement('button');
    helpButton.className = 'btn btn-sm btn-secondary';
    helpButton.textContent = '?';
    helpButton.title = t('tb.help-title');

    const helpContent = document.createElement('div');
    helpContent.className = 'dropdown-content editor-help-dropdown hidden';
    allDropdowns.push(helpContent);

    [
        ['Alt+S', t('hk.save')],
        ['Alt+F', t('hk.find')],
        ['Alt+L', t('hk.link')],
        ['Alt+P', t('hk.include')],
        ['Alt+1', t('mobile.ed.h1')],
        ['Alt+2', t('mobile.ed.h2')],
        ['Alt+3', t('mobile.ed.h3')],
        ['Alt+B', t('mobile.ed.bold')],
        ['Alt+I', t('mobile.ed.italic')],
        ['Alt+C', t('hk.code')],
        ['Alt+T', t('hk.table')],
        ['Alt+N', t('tb.meta-filename')],
        ['Alt+K', t('hk.comment')],
        ['Alt+A', t('hk.menu')],
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
    mdRef.textContent = t('tb.md-ref');
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
