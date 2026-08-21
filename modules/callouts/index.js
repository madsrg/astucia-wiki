// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Callouts — `> [!note] Optional title` blockquotes rendered as admonition boxes.
 *
 * The syntax is Obsidian's, and GitHub and GitLab render the same thing, so a note written
 * in any of them displays correctly here and vice versa. That two-way compatibility is the
 * reason this is worth having: pages move between tools without being rewritten.
 *
 * Runs on the rendered DOM rather than on the Markdown source, unlike `{include:ID}` and the
 * table-of-contents tag. A callout body is ordinary Markdown — lists, links, bold, even a
 * nested callout — and a source transform would have to either re-implement that or hand
 * marked a half-built HTML block. Post-processing lets marked do all of it first; this then
 * only restructures what it produced.
 *
 * Hooked into the MutationObserver in modules/page_view (`setupDiagramObserver`), the same
 * one mermaid uses, which covers page load, in-place refresh, the inline editor's preview and
 * transcluded content in a single place.
 */

const svg = (inner) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;

// Canonical types → the colour class in styles.css and an icon. Six colours, reusing the
// palette in :root rather than inventing new ones.
const TYPES = {
    note:      { tone: 'blue',   icon: svg('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>') },
    info:      { tone: 'blue',   icon: svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>') },
    abstract:  { tone: 'blue',   icon: svg('<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>') },
    todo:      { tone: 'blue',   icon: svg('<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>') },
    tip:       { tone: 'green',  icon: svg('<path d="M12 2c1 4 4 5 4 9a4 4 0 0 1-8 0c0-2 1-3 1-5 0 0-3 2-3 6a7 7 0 0 0 14 0c0-6-5-7-8-10Z"/>') },
    success:   { tone: 'green',  icon: svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>') },
    question:  { tone: 'orange', icon: svg('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>') },
    warning:   { tone: 'orange', icon: svg('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>') },
    failure:   { tone: 'red',    icon: svg('<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>') },
    danger:    { tone: 'red',    icon: svg('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>') },
    bug:       { tone: 'red',    icon: svg('<rect x="8" y="6" width="8" height="14" rx="4"/><path d="M19 7l-3 2M5 7l3 2M12 20v2M3 13h3M18 13h3"/>') },
    important: { tone: 'purple', icon: svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>') },
    example:   { tone: 'purple', icon: svg('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>') },
    quote:     { tone: 'gray',   icon: svg('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>') },
};

// Obsidian's aliases, plus GitHub's set (NOTE, TIP, IMPORTANT, WARNING, CAUTION). GitHub
// treats CAUTION as its most severe level, Obsidian treats it as a synonym for warning; we
// follow Obsidian, because that is where the longer type list comes from.
const ALIASES = {
    hint: 'tip', summary: 'abstract', tldr: 'abstract',
    check: 'success', done: 'success', help: 'question', faq: 'question',
    caution: 'warning', attention: 'warning', fail: 'failure', missing: 'failure',
    error: 'danger', cite: 'quote',
};

const FOLD_ICON = svg('<polyline points="6 9 12 15 18 9"/>');

// `[!type]` optionally followed by - (folded shut) or + (foldable, open), then the title.
const MARKER = /^\s*\[!([A-Za-z][\w-]*)\]([-+])?[ \t]*/;

const resolveType = (raw) => {
    const key = raw.toLowerCase();
    const canonical = ALIASES[key] || key;
    return TYPES[canonical] ? canonical : null;
};

const titleCase = (s) => s.charAt(0).toUpperCase() + s.slice(1);

const convert = (bq) => {
    const first = bq.firstElementChild;
    if (!first || first.tagName !== 'P') return false;

    // Match against innerHTML, not textContent: the rest of the line has to be carried over
    // as HTML (marked has already turned any bold/links in the title into elements), and the
    // marker itself is plain text at the very start, so it appears identically in both.
    const m = MARKER.exec(first.innerHTML);
    if (!m) return false;
    const type = resolveType(m[1]);
    if (!type) return false;                 // `[!nonsense]` stays an ordinary blockquote

    const fold = m[2] || '';
    // The title is the remainder of the first source line; everything after the first newline
    // is body text that marked folded into the same paragraph.
    const afterMarker = first.innerHTML.slice(m[0].length);
    const nl = afterMarker.indexOf('\n');
    const titleHtml = (nl === -1 ? afterMarker : afterMarker.slice(0, nl)).trim();
    const leadHtml  = nl === -1 ? '' : afterMarker.slice(nl + 1).trim();

    const box = document.createElement('div');
    box.className = `callout callout-${TYPES[type].tone}`
        + (fold ? ' callout-foldable' : '')
        + (fold === '-' ? ' callout-collapsed' : '');
    box.dataset.callout = type;

    const head = document.createElement('div');
    head.className = 'callout-title';
    head.innerHTML = `<span class="callout-icon">${TYPES[type].icon}</span>`
        + `<span class="callout-title-text"></span>`
        + (fold ? `<span class="callout-fold">${FOLD_ICON}</span>` : '');
    // Obsidian's default title is the type name; an explicit title may contain inline markup.
    const titleEl = head.querySelector('.callout-title-text');
    if (titleHtml) titleEl.innerHTML = titleHtml;
    else titleEl.textContent = titleCase(type);
    box.appendChild(head);

    const body = document.createElement('div');
    body.className = 'callout-content';
    if (leadHtml) {
        const p = document.createElement('p');
        p.innerHTML = leadHtml;
        body.appendChild(p);
    }
    // Everything the blockquote held after its first paragraph — further paragraphs, lists,
    // code blocks, nested callouts — moves across as live nodes, so handlers and rendered
    // mermaid inside a callout survive.
    while (first.nextSibling) body.appendChild(first.nextSibling);
    box.appendChild(body);

    if (fold) {
        head.setAttribute('role', 'button');
        head.tabIndex = 0;
        const toggle = () => box.classList.toggle('callout-collapsed');
        head.addEventListener('click', toggle);
        head.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });
    }

    bq.replaceWith(box);
    return true;
};

/**
 * Convert every callout blockquote inside `root`.
 * Idempotent: a converted callout is no longer a <blockquote>, so repeat passes find nothing.
 */
export const renderCalloutsIn = (root) => {
    if (!root) return;
    // Document order, so an outer callout is converted before one nested inside it. The inner
    // <blockquote> is only re-parented by that, never detached, so its turn still works.
    for (const bq of root.querySelectorAll('blockquote')) convert(bq);
};

// Coalesces the burst of mutations one innerHTML assignment produces into a single pass.
let _queued = false;
export const scheduleCalloutRender = (root) => {
    if (_queued) return;
    _queued = true;
    requestAnimationFrame(() => {
        _queued = false;
        renderCalloutsIn(root);
    });
};
