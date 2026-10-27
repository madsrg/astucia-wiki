<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// ACCOUNTS — a person's record in users.json and the ways it signs in.
//
// An account may hold both sign-in methods at once:
//   `sub`       single sign-on — the provider's stable subject id
//   `otpEmail`  email code — the address a login code is sent to and matched on
//   `auth`      derived from the two ('oidc' | 'otp' | 'both'), written for readers that
//               predate the split; never the source of truth
//
// Until both could coexist, an OTP record's login address was its `email`, and an SSO
// login wrote the provider's claim over `email` on every sign-in. Kept separate, the claim
// can go on doing that without ever moving the OTP login address.
//
// There is no migration. wiki_user_otp_email() reads a legacy record the old way (`auth:
// otp` means `email` is the login), and every write that gives an account a second method
// stores `otpEmail` first — so nothing has to rewrite users.json up front, and a request
// served before anything has written still signs the same people in.
//
// The one rule that matters more than the format: an account is never linked because two
// addresses match. A request's address may be typed by the requester (pending_access.php
// asks for one when the provider sends none), and many providers let a user set their own
// email claim. Every link is made by someone already signed in with one method completing
// the other in the same session — see wiki_oidc_complete() and wiki_account_add_otp().
// =================================================================

require_once __DIR__ . '/service_auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/logger.php';

const WIKI_OTP_TTL          = 600;   // seconds a code is valid
const WIKI_OTP_MAX_ATTEMPTS = 5;
const WIKI_OTP_RESEND_GAP   = 30;    // seconds between codes to a new address from one session
const WIKI_LINK_SSO_TTL     = 600;   // seconds an "add single sign-on" round trip may take

// --- Reading a record ----------------------------------------------------------

function wiki_user_is_person(array $u): bool {
    return empty($u['is_ai']) && empty($u['is_system']);
}

/** The SSO subject this account signs in with, or '' when it has none. */
function wiki_user_sso_sub(array $u): string {
    // A legacy record marked OTP that still carries a sub was refused at SSO login before
    // accounts could hold both; reading it as "no SSO" keeps it refused.
    if (($u['auth'] ?? '') === 'otp' && !array_key_exists('otpEmail', $u)) return '';
    return trim((string)($u['sub'] ?? ''));
}

/** The address an email code goes to, or '' when this account has no OTP sign-in. */
function wiki_user_otp_email(array $u): string {
    if (array_key_exists('otpEmail', $u)) return trim((string)$u['otpEmail']);
    $auth = $u['auth'] ?? (empty($u['sub']) ? 'otp' : 'oidc');
    return $auth === 'otp' ? trim((string)($u['email'] ?? '')) : '';
}

function wiki_user_auth(array $u): string {
    $sso = wiki_user_sso_sub($u) !== '';
    $otp = wiki_user_otp_email($u) !== '';
    return $sso && $otp ? 'both' : ($otp ? 'otp' : 'oidc');
}

function wiki_email_key(string $email): string {
    return strtolower(trim($email));
}

/**
 * The record with its methods set. Writes all three fields together so `auth` can never
 * disagree with `sub` / `otpEmail`.
 */
function wiki_user_with_methods(array $u, string $sub, string $otp_email): array {
    $sub = trim($sub);
    $otp_email = trim($otp_email);
    if ($sub !== '') $u['sub'] = $sub; else unset($u['sub']);
    if ($otp_email !== '') $u['otpEmail'] = $otp_email; else unset($u['otpEmail']);
    $u['auth'] = wiki_user_auth($u);
    return $u;
}

function wiki_find_user_index(array $users, int $uid): ?int {
    if ($uid <= 0) return null;
    foreach ($users as $i => $u) {
        if (wiki_user_is_person($u) && (int)($u['uid'] ?? 0) === $uid) return $i;
    }
    return null;
}

function wiki_find_user_index_by_sub(array $users, string $sub): ?int {
    if ($sub === '') return null;
    foreach ($users as $i => $u) {
        if (wiki_user_is_person($u) && wiki_user_sso_sub($u) === $sub) return $i;
    }
    return null;
}

function wiki_find_user_by_otp_email(array $users, string $email): ?array {
    $key = wiki_email_key($email);
    if ($key === '') return null;
    foreach ($users as $u) {
        if (wiki_user_is_person($u) && wiki_email_key(wiki_user_otp_email($u)) === $key) return $u;
    }
    return null;
}

