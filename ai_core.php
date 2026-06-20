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
    $exec_tool = function(string $tool_name, array $tool_input) use ($ai_user, $indexer, $space_dir): string {
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

    return ['reply' => $reply, 'error' => null];
}
