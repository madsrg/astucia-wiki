// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// The /jobs command: your own background jobs, and the log of any of them.
//
// Until now a queued job told you two things — an ETA when you asked for it, and its
// answer when it arrived. If it failed you got one line in the thread, and the log that
// explains why was admin-only. This is the user-facing half: state, and the same log the
// admin panel reads, authorised by having been the one who asked for it.
//
// Shared by team chat and Page Chat, like the /aiUsers overview beside it, so the two
// cannot drift.
import { api } from './api.js';
import { confirmModal } from './utils.js';
import { t } from '../i18n/index.js';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// "3m ago" — the absolute time is on the row's tooltip, which is enough for a list whose
// whole purpose is "what happened recently".
const ago = (iso) => {
    if (!iso) return '—';
    const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (secs < 90)    return t('jobs.ago-now');
    if (secs < 5400)  return t('jobs.ago-min', { n: Math.round(secs / 60) });
    if (secs < 172800) return t('jobs.ago-hour', { n: Math.round(secs / 3600) });
    return t('jobs.ago-day', { n: Math.round(secs / 86400) });
};

const STATE_LABEL = {
    queued:  'jobs.state-queued',
    running: 'jobs.state-running',
    ok:      'jobs.state-ok',
    error:   'jobs.state-error',
};

export const buildJobsTableHtml = (rows, runnerOk) => {
    const body = rows.map(j => {
        const stateKey = STATE_LABEL[j.state] || 'jobs.state-queued';
        // A queued job with no runner behind it is the failure mode with no error and no
        // log — it simply never happens. Say so here rather than let it read as "soon".
        const stalled = j.state === 'queued' && !runnerOk;
        const state = `<span class="jobs-state jobs-state-${esc(j.state)}">${esc(t(stateKey))}</span>`
            + (stalled ? ` <span class="jobs-stalled">${esc(t('jobs.no-runner'))}</span>` : '');
        const log = j.has_log
            ? `<button type="button" class="btn btn-sm btn-secondary jobs-log-btn" data-job="${esc(j.id)}">${esc(t('jobs.log'))}</button>`
            : '<span class="ai-users-empty">—</span>';
        // Where it ran sits under the request rather than in a column of its own: the
        // two belong together, a space/path is the widest thing in the table, and a
        // column of them pushed the request — the one cell you actually read — into an
        // ellipsis. Omitted rather than shown as a dash when there is no thread, so the
        // row stays one line instead of carrying an empty second one. It keeps the old
        // column heading as its tooltip, which is the only place that label now appears.
        const where = j.chat
            ? `<span class="jobs-where" title="${esc(t('jobs.col-where'))}">${esc(j.space || '')}${j.space ? ' / ' : ''}${esc(j.chat)}</span>`
            : '';
        return `<tr title="${esc(j.created_at || '')}">
            <td>#${esc(j.ai_user)}</td>
            <td>${state}</td>
            <td class="jobs-prompt"><span class="jobs-prompt-text">${esc(j.prompt)}</span>${where}</td>
            <td>${esc(ago(j.finished_at || j.created_at))}</td>
            <td>${log}</td>
        </tr>`;
    }).join('');
    return `<table class="ai-users-table jobs-table">
        <thead><tr>
            <th>${esc(t('jobs.col-ai'))}</th>
            <th>${esc(t('jobs.col-state'))}</th>
            <th>${esc(t('jobs.col-request'))}</th>
            <th>${esc(t('jobs.col-when'))}</th>
            <th></th>
        </tr></thead>
        <tbody>${body}</tbody>
    </table>`;
};

const logViewHtml = (res) => `<div class="jobs-view">
    <div class="jobs-view-bar"><button type="button" class="btn btn-sm btn-secondary jobs-back-btn">${esc(t('btn.back'))}</button></div>
    ${res.success
        ? `<pre class="jobs-log jobs-log-view">${esc(res.content)}</pre>`
        : `<p>${esc(res.message || t('jobs.log-failed'))}</p>`}
</div>`;

export const showJobsOverview = async () => {
    const res  = await api.call('list_my_jobs');
    const rows = res.success ? (res.data || []) : [];

    if (!rows.length) {
        await confirmModal(t('jobs.title'), {
            message: t('jobs.none'),
            confirmLabel: t('chat.cmd.ai-users-close'),
            hideCancel: true,
        });
        return;
    }

    const listHtml = `<div class="jobs-view">${buildJobsTableHtml(rows, res.runner_ok !== false)}</div>`;

    // A log opens *inside* the list rather than in place of it. confirmModal is a single
    // shared dialog, so the log used to close the list to borrow it, and closing the log
    // left you back at the chat with the list gone — one click to read a log, three to
    // read a second. The dialog's promise stays pending across the swap; only its title
    // and body change, and `Back` puts the list back exactly as it was, unfetched.
    const host    = document.getElementById('confirm-modal-message');
    const titleEl = document.getElementById('confirm-modal-title');
    const showList = () => {
        if (titleEl) titleEl.textContent = t('jobs.title');
        if (host) host.innerHTML = listHtml;
    };

    // Delegated onto the host, which survives the swap, so both views' buttons are
    // covered by one listener and it is removed once when the dialog closes.
    const onClick = async (e) => {
        if (e.target.closest('.jobs-back-btn')) { e.preventDefault(); showList(); return; }
        const btn = e.target.closest('.jobs-log-btn');
        if (!btn) return;
        e.preventDefault();
        const log = await api.call('get_my_job_log', { id: btn.dataset.job });
        // The dialog can be closed while the log is in flight; writing into it then would
        // paint a log behind a hidden overlay and leave it there for the next caller.
        if (document.getElementById('confirm-modal')?.classList.contains('hidden')) return;
        if (titleEl) titleEl.textContent = t('jobs.log-title');
        if (host) host.innerHTML = logViewHtml(log);
    };
    host?.addEventListener('click', onClick);
    try {
        await confirmModal(t('jobs.title'), {
            messageHtml: listHtml,
            confirmLabel: t('chat.cmd.ai-users-close'),
            hideCancel: true,
        });
    } finally {
        host?.removeEventListener('click', onClick);
    }
};
