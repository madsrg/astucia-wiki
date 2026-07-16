<?php
// =================================================================
// AI CORE — Self-contained agent job runner
// Requires: config.php (for PAGES_DIR, WIKI_SYSTEM_DATA), indexer.php
// =================================================================

require_once __DIR__ . '/llm_providers.php';

/**
 * Run a git command in the given working directory.
 * Returns ['output' => string, 'code' => int].
 */
function _ai_git_run(array $args, string $cwd): array {
    $parts = array_map('escapeshellarg', $args);
    $cmd   = 'git ' . implode(' ', $parts);
    $desc  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env   = [
        'PATH'                => '/usr/local/bin:/usr/bin:/bin:' . (getenv('PATH') ?: ''),
        'HOME'                => getenv('HOME') ?: sys_get_temp_dir(),
        'GIT_TERMINAL_PROMPT' => '0',
    ];
    $proc = proc_open($cmd, $desc, $pipes, $cwd, $env);
    if (!is_resource($proc)) return ['output' => '', 'code' => -1];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['output' => trim($out), 'code' => proc_close($proc)];
}

/**
 * Find the git root for the given space directory.
 * Returns ['root' => string, 'prefix' => string] or null.
 */
function _ai_find_git_root(string $space_dir): ?array {
    $space = rtrim($space_dir, '/');
    if (is_dir($space . '/.git')) {
        return ['root' => $space, 'prefix' => ''];
    }
    $pages = rtrim(PAGES_DIR, '/');
    if (is_dir($pages . '/.git')) {
        return ['root' => $pages, 'prefix' => basename($space) . '/'];
    }
    return null;
}

/**
 * Stage and commit a single file into git.
 */
function _ai_git_commit(string $abs_path, string $git_name, string $git_email, string $commit_msg, string $space_dir): void {
    $git_root = _ai_find_git_root($space_dir);
    if (!$git_root) return;
    $rel         = ltrim(str_replace(rtrim($space_dir, '/') . '/', '', $abs_path), '/');
    $git_relpath = $git_root['prefix'] . $rel;
    _ai_git_run(['add', $git_relpath], $git_root['root']);
    _ai_git_run([
        '-c', 'user.name=' . $git_name,
        '-c', 'user.email=' . $git_email,
        'commit', '-m', $commit_msg,
    ], $git_root['root']);
}

// Build the auth header line for an outbound MCP request from a server's
// configured header name + value scheme. Defaults preserve the historical
// "Authorization: Bearer <token>". A blank scheme sends the raw token (e.g.
// "X-Subscription-Token: <token>"). Returns null when no token is set.
function _mcp_auth_header(array $server): ?string {
    $token = $server['auth_token'] ?? '';
    if ($token === '') return null;
    $header = trim($server['auth_header'] ?? '');
    if ($header === '') $header = 'Authorization';
    // array_key_exists so an explicitly-blank scheme isn't overridden by the default.
    $scheme = trim(array_key_exists('auth_prefix', $server) ? (string)$server['auth_prefix'] : 'Bearer');
    return $header . ': ' . ($scheme === '' ? $token : $scheme . ' ' . $token);
}

// Normalizes a stored/posted extra-headers value into a clean list of
// ['name' => ..., 'value' => ...] pairs for persistence. Accepts a list of
// {name,value} objects (the canonical form) or a plain {name: value} map (so
// hand-edited JSON also works). CR/LF are stripped to prevent header injection,
// and entries with a blank name are dropped.
function _sanitize_extra_headers($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $k => $v) {
        if (is_array($v)) {              // list-of-objects form
            $name = (string)($v['name'] ?? '');
            $val  = (string)($v['value'] ?? '');
        } else {                          // {name: value} map form
            $name = (string)$k;
            $val  = (string)$v;
        }
        $name = trim(str_replace(["\r", "\n"], '', $name));
        $val  = str_replace(["\r", "\n"], '', $val);
        if ($name === '') continue;
        $out[] = ['name' => $name, 'value' => $val];
    }
    return $out;
}

