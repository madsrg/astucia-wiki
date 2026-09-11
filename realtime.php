<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// REALTIME — publishing invalidation hints to the Mercure hub
//
// The wiki's six polling loops become one push channel. See the design doc for the whole
// argument; the three rules that matter when reading this file:
//
//   1. **An event is a hint, never a payload.** It says "thread X changed, re-read it". The
//      actual read still goes through api.php and its guards, so the hub never becomes a
//      second content-serving path that would need its own copy of Space filtering. A
//      client that misses an event refetches on reconnect and is immediately correct.
//   2. **The subscribe JWT is the ACL.** The hub enforces the token's topic selectors
//      itself, so Space isolation on the push channel is declarative and lives outside our
//      code. The selectors come from `actor_spaces_filter()` — the same allowlist every
//      other entry point uses, not a second copy of it.
//   3. **A write must never fail because the hub is down.** Every failure here is swallowed
//      and logged, exactly as the audit writer does.
//
// PHP holds no persistent connection: publishing is an ordinary POST to localhost.
// =================================================================

require_once __DIR__ . '/service_auth.php';   // actor_spaces_filter()

const WIKI_RT_PUBLISH_TIMEOUT = 2;    // seconds; the hub is on loopback
const WIKI_RT_CONNECT_TIMEOUT = 300;  // milliseconds

function wiki_realtime_enabled(): bool {
    return defined('ENABLE_REALTIME') && ENABLE_REALTIME
        && defined('MERCURE_JWT_KEY') && MERCURE_JWT_KEY !== '';
}

function wiki_realtime_key(): string {
    return defined('MERCURE_JWT_KEY') ? (string)MERCURE_JWT_KEY : '';
}

function wiki_realtime_ticket_ttl(): int {
    return defined('REALTIME_TICKET_TTL') ? max(60, (int)REALTIME_TICKET_TTL) : 3600;
}

/** Where the browser connects. Same-origin, so no CORS and the cookie just flows. */
function wiki_realtime_public_url(): string {
    return defined('MERCURE_PUBLIC_URL') ? (string)MERCURE_PUBLIC_URL : '/.well-known/mercure';
}

// ── JWT ──────────────────────────────────────────────────────────────────────
// HS256 by hand rather than a composer package. It is a hash and two base64url encodings,
// the wiki installs by copying a directory, and the OIDC dependency is already optional —
// adding a hard one for thirty lines would be the wrong trade.

