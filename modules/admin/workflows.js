// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// Admin → Content → Workflows: the overview with its on/off switches, the editor, the
// Test preview and each workflow's run history. The behaviour lives server-side
// (workflows.php, workflow_runner.php); this only edits definitions and reads outcomes.
// Kept out of modules/admin/index.js, which is long enough already.

import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { icons } from '../core/icons.js';
import { openPagePicker } from './page_picker.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const TRIGGERS = ['page_created', 'page_updated', 'page_deleted', 'page_renamed', 'tag_added', 'tag_removed', 'fm_changed'];
const ACTIONS  = ['email', 'ai', 'chat', 'tag', 'frontmatter'];
const TYPES    = ['md', 'list', 'chat', 'json', 'drawio', 'search'];
const VARS     = ['page', 'path', 'space', 'url', 'actor', 'event', 'workflow', 'old_path', 'tags', 'field', 'old_value', 'new_value', 'date'];

let data = { workflows: [], spaces: [], ai_users: [], users: [], mail_configured: false };

const container = () => document.getElementById('admin-workflows-list');
const addBtn    = () => document.getElementById('admin-workflows-add-btn');

const spaceLabel = (s) => s === '*' ? t('admin.wf.all-spaces') : (s === '' ? t('admin.wf.root-space') : s);

// "When a page is updated in Main, in Docs/, tagged policy"
const triggerSummary = (wf) => {
    const tr = wf.trigger || {};
    let s = t(`admin.wf.trigger.${tr.type}`);
    if ((tr.type === 'tag_added' || tr.type === 'tag_removed') && tr.tag) s += ` #${tr.tag}`;
    if (tr.type === 'fm_changed') s += ` — ${tr.field}${tr.value ? ` → ${tr.value}` : ''}`;
    const f = wf.filters || {};
    const bits = [spaceLabel(wf.space ?? '')];
    if (f.folder)  bits.push(`${f.folder}/`);
    if (f.types?.length) bits.push(f.types.map(x => `.${x}`).join(' '));
    if (f.has_tag) bits.push(`#${f.has_tag}`);
    if (f.exclude_ai) bits.push(t('admin.wf.no-ai-short'));
    return `${esc(s)}<div class="wf-sub">${esc(bits.join(' · '))}</div>`;
};

const actionSummary = (a) => {
    switch (a.type) {
        case 'email':       return t('admin.wf.sum.email');
        case 'ai':          return t('admin.wf.sum.ai', { name: data.ai_users.find(u => u.uid === a.ai_uid)?.name || '?' });
        case 'chat':        return t('admin.wf.sum.chat', { chat: a.chat });
        case 'tag':         return [...(a.add || []).map(x => `+#${x}`), ...(a.remove || []).map(x => `−#${x}`)].join(' ');
        case 'frontmatter': return `${a.field}: ${a.value || '∅'}`;
        default:            return a.type;
    }
};

const statusBadge = (state) => {
    const cls = state === 'ok' ? 'ok' : (state === 'error' ? 'err' : 'skip');
    return `<span class="admin-job-status admin-job-status-${cls}">${esc(t(`admin.wf.state.${state}`))}</span>`;
};

