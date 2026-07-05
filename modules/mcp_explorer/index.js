/**
 * mcp_explorer/index.js — editor+ MCP Tool Explorer (lightbox).
 *
 * Chat-like layout: invocation results stack above, controls (server → tool
 * filter → tool → arguments → Invoke) below. Deterministic tools/list +
 * tools/call via the mcp_list_tools / mcp_invoke_tool actions. No LLM.
 *
 * Each result carries a toolbar: metadata (latency · size · lines), a Raw⇄JSON
 * toggle, and Copy / Download / Save-as-page actions. Query turns are clickable
 * to re-run a past invocation, and the last server/tool are remembered.
 */
import { api } from '../core/api.js';
import { t } from '../i18n/index.js';
import { getMcpServers } from '../core/mcp_servers.js';
import { showToast } from '../core/utils.js';
import { refreshFileTree } from '../file_tree/index.js';
import { state } from '../core/state.js';

const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

const TOOL_FILTER_MIN = 10;   // only surface the filter box past this many tools

let _servers    = [];      // [{name, slug, wiki_native}]
let _tools      = [];      // tools for the selected server [{name, description, params}]
let _lastServer = null;    // slug of last-selected server (remembered across opens)
let _lastTool   = null;    // name of last-selected tool

const lb        = () => document.getElementById('mcp-explorer-lightbox');
const serverSel = () => document.getElementById('mcp-explorer-server');
const toolSel   = () => document.getElementById('mcp-explorer-tool');
const filterEl  = () => document.getElementById('mcp-explorer-tool-filter');
const helpEl    = () => document.getElementById('mcp-explorer-help');
const argsEl    = () => document.getElementById('mcp-explorer-args');
const resultsEl = () => document.getElementById('mcp-explorer-results');

const scrollDown = () => { const r = resultsEl(); if (r) r.scrollTop = r.scrollHeight; };

// The filter box only earns its place once the tool list is long; below the
// threshold it just clutters the row, so hide it (and drop any stale query).
const syncFilterVisibility = () => {
    const f = filterEl();
    if (!f) return;
    const show = _tools.length > TOOL_FILTER_MIN;
    f.classList.toggle('hidden', !show);
    if (!show) f.value = '';
};

