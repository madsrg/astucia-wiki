// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Admin → Content → Retention policies: everything the wiki keeps for a while, in one tab.
 *
 * Four kinds, and the hints keep them apart because they mean different things:
 *  - **Chat** — the wiki default for threads that have never chosen a policy; a thread's
 *    own choice, "off" included, still wins (`admin_chat_retention`).
 *  - **Mentions** — how far back My Mentions looks. Nothing is deleted
 *    (`admin_mention_settings`).
 *  - **Log files** — deleted once a day by the cron runner when older than their limit, 0
 *    keeping them forever, which is every log's default (`admin_retention_settings`).
 *  - **Run history** — the finished jobs and workflow runs the wiki lists.
 *
 * Each log row shows what it holds now and what its limit would remove, recomputed as the
 * number changes, so a limit is never set blind. One Save writes all of it.
 *
 * Every input's id is `admin-ret-<setting key>`, so Save, the preview and the read-back
 * after saving all address a field the same way.
 */
import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';

const CHAT_OPTIONS = ['', 'count:100', 'count:200', 'count:300', 'days:30', 'days:90', 'days:180'];
const LOGS = ['access_log', 'error_log', 'agent_job_log', 'workflow_log', 'audit_log'];
const HISTORY = [['job', 'admin.retention.job-history'], ['workflow', 'admin.retention.wf-history']];

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const fmtSize = (b) => b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(1)} KB`
    : b < 1073741824 ? `${(b / 1048576).toFixed(1)} MB` : `${(b / 1073741824).toFixed(2)} GB`;
const $ = (id) => document.getElementById(id);
const num = (id) => Math.max(0, parseInt($(id)?.value, 10) || 0);

let _fields = {};
let _previewTimer = null;

const usageHtml = (scan, days) => {
    if (!scan || !scan.files) return `<span class="wf-sub">${esc(t('admin.retention.empty'))}</span>`;
    let html = esc(t('admin.retention.usage', { files: scan.files, size: fmtSize(scan.bytes), oldest: scan.oldest || '' }));
    if (days > 0 && scan.old_files > 0) {
        html += `<div class="admin-retention-warn">${esc(t('admin.retention.would-delete',
            { files: scan.old_files, size: fmtSize(scan.old_bytes) }))}</div>`;
    } else if (days === 0) {
        html += `<div class="wf-sub">${esc(t('admin.retention.forever'))}</div>`;
    }
    return html;
};

const paintUsage = (scan) => {
    LOGS.forEach(item => {
        const cell = $(`admin-ret-usage-${item}`);
        if (cell) cell.innerHTML = usageHtml(scan[item], num(`admin-ret-${item}_days`));
    });
};

/** Re-read what each log's limit would remove, from the numbers as typed (not saved). */
const preview = () => {
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(async () => {
        const params = {};
        LOGS.forEach(item => { params[`${item}_days`] = String(num(`admin-ret-${item}_days`)); });
        const res = await api.call('admin_retention_settings', params);
        if (res.success) paintUsage(res.scan);
    }, 300);
};

const numberInput = (id, value, key) => {
    const f = _fields[key] || { min: 0, max: 3650 };
    return `<input type="number" id="${id}" class="form-control admin-retention-num" min="${f.min}" max="${f.max}" step="1" value="${esc(value)}">`;
};

const render = (data, chatPolicy, mentionDays) => {
    const s = data.settings;
    const logRows = LOGS.map(item => `
        <tr>
            <td><label for="admin-ret-${item}_days">${esc(t(`admin.retention.log.${item}`))}</label>
                ${item === 'audit_log' ? `<div class="wf-sub">${esc(t('admin.retention.audit-hint'))}</div>` : ''}</td>
            <td>${numberInput(`admin-ret-${item}_days`, s[`${item}_days`], `${item}_days`)}</td>
            <td id="admin-ret-usage-${item}"></td>
        </tr>`).join('');
    const histRows = HISTORY.map(([k, label]) => `
        <tr>
            <td>${esc(t(label))}</td>
            <td>${numberInput(`admin-ret-${k}_history_days`, s[`${k}_history_days`], `${k}_history_days`)}</td>
            <td>${numberInput(`admin-ret-${k}_history_keep`, s[`${k}_history_keep`], `${k}_history_keep`)}</td>
        </tr>`).join('');

    $('admin-retention-body').innerHTML = `
        <p class="admin-reindex-desc">${esc(t('admin.retention.desc'))}</p>

        <h4 class="admin-retention-h">${esc(t('admin.retention.chat'))}</h4>
        <p class="pref-hint">${esc(t('admin.chatpolicy.desc'))}</p>
        <div class="admin-reindex-controls">
            <label class="admin-reindex-label" for="admin-chat-retention">${esc(t('admin.chatpolicy.label'))}</label>
            <select id="admin-chat-retention" class="form-control admin-reindex-select">${CHAT_OPTIONS.map(v =>
                `<option value="${esc(v)}" ${v === chatPolicy ? 'selected' : ''}>${esc(t('chat-topic.retention.' + (v === '' ? 'off' : v.replace(':', '-'))))}</option>`).join('')}</select>
        </div>
        <p class="pref-hint">${esc(t('admin.chatpolicy.hint'))}</p>

        <h4 class="admin-retention-h">${esc(t('admin.retention.mentions'))}</h4>
        <p class="pref-hint">${esc(t('admin.mentions.desc'))}</p>
        <div class="admin-reindex-controls">
            <label class="admin-reindex-label" for="admin-mention-days">${esc(t('admin.mentions.label'))}</label>
            <input type="number" id="admin-mention-days" class="form-control admin-reindex-select" min="0" step="1" value="${esc(mentionDays)}">
        </div>
        <p class="pref-hint">${esc(t('admin.mentions.hint'))}</p>

        <h4 class="admin-retention-h">${esc(t('admin.retention.logs'))}</h4>
        <p class="pref-hint">${esc(t('admin.retention.logs-desc'))}</p>
        ${data.log_dir ? '' : `<div class="wf-note wf-note-warn">${esc(t('admin.retention.no-log-dir'))}</div>`}
        <table class="admin-table admin-retention-table">
            <thead><tr><th>${esc(t('admin.retention.col-log'))}</th><th>${esc(t('admin.retention.col-days'))}</th><th>${esc(t('admin.retention.col-now'))}</th></tr></thead>
            <tbody>${logRows}</tbody>
        </table>

        <h4 class="admin-retention-h">${esc(t('admin.retention.history'))}</h4>
        <p class="pref-hint">${esc(t('admin.retention.history-desc'))}</p>
        <table class="admin-table admin-retention-table">
            <thead><tr><th></th><th>${esc(t('admin.retention.history-days'))}</th><th>${esc(t('admin.retention.history-keep'))}</th></tr></thead>
            <tbody>${histRows}</tbody>
        </table>

        <div class="admin-retention-actions">
            <button id="admin-retention-clean" class="btn btn-secondary">${esc(t('admin.retention.clean'))}</button>
            <button id="admin-retention-save" class="btn btn-blue">${esc(t('btn.save'))}</button>
        </div>`;

    paintUsage(data.scan);
    LOGS.forEach(item => $(`admin-ret-${item}_days`).addEventListener('input', preview));
    $('admin-retention-save').addEventListener('click', () => save(true));
    $('admin-retention-clean').addEventListener('click', cleanNow);
};

/** Write all three settings. Returns whether every one of them was saved. */
const save = async (announce) => {
    const btn = $('admin-retention-save');
    btn.disabled = true;
    const params = {};
    [...LOGS.map(i => `${i}_days`), 'job_history_days', 'job_history_keep', 'workflow_history_days', 'workflow_history_keep']
        .forEach(k => { params[k] = String(num(`admin-ret-${k}`)); });
    const [chat, mentions, logs] = await Promise.all([
        api.call('admin_chat_retention', { policy: $('admin-chat-retention').value }, 'POST'),
        api.call('admin_mention_settings', { days: String(num('admin-mention-days')) }, 'POST'),
        api.call('admin_retention_settings', params, 'POST'),
    ]);
    btn.disabled = false;
    const failed = [chat, mentions, logs].find(r => !r.success);
    if (failed) {
        showToast(failed.message || t('admin.retention.failed'), 'error');
        return false;
    }
    // What the server stored, not what was typed: it clamps to each field's bounds.
    if (mentions.success) $('admin-mention-days').value = mentions.days;
    Object.entries(logs.settings).forEach(([k, v]) => { const el = $(`admin-ret-${k}`); if (el) el.value = v; });
    paintUsage(logs.scan);
    if (announce) showToast(t('admin.retention.saved'), 'success');
    return true;
};

/** Save, then sweep now rather than tonight — after saying exactly what it will delete. */
const cleanNow = async () => {
    const res = await api.call('admin_retention_settings', Object.fromEntries(
        LOGS.map(i => [`${i}_days`, String(num(`admin-ret-${i}_days`))])));
    if (!res.success) return;
    const files = LOGS.reduce((n, i) => n + (num(`admin-ret-${i}_days`) > 0 ? res.scan[i].old_files : 0), 0);
    const bytes = LOGS.reduce((n, i) => n + (num(`admin-ret-${i}_days`) > 0 ? res.scan[i].old_bytes : 0), 0);
    if (!files) { showToast(t('admin.retention.clean-none'), 'info'); return; }
    const ok = await confirmModal(t('admin.retention.clean'), {
        message: t('admin.retention.clean-confirm', { files, size: fmtSize(bytes) }),
        confirmLabel: t('admin.retention.clean'), dangerous: true,
    });
    if (!ok || !(await save(false))) return;
    const run = await api.call('admin_retention_settings', { run: '1' }, 'POST');
    if (!run.success) { showToast(run.message || t('admin.retention.failed'), 'error'); return; }
    const n = Object.values(run.deleted || {}).reduce((a, b) => a + b, 0);
    showToast(t('admin.retention.cleaned', { files: n }), 'success');
    paintUsage(run.scan);
};

export const loadRetentionPane = async () => {
    const body = $('admin-retention-body');
    if (!body) return;
    body.innerHTML = `<p class="admin-loading">${esc(t('admin.users.loading'))}</p>`;
    const [data, chat, mentions] = await Promise.all([
        api.call('admin_retention_settings'),
        api.call('admin_chat_retention'),
        api.call('admin_mention_settings'),
    ]);
    if (!data.success) { body.innerHTML = `<p class="admin-empty">${esc(data.message || t('admin.retention.failed'))}</p>`; return; }
    _fields = data.fields || {};
    render(data, chat.success ? (chat.policy ?? '') : '', mentions.success ? mentions.days : 90);
};
