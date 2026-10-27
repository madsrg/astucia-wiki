<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Authentication handler — supports OIDC and/or OTP depending on AUTHENTICATION config.
 */
require_once __DIR__ . '/config.php';
require_once 'logger.php';
require_once 'mailer.php';
require_once __DIR__ . '/service_auth.php';   // wiki_migrate_user_emails(), wiki_user_notify_email()
require_once __DIR__ . '/remember_me.php';    // OTP "keep me signed in"
require_once __DIR__ . '/accounts.php';       // the account model, codes, linking, wiki_oidc_complete()

session_start();

if (!AUTHENTICATION_ENABLED) {
    header("Location: index.php");
    exit;
}

wiki_migrate_user_emails();   // before any read of users.json below

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
    if ($idToken && in_array(AUTHENTICATION, ['oidc', 'both'])) {
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
if ($_action === 'otp_send' && in_array(AUTHENTICATION, ['otp', 'both'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['login_error'] = 'login.err.invalid-email';
        header('Location: login.php');
        exit;
    }
    $user = wiki_find_user_by_otp_email(wiki_users_list(), $email);
    // Always say "code sent" — don't reveal whether the email exists
    if ($user) {
        wiki_otp_issue('login', $email, $user['name'] ?? '');
        write_access_log('OTP_SENT', $email, $user['name'] ?? '');
    }
    $_SESSION['login_notice'] = 'login.notice.code-sent';
    header('Location: login.php?step=verify');
    exit;
}

// ── OTP: Verify code ──────────────────────────────────────────────────────────

if ($_action === 'otp_verify' && in_array(AUTHENTICATION, ['otp', 'both'])) {
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

// ── OIDC flow ─────────────────────────────────────────────────────────────────

if (!in_array(AUTHENTICATION, ['oidc', 'both'])) {
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
        $redirect = wiki_oidc_complete([
            'sub'    => $userInfo->sub    ?? '',
            'email'  => $userInfo->email  ?? '',
            'name'   => $userInfo->name   ?? '',
            'locale' => $userInfo->locale ?? 'en_US',
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