// --- The file --------------------------------------------------------------------

function wiki_users_doc(): array {
    $file = WIKI_SYSTEM_DATA . 'users.json';
    $doc  = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($doc) ? $doc + ['users' => []] : ['users' => []];
}

function wiki_users_list(): array {
    return wiki_users_doc()['users'] ?? [];
}

/**
 * Locked read-modify-write of users.json. $fn receives the document by reference and
 * returns a result; the file is written only if the document changed. Every top-level
 * key (notably `schema`) is kept.
 */
function wiki_users_mutate(callable $fn): mixed {
    $lock = @fopen(WIKI_SYSTEM_DATA . '.users.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $doc    = wiki_users_doc();
        $before = $doc;
        $result = $fn($doc);
        if ($doc !== $before) {
            $file = WIKI_SYSTEM_DATA . 'users.json';
            $tmp  = $file . '.tmp';
            $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
                throw new RuntimeException('Failed to write users file.');
            }
        }
        return $result;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function wiki_requests_mutate(callable $fn): mixed {
    $file = WIKI_SYSTEM_DATA . 'user_requests.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    try {
        $doc = json_decode(stream_get_contents($fh) ?: '', true);
        $requests = is_array($doc) ? ($doc['requests'] ?? []) : [];
        $before = $requests;
        $result = $fn($requests);
        if ($requests !== $before) {
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(['requests' => array_values($requests)], JSON_PRETTY_PRINT));
            fflush($fh);
        }
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// --- The session -----------------------------------------------------------------

/**
 * $_SESSION['user'] for a person, whichever way they signed in. `via` is how this session
 * was established ('sso' | 'otp'); `auth` is the same thing in the older vocabulary.
 */
function wiki_session_user(array $u, string $via, array $extra = []): array {
    $s = [
        'uid'        => (int)($u['uid'] ?? 0),
        'name'       => $u['name']       ?? '',
        'email'      => trim((string)($u['email'] ?? '')) ?: wiki_user_otp_email($u),
        'role'       => $u['role']       ?? 'editor',
        'spaces'     => $u['spaces']     ?? null,
        'fontFamily' => $u['fontFamily'] ?? 'sans',
        'fontSize'   => $u['fontSize']   ?? 'normal',
        'auth'       => $via === 'sso' ? 'oidc' : 'otp',
        'via'        => $via,
    ];
    $sub = wiki_user_sso_sub($u);
    if ($sub !== '') $s['sub'] = $sub;
    return array_merge($s, $extra);
}

/** Rebuild the signed-in user's session after their record changed, keeping how they came in. */
function wiki_session_refresh(): void {
    $uid = (int)($_SESSION['user']['uid'] ?? 0);
    $users = wiki_users_list();
    $i = wiki_find_user_index($users, $uid);
    if ($i === null) return;
    $via = $_SESSION['user']['via'] ?? (($_SESSION['user']['auth'] ?? '') === 'oidc' ? 'sso' : 'otp');
    $extra = isset($_SESSION['user']['locale']) ? ['locale' => $_SESSION['user']['locale']] : [];
    $_SESSION['user'] = wiki_session_user($users[$i], $via, $extra);
}

// Stamp the moment a user signed in. Read by the My Mentions badge, which needs a line
// between seen and new the first time a user opens the panel.
function record_login(int $uid): void {
    if ($uid <= 0) return;
    wiki_users_mutate(function (array &$doc) use ($uid) {
        $i = wiki_find_user_index($doc['users'], $uid);
        if ($i !== null) $doc['users'][$i]['lastLogin'] = time();
    });
}

// --- Email codes -----------------------------------------------------------------
// One mechanism for every code: signing in, finishing a link an admin started, and
// adding an address in My Preferences. `purpose` keeps a code issued for one from being
// spent on another, and `ctx` carries what the server decided when it issued the code —
// nothing the browser sends at verify time is trusted for it.

function wiki_otp_issue(string $purpose, string $to, string $name, array $ctx = []): bool {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_pending'] = [
        'purpose'  => $purpose,
        'email'    => $to,
        'code'     => password_hash($code, PASSWORD_DEFAULT),
        'expires'  => time() + WIKI_OTP_TTL,
        'issued'   => time(),
        'attempts' => 0,
        'ctx'      => $ctx,
    ];
    $title = htmlspecialchars(APP_TITLE);
    $big   = "<p style='font-size:2rem;font-weight:bold;letter-spacing:0.3em;font-family:monospace'>{$code}</p>";
    $tail  = "<p>This code expires in 10 minutes. If you didn't request this, you can ignore this email.</p>";
    switch ($purpose) {
        case 'link_request':
            $subject = 'Link single sign-on to your account on ' . APP_TITLE;
            $html = "<p>Someone signed in to <strong>{$title}</strong> with single sign-on and asked to use it with your account. "
                  . "To allow that, enter this code:</p>{$big}"
                  . "<p>If that was not you, do not share the code — nothing is linked without it.</p>" . $tail;
            break;
        case 'add_otp':
            $subject = 'Confirm your sign-in address for ' . APP_TITLE;
            $html = "<p>Enter this code in <strong>{$title}</strong> to sign in with this address from now on:</p>{$big}" . $tail;
            break;
        default:
            $subject = 'Your login code for ' . APP_TITLE;
            $html = "<p>Your login code for <strong>{$title}</strong> is:</p>{$big}" . $tail;
    }
    return send_email($to, $name, $subject, $html);
}

