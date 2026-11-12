<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Authentication handler — every sign-in method, each gated by the site's mode
 * (auth_modes.php): single sign-on, email codes, passwords.
 */
require_once __DIR__ . '/config.php';
require_once 'logger.php';
require_once 'mailer.php';
require_once __DIR__ . '/service_auth.php';   // wiki_migrate_user_emails(), wiki_user_notify_email()
require_once __DIR__ . '/remember_me.php';    // OTP "keep me signed in"
require_once __DIR__ . '/accounts.php';       // the account model, codes, linking, wiki_oidc_complete()
require_once __DIR__ . '/passwords.php';      // the password mode

session_start();

if (!AUTHENTICATION_ENABLED) {
    header("Location: index.php");
    exit;
}

wiki_migrate_user_emails();   // before any read of users.json below
wiki_credentials_retire();    // a site that left the password mode keeps no passwords

$_action = $_GET['action'] ?? '';

// ── Logout ────────────────────────────────────────────────────────────────────

if ($_action === 'logout') {
    $idToken    = $_SESSION['id_token'] ?? null;
    $logout_id  = $_SESSION['user']['sub'] ?? $_SESSION['user']['email'] ?? '-';
    $logout_name = $_SESSION['user']['name'] ?? '-';
    write_access_log('LOGOUT', $logout_id, $logout_name);
    wiki_remember_forget();   // or the next page load signs them straight back in
    session_destroy();
    $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $loginUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login.php?logged_out=1';
    if ($idToken && wiki_auth_offers('sso')) {
        require_once __DIR__ . '/vendor/autoload.php';
        $oidc_lo = new \Jumbojett\OpenIDConnectClient(OIDC_PROVIDER_URL, OIDC_CLIENT_ID, OIDC_CLIENT_SECRET);
        $oidc_lo->signOut($idToken, $loginUrl);
    } else {
        header('Location: ' . $loginUrl);
    }
    exit;
}

// ── OTP: Send code ────────────────────────────────────────────────────────────

// login_error / login_notice hold an i18n key, not prose — login.php maps it to
// English (the no-JS fallback) and the i18n module translates it in the browser.
if ($_action === 'otp_send' && wiki_auth_offers('email')) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['login_error'] = 'login.err.invalid-email';
        header('Location: login.php');
        exit;
    }
    $user = wiki_find_user_by_otp_email(wiki_users_list(), $email);
    // Always say "code sent" — don't reveal whether the email exists. An account that signs
    // in with SSO never gets a code: one method per account, the stronger one. Its owner is
    // told by mail instead, so the page still says nothing about which accounts exist.
    $method = $user ? wiki_user_signin_method($user) : '';
    if ($method === 'email') {
        wiki_otp_issue('login', $email, $user['name'] ?? '');
        write_access_log('OTP_SENT', $email, $user['name'] ?? '');
    } elseif ($method === 'sso') {
        $t = htmlspecialchars(APP_TITLE);
        send_email($email, (string)($user['name'] ?? ''), 'Sign in to ' . APP_TITLE . ' with single sign-on',
            "<p>Someone asked for an email sign-in code for this address on <strong>{$t}</strong>. "
            . "Your account signs in with <strong>single sign-on</strong>, so no code was sent — use the "
            . "single sign-on button on the sign-in page instead.</p><p>If that was not you, you can ignore this email.</p>");
        write_access_log('OTP_REFUSED_SSO', $email, $user['name'] ?? '');
    }
    $_SESSION['login_notice'] = 'login.notice.code-sent';
    header('Location: login.php?step=verify');
    exit;
}

// ── OTP: Verify code ──────────────────────────────────────────────────────────

