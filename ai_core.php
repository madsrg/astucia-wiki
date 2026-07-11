<?php
// =================================================================
// AI CORE — Self-contained agent job runner
// Requires: config.php (for PAGES_DIR, WIKI_SYSTEM_DATA), indexer.php
// =================================================================

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

function _mcp_jsonrpc(string $base_url, ?string $auth_header_line, string $method, array $params, int $timeout = 15): array {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/event-stream',
    ];
    if ($auth_header_line) $headers[] = $auth_header_line;
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: (object)[]]);
    $ch = curl_init(rtrim($base_url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
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
    if (isset($data['error'])) return ['error' => $data['error']['message'] ?? 'JSON-RPC error'];
    return ['result' => $data['result'] ?? []];
}

function _mcp_fetch_tools(array $server): array {
    $res = _mcp_jsonrpc($server['url'] ?? '', _mcp_auth_header($server), 'tools/list', []);
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
    , 30);
    if (isset($res['error'])) return 'Error: MCP call failed — ' . $res['error'];
    $result = $res['result'];
    if (!empty($result['isError'])) {
        return 'Error: ' . (implode("\n", array_column($result['content'] ?? [], 'text')) ?: 'MCP tool returned an error.');
    }
    $parts = [];
    foreach ($result['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $parts[] = $block['text'] ?? '';
    }
    return implode("\n", $parts) ?: 'Done.';
}

// Sends a minimal completion request to verify a provider/api_url/api_key/model
// combination actually works together, without the full agentic tool loop — used
// by the "Test Connection" button on the AI User admin form.
function _test_ai_connection(string $provider, string $api_url, string $api_key, string $model): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl is not available on this server.'];

    $payload = [
        'model'      => $model,
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'Reply with the single word: OK']],
    ];
    if ($provider === 'anthropic') {
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'];
    } else {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
    }

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
    if (isset($data['error'])) return ['ok' => false, 'error' => $data['error']['message'] ?? 'API returned an error.'];
    if (($data['type'] ?? '') === 'error') return ['ok' => false, 'error' => $data['error']['message'] ?? 'API returned an error.'];
    // Some OpenAI-compatible providers (e.g. Mistral) use {"detail": "..."} instead.
    if ($http >= 400 && isset($data['detail'])) {
        return ['ok' => false, 'error' => is_string($data['detail']) ? $data['detail'] : json_encode($data['detail'])];
    }

    if ($provider === 'anthropic') {
        $text_blocks = array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text');
        $text = trim(implode(' ', array_column(array_values($text_blocks), 'text')));
    } else {
        $text = trim($data['choices'][0]['message']['content'] ?? '');
    }
    if ($text === '' && $http >= 400) return ['ok' => false, 'error' => "HTTP {$http}: empty response."];
    return ['ok' => true, 'reply' => $text !== '' ? $text : '(empty reply)'];
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
    $api_url       = $config['api_url']       ?? 'https://api.openai.com/v1/chat/completions';
    $api_key       = $config['api_key']       ?? '';
    $model         = $config['model']         ?? 'gpt-4o';
    $sys_prompt    = $config['system_prompt'] ?? 'You are a helpful assistant.';
    $max_tokens    = (int)($config['max_tokens']   ?? 4096);
    $temperature   = (float)($config['temperature'] ?? 0.7);

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

    if ($provider === 'anthropic') {
        $tools = array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $t['params'],
        ], $tools_def);
    } else {
        $tools = array_map(fn($t) => [
            'type'     => 'function',
            'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['params']],
        ], $tools_def);
    }

    // --- Initial messages ---
    if ($provider === 'anthropic') {
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

    for ($iter = 0; $iter < 10; $iter++) {
        if ($provider === 'anthropic') {
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
        } else {
            $payload = [
                'model'       => $model,
                'messages'    => $messages,
                'max_tokens'  => $max_tokens,
                'temperature' => $temperature,
                'tools'       => $tools,
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
            ];
        }

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
            $api_error = $data['error']['message'] ?? 'Unknown API error.';
            break;
        }
        if (($data['type'] ?? '') === 'error') {
            $api_error = $data['error']['message'] ?? 'Unknown API error.';
            break;
        }

        if ($provider === 'anthropic') {
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
            $reply = $candidate;
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
            $reply = trim($choice['message']['content'] ?? '');
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
