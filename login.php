<?php
require_once 'config.php';
session_start();

// Already logged in — go straight to the app
if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

// If auth is disabled, no login page needed
if (!AUTHENTICATION_ENABLED) {
    header('Location: index.php');
    exit;
}

$loggedOut = isset($_GET['logged_out']);
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
            max-width: 360px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }
        .login-logo { width: 64px; height: 64px; object-fit: contain; margin-bottom: 1rem; }
        .login-title { font-size: 1.3rem; font-weight: 700; color: #2d3748; margin: 0 0 0.25rem; }
        .login-subtitle { font-size: 0.85rem; color: #718096; margin: 0 0 1.75rem; }
        .login-logout-msg {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            color: #276749;
            border-radius: 6px;
            padding: 0.6rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }
        .btn-login {
            display: flex; align-items: center; justify-content: center; gap: 0.6rem;
            width: 100%; padding: 0.65rem 1rem; box-sizing: border-box;
            background: var(--accent-blue); color: white;
            border: none; border-radius: 6px;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-login:hover { background: var(--accent-blue-hover); }
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
        <div class="login-logout-msg">You have been logged out.</div>
        <?php endif; ?>

        <a href="auth.php" class="btn-login">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Sign In
        </a>
    </div>
    </div><!-- login-center -->
</body>
</html>