/**
 * Check a submitted code against the pending one. Returns the pending record on success
 * (and clears it), or an i18n error key. A wrong code counts an attempt; the fifth ends it.
 */
function wiki_otp_check(string $submitted, array $purposes): array|string {
    $p = $_SESSION['otp_pending'] ?? null;
    if (!$p || !in_array($p['purpose'] ?? 'login', $purposes, true)) return 'login.err.session-expired';
    if (time() > ($p['expires'] ?? 0)) { unset($_SESSION['otp_pending']); return 'login.err.code-expired'; }
    if (($p['attempts'] ?? 0) >= WIKI_OTP_MAX_ATTEMPTS) { unset($_SESSION['otp_pending']); return 'login.err.too-many'; }
    $submitted = trim($submitted);
    if ($submitted === '' || !password_verify($submitted, $p['code'] ?? '')) {
        $_SESSION['otp_pending']['attempts'] = ($p['attempts'] ?? 0) + 1;
        return 'login.err.invalid-code';
    }
    unset($_SESSION['otp_pending']);
    $p['purpose'] = $p['purpose'] ?? 'login';
    return $p;
}

/** English for an error key — the API's `message`, for a client without the locale. */
function wiki_account_error_text(string $key): string {
    return [
        'account.err.no-account'   => 'Account not found.',
        'account.err.has-sso'      => 'This account already has single sign-on.',
        'account.err.sso-taken'    => 'This single sign-on identity already belongs to another account. Ask an administrator to merge them.',
        'account.err.otp-taken'    => 'That address is already another account\'s sign-in address.',
        'account.err.last-method'  => 'This is the only way left to sign in to the account, so it cannot be removed.',
        'account.err.link-expired' => 'The link request expired. Please start again.',
        'account.err.not-offered'  => 'This wiki does not offer both ways of signing in.',
        'account.err.wait'         => 'Please wait a moment before asking for another code.',
        'account.err.no-mail'      => 'This wiki cannot send email, so codes cannot be sent.',
        'login.err.invalid-email'  => 'Please enter a valid email address.',
        'login.err.session-expired'=> 'Session expired. Please start again.',
        'login.err.code-expired'   => 'The code has expired. Please request a new one.',
        'login.err.too-many'       => 'Too many failed attempts. Please request a new code.',
        'login.err.invalid-code'   => 'Invalid code. Please try again.',
    ][$key] ?? $key;
}

// --- Linking and unlinking -------------------------------------------------------
// Each returns null on success or an i18n key naming what stopped it.

function wiki_account_log(string $event, array $u, string $detail): void {
    write_access_log($event, (string)($u['uid'] ?? '-'), (string)($u['name'] ?? '-'), $detail);
    require_once __DIR__ . '/audit.php';
    wiki_audit_log(strtolower($event) === 'account_linked' ? 'link' : 'unlink', 'success', [
        'user'            => (string)($_SESSION['user']['name'] ?? ($u['name'] ?? '')),
        'object'          => (string)($u['name'] ?? ''),
        'object_id'       => (string)($u['uid'] ?? ''),
        'object_category' => 'account',
        'detail'          => $detail,
    ]);
}

