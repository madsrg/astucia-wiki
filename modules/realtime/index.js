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
//   4. **One stream per browser, held by the active tab.** Over HTTP/1.1 a browser allows
//      about six connections per host, and an SSE stream holds one for as long as it
//      lives — so six open tabs used to leave no connection for anything else, and the
//      whole wiki hung in that browser. Now the tabs elect a leader over a
//      BroadcastChannel: the leader holds the one EventSource and relays every event to
//      the others. The tab you switch to claims it; a tab that is hidden keeps it only
//      until another tab becomes visible, so with the browser in the background the
//      last-active tab still delivers (modules/attention depends on that). Relaying is
//      safe because every tab of a browser shares one session cookie, so every tab's
//      ticket would have received exactly the same events. See *One stream per browser*
//      in .claude/rules/realtime.md.
import { api } from '../core/api.js';
import { state } from '../core/state.js';

/** Mirrors the PHP topic helpers in realtime.php. Keep the two in step. */
// Percent-encode a topic component, byte-identically to PHP's rawurlencode() — see
// wiki_rt_seg() in realtime.php for why this matters. encodeURIComponent leaves !'()* alone
// and rawurlencode does not, so those are escaped here by hand. A topic containing a raw
// space is not a valid URI, and the hub delivers such an update to nobody while reporting
// success, so an unencoded topic loses every event for any page whose name has a space in it.
const rtSeg = (s) => encodeURIComponent(String(s ?? ''))
    .replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
// Per segment, so `/` survives and a topic still reads as a path.
const rtPath = (p) => String(p ?? '').split('/').map(rtSeg).join('/');

export const rtTopic = {
    chat:    (space, path) => `wiki/${rtSeg(space || '')}/chat/${rtPath(path)}`,
    page:    (space, path) => `wiki/${rtSeg(space || '')}/page/${rtPath(path)}`,
    tree:    (space)       => `wiki/${rtSeg(space || '')}/tree`,
    users:   ()            => 'wiki//users',   // see wiki_rt_topic_users()
    job:     (uid)         => `wiki/user/${uid}/job`,
    mention: (uid)         => `wiki/user/${uid}/mention`,
    workflow: (uid)        => `wiki/user/${uid}/workflow`,   // a run failed; admins only
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

// Rule 4 — the election. Every message carries the sender's tab id; a claim also carries
// the time it was made, and **the latest claim wins**, ties broken by tab id, so two tabs
// that claim at the same moment agree on the winner without another round trip.
const CHANNEL           = 'astucia-wiki-realtime';
const BEAT_MS           = 10_000;   // the leader says it is alive this often
const LEADER_TIMEOUT_MS = 35_000;   // silence this long and a visible tab takes over
const tabId   = Math.random().toString(36).slice(2) + Date.now().toString(36);
let bc        = null;      // the BroadcastChannel, or null where there is none (one stream per tab, as before)
let role      = 'none';    // 'leader' | 'follower' | 'none' (nothing has subscribed yet)
let myClaim   = 0;         // when this tab last claimed the stream
let leader    = null;      // { id, ts, seen, visible } as last heard
let started   = false;
let beatTimer = null;
let draining  = null;      // timeout id while an ex-leader keeps its stream for the handoff
let openTimer = null;      // a new leader's deadline for its stream to open
const HANDOFF_MS = 15_000;

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
    if (role === 'leader') beat();
};

const post = (msg) => {
    if (!bc) return;
    try { bc.postMessage({ ...msg, from: tabId }); } catch { /* channel closed with the page */ }
};

/** The leader's heartbeat: alive, connected or not, and whether anyone is looking at it. */
const beat = () => post({ type: 'beat', ts: myClaim, live, visible: !document.hidden });

const leaderFresh = () => !!leader && Date.now() - leader.seen < LEADER_TIMEOUT_MS;

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
 * One connection for everything: the request asks for `wiki/*` and the *token* decides
 * what actually arrives. That is the point of the design — the Space allowlist is enforced
 * by the hub from the JWT, not re-implemented here — and it means navigating between pages
 * or Spaces never reopens the connection.
 */
