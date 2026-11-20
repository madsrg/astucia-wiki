// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Confine an HTML page's own CSS to the page.
 *
 * A page with `format: html` may carry a `<style>` block — a page generator's template gives
 * every page it writes an editable copy of the wk-* styles. A `<style>` in the document applies
 * to the whole window, so `h1 { color: red }` there would also restyle the wiki's own header.
 * scopeStyles() rewrites each `<style>` inside `container` so every rule reaches only what is
 * inside `scope` (a selector for the container — `#viewer-content`, the Preview's box):
 *
 *  - each selector of a rule is prefixed: `.wk-card, h1` → `#viewer-content .wk-card, #viewer-content h1`;
 *  - a selector that starts at the document — `html`, `body`, `:root` — starts at the scope
 *    instead, so `body { … }` styles the page, not the wiki;
 *  - `@media`, `@supports`, `@container` and `@layer` blocks are scoped inside; `@keyframes`
 *    and `@font-face` are left as they are (they select nothing); nested rules keep their
 *    relative selectors, which their scoped parent already confines.
 *
 * Parsed by the browser's own CSS parser (a constructed stylesheet), not by string surgery, so
 * strings, comments and commas inside :is(…) do not confuse it. Each block is done once
 * (`data-scoped`). Only HTML pages are scoped: a `<style>` in a Markdown page applies to the
 * window as it always has.
 */

/** Split a selector list on its top-level commas — not those inside (…) or […]. */
const splitSelectors = (list) => {
    const out = [];
    let depth = 0, quote = '', cur = '';
    for (const ch of list) {
        if (quote) { cur += ch; if (ch === quote) quote = ''; continue; }
        if (ch === '"' || ch === "'") { quote = ch; cur += ch; continue; }
        if (ch === '(' || ch === '[') depth++;
        if (ch === ')' || ch === ']') depth--;
        if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; continue; }
        cur += ch;
    }
    if (cur.trim()) out.push(cur.trim());
    return out;
};

const scopeSelector = (sel, scope) => {
    // A selector anchored at the document is anchored at the scope instead.
    const m = /^(?::root|html|body)(?![\w-])\s*(.*)$/i.exec(sel);
    if (m) {
        let rest = m[1];
        // `html body .x` — both document levels collapse into the one scope.
        rest = rest.replace(/^(?:>\s*)?body(?![\w-])\s*/i, '');
        return rest ? `${scope} ${rest}` : scope;
    }
    return `${scope} ${sel}`;
};

const scopeRules = (rules, scope) => {
    const out = [];
    for (const rule of rules) {
        if (rule instanceof CSSStyleRule) {
            const selector = splitSelectors(rule.selectorText).map(s => scopeSelector(s, scope)).join(', ');
            // cssText keeps the declarations and any nested rules exactly as parsed.
            const body = rule.cssText.slice(rule.cssText.indexOf('{'));
            out.push(`${selector} ${body}`);
        } else if (rule.cssRules && (rule instanceof CSSMediaRule || rule instanceof CSSSupportsRule
                   || (typeof CSSContainerRule !== 'undefined' && rule instanceof CSSContainerRule)
                   || (typeof CSSLayerBlockRule !== 'undefined' && rule instanceof CSSLayerBlockRule))) {
            const head = rule.cssText.slice(0, rule.cssText.indexOf('{'));
            out.push(`${head}{ ${scopeRules(rule.cssRules, scope).join(' ')} }`);
        } else {
            out.push(rule.cssText);          // @keyframes, @font-face, @layer a, b; …
        }
    }
    return out;
};

/** Scope every `<style>` inside `container` to `scope`. Safe to call more than once. */
export const scopeStyles = (container, scope) => {
    if (!container || typeof CSSStyleSheet === 'undefined') return;
    container.querySelectorAll('style:not([data-scoped])').forEach(styleEl => {
        let sheet;
        try {
            sheet = new CSSStyleSheet();
            sheet.replaceSync(styleEl.textContent || '');
        } catch {
            styleEl.textContent = '';        // unparseable: better no styles than the window's
            styleEl.dataset.scoped = '1';
            return;
        }
        styleEl.textContent = scopeRules(sheet.cssRules, scope).join('\n');
        styleEl.dataset.scoped = '1';
    });
};