// Turns configured extra headers into curl "Name: Value" header lines.
function _extra_header_lines($raw): array {
    $lines = [];
    foreach (_sanitize_extra_headers($raw) as $h) {
        $lines[] = $h['name'] . ': ' . $h['value'];
    }
    return $lines;
}

function _mcp_jsonrpc(string $base_url, ?string $auth_header_line, string $method, array $params, int $timeout = 15, array $extra_headers = []): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ];
    if ($auth_header_line) $headers[] = $auth_header_line;
    foreach ($extra_headers as $line) $headers[] = $line;
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object)[]]);
    $ch = curl_init(rtrim($base_url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '', // advertise gzip/deflate and auto-decode
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw) return ['error' => $err ?: 'no response'];
    // SSE stream: grab the first data: line
    if (str_contains($raw, 'data:')) {
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) { $raw = trim(substr($line, 5)); break; }
        }
    }
    $data = json_decode($raw, true);
    if (!$data) return ['error' => "HTTP {$code}: invalid JSON response"];
    if (isset($data['error'])) return ['error' => _mcp_error_text($data['error'])];
    return ['result' => $data['result'] ?? []];
}

// Turn a JSON-RPC error field into a readable string. Servers vary: some send
// {code, message, data}, some a bare string, some omit message — so surface the
// code and any data rather than collapsing everything to "JSON-RPC error".
function _mcp_error_text($error): string {
    if (is_string($error)) return $error;
    if (!is_array($error)) return 'JSON-RPC error';
    // JSON-RPC uses "message"; REST-style APIs (which you hit if the URL points
    // at a plain HTTP API rather than an MCP endpoint) use "detail".
    $msg  = $error['message'] ?? $error['detail'] ?? '';
    $code = isset($error['code']) ? 'code ' . $error['code'] : '';
    // Extra context: JSON-RPC "data" or REST-style "meta".
    $ctx   = $error['data'] ?? $error['meta'] ?? null;
    $extra = $ctx === null ? '' : (is_string($ctx) ? $ctx : json_encode($ctx));
    $parts = array_filter([$msg, $code, $extra]);
    return $parts ? implode(' — ', $parts) : 'JSON-RPC error';
}

function _mcp_fetch_tools(array $server): array {
    $res = _mcp_jsonrpc($server['url'] ?? '', _mcp_auth_header($server), 'tools/list', [], 15, _extra_header_lines($server['extra_headers'] ?? []));
    if (isset($res['error'])) return [];
    $tools = $res['result']['tools'] ?? [];
    $result = [];
    foreach ($tools as $t) {
        if (empty($t['name'])) continue;
        $schema = $t['inputSchema'] ?? ['type' => 'object', 'properties' => (object)[], 'required' => []];
        // json_decode(..., true) turns a tool with no parameters (JSON "properties":{})
        // into an empty PHP array, which json_encode later re-emits as [] instead of {} —
        // Anthropic/OpenAI both reject that ("input_schema.properties: Input should be an object").
        if (isset($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = (object)[];
        }
        $result[] = [
            'name'        => $t['name'],
            'description' => $t['description'] ?? '',
            'params'      => $schema,
        ];
    }
    return $result;
}

// Slugifies a name for use in identifiers: MCP tool aliases (below) and the
// "src:<slug>" explicit-source reference a user can type in a chat message.
function _mcp_slug(string $name): string {
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
    return $slug !== '' ? $slug : 'mcp';
}

// Namespaces a remote MCP tool name under its server so it can never collide with
// a built-in wiki_* tool (or another server's tool of the same name) — e.g. an
// AstuciaWiki instance connecting to another AstuciaWiki's mcp.php would otherwise
// see identically-named wiki_list_pages/wiki_write_page/etc. tools from both sides.
function _mcp_tool_alias(string $server_name, string $tool_name): string {
    return substr(_mcp_slug($server_name) . '__' . $tool_name, 0, 64);
}

// Extracts "src:<slug>" references from a message/prompt — an explicit,
// deterministic way for a human (or an Agent Job prompt) to force a reply to use
// only a specific MCP server's tools, as an alternative to the free-text
// per-server "instructions" field which only *hints* at when to use a server.
function _mcp_extract_forced_slugs(string $text): array {
    preg_match_all('/\bsrc:([a-zA-Z0-9_]+)/i', $text, $m);
    return array_unique(array_map('strtolower', $m[1] ?? []));
}

// Scans provider/MCP content blocks and returns a short human-readable note
// about any non-text blocks that were dropped (images, files, audio, blobs …),
// or '' when everything was text. Lets us surface silently-discarded binary
// content instead of losing it without a trace. $text_types lists the block
// "type" values that count as benign/text for the given source, so we only flag
// genuinely unsupported content.
function _omitted_content_note(array $blocks, array $text_types): string {
    $dropped = [];
    foreach ($blocks as $b) {
        $t = is_array($b) ? ($b['type'] ?? '') : '';
        if ($t === '' || in_array($t, $text_types, true)) continue;
        $dropped[$t] = ($dropped[$t] ?? 0) + 1;
    }
    if (!$dropped) return '';
    $parts = [];
    foreach ($dropped as $type => $n) {
        $parts[] = $n . ' ' . $type . ($n > 1 ? ' blocks' : ' block');
    }
    return '[' . implode(', ', $parts) . ' omitted — binary/non-text content is not yet supported]';
}

// Chat Completions `content` is normally a plain string, but multimodal replies
// use an array of typed parts. Return [text, note] so callers never trim() an
// array (a TypeError on PHP 8) and any non-text parts are surfaced, not dropped.
function _openai_chat_content($content): array {
    if (is_array($content)) {
        $text = '';
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'text') $text .= $part['text'] ?? '';
        }
        return [trim($text), _omitted_content_note($content, ['text', 'refusal'])];
    }
    return [trim((string)($content ?? '')), ''];
}

