// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * What the editor's formatting controls insert on an HTML page (`format: html` in its front
 * matter — a page generator's HTML template and the pages it writes).
 *
 * Such a page is shown as it is, with no Markdown pass and none of the wiki's tag passes, so
 * `**bold**`, `[text](url)` or `{toc}` would appear on it literally. The toolbar, the mobile
 * toolbar, the Alt shortcuts, the link and image dialogs and a pasted image therefore ask
 * `isHtmlPage()` and insert these instead. Controls with no HTML meaning here — the checklist,
 * `{toc}`, and everything in Insert but Image — carry `tb-md-only` and are hidden by CSS under
 * `body.page-html`; Alt+P ({include}) does nothing. `{filename}` and `{lastUpdated}` are
 * still filled in on an HTML page, so Auto text keeps them and Alt+N still inserts one.
 *
 * Text the author typed into a dialog (a link's text, an image's name) is escaped; text they
 * selected in the editor is wrapped as it is, since it is already HTML — except by Code, whose
 * whole point is to show the characters.
 */
import { getEditor, insertMarkdown, insertBlock } from './editor.js';

/** Is the page being edited an HTML page? `body.page-html` is set by modules/page_view. */
export const isHtmlPage = () => document.body.classList.contains('page-html');

export const escText = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
export const escAttr = (s) => escText(s).replace(/"/g, '&quot;');

/** The current line as an <hN>: an existing heading (HTML or a stray `#`) changes level. */
export const heading = (level) => {
    const ed = getEditor();
    if (!ed) return;
    const v = ed.value;
    const pos = ed.selectionStart;
    const ls = v.lastIndexOf('\n', pos - 1) + 1;
    let le = v.indexOf('\n', pos);
    if (le < 0) le = v.length;
    const line = v.slice(ls, le);
    const indent = line.match(/^\s*/)[0];
    let body = line.slice(indent.length);
    let attrs = '';
    const wrapped = body.match(/^<h[1-6]((?:\s[^>]*)?)>([\s\S]*?)<\/h[1-6]>\s*$/i);
    if (wrapped) { attrs = wrapped[1]; body = wrapped[2]; }
    else body = body.replace(/^#{1,6} /, '');
    const open = `<h${level}${attrs}>`;
    ed.setRangeText(`${indent}${open}${body}</h${level}>`, ls, le, 'end');
    // Typing on: inside an empty heading, after the text of one that has some.
    const caret = ls + indent.length + open.length + body.length;
    ed.setSelectionRange(caret, caret);
    ed.focus();
    ed.dispatchEvent(new Event('input'));
};

/** Wrap the selection in an inline element (caret inside it when nothing is selected). */
export const inline = (tag) => insertMarkdown(`<${tag}>`, `</${tag}>`);

/** <code>, or <pre><code> for a selection of several lines — its text escaped. */
export const code = () => {
    const ed = getEditor();
    if (!ed) return;
    const s = ed.selectionStart, e = ed.selectionEnd;
    const sel = ed.value.slice(s, e);
    if (sel) ed.setRangeText(escText(sel), s, e, 'select');
    if (sel.includes('\n')) insertBlock('<pre><code>', '</code></pre>');
    else insertMarkdown('<code>', '</code>');
};

/** A list: each selected line an item, or one empty item to type into. */
export const list = (tag) => {
    const ed = getEditor();
    if (!ed) return;
    const s = ed.selectionStart, e = ed.selectionEnd;
    const lines = ed.value.slice(s, e).split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) { insertBlock(`<${tag}>\n  <li>`, `</li>\n</${tag}>`); return; }
    ed.setRangeText(lines.map(l => `  <li>${l}</li>`).join('\n'), s, e, 'select');
    insertBlock(`<${tag}>\n`, `\n</${tag}>`);
};

export const quote = () => insertBlock('<blockquote>', '</blockquote>');
export const hr = () => insertBlock('<hr>');
export const comment = () => insertMarkdown('<!-- ', ' -->');

// Page content, like the Markdown table it stands in for (hotkeys.json alt+t).
export const table = () => insertBlock(
    '<table>\n  <thead>\n    <tr><th>Syntax</th><th>Description</th></tr>\n  </thead>\n'
    + '  <tbody>\n    <tr><td>Header</td><td>Title</td></tr>\n    <tr><td>Paragraph</td><td>Text</td></tr>\n'
    + '  </tbody>\n</table>');

/** A link. `text` is escaped unless it came from the editor's selection (`selected`). */
export const link = (href, text, selected = false) =>
    insertMarkdown(`<a href="${escAttr(href)}">${selected ? text : escText(text)}</a>`);

export const image = (src, alt, w = '', h = '') =>
    insertMarkdown(`<img src="${escAttr(src)}" alt="${escAttr(alt)}"`
        + (w ? ` width="${escAttr(w)}"` : '') + (h ? ` height="${escAttr(h)}"` : '') + '>');

/** The Alt shortcuts on an HTML page, by key. Keys absent here (p: {include}) insert nothing. */
export const HTML_HOTKEYS = {
    1: () => heading(1), 2: () => heading(2), 3: () => heading(3),
    b: () => inline('strong'), i: () => inline('em'), c: code, t: table, k: comment,
    n: () => insertMarkdown('{filename}'),
};
