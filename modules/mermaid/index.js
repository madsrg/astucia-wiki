// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { t } from '../i18n/index.js';

// Renders ```mermaid fenced blocks in Markdown pages — sequence diagrams, flowcharts,
// state, ER and gantt charts — as inline SVG.
//
// Kept as fenced blocks in ordinary .md pages rather than a separate content type: the
// source then lives in the page it documents (so it is in that page's git history and
// its text is searchable via the FTS index), edit mode is already a plain textarea, and
// {include:ID} — which runs before marked.parse — makes a page holding just a diagram
// embeddable anywhere, which is the only thing a dedicated content type would have added.

const CDN = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';

let _lib = null;    // in-flight or resolved import
let _seq = 0;       // mermaid needs a unique DOM id per render
let _queued = false;

// Loaded on demand, not up front: mermaid is ~1 MB, and most pages have no diagram.
const loadMermaid = () => {
    if (!_lib) {
        _lib = import(/* @vite-ignore */ CDN)
            .then(mod => {
                const mermaid = mod.default;
                mermaid.initialize({
                    startOnLoad:   false,
                    // Page content is user-authored, so labels must not become live HTML.
                    securityLevel: 'strict',
                    theme:         'default',
                    fontFamily:    'inherit',
                    // Without this, a diagram with a typo makes mermaid render its own
                    // "Syntax error in text" graphic into the temporary container it
                    // appends to <body> — and leave it there, so the whole page grows a
                    // bomb SVG and a leaked <style> block at the bottom, detached from
                    // the block that caused it. Suppressed, render() simply cleans up and
                    // rethrows, which is what showError() below turns into an inline
                    // message beside the source.
                    suppressErrorRendering: true,
                });
                return mermaid;
            })
            .catch(err => { _lib = null; throw err; });   // let a later attempt retry
    }
    return _lib;
};

/**
 * Strip the diagram source out of a mermaid error message.
 *
 * Mermaid quotes the source back at you in two shapes — "…for text:" followed by the
 * whole block, or a quoted snippet with a caret ruler under the offending token. Both
 * are redundant here: the source is left on screen directly below this box, so the echo
 * only doubles the page's noise. The diagnosis (which line, what it expected, what it
 * got) is kept as mermaid wrote it.
 */
const CARET_RULER = /^\s*-*\^\s*$/;

const cleanMessage = (raw) => {
    const full = String(raw ?? '').trim();
    // "No diagram type detected matching given configuration for text: <whole block>"
    const lines = full.replace(/\s*for text:[\s\S]*$/, '').split('\n');
    // "Parse error on line 5:" / "...quoted source..." / "-------^" / "Expecting …, got …"
    const kept = lines.filter((line, i) => {
        if (CARET_RULER.test(line)) return false;                 // the ruler itself
        const next = lines[i + 1];                                // and the line it points at
        return next === undefined || !CARET_RULER.test(next);
    });
    return kept.join(' ').replace(/\s+/g, ' ').trim() || full;
};

const showError = (pre, message) => {
    // The source stays on screen: a diagram with a typo is then fixable in place instead
    // of the page just losing content.
    const box = document.createElement('div');
    box.className = 'mermaid-error';
    box.textContent = `${t('mermaid.error')} ${message}`;
    pre.classList.add('mermaid-error-source');
    pre.before(box);
};

/**
 * Render every not-yet-rendered mermaid block inside `root`.
 * Idempotent — rendered and failed blocks are marked, so repeat calls are no-ops.
 */
export const renderMermaidIn = async (root) => {
    if (!root) return;
    const blocks = [...root.querySelectorAll('pre > code.language-mermaid:not([data-mermaid-done])')];
    if (!blocks.length) return;

    let mermaid;
    try {
        mermaid = await loadMermaid();
    } catch {
        blocks.forEach(code => {
            code.dataset.mermaidDone = 'error';
            showError(code.closest('pre'), t('mermaid.unavailable'));
        });
        return;
    }

    for (const code of blocks) {
        code.dataset.mermaidDone = '1';
        const pre = code.closest('pre');
        if (!pre) continue;
        try {
            const { svg } = await mermaid.render(`wiki-mermaid-${++_seq}`, code.textContent || '');
            const wrap = document.createElement('div');
            wrap.className = 'mermaid-diagram';
            wrap.innerHTML = svg;
            pre.replaceWith(wrap);
        } catch (err) {
            showError(pre, cleanMessage(err?.message || err));
        }
    }
};

// Coalesces the burst of mutations a single innerHTML assignment produces into one pass.
export const scheduleMermaidRender = (root) => {
    if (_queued) return;
    _queued = true;
    requestAnimationFrame(() => {
        _queued = false;
        renderMermaidIn(root);
    });
};