export const loadWorkflows = async () => {
    const box = container();
    if (!box) return;
    box.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const res = await api.call('admin_get_workflows');
    if (!res.success) { box.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`; return; }
    data = res;
    renderList();
};

const renderList = () => {
    const box = container();
    if (!box) return;
    addBtn()?.classList.remove('hidden');
    const notes = [];
    if (data.runner_stalled) notes.push(`<div class="wf-note wf-note-warn">${t('admin.wf.runner-stalled')}</div>`);
    notes.push(`<div class="wf-note">${t('admin.wf.intro', { minutes: data.runner_interval || 2 })}</div>`);

    if (!data.workflows.length) {
        box.innerHTML = notes.join('') + `<p class="admin-empty">${t('admin.wf.none')}</p>`;
        return;
    }
    const rows = data.workflows.map(wf => {
        const st = wf.stats || {};
        const last = st.last_run
            ? `${new Date(st.last_run).toLocaleString()} ${statusBadge(st.last_status)}` : '—';
        const counts = t('admin.wf.runs-7d', { n: wf.runs_7d || 0 })
            + (wf.queued ? ` · ${t('admin.wf.queued-n', { n: wf.queued })}` : '');
        const off = wf.disabled_reason
            ? `<div class="wf-sub wf-err" title="${esc(st.last_error || '')}">${t('admin.wf.auto-off', { why: esc(wf.disabled_reason) })}</div>` : '';
        return `<tr data-id="${esc(wf.id)}" class="${wf.enabled ? '' : 'wf-row-off'}">
            <td><label class="toggle-switch" title="${t('admin.wf.toggle-title')}">
                <input type="checkbox" class="wf-toggle" ${wf.enabled ? 'checked' : ''}>
                <span class="toggle-switch-track"></span><span class="toggle-switch-thumb"></span></label></td>
            <td class="admin-td-name">${esc(wf.name)}${wf.description ? `<div class="wf-sub">${esc(wf.description)}</div>` : ''}${off}</td>
            <td>${triggerSummary(wf)}</td>
            <td>${(wf.actions || []).map(a => `<span class="wf-chip wf-chip-${esc(a.type)}" title="${esc(t(`admin.wf.action.${a.type}`))}">${esc(actionSummary(a))}</span>`).join(' ')}</td>
            <td style="font-size:0.82rem">${last}<div class="wf-sub">${counts}</div></td>
            <td class="wf-row-btns">
                <button class="btn btn-sm btn-secondary wf-history">${t('admin.wf.history-btn')}</button>
                <button class="btn btn-sm btn-secondary wf-edit">${t('admin.wf.edit-btn')}</button>
                <button class="btn btn-sm btn-danger wf-delete">${t('btn.delete')}</button>
            </td></tr>`;
    }).join('');
    box.innerHTML = notes.join('') + `<table class="admin-table wf-table"><thead><tr>
        <th>${t('admin.wf.col-on')}</th><th>${t('admin.wf.col-name')}</th><th>${t('admin.wf.col-when')}</th>
        <th>${t('admin.wf.col-then')}</th><th>${t('admin.wf.col-last')}</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table>`;

    box.querySelectorAll('tbody tr').forEach(tr => {
        const wf = data.workflows.find(w => w.id === tr.dataset.id);
        tr.querySelector('.wf-toggle').addEventListener('change', async (e) => {
            const on = e.target.checked;
            const res = await api.call('admin_toggle_workflow', { id: wf.id, enabled: on ? '1' : '0' }, 'POST');
            if (!res.success) { e.target.checked = !on; showToast(res.message || t('admin.wf.save-fail'), 'error'); return; }
            showToast(t(on ? 'admin.wf.turned-on' : 'admin.wf.turned-off', { name: wf.name }), 'success');
            loadWorkflows();
        });
        tr.querySelector('.wf-edit').addEventListener('click', () => openForm(wf));
        tr.querySelector('.wf-history').addEventListener('click', () => openHistory(wf));
        tr.querySelector('.wf-delete').addEventListener('click', async () => {
            const ok = await confirmModal(t('admin.wf.delete-title'), {
                message: t('admin.wf.delete-confirm', { name: wf.name }),
                confirmLabel: t('btn.delete'), cancelLabel: t('btn.cancel'), dangerous: true });
            if (!ok) return;
            const res = await api.call('admin_delete_workflow', { id: wf.id }, 'POST');
            if (!res.success) { showToast(res.message || t('admin.wf.save-fail'), 'error'); return; }
            loadWorkflows();
        });
    });
};

// ── History ─────────────────────────────────────────────────────────────────────

const openHistory = async (wf) => {
    const box = container();
    addBtn()?.classList.add('hidden');
    box.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const res = await api.call('admin_get_workflow_runs', { id: wf.id });
    const runs = res.success ? (res.runs || []) : [];
    const rows = runs.map(r => {
        const when = r.finished_at || r.started_at || r.due_at || r.created_at;
        const results = (r.results || []).map(x =>
            `<div class="wf-result"><span class="wf-chip wf-chip-${esc(x.type)}">${esc(t(`admin.wf.action.${x.type}`))}</span> ${statusBadge(x.status)} ${esc(x.detail || '')}</div>`).join('');
        const waiting = r.state === 'queued'
            ? `<div class="wf-sub">${t('admin.wf.due-at', { time: new Date(r.due_at).toLocaleTimeString() })}</div>` : '';
        return `<tr data-run="${esc(r.id)}">
            <td style="white-space:nowrap;font-size:0.82rem">${when ? new Date(when).toLocaleString() : '—'}</td>
            <td>${statusBadge(r.state)}${waiting}</td>
            <td>${esc(r.space ? `${r.space}/` : '')}${esc(r.path)}${r.old_path ? `<div class="wf-sub">← ${esc(r.old_path)}</div>` : ''}
                <div class="wf-sub">${r.manual ? `<span class="wf-chip">${t('admin.wf.manual')}</span> ` : ''}${esc(t(`admin.wf.trigger.${r.event}`))} · ${esc(r.actor?.name || '—')}${(r.events || 1) > 1 ? ` · ${t('admin.wf.events-n', { n: r.events })}` : ''}</div></td>
            <td>${results}${r.error ? `<div class="wf-err">${esc(r.error)}</div>` : ''}</td>
            <td>${r.has_log ? `<button class="btn btn-sm btn-secondary wf-log">${t('admin.oneoff.log-btn')}</button>` : ''}</td></tr>`;
    }).join('');
    box.innerHTML = `<div class="wf-history-head"><button class="btn btn-sm btn-secondary wf-back">← ${t('admin.wf.back')}</button>
        <strong>${esc(wf.name)}</strong> <span class="wf-sub">${t('admin.wf.history-note')}</span></div>`
        + (runs.length ? `<table class="admin-table wf-table"><thead><tr><th>${t('admin.wf.col-time')}</th><th>${t('admin.wf.col-state')}</th>
            <th>${t('admin.wf.col-page')}</th><th>${t('admin.wf.col-result')}</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                       : `<p class="admin-empty">${t('admin.wf.no-runs')}</p>`);
    box.querySelector('.wf-back').addEventListener('click', renderList);
    box.querySelectorAll('.wf-log').forEach(btn => btn.addEventListener('click', async () => {
        const tr = btn.closest('tr');
        const out = await api.call('admin_get_workflow_runs', { id: wf.id, log: tr.dataset.run });
        document.getElementById('wf-log-row')?.remove();
        const row = document.createElement('tr');
        row.id = 'wf-log-row';
        row.innerHTML = `<td colspan="5"><pre class="admin-diag-pre admin-joblog-pre" style="max-height:320px"></pre></td>`;
        row.querySelector('pre').textContent = out.log || '';
        tr.after(row);
    }));
};

