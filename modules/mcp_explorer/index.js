/**
 * mcp_explorer/index.js — editor+ MCP Tool Explorer (lightbox).
 *
 * Chat-like layout: invocation results stack above, controls (server → tool →
 * arguments → Invoke) below. Deterministic tools/list + tools/call via the
 * mcp_list_tools / mcp_invoke_tool actions. No LLM.
 */
import { api } from '../core/api.js';
import { t } from '../i18n/index.js';
import { getMcpServers } from '../core/mcp_servers.js';

const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

let _servers = [];   // [{name, slug, wiki_native}]
let _tools   = [];   // tools for the selected server [{name, description, params}]

const lb        = () => document.getElementById('mcp-explorer-lightbox');
const serverSel = () => document.getElementById('mcp-explorer-server');
const toolSel   = () => document.getElementById('mcp-explorer-tool');
const helpEl    = () => document.getElementById('mcp-explorer-help');
const argsEl    = () => document.getElementById('mcp-explorer-args');
const resultsEl = () => document.getElementById('mcp-explorer-results');

const scrollDown = () => { const r = resultsEl(); if (r) r.scrollTop = r.scrollHeight; };

// Render both the fixed-height description panel and the dynamic per-argument
// input fields for the selected tool. The help box stays a fixed height (no
// bounce); the fields below grow with the parameter count, pushing results up.
const renderTool = () => {
    const tool = _tools.find(t => t.name === toolSel().value);
    const help = helpEl();
    const args = argsEl();
    args.innerHTML = '';
    if (!tool) { help.innerHTML = ''; return; }

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

const loadTools = async () => {
    const slug = serverSel().value;
    toolSel().innerHTML = `<option>…</option>`;
    helpEl().innerHTML = '';
    argsEl().innerHTML = '';
    const res = await api.call('mcp_list_tools', { source: slug });
    _tools = res.success ? (res.tools || []) : [];
    if (!res.success) {
        toolSel().innerHTML = '';
        resultsEl().insertAdjacentHTML('beforeend', `<div class="adv-turn adv-turn-result"><p class="adv-error">${esc(res.message || 'Failed to list tools.')}</p></div>`);
        return;
    }
    toolSel().innerHTML = _tools.map(t => `<option value="${esc(t.name)}">${esc(t.name)}</option>`).join('');
    renderTool();
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

    const turn = document.createElement('div');
    turn.className = 'adv-turn adv-turn-query';
    turn.innerHTML = `<span class="adv-turn-source">${esc(serverSel().selectedOptions[0]?.textContent || slug)}</span><code>${esc(tool)}(${esc(JSON.stringify(args))})</code>`;
    resultsEl().appendChild(turn);
    scrollDown();

    const btn = document.getElementById('mcp-explorer-run');
    btn.disabled = true; btn.textContent = '…';
    const res = await api.call('mcp_invoke_tool', { source: slug, tool, arguments: JSON.stringify(args) }, 'POST');
    btn.disabled = false; btn.textContent = t('explorer.invoke');

    const out = document.createElement('div');
    out.className = 'adv-turn adv-turn-result';
    out.innerHTML = res.success
        ? `<div class="adv-text-result"><pre>${esc(res.text || '')}</pre></div>`
        : `<p class="adv-error">${esc(res.message || 'Invocation failed.')}</p>`;
    resultsEl().appendChild(out);
    scrollDown();
};

const open = async () => {
    _servers = await getMcpServers();
    if (!_servers.length) {
        serverSel().innerHTML = '';
        helpEl().innerHTML = '';
        argsEl().innerHTML = '';
        resultsEl().innerHTML = `<p class="adv-empty" style="padding:1rem">${t('explorer.no-servers')}</p>`;
    } else {
        resultsEl().innerHTML = '';
        serverSel().innerHTML = _servers.map(s => `<option value="${esc(s.slug)}">${esc(s.name)}</option>`).join('');
        await loadTools();
    }
    lb().classList.remove('hidden');
};

const close = () => lb().classList.add('hidden');

export const init = () => {
    const btn = document.getElementById('mcp-explorer-btn');
    if (!btn) return; // reader — button not rendered
    btn.addEventListener('click', open);
    document.getElementById('mcp-explorer-close')?.addEventListener('click', close);
    lb()?.addEventListener('click', (e) => { if (e.target === lb()) close(); });
    serverSel()?.addEventListener('change', loadTools);
    toolSel()?.addEventListener('change', renderTool);
    document.getElementById('mcp-explorer-run')?.addEventListener('click', invoke);
};
