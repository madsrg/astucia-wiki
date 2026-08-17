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
                });
                return mermaid;
            })
            .catch(err => { _lib = null; throw err; });   // let a later attempt retry
    }
    return _lib;
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
            showError(pre, err?.message || String(err));
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