/** Give an account single sign-on. The caller has proved both identities. */
function wiki_account_link_sso(int $uid, string $sub, string $claim_email, string $name): ?string {
    $sub = trim($sub);
    if ($sub === '') return 'account.err.no-account';
    $linked = wiki_users_mutate(function (array &$doc) use ($uid, $sub, $claim_email, $name) {
        $i = wiki_find_user_index($doc['users'], $uid);
        if ($i === null) return 'account.err.no-account';
        $u = $doc['users'][$i];
        $have = wiki_user_sso_sub($u);
        if ($have === $sub) return null;                      // already this one
        if ($have !== '') return 'account.err.has-sso';
        $other = wiki_find_user_index_by_sub($doc['users'], $sub);
        if ($other !== null) return 'account.err.sso-taken';

        $otp = wiki_user_otp_email($u);                       // before `email` can move
        // Mail keeps going where it went before the link. The SSO login writes the
        // provider's address over `email` from now on, which would otherwise quietly move
        // this person's notifications to it.
        $cur = trim((string)($u['email'] ?? ''));
        if (!array_key_exists('notifyEmail', $u) && $cur !== '' && $claim_email !== '' && wiki_email_key($cur) !== wiki_email_key($claim_email)) {
            $u['notifyEmail'] = $cur;
        }
        if ($claim_email !== '') $u['email'] = $claim_email;
        if ($name !== '' && trim((string)($u['name'] ?? '')) === '') $u['name'] = $name;
        $doc['users'][$i] = wiki_user_with_methods($u, $sub, $otp);
        return $doc['users'][$i];
    });
    if (is_string($linked)) return $linked;
    // The access request this identity filed is answered by the link.
    wiki_requests_mutate(function (array &$requests) use ($sub) {
        $requests = array_values(array_filter($requests, fn($r) => ($r['sub'] ?? '') !== $sub));
    });
    if (is_array($linked)) wiki_account_log('ACCOUNT_LINKED', $linked, 'sso');
    return null;
}

/** Give an account an email-code address (or change it). The caller has proved the mailbox. */
function wiki_account_add_otp(int $uid, string $email): ?string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'login.err.invalid-email';
    $res = wiki_users_mutate(function (array &$doc) use ($uid, $email) {
        $i = wiki_find_user_index($doc['users'], $uid);
        if ($i === null) return 'account.err.no-account';
        $owner = wiki_find_user_by_otp_email($doc['users'], $email);
        if ($owner && (int)($owner['uid'] ?? 0) !== $uid) return 'account.err.otp-taken';
        $u = $doc['users'][$i];
        $old = wiki_user_otp_email($u);
        if (trim((string)($u['email'] ?? '')) === '') $u['email'] = $email;
        $doc['users'][$i] = wiki_user_with_methods($u, wiki_user_sso_sub($u), $email);
        return ['user' => $doc['users'][$i], 'old' => $old];
    });
    if (is_string($res)) return $res;
    if (wiki_email_key($res['old']) !== wiki_email_key($email)) {
        // A token issued to the old address must not outlive it.
        if ($res['old'] !== '') wiki_remember_forget_user($uid);
        wiki_account_log('ACCOUNT_LINKED', $res['user'], 'otp');
    }
    return null;
}

/** Remove one sign-in method. The last one cannot be removed. */
function wiki_account_unlink(int $uid, string $method): ?string {
    if (!in_array($method, ['sso', 'otp'], true)) return 'account.err.no-account';
    $res = wiki_users_mutate(function (array &$doc) use ($uid, $method) {
        $i = wiki_find_user_index($doc['users'], $uid);
        if ($i === null) return 'account.err.no-account';
        $u   = $doc['users'][$i];
        $sub = wiki_user_sso_sub($u);
        $otp = wiki_user_otp_email($u);
        if ($method === 'sso') {
            if ($sub === '') return null;
            if ($otp === '') return 'account.err.last-method';
            // Without SSO nothing refreshes `email` from a provider; it shows the login
            // address again, as on any OTP account.
            $u['email'] = $otp;
            $doc['users'][$i] = wiki_user_with_methods($u, '', $otp);
        } else {
            if ($otp === '') return null;
            if ($sub === '') return 'account.err.last-method';
            $doc['users'][$i] = wiki_user_with_methods($u, $sub, '');
        }
        return $doc['users'][$i];
    });
    if (is_string($res)) return $res;
    if (is_array($res)) {
        if ($method === 'otp') wiki_remember_forget_user($uid);
        wiki_account_log('ACCOUNT_UNLINKED', $res, $method);
    }
    return null;
}

