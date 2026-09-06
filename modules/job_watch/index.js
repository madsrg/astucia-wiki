// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Tells you when one of your one-off AI jobs finishes.
 *
 * A queued job answers into its chat thread, which is exactly where you are not looking —
 * the point of queueing it was to go and do something else. The result could sit there for
 * an hour. This watches your own jobs and raises a **sticky** notification the moment one
 * lands, with its status and a link back to the thread it was started from. Sticky because
 * a three-second toast for something that took twenty minutes is a notification you will
 * miss; it stays until you dismiss it.
 *
 * Scope is `list_my_jobs`, which is one-off jobs belonging to the caller. Scheduled jobs
 * are somebody's cron entry, not a thing you are waiting on.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showStickyToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

const POLL_MS = 60000;
const DONE = new Set(['ok', 'error']);

// job id → last state seen. Populated silently on the first poll: a job that had already
// finished before this page loaded is history, not news — the same rule the mentions badge
// uses for its count.
let _seen = null;
let _timer = null;

const findByPath = (items, path) => {
    for (const item of items || []) {
        if (item.path === path) return item;
        const hit = findByPath(item.children, path);
        if (hit) return hit;
    }
    return null;
};

/** Open the thread a job was started from, switching space first when it is elsewhere. */
const openJobChat = async (job) => {
    if (!job.chat) return;
    const { refreshFileTree, revealAndSelectFile } = await import('../file_tree/index.js');

    let crossSpace = false;
    if (job.space && job.space !== state.currentSpace) {
        const { switchSpaceSilently } = await import('../spaces/index.js');
        switchSpaceSilently(job.space);
        await refreshFileTree();
        crossSpace = true;
    }

    // Prefer the id: it is what survives the thread having been renamed between the job
    // being queued and it finishing.
    let path = job.chat, id = job.chat_id;
    if (id) {
        const res = await api.call('get_path_from_id', { pageid: id });
        if (res.success && res.path) path = res.path;
    } else {
        // No id means that space has never been listed, so nothing has assigned one yet.
        // Walking the tree is what assigns ids, so after a refresh the entry has one.
        if (!crossSpace) await refreshFileTree();
        id = findByPath(state.fullFileTree, path)?.id ?? null;
        if (!id) return;
    }

    const { loadPage } = await import('../page_view/index.js');
    await loadPage(path, id, []);
    revealAndSelectFile(path);
};

const announce = (job) => {
    const ok = job.state === 'ok';
    // The thread's name locates it better than its path: it is what the tab will say.
    const where = (job.chat || '').split('/').pop().replace(/\.chat$/, '');
    showStickyToast({
        key:   `job:${job.id}`,          // one notification per job, however often we poll
        type:  ok ? 'success' : 'error',
        title: t(ok ? 'jobwatch.done' : 'jobwatch.failed', { name: job.ai_user }),
        body:  job.error || job.prompt || '',
        linkText: job.chat ? t('jobwatch.open-chat', { where }) : '',
        onLink:   job.chat ? () => openJobChat(job) : null,
    });
};

const poll = async () => {
    // background(), not call(): the session idle timeout is measured from the last call()
    // and a poller using it would hold every session open for as long as a tab is left
    // sitting there. Same reason the mentions badge uses it.
    const res = await api.background('list_my_jobs');
    if (!res?.success) return;
    const jobs = res.data || [];

    if (_seen === null) {
        _seen = new Map(jobs.map(j => [j.id, j.state]));
        return;                       // first look: establish the baseline, say nothing
    }
    for (const job of jobs) {
        const before = _seen.get(job.id);
        _seen.set(job.id, job.state);
        // Unknown-and-already-finished means it was queued and finished between two polls,
        // which is still news. Unknown-and-queued is simply a job just added.
        if (DONE.has(job.state) && before !== job.state) announce(job);
    }
    // Drop ids that have aged out of the queue file so the map cannot grow forever.
    for (const id of [..._seen.keys()]) if (!jobs.some(j => j.id === id)) _seen.delete(id);
};

export const init = () => {
    // A reader cannot queue a job, so there is never anything here for them.
    if (window.WIKI_ROLE === 'reader') return;
    poll();
    _timer = setInterval(() => {
        if (document.hidden) return;      // an unwatched tab needs no notifications
        poll();
    }, POLL_MS);
    // A tab returning to the foreground may have missed several ticks.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
};

/** Switching space keeps the same queue — the jobs are the user's, not the space's. */
export const stop = () => { if (_timer) { clearInterval(_timer); _timer = null; } };
