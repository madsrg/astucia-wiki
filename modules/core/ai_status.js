// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// What an AI run is currently doing — one description, and one poll.
//
// Two server paths write the same `<chat>.ai-status.<id>` file and `get_ai_status` reads
// it back: `trigger_ai_response()` for an inline reply, and `run_agent_job()` for an AI
// user set to always run in the background. Two places in the UI show it — the "AI is
// working" modal, and the placeholder bubble of a queued job, which is the only thing on
// screen for a background run because that path shows no modal at all. So the wording
// lives here rather than in both: a run described two different ways in two places is a
// bug nobody notices until they are on screen together.
import { api } from './api.js';
import { t } from '../i18n/index.js';

const STEP_KEYS = {
    preparing:      'ai.step-preparing',
    calling_api:    'ai.step-calling',
    received:       'ai.step-received',
    executing_tool: 'ai.step-tool',
};

/**
 * One line of text for a status record: the step, plus the detail that makes it worth
 * watching — which API call this is and how long the last one took, or which tool is
 * running. An unrecognised step falls through as its own name rather than vanishing,
 * so a step added on the server is visible here before it is translated.
 */
export const aiStatusStep = (d) => {
    if (!d) return '';
    let step = STEP_KEYS[d.step] ? t(STEP_KEYS[d.step]) : (d.step || '');
    if (d.step === 'calling_api' || d.step === 'received') {
        step += ` (call ${d.api_calls}`
            + (d.last_call_ms ? ', ' + (d.last_call_ms / 1000).toFixed(1) + 's' : '') + ')';
    }
    if (d.step === 'executing_tool' && d.tool) {
        // The step's trailing ellipsis stands in for the name, so drop it once the name
        // is there: "Executing tool: wiki_write_page", not "Executing tool…: …".
        step = step.replace(/(?:…|\.\.\.)$/, '') + `: ${d.tool}`;
    }
    return step;
};

/** The span a queued job's status is written into, by message id. */
export const jobStatusElementId = (msgId) => `ai-job-status-${msgId}`;

// ── The queued-job poll ──────────────────────────────────────────────────────
// Its own timer rather than riding the thread's `chat_messages` poll: that one drops to
// 120 s once the realtime hub is live (a status write publishes no event, and one event
// per step would be a poor trade), and 120 s is not "currently doing".
const POLL_MS = 2000;
let _timer = null;
let _key   = '';

const _stop = () => {
    if (_timer) { clearInterval(_timer); _timer = null; }
    _key = '';
};

const _tick = async (filePath, msgId) => {
    if (document.hidden) return;
    let res;
    // background(), not call(): the session's idle timeout is measured from
    // state.lastApiCallTime, so a timer on call() would hold a session open for as long
    // as a job runs — and a background job is exactly the case where nobody is at the desk.
    try { res = await api.background('get_ai_status', { file: filePath, id: msgId }); }
    catch (e) { return; }
    // No element: a re-render is in flight, or the job has resolved. Either way the next
    // syncJobStatus() decides whether to keep going — this tick just does nothing.
    const el = document.getElementById(jobStatusElementId(msgId));
    if (!el) return;
    const line = aiStatusStep(res?.data);
    if (line) el.textContent = line;
};

/**
 * Keep the newest job-backed pending placeholder current, or stop if there is none.
 *
 * Called after every render rather than started once: a chat view rebuilds its bubbles
 * wholesale, so nothing may hold on to an element, and the render is the only moment
 * that knows whether such a placeholder is still on screen. Keyed by thread and message
 * so repeated renders of the same pending job do not restart the timer.
 */
export const syncJobStatus = (filePath, messages) => {
    const pending = (messages || []).slice().reverse().find(m => m.pending && m.job_id);
    if (!pending) { _stop(); return; }
    const key = `${filePath}|${pending.id}`;
    if (key === _key) return;
    _stop();
    _key = key;
    _tick(filePath, pending.id);   // first reading now, not one interval from now
    _timer = setInterval(() => _tick(filePath, pending.id), POLL_MS);
};

export const stopJobStatus = _stop;
