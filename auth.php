<?php
/**
 * Authentication handler — supports OIDC and/or OTP depending on AUTHENTICATION config.
 */
require_once __DIR__ . '/config.php';
require_once 'logger.php';
require_once 'mailer.php';

session_start();

if (!AUTHENTICATION_ENABLED) {
    header("Location: index.php");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function load_users(): array {
    $file = WIKI_SYSTEM_DATA . 'users.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true)['users'] ?? [];
}

function save_users(array $users): void {
    file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode(['users' => $users], JSON_PRETTY_PRINT));
}

function load_requests(): array {
    $file = WIKI_SYSTEM_DATA . 'user_requests.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true)['requests'] ?? [];
}

function save_requests(array $requests): void {
    file_put_contents(WIKI_SYSTEM_DATA . 'user_requests.json', json_encode(['requests' => $requests], JSON_PRETTY_PRINT));
}

function find_by_sub(array $list, string $sub): ?int {
    foreach ($list as $i => $item) {
        if (($item['sub'] ?? '') === $sub) return $i;
    }
    return null;
}

function find_otp_user(array $users, string $email): ?array {
    foreach ($users as $u) {
        if (strtolower(trim($u['email'] ?? '')) === $email
            && ($u['auth'] ?? 'oidc') === 'otp') {
            return $u;
        }
    }
    return null;
}

$_action = $_GET['action'] ?? '';

// ── Logout ────────────────────────────────────────────────────────────────────

if ($_action === 'logout') {
    $idToken    = $_SESSION['id_token'] ?? null;
    $logout_id  = $_SESSION['user']['sub'] ?? $_SESSION['user']['email'] ?? '-';
    $logout_name = $_SESSION['user']['name'] ?? '-';
    write_access_log('LOGOUT', $logout_id, $logout_name);
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

if ($_action === 'otp_send' && in_array(AUTHENTICATION, ['otp', 'both'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['login_error'] = 'Please enter a valid email address.';
        header('Location: login.php');
        exit;
    }
    $users = load_users();
    $user  = find_otp_user($users, $email);
    // Always say "code sent" — don't reveal whether the email exists
    if ($user) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_pending'] = [
            'email'    => $email,
            'code'     => password_hash($code, PASSWORD_DEFAULT),
            'expires'  => time() + 600,
            'attempts' => 0,
        ];
        $html = "<p>Your login code for <strong>" . htmlspecialchars(APP_TITLE) . "</strong> is:</p>"
              . "<p style='font-size:2rem;font-weight:bold;letter-spacing:0.3em;font-family:monospace'>{$code}</p>"
              . "<p>This code expires in 10 minutes. If you didn't request this, you can ignore this email.</p>";
        send_email($email, $user['name'] ?? '', 'Your login code for ' . APP_TITLE, $html);
        write_access_log('OTP_SENT', $email, $user['name'] ?? '');
    }
    $_SESSION['login_notice'] = 'If that address is registered, a 6-digit code has been sent.';
    header('Location: login.php?step=verify');
    exit;
}

// ── OTP: Verify code ──────────────────────────────────────────────────────────

