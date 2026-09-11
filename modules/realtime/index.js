// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// REALTIME — one SSE connection, and the rules that keep it honest.
//
// Modules do not change what they fetch, only what triggers it. A poller becomes:
//
//     const off = subscribe(rtTopic.chat(space, path), refetch);
//
// with `refetch` being the body the timer used to run. Three rules are load-bearing:
//
//   1. **Polling is demoted, never removed.** Every subscriber keeps its timer; going live
//      only slows it (see `pollInterval`). An SSE stream can go zombie through a proxy
//      without ever firing `error`, and a slow safety poll is what stops that being
//      invisible. It is also what makes the whole feature safe to switch off.
//   2. **Reconnect means refetch.** Events carry no payload and the hub keeps no history,
//      so a gap in the stream is repaired by re-reading, exactly as a poller's first tick
//      does. Every handler is therefore called once on (re)connect.
//   3. **The ticket is short-lived and lives in a cookie.** It is never handed to
//      JavaScript, so an XSS on the page cannot walk off with a subscribe credential.
import { api } from '../core/api.js';
import { state } from '../core/state.js';

/** Mirrors the PHP topic helpers in realtime.php. Keep the two in step. */
export const rtTopic = {
    chat:    (space, path) => `wiki/${space || ''}/chat/${path}`,
    page:    (space, path) => `wiki/${space || ''}/page/${path}`,
    tree:    (space)       => `wiki/${space || ''}/tree`,
    job:     (uid)         => `wiki/user/${uid}/job`,
    mention: (uid)         => `wiki/user/${uid}/mention`,
    // The admin monitor's round-trip test. Under wiki/user/<uid>/ so it needs no new
    // selector in the subscribe token, and nobody else's token can match it.
    diag:    (uid)         => `wiki/user/${uid}/diag`,
};

const FAIL_WINDOW_MS  = 60_000;    // three failures inside this and we call it down
const FAIL_LIMIT      = 3;
const RETRY_MS        = 300_000;   // 5 min before trying the subscription again
const COALESCE_MS     = 150;       // a save fires page+tree together; one refetch will do

let es          = null;
let live        = false;
let available   = null;    // null = not yet asked; false = server says realtime is off
let ticketUrl   = '/.well-known/mercure';
let myUid       = 0;
let failures    = [];      // timestamps
let retryTimer  = null;
const handlers  = new Map();   // topic -> Set<fn>
const pending   = new Map();   // topic -> timeout id

export const isLive = () => live;

/**
 * The interval a poller should use right now.
 *
 * Callers pass both numbers rather than reading `isLive()` and branching, so the demotion
 * rule is expressed in one place and a module cannot accidentally suspend itself entirely.
 */
export const pollInterval = (fast, slow) => (live ? slow : fast);

const announce = () => {
    document.dispatchEvent(new CustomEvent('wiki:realtime', { detail: { live } }));
};

const setLive = (v) => {
    if (live === v) return;
    live = v;
    announce();
};

/** Run a topic's handlers, coalescing a burst into one call. */
const fire = (topic) => {
    const set = handlers.get(topic);
    if (!set || !set.size) return;
    if (pending.has(topic)) clearTimeout(pending.get(topic));
    pending.set(topic, setTimeout(() => {
        pending.delete(topic);
        for (const fn of set) { try { fn(); } catch (e) { console.error('realtime handler', e); } }
    }, COALESCE_MS));
};

/** Rule 2: a fresh connection is a gap of unknown length, so everyone re-reads. */
const resyncAll = () => { for (const topic of handlers.keys()) fire(topic); };

const noteFailure = () => {
    const now = Date.now();
    failures = failures.filter((t) => now - t < FAIL_WINDOW_MS);
    failures.push(now);
    if (failures.length >= FAIL_LIMIT) {
        // Give up for a while rather than reconnecting in a tight loop against a hub that
        // is not coming back. Subscribers are already polling; they simply stay fast.
        close();
        setLive(false);
        if (!retryTimer) retryTimer = setTimeout(() => { retryTimer = null; connect(); }, RETRY_MS);
    }
};

