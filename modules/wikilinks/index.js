// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Wikilinks and embeds — `[[Page]]`, `[[Page|alias]]`, `[[Page#Heading]]`, `[[#Heading]]`,
 * `![[Page]]`, `![[Page#Heading]]`, `![[image.png|300]]`.
 *
 * Read-only support, on purpose. We render the syntax so a pasted Obsidian vault works, but the
 * editor keeps inserting `?pageid=ID&space=Name` links and nothing here rewrites a file: a
 * wikilink names its target instead of identifying it, so it cannot address another Space and
 * it breaks when the target is renamed. The capable format stays the one we write.
 *
 * **The source file is never modified.** Resolution happens in memory in the pass before
 * `marked.parse`, exactly like `{include:ID}` — the file keeps its `[[Page]]` for ever, which is
 * also what makes the page still work if it goes back to Obsidian.
 *
 * Within-space only. `[[Space:Page]]` would look like compatibility while being a dialect
 * Obsidian shows as broken, throwing away the portability that is the whole point.
 *
 * THE RESOLUTION RULES ARE DUPLICATED IN wikilinks.php, which backlinks and the knowledge graph
 * use. They must agree, or a link renders pointing at one page while its backlink is recorded
 * against another. Change both, and both test suites.
 */
import { state } from '../core/state.js';
import { mapOutsideCode } from '../core/md_text.js';
import { headingSlug } from '../toc/index.js';

