<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PHP WIKI - SECURE FILE GATEWAY
// =================================================================

require_once 'config.php';
require_once 'service_auth.php';   // actor_spaces_filter(), wiki_path_space()

session_start();

// If authentication is enabled and no user is in the session, deny access.
if (AUTHENTICATION_ENABLED && !isset($_SESSION['user'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo "Authentication Required";
    exit;
}

// Which Spaces this reader may see. Only session users reach this file — a service token
// carries no session and is refused above — so the actor is always the session user, and
// the role lookup matches get_current_role()'s session branch in api.php. With
// authentication off there is one local user and no restriction to apply.
$allowed_spaces = AUTHENTICATION_ENABLED
    ? actor_spaces_filter($_SESSION['user']['role'] ?? 'reader', null)
    : null;

function deny_403() {
    header("HTTP/1.1 403 Forbidden");
    echo "Access Denied";
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
        // Dot-prefixed names are not Spaces. Without this, ?space=.git serves the content
        // repository's internals — including remote URLs with credentials in them. api.php
        // has refused these since it grew Spaces; this gateway never did.
        if ($_sp_safe === '' || $_sp_safe[0] === '.') deny_403();
        $_sp_candidate = PAGES_DIR . '/' . $_sp_safe;
        if (is_dir($_sp_candidate)) {
            $base_path = realpath($_sp_candidate);
        }
    }

    // 2. Construct the full path to the requested file.
    $full_path = $base_path . '/' . $requested_path;

    // 3. Get the absolute, canonical path of the requested file.
    $real_file_path = realpath($full_path);

    // 4. Check the resolved file is really inside the base directory. The trailing
    //    separator matters: without it a base of "…/Alpha" also matches "…/Alpha2/leak",
    //    a different Space one directory up.
    $base_prefix = rtrim($base_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($real_file_path === false || strpos($real_file_path, $base_prefix) !== 0) {
        deny_403();
    }

    // 5. …and that the reader is allowed the Space it landed in. This is asked of the
    //    *resolved path*, not of the ?space= parameter, because the parameter is optional:
    //    with no space the base is PAGES_DIR and "Bravo/notes.md.uploads/plan.txt" reaches
    //    Bravo without ever naming it. Checking the parameter alone would leave that open.
    if (!wiki_space_allowed($allowed_spaces, wiki_path_space($real_file_path))) {
        deny_403();
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