const close = () => {
    if (es) { try { es.close(); } catch { /* already gone */ } }
    es = null;
};

/**
 * Mint a ticket and open the stream.
 *
 * One connection for everything: the request asks for `wiki/{+rest}` and the *token* decides
 * what actually arrives. That is the point of the design — the Space allowlist is enforced
 * by the hub from the JWT, not re-implemented here — and it means navigating between pages
 * or Spaces never reopens the connection.
 */
const connect = async () => {
    if (es || available === false) return;
    let res;
    try {
        // background(), or the realtime layer would hold every session open for as long as
        // a tab is left on screen — the exact problem it exists to remove.
        res = await api.background('realtime_ticket');
    } catch {
        noteFailure();
        return;
    }
    if (!res || res.success === false) { noteFailure(); return; }
    if (!res.enabled) { available = false; setLive(false); return; }

    available = true;
    ticketUrl = res.url || ticketUrl;
    myUid     = Number(res.uid || 0);

    const url = `${ticketUrl}?topic=${encodeURIComponent('wiki/{+rest}')}`;
    try {
        es = new EventSource(url, { withCredentials: true });
    } catch {
        noteFailure();
        return;
    }

    es.onopen = () => {
        failures = [];
        setLive(true);
        resyncAll();
    };
    es.onmessage = (ev) => {
        let data;
        try { data = JSON.parse(ev.data); } catch { return; }
        if (data && data.topic) fire(data.topic);
    };
    es.onerror = () => {
        // EventSource reconnects on its own while the connection is merely interrupted;
        // a CLOSED stream is the browser giving up, and only that counts as a failure.
        if (!es || es.readyState === EventSource.CLOSED) {
            close();
            setLive(false);
            noteFailure();
            if (failures.length < FAIL_LIMIT) setTimeout(connect, 2000);
        }
    };
};

/**
 * Ask to be told when `topic` changes. Returns an unsubscribe function.
 *
 * Subscribing does not start a fetch — the caller has just loaded whatever it is watching.
 * The first call comes on the next event, or on the next reconnect.
 */
export function subscribe(topic, fn) {
    if (!topic || typeof fn !== 'function') return () => {};
    if (!handlers.has(topic)) handlers.set(topic, new Set());
    handlers.get(topic).add(fn);
    if (available === null) connect();
    return () => {
        const set = handlers.get(topic);
        if (!set) return;
        set.delete(fn);
        if (!set.size) handlers.delete(topic);
    };
}

/**
 * The whole pattern in one call: run `fn` on a timer, and also whenever any of `topics`
 * fires — with the timer automatically re-armed at the right speed as the stream comes and
 * goes. Returns a stop function that tears down both.
 *
 * Every poller in the app is one of these. Having them share it is what stops rule 1 from
 * being re-implemented five times, and stops the next module from quietly clearing its
 * timer on subscribe and losing the safety net.
 */
export function watch(topics, fn, { fast, slow }) {
    const list = (Array.isArray(topics) ? topics : [topics]).filter(Boolean);
    let timer = null;
    const arm = () => {
        if (timer) clearInterval(timer);
        timer = setInterval(fn, pollInterval(fast, slow));
    };
    const offs   = list.map((t) => subscribe(t, fn));
    const onMode = () => arm();     // going live (or losing the hub) changes the right rate
    document.addEventListener('wiki:realtime', onMode);
    arm();
    return () => {
        if (timer) clearInterval(timer);
        timer = null;
        offs.forEach((off) => off());
        document.removeEventListener('wiki:realtime', onMode);
    };
}

export function initRealtime() {
    // Nothing connects until something subscribes: a wiki nobody has opened a chat or a
    // page in has no reason to hold a stream open.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        // A backgrounded tab's stream is often dropped by the browser or an intermediary.
        // Coming back is the moment to find out, and to re-read whatever was missed.
        if (available !== false && !es) { failures = []; connect(); }
        else if (live) resyncAll();
    });
}
