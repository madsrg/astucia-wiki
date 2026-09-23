// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Copy-to-clipboard buttons on the code blocks of a rendered Markdown page.
 *
 * A fenced block is usually something to be *run* rather than read — a command, a config
 * snippet, a payload — and selecting one by hand is exactly where `white-space: pre-wrap`
 * works against the reader: a wrapped line looks like two, and a triple-click takes the
 * paragraph around it as often as the block.
 *
 * Runs on the rendered DOM rather than on the Markdown source, like modules/callouts and
 * for the same reason: marked has already escaped the fence body and attached the language
 * class, so this only has to restructure what it produced. Hooked into the MutationObserver
 * in modules/page_view (`setupDiagramObserver`), the same one mermaid and callouts use,
 * which covers page load, in-place refresh, the inline editor's preview and transcluded
 * content in one place.
 *
 * Two structural decisions worth keeping:
 *
 * - **The button is a sibling of the `<pre>`, inside a wrapper**, not a child of it. Inside,
 *   it would join the block's own text — `code.textContent` would carry the word "Copy",
 *   and so would a reader's manual selection of the block, which is the very thing this is
 *   here to make unnecessary. The wrapper is also what the button is positioned against:
 *   `pre` scrolls on mobile (`body.mobile #viewer-content pre`), and an absolutely
 *   positioned child of a scrolling box scrolls away with the content.
 * - **The text copied is `code.textContent`, never `innerHTML`** — marked HTML-escapes the
 *   fence body, so `innerHTML` hands back `&gt;` and `&amp;` instead of what the author
 *   typed. Same trap as the mermaid renderer's.
 *
 * Mermaid blocks are skipped: that `<pre>` is replaced by an SVG moments later, and a copy
 * button on a diagram would be left floating beside it.
 */
import { t } from '../i18n/index.js';
import { showToast } from '../core/utils.js';

const svg = (inner) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none"
     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">${inner}</svg>`;

const COPY_ICON = svg('<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>'
                    + '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>');
const DONE_ICON = svg('<polyline points="20 6 9 17 4 12"/>');

const FEEDBACK_MS = 1600;

/**
 * Write to the clipboard, with the `execCommand` path as a fallback.
 *
 * `navigator.clipboard` is undefined outside a secure context, and a wiki reached over
 * plain HTTP on a LAN hostname is an ordinary install here — so on exactly the deployments
 * where nothing else fails, a button whose only job is copying would never work at all.
 *
 * The reader's own selection is captured and put back: the selection toolbar
 * (modules/selection_actions) is driven by it, so copying a block must not clear what they
 * had highlighted as a side effect.
 */
const writeClipboard = async (text) => {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch { /* fall through — a denied permission or an insecure origin */ }
    }

    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');                 // no keyboard on iOS
    ta.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0';
    document.body.appendChild(ta);

    const sel  = document.getSelection();
    const prev = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    if (sel && prev) { sel.removeAllRanges(); sel.addRange(prev); }
    return ok;
};

const flash = (btn, label, icon) => {
    btn.innerHTML = icon;
    btn.title = label;
    btn.setAttribute('aria-label', label);
    clearTimeout(btn._resetTimer);
    btn._resetTimer = setTimeout(() => {
        btn.innerHTML = COPY_ICON;
        btn.title = t('code.copy');
        btn.setAttribute('aria-label', t('code.copy'));
        btn.classList.remove('code-copy-done');
    }, FEEDBACK_MS);
};

const attach = (pre) => {
    // Marked first, and in every branch: wrapping the block is itself a mutation of the
    // viewer, so the observer schedules one more pass — which has to find nothing to do,
    // or this wires the same block for ever.
    pre.dataset.copyReady = '1';
    const code = pre.querySelector(':scope > code');
    if (!code) return;                                       // not a fenced block
    if (code.classList.contains('language-mermaid')) return; // about to become an SVG

    const wrap = document.createElement('div');
    wrap.className = 'code-block';
    pre.before(wrap);
    wrap.appendChild(pre);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'code-copy-btn';
    btn.innerHTML = COPY_ICON;
    btn.title = t('code.copy');
    btn.setAttribute('aria-label', t('code.copy'));
    // The inline editor turns a click anywhere in a block into "edit this block", and skips
    // the event only for `a, button` — which this is, so it needs no stopPropagation. It
    // still must not submit anything it may one day sit inside, hence type="button".
    btn.addEventListener('click', async () => {
        const ok = await writeClipboard(code.textContent ?? '');
        if (ok) {
            btn.classList.add('code-copy-done');
            flash(btn, t('code.copied'), DONE_ICON);
        } else {
            // A toast, because the button cannot say why and the reader is about to paste
            // something they do not have.
            showToast(t('code.copy-failed'), 'error');
        }
    });
    wrap.appendChild(btn);
};

/**
 * Give every not-yet-wired code block inside `root` a copy button.
 * Idempotent: a wired `<pre>` carries `data-copy-ready`, so repeat passes find nothing.
 */
export const renderCodeCopyIn = (root) => {
    if (!root) return;
    for (const pre of root.querySelectorAll('pre:not([data-copy-ready])')) attach(pre);
};

/**
 * Coalesces the burst of mutations one innerHTML assignment produces into a single pass.
 *
 * A **microtask**, where modules/mermaid and modules/callouts use `requestAnimationFrame`.
 * Both work in a browser, but a frame callback never fires in headless Chrome driven with
 * `--dump-dom` — which is why neither of those two renderers can be tested there, and this
 * one can (tests/code_copy_ui.test.sh). It is also the more accurate schedule for work
 * this small: the pass is local DOM surgery with nothing to fetch, so it belongs in the
 * same microtask checkpoint as the mutation that caused it, before the block is painted at
 * a padding it is about to lose.
 */
let _queued = false;
export const scheduleCodeCopyRender = (root) => {
    if (_queued) return;
    _queued = true;
    queueMicrotask(() => {
        _queued = false;
        renderCodeCopyIn(root);
    });
};