// $space, when non-empty, targets a specific space on the remote wiki by
// appending ?space= to its MCP URL (mirrors mcp.php's ?space= convention). The
// remote falls back to its default space if the name isn't a valid space there.
function _mcp_call_tool(array $server, string $name, array $input, string $space = ''): string {
    $url = $server['url'] ?? '';
    if ($space !== '') {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'space=' . rawurlencode($space);
    }
    $res = _mcp_jsonrpc(
        $url,
        _mcp_auth_header($server),
        'tools/call',
        ['name' => $name, 'arguments' => $input ?: (object)[]]
    , 30, _extra_header_lines($server['extra_headers'] ?? []));
    if (isset($res['error'])) return 'Error: MCP call failed — ' . $res['error'];
    $result = $res['result'];
    if (!empty($result['isError'])) {
        return 'Error: ' . (implode("\n", array_column($result['content'] ?? [], 'text')) ?: 'MCP tool returned an error.');
    }
    $parts = [];
    foreach ($result['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $parts[] = $block['text'] ?? '';
    }
    $out  = implode("\n", $parts);
    $note = _omitted_content_note($result['content'] ?? [], ['text']);
    if ($note) $out = trim($out . "\n" . $note);
    return $out ?: 'Done.';
}

// Sends a minimal completion request to verify a provider/api_url/api_key/model
// combination actually works together, without the full agentic tool loop — used
// by the "Test Connection" button on the AI User admin form.
// Newer OpenAI models (o-series, gpt-5) require "max_completion_tokens" on the
// Chat Completions API and reject "max_tokens"; older models and many
// OpenAI-compatible providers only accept "max_tokens". Default by endpoint,
// then swap-and-retry once if the API says otherwise (see _is_token_param_error).
function _openai_token_param(string $api_url): string {
    return stripos($api_url, 'api.openai.com') !== false ? 'max_completion_tokens' : 'max_tokens';
}

// True when an API error indicates the wrong token-limit parameter was sent.
function _is_token_param_error(string $msg): bool {
    if (stripos($msg, 'max_completion_tokens') !== false) return true;
    return stripos($msg, 'max_tokens') !== false
        && (stripos($msg, 'unsupported') !== false || stripos($msg, 'not supported') !== false
            || stripos($msg, 'instead') !== false || stripos($msg, 'use ') !== false);
}

// True when an API error indicates a custom temperature is not allowed (reasoning
// models accept only the default temperature).
function _is_temperature_error(string $msg): bool {
    return stripos($msg, 'temperature') !== false
        && (stripos($msg, 'unsupported') !== false || stripos($msg, 'not support') !== false
            || stripos($msg, 'does not support') !== false || stripos($msg, 'only the default') !== false
            || stripos($msg, 'only supports') !== false);
}

// --- OpenAI Responses API (/v1/responses) helpers ------------------------------
// The Responses API differs from Chat Completions: tools are flat (no nested
// "function" key), the prompt goes in "input" (+ "instructions" for the system
// prompt), the limit is "max_output_tokens", and the reply is an "output" array
// of typed items (message / function_call / reasoning …).

function _openai_responses_tools(array $tools_def): array {
    return array_map(fn($t) => [
        'type'        => 'function',
        'name'        => $t['name'],
        'description' => $t['description'],
        'parameters'  => $t['params'],
    ], $tools_def);
}

// Normalise a Responses result: ['text', 'tool_calls' => [['call_id','name','args'],…],
// 'output' => raw items to echo back on the next turn, 'truncated' => bool].
function _openai_responses_parse(array $data): array {
    $text = '';
    $calls = [];
    $msg_blocks = [];
    foreach ($data['output'] ?? [] as $item) {
        $type = $item['type'] ?? '';
        if ($type === 'message') {
            foreach ($item['content'] ?? [] as $blk) {
                $msg_blocks[] = $blk;
                if (($blk['type'] ?? '') === 'output_text') $text .= $blk['text'] ?? '';
            }
        } elseif ($type === 'function_call') {
            $calls[] = [
                'call_id' => $item['call_id'] ?? ($item['id'] ?? ''),
                'name'    => $item['name'] ?? '',
                'args'    => json_decode($item['arguments'] ?? '{}', true) ?: [],
            ];
        }
    }
    $truncated = ($data['status'] ?? '') === 'incomplete'
        && (($data['incomplete_details']['reason'] ?? '') === 'max_output_tokens');
    return ['text' => trim($text), 'tool_calls' => $calls, 'output' => $data['output'] ?? [], 'truncated' => $truncated,
            'omitted' => _omitted_content_note($msg_blocks, ['output_text', 'refusal'])];
}

function _test_openai_responses(string $api_url, string $api_key, string $model, array $extra_headers = []): array {
    $payload = ['model' => $model, 'input' => 'Reply with the single word: OK', 'max_output_tokens' => 64];
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Authorization: Bearer ' . $api_key], $extra_headers),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch); $curl_err = curl_error($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$raw) return ['ok' => false, 'error' => $curl_err ?: 'No response (connection failed or timed out).'];
    $data = json_decode($raw, true);
    if (!$data) return ['ok' => false, 'error' => "HTTP {$http}: unreadable response."];
    if (isset($data['error'])) return ['ok' => false, 'error' => $data['error']['message'] ?? 'API returned an error.'];
    $parsed = _openai_responses_parse($data);
    return ['ok' => true, 'reply' => $parsed['text'] !== '' ? $parsed['text'] : '(empty reply)'];
}

function _test_ai_connection(string $provider, string $api_url, string $api_key, string $model, array $extra_headers = []): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl is not available on this server.'];
    $family = llm_family($provider);
    if ($family === 'openai-responses') return _test_openai_responses($api_url, $api_key, $model, $extra_headers);

    if ($family === 'anthropic') {
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'];
    } else {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
    }
    $headers = array_merge($headers, $extra_headers);
    // Anthropic always uses max_tokens; OpenAI/compatible pick by endpoint and
    // swap on the "use max_completion_tokens instead" error.
    $token_param = $family === 'anthropic' ? 'max_tokens' : _openai_token_param($api_url);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $payload = [
            'model'      => $model,
            $token_param => 16,
            'messages'   => [['role' => 'user', 'content' => 'Reply with the single word: OK']],
        ];
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw      = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw) return ['ok' => false, 'error' => $curl_err ?: 'No response (connection failed or timed out).'];
        $data = json_decode($raw, true);
        if (!$data) return ['ok' => false, 'error' => "HTTP {$http}: unreadable response."];
        if (isset($data['error'])) {
            $emsg = $data['error']['message'] ?? 'API returned an error.';
            if ($family !== 'anthropic' && $attempt < 2 && _is_token_param_error($emsg)) {
                $token_param = $token_param === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                continue;
            }
            return ['ok' => false, 'error' => $emsg];
        }
        if (($data['type'] ?? '') === 'error') return ['ok' => false, 'error' => $data['error']['message'] ?? 'API returned an error.'];
        // Some OpenAI-compatible providers (e.g. Mistral) use {"detail": "..."} instead.
        if ($http >= 400 && isset($data['detail'])) {
            return ['ok' => false, 'error' => is_string($data['detail']) ? $data['detail'] : json_encode($data['detail'])];
        }

        if ($family === 'anthropic') {
            $text_blocks = array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text');
            $text = trim(implode(' ', array_column(array_values($text_blocks), 'text')));
        } else {
            $text = trim($data['choices'][0]['message']['content'] ?? '');
        }
        if ($text === '' && $http >= 400) return ['ok' => false, 'error' => "HTTP {$http}: empty response."];
        return ['ok' => true, 'reply' => $text !== '' ? $text : '(empty reply)'];
    }
    return ['ok' => false, 'error' => 'Could not find a supported token-limit parameter for this model.'];
}

