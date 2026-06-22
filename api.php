<?php
// =================================================================
// PHP WIKI - BACKEND API
// =================================================================

require_once 'config.php';
require_once 'logger.php';
require_once 'mailer.php';
require_once __DIR__ . '/ai_core.php';

session_start();

// --- Service Token auth (AI users: wk_ai_…, API Accounts: wk_sys_…) ---
$ai_auth_user = null;
$_ai_auth_hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$_ai_auth_hdr && function_exists('getallheaders')) {
    $_hdrs = getallheaders();
    $_ai_auth_hdr = $_hdrs['Authorization'] ?? $_hdrs['authorization'] ?? '';
}
if (str_starts_with($_ai_auth_hdr, 'Bearer wk_')
    && defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
    $_ai_token = substr($_ai_auth_hdr, 7);
    foreach ((json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []) as $_aiu) {
        if ((!empty($_aiu['is_ai']) || !empty($_aiu['is_system'])) && ($_aiu['service_token'] ?? '') === $_ai_token) {
            $ai_auth_user = $_aiu;
            break;
        }
    }
}

// Allow unauthenticated visitors as readers when anonymous access is enabled.
$_anonymous_reader = AUTHENTICATION_ENABLED
    && defined('ANONYMOUS_ACCESS_ENABLED') && ANONYMOUS_ACCESS_ENABLED
    && !isset($_SESSION['user']) && !$ai_auth_user;

// If authentication is enabled and neither session, AI token, nor anonymous access is valid, deny.
if (AUTHENTICATION_ENABLED && !$_anonymous_reader && !isset($_SESSION['user']) && !$ai_auth_user) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

require_once 'indexer.php';