if ($_action === 'otp_verify' && wiki_auth_offers('email')) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $pending = wiki_otp_check((string)($_POST['code'] ?? ''), ['login', 'link_request']);
    if (is_string($pending)) {
        $_SESSION['login_error'] = $pending;
        // A wrong code keeps the code step open; everything else starts over.
        header('Location: ' . ($pending === 'login.err.invalid-code' ? 'login.php?step=verify' : 'login.php'));
        exit;
    }
    // A code that finishes a link an admin started: the SSO identity joins the account the
    // code was sent to, and the person is signed in as that account.
    if ($pending['purpose'] === 'link_request') {
        header('Location: ' . wiki_link_request_complete($pending, !empty($_POST['remember'])));
        exit;
    }
    // Valid code — look up user and establish session
    $email = $pending['email'];
    $user  = wiki_find_user_by_otp_email(wiki_users_list(), $email);
    if (!$user) {
        $_SESSION['login_error'] = 'login.err.no-account';
        header('Location: login.php');
        exit;
    }
    // Re-checked here as well as at send time: the account may have gained SSO, or the
    // site's mode changed, while the code was on its way.
    if (wiki_user_signin_method($user) !== 'email') {
        $_SESSION['login_error'] = 'login.err.use-sso';
        header('Location: login.php');
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['user']          = wiki_otp_session_user($user, $email);
    $_SESSION['last_activity'] = time();
    if (!empty($_POST['remember'])) wiki_remember_issue((int)($user['uid'] ?? 0));
    record_login((int)($user['uid'] ?? 0));
    write_access_log('LOGIN_OK', $email, $user['name'] ?? '', $user['role'] ?? 'editor');
    $redirect = $_SESSION['login_redirect'] ?? 'index.php';
    unset($_SESSION['login_redirect']);
    header('Location: ' . $redirect);
    exit;
}

// ── Passwords ─────────────────────────────────────────────────────────────────
// Only in the password mode. See passwords.php for the rules: one message for every
// failure, equal timing for unknown accounts, throttling, links from configuration only.

/** Sign a password account in and send the browser on. */
function wiki_pw_sign_in(array $user, bool $remember): void {
    session_regenerate_id(true);
    unset($_SESSION['pw_change']);
    $_SESSION['user']          = wiki_session_user($user, 'password');
    $_SESSION['last_activity'] = time();
    if ($remember) wiki_remember_issue((int)($user['uid'] ?? 0));
    record_login((int)($user['uid'] ?? 0));
    write_access_log('LOGIN_OK', wiki_user_otp_email($user), $user['name'] ?? '', ($user['role'] ?? 'editor') . ' (password)');
    $redirect = $_SESSION['login_redirect'] ?? 'index.php';
    unset($_SESSION['login_redirect']);
    header('Location: ' . $redirect);
    exit;
}

/** Validate a new password pair; returns an i18n key, or null when it may be used. */
function wiki_pw_new_error(string $pw, string $pw2): ?string {
    if ($pw !== $pw2) return 'login.err.pw-mismatch';
    return wiki_pw_policy_error($pw);
}

