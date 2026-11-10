<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PHP WIKI - SECURE FILE GATEWAY
// =================================================================

require_once 'config.php';
require_once 'service_auth.php';   // actor_spaces_filter(), wiki_path_space()
require_once 'remember_me.php';

session_start();
wiki_remember_restore();

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

    if (is_file($real_file_path)) {
        $mime = mime_content_type($real_file_path) ?: 'application/octet-stream';
        $name = basename($real_file_path);

        // `download=1` is the files library's Download action: save, whatever the type.
        // Without it the browser shows what it can and downloads the rest (Open).
        $disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
        // The name is user-chosen, so it is never pasted into the header raw: a `"` or a
        // line break would end the header early. An ASCII fallback, plus RFC 5987 for the
        // real name.
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . filesize($real_file_path));
        header('X-Content-Type-Options: nosniff');
        // A document the browser would *run* — HTML, SVG, XML — is opened in a sandbox, as
        // an opaque origin with scripts off. Shown inline unsandboxed, an uploaded file
        // would run with this wiki's session. Only these types: Chrome's PDF viewer refuses
        // to render under `sandbox`, and an <img> embed ignores the header anyway.
        if ($disposition === 'inline' && preg_match('#^(text/html|application/xhtml\+xml|image/svg\+xml|text/xml|application/xml)#i', $mime)) {
            header('Content-Security-Policy: sandbox');
        }
        
        // Output the file contents
        readfile($real_file_path);
        exit;
    }
}

// If the path is missing or the file doesn't exist, return a 404 error.
header("HTTP/1.1 404 Not Found");
echo "File Not Found";
exit;
