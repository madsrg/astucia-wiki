<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
require_once 'config.php';
require_once 'service_auth.php';   // actor_spaces_filter(), wiki_path_space()
session_start();

// If authentication is enabled and no user is in the session, deny access.
if (AUTHENTICATION_ENABLED && !isset($_SESSION['user'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo "Authentication Required";
    exit;
}

if (!isset($_GET['path']) || !isset($_GET['format'])) {
    header("HTTP/1.1 400 Bad Request");
    echo "Missing required parameters.";
    exit;
}

$requested_path = $_GET['path'];
$format = strtolower($_GET['format']);

// Basic path sanitization
$sanitized_path = str_replace('..', '', $requested_path);

// Resolve against PAGES_DIR, and against the Space when one is named. This used to be a
// hardcoded relative 'pages/', which is only the content directory on an install that
// happens to keep it inside the web root — everywhere else this endpoint 404s. It also
// meant any .list in any Space was one `path` away, with no ACL between.
$base_dir = rtrim(PAGES_DIR, '/');
$_sp = trim($_GET['space'] ?? '');
if ($_sp !== '') {
    $_sp_safe = basename($_sp);
    if ($_sp_safe === '' || $_sp_safe[0] === '.') {
        header("HTTP/1.1 403 Forbidden");
        echo "Access Denied";
        exit;
    }
    if (is_dir($base_dir . '/' . $_sp_safe)) $base_dir .= '/' . $_sp_safe;
}
$full_path = $base_dir . '/' . ltrim($sanitized_path, '/');

if (!file_exists($full_path) || pathinfo($full_path, PATHINFO_EXTENSION) !== 'list') {
    header("HTTP/1.1 404 Not Found");
    echo "List file not found.";
    exit;
}

// Same rule as api.php and getfile.php: the Space is decided by the resolved path, not by
// the parameter, so a path that reaches into a Space without naming it is still caught.
$allowed_spaces = AUTHENTICATION_ENABLED
    ? actor_spaces_filter($_SESSION['user']['role'] ?? 'reader', null)
    : null;
if (!wiki_space_allowed($allowed_spaces, wiki_path_space(realpath($full_path)))) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access Denied";
    exit;
}

$json_content = file_get_contents($full_path);
$list_data = json_decode($json_content, true);
$items = $list_data['items'] ?? [];
$columns = $list_data['columns'] ?? [];
$filename_base = basename($sanitized_path, '.list');

// --- Export Logic ---

switch ($format) {
    case 'csv':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        $headers = array_map(function($col) { return $col['name']; }, $columns);
        fputcsv($output, $headers);
        
        // Rows
        foreach ($items as $item) {
            $row = [];
            foreach ($columns as $col) {
                $row[] = $item[$col['id']] ?? '';
            }
            fputcsv($output, $row);
        }
        fclose($output);
        break;

    case 'xml':
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.xml"');
        
        $xml = new SimpleXMLElement('<?xml version="1.0"?><items></items>');
        
        foreach ($items as $item) {
            $xml_item = $xml->addChild('item');
            foreach ($columns as $col) {
                // Sanitize column name to be a valid XML tag
                $tag_name = preg_replace('/[^a-zA-Z0-9_]/', '', $col['name']);
                if (is_numeric(substr($tag_name, 0, 1))) {
                    $tag_name = '_' . $tag_name; // Prepend underscore if it starts with a number
                }
                $xml_item->addChild($tag_name, htmlspecialchars($item[$col['id']] ?? ''));
            }
        }
        echo $xml->asXML();
        break;

    default:
        header("HTTP/1.1 400 Bad Request");
        echo "Invalid format specified.";
        break;
}

exit;