// ── Form ────────────────────────────────────────────────────────────────────────

// A path box with a browse button, and an × once it holds something. `kind` decides what
// the picker offers: 'folder', 'chat' (a .chat page) or 'page' (any content page).
const pathField = (id, value, placeholder, kind) => `
    <div class="wf-path">
        <span class="wf-path-box">
            <input type="text" id="${id}" class="form-control wf-path-input" value="${esc(value)}" placeholder="${esc(placeholder)}">
            <button type="button" class="wf-path-clear${value ? '' : ' hidden'}" data-for="${id}" title="${t('admin.picker.clear')}" aria-label="${t('admin.picker.clear')}">×</button>
        </span>
        <button type="button" class="btn btn-sm btn-secondary wf-path-browse" data-for="${id}" data-kind="${kind}" title="${t('admin.picker.browse')}">${kind === 'folder' ? icons.folder : icons.file} ${t('admin.picker.browse')}</button>
    </div>`;

const PICK = {
    folder: { mode: 'folder' },
    chat:   { mode: 'file', match: /\.chat$/i,                              empty: () => t('admin.picker.no-chats') },
    page:   { mode: 'file', match: /\.(md|list|chat|json|drawio|search)$/i, empty: () => t('admin.picker.no-pages') },
};

/**
 * One delegated listener for every path box in the form: browse, clear, and the × that
 * follows the value. Paths are relative to a space. The workflow's own space decides it —
 * fixed when the workflow names one, free to choose under "All spaces", where a relative
 * path means the same place in whichever space the event happened. The test page has its
 * own space selector, which the pick updates.
 */
