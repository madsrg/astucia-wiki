<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PASSWORDS — the `password` authentication mode (see auth_modes.php).
//
// Four stores in WIKI_SYSTEM_DATA, none of them users.json:
//
//   credentials.json      uid → { hash, changedAt, mustChange }. Hashes live apart from the
//                         user records on purpose: users.json is read and sent to the browser
//                         by many paths (the admin panel, backups, AI tools), and a hash in it
//                         would leak through whichever of them forgot to strip it.
//   password_tokens.json  sha256(token) → { uid, expires, purpose }. Set-password and reset
//                         links. Only the hash is stored, like service tokens.
//   login_throttle.json   failed sign-ins per account and per IP address.
//   (users.json)          `credentialsChangedAt` on the record, which ends every session
//                         signed in before it (wiki_session_guard in accounts.php).
//
// The account's login is its login address — the same `otpEmail` field an email-code
// account uses, so uniqueness, lookup and the admin form are shared rather than doubled.
//
// Rules worth knowing before changing anything here:
//   - Every failure says the same thing, and a sign-in for an address nobody has takes as
//     long as one for a real account (a dummy hash is verified), so neither the message nor
//     the timing tells an attacker which accounts exist.
//   - Links are built from APP_BASE_URL (or the OIDC redirect's origin), never from the
//     request's Host header: a reset requested with `Host: evil.example` would otherwise
//     mail the victim a link that hands their token to the attacker.
//   - A password set by any route ends every other session and every "keep me signed in"
//     token, and spends every outstanding link for that account.
//   - Leaving the password mode retires every stored password (wiki_credentials_retire),
//     so switching back later cannot quietly revive old ones.
// =================================================================

require_once __DIR__ . '/auth_modes.php';
require_once __DIR__ . '/accounts.php';

const WIKI_PW_MIN_LENGTH      = 12;
const WIKI_PW_MAX_LENGTH      = 1024;  // argon2/bcrypt cost grows with input; refuse absurd ones
const WIKI_PW_RESET_TTL       = 3600;  // a "forgot password" link: one hour
const WIKI_PW_INVITE_TTL      = 86400; // an admin's "set your password" link: one day
const WIKI_PW_THROTTLE_WINDOW = 900;   // seconds failures are counted over
const WIKI_PW_THROTTLE_USER   = 5;     // failures per account per window
const WIKI_PW_THROTTLE_IP     = 100;   // per IP: high, because a reverse proxy can make many people one address
const WIKI_PW_RESET_GAP       = 60;    // seconds between reset mails to one address
const WIKI_PW_CHANGE_TTL      = 600;   // a forced change after a temporary password must happen within this

// --- Hashing ---------------------------------------------------------------------

function wiki_pw_algo(): string|int|null {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function wiki_pw_hash(string $pw): string {
    return password_hash($pw, wiki_pw_algo());
}

/** null when acceptable, else an i18n key. Length only — composition rules do not help (NIST SP 800-63B). */
function wiki_pw_policy_error(string $pw): ?string {
    $len = function_exists('mb_strlen') ? mb_strlen($pw) : strlen($pw);
    if ($len < WIKI_PW_MIN_LENGTH) return 'login.err.pw-short';
    if ($len > WIKI_PW_MAX_LENGTH) return 'login.err.pw-long';
    return null;
}

// --- The credential store ----------------------------------------------------------

function wiki_json_mutate(string $file, callable $fn): mixed {
    $fh = @fopen($file, 'c+');
    if (!$fh) throw new RuntimeException('Cannot open ' . basename($file));
    flock($fh, LOCK_EX);
    try {
        $doc = json_decode(stream_get_contents($fh) ?: '', true);
        $doc = is_array($doc) ? $doc : [];
        $before = $doc;
        $result = $fn($doc);
        if ($doc !== $before) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fh);
        }
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($file, 0600);
    }
}

function wiki_credentials_file(): string { return WIKI_SYSTEM_DATA . 'credentials.json'; }

function wiki_credentials_get(int $uid): ?array {
    $f = wiki_credentials_file();
    if ($uid <= 0 || !is_file($f)) return null;
    $doc = json_decode((string)@file_get_contents($f), true);
    $c = is_array($doc) ? ($doc[(string)$uid] ?? null) : null;
    return is_array($c) && !empty($c['hash']) ? $c : null;
}

/** Drop a password, for an account that was removed (uids are reused) or retired. */
function wiki_credentials_forget(int $uid): void {
    wiki_pw_tokens_drop_user($uid);
    if (!is_file(wiki_credentials_file())) return;
    wiki_json_mutate(wiki_credentials_file(), function (array &$doc) use ($uid) { unset($doc[(string)$uid]); });
}

