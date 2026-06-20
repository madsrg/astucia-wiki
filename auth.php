<?php
/**
 * Auth0 / OIDC Authentication handler.
 * Uses the jumbojett/openid-connect-php library (installed via Composer).
 * Run: composer require jumbojett/openid-connect-php
 *
 * User identity is based on the OAuth `sub` claim, not email.
 * Accepted users live in WIKI_SYSTEM_DATA/users.json.
 * Pending/denied requests live in WIKI_SYSTEM_DATA/user_requests.json.
 *
 * Bootstrap (first admin): create WIKI_SYSTEM_DATA/users.json manually — see config.php for the format.
 * Until users.json exists, every login attempt lands on pending_access.php.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';
require_once 'logger.php';
require_once 'mailer.php';

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

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

// ── Main ──────────────────────────────────────────────────────────────────────

try {
    $oidc = new OpenIDConnectClient(
        OIDC_PROVIDER_URL,
        OIDC_CLIENT_ID,
        OIDC_CLIENT_SECRET
    );
    $oidc->setRedirectURL(OIDC_REDIRECT_URI);
    $oidc->addScope(['openid', 'profile', 'email']);

    $action = $_GET['action'] ?? '';

    if ($action === 'logout') {
        $idToken = $_SESSION['id_token'] ?? null;
        write_access_log('LOGOUT', $_SESSION['user']['sub'] ?? '-', $_SESSION['user']['name'] ?? '-');
        session_destroy();
        $scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $loginUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/login.php?logged_out=1';
        if ($idToken) {
            $oidc->signOut($idToken, $loginUrl);
        } else {
            header('Location: ' . $loginUrl);
        }
        exit;
    }

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
            // Only refresh name from the provider — email is user-managed via preferences.
            $users[$idx]['name'] = $name;
            // Assign a uid if this user was created before the uid field existed.
            if (!isset($u['uid'])) {
                $max_uid = 0;
                foreach ($users as $eu) { if (isset($eu['uid']) && $eu['uid'] > $max_uid) $max_uid = $eu['uid']; }
                $users[$idx]['uid'] = $max_uid + 1;
                $u['uid'] = $users[$idx]['uid'];
            }
            save_users($users);

            $_SESSION['user'] = [
                'sub'    => $sub,
                'uid'    => $u['uid'],
                'name'   => $name,
                'email'  => $email,
                'locale' => $locale,
                'role'       => $u['role']       ?? 'editor',
                'spaces'     => $u['spaces']     ?? null, // null = all spaces
                'fontFamily' => $u['fontFamily'] ?? 'sans',
                'fontSize'   => $u['fontSize']   ?? 'normal',
            ];
            $_SESSION['id_token'] = $oidc->getIdToken();
            write_access_log('LOGIN_OK', $sub, $name, $u['role'] ?? 'editor');
            header("Location: index.php");
            exit;
        }

        // ── Not approved — check / create access request ───────────────────
        $requests = load_requests();
        $ridx     = find_by_sub($requests, $sub);

        // Drop wiki-access session data; keep a minimal pending session for pending_access.php.
        unset($_SESSION['user'], $_SESSION['id_token']);
        session_regenerate_id(true);

        if ($ridx !== null) {
            // Refresh name from provider — don't touch email, the user sets it on pending_access.php.
            $requests[$ridx]['name'] = $name;
            save_requests($requests);
            $_SESSION['pending_sub']    = $sub;
            $_SESSION['pending_status'] = $requests[$ridx]['status'] ?? 'pending';
            header("Location: pending_access.php");
            exit;
        }

        // New request.
        $requests[] = [
            'sub'          => $sub,
            'name'         => $name,
            'email'        => $email,
            'requested_at' => date('c'),
            'status'       => 'pending',
        ];
        save_requests($requests);

        // Notify admin.
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

} catch (OpenIDConnectClientException $e) {
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