function _wiki_rt_b64(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function wiki_realtime_jwt(array $mercure_claim, int $ttl): string {
    $key = wiki_realtime_key();
    if ($key === '') return '';
    $header  = _wiki_rt_b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = _wiki_rt_b64(json_encode([
        'mercure' => $mercure_claim,
        'iat'     => time(),
        'exp'     => time() + $ttl,
    ], JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', "$header.$payload", $key, true);
    return "$header.$payload." . _wiki_rt_b64($sig);
}

/**
 * The publisher token. Short-lived and never leaves the server.
 *
 * `publish: ['*']` lets the wiki publish any topic; it is the wiki's own hub. The
 * interesting restriction is on the *subscribe* side, which is where an untrusted party is.
 */
function wiki_realtime_publish_token(): string {
    static $tok = null;
    if ($tok === null) $tok = wiki_realtime_jwt(['publish' => ['*']], 300);
    return $tok;
}

/**
 * A subscriber ticket for one actor.
 *
 * The selector list *is* the Space allowlist. `null` from actor_spaces_filter() means
 * unrestricted, which gets the whole tree; anything else is enumerated, plus the actor's own
 * user topics so they can receive their job and mention events.
 *
 * `{+rest}` is RFC 6570 reserved expansion — it matches slashes, so one selector per Space
 * covers every resource inside it.
 */
function wiki_realtime_subscribe_token(?array $allowed_spaces, int $uid): string {
    $selectors = [];
    if ($allowed_spaces === null) {
        $selectors[] = 'wiki/{+rest}';
    } else {
        foreach ($allowed_spaces as $s) {
            $s = trim((string)$s);
            if ($s === '' || str_starts_with($s, '.')) continue;
            $selectors[] = 'wiki/' . $s . '/{+rest}';
        }
        // Root-level content sits outside every Space and stays readable to a restricted
        // actor, exactly as wiki_space_allowed() decides for reads.
        $selectors[] = 'wiki//{+rest}';
        if ($uid > 0) $selectors[] = 'wiki/user/' . $uid . '/{+rest}';
    }
    return wiki_realtime_jwt(['subscribe' => $selectors], wiki_realtime_ticket_ttl());
}

// ── Topics ───────────────────────────────────────────────────────────────────
// A closed vocabulary of five. This is the public contract an external bridge is written
// against, so it is built from helpers rather than string-concatenated at each call site.

function wiki_rt_topic_chat(?string $space, string $rel_path): string {
    return 'wiki/' . (string)$space . '/chat/' . $rel_path;
}
function wiki_rt_topic_page(?string $space, string $rel_path): string {
    return 'wiki/' . (string)$space . '/page/' . $rel_path;
}
function wiki_rt_topic_tree(?string $space): string {
    return 'wiki/' . (string)$space . '/tree';
}
function wiki_rt_topic_job(int $uid): string {
    return 'wiki/user/' . $uid . '/job';
}
function wiki_rt_topic_mention(int $uid): string {
    return 'wiki/user/' . $uid . '/mention';
}
/**
 * Where the admin monitor's round-trip test goes.
 *
 * Under `wiki/user/<uid>/`, so it needs no new selector: every subscriber already holds
 * that prefix for its own jobs and mentions, and nobody else's token matches it — the
 * test cannot be seen by another account even though it travels the real channel.
 */
function wiki_rt_topic_diag(int $uid): string {
    return 'wiki/user/' . $uid . '/diag';
}

// ── Publishing ───────────────────────────────────────────────────────────────

/**
 * Push one invalidation hint.
 *
 * **`private=on` is load-bearing.** A Mercure update that is not marked private is delivered
 * to every subscriber of that topic whether or not their token authorises it — the hub only
 * consults the subscribe selectors for private updates. Omitting it would hand every
 * subscriber the change stream of every Space, which is precisely the isolation failure
 * fixed in v2026.9.3, reintroduced on a different channel. There is a test for it.
 */
/**
 * POST one update to the hub and say what happened.
 *
 * Split out of wiki_realtime_publish() so the admin monitor can report the outcome that
 * publishing deliberately swallows — there is still exactly one place that talks to the
 * hub, which matters because `private=on` is set here and nowhere else.
 *
 * @return array{ok:bool,code:int,ms:int,error:string,reason:string}
 */
function wiki_realtime_post(string $topic, array $data = []): array {
    $out = ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => '', 'reason' => ''];
    if (!wiki_realtime_enabled()) return ['reason' => 'disabled'] + $out;
    if (!function_exists('curl_init')) return ['reason' => 'no_curl'] + $out;

    $url = defined('MERCURE_INTERNAL_URL') ? rtrim((string)MERCURE_INTERNAL_URL, '/') : '';
    if ($url === '') return ['reason' => 'no_internal_url'] + $out;

    $data += ['topic' => $topic, 'ts' => time()];
    $body = http_build_query([
        'topic'   => $topic,
        'data'    => json_encode($data, JSON_UNESCAPED_SLASHES),
        'private' => 'on',
    ]);

    $started = microtime(true);
    $ch = curl_init($url . '/.well-known/mercure');
    curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $body,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_TIMEOUT           => WIKI_RT_PUBLISH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT_MS => WIKI_RT_CONNECT_TIMEOUT,
        CURLOPT_HTTPHEADER        => [
            'Authorization: Bearer ' . wiki_realtime_publish_token(),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $out['code']  = $code;
    $out['ms']    = (int)round((microtime(true) - $started) * 1000);
    $out['error'] = $err;
    $out['ok']    = $res !== false && $code >= 200 && $code < 300;
    if (!$out['ok']) $out['reason'] = $err !== '' ? 'transport' : 'http_' . $code;
    return $out;
}

function wiki_realtime_publish(string $topic, array $data = []): void {
    $r = wiki_realtime_post($topic, $data);
    if ($r['ok'] || $r['reason'] === 'disabled' || $r['reason'] === 'no_curl'
        || $r['reason'] === 'no_internal_url') {
        return;
    }
    // Logged, never propagated: a save that succeeded must not report failure because
    // a notification could not be delivered. Subscribers notice on their next poll.
    @error_log("realtime publish failed ($topic): HTTP {$r['code']} {$r['error']}");
}

/**
 * Publish for a path, choosing the topic from what the file *is*.
 *
 * A `.chat` write is a chat event and everything else is a page event; both also move the
 * tree when the file appears, disappears or is renamed. Keeping that decision here means the
 * ~25 call sites do not each have to remember it.
 */
function wiki_realtime_publish_path(?string $space, string $rel_path, string $change = 'update'): void {
    if (!wiki_realtime_enabled()) return;
    $rel_path = ltrim(str_replace('\\', '/', $rel_path), '/');
    if ($rel_path === '') return;

    $is_chat = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION)) === 'chat';
    $topic   = $is_chat ? wiki_rt_topic_chat($space, $rel_path)
                        : wiki_rt_topic_page($space, $rel_path);
    wiki_realtime_publish($topic, ['type' => $is_chat ? 'chat' : 'page',
                                   'space' => (string)$space, 'path' => $rel_path,
                                   'change' => $change]);

    if ($change !== 'update') wiki_realtime_publish_tree($space);
}

function wiki_realtime_publish_tree(?string $space): void {
    if (!wiki_realtime_enabled()) return;
    wiki_realtime_publish(wiki_rt_topic_tree($space), ['type' => 'tree', 'space' => (string)$space]);
}

/**
 * Publish for an **absolute** path, deriving the Space from the path itself.
 *
 * `wiki_path_space()` is the function the read ACL already uses to decide which Space a
 * resolved path belongs to. Reusing it here means a topic's Space and the allowlist that
 * gates subscription to it are computed by the same code — they cannot drift apart and
 * quietly publish a Space's changes onto a topic somebody else is authorised for.
 */
function wiki_realtime_publish_file(string $abs_path, string $change = 'update'): void {
    if (!wiki_realtime_enabled()) return;
    if (!defined('PAGES_DIR')) return;

    $space = function_exists('wiki_path_space') ? wiki_path_space($abs_path) : null;
    if ($space === null) return;   // outside PAGES_DIR entirely — not ours to announce

    $root = rtrim(realpath(PAGES_DIR) ?: PAGES_DIR, '/');
    $real = realpath($abs_path) ?: $abs_path;
    if (!str_starts_with($real, $root . '/')) return;

    $rel = substr($real, strlen($root) + 1);
    if ($space !== '') $rel = substr($rel, strlen($space) + 1);   // strip the Space segment
    wiki_realtime_publish_path($space, $rel, $change);
}

/**
 * Write a `.chat` thread and announce it.
 *
 * Chat is the one content type that never passes through `PageIndexer` — a posted message
 * changes the file but not the index — so the hook that covers every page write misses it
 * entirely. Rather than sprinkle a publish call after each of the dozen `file_put_contents`
 * sites that write a thread (and lose one the next time a thirteenth is added), those sites
 * now share this. It is the funnel chat never had.
 *
 * Retention is deliberately *not* applied here: auto-purge is documented as running when
 * somebody posts, not on every write, so folding it in would start trimming threads on a
 * topic rename.
 */
function wiki_chat_write(string $abs_path, array $chat_data): bool {
    $ok = file_put_contents($abs_path, json_encode($chat_data, JSON_PRETTY_PRINT)) !== false;
    if ($ok) wiki_realtime_publish_file($abs_path, 'update');
    return $ok;
}
