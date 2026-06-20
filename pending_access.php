<?php
/**
 * Shown to users after authentication when they are not yet in users.json.
 * Collects a contact email (mandatory, since OAuth does not provide one) before
 * showing the "awaiting approval" message.
 * Uses a minimal session (pending_sub / pending_status) set by auth.php.
 */
require_once 'config.php';
session_start();

$pending_sub    = $_SESSION['pending_sub']    ?? null;
$pending_status = $_SESSION['pending_status'] ?? null;

$error        = '';
$show_form    = false;
$show_waiting = false;
$show_denied  = false;

// ── Handle email form POST ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pending_sub) {
    $submitted = trim($_POST['email'] ?? '');
    if (!$submitted || !filter_var($submitted, FILTER_VALIDATE_EMAIL)) {
        $error     = 'Please enter a valid email address.';
        $show_form = true;
    } else {
        $rq_file = WIKI_SYSTEM_DATA . 'user_requests.json';
        $rq_data = file_exists($rq_file)
            ? (json_decode(file_get_contents($rq_file), true) ?? ['requests' => []])
            : ['requests' => []];
        $found = false;
        foreach ($rq_data['requests'] as &$r) {
            if ($r['sub'] === $pending_sub) {
                $r['email'] = $submitted;
                $found = true;
                break;
            }
        }
        unset($r);
        if ($found) {
            file_put_contents($rq_file, json_encode($rq_data, JSON_PRETTY_PRINT));
            unset($_SESSION['pending_sub'], $_SESSION['pending_status']);
            $show_waiting = true;
        } else {
            $error     = 'Your request record was not found. Please try signing in again.';
            $show_form = true;
        }
    }
}

// ── Determine state for GET (or after failed POST) ─────────────────────────────

if (!$show_waiting && !$show_form) {
    if (!$pending_sub) {
        // No session — expired or direct visit.
    } elseif ($pending_status === 'denied') {
        $show_denied = true;
    } else {
        // Pending — show form if email not yet provided.
        $rq_file = WIKI_SYSTEM_DATA . 'user_requests.json';
        $rq_data = file_exists($rq_file)
            ? (json_decode(file_get_contents($rq_file), true) ?? ['requests' => []])
            : ['requests' => []];
        $has_email = false;
        foreach ($rq_data['requests'] as $r) {
            if ($r['sub'] === $pending_sub) {
                $has_email = !empty($r['email']);
                break;
            }
        }
        if ($has_email) {
            $show_waiting = true;
        } else {
            $show_form = true;
        }
    }
}

$no_session = !$pending_sub && !$show_waiting && !$show_denied;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_TITLE); ?> — <?php
        if ($show_denied)  echo 'Access Denied';
        elseif ($no_session) echo 'Session Expired';
        else echo 'Access Pending';
    ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg-color); margin: 0; }
        .page-center { flex: 1; display: flex; align-items: center; justify-content: center; }
        .card {
            background: white; border: 1px solid var(--border-color); border-radius: 10px;
            padding: 2.5rem 3rem; width: 100%; max-width: 440px;
            text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }
        .card-icon {
            width: 52px; height: 52px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;
        }
        .card-icon-pending { background: #ebf8ff; border: 1px solid #bee3f8; color: #2b6cb0; }
        .card-icon-denied  { background: #fff5f5; border: 1px solid #fed7d7; color: #e53e3e; }
        .card-icon-form    { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
        .card h1 { font-size: 1.2rem; font-weight: 700; color: #2d3748; margin: 0 0 0.6rem; }
        .card p  { font-size: 0.9rem; color: #718096; margin: 0 0 1.25rem; line-height: 1.6; }
        .card .form-group { text-align: left; margin-bottom: 1rem; }
        .card label { display: block; font-size: 0.85rem; font-weight: 600; color: #4a5568; margin-bottom: 0.35rem; }
        .card .form-control { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; box-sizing: border-box; }
        .card .form-control:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 3px rgba(66,153,225,0.2); }
        .error-msg { background: #fff5f5; border: 1px solid #fed7d7; color: #c53030; border-radius: 6px; padding: 0.5rem 0.85rem; font-size: 0.85rem; margin-bottom: 1rem; text-align: left; }
        .btn-submit {
            display: block; width: 100%; padding: 0.65rem 1rem;
            background: var(--accent-blue); color: white; border: none;
            border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .btn-submit:hover { background: var(--accent-blue-hover); }
        .btn-back {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.6rem 1.25rem; background: var(--accent-blue); color: white;
            border-radius: 6px; text-decoration: none; font-size: 0.9rem; font-weight: 600;
            transition: background 0.15s;
        }
        .btn-back:hover { background: var(--accent-blue-hover); }
    </style>
</head>
<body>
    <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
    <div class="env-banner env-banner-<?php echo htmlspecialchars(ENVIRONMENT); ?>">
        <?php echo strtoupper(htmlspecialchars(ENVIRONMENT)); ?> ENVIRONMENT — changes here are not live
    </div>
    <?php endif; ?>
<div class="page-center">
<div class="card">

<?php if ($show_form): ?>

    <div class="card-icon card-icon-form">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    </div>
    <h1>One more step</h1>
    <p>Your identity was verified. Please provide a contact email so the administrator can notify you when your access request is reviewed.</p>

    <?php if ($error): ?>
    <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="pending_access.php">
        <div class="form-group">
            <label for="email">Contact Email <span style="color:#e53e3e">*</span></label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="you@example.com" required autofocus
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        <button type="submit" class="btn-submit">Submit Request</button>
    </form>

<?php elseif ($show_waiting): ?>

    <div class="card-icon card-icon-pending">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h1>Access Request Pending</h1>
    <p>Your request has been registered. An administrator will review it shortly, and you will receive an email notification once a decision has been made.</p>
    <a href="login.php" class="btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to login
    </a>

<?php elseif ($show_denied): ?>

    <div class="card-icon card-icon-denied">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    </div>
    <h1>Access Request Denied</h1>
    <p>Your access request for <strong><?php echo htmlspecialchars(APP_TITLE); ?></strong> was not approved. Please contact the wiki administrator if you believe this is an error.</p>
    <a href="login.php" class="btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to login
    </a>

<?php else: /* no session / expired */ ?>

    <div class="card-icon card-icon-pending">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <h1>Session Expired</h1>
    <p>Your session has expired. Please sign in again to check your access request status or to re-submit your request.</p>
    <a href="login.php" class="btn-back">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Sign in again
    </a>

<?php endif; ?>

</div>
</div><!-- page-center -->
</body>
</html>