if ($_action === 'otp_verify' && in_array(AUTHENTICATION, ['otp', 'both'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: login.php'); exit; }
    $pending = $_SESSION['otp_pending'] ?? null;
    if (!$pending) {
        $_SESSION['login_error'] = 'Session expired. Please start again.';
        header('Location: login.php');
        exit;
    }
    if (time() > ($pending['expires'] ?? 0)) {
        unset($_SESSION['otp_pending']);
        $_SESSION['login_error'] = 'The code has expired. Please request a new one.';
        header('Location: login.php');
        exit;
    }
    if (($pending['attempts'] ?? 0) >= 5) {
        unset($_SESSION['otp_pending']);
        $_SESSION['login_error'] = 'Too many failed attempts. Please request a new code.';
        header('Location: login.php');
        exit;
    }
    $submitted = trim($_POST['code'] ?? '');
    if (!$submitted || !password_verify($submitted, $pending['code'] ?? '')) {
        $_SESSION['otp_pending']['attempts'] = ($pending['attempts'] ?? 0) + 1;
        $_SESSION['login_error'] = 'Invalid code. Please try again.';
        header('Location: login.php?step=verify');
        exit;
    }
    // Valid code — look up user and establish session
    $email = $pending['email'];
    unset($_SESSION['otp_pending']);
    $users = load_users();
    $user  = find_otp_user($users, $email);
    if (!$user) {
        $_SESSION['login_error'] = 'Account not found.';
        header('Location: login.php');
        exit;
    }
    $_SESSION['user'] = [
        'uid'        => $user['uid']        ?? 0,
        'name'       => $user['name']       ?? '',
        'email'      => $user['email']      ?? $email,
        'role'       => $user['role']       ?? 'editor',
        'spaces'     => $user['spaces']     ?? null,
        'fontFamily' => $user['fontFamily'] ?? 'sans',
        'fontSize'   => $user['fontSize']   ?? 'normal',
        'auth'       => 'otp',
    ];
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

    if ($oidc->authenticate()) {
        $userInfo = $oidc->requestUserInfo();
        $sub      = $userInfo->sub   ?? '';
        $email    = $userInfo->email ?? '';
        $name     = $userInfo->name  ?? 'Unknown User';
        $locale   = $userInfo->locale ?? 'en_US';

        if (!$sub) {
            throw new Exception('OAuth token missing required `sub` claim.');
        }

        $users = load_users();

        // ── Check approved users ───────────────────────────────────────────
        $idx = find_by_sub($users, $sub);
        if ($idx !== null) {
            $u = $users[$idx];

            // Reject if this user is configured for OTP auth only
            if (($u['auth'] ?? 'oidc') === 'otp') {
                write_access_log('LOGIN_DENIED', $sub, $name, 'OTP user attempted OIDC login');
                session_destroy();
                $_SESSION['login_error'] = 'This account uses email code (OTP) login. Please use the email field below.';
                header('Location: login.php');
                exit;
            }

            $users[$idx]['name'] = $name;
            if (!isset($u['uid'])) {
                $max_uid = 0;
                foreach ($users as $eu) { if (isset($eu['uid']) && $eu['uid'] > $max_uid) $max_uid = $eu['uid']; }
                $users[$idx]['uid'] = $max_uid + 1;
                $u['uid'] = $users[$idx]['uid'];
            }
            if (!isset($u['auth'])) {
                $users[$idx]['auth'] = 'oidc';
            }
            save_users($users);

            $_SESSION['user'] = [
                'sub'        => $sub,
                'uid'        => $u['uid'],
                'name'       => $name,
                'email'      => $email,
                'locale'     => $locale,
                'role'       => $u['role']       ?? 'editor',
                'spaces'     => $u['spaces']     ?? null,
                'fontFamily' => $u['fontFamily'] ?? 'sans',
                'fontSize'   => $u['fontSize']   ?? 'normal',
                'auth'       => 'oidc',
            ];
            $_SESSION['id_token'] = $oidc->getIdToken();
            write_access_log('LOGIN_OK', $sub, $name, $u['role'] ?? 'editor');
            $redirect = $_SESSION['login_redirect'] ?? 'index.php';
            unset($_SESSION['login_redirect']);
            header("Location: " . $redirect);
            exit;
        }

        // ── Not approved — check / create access request ───────────────────
        $requests = load_requests();
        $ridx     = find_by_sub($requests, $sub);

        unset($_SESSION['user'], $_SESSION['id_token']);
        session_regenerate_id(true);

        if ($ridx !== null) {
            $requests[$ridx]['name'] = $name;
            save_requests($requests);
            $_SESSION['pending_sub']    = $sub;
            $_SESSION['pending_status'] = $requests[$ridx]['status'] ?? 'pending';
            header("Location: pending_access.php");
            exit;
        }

        $requests[] = [
            'sub'          => $sub,
            'name'         => $name,
            'email'        => $email,
            'requested_at' => date('c'),
            'status'       => 'pending',
        ];
        save_requests($requests);

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
        $_SESSION['pending_sub']    = $sub;
        $_SESSION['pending_status'] = 'pending';
        header("Location: pending_access.php");
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