// ── Small helpers ──────────────────────────────────────────────────────────────
const humanSize = (n) =>
    n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`;

const safeName = (s) => (s || 'result').replace(/[^a-z0-9._-]+/gi, '_').replace(/^_+|_+$/g, '') || 'result';

const download = (filename, content, mime) => {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
};

// Pick a code fence longer than any backtick run in the content, so a payload
// that itself contains ``` fences can't break out of the wrapping block.
const fenceFor = (s) => {
    const max = (s.match(/`+/g) || []).reduce((m, r) => Math.max(m, r.length), 0);
    return '`'.repeat(Math.max(3, max + 1));
};

// Path prefix suggested for new pages: mirror new_items — alongside the active
// tree selection (inside it when a folder is selected), else at the root. Used
// only to preselect a default in the folder picker.
const creationPath = () => {
    const el = document.querySelector('.file-item.active > .file-item-content');
    if (!el) return '';
    const p = el.dataset.path, type = el.dataset.type;
    if (type === 'folder') return p + '/';
    const parts = p.split('/'); parts.pop();
    return parts.length ? parts.join('/') + '/' : '';
};

// Flatten the nested `list` result into a sorted list of folder paths.
const flattenFolders = (items) => {
    let out = [];
    (items || []).forEach(it => {
        if (it.type !== 'folder') return;
        out.push(it.path);
        if (it.children?.length) out = out.concat(flattenFolders(it.children));
    });
    return out;
};

// ── Tool controls ──────────────────────────────────────────────────────────────
// Render both the fixed-height description panel and the dynamic per-argument
// input fields for the selected tool. The help box stays a fixed height (no
// bounce); the fields below grow with the parameter count, pushing results up.
const renderTool = () => {
    const tool = _tools.find(tl => tl.name === toolSel().value);
    const help = helpEl();
    const args = argsEl();
    args.innerHTML = '';
    if (!tool) { help.innerHTML = ''; return; }
    _lastTool = tool.name;

    help.innerHTML = tool.description
        ? `<p class="mcp-help-desc">${esc(tool.description)}</p>`
        : `<p class="form-hint" style="margin:0">${t('explorer.no-desc')}</p>`;

    let props = tool.params?.properties || {};
    if (Array.isArray(props)) props = {}; // PHP empty-object edge
    const required = tool.params?.required || [];
    const names = Object.keys(props);
    if (!names.length) {
        args.innerHTML = `<p class="form-hint" style="margin:0">${t('explorer.no-args')}</p>`;
        return;
    }
    names.forEach(name => {
        const spec  = props[name] || {};
        const isReq = required.includes(name);
        const type  = spec.type || 'string';
        const row = document.createElement('div');
        row.className = 'mcp-arg-row';
        row.innerHTML = `<label>${esc(name)}${isReq ? ' <span class="mcp-arg-req">*</span>' : ''} <span class="mcp-arg-type">${esc(type)}</span></label>`
            + `<input type="text" class="form-control mcp-arg-input" data-arg="${esc(name)}" data-type="${esc(type)}" placeholder="${esc(spec.description || '')}">`;
        args.appendChild(row);
    });
};

// Populate the tool <select> from _tools, honouring the filter box, and restore
// the remembered tool when it's still in the (filtered) list.
const renderToolOptions = () => {
    const q = (filterEl()?.value || '').trim().toLowerCase();
    const matches = _tools.filter(tl =>
        !q || tl.name.toLowerCase().includes(q) || (tl.description || '').toLowerCase().includes(q));
    toolSel().innerHTML = matches.map(tl => `<option value="${esc(tl.name)}">${esc(tl.name)}</option>`).join('');
    if (_lastTool && matches.some(tl => tl.name === _lastTool)) toolSel().value = _lastTool;
    renderTool();
};

const loadTools = async () => {
    const slug = serverSel().value;
    _lastServer = slug;
    toolSel().innerHTML = `<option>…</option>`;
    helpEl().innerHTML = '';
    argsEl().innerHTML = '';
    const res = await api.call('mcp_list_tools', { source: slug });
    _tools = res.success ? (res.tools || []) : [];
    syncFilterVisibility();
    if (!res.success) {
        toolSel().innerHTML = '';
        resultsEl().insertAdjacentHTML('beforeend', `<div class="adv-turn adv-turn-result"><p class="adv-error">${esc(res.message || 'Failed to list tools.')}</p></div>`);
        return;
    }
    renderToolOptions();
};

// ── Result rendering ───────────────────────────────────────────────────────────
// Build a tool-result turn with a per-result toolbar: a metadata line (latency ·
// size · lines), a Raw⇄JSON toggle (only when the payload parses as JSON,
// defaulting to the formatted view), and Copy / Download / Save-as-page actions.
// Uses textContent, so the payload is never HTML-interpreted (no injection risk).
const buildResultTurn = (text, meta, ms) => {
    const out = document.createElement('div');
    out.className = 'adv-turn adv-turn-result';

    const raw = text || '';
    const trimmed = raw.trim();
    let pretty = null;
    if (trimmed && (trimmed[0] === '{' || trimmed[0] === '[')) {
        try { pretty = JSON.stringify(JSON.parse(trimmed), null, 2); } catch { /* not JSON */ }
    }
    const isJson = pretty !== null;

    const bar = document.createElement('div');
    bar.className = 'mcp-result-bar';

    const lines = raw ? raw.split('\n').length : 0;
    const metaLine = document.createElement('span');
    metaLine.className = 'mcp-result-meta';
    metaLine.textContent = `${ms} ms · ${humanSize(new Blob([raw]).size)} · ${lines} ${t('explorer.lines')}`;
    bar.appendChild(metaLine);

    const actions = document.createElement('span');
    actions.className = 'mcp-result-actions';

    const pre = document.createElement('pre');
    let showPretty = isJson;                          // default to formatted when available
    const paint = () => { pre.textContent = showPretty ? pretty : raw; };
    const current = () => (showPretty ? pretty : raw);

    const mkBtn = (label, handler) => {
        const b = document.createElement('button');
        b.className = 'btn btn-secondary mcp-result-btn';
        b.textContent = label;
        b.addEventListener('click', handler);
        actions.appendChild(b);
        return b;
    };

    if (isJson) {
        let toggle;
        const relabel = () => { toggle.textContent = showPretty ? t('explorer.view-raw') : t('explorer.view-json'); };
        toggle = mkBtn('', () => { showPretty = !showPretty; paint(); relabel(); });
        relabel();
    }

    const copyBtn = mkBtn(t('explorer.copy'), async () => {
        const payload = current();
        try {
            await navigator.clipboard.writeText(payload);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = payload; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch { /* ignore */ }
            ta.remove();
        }
        copyBtn.textContent = t('explorer.copied');
        setTimeout(() => { copyBtn.textContent = t('explorer.copy'); }, 1500);
    });

    mkBtn(t('explorer.download'), () => {
        download(`${safeName(meta.tool)}-result.${isJson ? 'json' : 'txt'}`,
                 current(), isJson ? 'application/json' : 'text/plain');
    });

    mkBtn(t('explorer.save-page'), () => saveAsPage(meta, raw, pretty));

    bar.appendChild(actions);

    const wrap = document.createElement('div');
    wrap.className = 'adv-text-result';
    wrap.appendChild(pre);

    paint();
    out.appendChild(bar);
    out.appendChild(wrap);
    return out;
};

// ── Save result as a Markdown page ─────────────────────────────────────────────
const buildMarkdown = (title, meta, raw, pretty) => {
    const body  = pretty !== null ? pretty : raw;
    const fence = fenceFor(body);
    const lang  = pretty !== null ? 'json' : '';
    return `# ${title}\n\n`
        + `> ${t('explorer.md-note')}\n\n`
        + `- **${t('explorer.md-server')}:** ${meta.serverName}\n`
        + `- **${t('explorer.md-tool')}:** \`${meta.tool}\`\n`
        + `- **${t('explorer.md-args')}:** \`${JSON.stringify(meta.args)}\`\n`
        + `- **${t('explorer.md-retrieved')}:** ${new Date().toLocaleString()}\n\n`
        + `${fence}${lang}\n${body}\n${fence}\n`;
};

// Modal that lets the user choose a destination folder (from this space's tree)
// and a page name. Resolves to the chosen relative path, or null on cancel.
const chooseSaveTarget = async (defaultName) => {
    const overlay   = document.getElementById('mcp-save-lightbox');
    const folderSel = document.getElementById('mcp-save-folder');
    const nameInput = document.getElementById('mcp-save-name');
    const okBtn     = document.getElementById('mcp-save-confirm');
    const cancelBtn = document.getElementById('mcp-save-close');

    const res = await api.call('list');
    const folders = res.success ? [...new Set(flattenFolders(res.data))].sort() : [];
    folderSel.innerHTML = `<option value="">${esc(t('explorer.save-root'))}</option>`
        + folders.map(f => `<option value="${esc(f)}">${esc(f)}</option>`).join('');
    const def = creationPath().replace(/\/$/, '');
    folderSel.value = folders.includes(def) ? def : '';
    nameInput.value = defaultName;

    overlay.classList.remove('hidden');
    setTimeout(() => { nameInput.focus(); nameInput.select(); }, 50);

    return new Promise((resolve) => {
        const cleanup = (val) => {
            overlay.classList.add('hidden');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onBackdrop);
            nameInput.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const onOk = () => {
            let name = nameInput.value.trim();
            if (!name) { nameInput.focus(); return; }
            if (!name.endsWith('.md')) name += '.md';
            const folder = folderSel.value;
            cleanup((folder ? folder + '/' : '') + name);
        };
        const onCancel   = () => cleanup(null);
        const onBackdrop = (e) => { if (e.target === overlay) onCancel(); };
        const onKey      = (e) => {
            if (e.key === 'Enter')  { e.preventDefault(); onOk(); }
            if (e.key === 'Escape') { e.stopPropagation(); onCancel(); }
        };
        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        overlay.addEventListener('click', onBackdrop);
        nameInput.addEventListener('keydown', onKey);
    });
};

const saveAsPage = async (meta, raw, pretty) => {
    const path = await chooseSaveTarget(`${meta.tool} result`);
    if (!path) return;

    const created = await api.call('create_file', { path }, 'POST');
    if (!created.success) { showToast(created.message || t('explorer.save-failed'), 'error'); return; }

    const title   = path.split('/').pop().replace(/\.md$/, '');
    const content = buildMarkdown(title, meta, raw, pretty);
    const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
    try {
        const r = await fetch(`api.php?action=save&file=${encodeURIComponent(path)}${spaceQs}`, {
            method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: content,
        });
        const j = await r.json();
        if (!j.success) throw new Error(j.message);
        showToast(t('explorer.saved'), 'success');
        await refreshFileTree();
    } catch (e) {
        showToast(e.message || t('explorer.save-failed'), 'error');
    }
};

// ── Re-run a past invocation ───────────────────────────────────────────────────
const restoreInvocation = async (meta) => {
    const hasServer = [...serverSel().options].some(o => o.value === meta.slug);
    if (hasServer && serverSel().value !== meta.slug) {
        serverSel().value = meta.slug;
        await loadTools();
    }
    if (filterEl()) filterEl().value = '';
    _lastTool = meta.tool;
    renderToolOptions();
    if ([...toolSel().options].some(o => o.value === meta.tool)) {
        toolSel().value = meta.tool;
        renderTool();
    }
    Object.entries(meta.args || {}).forEach(([k, v]) => {
        const inp = argsEl().querySelector(`.mcp-arg-input[data-arg="${(window.CSS && CSS.escape) ? CSS.escape(k) : k}"]`);
        if (inp) inp.value = (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v);
    });
    argsEl().querySelector('.mcp-arg-input')?.focus();
};

// ── Invoke ───────────────────────────────────────────────────────────────────────
const invoke = async () => {
    const slug = serverSel().value;
    const tool = toolSel().value;
    if (!tool) return;
    const args = {};
    argsEl().querySelectorAll('.mcp-arg-input').forEach(inp => {
        const v = inp.value.trim();
        if (v === '') return;
        const type = inp.dataset.type;
        if (type === 'number' || type === 'integer') args[inp.dataset.arg] = Number(v);
        else if (type === 'boolean') args[inp.dataset.arg] = (v === 'true' || v === '1');
        else if (type === 'array' || type === 'object') { try { args[inp.dataset.arg] = JSON.parse(v); } catch { args[inp.dataset.arg] = v; } }
        else args[inp.dataset.arg] = v;
    });

    const meta = { slug, serverName: serverSel().selectedOptions[0]?.textContent || slug, tool, args };
    _lastServer = slug; _lastTool = tool;

    const turn = document.createElement('div');
    turn.className = 'adv-turn adv-turn-query mcp-turn-clickable';
    turn.title = t('explorer.rerun-hint');
    turn.innerHTML = `<span class="adv-turn-source">${esc(meta.serverName)}</span><code>${esc(tool)}(${esc(JSON.stringify(args))})</code>`;
    turn.addEventListener('click', () => restoreInvocation(meta));
    resultsEl().appendChild(turn);
    scrollDown();

    const btn = document.getElementById('mcp-explorer-run');
    btn.disabled = true; btn.textContent = '…';
    const t0 = performance.now();
    const res = await api.call('mcp_invoke_tool', { source: slug, tool, arguments: JSON.stringify(args) }, 'POST');
    const ms = Math.round(performance.now() - t0);
    btn.disabled = false; btn.textContent = t('explorer.invoke');

    let out;
    if (res.success) {
        out = buildResultTurn(res.text || '', meta, ms);
    } else {
        out = document.createElement('div');
        out.className = 'adv-turn adv-turn-result';
        out.innerHTML = `<p class="adv-error">${esc(res.message || 'Invocation failed.')}</p>`;
    }
    resultsEl().appendChild(out);
    scrollDown();
};

// ── Open / close ───────────────────────────────────────────────────────────────
const open = async () => {
    _servers = await getMcpServers();
    if (!_servers.length) {
        serverSel().innerHTML = '';
        helpEl().innerHTML = '';
        argsEl().innerHTML = '';
        _tools = []; syncFilterVisibility();
        resultsEl().innerHTML = `<p class="adv-empty" style="padding:1rem">${t('explorer.no-servers')}</p>`;
    } else {
        resultsEl().innerHTML = '';
        serverSel().innerHTML = _servers.map(s => `<option value="${esc(s.slug)}">${esc(s.name)}</option>`).join('');
        if (_lastServer && _servers.some(s => s.slug === _lastServer)) serverSel().value = _lastServer;
        if (filterEl()) filterEl().value = '';
        await loadTools();
    }
    lb().classList.remove('hidden');
};

const close = () => lb().classList.add('hidden');
const clearResults = () => { resultsEl().innerHTML = ''; };

export const init = () => {
    const btn = document.getElementById('mcp-explorer-btn');
    if (!btn) return; // reader — button not rendered
    btn.addEventListener('click', open);
    document.getElementById('mcp-explorer-close')?.addEventListener('click', close);
    lb()?.addEventListener('click', (e) => { if (e.target === lb()) close(); });
    serverSel()?.addEventListener('change', loadTools);
    toolSel()?.addEventListener('change', renderTool);
    filterEl()?.addEventListener('input', renderToolOptions);
    document.getElementById('mcp-explorer-run')?.addEventListener('click', invoke);
    document.getElementById('mcp-explorer-clear')?.addEventListener('click', clearResults);

    // Enter in an argument field invokes; Escape closes the lightbox.
    argsEl()?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && e.target.classList.contains('mcp-arg-input')) {
            e.preventDefault(); invoke();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !lb() || lb().classList.contains('hidden')) return;
        const saveLb = document.getElementById('mcp-save-lightbox');
        if (saveLb && !saveLb.classList.contains('hidden')) return; // save modal handles its own Escape
        close();
    });
};