// --- API ROUTER ---
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');

    if (!is_dir(PAGES_DIR)) {
        mkdir(PAGES_DIR, 0777, true);
    }

    // Resolve active space directory from the request param.
    // Defaults to PAGES_DIR for space-agnostic actions (admin, list_spaces, etc.).
    $space_dir = PAGES_DIR;
    $_sp = trim($_REQUEST['space'] ?? '');
    if ($_sp !== '') {
        $_sp_safe = basename($_sp);
        $_sp_candidate = PAGES_DIR . '/' . $_sp_safe;
        if (is_dir($_sp_candidate) && $_sp_safe[0] !== '.') {
            // ACL: non-admins can only access spaces they are granted.
            if (AUTHENTICATION_ENABLED) {
                $session_role   = $_SESSION['user']['role']   ?? 'reader';
                $session_spaces = $_SESSION['user']['spaces'] ?? null; // null = all spaces
                if ($session_role !== 'admin' && $session_spaces !== null && !in_array($_sp_safe, $session_spaces, true)) {
                    header('HTTP/1.1 403 Forbidden');
                    echo json_encode(['success' => false, 'message' => 'Access denied to this space.']);
                    exit;
                }
            }
            $space_dir = $_sp_candidate;
        }
    }

    $indexer = new PageIndexer($space_dir);

    // Session timeout check — app-level idle expiry, more reliable than PHP GC.
    if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) {
        $session_timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        $last_activity   = $_SESSION['last_activity'] ?? time();
        if (time() - $last_activity > $session_timeout) {
            session_unset();
            session_destroy();
            echo json_encode(['success' => false, 'session_expired' => true, 'message' => 'Session expired. Please log in again.']);
            exit;
        }
        $_SESSION['last_activity'] = time();
    }

    function sanitize_path($path) {
        global $space_dir;
        $path = str_replace('..', '', $path);
        $path = ltrim($path, '/');
        return $space_dir . '/' . $path;
    }

    // Returns [abs_path, rel_path, PageIndexer] for a destination that may be in another space.
    // Throws Exception if the space doesn't exist or the user lacks access.
    function resolve_target($new_path_raw, $target_space_name = '') {
        global $space_dir;
        if ($target_space_name !== '' && basename($target_space_name) !== basename($space_dir)) {
            $safe = basename($target_space_name);
            if ($safe[0] === '.') throw new Exception('Invalid target space.');
            $target_base = rtrim(PAGES_DIR, '/') . '/' . $safe;
            if (!is_dir($target_base)) throw new Exception('Target space does not exist.');
            if (AUTHENTICATION_ENABLED) {
                $role   = $_SESSION['user']['role']   ?? 'reader';
                $spaces = $_SESSION['user']['spaces'] ?? null;
                if ($role !== 'admin' && $spaces !== null && !in_array($safe, $spaces, true))
                    throw new Exception('Access denied to target space.');
            }
            $clean = ltrim(str_replace('..', '', $new_path_raw), '/');
            return [$target_base . '/' . $clean, $clean, new PageIndexer($target_base)];
        }
        return [sanitize_path($new_path_raw), ltrim(str_replace('..', '', $new_path_raw), '/'), null];
    }

    // --- Git helpers ---
    // Returns ['root' => $dir, 'prefix' => $relpath_prefix] or null if no git repo found.
    // Checks $space_dir first, then PAGES_DIR — stops there (never climbs higher).
    function find_git_root(): ?array {
        global $space_dir;
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

    function git_run(array $args, string $cwd): array {
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

    function git_auto_commit(string $abs_path, string $git_name, string $git_email, string $commit_msg): void {
        global $space_dir;
        $git_root = find_git_root();
        if (!$git_root) return;
        $rel         = ltrim(str_replace(rtrim($space_dir, '/') . '/', '', $abs_path), '/');
        $git_relpath = $git_root['prefix'] . $rel;
        git_run(['add', $git_relpath], $git_root['root']);
        git_run([
            '-c', 'user.name=' . $git_name,
            '-c', 'user.email=' . $git_email,
            'commit', '-m', $commit_msg,
        ], $git_root['root']);
    }

    function git_move_commit(string $old_abs, string $new_abs, string $git_name, string $git_email): void {
        $git_root = find_git_root();
        if (!$git_root) return;
        $root_prefix = rtrim($git_root['root'], '/') . '/';
        // Compute paths relative to git root; only stage paths that live inside it
        $old_rel = strpos($old_abs, $root_prefix) === 0 ? substr($old_abs, strlen($root_prefix)) : null;
        $new_rel = strpos($new_abs, $root_prefix) === 0 ? substr($new_abs, strlen($root_prefix)) : null;
        if ($old_rel === null) return;
        $to_stage = array_values(array_filter([$old_rel, $new_rel]));
        git_run(array_merge(['add'], $to_stage), $git_root['root']);
        $old_name = basename($old_abs);
        $new_name = basename($new_abs);
        $msg = $old_name === $new_name ? "Move $old_name" : "Rename $old_name → $new_name";
        git_run([
            '-c', 'user.name=' . $git_name,
            '-c', 'user.email=' . $git_email,
            'commit', '-m', $msg,
        ], $git_root['root']);
    }

    // --- Role-based access control ---
    function get_current_role() {
        global $ai_auth_user;
        if (!AUTHENTICATION_ENABLED) return 'admin';
        if ($ai_auth_user) return $ai_auth_user['role'] ?? 'editor';
        return $_SESSION['user']['role'] ?? 'reader';
    }

    function get_current_actor() {
        global $ai_auth_user;
        if (!AUTHENTICATION_ENABLED || (!isset($_SESSION['user']) && !$ai_auth_user)) return ['uid' => null, 'name' => null];
        if ($ai_auth_user) return ['uid' => $ai_auth_user['uid'] ?? null, 'name' => $ai_auth_user['name'] ?? null];
        return ['uid' => $_SESSION['user']['uid'] ?? null, 'name' => $_SESSION['user']['name'] ?? null];
    }

    function page_meta($data) {
        return [
            'created'   => $data['created']   ?? null,
            'updated'   => $data['updated']   ?? null,
            'createdBy' => $data['createdBy'] ?? null,
            'updatedBy' => $data['updatedBy'] ?? null,
            'tags'      => $data['tags']      ?? [],
        ];
    }

    function generate_ai_service_token() {
        return 'wk_ai_' . bin2hex(random_bytes(24));
    }

    function generate_api_service_token() {
        return 'wk_sys_' . bin2hex(random_bytes(24));
    }

    function wiki_error_log_dir() {
        if (!defined('LOG_DIR') || !LOG_DIR) return null;
        return rtrim(LOG_DIR, '/') . '/';
    }

    function write_wiki_error($message, $page = '', $actor_name = '', $ip = '') {
        $dir = wiki_error_log_dir();
        if (!$dir) return;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . 'wiki_errors_' . date('Y-m-d') . '.log';
        $line = date('Y-m-d H:i:s') . ' | ' . $page . ' | ' . $actor_name . ' | ' . $ip . ' | ' . str_replace(["\r", "\n"], ' ', $message);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    function get_wiki_tools($provider) {
        $tools_def = [
            [
                'name'        => 'wiki_list_pages',
                'description' => 'List all pages in the current wiki space. Returns a JSON array of objects with "id", "path", and "space" fields.',
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
            return array_map(fn($t) => [
                'name'         => $t['name'],
                'description'  => $t['description'],
                'input_schema' => $t['params'],
            ], $tools_def);
        }
        // OpenAI-compatible
        return array_map(fn($t) => [
            'type'     => 'function',
            'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['params']],
        ], $tools_def);
    }

    function execute_ai_tool($tool_name, $tool_input, $ai_user, $indexer, $space_dir) {
        switch ($tool_name) {
            case 'wiki_list_pages':
                $pages = $indexer->getAllPages();
                $space_name_lp = basename($space_dir);
                $result_lp = [];
                foreach ($pages as $id => $data) {
                    if (empty($data['path'])) continue;
                    $result_lp[] = ['id' => (string)$id, 'path' => $data['path'], 'space' => $space_name_lp];
                }
                usort($result_lp, fn($a, $b) => strcmp($a['path'], $b['path']));
                return json_encode($result_lp);

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
                    git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Create ' . basename($rel));
                    return "Page created: {$rel}";
                }
                $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Update ' . basename($rel));
                return "Page updated: {$rel}";

            default:
                return 'Error: unknown tool.';
        }
    }

    function trigger_ai_mentions($chat_file, $message_text, $chat_data) {
        global $indexer, $space_dir;
        if (!defined('WIKI_SYSTEM_DATA') || !file_exists(WIKI_SYSTEM_DATA . 'users.json')) return;
        $all_users = json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? [];
        foreach ($all_users as $u) {
            if (empty($u['is_ai'])) continue;
            $ai_name = $u['name'] ?? '';
            if (!$ai_name) continue;
            if (!preg_match('/(^|[\s,])[@#]' . preg_quote($ai_name, '/') . '(\b|$)/iu', $message_text)) continue;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            trigger_ai_response($u, $chat_file, $chat_data, $indexer, $space_dir);
            return;
        }
    }

    function trigger_ai_response($ai_user, $chat_file, $chat_data, $indexer, $space_dir, $placeholder_id = null) {
        $config        = $ai_user['ai_config']      ?? [];
        $provider      = $config['provider']         ?? 'openai';
        $api_url       = $config['api_url']          ?? 'https://api.openai.com/v1/chat/completions';
        $api_key       = $config['api_key']          ?? '';
        $model         = $config['model']            ?? 'gpt-4o';
        $system_prompt = $config['system_prompt']    ?? 'You are a helpful assistant.';
        $context_msgs  = (int)($config['context_messages'] ?? 20);
        $max_tokens    = (int)($config['max_tokens']       ?? 4096);
        $temperature   = (float)($config['temperature']    ?? 0.7);
        if (!$api_key || !$api_url || !function_exists('curl_init')) return;

        $ai_uid     = (int)($ai_user['uid'] ?? -2);
        $space_name = basename($space_dir);
        $chat_name  = basename($chat_file, '.chat');

        // Prepend wiki context so the AI knows where it is and what tools are available
        $wiki_ctx = "You are operating in the \"{$space_name}\" wiki space (current chat: \"{$chat_name}\"). "
            . "Use wiki_list_pages to discover available pages, wiki_read_page to read content, "
            . "and wiki_write_page to create or update .md pages. "
            . "When calling wiki_write_page you MUST include the complete markdown content in the \"content\" field in the same tool call — never call it with an empty or missing content field. "
            . "When the user asks you to create or modify wiki content, call the appropriate tool immediately — do not describe what you are about to do before doing it. "
            . "Only invoke tools when the user's request actually requires wiki content. "
            . "When writing internal links to other wiki pages, use the Markdown syntax [Page Title](?pageid=ID&space=SPACE) "
            . "where ID and SPACE come from the wiki_list_pages results. Never use file paths as link targets for internal pages.\n\n";
        // If a .md page with the same name exists in the same folder, inject its content as context
        $linked_md       = dirname($chat_file) . '/' . $chat_name . '.md';
        $pages_dir_real  = realpath(rtrim(PAGES_DIR, '/'));
        if (file_exists($linked_md) && $pages_dir_real !== false && strpos(realpath($linked_md), $pages_dir_real) === 0) {
            $page_content = file_get_contents($linked_md);
            $wiki_ctx .= "The following is the current content of the wiki page \"{$chat_name}\" that this chat is attached to. "
                      . "Use it as context when answering questions:\n\n```markdown\n"
                      . $page_content
                      . "\n```\n\n";
        }

        $full_system = $wiki_ctx . $system_prompt;

        $tools  = get_wiki_tools($provider);
        $recent = array_slice(array_values(array_filter($chat_data['messages'], fn($m) => empty($m['pending']))), -$context_msgs);

        // Build initial message list (provider-specific format)
        if ($provider === 'anthropic') {
            $messages = [];
            foreach ($recent as $msg) {
                $role = ((int)($msg['uid'] ?? -1) === $ai_uid) ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => ($msg['name'] ?? '') . ': ' . ($msg['text'] ?? '')];
            }
            // Anthropic requires strictly alternating roles — merge consecutive same-role messages
            $merged = [];
            foreach ($messages as $m) {
                if ($merged && end($merged)['role'] === $m['role']) {
                    $merged[count($merged) - 1]['content'] .= "\n" . $m['content'];
                } else {
                    $merged[] = $m;
                }
            }
            // First message must be user
            while ($merged && $merged[0]['role'] !== 'user') array_shift($merged);
            $messages = $merged;
        } else {
            $messages = [['role' => 'system', 'content' => $full_system]];
            foreach ($recent as $msg) {
                $role = ((int)($msg['uid'] ?? -1) === $ai_uid) ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => ($msg['name'] ?? '') . ': ' . ($msg['text'] ?? '')];
            }
        }

        // Agentic loop: call API → execute tools → repeat until text reply (max 8 iterations)
        $reply        = null;
        $api_error    = null;
        $tools_called = false; // tracks whether any tool has fired this session
        for ($iter = 0; $iter < 8; $iter++) {
            if ($provider === 'anthropic') {
                $payload = ['model' => $model, 'system' => $full_system, 'messages' => $messages,
                            'max_tokens' => $max_tokens, 'temperature' => $temperature, 'tools' => $tools];
                $headers = ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'];
            } else {
                $payload = ['model' => $model, 'messages' => $messages,
                            'max_tokens' => $max_tokens, 'temperature' => $temperature, 'tools' => $tools];
                $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
            }

            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers, CURLOPT_TIMEOUT => 60,
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

            // Detect error responses before attempting to parse content
            if (isset($data['error'])) {
                // OpenAI-compatible: {"error": {"message": "...", "type": "..."}}
                $api_error = $data['error']['message'] ?? 'Unknown API error.';
                break;
            }
            if (($data['type'] ?? '') === 'error') {
                // Anthropic: {"type": "error", "error": {"type": "...", "message": "..."}}
                $api_error = $data['error']['message'] ?? 'Unknown API error.';
                break;
            }

            if ($provider === 'anthropic') {
                // If the response was truncated by max_tokens, the tool input JSON is incomplete —
                // content will be missing. Report this immediately rather than looping.
                if (($data['stop_reason'] ?? '') === 'max_tokens') {
                    $api_error = 'Response truncated: the Max Tokens limit (' . $max_tokens . ') was reached before the AI could finish its reply. Increase Max Tokens in the AI user settings (recommend ≥ 4096 for page writing).';
                    break;
                }
                // Check for tool calls by content inspection (more reliable than stop_reason alone)
                $tool_uses = array_values(array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'tool_use'));
                if ($tool_uses) {
                    $tools_called = true;
                    // json_decode turns {} into [] in PHP; fix it back so json_encode re-emits {} not []
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
                            'content'     => execute_ai_tool($tu['name'] ?? '', $tu['input'] ?? [], $ai_user, $indexer, $space_dir),
                        ];
                    }
                    $messages[] = ['role' => 'user', 'content' => $results];
                    continue;
                }
                $text_blocks = array_filter($data['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text');
                $candidate   = trim(implode("\n", array_column(array_values($text_blocks), 'text')));
                // If the model gave a planning/acknowledgment response before using any tools,
                // re-prompt it once to actually proceed (handles "I'll do that now!" non-action replies)
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
                            'content'      => execute_ai_tool($tc['function']['name'] ?? '', $fn_args, $ai_user, $indexer, $space_dir),
                        ];
                    }
                    continue;
                }
                $reply = trim($choice['message']['content'] ?? '');
                break;
            }
        }

        // If no reply was produced, post a visible error message so the user is not left waiting
        if (!$reply) {
            if ($api_error) {
                $reply = '⚠️ ' . $api_error;
            } elseif ($iter >= 8) {
                $reply = '⚠️ Stopped after too many tool calls without producing a response.';
            } else {
                $reply = '⚠️ No response was generated.';
            }
        }

        $fresh = json_decode(file_get_contents($chat_file), true);
        if (!$fresh) return;
        if ($placeholder_id !== null) {
            $replaced = false;
            foreach ($fresh['messages'] as &$_m) {
                if ((int)($_m['id'] ?? -1) === $placeholder_id) {
                    $_m['text']      = $reply;
                    $_m['timestamp'] = date('c');
                    unset($_m['pending']);
                    $replaced = true;
                    break;
                }
            }
            unset($_m);
            if (!$replaced) {
                $fresh['messages'][] = ['id' => $fresh['nextMessageId'], 'uid' => (int)($ai_user['uid'] ?? 0), 'name' => $ai_user['name'] ?? 'AI', 'timestamp' => date('c'), 'text' => $reply];
                $fresh['nextMessageId']++;
            }
        } else {
            $fresh['messages'][] = ['id' => $fresh['nextMessageId'], 'uid' => (int)($ai_user['uid'] ?? 0), 'name' => $ai_user['name'] ?? 'AI', 'timestamp' => date('c'), 'text' => $reply];
            $fresh['nextMessageId']++;
        }
        file_put_contents($chat_file, json_encode($fresh, JSON_PRETTY_PRINT));
    }

    $edit_actions  = ['save', 'create_file', 'create_folder', 'create_diagram', 'create_list', 'create_chat',
                      'post_chat_message', 'delete_chat_message', 'update_chat_topic', 'toggle_sticky',
                      'create_filesfolder', 'delete', 'move', 'copy_page', 'upload_attachment',
                      'delete_attachment', 'upload_to_folder', 'delete_folder_file', 'update_tags',
                      'save_diagram_svg', 'create_space', 'rename_space', 'set_git_commit', 'commit_snapshot', 'git_restore'];
    $admin_actions = ['admin_get_users', 'admin_save_users', 'admin_get_user_requests',
                      'admin_approve_request', 'admin_deny_request',
                      'admin_get_logs', 'admin_get_log_content',
                      'admin_get_error_logs', 'admin_get_error_log_content',
                      'admin_send_test_email', 'admin_get_diag_log',
                      'admin_get_ai_users', 'admin_save_ai_user',
                      'admin_delete_ai_user', 'admin_regenerate_ai_token',
                      'admin_get_api_accounts', 'admin_save_api_account',
                      'admin_delete_api_account', 'admin_regenerate_api_token',
                      'admin_get_agent_jobs', 'admin_save_agent_job', 'admin_delete_agent_job', 'admin_run_agent_job',
                      'git_deleted_files', 'git_restore_deleted'];
    $requested_action = $_REQUEST['action'];
    $current_role     = get_current_role();

    if (in_array($requested_action, $edit_actions) && $current_role === 'reader') {
        echo json_encode(['success' => false, 'message' => 'Readers cannot modify content.']);
        exit;
    }
    if (in_array($requested_action, $admin_actions) && $current_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    try {
        switch ($_REQUEST['action']) {
            case 'ping':
                // Lightweight heartbeat — keeps the session alive, returns nothing else.
                echo json_encode(['success' => true]);
                break;

            case 'exists':
                $exists_path = sanitize_path($_GET['file'] ?? '');
                echo json_encode(['success' => true, 'exists' => $exists_path !== '' && file_exists($exists_path) && is_file($exists_path)]);
                break;

            case 'file_mtime':
                $mtime_path = sanitize_path($_GET['file'] ?? '');
                echo json_encode(['success' => true, 'mtime' => ($mtime_path !== '' && file_exists($mtime_path)) ? (int)filemtime($mtime_path) : 0]);
                break;

            case 'api_schema':
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
                echo json_encode([
                    'success' => true,
                    'version' => '1.0',
                    'base_url' => $base_url,
                    'auth' => [
                        'header' => 'Authorization: Bearer <token>',
                        'tokens' => [
                            'system_user' => 'wk_sys_<48hex> — headless integration account',
                            'ai_user'     => 'wk_ai_<48hex>  — AI bot account',
                        ],
                        'note' => 'Tokens are generated in the admin panel. Role (admin/editor/reader) controls write access.',
                    ],
                    'space' => [
                        'param' => 'space',
                        'note'  => 'Add ?space=SpaceName (or POST field space=) to target a specific space. Omit for the default space.',
                    ],
                    'conventions' => [
                        'response_envelope' => '{"success":true, ...} or {"success":false,"message":"reason"}',
                        'paths'             => 'Always relative to the space root, e.g. "Folder/Page.md"',
                        'extensions'        => '.md = Markdown page, .chat = chat thread, .list = structured list, .drawio = diagram',
                    ],
                    'actions' => [
                        [
                            'action'      => 'ping',
                            'method'      => 'GET',
                            'description' => 'Health check / session keepalive.',
                            'params'      => [],
                            'response'    => ['success' => true],
                        ],
                        [
                            'action'      => 'list',
                            'method'      => 'GET',
                            'description' => 'List all files and folders in the space as a recursive tree.',
                            'params'      => [],
                            'response'    => ['success' => true, 'data' => [['name' => 'string', 'path' => 'string', 'type' => 'file|folder', 'id' => 'int|null', 'children' => '...']]],
                        ],
                        [
                            'action'      => 'get',
                            'method'      => 'GET',
                            'description' => 'Read the raw content of any file.',
                            'params'      => [['name' => 'file', 'in' => 'query', 'required' => true, 'description' => 'Relative path, e.g. Notes/Meeting.md']],
                            'response'    => ['success' => true, 'data' => 'string (file content)'],
                        ],
                        [
                            'action'      => 'save',
                            'method'      => 'POST',
                            'description' => 'Overwrite an existing file with new content.',
                            'params'      => [
                                ['name' => 'file',  'in' => 'query', 'required' => true,  'description' => 'Relative path of the file to update'],
                                ['name' => '(body)', 'in' => 'body',  'required' => true,  'description' => 'Raw file content as the POST body (not form-encoded)'],
                            ],
                            'response' => ['success' => true, 'message' => 'File saved successfully.'],
                        ],
                        [
                            'action'      => 'create_file',
                            'method'      => 'POST',
                            'description' => 'Create a new Markdown page, optionally from a template.',
                            'params'      => [
                                ['name' => 'path',     'in' => 'post', 'required' => true,  'description' => 'Relative path including filename, e.g. Reports/Weekly.md'],
                                ['name' => 'template', 'in' => 'post', 'required' => false, 'description' => 'Template name (without .md) from the space templates/ folder'],
                            ],
                            'response' => ['success' => true, 'message' => 'File created.'],
                        ],
                        [
                            'action'      => 'create_folder',
                            'method'      => 'POST',
                            'description' => 'Create a new folder.',
                            'params'      => [
                                ['name' => 'path', 'in' => 'post', 'required' => true, 'description' => 'Relative folder path to create'],
                            ],
                            'response' => ['success' => true, 'message' => 'Folder created.'],
                        ],
                        [
                            'action'      => 'search',
                            'method'      => 'GET',
                            'description' => 'Full-text search across all Markdown pages in the space.',
                            'params'      => [['name' => 'query', 'in' => 'query', 'required' => true, 'description' => 'Search string']],
                            'response'    => ['success' => true, 'data' => [['path' => 'string', 'title' => 'string', 'preview' => 'string', 'score' => 'int']]],
                        ],
                        [
                            'action'      => 'get_pages_by_tag',
                            'method'      => 'GET',
                            'description' => 'Find all pages with a specific tag.',
                            'params'      => [['name' => 'tag', 'in' => 'query', 'required' => true, 'description' => 'Exact tag name']],
                            'response'    => ['success' => true, 'data' => [['id' => 'int', 'path' => 'string']]],
                        ],
                        [
                            'action'      => 'update_tags',
                            'method'      => 'POST',
                            'description' => 'Replace the tag list on a page.',
                            'params'      => [
                                ['name' => 'file', 'in' => 'post', 'required' => true, 'description' => 'Relative path of the page'],
                                ['name' => 'tags', 'in' => 'post', 'required' => true, 'description' => 'Comma-separated tag string, e.g. "report,weekly,q1"'],
                            ],
                            'response' => ['success' => true],
                        ],
                        [
                            'action'      => 'get_path_from_id',
                            'method'      => 'GET',
                            'description' => 'Resolve a stable numeric page ID to its current file path.',
                            'params'      => [['name' => 'id', 'in' => 'query', 'required' => true, 'description' => 'Numeric page ID']],
                            'response'    => ['success' => true, 'path' => 'string'],
                        ],
                        [
                            'action'      => 'chat_messages',
                            'method'      => 'GET',
                            'description' => 'Read messages from a chat file. Supports pagination.',
                            'params'      => [
                                ['name' => 'file',      'in' => 'query', 'required' => true,  'description' => 'Relative path to the .chat file'],
                                ['name' => 'before_id', 'in' => 'query', 'required' => false, 'description' => 'Return messages older than this ID (for pagination)'],
                                ['name' => 'since_id',  'in' => 'query', 'required' => false, 'description' => 'Return only messages newer than this ID (for polling)'],
                                ['name' => 'limit',     'in' => 'query', 'required' => false, 'description' => 'Max messages to return (default 50, max 200)'],
                            ],
                            'response' => ['success' => true, 'messages' => [['id' => 'int', 'name' => 'string', 'text' => 'string', 'timestamp' => 'ISO8601']], 'total' => 'int', 'has_more' => 'bool'],
                        ],
                        [
                            'action'      => 'post_chat_message',
                            'method'      => 'POST',
                            'description' => 'Post a message to a chat thread. Mention an AI user with @Name to trigger a response.',
                            'params'      => [
                                ['name' => 'file', 'in' => 'post', 'required' => true, 'description' => 'Relative path to the .chat file'],
                                ['name' => 'text', 'in' => 'post', 'required' => true, 'description' => 'Message text (Markdown supported, @Name to mention users)'],
                            ],
                            'response' => ['success' => true, 'data' => '(full updated chat data)'],
                        ],
                        [
                            'action'      => 'create_chat',
                            'method'      => 'POST',
                            'description' => 'Create a new chat thread.',
                            'params'      => [
                                ['name' => 'path',       'in' => 'post', 'required' => true,  'description' => 'Relative path including filename, e.g. Teams/General.chat'],
                                ['name' => 'topic',      'in' => 'post', 'required' => false, 'description' => 'Optional topic banner text'],
                                ['name' => 'git_commit', 'in' => 'post', 'required' => false, 'description' => '"1" to enable automatic git snapshots'],
                            ],
                            'response' => ['success' => true, 'message' => 'Chat created.'],
                        ],
                        [
                            'action'      => 'list_md_templates',
                            'method'      => 'GET',
                            'description' => 'List available Markdown page templates in the space.',
                            'params'      => [],
                            'response'    => ['success' => true, 'templates' => ['array of template names (without .md)']],
                        ],
                    ],
                ], JSON_PRETTY_PRINT);
                break;

            case 'api_agent_instructions':
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
                $token    = trim($_GET['token'] ?? 'YOUR_TOKEN_HERE');
                $instructions =
"You are an AI agent with access to the Astucia Wiki API. Use it to read and write wiki content.\n" .
"\n" .
"## Connection\n" .
"\n" .
"API endpoint : {$base_url}\n" .
"Auth header  : Authorization: Bearer {$token}\n" .
"\n" .
"To target a specific space add the parameter:\n" .
"  GET  : ?space=SpaceName\n" .
"  POST : space=SpaceName  (in the form-encoded body)\n" .
"\n" .
"## Response format\n" .
"\n" .
"All responses are JSON: {\"success\":true,...} or {\"success\":false,\"message\":\"reason\"}\n" .
"File paths are always relative to the space root, e.g. \"Folder/Page.md\"\n" .
"File types: .md (Markdown page), .chat (chat thread), .list (structured list)\n" .
"\n" .
"## Actions\n" .
"\n" .
"### List all files\n" .
"GET {$base_url}?action=list\n" .
"Returns a recursive tree of all files and folders in the space.\n" .
"\n" .
"### Read a file\n" .
"GET {$base_url}?action=get&file=Folder/Page.md\n" .
"Returns: {\"success\":true,\"data\":\"<raw file content>\"}\n" .
"\n" .
"### Save (overwrite) an existing file\n" .
"POST {$base_url}?action=save&file=Folder/Page.md\n" .
"Body: raw file content sent as plain text (not form-encoded)\n" .
"Returns: {\"success\":true,\"message\":\"File saved successfully.\"}\n" .
"\n" .
"### Create a new page\n" .
"POST {$base_url}?action=create_file\n" .
"Body (form-encoded): path=Folder/NewPage.md&template=meeting-notes\n" .
"(template is optional — omit for a blank page; call ?action=list_md_templates for available templates)\n" .
"Returns: {\"success\":true,\"message\":\"File created.\"}\n" .
"\n" .
"### Create a folder\n" .
"POST {$base_url}?action=create_folder\n" .
"Body (form-encoded): path=Folder/SubFolder\n" .
"Returns: {\"success\":true,\"message\":\"Folder created.\"}\n" .
"\n" .
"### Search pages\n" .
"GET {$base_url}?action=search&query=keyword\n" .
"Returns: {\"success\":true,\"data\":[{\"path\":\"...\",\"title\":\"...\",\"preview\":\"...\"},...] }\n" .
"\n" .
"### Get pages by tag\n" .
"GET {$base_url}?action=get_pages_by_tag&tag=tagname\n" .
"Returns: {\"success\":true,\"data\":[{\"id\":1,\"path\":\"Folder/Page.md\"},...] }\n" .
"\n" .
"### Update page tags\n" .
"POST {$base_url}?action=update_tags\n" .
"Body (form-encoded): file=Folder/Page.md&tags=tag1,tag2,tag3\n" .
"Returns: {\"success\":true}\n" .
"\n" .
"### Read a chat thread\n" .
"GET {$base_url}?action=chat_messages&file=Folder/Thread.chat\n" .
"Optional: &before_id=N (older pages), &since_id=N (new only), &limit=50\n" .
"Returns: {\"success\":true,\"messages\":[{\"id\":1,\"name\":\"Alice\",\"text\":\"...\",\"timestamp\":\"ISO8601\"},...],\"total\":N,\"has_more\":false}\n" .
"\n" .
"### Post a message to a chat thread\n" .
"POST {$base_url}?action=post_chat_message\n" .
"Body (form-encoded): file=Folder/Thread.chat&text=Your message here\n" .
"Returns: {\"success\":true,\"data\":{...full updated chat...}}\n" .
"Tip: mention an AI user with @Name in the text to trigger their response.\n" .
"\n" .
"### List available page templates\n" .
"GET {$base_url}?action=list_md_templates\n" .
"Returns: {\"success\":true,\"templates\":[\"meeting-notes\",\"project-overview\",...]}\n" .
"\n" .
"### Full API schema reference\n" .
"GET {$base_url}?action=api_schema\n" .
"Returns a complete machine-readable description of all available actions.\n" .
"\n" .
"## Guidelines\n" .
"\n" .
"- Always check {\"success\":true} before using response data.\n" .
"- Read a file before overwriting it to avoid data loss.\n" .
"- Search before creating pages to avoid duplicates.\n" .
"- File paths are case-sensitive.\n" .
"- When writing Markdown, preserve the existing heading structure of the page.\n" .
"- If an action fails, read the \"message\" field in the response for the reason.";
                echo json_encode(['success' => true, 'instructions' => $instructions]);
                break;

            case 'get_start_page':
                $start_rel  = 'Main.md';
                $start_file = sanitize_path($start_rel);
                if (!file_exists($start_file)) {
                    $welcome = "# Welcome to " . APP_TITLE . "\n\n"
                             . "This is your wiki's start page.\n\n"
                             . "- Use the **New ...** button in the sidebar to create pages, folders, diagrams and lists\n"
                             . "- Click any page in the left sidebar to open it\n"
                             . "- Use the pencil icon (top right) to edit a Markdown page\n"
                             . "- Embed diagrams and lists in any page using the toolbar insert options\n\n"
                             . "## Help & Documentation\n\n"
                             . "Full documentation is available at [astucia.wiki](https://astucia.wiki).\n";
                    file_put_contents($start_file, $welcome);
                    $start_id = $indexer->addPage($start_rel);
                } else {
                    $start_id = $indexer->getId($start_rel);
                    if (!$start_id) $start_id = $indexer->addPage($start_rel);
                }
                echo json_encode(['success' => true, 'path' => $start_rel, 'id' => $start_id]);
                break;

            case 'list':
                function get_dir_contents($dir, $indexer, $base_dir) {
                    $items = [];
                    $files = scandir($dir);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..' || substr($file, -8) === '.uploads') continue;
                        if ($file[0] === '.') continue; // skip hidden files/dirs (.git, .gitignore, etc.)

                        $path = $dir . '/' . $file;
                        $is_dir = is_dir($path);
                        $extension = pathinfo($path, PATHINFO_EXTENSION);

                        if (!$is_dir && !in_array($extension, ['md', 'drawio', 'list', 'chat'])) {
                            continue;
                        }

                        $relativePath = str_replace($base_dir . '/', '', $path);

                        if ($is_dir && file_exists($path . '/.filesfolder')) {
                            $items[] = ['name' => $file, 'path' => $relativePath, 'type' => 'filesfolder', 'id' => null, 'tags' => []];
                            continue;
                        }

                        $id = $is_dir ? null : $indexer->getId($relativePath);
                        if (!$is_dir && $id === null) {
                            $id = $indexer->addPage($relativePath);
                        }

                        $item = [
                            'name' => $file,
                            'path' => $relativePath,
                            'type' => $is_dir ? 'folder' : 'file',
                            'id'   => $id,
                            'tags' => $is_dir ? [] : $indexer->getTags($id)
                        ];

                        if ($is_dir) {
                            $item['children'] = get_dir_contents($path, $indexer, $base_dir);
                        }
                        $items[] = $item;
                    }
                    usort($items, function($a, $b) {
                        $aFolder = in_array($a['type'], ['folder', 'filesfolder']);
                        $bFolder = in_array($b['type'], ['folder', 'filesfolder']);
                        if ($aFolder && !$bFolder) return -1;
                        if (!$aFolder && $bFolder) return 1;
                        return strcmp($a['name'], $b['name']);
                    });
                    return $items;
                }
                echo json_encode(['success' => true, 'data' => get_dir_contents($space_dir, $indexer, $space_dir)]);
                break;

            case 'get':
                $file_path = sanitize_path($_GET['file']);
                $rel_get   = ltrim(str_replace('..', '', $_GET['file']), '/');
                $ext_get   = pathinfo($rel_get, PATHINFO_EXTENSION);
                if (file_exists($file_path) && is_file($file_path)) {
                    $content      = file_get_contents($file_path);
                    $last_updated = filemtime($file_path);
                    if (in_array($ext_get, ['md', 'drawio'], true)) {
                        $git_commit = $indexer->getGitCommit($rel_get, true);
                    } elseif (in_array($ext_get, ['chat', 'list'], true)) {
                        $decoded    = json_decode($content, true);
                        $git_commit = isset($decoded['git_commit']) ? (bool)$decoded['git_commit'] : false;
                    } else {
                        $git_commit = false;
                    }
                    echo json_encode(['success' => true, 'data' => $content, 'lastUpdated' => $last_updated, 'git_commit' => $git_commit]);
                } else {
                    echo json_encode(['success' => true, 'data' => '', 'lastUpdated' => time(), 'git_commit' => true]);
                }
                break;

            case 'chat_messages':
                $cm_path = sanitize_path($_GET['file']);
                if (!file_exists($cm_path) || !is_file($cm_path)) throw new Exception('Chat file not found.');
                $cm_data  = json_decode(file_get_contents($cm_path), true);
                if ($cm_data === null) throw new Exception('Invalid chat file.');
                $cm_all   = array_values($cm_data['messages'] ?? []);
                $cm_total = count($cm_all);
                $cm_limit = min(max((int)($_GET['limit'] ?? 50), 1), 200);
                $cm_mtime = filemtime($cm_path);

                if (isset($_GET['since_id'])) {
                    $cm_since = (int)$_GET['since_id'];
                    $cm_new   = array_values(array_filter($cm_all, fn($m) => ($m['id'] ?? 0) > $cm_since));
                    echo json_encode(['success' => true, 'messages' => $cm_new, 'total' => $cm_total, 'mtime' => $cm_mtime]);
                    break;
                }
                if (isset($_GET['before_id'])) {
                    $cm_before = (int)$_GET['before_id'];
                    $cm_older  = array_values(array_filter($cm_all, fn($m) => ($m['id'] ?? 0) < $cm_before));
                    $cm_slice  = array_slice($cm_older, -$cm_limit);
                    echo json_encode(['success' => true, 'messages' => $cm_slice, 'total' => $cm_total,
                                      'has_more' => count($cm_older) > $cm_limit]);
                    break;
                }
                // Initial load: last $cm_limit messages
                $cm_slice = array_slice($cm_all, -$cm_limit);
                echo json_encode([
                    'success'       => true,
                    'messages'      => $cm_slice,
                    'total'         => $cm_total,
                    'has_more'      => $cm_total > $cm_limit,
                    'topic'         => $cm_data['topic'] ?? '',
                    'git_commit'    => isset($cm_data['git_commit']) ? (bool)$cm_data['git_commit'] : false,
                    'nextMessageId' => $cm_data['nextMessageId'] ?? 1,
                    'mtime'         => $cm_mtime,
                ]);
                break;

            case 'create_diagram':
                $file_path_raw = $_POST['path'];
                $file_path_sanitized = sanitize_path($file_path_raw);
                $template_path = $space_dir . '/templates/default.drawio';

                if (!file_exists($template_path)) {
                    throw new Exception('Default diagram template not found.');
                }

                if (!file_exists($file_path_sanitized)) {
                    if (copy($template_path, $file_path_sanitized)) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'Diagram created.']);
                        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                        $git_name  = $actor['name'] ?? 'Wiki';
                        $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                        git_auto_commit($file_path_sanitized, $git_name, $git_email, 'Create ' . basename($file_path_raw));
                    } else {
                        throw new Exception('Could not create diagram file from template.');
                    }
                } else {
                    throw new Exception('A file with that name already exists.');
                }
                break;

            case 'create_list':
                $file_path_raw = $_POST['path'];
                $file_path_sanitized = sanitize_path($file_path_raw);
                $default_list_structure = [
                    "git_commit" => false,
                    "columns" => [
                        ["id" => "col_1", "name" => "ItemId", "type" => "autoincrement", "showInListView" => true],
                        ["id" => "col_2", "name" => "Title", "type" => "text_single", "showInListView" => true]
                    ],
                    "items" => [],
                    "nextItemId" => 1
                ];

                if (!file_exists($file_path_sanitized)) {
                    if (file_put_contents($file_path_sanitized, json_encode($default_list_structure, JSON_PRETTY_PRINT)) !== false) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'List created.']);
                    } else {
                        throw new Exception('Could not create list file.');
                    }
                } else {
                    throw new Exception('A file with that name already exists.');
                }
                break;

            case 'create_chat':
                $file_path_raw = $_POST['path'];
                $file_path_sanitized = sanitize_path($file_path_raw);
                $chat_topic_init  = trim($_POST['topic'] ?? '');
                $chat_git_commit  = isset($_POST['git_commit']) && $_POST['git_commit'] === '1';
                $default_chat = ['topic' => $chat_topic_init, 'git_commit' => $chat_git_commit, 'messages' => [], 'nextMessageId' => 1];
                if (!file_exists($file_path_sanitized)) {
                    if (file_put_contents($file_path_sanitized, json_encode($default_chat, JSON_PRETTY_PRINT)) !== false) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'Chat created.']);
                    } else {
                        throw new Exception('Could not create chat file.');
                    }
                } else {
                    throw new Exception('A file with that name already exists.');
                }
                break;

            case 'post_chat_message':
                $file_path = sanitize_path($_POST['file']);
                $text = trim($_POST['text'] ?? '');
                if (!$text) throw new Exception('Message cannot be empty.');
                if (mb_strlen($text) > 2000) throw new Exception('Message too long (max 2000 characters).');
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $actor = get_current_actor();
                $chat_data['messages'][] = [
                    'id'        => $chat_data['nextMessageId'],
                    'uid'       => AUTHENTICATION_ENABLED ? (int)($actor['uid'] ?? 0) : 0,
                    'name'      => AUTHENTICATION_ENABLED ? ($actor['name'] ?? 'Unknown') : 'Local User',
                    'timestamp' => date('c'),
                    'text'      => $text,
                ];
                $chat_data['nextMessageId']++;

                // Detect first AI @mention and add a pending placeholder now so the
                // client sees a spinner immediately in the initial response.
                $_pending_ai_user       = null;
                $_pending_placeholder_id = null;
                if (!$ai_auth_user && defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
                    foreach ((json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []) as $_aiu) {
                        if (empty($_aiu['is_ai'])) continue;
                        $_aname = $_aiu['name'] ?? '';
                        if (!$_aname) continue;
                        if (!preg_match('/(^|[\s,])[@#]' . preg_quote($_aname, '/') . '(\b|$)/iu', $text)) continue;
                        $_pending_placeholder_id = $chat_data['nextMessageId'];
                        $chat_data['messages'][] = [
                            'id'        => $_pending_placeholder_id,
                            'uid'       => (int)($_aiu['uid'] ?? 0),
                            'name'      => $_aname,
                            'timestamp' => date('c'),
                            'text'      => '',
                            'pending'   => true,
                        ];
                        $chat_data['nextMessageId']++;
                        $_pending_ai_user = $_aiu;
                        break;
                    }
                }

                file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT));
                $_async_ai = $_pending_ai_user !== null && function_exists('fastcgi_finish_request');
                echo json_encode(['success' => true, 'data' => $chat_data, 'async_ai' => $_async_ai]);

                if ($_pending_ai_user !== null) {
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    trigger_ai_response($_pending_ai_user, $file_path, $chat_data, $indexer, $space_dir, $_pending_placeholder_id);
                }
                break;

            case 'delete_chat_message':
                $file_path = sanitize_path($_POST['file']);
                $msg_id = (int)($_POST['id'] ?? 0);
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $idx = null;
                foreach ($chat_data['messages'] as $i => $msg) {
                    if ((int)$msg['id'] === $msg_id) { $idx = $i; break; }
                }
                if ($idx === null) throw new Exception('Message not found.');
                $current_uid  = AUTHENTICATION_ENABLED ? (int)($_SESSION['user']['uid'] ?? 0) : 0;
                $current_role = get_current_role();
                if ((int)($chat_data['messages'][$idx]['uid'] ?? -1) !== $current_uid && $current_role !== 'admin') {
                    throw new Exception('Permission denied.');
                }
                array_splice($chat_data['messages'], $idx, 1);
                file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'update_chat_topic':
                $file_path = sanitize_path($_POST['file']);
                $new_topic = trim($_POST['topic'] ?? '');
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $chat_data['topic'] = $new_topic;
                file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'set_git_commit':
                $file_path_sgc = sanitize_path($_POST['file']);
                $rel_sgc       = ltrim(str_replace('..', '', $_POST['file']), '/');
                $ext_sgc       = pathinfo($rel_sgc, PATHINFO_EXTENSION);
                $enabled_sgc   = ($_POST['enabled'] ?? '0') === '1';
                if (in_array($ext_sgc, ['md', 'drawio'], true)) {
                    $indexer->setGitCommit($rel_sgc, $enabled_sgc);
                } elseif (in_array($ext_sgc, ['chat', 'list'], true)) {
                    if (!file_exists($file_path_sgc)) throw new Exception('File not found.');
                    $data_sgc = json_decode(file_get_contents($file_path_sgc), true);
                    if ($data_sgc === null) throw new Exception('Invalid file.');
                    $data_sgc['git_commit'] = $enabled_sgc;
                    file_put_contents($file_path_sgc, json_encode($data_sgc, JSON_PRETTY_PRINT));
                }
                echo json_encode(['success' => true, 'git_commit' => $enabled_sgc]);
                break;

            case 'git_restore':
                $file_path_gr = sanitize_path($_POST['file']);
                $rel_gr       = ltrim(str_replace('..', '', $_POST['file']), '/');
                $hash_gr      = preg_replace('/[^a-f0-9]/i', '', $_POST['hash'] ?? '');
                if (strlen($hash_gr) < 7) throw new Exception('Invalid commit hash.');
                $git_root_gr  = find_git_root();
                if (!$git_root_gr) throw new Exception('No git repository found.');
                $git_relpath_gr = $git_root_gr['prefix'] . $rel_gr;
                $show = git_run(['show', $hash_gr . ':' . $git_relpath_gr], $git_root_gr['root']);
                if ($show['code'] !== 0) throw new Exception('Could not retrieve file at that revision.');
                if (file_put_contents($file_path_gr, $show['output']) === false) throw new Exception('Could not write file.');
                $actor_gr  = get_current_actor();
                $git_name  = $actor_gr['name'] ?? 'Wiki';
                $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                echo json_encode(['success' => true]);
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                git_auto_commit($file_path_gr, $git_name, $git_email, 'Restore ' . basename($rel_gr) . ' to ' . substr($hash_gr, 0, 8));
                break;

            case 'commit_snapshot':
                $file_path_cs = sanitize_path($_POST['file']);
                $rel_cs       = ltrim(str_replace('..', '', $_POST['file']), '/');
                if (!file_exists($file_path_cs)) throw new Exception('File not found.');
                $actor_cs  = get_current_actor();
                $git_name  = $actor_cs['name'] ?? 'Wiki';
                $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                $msg_cs    = trim($_POST['message'] ?? '') ?: 'Snapshot: ' . basename($rel_cs);
                git_auto_commit($file_path_cs, $git_name, $git_email, $msg_cs);
                echo json_encode(['success' => true]);
                break;

            case 'toggle_sticky':
                $file_path = sanitize_path($_POST['file']);
                $msg_id    = (int)($_POST['id'] ?? 0);
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $idx = null;
                foreach ($chat_data['messages'] as $i => $msg) {
                    if ((int)$msg['id'] === $msg_id) { $idx = $i; break; }
                }
                if ($idx === null) throw new Exception('Message not found.');
                $current_uid  = AUTHENTICATION_ENABLED ? (int)($_SESSION['user']['uid'] ?? 0) : 0;
                $current_role = get_current_role();
                if ((int)($chat_data['messages'][$idx]['uid'] ?? -1) !== $current_uid && $current_role !== 'admin') {
                    throw new Exception('Permission denied.');
                }
                $chat_data['messages'][$idx]['sticky'] = !($chat_data['messages'][$idx]['sticky'] ?? false);
                file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'toggle_reaction':
                $allowed_reactions = ['👍','👎','❤️','😂','😮','🎉','🔥'];
                $file_path   = sanitize_path($_POST['file']);
                $msg_id      = (int)($_POST['id'] ?? 0);
                $emoji       = $_POST['emoji'] ?? '';
                if (!in_array($emoji, $allowed_reactions, true)) throw new Exception('Invalid emoji.');
                $current_uid = AUTHENTICATION_ENABLED ? (int)($_SESSION['user']['uid'] ?? 0) : 0;
                $chat_data   = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $idx = null;
                foreach ($chat_data['messages'] as $i => $msg) {
                    if ((int)$msg['id'] === $msg_id) { $idx = $i; break; }
                }
                if ($idx === null) throw new Exception('Message not found.');
                if (!isset($chat_data['messages'][$idx]['reactions']) || !is_array($chat_data['messages'][$idx]['reactions'])) {
                    $chat_data['messages'][$idx]['reactions'] = (object)[];
                }
                $reactions = &$chat_data['messages'][$idx]['reactions'];
                $reactions = (array)$reactions;
                if (!isset($reactions[$emoji])) $reactions[$emoji] = [];
                $uid_pos = array_search($current_uid, $reactions[$emoji], true);
                if ($uid_pos !== false) {
                    array_splice($reactions[$emoji], $uid_pos, 1);
                } else {
                    $reactions[$emoji][] = $current_uid;
                }
                if (empty($reactions[$emoji])) unset($reactions[$emoji]);
                $chat_data['messages'][$idx]['reactions'] = empty($reactions) ? (object)[] : $reactions;
                file_put_contents($file_path, json_encode($chat_data, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'get_user_list':
                $users_file = WIKI_SYSTEM_DATA . 'users.json';
                if (!defined('WIKI_SYSTEM_DATA') || !file_exists($users_file)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $all_users = json_decode(file_get_contents($users_file), true)['users'] ?? [];
                $user_list = array_values(array_map(fn($u) => [
                    'uid'   => $u['uid']  ?? 0,
                    'name'  => $u['name'] ?? '',
                    'is_ai' => !empty($u['is_ai']),
                ], $all_users));
                echo json_encode(['success' => true, 'data' => $user_list]);
                break;

            case 'get_path_from_id':
                $id = $_GET['pageid'];
                $path = $indexer->getPath($id);
                if ($path) {
                    echo json_encode(['success' => true, 'path' => $path, 'id' => $id]);
                } else {
                    // Fallback: search all other spaces so cross-space links work.
                    $found_path  = null;
                    $found_space = null;
                    foreach (scandir(PAGES_DIR) as $_sf) {
                        if ($_sf === '.' || $_sf === '..' || $_sf[0] === '.') continue;
                        $candidate = PAGES_DIR . '/' . $_sf;
                        if (!is_dir($candidate) || rtrim($candidate, '/') === rtrim($space_dir, '/')) continue;
                        $other_idx = new PageIndexer($candidate);
                        $other_path = $other_idx->getPath($id);
                        if ($other_path) { $found_path = $other_path; $found_space = $_sf; break; }
                    }
                    if ($found_path) {
                        echo json_encode(['success' => true, 'path' => $found_path, 'id' => $id, 'space' => $found_space]);
                    } else {
                        throw new Exception('Page ID not found.');
                    }
                }
                break;
            
            case 'get_backlinks':
                $target_id = intval($_GET['pageid'] ?? 0);
                if (!$target_id) {
                    echo json_encode(['success' => true, 'backlinks' => []]);
                    break;
                }
                $pattern    = 'pageid=' . $target_id;
                $all_pages  = $indexer->getAllPages();
                $backlinks  = [];
                foreach ($all_pages as $bl_id => $bl_data) {
                    if (!isset($bl_data['path'])) continue;
                    $bl_path = sanitize_path($bl_data['path']);
                    if (!file_exists($bl_path)) continue;
                    if (strpos(file_get_contents($bl_path), $pattern) !== false) {
                        $backlinks[] = [
                            'id'    => $bl_id,
                            'path'  => $bl_data['path'],
                            'title' => basename($bl_data['path'], '.' . pathinfo($bl_data['path'], PATHINFO_EXTENSION)),
                        ];
                    }
                }
                echo json_encode(['success' => true, 'backlinks' => $backlinks]);
                break;

            case 'get_pages_by_tag':
                $tag = $_GET['tag'];
                $all_pages = $indexer->getAllPages();
                $results = [];
                foreach ($all_pages as $id => $data) {
                    if (isset($data['tags']) && is_array($data['tags']) && in_array($tag, $data['tags'])) {
                        $content = file_get_contents(sanitize_path($data['path']));
                        $lines = explode("\n", $content);
                        $header = '';
                        $preview_lines = [];
                        foreach ($lines as $line) {
                            if (substr(trim($line), 0, 1) === '#' && empty($header)) {
                                $header = trim($line);
                            } elseif (!empty($header) && count($preview_lines) < 3 && trim($line) !== '') {
                                $preview_lines[] = trim($line);
                            }
                        }
                        $results[] = array_merge([
                            'id'      => $id,
                            'path'    => $data['path'],
                            'header'  => $header,
                            'preview' => implode(' ', $preview_lines),
                        ], page_meta($data));
                    }
                }
                echo json_encode(['success' => true, 'data' => $results]);
                break;

            case 'search':
                $query = $_GET['query'];
                if (empty($query)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $all_pages = $indexer->getAllPages();
                $results = [];
                foreach ($all_pages as $id => $data) {
                    if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') {
                        continue; // Only search markdown files
                    }
                    $full_path = sanitize_path($data['path']);
                    if (!file_exists($full_path)) {
                        continue;
                    }
                    $content = file_get_contents($full_path);

                    // Search content and path (case-insensitive)
                    $content_pos = stripos($content, $query);
                    $path_pos = stripos($data['path'], $query);

                    if ($content_pos !== false || $path_pos !== false) {
                        $lines = explode("\n", $content);
                        $header = '';
                        foreach ($lines as $line) {
                            if (substr(trim($line), 0, 1) === '#') {
                                $header = trim($line);
                                break;
                            }
                        }
                        
                        $preview = '';
                        if ($content_pos !== false) {
                            $start = max(0, $content_pos - 50);
                            $length = strlen($query) + 100;
                            $snippet = htmlspecialchars(substr($content, $start, $length));
                            $preview = '...' . preg_replace('/' . preg_quote($query, '/') . '/i', '<mark>$0</mark>', $snippet) . '...';
                        } else {
                             $preview_lines = [];
                             foreach ($lines as $line) {
                                 if (!empty($header) && count($preview_lines) < 2 && trim($line) !== '') {
                                     $preview_lines[] = trim($line);
                                 }
                             }
                             $preview = htmlspecialchars(implode(' ', $preview_lines));
                        }

                        $results[] = array_merge([
                            'id'      => $id,
                            'path'    => $data['path'],
                            'header'  => $header,
                            'preview' => $preview,
                        ], page_meta($data));
                    }
                }
                echo json_encode(['success' => true, 'data' => $results]);
                break;

            case 'get_mentions':
                // Pages where the current user is mentioned:
                //   1. #Name anywhere in plain page text
                //   2. UID appears in the mentioned-UIDs field of a {user_comment:author:base64:uid1,uid2} tag
                $mention_name = trim($_GET['name'] ?? '');
                $mention_uid  = (int)($_GET['uid'] ?? 0);
                if ($mention_name === '' && $mention_uid === 0) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $uid_str   = (string)$mention_uid;
                $all_pages = $indexer->getAllPages();
                $results   = [];
                foreach ($all_pages as $id => $data) {
                    if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') continue;
                    $full_path = sanitize_path($data['path']);
                    if (!file_exists($full_path)) continue;
                    $content = file_get_contents($full_path);
                    // #Name mention in plain text
                    $has_name = $mention_name !== '' && (bool)preg_match('/#' . preg_quote($mention_name, '/') . '\b/i', $content);
                    // UID in the 4th field of a comment tag: {user_comment:A:B:uid1,uid2}
                    // Match: ...:(digits,)*UID(,digits)*}
                    $has_uid  = $mention_uid > 0 && (bool)preg_match(
                        '/\{user_comment:\d+:[A-Za-z0-9+\/=]*:(?:\d+,)*' . preg_quote($uid_str, '/') . '(?:,\d+)*\}/',
                        $content
                    );
                    if (!$has_name && !$has_uid) continue;
                    $lines = explode("\n", $content);
                    $header = '';
                    foreach ($lines as $line) {
                        if (substr(trim($line), 0, 1) === '#' && substr(trim($line), 0, 2) !== '#{') { $header = trim($line); break; }
                    }
                    $preview = '';
                    if ($has_name) {
                        $pos = stripos($content, '#' . $mention_name);
                        if ($pos !== false) {
                            $start   = max(0, $pos - 40);
                            $snippet = htmlspecialchars(substr($content, $start, strlen($mention_name) + 90));
                            $preview = '...' . preg_replace('/(#' . preg_quote($mention_name, '/') . ')/i', '<mark>$1</mark>', $snippet) . '...';
                        }
                    } elseif ($has_uid) {
                        // Decode and show the comment that mentions the user
                        if (preg_match('/\{user_comment:\d+:([A-Za-z0-9+\/=]*):(?:\d+,)*' . preg_quote($uid_str, '/') . '/', $content, $m)) {
                            $decoded = base64_decode($m[1]);
                            if ($decoded !== false) $preview = htmlspecialchars(mb_substr($decoded, 0, 120));
                        }
                    }
                    $results[] = array_merge([
                        'id' => $id, 'path' => $data['path'], 'header' => $header, 'preview' => $preview,
                    ], page_meta($data));
                }
                echo json_encode(['success' => true, 'data' => $results]);
                break;

            case 'get_my_comments':
                // Pages where the current user authored a {user_comment} tag
                $comment_uid = (int)($_GET['uid'] ?? 0);
                if ($comment_uid === 0) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $needle    = '{user_comment:' . $comment_uid . ':';
                $all_pages = $indexer->getAllPages();
                $results   = [];
                foreach ($all_pages as $id => $data) {
                    if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') continue;
                    $full_path = sanitize_path($data['path']);
                    if (!file_exists($full_path)) continue;
                    $content = file_get_contents($full_path);
                    if (strpos($content, $needle) === false) continue;
                    $lines = explode("\n", $content);
                    $header = '';
                    foreach ($lines as $line) {
                        if (substr(trim($line), 0, 1) === '#' && substr(trim($line), 0, 2) !== '#{') { $header = trim($line); break; }
                    }
                    // Decode the first comment by this user to use as preview
                    $preview = '';
                    if (preg_match('/\{user_comment:' . $comment_uid . ':([A-Za-z0-9+\/=]*)/', $content, $m)) {
                        $decoded = base64_decode($m[1]);
                        if ($decoded !== false) $preview = htmlspecialchars(mb_substr($decoded, 0, 120));
                    }
                    $results[] = array_merge([
                        'id' => $id, 'path' => $data['path'], 'header' => $header, 'preview' => $preview,
                    ], page_meta($data));
                }
                echo json_encode(['success' => true, 'data' => $results]);
                break;

            case 'update_tags':
                $id = $_POST['id'];
                $tags = json_decode($_POST['tags'], true);
                if ($indexer->updateTags($id, $tags)) {
                    echo json_encode(['success' => true, 'message' => 'Tags updated.']);
                } else {
                    throw new Exception('Could not update tags.');
                }
                break;

            case 'save':
                $file_path  = sanitize_path($_GET['file']);
                $rel_save   = ltrim(str_replace('..', '', $_GET['file']), '/');
                $ext_save   = pathinfo($rel_save, PATHINFO_EXTENSION);
                $content    = file_get_contents('php://input');
                if (file_put_contents($file_path, $content) !== false) {
                    $actor = get_current_actor();
                    $indexer->updateModified($_GET['file'], $actor['uid'], $actor['name']);
                    echo json_encode(['success' => true, 'message' => 'File saved successfully.']);
                    if (in_array($ext_save, ['md', 'drawio'], true) && $indexer->getGitCommit($rel_save, true)) {
                        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                        $git_name  = $actor['name'] ?? 'Wiki';
                        $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                        git_auto_commit($file_path, $git_name, $git_email, 'Update ' . basename($_GET['file']));
                    }
                } else {
                    throw new Exception('Failed to save file.');
                }
                break;

            case 'list_md_templates':
                $tpl_dir = rtrim($space_dir, '/') . '/templates';
                $tpl_names = [];
                if (is_dir($tpl_dir)) {
                    foreach (glob($tpl_dir . '/*.md') as $f) {
                        $tpl_names[] = pathinfo($f, PATHINFO_FILENAME);
                    }
                    sort($tpl_names);
                    // Move 'default' to front if present
                    $di = array_search('default', $tpl_names);
                    if ($di !== false) {
                        array_splice($tpl_names, $di, 1);
                        array_unshift($tpl_names, 'default');
                    }
                }
                echo json_encode(['success' => true, 'templates' => $tpl_names]);
                break;

            case 'create_file':
                $file_path_raw = $_POST['path'];
                $file_path_sanitized = sanitize_path($file_path_raw);
                if (!file_exists($file_path_sanitized)) {
                    $page_title = pathinfo($file_path_raw, PATHINFO_FILENAME);
                    $tpl_name   = isset($_POST['template']) ? basename($_POST['template']) : '';
                    $content    = "# {$page_title}\n\n";
                    if ($tpl_name !== '') {
                        $tpl_file = rtrim($space_dir, '/') . '/templates/' . $tpl_name . '.md';
                        if (is_file($tpl_file)) {
                            $content = file_get_contents($tpl_file);
                        }
                    }
                    if (file_put_contents($file_path_sanitized, $content) !== false) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'File created.']);
                        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                        $git_name  = $actor['name'] ?? 'Wiki';
                        $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                        git_auto_commit($file_path_sanitized, $git_name, $git_email, 'Create ' . basename($file_path_raw));
                    } else {
                        throw new Exception('Could not create file.');
                    }
                } else {
                    throw new Exception('File already exists.');
                }
                break;

            case 'create_folder':
                $folder_path = sanitize_path($_POST['path']);
                if (!is_dir($folder_path)) {
                    if (mkdir($folder_path, 0777, true)) {
                        echo json_encode(['success' => true, 'message' => 'Folder created.']);
                    } else {
                        throw new Exception('Could not create folder.');
                    }
                } else {
                    throw new Exception('Folder already exists.');
                }
                break;
            
            case 'delete':
                $path_raw = $_POST['path'];
                $path_sanitized = sanitize_path($path_raw);
                function delete_recursive($dir, $indexer, $base_dir) {
                    if (!is_dir($dir)) {
                        $indexer->removePage(str_replace($base_dir . '/', '', $dir));
                        // Also delete associated uploads folder if it exists
                        $uploads_dir = $dir . '.uploads';
                        if (is_dir($uploads_dir)) {
                            delete_recursive($uploads_dir, $indexer, $base_dir);
                        }
                        // Delete cached SVG export if this is a drawio file
                        $svg_cache = $dir . '.svg';
                        if (file_exists($svg_cache)) {
                            unlink($svg_cache);
                        }
                        return unlink($dir);
                    }
                    foreach (scandir($dir) as $item) {
                        if ($item == '.' || $item == '..') continue;
                        if (!delete_recursive($dir . DIRECTORY_SEPARATOR . $item, $indexer, $base_dir)) return false;
                    }
                    return rmdir($dir);
                }

                if (file_exists($path_sanitized)) {
                    if (delete_recursive($path_sanitized, $indexer, $space_dir)) {
                        echo json_encode(['success' => true, 'message' => 'Item deleted.']);
                    } else {
                        throw new Exception('Could not delete item.');
                    }
                } else {
                     throw new Exception('Item not found.');
                }
                break;
            
            case 'move':
                $old_path_raw = $_POST['old_path'];
                $new_path_raw = $_POST['new_path'];
                $target_space_name = trim($_POST['target_space'] ?? '');
                $old_path_sanitized = sanitize_path($old_path_raw);
                [$new_path_sanitized, $new_path_rel, $target_indexer] = resolve_target($new_path_raw, $target_space_name);
                $is_cross_space = $target_indexer !== null;
                $is_directory = is_dir($old_path_sanitized);

                if (!file_exists($old_path_sanitized)) {
                    throw new Exception('Source item does not exist.');
                }
                if (file_exists($new_path_sanitized)) {
                    throw new Exception('Destination already exists.');
                }
                // Ensure parent directory exists in target space
                $new_parent = dirname($new_path_sanitized);
                if (!is_dir($new_parent)) {
                    mkdir($new_parent, 0755, true);
                }
                if (rename($old_path_sanitized, $new_path_sanitized)) {
                    if ($is_directory) {
                        if ($is_cross_space) {
                            // Migrate all index entries from source space to target space indexer
                            $all_pages = $indexer->getAllPages();
                            $old_prefix = ltrim(str_replace('..', '', $old_path_raw), '/') . '/';
                            $new_base = ltrim($new_path_rel, '/');
                            foreach ($all_pages as $pid => $pdata) {
                                $ppath = $pdata['path'] ?? '';
                                if (strpos($ppath, $old_prefix) !== 0) continue;
                                $suffix = substr($ppath, strlen($old_prefix));
                                $new_entry_rel = $new_base . '/' . $suffix;
                                $cb_uid  = $pdata['createdBy']['uid']  ?? null;
                                $cb_name = $pdata['createdBy']['name'] ?? null;
                                $target_indexer->addPage($new_entry_rel, $cb_uid, $cb_name);
                                $indexer->removePage($ppath);
                            }
                        } else {
                            $indexer->updateFolderPath($old_path_raw, $new_path_raw);
                        }
                    } else {
                        if ($is_cross_space) {
                            $actor = get_current_actor();
                            $target_indexer->addPage($new_path_rel, $actor['uid'], $actor['name']);
                            $indexer->removePage(ltrim(str_replace('..', '', $old_path_raw), '/'));
                        } else {
                            $indexer->updatePath($old_path_raw, $new_path_raw);
                        }
                        // Move associated uploads folder if it exists
                        $old_uploads_dir = $old_path_sanitized . '.uploads';
                        $new_uploads_dir = $new_path_sanitized . '.uploads';
                        if (is_dir($old_uploads_dir)) {
                            rename($old_uploads_dir, $new_uploads_dir);
                        }
                        // Move cached SVG export if present
                        $old_svg_cache = $old_path_sanitized . '.svg';
                        $new_svg_cache = $new_path_sanitized . '.svg';
                        if (file_exists($old_svg_cache)) {
                            rename($old_svg_cache, $new_svg_cache);
                        }
                    }
                    echo json_encode(['success' => true, 'message' => 'Item moved/renamed successfully.']);
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    $actor_mv    = get_current_actor();
                    $git_name_mv  = $actor_mv['name'] ?? 'Wiki';
                    $git_email_mv = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                    git_move_commit($old_path_sanitized, $new_path_sanitized, $git_name_mv, $git_email_mv);
                } else {
                    throw new Exception('Could not move/rename item.');
                }
                break;

            case 'copy_page':
                $source_path_raw = $_POST['source_path'];
                $new_path_raw = $_POST['new_path'];
                $target_space_name = trim($_POST['target_space'] ?? '');
                $source_path_sanitized = sanitize_path($source_path_raw);
                [$new_path_sanitized, $new_path_rel, $target_indexer] = resolve_target($new_path_raw, $target_space_name);
                $dest_indexer = $target_indexer ?? $indexer;

                if (!file_exists($source_path_sanitized)) {
                    throw new Exception('Source file does not exist.');
                }
                if (file_exists($new_path_sanitized)) {
                    throw new Exception('A file with that name already exists.');
                }
                // Ensure parent directory exists in target space
                $new_parent = dirname($new_path_sanitized);
                if (!is_dir($new_parent)) {
                    mkdir($new_parent, 0755, true);
                }
                if (copy($source_path_sanitized, $new_path_sanitized)) {
                    $source_id = $indexer->getId(ltrim(str_replace('..', '', $source_path_raw), '/'));
                    $source_tags = [];
                    if ($source_id) {
                        $source_tags = $indexer->getTags($source_id);
                    }
                    $actor  = get_current_actor();
                    $new_id = $dest_indexer->addPage($new_path_rel, $actor['uid'], $actor['name']);
                    if ($new_id && !empty($source_tags)) {
                        $dest_indexer->updateTags($new_id, $source_tags);
                    }
                    echo json_encode(['success' => true, 'message' => 'Page copied successfully.']);
                } else {
                    throw new Exception('Could not copy page.');
                }
                break;

            case 'list_attachments':
                $page_path = $_GET['page_path'];
                $upload_dir = sanitize_path($page_path) . '.uploads';
                $attachments = [];
                if (is_dir($upload_dir)) {
                    $files = scandir($upload_dir);
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..') {
                            $attachments[] = $file;
                        }
                    }
                }
                echo json_encode(['success' => true, 'data' => $attachments]);
                break;

            case 'upload_attachment':
                $page_path = $_POST['page_path'];
                $upload_dir = sanitize_path($page_path) . '.uploads';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file = $_FILES['file'];
                $destination = $upload_dir . '/' . basename($file['name']);
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    echo json_encode(['success' => true, 'message' => 'File uploaded.']);
                } else {
                    throw new Exception('Failed to upload file.');
                }
                break;

            case 'delete_attachment':
                $page_path = $_POST['page_path'];
                $filename = $_POST['filename'];
                $file_to_delete = sanitize_path($page_path) . '.uploads/' . basename($filename);
                if (file_exists($file_to_delete)) {
                    if (unlink($file_to_delete)) {
                        echo json_encode(['success' => true, 'message' => 'File deleted.']);
                    } else {
                        throw new Exception('Failed to delete file.');
                    }
                } else {
                    throw new Exception('File not found.');
                }
                break;

            case 'get_diagram_svg':
                $file_path = sanitize_path($_GET['file']);
                if (!file_exists($file_path)) {
                    throw new Exception('Diagram file not found.');
                }
                $svg_path = $file_path . '.svg';
                if (!file_exists($svg_path)) {
                    // SVG is generated client-side when the diagram is saved in the editor.
                    echo json_encode(['success' => false, 'message' => 'SVG not yet generated. Open and save the diagram to create it.']);
                    break;
                }
                echo json_encode(['success' => true, 'svg' => base64_encode(file_get_contents($svg_path))]);
                break;

            case 'save_diagram_svg':
                $diag_path_raw = $_POST['path'];
                $diag_path     = sanitize_path($diag_path_raw);
                $svg_b64       = $_POST['svg'] ?? '';
                if (!$diag_path || !$svg_b64) throw new Exception('Missing path or svg.');
                $svg_bytes = base64_decode($svg_b64);
                if ($svg_bytes === false) throw new Exception('Invalid SVG data.');
                if (file_put_contents($diag_path . '.svg', $svg_bytes) === false) {
                    throw new Exception('Failed to write SVG file.');
                }
                $actor = get_current_actor();
                $indexer->updateModified($diag_path_raw, $actor['uid'], $actor['name']);
                echo json_encode(['success' => true]);
                break;

            case 'create_filesfolder':
                $folder_path = sanitize_path($_POST['path']);
                if (is_dir($folder_path)) throw new Exception('A folder with this name already exists.');
                if (!mkdir($folder_path, 0755, true)) throw new Exception('Failed to create folder.');
                file_put_contents($folder_path . '/.filesfolder', '');
                echo json_encode(['success' => true]);
                break;

            case 'list_folder_files':
                $folder_path = sanitize_path($_GET['folder_path']);
                if (!is_dir($folder_path) || !file_exists($folder_path . '/.filesfolder'))
                    throw new Exception('Not a files library.');
                $ff_files = [];
                foreach (scandir($folder_path) as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry === '.filesfolder') continue;
                    $full = $folder_path . '/' . $entry;
                    if (!is_file($full)) continue;
                    $ff_files[] = [
                        'name'  => $entry,
                        'size'  => filesize($full),
                        'mtime' => filemtime($full),
                        'path'  => ltrim(str_replace(PAGES_DIR, '', $full), '/'),
                    ];
                }
                usort($ff_files, fn($a, $b) => strcmp($a['name'], $b['name']));
                echo json_encode(['success' => true, 'data' => $ff_files]);
                break;

            case 'upload_to_folder':
                $folder_path = sanitize_path($_POST['folder_path']);
                if (!is_dir($folder_path) || !file_exists($folder_path . '/.filesfolder'))
                    throw new Exception('Not a files library.');
                if (empty($_FILES['file'])) throw new Exception('No file uploaded.');
                $dest = $folder_path . '/' . basename($_FILES['file']['name']);
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest))
                    throw new Exception('Upload failed.');
                echo json_encode(['success' => true]);
                break;

            case 'delete_folder_file':
                $file_path = sanitize_path($_POST['path']);
                if (!is_file($file_path)) throw new Exception('File not found.');
                unlink($file_path);
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_users':
                $uf_data = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $human_users = array_values(array_filter($uf_data['users'] ?? [], fn($u) => empty($u['is_ai']) && empty($u['is_system'])));
                echo json_encode(['success' => true, 'data' => $human_users]);
                break;

            case 'admin_save_users':
                $incoming_users = json_decode($_POST['users'] ?? '[]', true);
                if (!is_array($incoming_users)) throw new Exception('Invalid users data.');
                $existing_uf = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $non_human_preserved = array_values(array_filter($existing_uf['users'] ?? [], fn($u) => !empty($u['is_ai']) || !empty($u['is_system'])));
                $existing_by_sub = [];
                foreach ($existing_uf['users'] ?? [] as $eu) {
                    if (!empty($eu['is_ai']) || !empty($eu['is_system'])) continue;
                    if ($eu['sub'] ?? '') $existing_by_sub[$eu['sub']] = $eu;
                }
                $merged = [];
                foreach ($incoming_users as $u) {
                    $sub = trim($u['sub'] ?? '');
                    if (!$sub) continue;
                    $role = in_array($u['role'] ?? '', ['admin', 'editor', 'reader']) ? $u['role'] : 'editor';
                    $base = $existing_by_sub[$sub] ?? ['sub' => $sub, 'name' => $u['name'] ?? '', 'email' => $u['email'] ?? ''];
                    $base['role'] = $role;
                    // spaces: null = all spaces, [] = no access, [...] = specific spaces
                    if (array_key_exists('spaces', $u)) {
                        $base['spaces'] = is_array($u['spaces']) ? array_values(array_filter($u['spaces'], 'is_string')) : null;
                    }
                    $merged[] = $base;
                }
                $merged = array_merge($merged, $non_human_preserved);
                if (file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode(['users' => $merged], JSON_PRETTY_PRINT)) === false) {
                    throw new Exception('Failed to write users file.');
                }
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_user_requests':
                $rq_data = file_exists(WIKI_SYSTEM_DATA . 'user_requests.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'user_requests.json'), true) ?? []) : [];
                echo json_encode(['success' => true, 'data' => $rq_data['requests'] ?? []]);
                break;

            case 'admin_approve_request':
                $approve_sub  = trim($_POST['sub'] ?? '');
                $approve_role = in_array($_POST['role'] ?? '', ['admin', 'editor', 'reader']) ? $_POST['role'] : 'editor';
                if (!$approve_sub) throw new Exception('Missing sub.');
                $rq2 = file_exists(WIKI_SYSTEM_DATA . 'user_requests.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'user_requests.json'), true) ?? ['requests' => []]) : ['requests' => []];
                $found_rq = null;
                $remaining = [];
                foreach ($rq2['requests'] ?? [] as $r) {
                    if ($r['sub'] === $approve_sub) { $found_rq = $r; } else { $remaining[] = $r; }
                }
                if (!$found_rq) throw new Exception('Request not found.');
                file_put_contents(WIKI_SYSTEM_DATA . 'user_requests.json', json_encode(['requests' => $remaining], JSON_PRETTY_PRINT));
                $uf2 = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $max_uid2 = 0;
                foreach ($uf2['users'] ?? [] as $eu2) { if (isset($eu2['uid']) && $eu2['uid'] > $max_uid2) $max_uid2 = $eu2['uid']; }
                $uf2['users'][] = [
                    'sub'    => $approve_sub,
                    'uid'    => $max_uid2 + 1,
                    'name'   => $found_rq['name']  ?? '',
                    'email'  => $found_rq['email'] ?? '',
                    'role'   => $approve_role,
                    'spaces' => null, // null = all spaces; admin can restrict later
                ];
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf2, JSON_PRETTY_PRINT));
                $notify_to = $found_rq['email'] ?? '';
                if ($notify_to && is_mail_configured()) {
                    $uname = htmlspecialchars($found_rq['name'] ?? 'User');
                    send_email($notify_to, $found_rq['name'] ?? 'User',
                        'Your access to ' . APP_TITLE . ' has been approved',
                        "<p>Hello {$uname},</p><p>Your access request for <strong>" . htmlspecialchars(APP_TITLE) . "</strong> has been approved. You can now log in.</p>"
                    );
                }
                write_access_log('USER_APPROVED', $approve_sub, $found_rq['name'] ?? '-', $approve_role);
                echo json_encode(['success' => true]);
                break;

            case 'admin_deny_request':
                $deny_sub = trim($_POST['sub'] ?? '');
                if (!$deny_sub) throw new Exception('Missing sub.');
                $rq3 = file_exists(WIKI_SYSTEM_DATA . 'user_requests.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'user_requests.json'), true) ?? ['requests' => []]) : ['requests' => []];
                $found_deny = null;
                foreach ($rq3['requests'] as &$r3) {
                    if ($r3['sub'] === $deny_sub) { $r3['status'] = 'denied'; $found_deny = $r3; break; }
                }
                unset($r3);
                if (!$found_deny) throw new Exception('Request not found.');
                file_put_contents(WIKI_SYSTEM_DATA . 'user_requests.json', json_encode(['requests' => $rq3['requests']], JSON_PRETTY_PRINT));
                $notify_to2 = $found_deny['email'] ?? '';
                if ($notify_to2 && is_mail_configured()) {
                    $uname2 = htmlspecialchars($found_deny['name'] ?? 'User');
                    send_email($notify_to2, $found_deny['name'] ?? 'User',
                        'Your access request for ' . APP_TITLE,
                        "<p>Hello {$uname2},</p><p>Your access request for <strong>" . htmlspecialchars(APP_TITLE) . "</strong> has been reviewed and was not approved at this time. Please contact the administrator if you believe this is an error.</p>"
                    );
                }
                write_access_log('USER_DENIED', $deny_sub, $found_deny['name'] ?? '-');
                echo json_encode(['success' => true]);
                break;

            case 'user_get_preferences':
                $me_sub = $_SESSION['user']['sub'] ?? '';
                if (!$me_sub) throw new Exception('Not authenticated.');
                $pref_data = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                foreach ($pref_data['users'] ?? [] as $pu) {
                    if (($pu['sub'] ?? '') === $me_sub) {
                        echo json_encode(['success' => true, 'data' => [
                            'name'       => $pu['name']       ?? '',
                            'email'      => $pu['email']      ?? '',
                            'fontFamily' => $pu['fontFamily'] ?? 'sans',
                            'fontSize'   => $pu['fontSize']   ?? 'normal',
                        ]]);
                        break 2;
                    }
                }
                throw new Exception('User not found.');

            case 'user_save_preferences':
                $me_sub2 = $_SESSION['user']['sub'] ?? '';
                if (!$me_sub2) throw new Exception('Not authenticated.');
                $new_email      = trim($_POST['email']      ?? '');
                $new_font       = trim($_POST['fontFamily'] ?? 'sans');
                $new_font_size  = trim($_POST['fontSize']   ?? 'normal');
                if (!in_array($new_font,      ['sans','serif','mono']))         $new_font      = 'sans';
                if (!in_array($new_font_size, ['compact','normal','large']))    $new_font_size = 'normal';
                if ($new_email && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address.');
                }
                $pref_data2 = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $found_pref = false;
                foreach ($pref_data2['users'] as &$pu2) {
                    if (($pu2['sub'] ?? '') === $me_sub2) {
                        if ($new_email) $pu2['email'] = $new_email;
                        $pu2['fontFamily'] = $new_font;
                        $pu2['fontSize']   = $new_font_size;
                        $found_pref = true;
                        break;
                    }
                }
                unset($pu2);
                if (!$found_pref) throw new Exception('User not found.');
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($pref_data2, JSON_PRETTY_PRINT));
                $_SESSION['user']['fontFamily'] = $new_font;
                $_SESSION['user']['fontSize']   = $new_font_size;
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_logs':
                if (!defined('LOG_DIR') || !LOG_DIR || !is_dir(LOG_DIR)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $log_files = [];
                foreach (scandir(LOG_DIR, SCANDIR_SORT_DESCENDING) as $lf) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}_access\.log$/', $lf)) continue;
                    $log_files[] = [
                        'file' => $lf,
                        'date' => substr($lf, 0, 10),
                        'size' => filesize(rtrim(LOG_DIR, '/\\') . '/' . $lf),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $log_files]);
                break;

            case 'admin_get_log_content':
                $lf_name = $_GET['file'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}_access\.log$/', $lf_name)) {
                    throw new Exception('Invalid log file name.');
                }
                if (!defined('LOG_DIR') || !LOG_DIR) throw new Exception('Log directory not configured.');
                $lf_path = rtrim(LOG_DIR, '/\\') . '/' . $lf_name;
                if (!file_exists($lf_path)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $lines   = file($lf_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $entries = [];
                foreach (array_reverse($lines) as $ln) {
                    $p = explode(' | ', $ln, 6);
                    if (count($p) >= 6) {
                        // New 6-field format: time | event | sub | name | ip | detail
                        $entries[] = [
                            'time'   => $p[0],
                            'event'  => $p[1],
                            'sub'    => $p[2],
                            'name'   => $p[3],
                            'ip'     => $p[4],
                            'detail' => $p[5],
                        ];
                    } else {
                        // Legacy 5-field format: time | event | email | ip | detail
                        $entries[] = [
                            'time'   => $p[0] ?? '',
                            'event'  => $p[1] ?? '',
                            'sub'    => $p[2] ?? '',
                            'name'   => '',
                            'ip'     => $p[3] ?? '',
                            'detail' => $p[4] ?? '',
                        ];
                    }
                }
                echo json_encode(['success' => true, 'data' => $entries]);
                break;

            case 'log_client_error':
                $err_msg  = substr(trim($_POST['message'] ?? ''), 0, 500);
                $err_page = substr(trim($_POST['page'] ?? ''), 0, 300);
                if ($err_msg !== '') {
                    $actor = $_SESSION['user']['name'] ?? ($ai_auth_user['name'] ?? 'unknown');
                    write_wiki_error($err_msg, $err_page, $actor, $_SERVER['REMOTE_ADDR'] ?? '');
                }
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_error_logs':
                $err_log_dir = wiki_error_log_dir();
                if (!$err_log_dir || !is_dir($err_log_dir)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $err_log_files = [];
                foreach (scandir($err_log_dir, SCANDIR_SORT_DESCENDING) as $elf) {
                    if (!preg_match('/^wiki_errors_\d{4}-\d{2}-\d{2}\.log$/', $elf)) continue;
                    $err_log_files[] = [
                        'file' => $elf,
                        'date' => substr($elf, 12, 10),
                        'size' => filesize($err_log_dir . $elf),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $err_log_files]);
                break;

            case 'admin_get_error_log_content':
                $elf_name = $_GET['file'] ?? '';
                if (!preg_match('/^wiki_errors_\d{4}-\d{2}-\d{2}\.log$/', $elf_name)) {
                    throw new Exception('Invalid error log file name.');
                }
                $elf_dir = wiki_error_log_dir();
                if (!$elf_dir) throw new Exception('Log directory not configured.');
                $elf_path = $elf_dir . $elf_name;
                if (!file_exists($elf_path)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $elf_lines   = file($elf_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $elf_entries = [];
                foreach (array_reverse($elf_lines) as $eln) {
                    $ep = explode(' | ', $eln, 5);
                    $elf_entries[] = [
                        'time'    => $ep[0] ?? '',
                        'page'    => $ep[1] ?? '',
                        'actor'   => $ep[2] ?? '',
                        'ip'      => $ep[3] ?? '',
                        'message' => $ep[4] ?? '',
                    ];
                }
                echo json_encode(['success' => true, 'data' => $elf_entries]);
                break;

            case 'admin_send_test_email':
                if (!is_mail_configured()) {
                    throw new Exception('Email is not configured. Add SENDGRID_API_KEY or MAILGUN_API_KEY (+ related constants) to config.php.');
                }
                $test_to   = $_SESSION['user']['email'] ?? '';
                $test_name = $_SESSION['user']['name']  ?? 'Admin';
                if (!$test_to) throw new Exception('No email address found for your account.');
                $test_ok = send_email(
                    $test_to, $test_name,
                    APP_TITLE . ' — test email',
                    '<p>This is a test email from <strong>' . htmlspecialchars(APP_TITLE) . '</strong>.</p>'
                    . '<p>If you received this, your ' . mail_provider_name() . ' email configuration is working correctly.</p>'
                );
                if (!$test_ok) throw new Exception('Email was not accepted by ' . mail_provider_name() . '. Check your API key and sending domain in config.php.');
                echo json_encode(['success' => true, 'message' => "Test email sent to {$test_to} via " . mail_provider_name()]);
                break;

            case 'admin_get_diag_log':
                $diag_type = $_GET['type'] ?? '';
                $diag_path = null;
                $diag_hint = null;

                if ($diag_type === 'php') {
                    $diag_path = ini_get('error_log');
                    if (!$diag_path) {
                        $diag_hint = "PHP error log path is not set. Add <code>error_log = /path/to/php_error.log</code> to your php.ini.";
                    }
                } elseif ($diag_type === 'nginx_error') {
                    if (!defined('NGINX_ERROR_LOG') || !NGINX_ERROR_LOG) {
                        $diag_hint = "Add <code>define('NGINX_ERROR_LOG', '/var/log/nginx/error.log');</code> to config.php.";
                    } else {
                        $diag_path = NGINX_ERROR_LOG;
                    }
                } elseif ($diag_type === 'nginx_access') {
                    if (!defined('NGINX_ACCESS_LOG') || !NGINX_ACCESS_LOG) {
                        $diag_hint = "Add <code>define('NGINX_ACCESS_LOG', '/var/log/nginx/access.log');</code> to config.php.";
                    } else {
                        $diag_path = NGINX_ACCESS_LOG;
                    }
                } else {
                    throw new Exception('Unknown log type.');
                }

                if ($diag_hint) {
                    echo json_encode(['success' => true, 'configured' => false, 'hint' => $diag_hint]);
                    break;
                }

                if (!$diag_path || !file_exists($diag_path)) {
                    echo json_encode(['success' => true, 'configured' => true, 'lines' => [],
                        'message' => 'Log file not found: ' . htmlspecialchars($diag_path)]);
                    break;
                }

                if (!is_readable($diag_path)) {
                    echo json_encode(['success' => true, 'configured' => true, 'lines' => [],
                        'message' => 'Log file is not readable by the web server: ' . htmlspecialchars($diag_path)]);
                    break;
                }

                $diag_handle = popen('tail -n 100 ' . escapeshellarg($diag_path) . ' 2>&1', 'r');
                if (!$diag_handle) throw new Exception('Failed to read log file.');
                $diag_content = stream_get_contents($diag_handle);
                pclose($diag_handle);
                $diag_lines = array_values(array_filter(explode("\n", $diag_content), fn($l) => $l !== ''));
                echo json_encode(['success' => true, 'configured' => true, 'path' => $diag_path, 'lines' => $diag_lines]);
                break;

            case 'git_status':
                echo json_encode(['success' => true, 'data' => ['has_git' => find_git_root() !== null]]);
                break;

            case 'git_file_log':
                $git_log_root = find_git_root();
                if (!$git_log_root) { echo json_encode(['success' => true, 'data' => []]); break; }
                $git_log_relpath = $git_log_root['prefix'] . ltrim(str_replace('..', '', $_GET['file'] ?? ''), '/');
                if (!$git_log_relpath) throw new Exception('Missing file path.');
                // %x1F = ASCII unit separator — safe delimiter within commit fields
                $git_log_result = git_run(
                    ['log', '--follow', '--format=%H%x1F%an%x1F%at%x1F%s', '--', $git_log_relpath],
                    $git_log_root['root']
                );
                $commits = [];
                foreach (array_filter(explode("\n", $git_log_result['output'])) as $line) {
                    $p = explode("\x1F", $line, 4);
                    if (count($p) < 3) continue;
                    $commits[] = [
                        'hash'       => trim($p[0]),
                        'short_hash' => substr(trim($p[0]), 0, 8),
                        'author'     => trim($p[1]),
                        'timestamp'  => (int)trim($p[2]),
                        'message'    => trim($p[3] ?? ''),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $commits]);
                break;

            case 'git_file_diff':
                $git_diff_root = find_git_root();
                if (!$git_diff_root) throw new Exception('No git repository found.');
                $git_diff_hash = preg_replace('/[^a-f0-9]/i', '', $_GET['hash'] ?? '');
                if (strlen($git_diff_hash) < 7) throw new Exception('Invalid commit hash.');
                $git_diff_rel  = $git_diff_root['prefix'] . ltrim(str_replace('..', '', $_GET['file'] ?? ''), '/');
                if (!$git_diff_rel) throw new Exception('Missing file path.');
                // Try diff against parent; fall back to show for initial (parentless) commits
                $git_diff_res = git_run(
                    ['diff', '--no-color', $git_diff_hash . '^', $git_diff_hash, '--', $git_diff_rel],
                    $git_diff_root['root']
                );
                if ($git_diff_res['code'] !== 0 || trim($git_diff_res['output']) === '') {
                    $git_diff_res = git_run(
                        ['show', '--no-color', '--format=', '-p', $git_diff_hash, '--', $git_diff_rel],
                        $git_diff_root['root']
                    );
                }
                echo json_encode(['success' => true, 'diff' => $git_diff_res['output']]);
                break;

            case 'git_deleted_files':
                $gdf_root = find_git_root();
                if (!$gdf_root) { echo json_encode(['success' => true, 'data' => []]); break; }
                $gdf_result = git_run(
                    ['log', '--diff-filter=D', '--name-only', '--format=COMMIT:%H' . "\x1F" . '%at' . "\x1F" . '%an' . "\x1F" . '%s'],
                    $gdf_root['root']
                );
                $deleted_files = [];
                $gdf_cur       = null;
                $gdf_prefix    = $gdf_root['prefix'];
                $gdf_pages     = rtrim(PAGES_DIR, '/');
                foreach (explode("\n", $gdf_result['output']) as $raw) {
                    $line = trim($raw);
                    if (!$line) continue;
                    if (str_starts_with($line, 'COMMIT:')) {
                        $parts = explode("\x1F", substr($line, 7), 4);
                        $gdf_cur = count($parts) >= 3 ? [
                            'hash'       => trim($parts[0]),
                            'short_hash' => substr(trim($parts[0]), 0, 8),
                            'timestamp'  => (int)trim($parts[1]),
                            'author'     => trim($parts[2]),
                            'message'    => trim($parts[3] ?? ''),
                        ] : null;
                    } elseif ($gdf_cur) {
                        if ($gdf_prefix !== '' && !str_starts_with($line, $gdf_prefix)) continue;
                        $rel = $gdf_prefix ? substr($line, strlen($gdf_prefix)) : $line;
                        if (!preg_match('/\.(md|drawio|list|chat)$/', $rel)) continue;
                        if (file_exists($gdf_pages . '/' . $rel)) continue;
                        if (array_search($rel, array_column($deleted_files, 'path')) === false) {
                            $deleted_files[] = array_merge(['path' => $rel], $gdf_cur);
                        }
                    }
                }
                echo json_encode(['success' => true, 'data' => $deleted_files]);
                break;

            case 'git_restore_deleted':
                $grd_hash = preg_replace('/[^a-f0-9]/i', '', $_POST['hash'] ?? '');
                $grd_rel  = ltrim(str_replace('..', '', $_POST['file'] ?? ''), '/');
                if (strlen($grd_hash) < 7 || !$grd_rel) throw new Exception('Invalid parameters.');
                $grd_root = find_git_root();
                if (!$grd_root) throw new Exception('No git repository found.');
                $grd_gitpath = $grd_root['prefix'] . $grd_rel;
                // Restore from the commit just before the deletion
                $grd_show = git_run(['show', $grd_hash . '^:' . $grd_gitpath], $grd_root['root']);
                if ($grd_show['code'] !== 0) throw new Exception('Could not retrieve file from git history.');
                $grd_dest = sanitize_path($grd_rel);
                if (!$grd_dest) throw new Exception('Invalid file path.');
                $grd_dir = dirname($grd_dest);
                if (!is_dir($grd_dir)) mkdir($grd_dir, 0777, true);
                if (file_put_contents($grd_dest, $grd_show['output']) === false) throw new Exception('Could not write file.');
                $indexer->addPage($grd_dest);
                $grd_actor = get_current_actor();
                $grd_name  = $grd_actor['name'] ?? 'Wiki';
                $grd_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                echo json_encode(['success' => true]);
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                git_auto_commit($grd_dest, $grd_name, $grd_email, 'Restore deleted ' . basename($grd_rel));
                break;

            case 'tree_mtime':
                $idx_file = $space_dir . '/index.json';
                echo json_encode(['success' => true, 'mtime' => file_exists($idx_file) ? filemtime($idx_file) : 0]);
                break;

            case 'indexfiles':
                $count = $indexer->rebuildIndex($space_dir);
                header("Content-Type: text/plain");
                echo "Indexing complete. Found and indexed {$count} pages.";
                exit;

            case 'list_spaces':
                $spaces_list = [];
                foreach (scandir(PAGES_DIR) as $_sf) {
                    if ($_sf === '.' || $_sf === '..' || $_sf[0] === '.') continue;
                    if (is_dir(PAGES_DIR . '/' . $_sf)) $spaces_list[] = $_sf;
                }
                sort($spaces_list);
                // Filter by user's allowed spaces (admins see all).
                if (AUTHENTICATION_ENABLED) {
                    $_ls_role   = $_SESSION['user']['role']   ?? 'reader';
                    $_ls_spaces = $_SESSION['user']['spaces'] ?? null;
                    if ($_ls_role !== 'admin' && $_ls_spaces !== null) {
                        $spaces_list = array_values(array_filter($spaces_list, fn($s) => in_array($s, $_ls_spaces, true)));
                    }
                }
                echo json_encode(['success' => true, 'data' => $spaces_list]);
                break;

            case 'rename_space':
                $rs_old = trim($_POST['old_name'] ?? '');
                $rs_new = trim($_POST['new_name'] ?? '');
                if ($rs_old === '' || $rs_new === '') throw new Exception('Space name cannot be empty.');
                $rs_safe_old = basename($rs_old);
                $rs_safe_new = basename($rs_new);
                if ($rs_safe_old !== $rs_old || $rs_safe_new !== $rs_new) throw new Exception('Invalid characters in space name.');
                if ($rs_safe_new[0] === '.') throw new Exception('Space name cannot start with a dot.');
                if ($rs_safe_old === $rs_safe_new) { echo json_encode(['success' => true]); break; }
                $rs_old_dir = PAGES_DIR . '/' . $rs_safe_old;
                $rs_new_dir = PAGES_DIR . '/' . $rs_safe_new;
                if (!is_dir($rs_old_dir)) throw new Exception('Space not found.');
                if (is_dir($rs_new_dir)) throw new Exception('A space with that name already exists.');
                if (!rename($rs_old_dir, $rs_new_dir)) throw new Exception('Could not rename space directory.');
                // Update spaces arrays in users.json so access rights are preserved
                if (defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
                    $rs_uf = json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []];
                    $rs_changed = false;
                    foreach ($rs_uf['users'] as &$rs_user) {
                        if (!isset($rs_user['spaces']) || !is_array($rs_user['spaces'])) continue;
                        $rs_idx = array_search($rs_safe_old, $rs_user['spaces'], true);
                        if ($rs_idx !== false) {
                            $rs_user['spaces'][$rs_idx] = $rs_safe_new;
                            $rs_changed = true;
                        }
                    }
                    unset($rs_user);
                    if ($rs_changed) {
                        file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($rs_uf, JSON_PRETTY_PRINT));
                    }
                }
                echo json_encode(['success' => true]);
                break;

            case 'create_space':
                $new_space_name = trim($_POST['name'] ?? '');
                if ($new_space_name === '') throw new Exception('Space name cannot be empty.');
                $safe_space_name = basename($new_space_name);
                if ($safe_space_name !== $new_space_name) throw new Exception('Invalid characters in space name.');
                if ($safe_space_name[0] === '.') throw new Exception('Space name cannot start with a dot.');
                $new_space_dir = PAGES_DIR . '/' . $safe_space_name;
                if (is_dir($new_space_dir)) throw new Exception('A space with that name already exists.');
                if (!mkdir($new_space_dir, 0755)) throw new Exception('Could not create space directory.');
                // Create Main.md start page and index it
                $main_md_path = $new_space_dir . '/Main.md';
                $main_md_content = "# Welcome to " . APP_TITLE . "\n\n"
                    . "This is your wiki's start page.\n\n"
                    . "- Use the **New ...** button in the sidebar to create pages, folders, diagrams and lists\n"
                    . "- Click any page in the left sidebar to open it\n"
                    . "- Use the pencil icon (top right) to edit a Markdown page\n"
                    . "- Embed diagrams and lists in any page using the toolbar insert options\n\n"
                    . "## Help & Documentation\n\n"
                    . "Full documentation is available at [astucia.wiki](https://astucia.wiki).\n";
                file_put_contents($main_md_path, $main_md_content);
                $new_space_indexer = new PageIndexer($new_space_dir);
                $actor = get_current_actor();
                $new_space_indexer->addPage('Main.md', $actor['uid'], $actor['name']);
                // Copy app-level templates folder into the new space
                $src_templates = __DIR__ . '/templates';
                if (is_dir($src_templates)) {
                    $dst_templates = $new_space_dir . '/templates';
                    if (mkdir($dst_templates, 0755)) {
                        foreach (new DirectoryIterator($src_templates) as $tpl) {
                            if ($tpl->isDot() || !$tpl->isFile()) continue;
                            copy($tpl->getPathname(), $dst_templates . '/' . $tpl->getFilename());
                        }
                    }
                }
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_ai_users':
                $uf_ai = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $ai_users_out = [];
                foreach ($uf_ai['users'] ?? [] as $u) {
                    if (empty($u['is_ai'])) continue;
                    $cfg = $u['ai_config'] ?? [];
                    $has_key = !empty($cfg['api_key']);
                    unset($cfg['api_key']);
                    $ai_users_out[] = [
                        'uid'           => $u['uid']           ?? null,
                        'name'          => $u['name']          ?? '',
                        'role'          => $u['role']          ?? 'editor',
                        'service_token' => $u['service_token'] ?? '',
                        'ai_config'     => array_merge($cfg, ['api_key_set' => $has_key]),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $ai_users_out]);
                break;

            case 'admin_save_ai_user':
                $ai_name   = trim($_POST['name'] ?? '');
                $ai_role   = in_array($_POST['role'] ?? '', ['editor', 'reader']) ? $_POST['role'] : 'editor';
                $ai_uid    = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                $ai_cfg_in = json_decode($_POST['ai_config'] ?? '{}', true) ?? [];
                if (!$ai_name) throw new Exception('AI user name is required.');
                $uf_sai = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                if ($ai_uid !== null) {
                    $found_ai = false;
                    foreach ($uf_sai['users'] as &$u) {
                        if (empty($u['is_ai']) || (int)($u['uid'] ?? -1) !== $ai_uid) continue;
                        $u['name'] = $ai_name;
                        $u['role'] = $ai_role;
                        $ec = $u['ai_config'] ?? [];
                        $nc = [
                            'provider'         =>        $ai_cfg_in['provider']         ?? $ec['provider']         ?? 'openai',
                            'api_url'          => trim($ai_cfg_in['api_url']            ?? $ec['api_url']          ?? ''),
                            'model'            => trim($ai_cfg_in['model']              ?? $ec['model']            ?? ''),
                            'system_prompt'    =>        $ai_cfg_in['system_prompt']    ?? $ec['system_prompt']    ?? '',
                            'context_messages' => (int)( $ai_cfg_in['context_messages'] ?? $ec['context_messages'] ?? 20),
                            'max_tokens'       => (int)( $ai_cfg_in['max_tokens']       ?? $ec['max_tokens']       ?? 4096),
                            'temperature'      => (float)($ai_cfg_in['temperature']      ?? $ec['temperature']      ?? 0.7),
                        ];
                        if (!empty($ai_cfg_in['api_key'])) $nc['api_key'] = $ai_cfg_in['api_key'];
                        elseif (!empty($ec['api_key']))     $nc['api_key'] = $ec['api_key'];
                        $u['ai_config'] = $nc;
                        $found_ai = true;
                        break;
                    }
                    unset($u);
                    if (!$found_ai) throw new Exception('AI user not found.');
                } else {
                    $max_uid_ai = 0;
                    foreach ($uf_sai['users'] as $eu) { if (isset($eu['uid']) && $eu['uid'] > $max_uid_ai) $max_uid_ai = $eu['uid']; }
                    $uf_sai['users'][] = [
                        'uid'           => $max_uid_ai + 1,
                        'name'          => $ai_name,
                        'role'          => $ai_role,
                        'is_ai'         => true,
                        'service_token' => generate_ai_service_token(),
                        'ai_config'     => [
                            'provider'         =>        $ai_cfg_in['provider']         ?? 'openai',
                            'api_url'          => trim($ai_cfg_in['api_url']            ?? ''),
                            'api_key'          =>        $ai_cfg_in['api_key']          ?? '',
                            'model'            => trim($ai_cfg_in['model']              ?? ''),
                            'system_prompt'    =>        $ai_cfg_in['system_prompt']    ?? '',
                            'context_messages' => (int)( $ai_cfg_in['context_messages'] ?? 20),
                            'max_tokens'       => (int)( $ai_cfg_in['max_tokens']       ?? 4096),
                            'temperature'      => (float)($ai_cfg_in['temperature']      ?? 0.7),
                        ],
                    ];
                }
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_sai, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                break;

            case 'admin_delete_ai_user':
                $del_ai_uid = (int)($_POST['uid'] ?? 0);
                if (!$del_ai_uid) throw new Exception('Missing uid.');
                $uf_del = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $uf_del['users'] = array_values(array_filter($uf_del['users'], fn($u) => !(!empty($u['is_ai']) && (int)($u['uid'] ?? -1) === $del_ai_uid)));
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_del, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                break;

            case 'admin_regenerate_ai_token':
                $regen_uid = (int)($_POST['uid'] ?? 0);
                if (!$regen_uid) throw new Exception('Missing uid.');
                $uf_regen = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $new_token = generate_ai_service_token();
                $found_regen = false;
                foreach ($uf_regen['users'] as &$u) {
                    if (!empty($u['is_ai']) && (int)($u['uid'] ?? -1) === $regen_uid) {
                        $u['service_token'] = $new_token;
                        $found_regen = true;
                        break;
                    }
                }
                unset($u);
                if (!$found_regen) throw new Exception('AI user not found.');
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_regen, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'token' => $new_token]);
                break;

            case 'admin_get_api_accounts':
                $uf_sys = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $sys_out = [];
                foreach ($uf_sys['users'] ?? [] as $u) {
                    if (empty($u['is_system'])) continue;
                    $sys_out[] = ['uid' => $u['uid'] ?? null, 'name' => $u['name'] ?? '', 'role' => $u['role'] ?? 'editor', 'service_token' => $u['service_token'] ?? ''];
                }
                echo json_encode(['success' => true, 'data' => $sys_out]);
                break;

            case 'admin_save_api_account':
                $sys_name = trim($_POST['name'] ?? '');
                $sys_role = in_array($_POST['role'] ?? '', ['editor', 'reader']) ? $_POST['role'] : 'editor';
                $sys_uid  = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                if (!$sys_name) throw new Exception('System user name is required.');
                $uf_ssys = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                if ($sys_uid !== null) {
                    $found_ssys = false;
                    foreach ($uf_ssys['users'] as &$u) {
                        if (empty($u['is_system']) || (int)($u['uid'] ?? -1) !== $sys_uid) continue;
                        $u['name'] = $sys_name;
                        $u['role'] = $sys_role;
                        $found_ssys = true;
                        break;
                    }
                    unset($u);
                    if (!$found_ssys) throw new Exception('System user not found.');
                } else {
                    $max_uid_sys = 0;
                    foreach ($uf_ssys['users'] as $eu) { if (isset($eu['uid']) && $eu['uid'] > $max_uid_sys) $max_uid_sys = $eu['uid']; }
                    $uf_ssys['users'][] = ['uid' => $max_uid_sys + 1, 'name' => $sys_name, 'role' => $sys_role, 'is_system' => true, 'service_token' => generate_api_service_token()];
                }
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_ssys, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                break;

            case 'admin_delete_api_account':
                $del_sys_uid = (int)($_POST['uid'] ?? 0);
                if (!$del_sys_uid) throw new Exception('Missing uid.');
                $uf_del_sys = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $uf_del_sys['users'] = array_values(array_filter($uf_del_sys['users'], fn($u) => !(!empty($u['is_system']) && (int)($u['uid'] ?? -1) === $del_sys_uid)));
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_del_sys, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                break;

            case 'admin_regenerate_api_token':
                $regen_sys_uid = (int)($_POST['uid'] ?? 0);
                if (!$regen_sys_uid) throw new Exception('Missing uid.');
                $uf_regen_sys = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $new_sys_token = generate_api_service_token();
                $found_sys_regen = false;
                foreach ($uf_regen_sys['users'] as &$u) {
                    if (!empty($u['is_system']) && (int)($u['uid'] ?? -1) === $regen_sys_uid) {
                        $u['service_token'] = $new_sys_token;
                        $found_sys_regen = true;
                        break;
                    }
                }
                unset($u);
                if (!$found_sys_regen) throw new Exception('System user not found.');
                file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($uf_regen_sys, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'token' => $new_sys_token]);
                break;

            case 'admin_get_agent_jobs':
                $aj_file = WIKI_SYSTEM_DATA . 'agent_jobs.json';
                $aj_data = file_exists($aj_file) ? (json_decode(file_get_contents($aj_file), true) ?? ['jobs' => []]) : ['jobs' => []];
                $uf_aj = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $ai_map = [];
                foreach ($uf_aj['users'] ?? [] as $u) {
                    if (!empty($u['is_ai'])) $ai_map[(int)$u['uid']] = $u['name'] ?? 'AI';
                }
                $sp_list = [];
                foreach (scandir(PAGES_DIR) as $_sf) {
                    if ($_sf[0] !== '.' && is_dir(PAGES_DIR . '/' . $_sf)) $sp_list[] = $_sf;
                }
                echo json_encode([
                    'success'          => true,
                    'jobs'             => $aj_data['jobs'] ?? [],
                    'ai_users'         => $ai_map,
                    'spaces'           => $sp_list,
                    'server_time'      => date('Y-m-d H:i:s'),
                    'server_timezone'  => date_default_timezone_get(),
                ]);
                break;

            case 'admin_save_agent_job':
                $aj_file2 = WIKI_SYSTEM_DATA . 'agent_jobs.json';
                $aj_data2 = file_exists($aj_file2) ? (json_decode(file_get_contents($aj_file2), true) ?? ['jobs' => []]) : ['jobs' => []];
                $job_id2  = trim($_POST['id'] ?? '');
                $sched_raw = trim($_POST['schedule'] ?? '{}');
                $sched_obj = json_decode($sched_raw, true);
                if (!is_array($sched_obj)) $sched_obj = [];
                $job_in2  = [
                    'id'          => $job_id2 ?: 'job_' . time() . '_' . rand(100, 999),
                    'name'        => trim($_POST['name'] ?? ''),
                    'enabled'     => !empty($_POST['enabled']) && $_POST['enabled'] !== 'false',
                    'ai_user_uid' => (int)($_POST['ai_user_uid'] ?? 0),
                    'space'       => basename($_POST['space'] ?? basename(PAGES_DIR)),
                    'prompt'      => trim($_POST['prompt'] ?? ''),
                    'schedule'    => $sched_obj,
                ];
                if (!$job_in2['name'])   throw new Exception('Job name is required.');
                if (!$job_in2['prompt']) throw new Exception('Prompt is required.');
                if ($job_id2) {
                    $found2 = false;
                    foreach ($aj_data2['jobs'] as &$_j2) {
                        if ($_j2['id'] === $job_id2) {
                            $job_in2['last_run']      = $_j2['last_run']      ?? null;
                            $job_in2['last_status']   = $_j2['last_status']   ?? null;
                            $job_in2['last_log_page'] = $_j2['last_log_page'] ?? null;
                            $_j2 = $job_in2; $found2 = true; break;
                        }
                    }
                    unset($_j2);
                    if (!$found2) throw new Exception('Job not found.');
                } else {
                    $job_in2['last_run'] = null; $job_in2['last_status'] = null; $job_in2['last_log_page'] = null;
                    $aj_data2['jobs'][] = $job_in2;
                }
                file_put_contents($aj_file2, json_encode($aj_data2, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'id' => $job_in2['id']]);
                break;

            case 'admin_delete_agent_job':
                $aj_file3 = WIKI_SYSTEM_DATA . 'agent_jobs.json';
                $del_job_id = trim($_POST['id'] ?? '');
                if (!$del_job_id) throw new Exception('Missing id.');
                $aj_data3 = file_exists($aj_file3) ? (json_decode(file_get_contents($aj_file3), true) ?? ['jobs' => []]) : ['jobs' => []];
                $aj_data3['jobs'] = array_values(array_filter($aj_data3['jobs'], fn($j) => $j['id'] !== $del_job_id));
                file_put_contents($aj_file3, json_encode($aj_data3, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true]);
                break;

            case 'admin_run_agent_job':
                $aj_file4 = WIKI_SYSTEM_DATA . 'agent_jobs.json';
                $run_job_id = trim($_POST['id'] ?? '');
                if (!$run_job_id) throw new Exception('Missing id.');
                $aj_data4 = file_exists($aj_file4) ? (json_decode(file_get_contents($aj_file4), true) ?? ['jobs' => []]) : ['jobs' => []];
                $run_job = null;
                $run_job_idx = -1;
                foreach ($aj_data4['jobs'] as $k4 => $j4) {
                    if ($j4['id'] === $run_job_id) { $run_job = $j4; $run_job_idx = $k4; break; }
                }
                if (!$run_job) throw new Exception('Job not found.');
                $uf_run = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $run_ai_user = null;
                foreach ($uf_run['users'] ?? [] as $_ru) {
                    if (!empty($_ru['is_ai']) && (int)($_ru['uid'] ?? -1) === (int)($run_job['ai_user_uid'] ?? 0)) { $run_ai_user = $_ru; break; }
                }
                if (!$run_ai_user) throw new Exception('AI user not found.');
                $run_safe_space = basename($run_job['space'] ?? basename(PAGES_DIR));
                $run_space_dir2 = rtrim(PAGES_DIR, '/') . '/' . $run_safe_space;
                if (!is_dir($run_space_dir2)) $run_space_dir2 = rtrim(PAGES_DIR, '/');
                $run_indexer2 = new PageIndexer($run_space_dir2);
                set_time_limit(300);
                $run_result = run_agent_job($run_job, $run_ai_user, $run_indexer2, $run_space_dir2);
                $run_status = $run_result['error'] ? 'error' : 'ok';
                $run_ts     = date('c');
                $safe_jn2   = preg_replace('/[^a-zA-Z0-9_-]/', '-', $run_job['name'] ?? 'job');
                $log_dir2   = rtrim(LOG_DIR, '/') . '/agent-jobs/' . $safe_jn2 . '/';
                $log_file2  = $log_dir2 . date('Y-m-d-His') . '.log';
                if (!is_dir($log_dir2)) mkdir($log_dir2, 0755, true);
                $log_body2  = "[{$run_ts}] Job: {$run_job['name']}\n[{$run_ts}] Status: {$run_status}\n[{$run_ts}] AI User: " . ($run_ai_user['name'] ?? 'AI') . "\n\n";
                $log_body2 .= $run_result['error'] ? "ERROR:\n{$run_result['error']}\n" : "RESULT:\n{$run_result['reply']}\n";
                file_put_contents($log_file2, $log_body2);
                if ($run_result['error'] && defined('ADMIN_EMAIL') && ADMIN_EMAIL && is_mail_configured()) {
                    $rj_subj = APP_TITLE . ' — Agent job failed: ' . $run_job['name'];
                    $rj_body = '<h2>Agent Job Failed</h2>'
                             . '<p><strong>Job:</strong> '      . htmlspecialchars($run_job['name'])              . '</p>'
                             . '<p><strong>Run time:</strong> '  . htmlspecialchars($run_ts)                       . '</p>'
                             . '<p><strong>AI User:</strong> '   . htmlspecialchars($run_ai_user['name'] ?? 'AI')  . '</p>'
                             . '<p><strong>Error:</strong></p>'
                             . '<pre style="background:#fff5f5;padding:0.8rem;border-radius:4px">'
                             . htmlspecialchars($run_result['error']) . '</pre>'
                             . '<p><strong>Log file:</strong> <code>' . htmlspecialchars($log_file2) . '</code></p>';
                    send_email(ADMIN_EMAIL, 'Admin', $rj_subj, $rj_body);
                }
                $aj_data4['jobs'][$run_job_idx]['last_run']      = $run_ts;
                $aj_data4['jobs'][$run_job_idx]['last_status']   = $run_status;
                $aj_data4['jobs'][$run_job_idx]['last_log_file'] = $log_file2;
                file_put_contents($aj_file4, json_encode($aj_data4, JSON_PRETTY_PRINT));
                echo json_encode(['success' => true, 'status' => $run_status, 'reply' => $run_result['reply'] ?? '', 'error' => $run_result['error'] ?? null, 'log_file' => $log_file2]);
                break;

            default:
                throw new Exception('Invalid action.');
        }
    } catch (Exception $e) {
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>