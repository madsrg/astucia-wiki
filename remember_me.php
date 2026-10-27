<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * "Keep me signed in" for OTP users.
 *
 * Without it an email-code user asks for a new code every time the PHP session goes —
 * on SESSION_TIMEOUT idle, on session GC, on closing the browser. OIDC users don't feel
 * that because the provider's own session signs them straight back in; OTP has no such
 * second layer, so this is it.
 *
 * The cookie is `selector.validator` (a ':' would be URL-encoded by setcookie). Only sha256(validator) is stored, in
 * WIKI_SYSTEM_DATA/remember_tokens.json, so a leaked copy of that file signs nobody in.
 * The selector finds the record without a timing-sensitive search. The token is *not*
 * rotated on use: the app fires several API calls in parallel on load, and rotating
 * would let the first one invalidate the cookie the others carry.
 *
 * Restoring re-reads users.json rather than trusting anything the token recorded, so
 * deleting a user, changing their role or removing their email-code address takes effect at once.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/accounts.php';   // wiki_session_user(), wiki_user_otp_email()

const WIKI_REMEMBER_COOKIE = 'wiki_remember';

function wiki_remember_days(): int {
    return defined('OTP_REMEMBER_DAYS') ? max(0, (int)OTP_REMEMBER_DAYS) : 30;
}

function wiki_remember_enabled(): bool {
    return AUTHENTICATION_ENABLED
        && in_array(AUTHENTICATION, ['otp', 'both'], true)
        && wiki_remember_days() > 0;
}

// The $_SESSION['user'] shape for an OTP user — shared by the code login in auth.php and
// the cookie restore below, so the two cannot drift apart.
function wiki_otp_session_user(array $user, string $email = ''): array {
    $s = wiki_session_user($user, 'otp');
    if ($s['email'] === '') $s['email'] = $email;
    return $s;
}

// Locked read-modify-write of the token store. $fn gets the token map and returns it.
function wiki_remember_mutate(callable $fn): mixed {
    $fh = @fopen(WIKI_SYSTEM_DATA . 'remember_tokens.json', 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $tokens = json_decode(stream_get_contents($fh) ?: '', true)['tokens'] ?? [];
    $now = time();
    $tokens = array_filter($tokens, fn($t) => ($t['expires'] ?? 0) > $now);   // prune as we go
    $result = null;
    $tokens = $fn($tokens, $result);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['tokens' => $tokens], JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function wiki_remember_set_cookie(string $value, int $expires): void {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $path  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    setcookie(WIKI_REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => $path,
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Issue a token for a user who has just proved themselves with a code.
function wiki_remember_issue(int $uid): void {
    if (!wiki_remember_enabled() || $uid <= 0) return;
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + wiki_remember_days() * 86400;
    wiki_remember_mutate(function (array $tokens) use ($selector, $validator, $uid, $expires) {
        $tokens[$selector] = [
            'uid'     => $uid,
            'hash'    => hash('sha256', $validator),
            'created' => time(),
            'expires' => $expires,
        ];
        return $tokens;
    });
    wiki_remember_set_cookie($selector . '.' . $validator, $expires);
    $_SESSION['remembered'] = true;
}

// Drop this browser's token (logout). Other devices keep theirs.
function wiki_remember_forget(): void {
    $cookie = $_COOKIE[WIKI_REMEMBER_COOKIE] ?? '';
    if ($cookie === '') return;
    $selector = explode('.', $cookie, 2)[0];
    wiki_remember_mutate(function (array $tokens) use ($selector) {
        unset($tokens[$selector]);
        return $tokens;
    });
    wiki_remember_set_cookie('', time() - 3600);
}

// Drop every token a user holds — for when the account itself goes away or changes.
function wiki_remember_forget_user(int $uid): void {
    if (!file_exists(WIKI_SYSTEM_DATA . 'remember_tokens.json')) return;
    wiki_remember_mutate(fn(array $tokens) => array_filter($tokens, fn($t) => (int)($t['uid'] ?? 0) !== $uid));
}

/**
 * Called straight after session_start() by every entry point a browser reaches.
 * Rebuilds the session from the cookie when there is no signed-in user, or when the
 * session has passed SESSION_TIMEOUT idle — the check api.php makes later would
 * otherwise log a remembered user out. Returns true when it signed someone in.
 */
function wiki_remember_restore(): bool {
    if (!wiki_remember_enabled()) return false;
    $cookie = $_COOKIE[WIKI_REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || !str_contains($cookie, '.')) return false;

    if (isset($_SESSION['user'])) {
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        $idle    = time() - ($_SESSION['last_activity'] ?? time());
        if ($idle <= $timeout) return false;               // live session, nothing to do
    }

    [$selector, $validator] = explode('.', $cookie, 2);
    $uid = wiki_remember_mutate(function (array $tokens, &$result) use ($selector, $validator) {
        $t = $tokens[$selector] ?? null;
        if ($t && hash_equals($t['hash'] ?? '', hash('sha256', $validator))) {
            $result = (int)$t['uid'];
        }
        return $tokens;
    });

    $user = null;
    if ($uid) {
        $users = json_decode(@file_get_contents(WIKI_SYSTEM_DATA . 'users.json') ?: '', true)['users'] ?? [];
        foreach ($users as $u) {
            // Only while the account still signs in with a code: a token outlives neither
            // the account nor its OTP address.
            if ((int)($u['uid'] ?? -1) === $uid && wiki_user_is_person($u) && wiki_user_otp_email($u) !== '') { $user = $u; break; }
        }
    }
    if (!$user) {
        wiki_remember_forget();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user']          = wiki_otp_session_user($user);
    $_SESSION['last_activity'] = time();
    $_SESSION['remembered']    = true;
    write_access_log('LOGIN_REMEMBERED', wiki_user_otp_email($user) ?: '-', $user['name'] ?? '', $user['role'] ?? 'editor');
    return true;
}