/**
 * Run a single agent job.
 *
 * @param array       $job      The job definition (id, name, prompt, space, …)
 * @param array       $ai_user  The AI user record (ai_config, name, role, uid, …)
 * @param PageIndexer $indexer  PageIndexer for the space
 * @param string      $space_dir Absolute path to the space directory
 * @return array ['reply' => string|null, 'error' => string|null]
 */
function run_agent_job(array $job, array $ai_user, PageIndexer $indexer, string $space_dir): array {
    // --- Extract LLM config ---
    $config        = $ai_user['ai_config']   ?? [];
    $provider      = $config['provider']      ?? 'openai';
    $family        = llm_family($provider);
    $api_url       = $config['api_url']       ?? '';
    if ($api_url === '') $api_url = llm_default_url($provider);
    $api_key       = $config['api_key']       ?? '';
    $model         = $config['model']         ?? 'gpt-4o';
    $sys_prompt    = $config['system_prompt'] ?? 'You are a helpful assistant.';
    $max_tokens    = (int)($config['max_tokens']   ?? 4096);
    $temperature   = (float)($config['temperature'] ?? 0.7);
    $extra_lines   = _extra_header_lines($config['extra_headers'] ?? []);

    if (!$api_key)              return ['reply' => null, 'error' => 'AI user has no api_key configured.'];
    if (!$api_url)              return ['reply' => null, 'error' => 'AI user has no api_url configured.'];
    if (!function_exists('curl_init')) return ['reply' => null, 'error' => 'curl is not available on this server.'];

    $space_name = basename($space_dir);

    // --- Build system prompt with wiki context ---
    $full_system = "You are an AI agent operating in the \"{$space_name}\" wiki space. "
        . "Use wiki_list_pages to discover pages, wiki_read_page to read content, "
        . "and wiki_write_page to create or update .md pages. "
        . "When calling wiki_write_page you MUST include the complete markdown content in the \"content\" field. "
        . "Proceed with tasks directly using tools — do not describe what you are about to do before doing it.\n\n"
        . $sys_prompt;

    // --- Tool executor closure ---
    $mcp_tool_map  = []; // populated below after $tools_def is built
    $mcp_calls_log = [];
    $exec_tool = function(string $tool_name, array $tool_input) use ($ai_user, $indexer, $space_dir, &$mcp_tool_map, &$mcp_calls_log): string {
        if (isset($mcp_tool_map[$tool_name])) {
            $mcp_calls_log[] = ($mcp_tool_map[$tool_name]['server']['name'] ?? '?') . ':' . $mcp_tool_map[$tool_name]['real_name'];
            return _mcp_call_tool($mcp_tool_map[$tool_name]['server'], $mcp_tool_map[$tool_name]['real_name'], $tool_input);
        }
        switch ($tool_name) {
            case 'wiki_list_pages':
                $pages = $indexer->getAllPages();
                $paths = array_values(array_filter(array_column($pages, 'path')));
                sort($paths);
                return json_encode($paths);

            case 'wiki_read_page':
                $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
                if (!$rel) return 'Error: path is required.';
                $ext = pathinfo($rel, PATHINFO_EXTENSION);
                if (!in_array($ext, ['md', 'list', 'chat'], true)) return 'Error: only .md, .list and .chat files can be read.';
                $abs = rtrim($space_dir, '/') . '/' . $rel;
                if (!file_exists($abs) || !is_file($abs)) return 'Error: page not found.';
                return file_get_contents($abs);

            case 'wiki_write_page':
                if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot write pages.';
                $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
                if (!$rel) return 'Error: path is required.';
                if (pathinfo($rel, PATHINFO_EXTENSION) !== 'md') return 'Error: only .md files can be written.';
                if (!isset($tool_input['content']) || $tool_input['content'] === '') {
                    return 'Error: content parameter is required and must not be empty. Call wiki_write_page again and include the full markdown content in the "content" field.';
                }
                $content = $tool_input['content'];
                $abs     = rtrim($space_dir, '/') . '/' . $rel;
                $dir     = dirname($abs);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $is_new  = !file_exists($abs);
                if (file_put_contents($abs, $content) === false) return 'Error: could not write file.';
                $ai_git_name  = $ai_user['name'] ?? 'AI';
                $ai_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
                if ($is_new) {
                    $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                    _ai_git_commit($abs, $ai_git_name, $ai_git_email, 'Create ' . basename($rel), $space_dir);
                    return "Page created: {$rel}";
                }
                $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                _ai_git_commit($abs, $ai_git_name, $ai_git_email, 'Update ' . basename($rel), $space_dir);
                return "Page updated: {$rel}";

            default:
                return 'Error: unknown tool.';
        }
    };

    // --- Tools array (provider-specific format) ---
    $tools_def = [
        [
            'name'        => 'wiki_list_pages',
            'description' => 'List all pages in the current wiki space. Returns a JSON array of relative file paths.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'wiki_read_page',
            'description' => 'Read the full content of a wiki page by its relative path.',
            'params'      => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the page, e.g. Notes/Meeting.md']],
                'required'   => ['path'],
            ],
        ],
        [
            'name'        => 'wiki_write_page',
            'description' => 'Create a new wiki page or overwrite an existing one with markdown content. Path must end in .md. Both "path" and "content" are required — you MUST supply the complete markdown text in "content"; omitting it or passing an empty string is an error. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'    => ['type' => 'string', 'description' => 'Relative path ending in .md, e.g. Notes/Summary.md'],
                    'content' => ['type' => 'string', 'description' => 'REQUIRED: the complete markdown content of the page. Must not be omitted or empty.'],
                ],
                'required'   => ['path', 'content'],
            ],
        ],
    ];

    $mcp_server_ids    = $config['mcp_server_ids']   ?? [];
    $mcp_instructions  = $config['mcp_instructions'] ?? [];
    // Explicit "src:<slug>" in the prompt forces this run to use ONLY that MCP
    // server's tools (no built-ins, no other enabled servers) — a deterministic
    // alternative to the free-text per-server instructions below.
    $forced_slugs = _mcp_extract_forced_slugs($job['prompt'] ?? '');
    $add_mcp_server_tools = function(array $mcp_srv) use (&$mcp_tool_map, &$tools_def) {
        foreach (_mcp_fetch_tools($mcp_srv) as $tool) {
            $alias = _mcp_tool_alias($mcp_srv['name'] ?? 'mcp', $tool['name']);
            if (isset($mcp_tool_map[$alias])) continue;
            $mcp_tool_map[$alias] = ['server' => $mcp_srv, 'real_name' => $tool['name']];
            $tools_def[] = ['name' => $alias, 'description' => $tool['description'], 'params' => $tool['params']];
        }
    };
    if ($mcp_server_ids && defined('WIKI_SYSTEM_DATA')) {
        $mcp_file = WIKI_SYSTEM_DATA . 'mcp_servers.json';
        if (file_exists($mcp_file)) {
            $all_mcp = json_decode(file_get_contents($mcp_file), true) ?? [];
            $enabled = array_values(array_filter($all_mcp, fn($s) => in_array($s['id'] ?? '', $mcp_server_ids, true)));
            $forced  = $forced_slugs ? array_values(array_filter($enabled, fn($s) => in_array(_mcp_slug($s['name'] ?? ''), $forced_slugs, true))) : [];
            if ($forced) {
                $tools_def = []; // explicit source(s) referenced — built-ins and other servers excluded
                foreach ($forced as $mcp_srv) $add_mcp_server_tools($mcp_srv);
                $full_system .= "\n\nThe prompt explicitly requested " . implode(' and ', array_column($forced, 'name'))
                    . " via src: — use only the tools available to you for this run.";
            } else {
                $mcp_guidance = '';
                foreach ($enabled as $mcp_srv) {
                    $add_mcp_server_tools($mcp_srv);
                    $instr = trim($mcp_instructions[$mcp_srv['id'] ?? ''] ?? '');
                    if ($instr) $mcp_guidance .= "\n[" . $mcp_srv['name'] . '] ' . $instr;
                }
                if ($mcp_guidance) $full_system .= "\n\nMCP tool guidance:" . $mcp_guidance;
            }
        }
    }

    if ($family === 'anthropic') {
        $tools = array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $t['params'],
        ], $tools_def);
    } elseif ($family === 'openai-responses') {
        $tools = _openai_responses_tools($tools_def);
    } else {
        $tools = array_map(fn($t) => [
            'type'     => 'function',
            'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['params']],
        ], $tools_def);
    }

    // --- Initial messages ---
    // For openai-responses this is the "input" list; the system prompt goes in
    // "instructions" instead of a system message.
    if ($family === 'anthropic' || $family === 'openai-responses') {
        $messages = [
            ['role' => 'user', 'content' => $job['prompt']],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => $full_system],
            ['role' => 'user',   'content' => $job['prompt']],
        ];
    }

    // --- Agentic loop (max 10 iterations) ---
    $reply        = null;
    $api_error    = null;
    $tools_called = false;
    // OpenAI token-limit param + custom-temperature handling (see helpers above).
    $openai_token_param  = _openai_token_param($api_url);
    $token_param_swapped = false;
    $drop_temperature    = false;

    for ($iter = 0; $iter < 10; $iter++) {
        if ($family === 'anthropic') {
            $payload = [
                'model'       => $model,
                'system'      => $full_system,
                'messages'    => $messages,
                'max_tokens'  => $max_tokens,
                'temperature' => $temperature,
                'tools'       => $tools,
            ];
            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
            ];
        } elseif ($family === 'openai-responses') {
            $payload = [
                'model'             => $model,
                'instructions'      => $full_system,
                'input'             => $messages,
                'max_output_tokens' => $max_tokens,
                'tools'             => $tools,
            ];
            if (!$drop_temperature) $payload['temperature'] = $temperature;
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ];
        } else {
            $payload = [
                'model'             => $model,
                'messages'          => $messages,
                $openai_token_param => $max_tokens,
                'tools'             => $tools,
            ];
            if (!$drop_temperature) $payload['temperature'] = $temperature;
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ];
        }
        if ($extra_lines) $headers = array_merge($headers, $extra_lines);

        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $raw      = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if (!$raw) {
            $api_error = $curl_err ?: 'No response from the API (connection failed or timed out).';
            break;
        }
        $data = json_decode($raw, true);
        if (!$data) {
            $api_error = 'The API returned an unreadable response.';
            break;
        }

        // Detect error responses
        if (isset($data['error'])) {
            $emsg = $data['error']['message'] ?? 'Unknown API error.';
            // Reasoning models (o-series, gpt-5) reject max_tokens / custom
            // temperature — swap the token param or drop temperature and retry once each.
            if ($family !== 'anthropic' && !$token_param_swapped && _is_token_param_error($emsg)) {
                $openai_token_param  = $openai_token_param === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                $token_param_swapped = true;
                continue;
            }
            if ($family !== 'anthropic' && !$drop_temperature && _is_temperature_error($emsg)) {
                $drop_temperature = true;
                continue;
            }
            $api_error = $emsg;
            break;
        }
        if (($data['type'] ?? '') === 'error') {
            $api_error = $data['error']['message'] ?? 'Unknown API error.';
            break;
        }

        if ($family === 'anthropic') {
            if (($data['stop_reason'] ?? '') === 'max_tokens') {
                $api_error = 'Response truncated: the Max Tokens limit (' . $max_tokens . ') was reached before the AI could finish its reply. Increase Max Tokens in the AI user settings (recommend ≥ 4096 for page writing).';
                break;
            }
            $tool_uses = array_values(array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'tool_use'));
            if ($tool_uses) {
                $tools_called = true;
                $assistant_content = $data['content'];
                foreach ($assistant_content as &$_blk) {
                    if (($_blk['type'] ?? '') === 'tool_use' && $_blk['input'] === []) {
                        $_blk['input'] = new stdClass();
                    }
                }
                unset($_blk);
                $messages[] = ['role' => 'assistant', 'content' => $assistant_content];
                $results = [];
                foreach ($tool_uses as $tu) {
                    $results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $tu['id'],
                        'content'     => $exec_tool($tu['name'] ?? '', $tu['input'] ?? []),
                    ];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
                continue;
            }
            $text_blocks = array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text');
            $candidate   = trim(implode("\n", array_column(array_values($text_blocks), 'text')));
            if (!$tools_called && $candidate !== '' && $iter < 3) {
                $messages[] = ['role' => 'assistant', 'content' => $data['content']];
                $messages[] = ['role' => 'user',      'content' => 'Please proceed now using the available wiki tools.'];
                continue;
            }
            $note  = _omitted_content_note($data['content'] ?? [], ['text', 'thinking', 'redacted_thinking', 'tool_use']);
            $reply = $note ? trim($candidate . "\n\n" . $note) : $candidate;
            break;

        } elseif ($family === 'openai-responses') {
            $parsed = _openai_responses_parse($data);
            if ($parsed['tool_calls']) {
                $tools_called = true;
                // Echo the model's output items back, then append each tool result.
                foreach ($parsed['output'] as $it) $messages[] = $it;
                foreach ($parsed['tool_calls'] as $call) {
                    $messages[] = [
                        'type'    => 'function_call_output',
                        'call_id' => $call['call_id'],
                        'output'  => $exec_tool($call['name'], $call['args']),
                    ];
                }
                continue;
            }
            if ($parsed['truncated']) {
                $api_error = 'Response truncated: the Max Tokens limit (' . $max_tokens . ') was reached before the AI could finish its reply. Increase Max Tokens in the AI user settings (recommend ≥ 4096 for page writing).';
                break;
            }
            $reply = $parsed['text'];
            if (!empty($parsed['omitted'])) $reply = trim($reply . "\n\n" . $parsed['omitted']);
            break;

        } else {
            $choice = $data['choices'][0] ?? [];
            if (($choice['finish_reason'] ?? '') === 'tool_calls') {
                $tool_calls = $choice['message']['tool_calls'] ?? [];
                if (!$tool_calls) break;
                $messages[] = $choice['message'];
                foreach ($tool_calls as $tc) {
                    $fn_args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $tc['id'] ?? '',
                        'content'      => $exec_tool($tc['function']['name'] ?? '', $fn_args),
                    ];
                }
                continue;
            }
            [$reply, $note] = _openai_chat_content($choice['message']['content'] ?? '');
            if ($note) $reply = trim($reply . "\n\n" . $note);
            break;
        }
    }

    if ($api_error) {
        return ['reply' => null, 'error' => $api_error];
    }
    if (!$reply) {
        if ($iter >= 10) {
            return ['reply' => null, 'error' => 'Stopped after too many tool calls without producing a response.'];
        }
        return ['reply' => null, 'error' => 'No response was generated.'];
    }

    if ($mcp_calls_log) {
        $unique = array_unique($mcp_calls_log);
        $reply .= "\n\n---\n*MCP tools used: " . implode(', ', $unique) . '*';
    }

    return ['reply' => $reply, 'error' => null];
}
