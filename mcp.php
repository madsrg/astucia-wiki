<?php
// =================================================================
// ASTUCIA WIKI — MCP SERVER
// Exposes the wiki_* tools (also used for in-app AI chat @mentions) over the
// Model Context Protocol: JSON-RPC 2.0 via the Streamable HTTP transport.
// https://modelcontextprotocol.io
//
// Auth: same Bearer service tokens as api.php (wk_ai_… AI users, wk_sys_…
// API accounts) — configure these in Admin → AI. Role (editor/reader)
// controls write access exactly as it does for chat/agent jobs.
//
// Stateless by design: every call re-authenticates via the Bearer token and
// rebuilds its own PageIndexer, so there is no server-held session to track —
// no Mcp-Session-Id bookkeeping is needed.
// =================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/service_auth.php';
require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/wiki_ai_tools.php';

header('Content-Type: application/json');

$ai_auth_user = resolve_service_token_auth();
if (!$ai_auth_user) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => [
        'code'    => -32001,
        'message' => 'Authentication required: Authorization: Bearer <wk_ai_… or wk_sys_…> token.',
    ]]);
    exit;
}

// GET: human/agent-readable connection info (not part of the JSON-RPC protocol itself).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
    echo json_encode([
        'success'  => true,
        'protocol' => 'Model Context Protocol (MCP) — JSON-RPC 2.0 over Streamable HTTP',
        'endpoint' => $base_url,
        'auth'     => 'Authorization: Bearer <token>  (AI user or API account token from Admin → AI)',
        'space'    => 'Add ?space=SpaceName to target a specific space. Omit for the default space.',
        'note'     => 'POST JSON-RPC 2.0 requests here: initialize, tools/list, tools/call.',
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Only GET (info) and POST (JSON-RPC) are supported.']]);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: invalid JSON.']]);
    exit;
}

$id              = $body['id'] ?? null;
$method          = $body['method'] ?? '';
$params          = $body['params'] ?? [];
$is_notification = !array_key_exists('id', $body);

// Resolve the wiki space (mirrors api.php's ?space= convention).
$space_dir = rtrim(PAGES_DIR, '/');
$_sp = trim($_GET['space'] ?? '');
if ($_sp !== '') {
    $_sp_safe = basename($_sp);
    $_sp_candidate = $space_dir . '/' . $_sp_safe;
    if ($_sp_safe !== '' && $_sp_safe[0] !== '.' && is_dir($_sp_candidate)) {
        $space_dir = $_sp_candidate;
    }
}
$indexer = new PageIndexer($space_dir);

switch ($method) {
    case 'initialize':
        echo json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => ['tools' => (object)[]],
                'serverInfo'      => ['name' => 'astuciawiki', 'version' => '1.0'],
            ],
        ]);
        break;

    case 'notifications/initialized':
    case 'notifications/cancelled':
        // Notifications carry no id and expect no JSON-RPC response body.
        header('HTTP/1.1 202 Accepted');
        break;

    case 'ping':
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object)[]]);
        break;

    case 'tools/list':
        $tools = array_map(fn($t) => [
            'name'        => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['params'],
        ], wiki_tool_definitions());
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
        break;

    case 'tools/call':
        $tool_name  = $params['name'] ?? '';
        $tool_input = $params['arguments'] ?? [];
        if (!is_array($tool_input)) $tool_input = [];
        $result   = execute_ai_tool($tool_name, $tool_input, $ai_auth_user, $indexer, $space_dir);
        $is_error = str_starts_with($result, 'Error:');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [['type' => 'text', 'text' => $result]],
                'isError' => $is_error,
            ],
        ]);
        break;

    default:
        if ($is_notification) { header('HTTP/1.1 202 Accepted'); break; }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: {$method}"]]);
}