/**
 * Leaving the password mode retires every password. Only for a *valid* other mode: a typo
 * in config.php reads as 'invalid', and must not wipe everyone's password on the way.
 */
function wiki_credentials_retire(): void {
    $mode = wiki_auth_mode();
    if ($mode === 'password' || $mode === 'invalid') return;
    foreach (['credentials.json', 'password_tokens.json'] as $f) {
        if (is_file(WIKI_SYSTEM_DATA . $f)) {
            @unlink(WIKI_SYSTEM_DATA . $f);
            if ($f === 'credentials.json') write_access_log('PASSWORDS_RETIRED', '-', '-', 'mode ' . $mode);
        }
    }
}

/**
 * Set a password. Ends every other session (via credentialsChangedAt), every remember-me
 * token and every outstanding link for the account. $keep_session re-stamps the caller's
 * own session so that one survives a change made from inside it.
 */
function wiki_password_set(int $uid, string $pw, bool $must_change = false, bool $keep_session = false): void {
    $now = time();
    wiki_json_mutate(wiki_credentials_file(), function (array &$doc) use ($uid, $pw, $must_change, $now) {
        $doc[(string)$uid] = ['hash' => wiki_pw_hash($pw), 'changedAt' => $now, 'mustChange' => $must_change];
    });
    wiki_credentials_touch($uid, $now);
    wiki_pw_tokens_drop_user($uid);
    if (function_exists('wiki_remember_forget_user')) wiki_remember_forget_user($uid);
    if ($keep_session && isset($_SESSION['user'])) $_SESSION['user']['signed_in_at'] = $now;
}

/** Stamp the record so every session signed in before $at ends at its next request. */
function wiki_credentials_touch(int $uid, int $at): void {
    wiki_users_mutate(function (array &$doc) use ($uid, $at) {
        $i = wiki_find_user_index($doc['users'], $uid);
        if ($i !== null) $doc['users'][$i]['credentialsChangedAt'] = $at;
    });
}

/**
 * Check a password for an account (null = no such account). Always does the work of one
 * verification, so a missing account costs the same as a wrong password. Upgrades the
 * hash when the algorithm or its cost has moved on.
 */
function wiki_password_verify(?int $uid, string $pw): bool {
    static $dummy = null;
    $cred = $uid ? wiki_credentials_get($uid) : null;
    if (!$cred) {
        $dummy ??= wiki_pw_hash('not-a-password-' . bin2hex(random_bytes(8)));
        password_verify($pw, $dummy);
        return false;
    }
    if (!password_verify($pw, $cred['hash'])) return false;
    if (password_needs_rehash($cred['hash'], wiki_pw_algo())) {
        wiki_json_mutate(wiki_credentials_file(), function (array &$doc) use ($uid, $pw) {
            if (isset($doc[(string)$uid])) $doc[(string)$uid]['hash'] = wiki_pw_hash($pw);
        });
    }
    return true;
}

/** A temporary password an admin hands over: ~80 bits, no look-alike characters. */
function wiki_pw_generate(): string {
    $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alpha[random_int(0, strlen($alpha) - 1)];
        $groups[] = $s;
    }
    return implode('-', $groups);
}

// --- Throttling --------------------------------------------------------------------
// Failures are counted per account and per IP over a sliding window. Unknown addresses are
// counted like real ones, so the throttle does not reveal which exist either.

function wiki_pw_throttle_keys(string $login): array {
    return [
        'a:' . hash('sha256', strtolower(trim($login))) => WIKI_PW_THROTTLE_USER,
        'i:' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '-'))  => WIKI_PW_THROTTLE_IP,
    ];
}

/** Seconds until another attempt is allowed for this login from this address; 0 = now. */
function wiki_pw_throttle_wait(string $login): int {
    $f = WIKI_SYSTEM_DATA . 'login_throttle.json';
    if (!is_file($f)) return 0;
    $doc = json_decode((string)@file_get_contents($f), true) ?: [];
    $now = time();
    $wait = 0;
    foreach (wiki_pw_throttle_keys($login) as $key => $limit) {
        $hits = array_values(array_filter($doc[$key] ?? [], fn($t) => $t > $now - WIKI_PW_THROTTLE_WINDOW));
        if (count($hits) >= $limit) {
            // Free again when the oldest failure that still counts leaves the window.
            sort($hits);
            $wait = max($wait, $hits[count($hits) - $limit] + WIKI_PW_THROTTLE_WINDOW - $now);
        }
    }
    return max(0, $wait);
}

