<?php
require_once 'config.php';
session_start();

if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (!AUTHENTICATION_ENABLED) {
    header('Location: index.php');
    exit;
}

// Cancel OTP flow
if (($_GET['action'] ?? '') === 'otp_cancel') {
    unset($_SESSION['otp_pending'], $_SESSION['login_notice']);
    header('Location: login.php');
    exit;
}

$loggedOut  = isset($_GET['logged_out']);
$otpStep    = isset($_SESSION['otp_pending']) || ($_GET['step'] ?? '') === 'verify';
$otpEmail   = $_SESSION['otp_pending']['email'] ?? '';
$error      = $_SESSION['login_error']  ?? null; unset($_SESSION['login_error']);
$notice     = $_SESSION['login_notice'] ?? null; unset($_SESSION['login_notice']);

$showOidc = in_array(AUTHENTICATION, ['oidc', 'both']);
$showOtp  = in_array(AUTHENTICATION, ['otp', 'both']);

// Mask email for display: a••••@example.com
function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $local = $parts[0];
    $masked = (strlen($local) > 1) ? substr($local, 0, 1) . '••••' : '••••';
    return $masked . '@' . $parts[1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_TITLE); ?> — Sign In</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body { display: flex; flex-direction: column; min-height: 100vh; background: var(--bg-color); margin: 0; }
        .login-center { flex: 1; display: flex; align-items: center; justify-content: center; }
        .login-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 2.5rem 3rem;
            width: 100%;
            max-width: 380px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }
        .login-logo { width: 64px; height: 64px; object-fit: contain; margin-bottom: 1rem; }
        .login-title { font-size: 1.3rem; font-weight: 700; color: #2d3748; margin: 0 0 0.25rem; }
        .login-subtitle { font-size: 0.85rem; color: #718096; margin: 0 0 1.75rem; }
        .login-msg {
            border-radius: 6px; padding: 0.6rem 1rem; font-size: 0.85rem; margin-bottom: 1.25rem; text-align: left;
        }
        .login-msg-error  { background: #fff5f5; border: 1px solid #fc8181; color: #c53030; }
        .login-msg-success { background: #f0fff4; border: 1px solid #9ae6b4; color: #276749; }
        .login-or { display: flex; align-items: center; gap: 0.75rem; margin: 1.25rem 0; color: #a0aec0; font-size: 0.8rem; }
        .login-or::before, .login-or::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }
        .login-form-group { text-align: left; margin-bottom: 0.9rem; }
        .login-label { display: block; font-size: 0.82rem; font-weight: 500; color: #4a5568; margin-bottom: 0.3rem; }
        .login-input { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.95rem; box-sizing: border-box; }
        .login-input:focus { outline: none; border-color: var(--accent-blue); box-shadow: 0 0 0 2px rgba(66,153,225,0.2); }
        .btn-login {
            display: flex; align-items: center; justify-content: center; gap: 0.6rem;
            width: 100%; padding: 0.65rem 1rem; box-sizing: border-box;
            background: var(--accent-blue); color: white;
            border: none; border-radius: 6px;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            text-decoration: none; transition: background 0.15s;
        }
        .btn-login:hover { background: var(--accent-blue-hover); }
        .btn-login-secondary {
            display: flex; align-items: center; justify-content: center;
            width: 100%; padding: 0.65rem 1rem; box-sizing: border-box;
            background: white; color: #4a5568;
            border: 1px solid var(--border-color); border-radius: 6px;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            text-decoration: none; transition: background 0.15s;
        }
        .btn-login-secondary:hover { background: #f7fafc; }
        .login-hint { font-size: 0.78rem; color: #a0aec0; margin-top: 1rem; }
        .login-back { font-size: 0.82rem; color: var(--accent-blue); text-decoration: none; display: inline-block; margin-top: 0.75rem; }
        .login-back:hover { text-decoration: underline; }
        .login-otp-target { font-size: 0.85rem; color: #4a5568; margin-bottom: 1rem; }
        .login-otp-code-input { text-align: center; letter-spacing: 0.4em; font-size: 1.4rem; font-family: monospace; }
    </style>
</head>
<body>
    <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
    <div class="env-banner env-banner-<?php echo htmlspecialchars(ENVIRONMENT); ?>">
        <?php echo strtoupper(htmlspecialchars(ENVIRONMENT)); ?> ENVIRONMENT — changes here are not live
    </div>
    <?php endif; ?>
    <div class="login-center">
    <div class="login-card">
        <img src="logo.png" alt="Logo" class="login-logo">
        <h1 class="login-title"><?php echo htmlspecialchars(APP_TITLE); ?></h1>
        <p class="login-subtitle">Sign in to continue</p>

        <?php if ($loggedOut): ?>
        <div class="login-msg login-msg-success">You have been logged out.</div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="login-msg login-msg-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($notice && !$error): ?>
        <div class="login-msg login-msg-success"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <?php if ($showOidc && !$otpStep): ?>
        <a href="auth.php" class="btn-login">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Sign In
        </a>
        <?php endif; ?>

        <?php if ($showOidc && $showOtp && !$otpStep): ?>
        <div class="login-or">or</div>
        <?php endif; ?>

        <?php if ($showOtp): ?>
            <?php if (!$otpStep): ?>
            <form method="POST" action="auth.php?action=otp_send">
                <div class="login-form-group">
                    <label class="login-label" for="otp-email">Email address</label>
                    <input class="login-input" type="email" id="otp-email" name="email" required autofocus placeholder="you@example.com">
                </div>
                <button type="submit" class="<?php echo $showOidc ? 'btn-login-secondary' : 'btn-login'; ?>">Send code</button>
            </form>
            <?php else: ?>
            <p class="login-otp-target">Enter the 6-digit code sent to<br><strong><?php echo htmlspecialchars(mask_email($otpEmail)); ?></strong></p>
            <form method="POST" action="auth.php?action=otp_verify">
                <div class="login-form-group">
                    <label class="login-label" for="otp-code">Login code</label>
                    <input class="login-input login-otp-code-input" type="text" id="otp-code" name="code" required autofocus maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="000000" autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn-login">Sign in</button>
            </form>
            <?php if ($showOidc): ?>
            <div class="login-or">or</div>
            <a href="auth.php" class="btn-login-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Sign In with SSO instead
            </a>
            <?php endif; ?>
            <a href="login.php?action=otp_cancel" class="login-back">← Use a different email</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </div>
</body>
</html>
