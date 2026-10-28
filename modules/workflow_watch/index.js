// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Tells an administrator when a workflow run fails, with a link to its log.
 *
 * Workflows run in the cron runner, minutes after the save that started them, so a
 * failure used to surface only in Admin → Workflows → History — or as an email once five
 * in a row had switched the workflow off. This raises a **sticky** notification the moment
 * a run fails, for the same reason `job_watch` does: a three-second toast about something
 * that happened while you were elsewhere is one you will miss.
 *
 * Administrators only: workflows are theirs, and the log is admin-only. The runner writes a
 * log for every failed run (not just AI actions), so the link always has something to show.
 */
import { api } from '../core/api.js';
import { watch, rtTopic } from '../realtime/index.js';
import { showStickyToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';

const POLL_MS = 60000;
const POLL_SLOW_MS = 600000;   // safety net while push is live; see modules/realtime

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// Run ids already known. Filled silently on the first poll: a failure from before this page
// loaded is history, not news — the rule job_watch and the mentions badge follow too.
let _seen = null;
let _timer = null;

/** The run's log in the shared dialog, or its one-line error when there is no log file. */
const openLog = async (run) => {
    const res = run.has_log
        ? await api.call('admin_get_workflow_runs', { id: run.workflow_id, log: run.id })
        : null;
    const where = `${run.space ? `${run.space}/` : ''}${run.path}`;
    const when  = run.finished_at ? new Date(run.finished_at).toLocaleString() : '';
    const body  = res?.success && res.log ? res.log : run.error;
    await confirmModal(t('wfwatch.log-title', { name: run.workflow_name }), {
        messageHtml: `<div class="jobs-view">
            <p class="wf-sub">${esc(where)}${when ? ` · ${esc(when)}` : ''}</p>
            <pre class="jobs-log jobs-log-view">${esc(body || t('wfwatch.no-log'))}</pre></div>`,
        confirmLabel: t('chat.cmd.ai-users-close'),
        hideCancel: true,
    });
};

const announce = (run) => {
    showStickyToast({
        key:   `wf:${run.id}`,           // one notification per run, however often we poll
        type:  'error',
        title: t('wfwatch.failed', { name: run.workflow_name }),
        body:  run.error || '',
        linkText: t('wfwatch.open-log'),
        onLink:   () => openLog(run),
    });
};

const poll = async () => {
    // background(), not call(): a poller on call() would hold the session open for as long
    // as a tab sits there. Same reason as job_watch and the mentions badge.
    const res = await api.background('admin_workflow_failures');
    if (!res?.success) return;
    const runs = res.runs || [];
    if (_seen === null) {
        _seen = new Set(runs.map(r => r.id));
        return;                       // first look: establish the baseline, say nothing
    }
    // Oldest first, so stacked notifications end with the newest on top.
    for (const run of [...runs].reverse()) {
        if (_seen.has(run.id)) continue;
        _seen.add(run.id);
        announce(run);
    }
    // Forget ids that have aged out of the response so the set cannot grow forever.
    for (const id of [..._seen]) if (!runs.some(r => r.id === id)) _seen.delete(id);
};

export const init = () => {
    if (window.WIKI_ROLE !== 'admin') return;
    poll();
    _timer = watch(rtTopic.workflow(window.WIKI_USER_UID || 0), () => {
        if (document.hidden) return;      // an unwatched tab needs no notifications
        poll();
    }, { fast: POLL_MS, slow: POLL_SLOW_MS });
    // A tab returning to the foreground may have missed several ticks.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
};

export const stop = () => { if (_timer) { _timer(); _timer = null; } };
