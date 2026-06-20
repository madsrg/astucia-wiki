import { t } from '../i18n/index.js';

// ── Heading extraction ────────────────────────────────────────────────────────

const FENCE_RE   = /^```/;
const HEADING_RE = /^(#{1,6})\s+(.+)$/;

const stripInlineMarkdown = (text) =>
    text.replace(/\*\*?([^*]+)\*\*?/g, '$1')
        .replace(/__?([^_]+)__?/g, '$1')
        .replace(/`([^`]+)`/g, '$1')
        .trim();

const makeSlug = (text) =>
    text.toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '') || 'heading';

export const extractHeadings = (markdown, maxLevels = 6) => {
    const headings = [];
    const counts   = {};
    let inCode     = false;

    for (const line of markdown.split('\n')) {
        if (FENCE_RE.test(line.trimStart())) { inCode = !inCode; continue; }
        if (inCode) continue;
        const m = HEADING_RE.exec(line);
        if (!m) continue;
        const level = m[1].length;
        if (level > maxLevels) continue;
        const text = stripInlineMarkdown(m[2]);
        const slug = makeSlug(text);
        const n    = (counts[slug] = (counts[slug] || 0) + 1);
        headings.push({ level, text, id: n > 1 ? `${slug}-${n}` : slug });
    }
    return headings;
};

// ── Inline {toc} replacement (runs before marked.parse) ──────────────────────

export const processTocTag = (content, allHeadings) => {
    return content.replace(/\{toc(?:\s+maxLevels:(\d+))?\}/g, (_, maxStr) => {
        const max      = maxStr ? parseInt(maxStr, 10) : 6;
        const filtered = allHeadings.filter(h => h.level <= max);
        if (!filtered.length) return '';
        const minLevel = Math.min(...filtered.map(h => h.level));
        let html = '<div class="toc-inline"><nav>';
        for (const h of filtered) {
            html += `<a class="toc-inline-item toc-indent-${h.level - minLevel}" href="#${h.id}">${h.text}</a>`;
        }
        html += '</nav></div>';
        return '\n\n' + html + '\n\n';
    });
};

// ── Apply IDs to rendered DOM headings, wire inline ToC clicks ───────────────

export const addHeadingIds = (container, headings) => {
    const els = Array.from(container.querySelectorAll('h1,h2,h3,h4,h5,h6'));
    headings.forEach((h, i) => { if (els[i]) els[i].id = h.id; });

    // Intercept inline ToC anchor clicks — scroll inside viewer, not window
    container.querySelectorAll('.toc-inline a[href^="#"]').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const id = a.getAttribute('href').slice(1);
            container.querySelector(`#${CSS.escape(id)}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
};

// ── Overlay panel ─────────────────────────────────────────────────────────────

let _panel  = null;
let _tocBtn = null;
let _nav    = null;

export const closeTocPanel = () => {
    _panel?.classList.remove('open');
    if (_tocBtn) {
        _tocBtn.classList.remove('toc-btn-active');
        _tocBtn.title = t('toc.show-btn');
    }
};

export const updateTocPanel = (headings, viewerContent) => {
    if (!_nav || !_tocBtn) return;
    _nav.innerHTML = '';
    closeTocPanel();

    const has = headings.length > 0;
    _tocBtn.classList.toggle('hidden', !has);
    if (!has) return;

    const minLevel = Math.min(...headings.map(h => h.level));
    for (const h of headings) {
        const a = document.createElement('a');
        a.className = `toc-panel-item toc-indent-${h.level - minLevel}`;
        a.href = '#';
        a.textContent = h.text;
        a.addEventListener('click', (e) => {
            e.preventDefault();
            viewerContent?.querySelector(`#${CSS.escape(h.id)}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        _nav.appendChild(a);
    }
};

const alignPanel = () => {
    if (!_panel) return;
    const sidebar = document.querySelector('.sidebar');
    const panes   = document.querySelector('.sidebar-panes');
    const app     = document.querySelector('.app-container');
    if (!sidebar || !panes || !app) return;
    const sr = sidebar.getBoundingClientRect();
    const pr = panes.getBoundingClientRect();
    const ar = app.getBoundingClientRect();
    _panel.style.width  = Math.round(sr.width)              + 'px';
    _panel.style.top    = Math.round(pr.top    - ar.top)    + 'px';
    _panel.style.bottom = Math.round(ar.bottom - pr.bottom) + 'px';
};

export const init = () => {
    _panel  = document.getElementById('toc-panel');
    _tocBtn = document.getElementById('toc-btn');
    _nav    = document.getElementById('toc-panel-nav');
    if (!_panel || !_tocBtn) return;

    alignPanel();
    window.addEventListener('resize', alignPanel);

    _tocBtn.addEventListener('click', () => {
        alignPanel();
        const isOpen = _panel.classList.toggle('open');
        _tocBtn.classList.toggle('toc-btn-active', isOpen);
        _tocBtn.title = t(isOpen ? 'toc.hide-btn' : 'toc.show-btn');
    });

    document.getElementById('toc-panel-close-btn')?.addEventListener('click', closeTocPanel);
};