/** Accounts a request's address matches — a hint for the admin, never proof. */
function wiki_request_matches(array $users, string $email): array {
    $key = wiki_email_key($email);
    if ($key === '') return [];
    $out = [];
    foreach ($users as $u) {
        if (!wiki_user_is_person($u)) continue;
        $addrs = array_map('wiki_email_key', [(string)($u['email'] ?? ''), wiki_user_otp_email($u), (string)($u['notifyEmail'] ?? '')]);
        if (!in_array($key, $addrs, true)) continue;
        $out[] = [
            'uid'     => (int)($u['uid'] ?? 0),
            'name'    => (string)($u['name'] ?? ''),
            'auth'    => wiki_user_auth($u),
            // Linking finishes with a code to this address, so it is the one that has to exist.
            'linkable'=> wiki_user_otp_email($u) !== '' && wiki_user_sso_sub($u) === '',
        ];
    }
    return $out;
}

// --- Finishing an SSO sign-in ----------------------------------------------------

/**
 * Everything after the provider has vouched for $claims (sub, email, name, locale).
 * Returns where to send the browser. Kept out of auth.php's OIDC round trip so the
 * decisions — sign in, link, ask for a code, file a request — can be tested without a
 * provider.
 */
function wiki_oidc_complete(array $claims, ?string $id_token = null): string {
    $sub    = trim((string)($claims['sub'] ?? ''));
    $email  = trim((string)($claims['email'] ?? ''));
    $name   = trim((string)($claims['name'] ?? '')) ?: 'Unknown User';
    $locale = (string)($claims['locale'] ?? 'en_US');
    if ($sub === '') throw new Exception('OAuth token missing required `sub` claim.');

    // 1. A signed-in user adding SSO from My Preferences.
    $link = $_SESSION['link_sso'] ?? null;
    unset($_SESSION['link_sso']);
    if ($link && !empty($link['started'])) {
        $me = (int)($_SESSION['user']['uid'] ?? 0);
        if ($me <= 0 || $me !== (int)($link['uid'] ?? -1) || time() > (int)($link['expires'] ?? 0)) {
            return 'index.php?account_error=account.err.link-expired';
        }
        $err = wiki_account_link_sso($me, $sub, $email, $name);
        if ($err !== null) return 'index.php?account_error=' . $err;
        wiki_session_refresh();
        return 'index.php?account_notice=account.notice.sso-linked';
    }

    // 2. An account that already has this identity.
    $redirect = $_SESSION['login_redirect'] ?? 'index.php';
    $signed_in = wiki_users_mutate(function (array &$doc) use ($sub, $email, $name) {
        $i = wiki_find_user_index_by_sub($doc['users'], $sub);
        if ($i === null) return null;
        $u = $doc['users'][$i];
        $u['name'] = $name;
        // The provider is authoritative for the identity address, and writing the claim
        // back on every login repairs records a saved preference once overwrote. The OTP
        // login address is a separate field, so this never moves it.
        if ($email !== '') $u['email'] = $email;
        if (!isset($u['uid'])) {
            $max = 0;
            foreach ($doc['users'] as $eu) $max = max($max, (int)($eu['uid'] ?? 0));
            $u['uid'] = $max + 1;
        }
        $u = wiki_user_with_methods($u, $sub, wiki_user_otp_email($u));
        $u['lastLogin'] = time();
        $doc['users'][$i] = $u;
        return $u;
    });
    if ($signed_in) {
        session_regenerate_id(true);
        $_SESSION['user'] = wiki_session_user($signed_in, 'sso', ['locale' => $locale, 'email' => $email ?: ($signed_in['email'] ?? '')]);
        $_SESSION['last_activity'] = time();
        if ($id_token) $_SESSION['id_token'] = $id_token;
        unset($_SESSION['login_redirect']);
        write_access_log('LOGIN_OK', $sub, $name, $signed_in['role'] ?? 'editor');
        return $redirect;
    }

    unset($_SESSION['user'], $_SESSION['id_token']);
    session_regenerate_id(true);

    // 3. An admin chose to link this identity to an existing account: the person proves
    //    they own it with a code sent to *that account's* address — never the request's,
    //    which may have been typed by whoever filed it.
    $users = wiki_users_list();
    $request = wiki_requests_mutate(function (array &$requests) use ($sub, $name, $email, $users) {
        foreach ($requests as $k => $r) {
            if (($r['sub'] ?? '') !== $sub) continue;
            $requests[$k]['name'] = $name;
            if (($r['status'] ?? '') === 'link') {
                $ti = wiki_find_user_index($users, (int)($r['link_uid'] ?? 0));
                $t  = $ti === null ? null : $users[$ti];
                if (!$t || wiki_user_otp_email($t) === '' || wiki_user_sso_sub($t) !== '') {
                    // The account changed since the admin decided; back to the queue.
                    $requests[$k]['status'] = 'pending';
                    unset($requests[$k]['link_uid']);
                }
            }
            return $requests[$k];
        }
        $requests[] = $new = [
            'sub'          => $sub,
            'name'         => $name,
            'email'        => $email,
            // Where the address came from, so a match on it can be weighed: an address the
            // provider asserted is worth more than one the requester typed.
            'email_source' => $email !== '' ? 'claim' : 'typed',
            'requested_at' => date('c'),
            'status'       => 'pending',
        ];
        return $new + ['_new' => true];
    });

    if (($request['status'] ?? '') === 'link') {
        $ti = wiki_find_user_index($users, (int)$request['link_uid']);
        $t  = $users[$ti];
        wiki_otp_issue('link_request', wiki_user_otp_email($t), (string)($t['name'] ?? ''), [
            'uid' => (int)$t['uid'], 'sub' => $sub, 'email' => $email, 'name' => $name,
            'locale' => $locale, 'id_token' => $id_token,
        ]);
        write_access_log('LINK_CODE_SENT', $sub, $name, 'uid ' . (int)$t['uid']);
        return 'login.php?step=verify';
    }

    if (!empty($request['_new'])) {
        if (is_mail_configured()) {
            $n = htmlspecialchars($name);
            send_email(
                mail_from_email(), APP_TITLE . ' Admin',
                APP_TITLE . ': New Access Request from ' . $n,
                "<p><strong>{$n}</strong> has requested access to <strong>" . htmlspecialchars(APP_TITLE) . "</strong>.</p>"
                . "<p>Log in to the admin panel and open the <strong>Requests</strong> tab to approve or deny.</p>"
            );
        }
        write_access_log('ACCESS_REQUESTED', $sub, $name);
    }
    $_SESSION['pending_sub']    = $sub;
    $_SESSION['pending_status'] = ($request['status'] ?? 'pending') === 'denied' ? 'denied' : 'pending';
    return 'pending_access.php';
}