const wirePathFields = (root) => {
    const syncClear = (input) => {
        root.querySelector(`.wf-path-clear[data-for="${input.id}"]`)?.classList.toggle('hidden', !input.value);
    };
    root.addEventListener('input', (e) => { if (e.target.classList?.contains('wf-path-input')) syncClear(e.target); });
    root.addEventListener('click', (e) => {
        const clear = e.target.closest('.wf-path-clear');
        if (clear) {
            const input = document.getElementById(clear.dataset.for);
            input.value = '';
            syncClear(input);
            input.focus();
            return;
        }
        const browse = e.target.closest('.wf-path-browse');
        if (!browse) return;
        const input = document.getElementById(browse.dataset.for);
        const kind  = browse.dataset.kind;
        const isTest = input.id === 'wf-t-path';
        const wfSpace = document.getElementById('wf-f-space')?.value ?? '';
        const space = isTest ? document.getElementById('wf-t-space').value : (wfSpace === '*' ? '' : wfSpace);
        const conf = PICK[kind];
        openPagePicker({
            space, path: input.value, mode: conf.mode, match: conf.match,
            title: t(`admin.picker.title-${kind}`),
            empty: conf.empty ? conf.empty() : t('admin.ai.prompt-page-empty'),
            lockSpace: !isTest && wfSpace !== '*',
            onSelect: (sp, path) => {
                input.value = path;
                syncClear(input);
                if (isTest) document.getElementById('wf-t-space').value = sp;
            },
        });
    });
};

const opt = (value, label, selected) => `<option value="${esc(value)}" ${selected ? 'selected' : ''}>${esc(label)}</option>`;

const actionFields = (a, i) => {
    const id = (k) => `wf-a${i}-${k}`;
    switch (a.type) {
        case 'email': {
            const users = data.users.map(u => `<label class="wf-check"><input type="checkbox" class="${id('user')}" value="${u.uid}"
                ${(a.to_users || []).includes(u.uid) ? 'checked' : ''} ${u.has_email ? '' : 'disabled'}> ${esc(u.name)}${u.has_email ? '' : ` <span class="wf-sub">(${t('admin.wf.no-email')})</span>`}</label>`).join('');
            return `${data.mail_configured ? '' : `<div class="wf-note wf-note-warn">${t('admin.wf.mail-off')}</div>`}
                <label>${t('admin.wf.email.to')}</label>
                <div class="wf-checks">
                    <label class="wf-check"><input type="checkbox" id="${id('author')}" ${a.to_author ? 'checked' : ''}> ${t('admin.wf.email.author')}</label>
                    <label class="wf-check"><input type="checkbox" id="${id('actor')}" ${a.to_actor ? 'checked' : ''}> ${t('admin.wf.email.actor')}</label>
                    ${users}</div>
                <label>${t('admin.wf.email.others')}</label>
                <input type="text" id="${id('emails')}" class="form-control" value="${esc(a.to_emails || '')}" placeholder="name@example.com, …">
                <label>${t('admin.wf.email.subject')}</label>
                <input type="text" id="${id('subject')}" class="form-control" value="${esc(a.subject ?? t('admin.wf.email.subject-default'))}">
                <label>${t('admin.wf.email.body')}</label>
                <textarea id="${id('body')}" class="form-control" rows="5">${esc(a.body ?? t('admin.wf.email.body-default'))}</textarea>`;
        }
        case 'ai':
            return `<label>${t('admin.wf.ai.user')}</label>
                <select id="${id('ai')}" class="form-control">${data.ai_users.length
                    ? data.ai_users.map(u => opt(u.uid, u.name, u.uid === a.ai_uid)).join('')
                    : `<option value="">${t('admin.wf.ai.none')}</option>`}</select>
                <label>${t('admin.wf.ai.prompt')}</label>
                <textarea id="${id('prompt')}" class="form-control" rows="5" placeholder="${t('admin.wf.ai.prompt-ph')}">${esc(a.prompt || '')}</textarea>
                <label>${t('admin.wf.ai.chat')}</label>
                ${pathField(id('chat'), a.chat || '', t('admin.wf.ai.chat-ph'), 'chat')}`;
        case 'chat':
            return `<label>${t('admin.wf.chat.thread')}</label>
                ${pathField(id('chat'), a.chat || '', 'Team/General.chat', 'chat')}
                <label>${t('admin.wf.chat.as')}</label>
                <select id="${id('as')}" class="form-control">${opt(0, t('admin.wf.chat.as-workflow'), !a.as_uid)}${data.ai_users.map(u => opt(u.uid, u.name, u.uid === a.as_uid)).join('')}</select>
                <label>${t('admin.wf.chat.text')}</label>
                <textarea id="${id('text')}" class="form-control" rows="3">${esc(a.text ?? t('admin.wf.chat.text-default'))}</textarea>`;
        case 'tag':
            return `<label>${t('admin.wf.tag.add')}</label>
                <input type="text" id="${id('add')}" class="form-control" value="${esc((a.add || []).join(', '))}" placeholder="reviewed, published">
                <label>${t('admin.wf.tag.remove')}</label>
                <input type="text" id="${id('remove')}" class="form-control" value="${esc((a.remove || []).join(', '))}" placeholder="draft">`;
        case 'frontmatter':
            return `<label>${t('admin.wf.fm.field')}</label>
                <input type="text" id="${id('field')}" class="form-control" value="${esc(a.field || '')}" placeholder="status">
                <label>${t('admin.wf.fm.value')}</label>
                <input type="text" id="${id('value')}" class="form-control" value="${esc(a.value || '')}" placeholder="${t('admin.wf.fm.value-ph')}">`;
        default: return '';
    }
};