// Optional "!" for an embed, then [[target#heading|alias]]. Target may be empty ([[#Heading]]).
const LINK_RE = /(!?)\[\[([^\]|#]*)(?:#([^\]|]*))?(?:\|([^\]]*))?\]\]/g;

const STRIP_EXTS  = ['md', 'drawio', 'list', 'chat', 'search', 'json'];
const IMAGE_EXTS  = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico'];

const esc = (s) => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

/** Same normalisation as wikilink_normalise() in wikilinks.php. */
const normalise = (target) => {
    let t = String(target || '').replace(/\\/g, '/').trim();
    t = t.replace(/^\.\//, '').replace(/^\/+|\/+$/g, '');
    const dot = t.lastIndexOf('.');
    if (dot > -1 && STRIP_EXTS.includes(t.slice(dot + 1).toLowerCase())) t = t.slice(0, dot);
    return t.toLowerCase();
};

// A page's display name: basename without its content extension.
const titleOf = (path) => (path || '').split('/').pop().replace(/\.(md|drawio|list|chat|search|json)$/, '');

const extOf = (name) => {
    const dot = String(name || '').lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
};

// ── index over the current space ─────────────────────────────────────────────

const collect = (items, out) => {
    for (const item of items || []) {
        if (item.path && item.id !== undefined && item.id !== null) out.push(item);
        if (item.children) collect(item.children, out);
    }
    return out;
};

/**
 * Built from `state.fullFileTree`, which the file tree has already loaded, so resolving a
 * whole page of wikilinks costs no requests at all — cheaper than `{include:ID}`, which makes
 * one round-trip per include.
 */
const buildIndex = () => {
    const byPath = new Map();     // 'folder/page' → item
    const byName = new Map();     // 'page'        → [item, …]
    for (const item of collect(state.fullFileTree, [])) {
        const path = normalise(item.path);
        if (!byPath.has(path)) byPath.set(path, item);
        const base = normalise(item.path.split('/').pop());
        if (!byName.has(base)) byName.set(base, []);
        byName.get(base).push(item);
    }
    return { byPath, byName };
};

// Obsidian prefers the shortest path for a duplicated basename: fewest segments, then shortest,
// then alphabetical so the choice never depends on tree order.
const shortest = (items) => [...items].sort((a, b) => {
    const da = (a.path.match(/\//g) || []).length;
    const db = (b.path.match(/\//g) || []).length;
    return da - db || a.path.length - b.path.length || a.path.localeCompare(b.path);
})[0];

const resolve = (idx, target) => {
    const key = normalise(target);
    if (!key) return null;
    const exact = idx.byPath.get(key);
    if (exact) return exact;
    const named = idx.byName.get(key);
    if (!named || !named.length) return null;
    return named.length === 1 ? named[0] : shortest(named);
};

// ── rendering ────────────────────────────────────────────────────────────────

const spaceQs = () => (state.currentSpace ? `&amp;space=${encodeURIComponent(state.currentSpace)}` : '');

const pageHref = (item, heading) =>
    `?pageid=${encodeURIComponent(item.id)}${spaceQs()}`
    + (heading ? `#${encodeURIComponent(headingSlug(heading))}` : '');

// Obsidian's own display rules: an alias wins; otherwise the link text, with " > heading"
// appended; a target-less [[#Heading]] shows the heading alone.
const labelFor = (target, heading, alias) => {
    if (alias) return alias;
    if (!target) return heading || '';
    return heading ? `${target} > ${heading}` : target;
};

const attachmentHref = (name, basePath) => {
    // Attachments live beside the page, in `<page>.uploads/`, so an embed resolves relative to
    // the page the text came from — which for transcluded content is the *included* page, not
    // the one being viewed.
    const page = basePath || state.currentPagePath || '';
    const path = `${page}.uploads/${name}`;
    const sp = state.currentSpace ? `&amp;space=${encodeURIComponent(state.currentSpace)}` : '';
    return `getfile.php?path=${encodeURIComponent(path)}${sp}`;
};

const renderEmbed = (idx, target, heading, alias, basePath) => {
    const ext = extOf(target);
    if (IMAGE_EXTS.includes(ext)) {
        // `![[image.png|300]]` — Obsidian reads the alias slot as a pixel width.
        const width = /^\d+$/.test(String(alias || '').trim()) ? ` width="${alias.trim()}"` : '';
        return `<img class="wikilink-embed" src="${attachmentHref(target, basePath)}" alt="${esc(target)}"${width}>`;
    }
    const item = resolve(idx, target);
    if (!item) return missing(`![[${target}${heading ? '#' + heading : ''}]]`);

    // Obsidian's embed chrome: the source page's name as a clickable heading, and a rule down
    // the left margin marking how far the embedded content extends. Without it a transclusion
    // reads as part of the host page, so it is unclear which words you can edit here.
    //
    // Built as a raw HTML block around an `{include:ID}` tag rather than in the DOM afterwards:
    // the blank lines make marked close the opening block, parse the transcluded Markdown
    // normally, then treat `</div>` as its own block, so the whole thing nests correctly and
    // the transclusion pass keeps its circular-reference guard.
    //
    // Only `![[…]]` gets this. A hand-written `{include:ID}` stays seamless, which is what it
    // is for — quoting a shared fragment as if it were part of this page.
    const label = alias || (heading ? `${titleOf(item.path)} > ${heading}` : titleOf(item.path));
    const tag = heading ? `{include:${item.id}#${headingSlug(heading)}}` : `{include:${item.id}}`;
    return `\n\n<div class="wiki-embed">\n`
         + `<a class="wiki-embed-title" href="${pageHref(item, heading)}">${esc(label)}</a>\n\n`
         + `${tag}\n\n</div>\n\n`;
};


const missing = (raw) =>
    `<span class="wikilink-missing" title="This page does not exist in this Space">${esc(raw)}</span>`;

/**
 * Replace every wikilink and embed in `content`. Runs before `processIncludes`, so a page embed
 * can be expressed as an `{include:ID}` tag and reuse that machinery.
 */
/**
 * @param {string} content Markdown source.
 * @param {string} [basePath] the page this text belongs to, for resolving `![[image.png]]`
 *        against the right `.uploads` folder. Defaults to the page being viewed; transclusion
 *        passes the included page's path so its own attachments resolve.
 */
export const processWikiLinks = (content, basePath) => {
    if (typeof content !== 'string' || !content.includes('[[')) return content;
    const idx = buildIndex();

    return mapOutsideCode(content, (text) => {
        if (!text.includes('[[')) return text;
        return text.replace(LINK_RE, (raw, bang, target, heading, alias) => {
            const tgt = (target || '').trim();
            const hd  = (heading || '').trim();
            const al  = alias === undefined ? '' : alias.trim();

            if (bang) return renderEmbed(idx, tgt, hd, al, basePath);

            // Same-page anchor: no target to resolve, just a heading on this page.
            if (!tgt) {
                if (!hd) return raw;
                return `<a class="wikilink wikilink-anchor" href="#${encodeURIComponent(headingSlug(hd))}">`
                     + `${esc(labelFor('', hd, al))}</a>`;
            }
            const item = resolve(idx, tgt);
            if (!item) return missing(raw);
            return `<a class="wikilink" href="${pageHref(item, hd)}">${esc(labelFor(tgt, hd, al))}</a>`;
        });
    });
};

/** Exposed for tests and for the rename flow's preview. */
export const resolveTarget = (target) => resolve(buildIndex(), target);

// ── rename support ───────────────────────────────────────────────────────────

/**
 * After a page is renamed or moved within its Space, offer to update the wikilinks that named
 * it. Silent when there are none, so an ordinary rename is unchanged.
 *
 * This is the only path that rewrites wikilink source text, and it always asks first: the
 * alternative to asking is either silently editing pages the user did not open (Obsidian's
 * behaviour) or silently leaving links broken. Counting first means the question can say how
 * much is at stake.
 *
 * Not attempted for a cross-space move — a wikilink cannot address another Space, so there is
 * no target to rewrite to — nor for a folder rename, where every page inside moved at once.
 */
export const offerRetarget = async (oldPath, newPath) => {
    if (!oldPath || !newPath || oldPath === newPath) return;
    const { api } = await import('../core/api.js');
    const { confirmModal, showToast } = await import('../core/utils.js');
    const { t } = await import('../i18n/index.js');

    const found = await api.call('retarget_wikilinks',
        { old_path: oldPath, new_path: newPath }, 'POST');
    if (!found.success || !found.links) return;

    const vars = { links: found.links, pages: found.pages, name: newPath.split('/').pop() };
    if (!await confirmModal(t('wikilinks.retarget-title'), {
        message: t('wikilinks.retarget-msg', vars),
        confirmLabel: t('wikilinks.retarget-confirm'),
    })) return;

    const done = await api.call('retarget_wikilinks',
        { old_path: oldPath, new_path: newPath, apply: 1 }, 'POST');
    if (done.success) {
        showToast(t('wikilinks.retargeted', { links: done.links, pages: done.pages }), 'success');
    } else {
        showToast(t('wikilinks.retarget-failed'), 'error');
    }
};
