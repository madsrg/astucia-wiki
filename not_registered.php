<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_TITLE); ?> — Access Denied</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-color); }
        .card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 2.5rem 3rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
        }
        .card-icon {
            width: 52px; height: 52px;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            color: #e53e3e;
        }
        .card h1 { font-size: 1.2rem; font-weight: 700; color: #2d3748; margin: 0 0 0.6rem; }
        .card p { font-size: 0.9rem; color: #718096; margin: 0 0 1.75rem; line-height: 1.6; }
        .btn-back {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.6rem 1.25rem;
            background: var(--accent-blue); color: white;
            border-radius: 6px; text-decoration: none;
            font-size: 0.9rem; font-weight: 600;
            transition: background 0.15s;
        }
        .btn-back:hover { background: var(--accent-blue-hover); }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h1>Access not granted</h1>
        <p>Your account is not registered to access <strong><?php echo htmlspecialchars(APP_TITLE); ?></strong>. Please contact the wiki administrator to request access.</p>
        <a href="login.php" class="btn-back">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Back to login
        </a>
    </div>
</body>
</html>
