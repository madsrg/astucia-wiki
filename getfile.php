<?php
// =================================================================
// PHP WIKI - SECURE FILE GATEWAY
// =================================================================

require_once 'config.php';

session_start();

// If authentication is enabled and no user is in the session, deny access.
if (AUTHENTICATION_ENABLED && !isset($_SESSION['user'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo "Authentication Required";
    exit;
}


if (isset($_GET['path'])) {
    $requested_path = $_GET['path'];

    // --- CRUCIAL SECURITY CHECK ---
    // This prevents directory traversal attacks (e.g., ../../secret.txt)

    // 1. Get the absolute, canonical path of the allowed base directory.
    //    If a space param is provided, scope to PAGES_DIR/<space>.
    $base_path = realpath(PAGES_DIR);
    $_sp = trim($_GET['space'] ?? '');
    if ($_sp !== '') {
        $_sp_safe = basename($_sp);
        $_sp_candidate = PAGES_DIR . '/' . $_sp_safe;
        if (is_dir($_sp_candidate)) {
            $base_path = realpath($_sp_candidate);
        }
    }

    // 2. Construct the full path to the requested file.
    $full_path = $base_path . '/' . $requested_path;

    // 3. Get the absolute, canonical path of the requested file.
    $real_file_path = realpath($full_path);

    // 4. Check if the requested file's path is valid and actually exists inside the allowed base path.
    if ($real_file_path === false || strpos($real_file_path, $base_path) !== 0) {
        // If it doesn't, deny access.
        header("HTTP/1.1 403 Forbidden");
        echo "Access Denied";
        exit;
    }

    if (file_exists($real_file_path)) {
        // Set headers to display the file inline in the browser
        header('Content-Type: ' . mime_content_type($real_file_path));
        header('Content-Disposition: inline; filename="' . basename($real_file_path) . '"');
        header('Content-Length: ' . filesize($real_file_path));
        
        // Output the file contents
        readfile($real_file_path);
        exit;
    }
}

// If the path is missing or the file doesn't exist, return a 404 error.
header("HTTP/1.1 404 Not Found");
echo "File Not Found";
exit;
