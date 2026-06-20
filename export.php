<?php
require_once 'config.php';
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
$full_path = 'pages/' . ltrim($sanitized_path, '/');

if (!file_exists($full_path) || pathinfo($full_path, PATHINFO_EXTENSION) !== 'list') {
    header("HTTP/1.1 404 Not Found");
    echo "List file not found.";
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