function wiki_pw_throttle_fail(string $login): void {
    $now = time();
    wiki_json_mutate(WIKI_SYSTEM_DATA . 'login_throttle.json', function (array &$doc) use ($login, $now) {
        foreach (wiki_pw_throttle_keys($login) as $key => $limit) {
            $hits = array_filter($doc[$key] ?? [], fn($t) => $t > $now - WIKI_PW_THROTTLE_WINDOW);
            $hits[] = $now;
            $doc[$key] = array_values(array_slice($hits, -max($limit, 1) * 2));
        }
        // Keep the file small: forget keys with nothing left in the window.
        foreach ($doc as $k => $hits) {
            if (!array_filter((array)$hits, fn($t) => $t > $now - WIKI_PW_THROTTLE_WINDOW)) unset($doc[$k]);
        }
    });
}

function wiki_pw_throttle_clear(string $login): void {
    $f = WIKI_SYSTEM_DATA . 'login_throttle.json';
    if (!is_file($f)) return;
    $key = array_key_first(wiki_pw_throttle_keys($login));   // the account; the IP keeps counting
    wiki_json_mutate($f, function (array &$doc) use ($key) { unset($doc[$key]); });
}

// --- Links -----------------------------------------------------------------------

/** The wiki's own origin from configuration only — never the request's Host header. */
function wiki_pw_base_url(): string {
    if (defined('APP_BASE_URL') && APP_BASE_URL) return rtrim((string)APP_BASE_URL, '/');
    if (defined('OIDC_REDIRECT_URI') && OIDC_REDIRECT_URI) return rtrim(dirname((string)OIDC_REDIRECT_URI), '/');
    return '';
}

/** Can this wiki email a set-password link at all? */
function wiki_pw_links_possible(): bool {
    return wiki_pw_base_url() !== '' && function_exists('is_mail_configured') && is_mail_configured();
}

function wiki_pw_token_issue(int $uid, string $purpose): string {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $ttl = $purpose === 'invite' ? WIKI_PW_INVITE_TTL : WIKI_PW_RESET_TTL;
    $now = time();
    wiki_json_mutate(WIKI_SYSTEM_DATA . 'password_tokens.json', function (array &$doc) use ($token, $uid, $purpose, $ttl, $now) {
        foreach ($doc as $h => $t) if (($t['expires'] ?? 0) < $now) unset($doc[$h]);
        $doc[hash('sha256', $token)] = ['uid' => $uid, 'purpose' => $purpose, 'expires' => $now + $ttl];
    });
    return $token;
}

/** The token's record if it is live, without spending it (the form is shown first). */
function wiki_pw_token_peek(string $token): ?array {
    $f = WIKI_SYSTEM_DATA . 'password_tokens.json';
    if ($token === '' || !is_file($f)) return null;
    $doc = json_decode((string)@file_get_contents($f), true) ?: [];
    $t = $doc[hash('sha256', $token)] ?? null;
    return ($t && ($t['expires'] ?? 0) >= time()) ? $t : null;
}

/** Spend a token: returns its record once, then never again. */
function wiki_pw_token_take(string $token): ?array {
    $f = WIKI_SYSTEM_DATA . 'password_tokens.json';
    if ($token === '' || !is_file($f)) return null;
    return wiki_json_mutate($f, function (array &$doc) use ($token) {
        $h = hash('sha256', $token);
        $t = $doc[$h] ?? null;
        unset($doc[$h]);
        return ($t && ($t['expires'] ?? 0) >= time()) ? $t : null;
    });
}

function wiki_pw_tokens_drop_user(int $uid): void {
    $f = WIKI_SYSTEM_DATA . 'password_tokens.json';
    if (!is_file($f)) return;
    wiki_json_mutate($f, function (array &$doc) use ($uid) {
        foreach ($doc as $h => $t) if ((int)($t['uid'] ?? 0) === $uid) unset($doc[$h]);
    });
}

/** Mail a set-password (invite) or reset link. False when it could not be sent. */
function wiki_pw_send_link(array $user, string $purpose): bool {
    $to = wiki_user_otp_email($user);
    if ($to === '' || !wiki_pw_links_possible()) return false;
    $token = wiki_pw_token_issue((int)$user['uid'], $purpose);
    $url   = wiki_pw_base_url() . '/login.php?step=set&t=' . rawurlencode($token);
    $title = htmlspecialchars(APP_TITLE);
    $link  = '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a></p>';
    if ($purpose === 'invite') {
        $subject = 'Set your password for ' . APP_TITLE;
        $html = "<p>An account has been made for you on <strong>{$title}</strong>. Choose your password here:</p>{$link}"
              . "<p>The link works once and expires in 24 hours.</p>";
    } else {
        $subject = 'Reset your password for ' . APP_TITLE;
        $html = "<p>Someone asked to reset the password for this address on <strong>{$title}</strong>. To choose a new one:</p>{$link}"
              . "<p>The link works once and expires in one hour. If you did not ask for this, ignore this email — your password stays as it is.</p>";
    }
    return send_email($to, (string)($user['name'] ?? ''), $subject, $html);
}