const connect = async () => {
    if (es || available === false) return;
    if (bc && role !== 'leader') return;       // a follower is fed by the leader
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
    if (!res.enabled) { available = false; setLive(false); post({ type: 'off' }); return; }
    // Lost the stream to another tab while the ticket was being fetched.
    if (bc && role !== 'leader') return;

    available = true;
    ticketUrl = res.url || ticketUrl;
    myUid     = Number(res.uid || 0);

    // One matcher for everything the token allows. Mercure 1.0 replaced `topic=` (URI
    // Template) with `match=` (exact) or `match_urlpattern=`; `*` is the URL Pattern
    // wildcard and matches across `/`, so this is the whole tree, exactly as
    // `wiki/{+rest}` was. An unrecognised name under the `match` prefix is a 400, so a
    // typo here fails loudly rather than silently subscribing to nothing.
    const url = `${ticketUrl}?match_urlpattern=${encodeURIComponent('wiki/*')}`;
    try {
        es = new EventSource(url, { withCredentials: true });
    } catch {
        noteFailure();
        return;
    }

    es.onopen = () => {
        failures = [];
        if (openTimer) { clearTimeout(openTimer); openTimer = null; }
        // Rule 2, except across a handoff: the tab that held the stream keeps it until this
        // one is open (see follow()), so a claim from a live leader leaves no gap to repair
        // and nobody needs to re-read. Without that, every tab switch would make every tab
        // refetch everything it watches.
        const gapless = live;
        setLive(true);
        if (!gapless) resyncAll();
        post({ type: 'open', gapless });
    };
    es.onmessage = (ev) => {
        let data;
        try { data = JSON.parse(ev.data); } catch { return; }
        if (data && data.topic) {
            fire(data.topic);
            post({ type: 'event', topic: data.topic });
        }
    };
    es.onerror = () => {
        // An ex-leader draining for a handoff just lets go: the new leader owns recovery.
        if (bc && role !== 'leader') { close(); return; }
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

/** Take the stream: this tab becomes the leader, and whoever held it lets go. */
const claim = () => {
    if (!bc) { connect(); return; }
    if (role === 'leader') return;
    role    = 'leader';
    myClaim = Date.now();
    leader  = { id: tabId, ts: myClaim, seen: myClaim, visible: !document.hidden };
    if (draining) { clearTimeout(draining); draining = null; }
    // `live` is carried over from the leader being replaced, which keeps streaming until
    // this tab's stream opens. If it never does, stop claiming to be live.
    if (openTimer) clearTimeout(openTimer);
    openTimer = setTimeout(() => { openTimer = null; if (role === 'leader' && !es?.readyState) setLive(false); }, HANDOFF_MS);
    post({ type: 'claim', ts: myClaim });
    beat();
    connect();
};

/**
 * Another tab holds the stream now; its events arrive over the channel. An ex-leader keeps
 * its own stream open — still relaying — until the new one reports it is open, so no event
 * falls into the gap between one stream closing and the next opening. `isLive` is passed
 * only by a heartbeat; a claim leaves `live` as it was, for the same reason.
 */
const follow = (from, ts, visible = true, isLive) => {
    const wasLeader = role === 'leader';
    role   = 'follower';
    leader = { id: from, ts, seen: Date.now(), visible };
    if (wasLeader) {
        if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
        if (openTimer)  { clearTimeout(openTimer);  openTimer  = null; }
        if (es && !draining) draining = setTimeout(endDrain, HANDOFF_MS);
        else if (!es) endDrain();
    }
    if (isLive !== undefined) setLive(isLive);
};

const endDrain = () => {
    if (draining) { clearTimeout(draining); draining = null; }
    if (role !== 'leader') close();
};

const outranks = (ts, from) => ts > myClaim || (ts === myClaim && from > tabId);

const onChannel = ({ data: m }) => {
    if (!m || !m.from || m.from === tabId) return;
    switch (m.type) {
        case 'claim':
            // Latest claim wins. A claim older than ours is the other half of a crossing:
            // that tab receives ours too, and yields by the same rule.
            if (role === 'leader') { if (outranks(m.ts, m.from)) follow(m.from, m.ts); }
            else if (!leader || m.ts >= leader.ts || leader.id === m.from) follow(m.from, m.ts);
            break;
        case 'beat':
            if (role === 'leader') {
                // Two leaders (a claim lost while a tab was frozen): the same rule settles it.
                if (outranks(m.ts, m.from)) follow(m.from, m.ts, m.visible, m.live);
                else beat();
                break;
            }
            follow(m.from, m.ts, m.visible, m.live);
            // The leader was hidden and this tab is the one being looked at: take over.
            if (!m.visible && !document.hidden && handlers.size) claim();
            break;
        case 'event':
            // From the leader — or, during a handoff, from the ex-leader still draining,
            // which is what covers the new leader until its own stream is open. A duplicate
            // from the overlap is harmless: events are hints, and fire() coalesces them.
            fire(m.topic);
            break;
        case 'open':
            if (role === 'leader') break;
            if (draining) endDrain();
            if (!m.gapless || !live) resyncAll();
            setLive(true);
            break;
        case 'off':
            available = false;
            setLive(false);
            break;
        case 'hello':
            if (role === 'leader') beat();
            break;
        case 'resign':
            if (leader && leader.id === m.from) {
                leader = null;
                setLive(false);                 // pollers speed up until someone holds it
                if (!document.hidden && handlers.size) claim();
            }
            break;
    }
};

/**
 * The first subscription starts realtime in this tab. A visible tab claims the stream
 * straight away — it is the one being used. A tab opened in the background asks who holds
 * it and listens; it claims only when it is shown.
 */
const start = () => {
    started = true;
    if (typeof BroadcastChannel === 'undefined') { connect(); return; }
    try { bc = new BroadcastChannel(CHANNEL); } catch { bc = null; connect(); return; }
    bc.onmessage = onChannel;
    // The watchdog. A leader that crashed or was frozen says nothing at all; a visible tab
    // that has not heard from it in LEADER_TIMEOUT_MS takes over. Hidden tabs never do —
    // their pollers cover them, and that is what keeps the stream with the active tab.
    beatTimer = setInterval(() => {
        if (role === 'leader') beat();
        else if (handlers.size && !document.hidden && !leaderFresh()) claim();
    }, BEAT_MS);
    // A tab closing hands the stream on rather than leaving the others waiting out the
    // watchdog.
    window.addEventListener('pagehide', () => {
        if (role !== 'leader') return;
        post({ type: 'resign' });
        close();
        role = 'none';
    });
    if (!document.hidden) claim();
    else { role = 'follower'; post({ type: 'hello' }); }
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
    if (!started) start();
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
export function watch(topics, fn, { fast, slow, hidden = 0 }) {
    const list = (Array.isArray(topics) ? topics : [topics]).filter(Boolean);
    let timer = null;
    // `hidden` is a floor applied while the tab is in the background, for a watcher that
    // must keep working there rather than stop. It is a floor and not a rate, so a hub
    // that has already slowed the timer past it is left alone. Most watchers pass
    // nothing: a page nobody is looking at does not need its content re-read, and they
    // keep exactly the two rates they had.
    const rate = () => {
        const base = pollInterval(fast, slow);
        return (hidden && document.hidden) ? Math.max(hidden, base) : base;
    };
    const arm = () => {
        if (timer) clearInterval(timer);
        timer = setInterval(fn, rate());
    };
    const offs   = list.map((t) => subscribe(t, fn));
    const onMode = () => arm();     // going live (or losing the hub) changes the right rate
    document.addEventListener('wiki:realtime', onMode);
    // Only where a floor was asked for, so the five watchers that did not ask gain no
    // listener and no re-arm they never had. A push event is never throttled by any of
    // this — it goes straight to `fn`; the floor is the timer's alone.
    if (hidden) document.addEventListener('visibilitychange', onMode);
    arm();
    return () => {
        if (timer) clearInterval(timer);
        timer = null;
        offs.forEach((off) => off());
        document.removeEventListener('wiki:realtime', onMode);
        if (hidden) document.removeEventListener('visibilitychange', onMode);
    };
}

export function initRealtime() {
    // Nothing connects until something subscribes: a wiki nobody has opened a chat or a
    // page in has no reason to hold a stream open.
    document.addEventListener('visibilitychange', () => {
        if (!started) return;
        if (document.visibilityState !== 'visible') {
            // Tell the others this leader is no longer being looked at, so the tab that
            // is can take the stream at once rather than after the watchdog.
            if (role === 'leader') beat();
            return;
        }
        if (bc && role !== 'leader') {
            // Rule 4: the tab being looked at holds the stream — unless another visible
            // tab (a second window beside this one) already does.
            if (available !== false && !(leaderFresh() && leader.visible)) { claim(); return; }
            if (live) resyncAll();
            return;
        }
        // A backgrounded tab's stream is often dropped by the browser or an intermediary.
        // Coming back is the moment to find out, and to re-read whatever was missed.
        if (available !== false && !es) { failures = []; connect(); }
        else if (live) resyncAll();
        if (role === 'leader') beat();
    });
}