/** Sign in after a code finished a link the admin started. */
function wiki_link_request_complete(array $pending, bool $remember): string {
    $ctx = $pending['ctx'] ?? [];
    $uid = (int)($ctx['uid'] ?? 0);
    $err = wiki_account_link_sso($uid, (string)($ctx['sub'] ?? ''), (string)($ctx['email'] ?? ''), (string)($ctx['name'] ?? ''));
    if ($err !== null) {
        $_SESSION['login_error'] = $err;
        return 'login.php';
    }
    $users = wiki_users_list();
    $i = wiki_find_user_index($users, $uid);
    session_regenerate_id(true);
    $_SESSION['user'] = wiki_session_user($users[$i], 'sso', ['locale' => (string)($ctx['locale'] ?? 'en_US')]);
    $_SESSION['last_activity'] = time();
    if (!empty($ctx['id_token'])) $_SESSION['id_token'] = $ctx['id_token'];
    if ($remember) wiki_remember_issue($uid);
    record_login($uid);
    write_access_log('LOGIN_OK', (string)($ctx['sub'] ?? ''), (string)($users[$i]['name'] ?? ''), ($users[$i]['role'] ?? 'editor') . ' (linked)');
    $redirect = $_SESSION['login_redirect'] ?? 'index.php';
    unset($_SESSION['login_redirect']);
    return $redirect;
}