if (in_array($_action, ['password_login', 'password_forgot', 'password_set', 'password_change'], true)) {
    if (!wiki_auth_offers('password') || $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $back = function (string $key, string $to = 'login.php', bool $notice = false) {
        $_SESSION[$notice ? 'login_notice' : 'login_error'] = $key;
        header('Location: ' . $to);
        exit;
    };

    if ($_action === 'password_login') {
        $login = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw    = (string)($_POST['password'] ?? '');
        // Throttled before anything is checked, and for unknown addresses too.
        $wait = wiki_pw_throttle_wait($login);
        if ($wait > 0) {
            write_access_log('LOGIN_THROTTLED', $login ?: '-', '-');
            $back('login.err.throttled');
        }
        $user = $login !== '' ? wiki_find_user_by_otp_email(wiki_users_list(), $login) : null;
        if ($user && wiki_user_signin_method($user) !== 'password') $user = null;
        // An empty password is refused by the same path, so it costs the same time.
        if (!wiki_password_verify($user ? (int)$user['uid'] : null, $pw) || $pw === '') {
            wiki_pw_throttle_fail($login);
            write_access_log('LOGIN_FAILED', $login ?: '-', '-', 'password');
            $back('login.err.bad-password');
        }
        wiki_pw_throttle_clear($login);
        $cred = wiki_credentials_get((int)$user['uid']);
        if (!empty($cred['mustChange'])) {
            // A temporary password only opens the change form, never the wiki.
            session_regenerate_id(true);
            $_SESSION['pw_change'] = ['uid' => (int)$user['uid'], 'expires' => time() + WIKI_PW_CHANGE_TTL,
                                      'remember' => !empty($_POST['remember'])];
            header('Location: login.php?step=change');
            exit;
        }
        wiki_pw_sign_in($user, !empty($_POST['remember']));
    }

    if ($_action === 'password_forgot') {
        $login = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($login, FILTER_VALIDATE_EMAIL)) $back('login.err.invalid-email', 'login.php?step=forgot');
        $user = wiki_find_user_by_otp_email(wiki_users_list(), $login);
        if ($user && wiki_user_signin_method($user) === 'password') {
            // At most one mail a minute per address, so the form cannot be used to flood an inbox.
            $now = time();
            $sendable = wiki_json_mutate(WIKI_SYSTEM_DATA . 'login_throttle.json', function (array &$doc) use ($login, $now) {
                $k = 'r:' . hash('sha256', $login);
                $last = (int)(($doc[$k] ?? [0])[0] ?? 0);
                if ($last > $now - WIKI_PW_RESET_GAP) return false;
                $doc[$k] = [$now];
                return true;
            });
            if ($sendable) {
                $ok = wiki_pw_send_link($user, 'reset');
                write_access_log($ok ? 'PW_RESET_SENT' : 'PW_RESET_UNSENT', $login, $user['name'] ?? '');
            }
        }
        // The same answer whatever happened.
        $back('login.notice.reset-sent', 'login.php', true);
    }

    if ($_action === 'password_set') {
        $token = (string)($_POST['t'] ?? '');
        $to    = 'login.php?step=set&t=' . rawurlencode($token);
        if (!wiki_pw_token_peek($token)) $back('login.err.link-invalid');
        $err = wiki_pw_new_error((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($err) $back($err, $to);
        $t = wiki_pw_token_take($token);                      // spent here, once
        $users = wiki_users_list();
        $i = $t ? wiki_find_user_index($users, (int)$t['uid']) : null;
        if ($i === null || wiki_user_signin_method($users[$i]) !== 'password') $back('login.err.link-invalid');
        wiki_password_set((int)$t['uid'], (string)$_POST['password']);
        write_access_log('PW_SET', wiki_user_otp_email($users[$i]), $users[$i]['name'] ?? '', $t['purpose'] ?? '');
        // The link proved the mailbox, so it signs the person in.
        wiki_pw_sign_in(wiki_users_list()[$i], false);
    }

    if ($_action === 'password_change') {
        $pc = $_SESSION['pw_change'] ?? null;
        if (!$pc || time() > (int)($pc['expires'] ?? 0)) { unset($_SESSION['pw_change']); $back('login.err.session-expired'); }
        $err = wiki_pw_new_error((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($err) $back($err, 'login.php?step=change');
        $users = wiki_users_list();
        $i = wiki_find_user_index($users, (int)$pc['uid']);
        if ($i === null || wiki_user_signin_method($users[$i]) !== 'password') { unset($_SESSION['pw_change']); $back('login.err.session-expired'); }
        wiki_password_set((int)$pc['uid'], (string)$_POST['password']);
        write_access_log('PW_CHANGED', wiki_user_otp_email($users[$i]), $users[$i]['name'] ?? '', 'from temporary');
        wiki_pw_sign_in(wiki_users_list()[$i], !empty($pc['remember']));
    }
}

// ── OIDC flow ─────────────────────────────────────────────────────────────────

if (!wiki_auth_offers('sso')) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

try {
    $oidc = new \Jumbojett\OpenIDConnectClient(
        OIDC_PROVIDER_URL,
        OIDC_CLIENT_ID,
        OIDC_CLIENT_SECRET
    );
    $oidc->setRedirectURL(OIDC_REDIRECT_URI);
    $oidc->addScope(['openid', 'profile', 'email']);

    if ($_action === 'link_sso') {
        // A signed-in user adding SSO from My Preferences. The nonce was handed out by
        // api.php (account_sso_link_start) to this session only, so a link to this URL
        // from another site cannot start a link. The provider is asked to show its login
        // whatever its own session holds, so the person sees which identity they attach.
        $ls = $_SESSION['link_sso'] ?? null;
        if (!$ls || !hash_equals((string)($ls['nonce'] ?? ''), (string)($_GET['n'] ?? ''))
            || time() > (int)($ls['expires'] ?? 0) || (int)($_SESSION['user']['uid'] ?? 0) !== (int)($ls['uid'] ?? -1)) {
            unset($_SESSION['link_sso']);
            header('Location: index.php?account_error=account.err.link-expired');
            exit;
        }
        // Only a marker that passed the nonce check here is honoured at the callback, or a
        // forged POST to account_sso_link_start followed by a plain auth.php would link.
        $_SESSION['link_sso']['started'] = true;
        $oidc->addAuthParam(['prompt' => 'login']);
    }

    if ($oidc->authenticate()) {
        $userInfo = $oidc->requestUserInfo();
        // Every claim, from the verified ID token and userinfo (userinfo wins where both
        // have one). AD FS's userinfo carries only `sub`, so without the ID token there is no
        // name or address; Entra's groups, roles and tid live there too — what automatic
        // access rules match on.
        $as_array = fn($o) => json_decode(json_encode($o ?? []), true) ?: [];
        $id_claims = $as_array($oidc->getVerifiedClaims());
        $ui_claims = $as_array($userInfo);
        // OIDC Core 5.3.2: userinfo's `sub` must be the ID token's, or the response is not
        // about this sign-in. The ID token's is the one that was verified, so it decides.
        $id_sub = (string)($id_claims['sub'] ?? '');
        if ($id_sub !== '' && isset($ui_claims['sub']) && (string)$ui_claims['sub'] !== $id_sub) {
            throw new Exception('The provider\'s user info is for a different subject than the ID token.');
        }
        $all = array_merge($id_claims, $ui_claims);
        if ($id_sub !== '') $all['sub'] = $id_sub;
        // Entra ID often sends no `email`; its sign-in name is an address in practice.
        // Used for display and the request only — rules read the raw claims, so an
        // email-domain rule never matches on this fallback.
        $email = (string)($all['email'] ?? '');
        if ($email === '') {
            foreach (['preferred_username', 'upn'] as $k) {
                if (filter_var($all[$k] ?? '', FILTER_VALIDATE_EMAIL)) { $email = (string)$all[$k]; break; }
            }
        }
        $redirect = wiki_oidc_complete([
            'sub'    => (string)($all['sub'] ?? ''),
            'email'  => $email,
            'name'   => (string)($all['name'] ?? ''),
            'locale' => (string)($all['locale'] ?? 'en_US'),
            'claims' => $all,
        ], $oidc->getIdToken());
        header('Location: ' . $redirect);
        exit;
    }

} catch (\Jumbojett\OpenIDConnectClientException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'user profile') !== false || stripos($msg, 'invalid_request') !== false) {
        write_access_log('LOGIN_DENIED', '-', '-', $msg);
        session_destroy();
        header('Location: not_registered.php');
        exit;
    }
    write_access_log('LOGIN_ERROR', '-', '-', $msg);
    echo "OIDC Authentication Error: " . htmlspecialchars($msg);
} catch (Exception $e) {
    write_access_log('LOGIN_ERROR', '-', '-', $e->getMessage());
    echo "Error: " . htmlspecialchars($e->getMessage());
}