const readAction = (type, i) => {
    const v = (k) => document.getElementById(`wf-a${i}-${k}`)?.value ?? '';
    const list = (k) => v(k).split(/[,\s]+/).map(s => s.replace(/^#/, '').trim()).filter(Boolean);
    switch (type) {
        case 'email': return {
            type, to_author: document.getElementById(`wf-a${i}-author`)?.checked || false,
            to_actor: document.getElementById(`wf-a${i}-actor`)?.checked || false,
            to_users: [...document.querySelectorAll(`.wf-a${i}-user:checked`)].map(c => Number(c.value)),
            to_emails: v('emails'), subject: v('subject'), body: v('body') };
        case 'ai':          return { type, ai_uid: Number(v('ai')), prompt: v('prompt'), chat: v('chat').trim() };
        case 'chat':        return { type, chat: v('chat').trim(), as_uid: Number(v('as')), text: v('text') };
        case 'tag':         return { type, add: list('add'), remove: list('remove') };
        case 'frontmatter': return { type, field: v('field').trim(), value: v('value') };
        default:            return { type };
    }
};

let formActions = [];

const renderActions = () => {
    const box = document.getElementById('wf-actions');
    if (!box) return;
    box.innerHTML = formActions.map((a, i) => `
        <div class="wf-action-card" data-i="${i}">
            <div class="wf-action-head">
                <span class="wf-action-n">${i + 1}</span>
                <select class="form-control wf-action-type">${ACTIONS.map(x => opt(x, t(`admin.wf.action.${x}`), x === a.type)).join('')}</select>
                <button type="button" class="btn btn-sm btn-secondary wf-up" ${i === 0 ? 'disabled' : ''} title="${t('admin.wf.move-up')}">↑</button>
                <button type="button" class="btn btn-sm btn-secondary wf-remove" title="${t('admin.wf.remove-action')}">✕</button>
            </div>
            <div class="job-form-grid">${actionFields(a, i)}</div>
        </div>`).join('');
    box.querySelectorAll('.wf-action-card').forEach(card => {
        const i = Number(card.dataset.i);
        card.querySelector('.wf-action-type').addEventListener('change', (e) => {
            captureActions();
            formActions[i] = { type: e.target.value };
            renderActions();
        });
        card.querySelector('.wf-remove').addEventListener('click', () => { captureActions(); formActions.splice(i, 1); renderActions(); });
        card.querySelector('.wf-up').addEventListener('click', () => {
            captureActions();
            [formActions[i - 1], formActions[i]] = [formActions[i], formActions[i - 1]];
            renderActions();
        });
    });
};

// Read what is typed back into formActions before a re-render throws the inputs away.
const captureActions = () => { formActions = formActions.map((a, i) => readAction(a.type, i)); };

const triggerExtra = (tr) => {
    switch (tr.type) {
        case 'tag_added': case 'tag_removed':
            return `<label>${t('admin.wf.trigger-tag')}</label>
                <input type="text" id="wf-f-tag" class="form-control" value="${esc(tr.tag || '')}" placeholder="${t('admin.wf.any-tag')}">`;
        case 'fm_changed':
            return `<label>${t('admin.wf.trigger-field')}</label>
                <input type="text" id="wf-f-field" class="form-control" value="${esc(tr.field || '')}" placeholder="status">
                <label>${t('admin.wf.trigger-value')}</label>
                <input type="text" id="wf-f-value" class="form-control" value="${esc(tr.value || '')}" placeholder="${t('admin.wf.any-value')}">`;
        default: return '';
    }
};

const readForm = (wf) => {
    captureActions();
    const tt = document.getElementById('wf-f-trigger').value;
    return {
        id: wf?.id || '',
        name: document.getElementById('wf-f-name').value,
        description: document.getElementById('wf-f-desc').value,
        enabled: document.getElementById('wf-f-enabled').checked,
        space: document.getElementById('wf-f-space').value,
        trigger: { type: tt, tag: document.getElementById('wf-f-tag')?.value || '',
                   field: document.getElementById('wf-f-field')?.value || '', value: document.getElementById('wf-f-value')?.value || '' },
        filters: {
            folder: document.getElementById('wf-f-folder').value,
            types: [...document.querySelectorAll('.wf-f-type:checked')].map(c => c.value),
            has_tag: document.getElementById('wf-f-has-tag').value,
            exclude_ai: document.getElementById('wf-f-no-ai').checked,
        },
        actions: formActions,
        debounce: Number(document.getElementById('wf-f-debounce').value),
        max_per_hour: Number(document.getElementById('wf-f-per-hour').value),
    };
};

const openForm = (wf) => {
    const box = container();
    if (!box) return;
    addBtn()?.classList.add('hidden');
    const isNew = !wf;
    const tr = wf?.trigger || { type: 'page_updated' };
    const f  = wf?.filters || {};
    formActions = (wf?.actions || [{ type: 'email' }]).map(a => ({ ...a }));
    const spaces = ['*', ...data.spaces];
    const defaultSpace = wf ? wf.space : (data.spaces[0] ?? '');

    box.innerHTML = `
        <div class="admin-ai-form">
            <div class="job-form-grid">
                <label>${t('admin.wf.name')}</label>
                <input type="text" id="wf-f-name" class="form-control" value="${esc(wf?.name || '')}">
                <label>${t('admin.wf.description')}</label>
                <input type="text" id="wf-f-desc" class="form-control" value="${esc(wf?.description || '')}">
                <label>${t('admin.wf.enabled')}</label>
                <label class="toggle-switch"><input type="checkbox" id="wf-f-enabled" ${isNew || wf.enabled ? 'checked' : ''}>
                    <span class="toggle-switch-track"></span><span class="toggle-switch-thumb"></span></label>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.wf.when')}</div>
            <div class="job-form-grid">
                <label>${t('admin.wf.trigger')}</label>
                <select id="wf-f-trigger" class="form-control">${TRIGGERS.map(x => opt(x, t(`admin.wf.trigger.${x}`), x === tr.type)).join('')}</select>
                <div id="wf-f-trigger-extra" style="display:contents">${triggerExtra(tr)}</div>
                <label>${t('admin.wf.space')}</label>
                <select id="wf-f-space" class="form-control">${spaces.map(s => opt(s, spaceLabel(s), s === defaultSpace)).join('')}</select>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.wf.only-for')}</div>
            <div class="job-form-grid">
                <label>${t('admin.wf.folder')}</label>
                ${pathField('wf-f-folder', f.folder || '', t('admin.wf.folder-ph'), 'folder')}
                <label>${t('admin.wf.types')}</label>
                <div class="wf-checks">${TYPES.map(x => `<label class="wf-check"><input type="checkbox" class="wf-f-type" value="${x}" ${(f.types || []).includes(x) ? 'checked' : ''}> .${x}</label>`).join('')}
                    <span class="wf-sub">${t('admin.wf.types-hint')}</span></div>
                <label>${t('admin.wf.has-tag')}</label>
                <input type="text" id="wf-f-has-tag" class="form-control" value="${esc(f.has_tag || '')}" placeholder="${t('admin.wf.any-tag')}">
                <label>${t('admin.wf.no-ai')}</label>
                <label class="wf-check"><input type="checkbox" id="wf-f-no-ai" ${f.exclude_ai ? 'checked' : ''}> ${t('admin.wf.no-ai-hint')}</label>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.wf.then')}</div>
            <div id="wf-actions"></div>
            <div><button type="button" id="wf-add-action" class="btn btn-sm btn-secondary">${t('admin.wf.add-action')}</button></div>
            <div class="wf-sub wf-vars">${t('admin.wf.vars-hint')} ${VARS.map(v => `<code>{{${v}}}</code>`).join(' ')}</div>

            <div class="admin-ai-form-section-header">${t('admin.wf.limits')}</div>
            <div class="job-form-grid">
                <label>${t('admin.wf.debounce')}</label>
                <div><input type="number" id="wf-f-debounce" class="form-control" min="0" max="86400" style="max-width:8rem" value="${wf?.debounce ?? 120}">
                    <div class="wf-sub">${t('admin.wf.debounce-hint')}</div></div>
                <label>${t('admin.wf.per-hour')}</label>
                <div><input type="number" id="wf-f-per-hour" class="form-control" min="1" max="500" style="max-width:8rem" value="${wf?.max_per_hour ?? 20}">
                    <div class="wf-sub">${t('admin.wf.per-hour-hint')}</div></div>
            </div>

            <div class="admin-ai-form-section-header">${t('admin.wf.test')}</div>
            <div class="job-form-grid">
                <label>${t('admin.wf.test-page')}</label>
                <div class="wf-test-row">
                    <select id="wf-t-space" class="form-control" style="max-width:12rem">${data.spaces.map(s => opt(s, s, s === (wf?.space && wf.space !== '*' ? wf.space : data.spaces[0]))).join('')}</select>
                    ${pathField('wf-t-path', '', 'Docs/page.md', 'page')}
                    <button type="button" id="wf-test-btn" class="btn btn-sm btn-secondary">${t('admin.wf.test-btn')}</button>
                    ${isNew ? '' : `<button type="button" id="wf-run-btn" class="btn btn-sm btn-blue" title="${t('admin.wf.run-hint')}">${t('admin.wf.run-btn')}</button>`}
                </div>
            </div>
            <div id="wf-test-out"></div>

            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color)">
                <button id="wf-f-cancel" class="btn btn-secondary">${t('btn.cancel')}</button>
                <button id="wf-f-save" class="btn btn-green" style="margin-left:auto">${t('btn.save')}</button>
            </div>
        </div>`;

    renderActions();
    wirePathFields(box.querySelector('.admin-ai-form'));
    document.getElementById('wf-f-trigger').addEventListener('change', (e) => {
        document.getElementById('wf-f-trigger-extra').innerHTML = triggerExtra({ type: e.target.value });
    });
    document.getElementById('wf-add-action').addEventListener('click', () => {
        captureActions();
        if (formActions.length >= 10) return;
        formActions.push({ type: 'email' });
        renderActions();
    });
    document.getElementById('wf-f-cancel').addEventListener('click', renderList);
    document.getElementById('wf-f-save').addEventListener('click', async () => {
        const res = await api.call('admin_save_workflow', { workflow: JSON.stringify(readForm(wf)) }, 'POST');
        if (!res.success) { showToast(res.message || t('admin.wf.save-fail'), 'error'); return; }
        showToast(t('admin.wf.saved'), 'success');
        loadWorkflows();
    });
    document.getElementById('wf-test-btn').addEventListener('click', () => runTest(wf));
    document.getElementById('wf-run-btn')?.addEventListener('click', () => runNow(wf));
};

// Run now: the saved workflow, for real, on the example page. Confirmed first — unlike
// Test it sends the email and changes the page.
const runNow = async (wf) => {
    const out   = document.getElementById('wf-test-out');
    const space = document.getElementById('wf-t-space').value;
    const path  = document.getElementById('wf-t-path').value.trim();
    if (!path) { out.innerHTML = `<div class="wf-note wf-note-warn">${t('admin.wf.run-need-page')}</div>`; return; }
    const ok = await confirmModal(t('admin.wf.run-confirm-title'), {
        message: t('admin.wf.run-confirm', { name: wf.name, page: path }),
        confirmLabel: t('admin.wf.run-btn'), cancelLabel: t('btn.cancel') });
    if (!ok) return;
    const btn = document.getElementById('wf-run-btn');
    btn.disabled = true;
    out.innerHTML = `<p class="admin-loading">${t('admin.wf.run-running')}</p>`;
    const res = await api.call('admin_run_workflow', { id: wf.id, test_space: space, test_path: path }, 'POST');
    btn.disabled = false;
    if (!res.success) { out.innerHTML = `<div class="wf-note wf-note-warn">${esc(res.message || t('admin.wf.save-fail'))}</div>`; return; }
    const r = res.run || {};
    if (r.state === 'queued') {
        out.innerHTML = `<div class="wf-note">${t('admin.wf.run-queued', { minutes: data.runner_interval || 2 })}</div>`;
        return;
    }
    const results = (r.results || []).map((x, i) => `<div class="wf-action-card"><div class="wf-action-head"><span class="wf-action-n">${i + 1}</span>
        <strong>${esc(t(`admin.wf.action.${x.type}`))}</strong> ${statusBadge(x.status)}</div><div class="wf-sub">${esc(x.detail || '')}</div></div>`).join('');
    out.innerHTML = `<div class="wf-note${r.state === 'ok' ? '' : ' wf-note-warn'}">${t('admin.wf.run-done')} ${statusBadge(r.state)}${r.error ? ` — ${esc(r.error)}` : ''}</div>` + results;
};

const runTest = async (wf) => {
    const out = document.getElementById('wf-test-out');
    const res = await api.call('admin_test_workflow', {
        workflow: JSON.stringify(readForm(wf)),
        test_space: document.getElementById('wf-t-space').value,
        test_path: document.getElementById('wf-t-path').value }, 'POST');
    if (!res.success) { out.innerHTML = `<div class="wf-note wf-note-warn">${esc(res.message || t('admin.wf.save-fail'))}</div>`; return; }
    const p = res.preview;
    const head = [];
    if (!p.page_exists) head.push(t('admin.wf.test-no-page'));
    if (p.filter_miss)  head.push(t('admin.wf.test-miss', { filter: t(`admin.wf.filter.${p.filter_miss}`) }));
    if (p.readonly)     head.push(t('admin.wf.test-readonly'));
    const warn = (w) => `<div class="wf-err">${esc(t(`admin.wf.warn.${w}`))}</div>`;
    const blocks = p.actions.map((a, i) => {
        let body = '';
        switch (a.type) {
            case 'email': body = `<div><b>${t('admin.wf.email.to')}</b> ${esc(a.to.join(', ') || '—')}</div>
                <div><b>${t('admin.wf.email.subject')}</b> ${esc(a.subject)}</div><pre class="wf-pre">${esc(a.body)}</pre>`; break;
            case 'ai': body = `<div><b>${t('admin.wf.ai.user')}</b> ${esc(a.ai_user)}${a.chat ? ` → ${esc(a.chat)}` : ''}</div><pre class="wf-pre">${esc(a.prompt)}</pre>`; break;
            case 'chat': body = `<div><b>${t('admin.wf.chat.thread')}</b> ${esc(a.chat)}</div><pre class="wf-pre">${esc(a.text)}</pre>`; break;
            case 'tag': body = `<div>${esc([...a.add.map(x => `+#${x}`), ...a.remove.map(x => `−#${x}`)].join(' '))}</div>`; break;
            case 'frontmatter': body = `<div><code>${esc(a.field)}: ${esc(a.value)}</code></div>`; break;
        }
        return `<div class="wf-action-card"><div class="wf-action-head"><span class="wf-action-n">${i + 1}</span>
            <strong>${esc(t(`admin.wf.action.${a.type}`))}</strong></div>${body}${(a.warnings || []).map(warn).join('')}</div>`;
    }).join('');
    out.innerHTML = `<div class="wf-note">${t('admin.wf.test-note')}</div>`
        + head.map(h => `<div class="wf-note wf-note-warn">${esc(h)}</div>`).join('') + blocks;
};

export const initWorkflows = () => {
    addBtn()?.addEventListener('click', () => openForm(null));
};
