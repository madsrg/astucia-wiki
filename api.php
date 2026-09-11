<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PHP WIKI - BACKEND API
// =================================================================

require_once 'config.php';
require_once 'logger.php';
require_once 'mailer.php';
require_once __DIR__ . '/ai_core.php';
require_once __DIR__ . '/agent_jobs.php';
require_once __DIR__ . '/service_auth.php';
require_once __DIR__ . '/chat_retention.php';
require_once __DIR__ . '/system_prompt_gallery.php';
require_once __DIR__ . '/space_settings.php';
require_once __DIR__ . '/mentions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/llm_trace.php';
require_once __DIR__ . '/wikilinks.php';
require_once __DIR__ . '/realtime.php';

session_start();

// --- Service Token auth (AI users: wk_ai_…, API Accounts: wk_sys_…) ---
$ai_auth_user = resolve_service_token_auth();

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
require_once 'graph.php';
require_once 'search_index.php';
require_once 'index_sync.php';

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
        $_sp_candidate = rtrim(PAGES_DIR, '/') . '/' . $_sp_safe;
        if (is_dir($_sp_candidate) && $_sp_safe[0] !== '.') {
            // ACL: non-admins (session users, AI Users, and API Accounts alike)
            // can only access spaces they are granted. null = all spaces.
            if (AUTHENTICATION_ENABLED) {
                $allowed_spaces = actor_spaces_filter(get_current_role(), $ai_auth_user);
                if ($allowed_spaces !== null && !in_array($_sp_safe, $allowed_spaces, true)) {
                    header('HTTP/1.1 403 Forbidden');
                    echo json_encode(['success' => false, 'message' => 'Access denied to this space.']);
                    exit;
                }
            }
            $space_dir = $_sp_candidate;
        }
    }

    wiki_migrate_user_emails();   // one-shot, marked by `schema` in users.json

    $indexer = new PageIndexer($space_dir);

    // SQLite FTS5 search index (null when SEARCH_ENGINE !== 'sqlite').
    $search_idx = null;
    if (defined('SEARCH_ENGINE') && SEARCH_ENGINE === 'sqlite') {
        try { $search_idx = new SearchIndex(); } catch (\Throwable $_sie) {}
    }
    // Helper: space name for the current request's space_dir.
    function _sidx_space(): string {
        global $space_dir;
        return basename(rtrim($space_dir, '/'));
    }

    // Fills a newly created space directory with its start page and a copy of the
    // app-level templates. Shared by create_space and wiki_ensure_default_space so a
    // space bootstrapped automatically is indistinguishable from a hand-made one.
    function wiki_scaffold_space(string $space_dir_new, ?int $uid = null, ?string $user_name = null): void {
        $main_md = rtrim($space_dir_new, '/') . '/Main.md';
        if (!file_exists($main_md)) {
            file_put_contents($main_md,
                  "# Welcome to " . APP_TITLE . "\n\n"
                . "This is your wiki's start page.\n\n"
                . "- Use the **New ...** button in the sidebar to create pages, folders, diagrams and lists\n"
                . "- Click any page in the left sidebar to open it\n"
                . "- Use the pencil icon (top right) to edit a Markdown page\n"
                . "- Embed diagrams and lists in any page using the toolbar insert options\n\n"
                . "## Help & Documentation\n\n"
                . "Full documentation is available at [astucia.wiki](https://astucia.wiki).\n");
            (new PageIndexer($space_dir_new))->addPage('Main.md', $uid, $user_name);
        }
        $src_templates = __DIR__ . '/templates';
        $dst_templates = rtrim($space_dir_new, '/') . '/templates';
        if (is_dir($src_templates) && !is_dir($dst_templates) && mkdir($dst_templates, 0755)) {
            foreach (new DirectoryIterator($src_templates) as $tpl) {
                if ($tpl->isDot() || !$tpl->isFile()) continue;
                copy($tpl->getPathname(), $dst_templates . '/' . $tpl->getFilename());
            }
        }
    }

    /**
     * Guarantee that a wiki has at least one Space.
     *
     * Content is meant to live in Spaces: once any Space exists the UI can no longer
     * select PAGES_DIR itself, so anything sitting at the root becomes unreachable.
     * A fresh install therefore gets a "Main" Space rather than being left pointing at
     * the root — and because this runs before the start-page check, the start page is
     * created inside that Space instead of at the root.
     *
     * Deliberately does nothing when the root already holds content files: creating a
     * Space at that point would hide an existing wiki's pages behind a directory the UI
     * cannot open. Such an install keeps working as it always has.
     *
     * @return string|null The Space that was created, or null if nothing was done.
     */
    function wiki_ensure_default_space(): ?string {
        $root = rtrim(PAGES_DIR, '/');
        $entries = @scandir($root);
        if ($entries === false) return null;

        $has_root_content = false;
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..' || $e[0] === '.') continue;
            if (is_dir($root . '/' . $e)) return null;              // a Space already exists
            if ($e === 'index.json' || $e === 'graph.json') continue; // sidecars, not content
            if (in_array(strtolower(pathinfo($e, PATHINFO_EXTENSION)), PageIndexer::CONTENT_EXTS, true)) {
                $has_root_content = true;
            }
        }
        if ($has_root_content) return null;   // pre-existing root wiki — leave it alone

        $name = 'Main';
        $dir  = $root . '/' . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0755)) return null;
        $actor = get_current_actor();
        wiki_scaffold_space($dir, $actor['uid'] ?? null, $actor['name'] ?? null);
        return $name;
    }

    // Pick up content added/removed/edited outside the wiki (rsync, git pull, an editor
    // writing straight into PAGES_DIR). Debounced and lock-guarded, so the common case
    // is one small stamp-file read; see index_sync.php for why this is here rather than
    // in cron or a filesystem watcher. Runs before the action so the request that
    // notices drift already serves the reconciled index.
    if (($_REQUEST['action'] ?? '') !== 'indexfiles') {   // that action rebuilds unconditionally anyway
        try { index_sync_maybe($space_dir, $indexer, $search_idx); } catch (\Throwable $_isy) {}
    }

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
        global $space_dir, $ai_auth_user;
        $path = str_replace('..', '', $path);
        $path = ltrim($path, '/');
        $abs  = $space_dir . '/' . $path;

        // The ?space= guard in the bootstrap covers the parameter; this covers the path.
        // With no ?space= the base is PAGES_DIR itself, so a *relative* path of
        // "Bravo/secret.md" reaches Bravo without the parameter ever being set and without
        // the guard ever running. Asking which Space the resolved path lands in catches
        // that, and it is asked here because every action's file access comes through this
        // one function.
        if (AUTHENTICATION_ENABLED) {
            $allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
            if ($allowed !== null) {
                // realpath() for something that exists; for a path being created, resolve
                // the deepest parent that does, since that is what decides the Space.
                $probe = $abs;
                while ($probe !== '' && !file_exists($probe)) {
                    $parent = dirname($probe);
                    if ($parent === $probe) break;
                    $probe = $parent;
                }
                $real = realpath($probe);
                if ($real === false || !wiki_space_allowed($allowed, wiki_path_space($real))) {
                    throw new Exception('Access denied to this space.');
                }
            }
        }
        return $abs;
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
                global $ai_auth_user;
                $allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
                if ($allowed !== null && !in_array($safe, $allowed, true))
                    throw new Exception('Access denied to target space.');
            }
            $clean = ltrim(str_replace('..', '', $new_path_raw), '/');
            return [$target_base . '/' . $clean, $clean, new PageIndexer($target_base)];
        }
        return [sanitize_path($new_path_raw), ltrim(str_replace('..', '', $new_path_raw), '/'), null];
    }

    // --- Git helpers ---
    require_once __DIR__ . '/git_helpers.php';

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

    function _load_mcp_servers(): array {
        if (!defined('WIKI_SYSTEM_DATA')) return [];
        $file = WIKI_SYSTEM_DATA . 'mcp_servers.json';
        return file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    }

    function _save_mcp_servers(array $servers): void {
        if (!defined('WIKI_SYSTEM_DATA')) return;
        file_put_contents(WIKI_SYSTEM_DATA . 'mcp_servers.json', json_encode($servers, JSON_PRETTY_PRINT));
    }

    function _mcp_jsonrpc_test(string $base_url, ?string $auth_header_line, string $method, array $params = [], array $extra_headers = []): array {
        if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl not available', 'data' => null];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];
        if ($auth_header_line) $headers[] = $auth_header_line;
        foreach ($extra_headers as $line) $headers[] = $line;

        // Map a low-level _mcp_http_rpc() result to this function's ok/error/data
        // contract, preserving the HTTP>=400 failure signal for the Test button.
        $as_test = function (array $r): array {
            if (isset($r['error']))         return ['ok' => false, 'error' => $r['error'], 'data' => null];
            if (($r['http'] ?? 200) >= 400) return ['ok' => false, 'error' => 'HTTP ' . ($r['http'] ?? '?'), 'data' => $r['result'] ?? null];
            return ['ok' => true, 'error' => null, 'data' => $r['result'] ?? []];
        };

        // Mirror the runtime MCP client (_mcp_jsonrpc): perform the Streamable HTTP
        // handshake so stateful servers are tested the same way they're used —
        // initialize, capture any Mcp-Session-Id, send the initialized
        // notification, then the real method carrying the session. Stateless
        // servers return no session id and the call proceeds directly; a server
        // that doesn't support initialize falls back to a direct call.
        if ($method === 'initialize') {
            return $as_test(_mcp_http_rpc($base_url, $headers, 'initialize', $params, 15));
        }
        $init = _mcp_http_rpc($base_url, $headers, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities'    => (object)[],
            'clientInfo'      => ['name' => 'AstuciaWiki', 'version' => '1.0'],
        ], 15);
        $session = $init['session'] ?? null;
        if (!isset($init['error']) && $session) {
            _mcp_http_rpc($base_url, $headers, 'notifications/initialized', null, 15, $session, true);
            return $as_test(_mcp_http_rpc($base_url, $headers, $method, $params, 15, $session));
        }
        return $as_test(_mcp_http_rpc($base_url, $headers, $method, $params, 15));
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

    require_once __DIR__ . '/wiki_ai_tools.php';

    function trigger_ai_mentions($chat_file, $message_text, $chat_data) {
        global $indexer, $space_dir;
        if (!defined('WIKI_SYSTEM_DATA') || !file_exists(WIKI_SYSTEM_DATA . 'users.json')) return;
        $all_users = json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? [];
        // Same resolver as post_chat_message, so this can never disagree with it about
        // who was addressed. (Nothing calls this function today.)
        $u = wiki_match_ai_mention($message_text, $all_users);
        if ($u === null) return;
        ignore_user_abort(true);
        set_time_limit(0);
        if (session_id()) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        trigger_ai_response($u, $chat_file, $chat_data, $indexer, $space_dir);
    }

    function trigger_ai_response($ai_user, $chat_file, $chat_data, $indexer, $space_dir, $placeholder_id = null) {
        $config        = $ai_user['ai_config']      ?? [];
        $provider      = $config['provider']         ?? 'openai';
        $family        = llm_family($provider);
        $api_url       = $config['api_url']          ?? '';
        if ($api_url === '') $api_url = llm_default_url($provider);
        $api_key       = $config['api_key']          ?? '';
        $model         = $config['model']            ?? 'gpt-4o';
        $system_prompt = _resolve_system_prompt($config);
        $context_msgs  = (int)($config['context_messages'] ?? 10);
        $max_tokens    = (int)($config['max_tokens']       ?? 4096);
        $temperature   = (float)($config['temperature']    ?? 0.7);
        $extra_lines   = _extra_header_lines($config['extra_headers'] ?? []);
        if (!$api_key || !$api_url || !function_exists('curl_init')) return;

        $status_file = $chat_file . '.ai-status.' . (int)$placeholder_id;
        $started_at  = microtime(true);
        $api_call_count = 0;
        $tools_log   = [];
        $write_status = function(string $step, array $extra = []) use ($status_file, &$started_at, &$api_call_count, &$tools_log, $model, $context_msgs) {
            file_put_contents($status_file, json_encode(array_merge([
                'step'             => $step,
                'model'            => $model,
                'context_messages' => $context_msgs,
                'api_calls'        => $api_call_count,
                'tools_used'       => $tools_log,
                'elapsed_ms'       => (int)((microtime(true) - $started_at) * 1000),
            ], $extra)));
        };
        $write_status('preparing');

        $ai_uid     = (int)($ai_user['uid'] ?? -2);
        $space_name = basename($space_dir);
        $chat_name  = basename($chat_file, '.chat');

        // Where the AI is. Without an explicit current folder, models invent a
        // plausible-looking path ("Notes/…") for a new page instead of writing it
        // next to the chat they were asked in — so state the folder and spell out
        // the resulting path, since the tool takes a relative path string.
        $chat_dir_rel = ltrim(str_replace(rtrim($space_dir, '/'), '', dirname($chat_file)), '/');
        // Prepend wiki context so the AI knows where it is and what tools are available.
        // Built by ai_core so the admin panel can show the same text (see
        // admin_ai_builtin_instructions) instead of a copy that drifts.
        $wiki_ctx = wiki_chat_context_prompt($space_name, $chat_name, $chat_dir_rel);

        // The page this chat is attached to, if any. Built by ai_core so the queued-job
        // path (an AI set to always run in the background) gets exactly the same block;
        // kept in its own variable so /debug can price it separately, since it is
        // unbounded and frequently the largest thing in the payload.
        $page_ctx = wiki_page_context_prompt($chat_file, $space_dir);

        $full_system = $wiki_ctx . $page_ctx . $system_prompt;
        $debug_on    = !empty($chat_data['debug']);

        $tools          = get_wiki_tools($provider);
        $mcp_tool_map_c    = [];
        $mcp_calls_c       = [];
        $mcp_server_ids_c  = $config['mcp_server_ids']   ?? [];
        $mcp_instructions_c = $config['mcp_instructions'] ?? [];

        // Explicit "src:<slug>" in the triggering message forces this reply to use
        // ONLY that MCP server's tools (no built-ins, no other enabled servers) —
        // a deterministic alternative to the free-text per-server instructions
        // below. The type-ahead for this lives in chat.js / page_chat.js.
        $_trig_msgs_c   = array_values(array_filter($chat_data['messages'], fn($m) => empty($m['pending']) && empty($m['is_debug'])));
        $forced_slugs_c = _mcp_extract_forced_slugs(end($_trig_msgs_c)['text'] ?? '');

        $_add_mcp_server_tools_c = function(array $mcp_srv_c) use (&$tools, &$mcp_tool_map_c, $family) {
            foreach (_mcp_fetch_tools($mcp_srv_c) as $mt) {
                // Namespaced alias — a remote server (e.g. another AstuciaWiki's
                // mcp.php) can expose tools named identically to our own
                // built-ins, so the raw name can't be used directly.
                $alias = _mcp_tool_alias($mcp_srv_c['name'] ?? 'mcp', $mt['name']);
                if (isset($mcp_tool_map_c[$alias])) continue;
                $mcp_tool_map_c[$alias] = ['server' => $mcp_srv_c, 'real_name' => $mt['name']];
                if ($family === 'anthropic') {
                    $tools[] = ['name' => $alias, 'description' => $mt['description'], 'input_schema' => $mt['params']];
                } elseif ($family === 'openai-responses') {
                    $tools[] = ['type' => 'function', 'name' => $alias, 'description' => $mt['description'], 'parameters' => $mt['params']];
                } else {
                    $tools[] = ['type' => 'function', 'function' => ['name' => $alias, 'description' => $mt['description'], 'parameters' => $mt['params']]];
                }
            }
        };

        // Everything MCP appends to the system prompt lands here first, so /debug can
        // price the servers' guidance apart from the built-in and custom instructions.
        $mcp_extra_c   = '';
        $mcp_srv_count = 0;
        if ($mcp_server_ids_c && defined('WIKI_SYSTEM_DATA')) {
            $mcp_file_c = WIKI_SYSTEM_DATA . 'mcp_servers.json';
            if (file_exists($mcp_file_c)) {
                $all_mcp_c = json_decode(file_get_contents($mcp_file_c), true) ?? [];
                $enabled_c = array_values(array_filter($all_mcp_c, fn($s) => in_array($s['id'] ?? '', $mcp_server_ids_c, true)));
                $forced_c  = $forced_slugs_c ? array_values(array_filter($enabled_c, fn($s) => in_array(_mcp_slug($s['name'] ?? ''), $forced_slugs_c, true))) : [];

                if ($forced_c) {
                    $tools = []; // explicit source(s) referenced — built-ins and other servers excluded
                    foreach ($forced_c as $mcp_srv_c) $_add_mcp_server_tools_c($mcp_srv_c);
                    $mcp_srv_count = count($forced_c);
                    $mcp_extra_c .= "\n\nThe user explicitly requested " . implode(' and ', array_column($forced_c, 'name'))
                        . " via src: — use only the tools available to you for this reply.";
                } else {
                    foreach ($enabled_c as $mcp_srv_c) $_add_mcp_server_tools_c($mcp_srv_c);
                    $mcp_srv_count = count($enabled_c);
                    $mcp_extra_c .= wiki_mcp_guidance($enabled_c, $mcp_instructions_c);
                }
            }
        }
        $full_system .= $mcp_extra_c;

        // /newTopic sentinels, pending placeholders and /debug reports — see
        // wiki_chat_context_slice(), which the two job paths use as well so a queued
        // answer and an inline one see the same thread.
        $recent = wiki_chat_context_slice($chat_data['messages'], $context_msgs);

        // Build initial message list (provider-specific format)
        if ($family === 'anthropic') {
            $messages = [];
            foreach ($recent as $msg) {
                $role = ((int)($msg['uid'] ?? -1) === $ai_uid) ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => ($msg['name'] ?? '') . ': ' . preg_replace('/\bsrc:[a-zA-Z0-9_]+\s*/i', '', $msg['text'] ?? '')];
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
        } elseif ($family === 'openai-responses') {
            // Responses "input": conversation as role/content items; system → "instructions".
            $messages = [];
            foreach ($recent as $msg) {
                $role = ((int)($msg['uid'] ?? -1) === $ai_uid) ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => ($msg['name'] ?? '') . ': ' . preg_replace('/\bsrc:[a-zA-Z0-9_]+\s*/i', '', $msg['text'] ?? '')];
            }
        } else {
            $messages = [['role' => 'system', 'content' => $full_system]];
            foreach ($recent as $msg) {
                $role = ((int)($msg['uid'] ?? -1) === $ai_uid) ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => ($msg['name'] ?? '') . ': ' . preg_replace('/\bsrc:[a-zA-Z0-9_]+\s*/i', '', $msg['text'] ?? '')];
            }
        }

        // /debug accounting: price every block of the first request. Measured here,
        // after the tool list is final (MCP schemas are fetched live) and the history
        // window has been sliced, so the numbers match what actually goes on the wire.
        $debug_blocks = [];
        // /debug also records the whole conversation with the model — see llm_trace.php.
        if ($debug_on) wiki_trace_start();
        $debug_usage  = [];
        if ($debug_on) {
            $hist_chars = '';
            foreach ($messages as $_dm) {
                if (($_dm['role'] ?? '') === 'system') continue; // counted as its own blocks below
                if (is_string($_dm['content'] ?? null)) $hist_chars .= $_dm['content'];
            }
            $tool_names = array_map(
                fn($t) => $t['name'] ?? ($t['function']['name'] ?? '?'),
                $tools
            );
            $mcp_tool_n = count($mcp_tool_map_c);
            $debug_blocks = [
                'Wiki instructions'   => ['tokens' => ai_token_estimate($wiki_ctx),     'note' => 'built-in'],
                'Attached page'       => ['tokens' => ai_token_estimate($page_ctx),     'note' => $page_ctx === '' ? 'none' : $chat_name . '.md'],
                'Custom system prompt'=> ['tokens' => ai_token_estimate($system_prompt), 'note' => ''],
                'MCP guidance'        => ['tokens' => ai_token_estimate($mcp_extra_c),   'note' => $mcp_srv_count . ' server' . ($mcp_srv_count === 1 ? '' : 's')],
                'Tool schemas'        => ['tokens' => ai_token_estimate(json_encode($tools)),
                                          'note'   => count($tool_names) . ' tools, ' . $mcp_tool_n . ' via MCP'],
                'Chat history'        => ['tokens' => ai_token_estimate($hist_chars),
                                          'note'   => count($recent) . ' of last ' . $context_msgs],
            ];
        }

        // Agentic loop: call API → execute tools → repeat until text reply (max 8 iterations)
        $reply        = null;
        $api_error    = null;
        $tools_called = false; // tracks whether any tool has fired this session
        // OpenAI token-limit param + sampling handling (helpers in ai_core.php).
        $openai_token_param  = _openai_token_param($api_url);
        $token_param_swapped = false;
        $drop_temperature    = false;
        // Whether this AI reasons, for the truncation message: reasoning tokens come out
        // of the same ceiling, which is the usual reason a high-effort run returns nothing.
        $_inline_thinking    = wiki_ai_thinking_config($config) !== null;
        // Reserve the final iteration for a tool-free answer: if the model keeps
        // calling tools right up to the cap, forbid tools on the last pass so it
        // must return text — instead of failing with "too many tool calls".
        $max_iters = 10;
        for ($iter = 0; $iter < $max_iters; $iter++) {
            $force_final = ($iter === $max_iters - 1);
            // The AI user's own reasoning effort applies here too: it describes the
            // model, not the delivery route. Note an inline reply is bounded by this
            // request's curl timeout, so a high-effort AI belongs on the queued setting —
            // which is why the two controls sit next to each other in the admin panel.
            $tuning = _llm_tuning_params($family, $model, $temperature, $max_tokens,
                                        wiki_ai_thinking_config($config), $drop_temperature);
            if ($family === 'anthropic') {
                $payload = ['model' => $model, 'system' => $full_system, 'messages' => $messages,
                            'max_tokens' => $max_tokens, 'tools' => $tools];
                $headers = ['Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01'];
            } elseif ($family === 'openai-responses') {
                $payload = ['model' => $model, 'instructions' => $full_system, 'input' => $messages,
                            'max_output_tokens' => $max_tokens, 'tools' => $tools];
                $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
            } else {
                $payload = ['model' => $model, 'messages' => $messages,
                            $openai_token_param => $max_tokens, 'tools' => $tools];
                $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key];
            }
            $payload = array_merge($payload, $tuning);
            if ($extra_lines) $headers = array_merge($headers, $extra_lines);
            // Final pass: forbid tools so the model must produce a text reply.
            if ($force_final) $payload['tool_choice'] = $family === 'anthropic' ? ['type' => 'none'] : 'none';

            $api_call_count++;
            $write_status('calling_api', ['iteration' => $iter + 1]);
            $call_start = microtime(true);
            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers, CURLOPT_TIMEOUT => 120,
                CURLOPT_ENCODING       => '', // advertise gzip/deflate and auto-decode
            ]);
            $raw      = curl_exec($ch);
            $curl_err = curl_error($ch);
            $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $last_call_ms = (int)((microtime(true) - $call_start) * 1000);
            wiki_trace_add([
                'type' => 'llm', 'url' => $api_url, 'http' => $http, 'ms' => $last_call_ms,
                // Redacted here, not at render time: the key must never enter the trace.
                'headers'  => wiki_trace_redact_headers($headers),
                'request'  => json_encode($payload),
                'response' => (string)$raw,
                'error'    => $curl_err ?: null,
            ]);
            $write_status('received', ['iteration' => $iter + 1, 'last_call_ms' => $last_call_ms]);

            if (!$raw) {
                $api_error = ($curl_err ?: 'No response from the API (connection failed or timed out).')
                    . ($http ? " (HTTP {$http})" : '');
                break;
            }
            $data = json_decode($raw, true);
            if (!$data) {
                $api_error = 'The API returned an unreadable response' . ($http ? " (HTTP {$http})" : '') . '.'
                    . _ai_raw_debug($raw, $http);
                break;
            }

            // One capture point for all three wire families, before the family-specific
            // branches — every successful pass adds a row to the /debug usage table.
            if ($debug_on) {
                $_u = ai_usage_tokens($data);
                if ($_u['in'] || $_u['out']) $debug_usage[] = $_u;
            }

            // Detect error responses before attempting to parse content
            if (isset($data['error'])) {
                // OpenAI-compatible: {"error": {"message": "...", "type": "..."}}
                $emsg = $data['error']['message'] ?? 'Unknown API error.';
                // Models disagree about which tuning parameters they accept, and the
                // model_rules table cannot know every gateway alias — so swap the
                // token param, or drop the sampling params, and retry once each.
                if ($family !== 'anthropic' && !$token_param_swapped && _is_token_param_error($emsg)) {
                    $openai_token_param  = $openai_token_param === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                    $token_param_swapped = true;
                    continue;
                }
                if (!$drop_temperature && _is_sampling_param_error($emsg)) {
                    $drop_temperature = true;
                    continue;
                }
                $api_error = $emsg . _ai_raw_debug($raw, $http);
                break;
            }
            if (($data['type'] ?? '') === 'error') {
                // Anthropic: {"type": "error", "error": {"type": "...", "message": "..."}}
                $api_error = ($data['error']['message'] ?? 'Unknown API error.') . _ai_raw_debug($raw, $http);
                break;
            }

            if ($family === 'anthropic') {
                // If the response was truncated by max_tokens, the tool input JSON is incomplete —
                // content will be missing. Report this immediately rather than looping.
                if (($data['stop_reason'] ?? '') === 'max_tokens') {
                    $api_error = wiki_ai_truncated_error($max_tokens, $_inline_thinking);
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
                        $_tname    = $tu['name'] ?? 'unknown';
                        $_is_mcp   = isset($mcp_tool_map_c[$_tname]);
                        $_tdisplay = $_is_mcp ? ($mcp_tool_map_c[$_tname]['server']['name'] ?? '?') . ':' . $mcp_tool_map_c[$_tname]['real_name'] : $_tname;
                        $tools_log[] = $_tdisplay;
                        $write_status('executing_tool', ['tool' => $_tdisplay, 'iteration' => $iter + 1]);
                        if ($_is_mcp) {
                            $mcp_calls_c[] = $_tdisplay;
                            $_tool_result = _mcp_call_tool($mcp_tool_map_c[$_tname]['server'], $mcp_tool_map_c[$_tname]['real_name'], $tu['input'] ?? []);
                        } else {
                            $_tool_result = execute_ai_tool($_tname, $tu['input'] ?? [], $ai_user, $indexer, $space_dir);
                        }
                        $results[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $tu['id'],
                            'content'     => $_tool_result,
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
                $note  = _omitted_content_note($data['content'] ?? [], ['text', 'thinking', 'redacted_thinking', 'tool_use']);
                $reply = $note ? trim($candidate . "\n\n" . $note) : $candidate;
                break;

            } elseif ($family === 'openai-responses') {
                $parsed = _openai_responses_parse($data);
                if ($parsed['tool_calls']) {
                    $tools_called = true;
                    foreach ($parsed['output'] as $it) $messages[] = $it; // echo output items back
                    foreach ($parsed['tool_calls'] as $call) {
                        $_tname    = $call['name'] ?: 'unknown';
                        $_is_mcp   = isset($mcp_tool_map_c[$_tname]);
                        $_tdisplay = $_is_mcp ? ($mcp_tool_map_c[$_tname]['server']['name'] ?? '?') . ':' . $mcp_tool_map_c[$_tname]['real_name'] : $_tname;
                        $tools_log[] = $_tdisplay;
                        $write_status('executing_tool', ['tool' => $_tdisplay, 'iteration' => $iter + 1]);
                        if ($_is_mcp) {
                            $mcp_calls_c[] = $_tdisplay;
                            $_tool_result = _mcp_call_tool($mcp_tool_map_c[$_tname]['server'], $mcp_tool_map_c[$_tname]['real_name'], $call['args']);
                        } else {
                            $_tool_result = execute_ai_tool($_tname, $call['args'], $ai_user, $indexer, $space_dir);
                        }
                        $messages[] = [
                            'type'    => 'function_call_output',
                            'call_id' => $call['call_id'],
                            'output'  => $_tool_result,
                        ];
                    }
                    continue;
                }
                if ($parsed['truncated']) {
                    $api_error = wiki_ai_truncated_error($max_tokens, $_inline_thinking);
                    break;
                }
                $reply = $parsed['text'];
                if (!empty($parsed['omitted'])) $reply = trim($reply . "\n\n" . $parsed['omitted']);
                break;

            } else {
                $choice = $data['choices'][0] ?? [];
                // The third wire family had no truncation check at all, so a run that
                // hit the ceiling reported "No response was generated." See
                // wiki_ai_truncated_error(). Before the tool-call branch, because a
                // truncated response can carry half a tool call.
                if (($choice['finish_reason'] ?? '') === 'length') {
                    $api_error = wiki_ai_truncated_error($max_tokens, $_inline_thinking);
                    break;
                }
                if (($choice['finish_reason'] ?? '') === 'tool_calls') {
                    $tool_calls = $choice['message']['tool_calls'] ?? [];
                    if (!$tool_calls) break;
                    $messages[] = $choice['message'];
                    foreach ($tool_calls as $tc) {
                        $_tname    = $tc['function']['name'] ?? 'unknown';
                        $_is_mcp   = isset($mcp_tool_map_c[$_tname]);
                        $_tdisplay = $_is_mcp ? ($mcp_tool_map_c[$_tname]['server']['name'] ?? '?') . ':' . $mcp_tool_map_c[$_tname]['real_name'] : $_tname;
                        $tools_log[] = $_tdisplay;
                        $write_status('executing_tool', ['tool' => $_tdisplay, 'iteration' => $iter + 1]);
                        $fn_args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
                        if ($_is_mcp) {
                            $mcp_calls_c[] = $_tdisplay;
                            $_tool_result = _mcp_call_tool($mcp_tool_map_c[$_tname]['server'], $mcp_tool_map_c[$_tname]['real_name'], $fn_args);
                        } else {
                            $_tool_result = execute_ai_tool($_tname, $fn_args, $ai_user, $indexer, $space_dir);
                        }
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $tc['id'] ?? '',
                            'content'      => $_tool_result,
                        ];
                    }
                    continue;
                }
                [$reply, $note] = _openai_chat_content($choice['message']['content'] ?? '');
                // Fallback: some models/servers (e.g. Qwen via vLLM) emit a tool
                // call as plain text instead of the structured tool_calls field.
                // Execute it and keep looping rather than posting the raw JSON.
                $fb = _extract_text_tool_call($reply, _tool_names($tools));
                if ($fb) {
                    $_tname    = $fb['name'];
                    $_is_mcp   = isset($mcp_tool_map_c[$_tname]);
                    $_tdisplay = $_is_mcp ? ($mcp_tool_map_c[$_tname]['server']['name'] ?? '?') . ':' . $mcp_tool_map_c[$_tname]['real_name'] : $_tname;
                    $tools_log[] = $_tdisplay;
                    $write_status('executing_tool', ['tool' => $_tdisplay, 'iteration' => $iter + 1]);
                    if ($_is_mcp) {
                        $mcp_calls_c[] = $_tdisplay;
                        $_tool_result = _mcp_call_tool($mcp_tool_map_c[$_tname]['server'], $mcp_tool_map_c[$_tname]['real_name'], $fb['args']);
                    } else {
                        $_tool_result = execute_ai_tool($_tname, $fb['args'], $ai_user, $indexer, $space_dir);
                    }
                    $fb_id = 'fallback_' . $iter;
                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                        'id' => $fb_id, 'type' => 'function',
                        'function' => ['name' => $_tname, 'arguments' => json_encode($fb['args'])],
                    ]]];
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $fb_id, 'content' => $_tool_result];
                    $reply = null;
                    continue;
                }
                if ($note) $reply = trim($reply . "\n\n" . $note);
                break;
            }
        }

        if ($reply && $mcp_calls_c) {
            $reply .= "\n\n---\n*MCP tools used: " . implode(', ', array_unique($mcp_calls_c)) . '*';
        }

        // If no reply was produced, post a visible error message so the user is not left waiting
        if (!$reply) {
            if ($api_error) {
                $reply = '⚠️ ' . $api_error;
            } elseif ($iter >= $max_iters) {
                $reply = '⚠️ Stopped after too many tool calls without producing a response.';
            } else {
                $reply = '⚠️ No response was generated.';
            }
        }

        // Re-read the file to get the latest state (including any messages posted by others
        // while the AI was thinking). If the file was moved while the AI was processing,
        // file_get_contents() returns false and we bail — the pending placeholder stays visible
        // and will time out gracefully in the UI rather than disappearing silently.
        $fresh_raw = file_get_contents($chat_file);
        if ($fresh_raw === false) { @unlink($status_file); return; }
        $fresh = json_decode($fresh_raw, true);
        if (!$fresh) { @unlink($status_file); return; }
        if ($placeholder_id !== null) {
            $replaced = false;
            foreach ($fresh['messages'] as &$_m) {
                if ((int)($_m['id'] ?? -1) === $placeholder_id) {
                    if (empty($_m['pending'])) break; // already cancelled — don't overwrite
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
        // Debug report goes in after the reply so it reads as a footnote to it. It is
        // flagged is_debug, which keeps it out of the context window (see the filters
        // above) and gives it its own bubble style in the client.
        if ($debug_on && $debug_blocks) {
            $fresh['messages'][] = [
                'id'        => $fresh['nextMessageId'],
                'uid'       => (int)($ai_user['uid'] ?? 0),
                'name'      => $ai_user['name'] ?? 'AI',
                'timestamp' => date('c'),
                'is_debug'  => true,
                'text'      => ai_debug_report($debug_blocks, $debug_usage, $model, $api_call_count,
                                               (int)((microtime(true) - $started_at) * 1000), $max_tokens),
            ];
            $fresh['nextMessageId']++;

            // …and the whole conversation goes to a page beside the chat. Written after
            // the reply is in hand so a failed run still leaves the transcript that
            // explains it, and last of all because it must never delay the answer.
            // Who asked, and what they asked: the last message in the context that is not
            // the AI's own. The thread is the only record of it at this point.
            $_req_name = 'Unknown';
            $_req_text = '';
            foreach (array_reverse($recent) as $_rm) {
                if ((int)($_rm['uid'] ?? -1) === $ai_uid) continue;
                $_req_name = (string)($_rm['name'] ?? 'Unknown');
                $_req_text = (string)($_rm['text'] ?? '');
                break;
            }
            $_trace_md = wiki_trace_markdown(
                $_req_name, $ai_user['name'] ?? 'AI', $model, $provider,
                basename(rtrim($space_dir, '/')), basename($chat_file),
                $_req_text,
                ai_debug_report($debug_blocks, $debug_usage, $model, $api_call_count,
                                (int)((microtime(true) - $started_at) * 1000), $max_tokens));
            $_trace_rel = wiki_trace_write_page($space_dir, $chat_file, $_trace_md, $indexer, $ai_user);
            if ($_trace_rel !== null) {
                $fresh['messages'][count($fresh['messages']) - 1]['text'] .=
                    "\n\n*Full call transcript: [" . basename($_trace_rel, '.md') . "](?pageid="
                    . (string)$indexer->getId($_trace_rel) . '&space=' . rawurlencode(basename(rtrim($space_dir, '/'))) . ")*";
            }
            wiki_trace_stop();
        }
        wiki_chat_write($chat_file, $fresh);
        @unlink($status_file);
    }

    $edit_actions  = ['save', 'create_file', 'save_message_page', 'create_folder', 'create_diagram', 'create_list', 'create_chat', 'create_search',
                      'post_chat_message', 'queue_agent_job', 'toggle_chat_debug', 'delete_chat_message', 'cancel_pending_chat_message', 'update_chat_topic', 'purge_chat_messages', 'set_chat_retention', 'toggle_sticky',
                      'create_filesfolder', 'delete', 'move', 'copy_page', 'upload_attachment',
                      'delete_attachment', 'upload_to_folder', 'delete_folder_file', 'update_tags',
                      'save_diagram_svg', 'upload_page', 'create_space', 'rename_space', 'set_git_commit', 'commit_snapshot', 'git_restore',
                      'retarget_wikilinks'];
    $admin_actions = ['admin_chat_retention', 'admin_prompt_gallery',
                      'admin_get_users', 'admin_save_users', 'admin_get_user_requests',
                      'admin_approve_request', 'admin_deny_request',
                      'admin_get_logs', 'admin_get_log_content',
                      'admin_get_error_logs', 'admin_get_error_log_content',
                      'admin_send_test_email', 'admin_get_diag_log',
                      'admin_get_ai_users', 'admin_save_ai_user', 'admin_test_ai_user',
                      'admin_delete_ai_user', 'admin_regenerate_ai_token',
                      'admin_get_api_accounts', 'admin_save_api_account',
                      'admin_delete_api_account', 'admin_regenerate_api_token',
                      'admin_get_agent_jobs', 'admin_save_agent_job', 'admin_delete_agent_job', 'admin_run_agent_job', 'admin_agent_job_status', 'admin_get_agent_job_logs',
                      'admin_get_oneoff_job_log',
                      'git_deleted_files', 'git_restore_deleted',
                      'admin_reindex',
                      'admin_get_mcp_servers', 'admin_save_mcp_server', 'admin_delete_mcp_server', 'admin_test_mcp_server',
                      'admin_realtime_status', 'admin_realtime_test',
                      'admin_audit_config', 'admin_set_audit_enabled', 'admin_get_audit_entries',
                      'admin_ai_builtin_instructions', 'admin_space_settings', 'admin_set_space_readonly', 'admin_merge_space_preflight', 'admin_merge_space'];
    $requested_action = $_REQUEST['action'];
    $current_role     = get_current_role();

    // Audit: describe the target before anything happens to it. A delete removes the
    // index entry, so the id has to be read now or not at all; the whole router is one
    // try/switch/catch, so this one pair of calls covers every content action, from a
    // browser or a service token alike. Returns null unless auditing is on and this
    // action is in scope.
    $_audit_space = rtrim($space_dir, '/') === rtrim(PAGES_DIR, '/') ? '' : basename(rtrim($space_dir, '/'));
    if ($ai_auth_user) {
        wiki_audit_set_context([
            'user'    => $ai_auth_user['name'] ?? null,
            'user_id' => $ai_auth_user['uid'] ?? null,
            'via'     => !empty($ai_auth_user['is_ai']) ? 'ai_token' : 'api_token',
        ]);
    }
    $_audit = wiki_audit_begin($requested_action, $indexer);

    if (in_array($requested_action, $edit_actions) && $current_role === 'reader') {
        wiki_audit_finish($_audit, 'failure', 'readers cannot modify content', null, $_audit_space);
        echo json_encode(['success' => false, 'message' => 'Readers cannot modify content.']);
        exit;
    }
    if (in_array($requested_action, $admin_actions) && $current_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Admin access required.']);
        exit;
    }

    // Read-only Spaces: refuse every content-mutating action, whoever the actor is —
    // administrators included, since the mode exists to freeze a space rather than to
    // hint at it. One rule here covers browser sessions, AI Users, API Accounts and
    // chat posting alike, because every content write is an action in $edit_actions;
    // mcp.php and run_ai_agent_jobs.php reach content by their own routes and carry
    // the same check. Space *management* is exempt: create/rename are settings rather
    // than content, and an admin must be able to rename a frozen space.
    // Read-only is stricter than the reader role, so it needs one action the reader
    // role deliberately allows: a chat reaction rewrites the .chat file. Everything
    // else that writes outside $edit_actions is bookkeeping, not content — the
    // pending-message sweep in chat_messages (which would otherwise leave a
    // placeholder spinning forever) and the addPage() calls in list/get_start_page
    // that give an existing file an id.
    $readonly_actions = array_merge($edit_actions, ['toggle_reaction']);
    if (in_array($requested_action, $readonly_actions, true)
        && !in_array($requested_action, ['create_space', 'rename_space'], true)) {
        $_ro_names = [];
        if (rtrim($space_dir, '/') !== rtrim(PAGES_DIR, '/')) {
            $_ro_names[] = basename(rtrim($space_dir, '/'));
        }
        // move/copy into a frozen space is a write to that space, not to this one.
        $_ro_target = trim($_REQUEST['target_space'] ?? '');
        if ($_ro_target !== '') $_ro_names[] = basename($_ro_target);
        foreach ($_ro_names as $_ro_name) {
            if (!wiki_space_is_readonly($_ro_name)) continue;
            wiki_audit_finish($_audit, 'failure', 'space is read-only', null, $_audit_space);
            echo json_encode([
                'success'        => false,
                'readonly_space' => $_ro_name,
                'message'        => 'The space "' . $_ro_name . '" is read-only.',
            ]);
            exit;
        }
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
                // Size is reported alongside mtime because filemtime has 1-second
                // resolution: a write landing in the same second as the reader's
                // baseline is invisible to mtime alone. Callers that only read
                // "mtime" are unaffected.
                $mtime_path = sanitize_path($_GET['file'] ?? '');
                $mtime_ok   = $mtime_path !== '' && file_exists($mtime_path);
                echo json_encode([
                    'success' => true,
                    'mtime'   => $mtime_ok ? (int)filemtime($mtime_path) : 0,
                    'size'    => $mtime_ok ? (int)filesize($mtime_path)  : 0,
                ]);
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
                                ['name' => 'text', 'in' => 'post', 'required' => true, 'description' => 'Message text (Markdown supported; #AiName triggers an AI user, @Name mentions a person)'],
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
"## Page content\n" .
"\n" .
".md pages are Markdown with these wiki extensions:\n" .
"  ```mermaid fenced block  renders as a diagram (flowchart, sequence, state, ER, gantt).\n" .
"                           Write one instead of ASCII art or an image link.\n" .
"  > [!note] Title          a blockquote starting with [!type] renders as a callout box. Types:\n" .
"                           note, info, abstract, todo, tip, success, question, warning, failure,\n" .
"                           danger, bug, important, example, quote. Suffix the type with - to\n" .
"                           start it collapsed, + to make it foldable but open.\n" .
"  [[Page]] / ![[Page]]     link to, or embed, a page in this space by name. These render,\n" .
"                           but write new links as [Title](?pageid=ID&space=SPACE): that form\n" .
"                           also reaches other spaces and survives the target being renamed.\n" .
"  {include:ID}             embeds the page with that numeric id at this position.\n" .
"  {toc}                    inserts a table of contents built from the page's headings.\n" .
"  {filename} {lastUpdated} substituted when the page renders.\n" .
"Preserve these constructs verbatim when rewriting a page that already uses them.\n" .
"Internal links use [Page Title](?pageid=ID&space=SPACE), never a file path.\n" .
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
"Tip: mention an AI user with #Name in the text to trigger their response (@Name also works).\n" .
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
                // A missing Main.md is respected, not repaired: deleting the start page
                // used to resurrect it on the next load, which made the delete look
                // broken. The client shows an empty view when "exists" is false.
                $start_rel  = 'Main.md';
                $start_file = sanitize_path($start_rel);
                if (!file_exists($start_file)) {
                    // Drop a stale index entry (no-op if absent) so the page doesn't
                    // linger in the ID map when the file was removed outside the app.
                    $indexer->removePage($start_rel);
                    echo json_encode(['success' => true, 'exists' => false, 'path' => null, 'id' => null]);
                    break;
                }
                $start_id = $indexer->getId($start_rel);
                if (!$start_id) $start_id = $indexer->addPage($start_rel);
                echo json_encode(['success' => true, 'exists' => true, 'path' => $start_rel, 'id' => $start_id]);
                break;

            case 'list':
                function get_dir_contents($dir, $indexer, $base_dir) {
                    $items = [];
                    $files = scandir($dir);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..' || substr($file, -8) === '.uploads') continue;
                        if ($file[0] === '.') continue; // skip hidden files/dirs (.git, .gitignore, etc.)
                        // System sidecars at the space root (PageIndexer index + graph cache) are not content.
                        if ($dir === $base_dir && ($file === 'index.json' || $file === 'graph.json')) continue;

                        $path = $dir . '/' . $file;
                        $is_dir = is_dir($path);
                        $extension = pathinfo($path, PATHINFO_EXTENSION);

                        if (!$is_dir && !in_array($extension, ['md', 'drawio', 'list', 'chat', 'search', 'json'])) {
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
                            'tags' => $is_dir ? [] : $indexer->getTags($id),
                            // Read off the index entry that is already in memory rather than
                            // stat()ing every file — the folder view's "Modified" column is the
                            // only consumer, and a whole-tree scandir is expensive enough already.
                            'updated' => $is_dir ? null : ($indexer->getPageData($id)['updated'] ?? null)
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
                    // "size" lets a client baseline exactly the bytes it rendered, which
                    // mtime alone cannot do at 1-second resolution (see file_mtime).
                    echo json_encode(['success' => true, 'data' => $content, 'lastUpdated' => $last_updated, 'size' => strlen($content), 'git_commit' => $git_commit]);
                } else {
                    echo json_encode(['success' => true, 'data' => '', 'lastUpdated' => time(), 'size' => 0, 'git_commit' => true]);
                }
                break;

            case 'chat_messages':
                $cm_path = sanitize_path($_GET['file']);
                if (!file_exists($cm_path) || !is_file($cm_path)) throw new Exception('Chat file not found.');
                $cm_data  = json_decode(file_get_contents($cm_path), true);
                if ($cm_data === null) throw new Exception('Invalid chat file.');
                // Resolve stale pending messages (AI worker died before responding)
                $cm_stale_timeout = 300; // 5 minutes
                $cm_changed = false;
                foreach ($cm_data['messages'] as &$_cm_msg) {
                    if (empty($_cm_msg['pending'])) continue;
                    // A job-backed placeholder is *meant* to sit pending until the cron
                    // runner picks the job up, which is minutes to hours away — so the
                    // 5-minute sweep above must not touch it. The one case it does own is
                    // the one the runner cannot report on: a job nothing will ever start,
                    // because the runner is not running. Left alone that placeholder spins
                    // forever, with no error anywhere, since logging happens in the runner.
                    if (!empty($_cm_msg['job_id'])) {
                        $cm_abandoned = agent_job_abandon_if_stalled((string)$_cm_msg['job_id']);
                        if ($cm_abandoned !== null) {
                            $_cm_msg['text']      = '⚠️ ' . $cm_abandoned;
                            $_cm_msg['timestamp'] = date('c');
                            unset($_cm_msg['pending']);
                            $cm_changed = true;
                        }
                        continue;
                    }
                    $cm_age = time() - strtotime($_cm_msg['timestamp'] ?? '');
                    if ($cm_age > $cm_stale_timeout) {
                        $_cm_msg['text']    = '⚠️ No response was received. The request may have timed out on the server.';
                        $_cm_msg['timestamp'] = date('c');
                        unset($_cm_msg['pending']);
                        $cm_changed = true;
                    }
                }
                unset($_cm_msg);
                if ($cm_changed) file_put_contents($cm_path, json_encode($cm_data, JSON_PRETTY_PRINT));
                $cm_all   = array_values($cm_data['messages'] ?? []);
                // What a background job is currently doing, so the placeholder is right on
                // the first paint instead of showing "Queued…" until the next status poll.
                // Attached to $cm_all and not inside the sweep above: that loop holds
                // $cm_data by reference and writes it back to disk, and a transient status
                // must never be persisted into the .chat file.
                foreach ($cm_all as &$_cm_s) {
                    if (empty($_cm_s['pending']) || empty($_cm_s['job_id'])) continue;
                    $_cm_sf = $cm_path . '.ai-status.' . (int)($_cm_s['id'] ?? 0);
                    if (!is_file($_cm_sf)) continue;
                    $_cm_sd = json_decode((string)@file_get_contents($_cm_sf), true);
                    if (is_array($_cm_sd)) $_cm_s['ai_status'] = $_cm_sd;
                }
                unset($_cm_s);
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
                    // null means the thread has never chosen and follows the wiki default.
                    // '' means the thread chose *off*, which outranks the default — the
                    // dialog cannot offer that distinction unless it can see it.
                    'retention'         => array_key_exists('retention', $cm_data) ? (string)$cm_data['retention'] : null,
                    'retention_default' => wiki_chat_policy_default(),
                ]);
                break;

            case 'get_ai_status':
                $gs_path = sanitize_path($_GET['file']);
                $gs_status_file = $gs_path . '.ai-status.' . (int)($_GET['id'] ?? 0);
                if (!file_exists($gs_status_file)) {
                    echo json_encode(['success' => true, 'data' => null]);
                } else {
                    $gs_data = json_decode(file_get_contents($gs_status_file), true);
                    echo json_encode(['success' => true, 'data' => $gs_data ?: null]);
                }
                break;

            case 'create_diagram':
                $file_path_raw = $_POST['path'];
                $file_path_sanitized = sanitize_path($file_path_raw);
                $tpl_name_dg   = isset($_POST['template']) ? basename($_POST['template']) : 'default';
                $template_path = $space_dir . '/templates/' . $tpl_name_dg . '.drawio';

                if (!file_exists($template_path)) {
                    $template_path = $space_dir . '/templates/default.drawio';
                }
                if (!file_exists($template_path)) {
                    throw new Exception('Default diagram template not found.');
                }

                if (!file_exists($file_path_sanitized)) {
                    if (copy($template_path, $file_path_sanitized)) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'Diagram created.']);
                        if ($search_idx) { try { $search_idx->upsertPage(_sidx_space(), ltrim(str_replace('..','', $file_path_raw),'/'), ''); } catch(\Throwable $_e){} }
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
                        if ($search_idx) { try { $search_idx->upsertPage(_sidx_space(), ltrim(str_replace('..','', $file_path_raw),'/'), ''); } catch(\Throwable $_e){} }
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
                    if (wiki_chat_write($file_path_sanitized, $default_chat)) {
                        $actor = get_current_actor();
                        $indexer->addPage($file_path_raw, $actor['uid'], $actor['name']);
                        echo json_encode(['success' => true, 'message' => 'Chat created.']);
                        if ($search_idx) { try { $search_idx->upsertPage(_sidx_space(), ltrim(str_replace('..','', $file_path_raw),'/'), ''); } catch(\Throwable $_e){} }
                    } else {
                        throw new Exception('Could not create chat file.');
                    }
                } else {
                    throw new Exception('A file with that name already exists.');
                }
                break;

            case 'create_search':
                $search_path_raw = $_POST['path'];
                $search_path_san = sanitize_path($search_path_raw);
                $default_search  = [
                    'query'  => trim($_POST['query'] ?? ''),
                    'source' => trim($_POST['source'] ?? 'wiki'),
                    'title'  => trim($_POST['title'] ?? ''),
                ];
                if (file_exists($search_path_san)) throw new Exception('A file with that name already exists.');
                if (file_put_contents($search_path_san, json_encode($default_search, JSON_PRETTY_PRINT)) === false) {
                    throw new Exception('Could not create search file.');
                }
                $actor = get_current_actor();
                $indexer->addPage($search_path_raw, $actor['uid'], $actor['name']);
                echo json_encode(['success' => true, 'message' => 'Search created.']);
                break;

            case 'post_chat_message':
                $file_path = sanitize_path($_POST['file']);
                $text = trim($_POST['text'] ?? '');
                if (!$text) throw new Exception('Message cannot be empty.');
                if (mb_strlen($text) > 2000) throw new Exception('Message too long (max 2000 characters).');
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $actor = get_current_actor();
                $is_new_topic = (bool)preg_match('/^\/newTopic(\s|$)/i', $text);
                $is_action    = (bool)preg_match('/^\/me(\s|$)/i', $text);

                // A bare /newTopic straight after another one resets a context that is
                // already empty. Drop it rather than stack a second divider in the thread
                // for a message that changes nothing.
                //
                // Both halves must be *bare*. "/newTopic let's discuss X" carries a real
                // message, so a following bare /newTopic does discard it and is not a
                // no-op; and a bare one followed by "/newTopic and now Y" posts Y. Only
                // the exact repeat is redundant. Any message in between — an AI reply
                // included — means the reset now excludes something, so the last message
                // in the thread is the only one worth comparing against.
                $nt_bare = fn($t) => trim(preg_replace('/^\/newTopic\s*/i', '', (string)$t)) === '';
                if ($is_new_topic && $nt_bare($text)) {
                    $prev_msgs = $chat_data['messages'] ?? [];
                    $prev = $prev_msgs ? end($prev_msgs) : null;
                    if ($prev && !empty($prev['is_new_topic']) && $nt_bare($prev['text'] ?? '')) {
                        echo json_encode(['success' => true, 'data' => $chat_data,
                                          'async_ai' => false, 'queued_job' => null, 'ignored' => 'new_topic']);
                        break;
                    }
                }
                $stored_text  = $is_action ? trim(preg_replace('/^\/me\s*/i', '', $text)) : $text;
                $new_msg = [
                    'id'        => $chat_data['nextMessageId'],
                    'uid'       => AUTHENTICATION_ENABLED ? (int)($actor['uid'] ?? 0) : 0,
                    'name'      => AUTHENTICATION_ENABLED ? ($actor['name'] ?? 'Unknown') : 'Local User',
                    'timestamp' => date('c'),
                    'text'      => $stored_text,
                ];
                if ($is_new_topic) $new_msg['is_new_topic'] = true;
                if ($is_action)    $new_msg['is_action']    = true;
                $chat_data['messages'][] = $new_msg;
                $chat_data['nextMessageId']++;

                // Detect first AI @mention and add a pending placeholder now so the
                // client sees a spinner immediately in the initial response.
                $_pending_ai_user       = null;
                $_pending_placeholder_id = null;
                if (!$ai_auth_user && !$is_action && defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
                    // wiki_match_ai_mention() rather than a loop of its own: which AI a
                    // message addresses has to be answered the same way here and in the
                    // routing below, and a per-name \b boundary let an AI whose name is a
                    // prefix of the mentioned one ("gpt120" for "#gpt120-think") claim it.
                    $_aiu = wiki_match_ai_mention($text,
                        json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []);
                    if ($_aiu !== null) {
                        $_pending_placeholder_id = $chat_data['nextMessageId'];
                        $chat_data['messages'][] = [
                            'id'        => $_pending_placeholder_id,
                            'uid'       => (int)($_aiu['uid'] ?? 0),
                            'name'      => $_aiu['name'],
                            'timestamp' => date('c'),
                            'text'      => '',
                            'pending'   => true,
                        ];
                        $chat_data['nextMessageId']++;
                        $_pending_ai_user = $_aiu;
                    }
                }

                // "Always run in the background": the AI answers as a queued one-off job
                // rather than inline. Same destination as /aiJob — the placeholder already
                // written above just needs its job_id, which is also what stops the chat
                // poll's stale-pending sweep from timing it out while it waits its turn.
                $_bg_job = null;
                if ($_pending_ai_user !== null && !empty($_pending_ai_user['ai_config']['always_background'])) {
                    $_bg_space = trim(str_replace(rtrim(PAGES_DIR, '/'), '', rtrim($space_dir, '/')), '/');
                    $_bg_id    = 'oneoff_' . date('Ymd-His') . '_' . bin2hex(random_bytes(3));
                    $_bg_rel   = ltrim(str_replace(rtrim($space_dir, '/'), '', $file_path), '/');
                    $_bg_actor = get_current_actor();
                    $_bg_page_ctx = wiki_page_context_prompt($file_path, $space_dir);

                    // A job is handed only its prompt, while an inline reply sees the
                    // recent thread. Without the transcript, switching this on would make
                    // the assistant answer every follow-up with no idea what came before.
                    $_bg_history = [];
                    $_bg_ctx = wiki_chat_context_slice($chat_data['messages'],
                        (int)($_pending_ai_user['ai_config']['context_messages'] ?? 10));
                    // The message just posted is quoted on its own below.
                    array_pop($_bg_ctx);
                    foreach ($_bg_ctx as $_bm) {
                        $_bg_history[] = ($_bm['name'] ?? '?') . ': ' . (string)($_bm['text'] ?? '');
                    }
                    $_bg_prompt = ($_bg_history
                            ? "Earlier in this chat thread:\n" . implode("\n", $_bg_history) . "\n\n"
                            : '')
                        . 'Latest message from ' . ($new_msg['name'] ?? 'a user') . ': ' . $text;

                    foreach ($chat_data['messages'] as &$_bm2) {
                        if (($_bm2['id'] ?? null) === $_pending_placeholder_id) { $_bm2['job_id'] = $_bg_id; break; }
                    }
                    unset($_bm2);

                    $_bg_ahead = 0;
                    $_bg_mine  = 0;
                    foreach (agent_job_queue_read() as $_bq) {
                        if (($_bq['state'] ?? '') !== 'queued') continue;
                        $_bg_ahead++;
                        if ((int)($_bq['requested_by']['uid'] ?? -1) === (int)($_bg_actor['uid'] ?? 0)) $_bg_mine++;
                    }
                    // The same per-user cap /aiJob enforces. Chat makes it far easier to
                    // reach by accident, so rather than rejecting the message — it is
                    // already in the thread — the placeholder resolves to the reason.
                    if ($_bg_mine >= AGENT_JOB_MAX_QUEUED_PER_USER) {
                        foreach ($chat_data['messages'] as &$_bm3) {
                            if (($_bm3['id'] ?? null) !== $_pending_placeholder_id) continue;
                            unset($_bm3['pending']);
                            $_bm3['text'] = 'You already have ' . AGENT_JOB_MAX_QUEUED_PER_USER
                                . ' background jobs waiting. This one was not queued — let those finish and ask again.';
                            break;
                        }
                        unset($_bm3);
                        wiki_chat_write($file_path, $chat_data);
                        echo json_encode(['success' => true, 'data' => $chat_data,
                                          'async_ai' => false, 'queued_job' => null]);
                        break;
                    }
                    agent_job_queue_mutate(function (array &$jobs) use (
                        $_bg_id, $_bg_actor, $_pending_ai_user, $_bg_space, $_bg_prompt, $_bg_rel,
                        $_pending_placeholder_id, $_bg_page_ctx
                    ) {
                        $jobs[] = [
                            'id'           => $_bg_id,
                            'state'        => 'queued',
                            'created_at'   => date('c'),
                            'requested_by' => ['uid' => $_bg_actor['uid'] ?? 0, 'name' => $_bg_actor['name'] ?? 'Local User'],
                            'ai_user_uid'  => (int)($_pending_ai_user['uid'] ?? 0),
                            'ai_user_name' => $_pending_ai_user['name'] ?? 'AI',
                            'space'        => $_bg_space,
                            'prompt'       => $_bg_prompt,
                            // A page chat's page travels with the job; run_agent_job puts
                            // it in the system prompt, where the inline path has it.
                            'page_context' => $_bg_page_ctx,
                            'reply_to'     => ['chat' => $_bg_rel, 'message_id' => $_pending_placeholder_id],
                            // Queueing says *when* the answer arrives; how hard the model
                            // thinks is the AI user's own setting, the same on all three
                            // run paths. This used to be a hardcoded effort => high, which
                            // made "always run in the background" the most expensive mode
                            // in the product rather than the quick one it was meant to be.
                            'thinking'     => wiki_ai_thinking_config($_pending_ai_user['ai_config'] ?? []),
                            'started_at'   => null, 'finished_at' => null, 'log_file' => null, 'error' => null,
                        ];
                    });
                    $_bg_job = ['job_id' => $_bg_id, 'eta_minutes' => agent_job_eta_minutes($_bg_ahead),
                                'ai_user' => $_pending_ai_user['name'] ?? 'AI'];
                    $_pending_ai_user = null;   // queued, so nothing runs inline
                }

                // Auto-purge rides on the write this request was going to make anyway, so
                // it costs nothing and a trim can never land without the message that
                // triggered it. It runs after the AI placeholder has been appended, which
                // is why the policy protects pending messages carrying a job_id.
                $_purged = wiki_chat_autopurge($chat_data);
                if ($_purged > 0) wiki_audit_chat_purge($file_path, $_purged, 'auto');

                wiki_chat_write($file_path, $chat_data);
                echo json_encode(['success' => true, 'data' => $chat_data,
                                  'async_ai' => $_pending_ai_user !== null, 'queued_job' => $_bg_job]);

                if ($_pending_ai_user !== null) {
                    // Release session lock immediately so chat-poll requests are not blocked
                    // while the AI processes (which can take minutes for complex queries).
                    ignore_user_abort(true);
                    set_time_limit(0);
                    if (session_id()) session_write_close();
                    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                    trigger_ai_response($_pending_ai_user, $file_path, $chat_data, $indexer, $space_dir, $_pending_placeholder_id);
                }
                break;

            case 'chat_retention_preview':
                // What would this policy remove, right now. Auto-purge is silent and
                // irreversible, so the dialog shows a number before anything is enabled.
                $cp_path = sanitize_path($_REQUEST['file'] ?? '');
                $cp_pol  = (string)($_REQUEST['policy'] ?? '');
                $cp_inherit = ($cp_pol === 'inherit');
                if ($cp_inherit) $cp_pol = wiki_chat_policy_default();
                if (!wiki_chat_policy_valid($cp_pol)) throw new Exception('Unknown retention policy.');
                $cp_data = json_decode((string)@file_get_contents($cp_path), true);
                if ($cp_data === null) throw new Exception('Invalid chat file.');
                $cp_msgs = $cp_data['messages'] ?? [];
                [$cp_keep, $cp_gone] = wiki_chat_apply_policy($cp_msgs, $cp_pol);
                echo json_encode(['success' => true,
                    'total' => count($cp_msgs), 'keeps' => count($cp_keep), 'removes' => count($cp_gone),
                    // So the dialog can label "Use the wiki default" with what that is.
                    'default' => wiki_chat_policy_default(), 'resolved' => $cp_pol, 'inherited' => $cp_inherit]);
                break;

            case 'set_chat_retention':
                $cr_path = sanitize_path($_POST['file'] ?? '');
                $cr_pol  = (string)($_POST['policy'] ?? '');
                if ($cr_pol !== 'inherit' && !wiki_chat_policy_valid($cr_pol)) throw new Exception('Unknown retention policy.');
                $cr_data = json_decode((string)@file_get_contents($cr_path), true);
                if ($cr_data === null) throw new Exception('Invalid chat file.');
                if ($cr_pol === 'inherit') {
                    // Removing the key is the only way back to following the wiki default.
                    // Without this a thread is pinned the first time the dialog is saved and
                    // can never inherit again.
                    unset($cr_data['retention']);
                } else {
                    // Stored even when '' — that is the thread saying "off", which has to
                    // outrank a wiki-wide default rather than fall through to it.
                    $cr_data['retention'] = $cr_pol;
                }
                // Applied at once: a policy that did nothing until the next message would
                // look broken on a thread that is already over the limit.
                $cr_gone = wiki_chat_autopurge($cr_data);
                if ($cr_gone > 0) wiki_audit_chat_purge($cr_path, $cr_gone, 'auto');
                wiki_chat_write($cr_path, $cr_data);
                echo json_encode(['success' => true, 'removed' => $cr_gone, 'data' => $cr_data,
                    'retention' => array_key_exists('retention', $cr_data) ? (string)$cr_data['retention'] : null]);
                break;

            case 'admin_prompt_gallery':
                // Fetched here, by the server, and only because an administrator asked —
                // see system_prompt_gallery.php for why that matters.
                $g = wiki_gallery_get(!empty($_REQUEST['refresh']));
                echo json_encode(['success' => true,
                    'prompts' => $g['prompts'], 'source' => $g['source'],
                    'updated' => $g['updated'] ?? null, 'url' => $g['url']]);
                break;

            case 'admin_chat_retention':
                // The wiki-wide default, for threads that have never chosen one.
                if (isset($_POST['policy'])) {
                    $ap = (string)$_POST['policy'];
                    if (!wiki_chat_policy_valid($ap)) throw new Exception('Unknown retention policy.');
                    if (!wiki_setting_set(WIKI_CHAT_POLICY_KEY, $ap)) throw new Exception('Could not save the setting.');
                    wiki_audit_log('update', 'success', ['object' => 'settings.json',
                        'object_type' => 'setting', 'change_type' => 'chat_retention_default', 'value' => $ap]);
                }
                echo json_encode(['success' => true,
                    'policy'  => wiki_chat_policy_default(),
                    'options' => WIKI_CHAT_POLICIES]);
                break;

            case 'toggle_chat_debug':
                // /debug — per-thread context accounting. The flag has to live in the
                // chat file because trigger_ai_response reads it server-side, long after
                // the request that set it has finished. Turning it off sweeps the reports
                // so a thread never accumulates them.
                $td_path = sanitize_path($_POST['file'] ?? '');
                if (!is_file($td_path)) throw new Exception('Chat file not found.');
                $td_data = json_decode(file_get_contents($td_path), true);
                if ($td_data === null) throw new Exception('Invalid chat file.');
                $td_on   = empty($td_data['debug']);
                $td_removed = 0;
                if ($td_on) {
                    $td_data['debug'] = true;
                } else {
                    unset($td_data['debug']);
                    $td_before = count($td_data['messages'] ?? []);
                    $td_data['messages'] = array_values(array_filter($td_data['messages'] ?? [], fn($m) => empty($m['is_debug'])));
                    $td_removed = $td_before - count($td_data['messages']);
                }
                wiki_chat_write($td_path, $td_data);
                echo json_encode(['success' => true, 'debug' => $td_on, 'removed' => $td_removed, 'data' => $td_data]);
                break;

            case 'queue_agent_job':
                // One-off AI job from the chat prompt (/aiJob #AiUser <prompt>).
                // This request only *accepts* the job: it appends the request and a
                // pending placeholder to the thread, queues the work, and answers with
                // an ETA. run_ai_agent_jobs.php executes it and fills the placeholder
                // in — deliberately unlike post_chat_message, which runs the AI inline.
                if (!defined('WIKI_SYSTEM_DATA')) throw new Exception('WIKI_SYSTEM_DATA is not configured.');
                $qj_path = sanitize_path($_POST['file'] ?? '');
                if (!is_file($qj_path)) throw new Exception('Chat file not found.');
                $qj_prompt = trim($_POST['prompt'] ?? '');
                if ($qj_prompt === '') throw new Exception('The job needs a prompt.');
                if (mb_strlen($qj_prompt) > 4000) throw new Exception('Job prompt too long (max 4000 characters).');

                // The AI user is explicit — no focus fallback. An expensive background
                // job should never run on a guess about who was meant.
                $qj_ai_name = ltrim(trim($_POST['ai_user'] ?? ''), '#@');
                if ($qj_ai_name === '') throw new Exception('Specify the AI user to run the job: /aiJob #AiUser <prompt>');
                $qj_users = is_file(WIKI_SYSTEM_DATA . 'users.json')
                    ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []) : [];
                $qj_ai = null;
                foreach ($qj_users as $_qu) {
                    if (empty($_qu['is_ai'])) continue;
                    if (mb_strtolower((string)($_qu['name'] ?? '')) === mb_strtolower($qj_ai_name)) { $qj_ai = $_qu; break; }
                }
                if (!$qj_ai) throw new Exception('No AI user named "' . $qj_ai_name . '".');

                // The job runs later, outside this session — so the AI user's own space
                // grant is what authorises it, checked here while we still can.
                $qj_space = trim(str_replace(rtrim(PAGES_DIR, '/'), '', rtrim($space_dir, '/')), '/');
                $qj_ai_spaces = $qj_ai['spaces'] ?? null;
                if ($qj_ai_spaces !== null && $qj_space !== '' && !in_array($qj_space, (array)$qj_ai_spaces, true)) {
                    throw new Exception('"' . ($qj_ai['name'] ?? 'That AI user') . '" does not have access to this space.');
                }

                $qj_actor = get_current_actor();
                $qj_uid   = AUTHENTICATION_ENABLED ? ($qj_actor['uid'] ?? null) : 0;
                $qj_ahead = 0;
                $qj_mine  = 0;
                foreach (agent_job_queue_read() as $_qq) {
                    if (($_qq['state'] ?? '') !== 'queued') continue;
                    $qj_ahead++;
                    if ((int)($_qq['requested_by']['uid'] ?? -1) === (int)$qj_uid) $qj_mine++;
                }
                if ($qj_mine >= AGENT_JOB_MAX_QUEUED_PER_USER) {
                    throw new Exception('You already have ' . AGENT_JOB_MAX_QUEUED_PER_USER
                        . ' jobs waiting. Let those finish before queuing more.');
                }

                $qj_chat = json_decode((string)file_get_contents($qj_path), true);
                if ($qj_chat === null) throw new Exception('Invalid chat file.');
                $qj_id   = 'oneoff_' . date('Ymd-His') . '_' . bin2hex(random_bytes(3));
                $qj_rel  = ltrim(str_replace(rtrim($space_dir, '/'), '', $qj_path), '/');
                // The same two things the background chat path gives a job, because a job
                // queued from a thread is about that thread whichever way it was asked
                // for. The typed prompt still comes last, so it remains the instruction.
                $qj_page_ctx = wiki_page_context_prompt($qj_path, $space_dir);
                $qj_history  = [];
                foreach (wiki_chat_context_slice($qj_chat['messages'] ?? [],
                             (int)($qj_ai['ai_config']['context_messages'] ?? 10)) as $_qm) {
                    $qj_history[] = ($_qm['name'] ?? '?') . ': ' . (string)($_qm['text'] ?? '');
                }
                if ($qj_history) {
                    $qj_prompt = "Earlier in this chat thread:\n" . implode("\n", $qj_history)
                               . "\n\nThe request: " . $qj_prompt;
                }

                // The request, as the user typed it, so the thread reads normally.
                $qj_chat['messages'][] = [
                    'id'        => $qj_chat['nextMessageId'],
                    'uid'       => AUTHENTICATION_ENABLED ? (int)($qj_actor['uid'] ?? 0) : 0,
                    'name'      => AUTHENTICATION_ENABLED ? ($qj_actor['name'] ?? 'Unknown') : 'Local User',
                    'timestamp' => date('c'),
                    'text'      => '/aiJob #' . ($qj_ai['name'] ?? $qj_ai_name) . ' ' . $qj_prompt,
                ];
                $qj_chat['nextMessageId']++;
                // Placeholder the runner fills in. 'job_id' marks it as job-backed so
                // the chat poll's stale-pending sweep leaves it alone (see chat_messages).
                $qj_msg_id = $qj_chat['nextMessageId'];
                $qj_chat['messages'][] = [
                    'id'        => $qj_msg_id,
                    'uid'       => (int)($qj_ai['uid'] ?? 0),
                    'name'      => $qj_ai['name'] ?? 'AI',
                    'timestamp' => date('c'),
                    'text'      => '',
                    'pending'   => true,
                    'job_id'    => $qj_id,
                ];
                $qj_chat['nextMessageId']++;

                $qj_eta = agent_job_eta_minutes($qj_ahead);
                agent_job_queue_mutate(function (array &$jobs) use (
                    $qj_id, $qj_actor, $qj_uid, $qj_ai, $qj_space, $qj_prompt, $qj_rel, $qj_msg_id,
                    $qj_page_ctx
                ) {
                    $jobs[] = [
                        'id'           => $qj_id,
                        'state'        => 'queued',
                        'created_at'   => date('c'),
                        'requested_by' => ['uid' => $qj_uid, 'name' => $qj_actor['name'] ?? 'Local User'],
                        'ai_user_uid'  => (int)($qj_ai['uid'] ?? 0),
                        'ai_user_name' => $qj_ai['name'] ?? 'AI',
                        'space'        => $qj_space,
                        'prompt'       => $qj_prompt,
                        // The page a page-chat thread is attached to, as the background
                        // chat path has always passed. Without it /aiJob asked in a page
                        // chat could not see the page, while mentioning the same AI in the
                        // same thread could — same destination, different information.
                        'page_context' => $qj_page_ctx,
                        'reply_to'     => ['chat' => $qj_rel, 'message_id' => $qj_msg_id],
                        // Effort is the AI user's setting, not something /aiJob decides:
                        // queuing is about when the answer arrives, not how hard the model
                        // works. See wiki_ai_thinking_config().
                        'thinking'     => wiki_ai_thinking_config($qj_ai['ai_config'] ?? []),
                        'started_at'   => null,
                        'finished_at'  => null,
                        'log_file'     => null,
                        'error'        => null,
                    ];
                });

                // Written only after the job is safely queued: a placeholder with no job
                // behind it would spin until the stale sweep gave up on it.
                wiki_chat_write($qj_path, $qj_chat);
                echo json_encode([
                    'success'     => true,
                    'job_id'      => $qj_id,
                    'eta_minutes' => $qj_eta,
                    'ai_user'     => $qj_ai['name'] ?? 'AI',
                    'queued_ahead' => $qj_ahead,
                    'data'        => $qj_chat,
                ]);
                break;

            case 'ai_explain':
                // Ephemeral, synchronous "explain this selection" — a single tool-free
                // completion returned as text (no .chat file, no placeholder message).
                $ex_text = trim($_POST['text'] ?? '');
                if ($ex_text === '') throw new Exception('No text provided.');
                if (mb_strlen($ex_text) > 4000) $ex_text = mb_substr($ex_text, 0, 4000);
                $ex_uid = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                // Resolve an AI user accessible in the current space (explicit uid wins,
                // else the first is_ai user whose space grant covers this space).
                $ex_users = defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')
                    ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []) : [];
                $ex_space = basename(trim($_REQUEST['space'] ?? ''));
                $ex_ai = null;
                foreach ($ex_users as $_eu) {
                    if (empty($_eu['is_ai'])) continue;
                    if ($ex_uid !== null) { if ((int)($_eu['uid'] ?? -1) === $ex_uid) { $ex_ai = $_eu; break; } continue; }
                    $_esp = $_eu['spaces'] ?? null;
                    if ($_esp === null || $ex_space === '' || in_array($ex_space, (array)$_esp, true)) { $ex_ai = $_eu; break; }
                }
                if (!$ex_ai) throw new Exception('No AI user is available to explain the selection.');
                $ex_page = trim($_POST['page'] ?? '');
                $ex_page = $ex_page !== '' ? preg_replace('/\.[^.]+$/', '', basename($ex_page)) : '';
                $ex_system = 'You are a concise explainer embedded in a wiki reader. Explain or define the user\'s '
                    . 'selected text in 2–4 short sentences of plain prose. Be direct — no preamble, no restating '
                    . 'the request, no Markdown headings.';
                $ex_user = ($ex_page !== '' ? "Selected from the wiki page \"{$ex_page}\".\n\n" : '')
                    . "Selected text:\n\"\"\"\n{$ex_text}\n\"\"\"";
                $ex_res = _ai_quick_reply($ex_ai, $ex_system, $ex_user, 400);
                echo json_encode([
                    'success' => $ex_res['ok'],
                    'reply'   => $ex_res['reply'] ?? null,
                    'ai'      => $ex_ai['name'] ?? '',
                    'message' => $ex_res['error'] ?? null,
                ]);
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
                wiki_chat_write($file_path, $chat_data);
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'cancel_pending_chat_message':
                $file_path = sanitize_path($_POST['file']);
                $msg_id = (int)($_POST['id'] ?? 0);
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $cancelled = false;
                foreach ($chat_data['messages'] as &$_cm) {
                    if ((int)($_cm['id'] ?? -1) === $msg_id && !empty($_cm['pending'])) {
                        $_cm['text'] = '⚠️ Request cancelled.';
                        $_cm['timestamp'] = date('c');
                        unset($_cm['pending']);
                        $cancelled = true;
                        break;
                    }
                }
                unset($_cm);
                if (!$cancelled) throw new Exception('No pending message found with that ID.');
                wiki_chat_write($file_path, $chat_data);
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'update_chat_topic':
                $file_path = sanitize_path($_POST['file']);
                $new_topic = trim($_POST['topic'] ?? '');
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $chat_data['topic'] = $new_topic;
                wiki_chat_write($file_path, $chat_data);
                echo json_encode(['success' => true, 'data' => $chat_data]);
                break;

            case 'purge_chat_messages':
                $file_path = sanitize_path($_POST['file']);
                $keep = max(0, (int)($_POST['keep'] ?? 0));
                $chat_data = json_decode(file_get_contents($file_path), true);
                if ($chat_data === null) throw new Exception('Invalid chat file.');
                $_before = count($chat_data['messages'] ?? []);
                $chat_data['messages'] = $keep === 0 ? [] : array_slice($chat_data['messages'] ?? [], -$keep);
                $_gone = $_before - count($chat_data['messages']);
                if ($_gone > 0) wiki_audit_chat_purge($file_path, $_gone, 'manual');
                wiki_chat_write($file_path, $chat_data);
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
                wiki_chat_write($file_path, $chat_data);
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
                wiki_chat_write($file_path, $chat_data);
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
                    'uid'       => $u['uid']  ?? 0,
                    'name'      => $u['name'] ?? '',
                    'is_ai'     => !empty($u['is_ai']),
                    'is_system' => !empty($u['is_system']),
                    // The composer needs this before it sends: a background AI gets an
                    // ETA toast, not the waiting modal.
                    'always_background' => !empty($u['ai_config']['always_background']),
                ], $all_users));
                echo json_encode(['success' => true, 'data' => $user_list]);
                break;

            case 'get_ai_users_overview':
                // Richer AI-user list for the /aiUsers chat command: model + the MCP
                // servers enabled for each. Non-secret only (no api_url / api_key / token).
                $aio_users_file = WIKI_SYSTEM_DATA . 'users.json';
                if (!defined('WIKI_SYSTEM_DATA') || !file_exists($aio_users_file)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $aio_all   = json_decode(file_get_contents($aio_users_file), true)['users'] ?? [];
                $aio_srv   = _load_mcp_servers();
                $aio_names = [];
                foreach ($aio_srv as $s) { if (!empty($s['id'])) $aio_names[$s['id']] = $s['name'] ?? ''; }
                $aio_out = [];
                foreach ($aio_all as $u) {
                    if (empty($u['is_ai'])) continue;
                    $cfg = $u['ai_config'] ?? [];
                    $ids = $cfg['mcp_server_ids'] ?? [];
                    $servers = [];
                    foreach ((is_array($ids) ? $ids : []) as $id) {
                        if (isset($aio_names[$id]) && $aio_names[$id] !== '') $servers[] = $aio_names[$id];
                    }
                    $aio_out[] = [
                        'name'        => $u['name'] ?? '',
                        'provider'    => $cfg['provider'] ?? '',
                        'model'       => $cfg['model'] ?? '',
                        'mcp_servers' => $servers,
                    ];
                }
                echo json_encode(['success' => true, 'data' => $aio_out]);
                break;

            case 'get_llm_providers':
                // Provider registry (id, label, default_url) for the Admin → AI form.
                echo json_encode(['success' => true, 'data' => array_map(fn($p) => [
                    'id'          => $p['id'],
                    'label'       => $p['label'],
                    'default_url' => $p['default_url'],
                ], llm_providers())]);
                break;

            case 'list_mcp_servers':
                // Non-admin, read-only: name + slug only (no url/token) — used by the
                // src: type-ahead in chat/Page Chat. Whether a given AI User can
                // actually use a server is still gated by its own mcp_server_ids.
                $mcp_list_out = array_map(fn($s) => [
                    'name'        => $s['name'] ?? '',
                    'slug'        => _mcp_slug($s['name'] ?? ''),
                    'wiki_native' => !empty($s['wiki_native']),
                ], _load_mcp_servers());
                echo json_encode(['success' => true, 'data' => array_values($mcp_list_out)]);
                break;

            case 'get_path_from_id':
                $id = $_GET['pageid'];
                $path = $indexer->getPath($id);
                if ($path) {
                    echo json_encode(['success' => true, 'path' => $path, 'id' => $id]);
                } else {
                    // Fallback: search all other accessible spaces so cross-space links work.
                    $_gpfi_allowed = AUTHENTICATION_ENABLED ? actor_spaces_filter(get_current_role(), $ai_auth_user) : null;
                    $found_path  = null;
                    $found_space = null;
                    foreach (scandir(PAGES_DIR) as $_sf) {
                        if ($_sf === '.' || $_sf === '..' || $_sf[0] === '.') continue;
                        if ($_gpfi_allowed !== null && !in_array($_sf, $_gpfi_allowed, true)) continue;
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
            
            case 'retarget_wikilinks':
                // After a page is renamed or moved, `[[Old name]]` elsewhere in the space no
                // longer resolves — a wikilink names its target instead of identifying it. This
                // finds those links and, only when apply=1, rewrites them.
                //
                // The one place wikilink source text is modified, and never without being asked:
                // the client calls this first with apply=0 to get a count for the confirmation.
                $rw_old = ltrim(str_replace('..', '', $_POST['old_path'] ?? $_GET['old_path'] ?? ''), '/');
                $rw_new = ltrim(str_replace('..', '', $_POST['new_path'] ?? $_GET['new_path'] ?? ''), '/');
                $rw_apply = !empty($_POST['apply']) || !empty($_GET['apply']);
                if ($rw_old === '' || $rw_new === '') throw new Exception('old_path and new_path are required.');

                $rw_pages = $indexer->getAllPages();
                $rw_files = [];
                $rw_total = 0;
                foreach ($rw_pages as $rw_id => $rw_data) {
                    $rw_rel = (string)($rw_data['path'] ?? '');
                    // Only Markdown bodies carry wikilinks; a .list or .chat is structured JSON
                    // and rewriting inside it would corrupt the file.
                    if ($rw_rel === '' || strtolower(pathinfo($rw_rel, PATHINFO_EXTENSION)) !== 'md') continue;
                    if ($rw_rel === $rw_new) continue;                 // the renamed page itself
                    $rw_abs = sanitize_path($rw_rel);
                    if (!is_file($rw_abs)) continue;
                    $rw_body = file_get_contents($rw_abs);
                    if (strpos($rw_body, '[[') === false) continue;    // cheap reject
                    [$rw_out, $rw_n] = wikilink_retarget($rw_body, $rw_old, $rw_new);
                    if ($rw_n === 0) continue;
                    $rw_total += $rw_n;
                    $rw_files[] = ['id' => (string)$rw_id, 'path' => $rw_rel, 'links' => $rw_n];
                    if (!$rw_apply) continue;
                    if (file_put_contents($rw_abs, $rw_out) === false) continue;
                    // Same bookkeeping as a normal save, so the change is not invisible to the
                    // index, full-text search or the modified-by column.
                    $rw_actor = get_current_actor();
                    $indexer->updateModified($rw_rel, $rw_actor['uid'], $rw_actor['name']);
                    if ($search_idx) {
                        try { $search_idx->upsertPage(_sidx_space(), $rw_rel, $rw_out); } catch (\Throwable $_e) {}
                    }
                }
                echo json_encode([
                    'success' => true, 'applied' => $rw_apply,
                    'links'   => $rw_total, 'pages' => count($rw_files), 'files' => $rw_files,
                ]);
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
                // A wikilink names its target rather than carrying its id, so the substring
                // search below cannot see one. Resolving every wikilink in a candidate page and
                // comparing ids is what makes an imported Obsidian vault show backlinks at all.
                $bl_resolve = wikilink_resolver($all_pages);
                foreach ($all_pages as $bl_id => $bl_data) {
                    if (!isset($bl_data['path'])) continue;
                    $bl_path = sanitize_path($bl_data['path']);
                    if (!file_exists($bl_path)) continue;
                    $bl_body = file_get_contents($bl_path);
                    if (strpos($bl_body, $pattern) !== false
                        || in_array((string)$target_id, wikilink_ids($bl_body, $bl_resolve), true)) {
                        $backlinks[] = [
                            'id'    => $bl_id,
                            'path'  => $bl_data['path'],
                            'title' => basename($bl_data['path'], '.' . pathinfo($bl_data['path'], PATHINFO_EXTENSION)),
                        ];
                    }
                }
                echo json_encode(['success' => true, 'backlinks' => $backlinks]);
                break;

            case 'get_graph':
                // Full knowledge graph for the current space: page + folder
                // nodes, and reference / containment / affinity edges.
                // Optional focus scoping: ?root=<pageid>&hops=<n> filters to the
                // neighbourhood of one page (used by the per-page focus view).
                $graph = new WikiGraph($space_dir, $indexer);
                $full  = $graph->build();
                $root  = (string)($_GET['root'] ?? '');
                $hops  = max(1, min(4, intval($_GET['hops'] ?? 2)));
                if ($root !== '') {
                    $related = $graph->related($root, $hops);
                    $keep    = [$root => true];
                    foreach ($related as $r) $keep[$r['id']] = true;
                    // Keep folder/other nodes that sit on a kept edge too.
                    foreach ($full['edges'] as $e) {
                        if (isset($keep[$e['source']]) || isset($keep[$e['target']])) {
                            $keep[$e['source']] = true;
                            $keep[$e['target']] = true;
                        }
                    }
                    $full['nodes'] = array_values(array_filter($full['nodes'], fn($n) => isset($keep[$n['id']])));
                    $full['edges'] = array_values(array_filter($full['edges'], fn($e) => isset($keep[$e['source']]) && isset($keep[$e['target']])));
                }
                echo json_encode(['success' => true, 'root' => $root, 'nodes' => $full['nodes'], 'edges' => $full['edges']]);
                break;

            case 'get_related':
                // Related pages for one page, nearest first (see-also / MCP).
                $rel_root = (string)($_GET['pageid'] ?? '');
                $rel_hops = max(1, min(4, intval($_GET['hops'] ?? 1)));
                if ($rel_root === '') { echo json_encode(['success' => true, 'related' => []]); break; }
                $rel_graph = new WikiGraph($space_dir, $indexer);
                echo json_encode(['success' => true, 'related' => $rel_graph->related($rel_root, $rel_hops)]);
                break;

            case 'get_pages_by_tag':
                $tag = $_GET['tag'] ?? '';
                $_gpt_all = !empty($_GET['all_spaces']);

                // Helper: extract header + preview lines from page content.
                $extract_preview = function(string $content): array {
                    $lines   = explode("\n", $content);
                    $header  = '';
                    $preview = [];
                    foreach ($lines as $line) {
                        if ($header === '' && substr(trim($line), 0, 1) === '#') {
                            $header = trim($line);
                        } elseif ($header !== '' && count($preview) < 3 && trim($line) !== '') {
                            $preview[] = trim($line);
                        }
                    }
                    return [$header, implode(' ', $preview)];
                };

                if (!$_gpt_all) {
                    // Current space only.
                    $results = [];
                    foreach ($indexer->getAllPages() as $id => $data) {
                        if (!isset($data['tags']) || !in_array($tag, $data['tags'])) continue;
                        $abs = sanitize_path($data['path']);
                        if (!file_exists($abs)) continue;
                        [$hdr, $prv] = $extract_preview(file_get_contents($abs));
                        $results[] = array_merge(['id' => $id, 'path' => $data['path'], 'header' => $hdr, 'preview' => $prv], page_meta($data));
                    }
                    echo json_encode(['success' => true, 'data' => $results]);
                } else {
                    // All accessible spaces.
                    $_gpt_spaces = [];
                    foreach (scandir(PAGES_DIR) as $_sf) {
                        if ($_sf === '.' || $_sf === '..' || $_sf[0] === '.') continue;
                        if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $_sf)) $_gpt_spaces[] = $_sf;
                    }
                    if (AUTHENTICATION_ENABLED) {
                        $_gpt_allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
                        if ($_gpt_allowed !== null) {
                            $_gpt_spaces = array_values(array_filter($_gpt_spaces, fn($s) => in_array($s, $_gpt_allowed, true)));
                        }
                    }
                    $results = [];
                    foreach ($_gpt_spaces as $_gpt_sp) {
                        $_gpt_dir = rtrim(PAGES_DIR, '/') . '/' . $_gpt_sp;
                        if (!file_exists($_gpt_dir . '/index.json')) continue;
                        $_gpt_idx = new PageIndexer($_gpt_dir);
                        foreach ($_gpt_idx->getAllPages() as $id => $data) {
                            if (!isset($data['tags']) || !in_array($tag, $data['tags'])) continue;
                            $abs = rtrim($_gpt_dir, '/') . '/' . ltrim($data['path'], '/');
                            if (!file_exists($abs)) continue;
                            [$hdr, $prv] = $extract_preview(file_get_contents($abs));
                            $results[] = array_merge(['id' => $id, 'path' => $data['path'], 'space' => $_gpt_sp, 'header' => $hdr, 'preview' => $prv], page_meta($data));
                        }
                    }
                    echo json_encode(['success' => true, 'data' => $results, 'cross_space' => true]);
                }
                break;

            case 'search':
                $query = trim($_GET['query'] ?? '');
                if (empty($query)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }

                if ($search_idx !== null) {
                    // ── SQLite FTS5 path ──────────────────────────────────────
                    $all_spaces_req = !empty($_GET['all_spaces']);

                    // Determine which spaces the user may access.
                    $allowed_spaces = AUTHENTICATION_ENABLED ? actor_spaces_filter(get_current_role(), $ai_auth_user) : null;

                    if (!$all_spaces_req) {
                        // Single-space mode: restrict to the current space.
                        $cur_sp = _sidx_space();
                        $fts_rows = $search_idx->search($query, [$cur_sp], false);
                    } else {
                        $fts_rows = $search_idx->search($query, $allowed_spaces, true);
                    }

                    // Hydrate results with metadata from each space's index.json.
                    $space_indexers = []; // lazy cache
                    $results = [];
                    foreach ($fts_rows as $row) {
                        if (wiki_is_template_path($row['path'])) continue;
                        $sp = $row['space'];
                        if (!isset($space_indexers[$sp])) {
                            $sp_dir = rtrim(PAGES_DIR, '/') . '/' . $sp;
                            if (!is_dir($sp_dir)) continue;
                            $space_indexers[$sp] = new PageIndexer($sp_dir);
                        }
                        $idx   = $space_indexers[$sp];
                        $pg_id = $idx->getId($row['path']);
                        if (!$pg_id) continue;
                        $data  = $idx->getAllPages()[$pg_id] ?? null;
                        if (!$data) continue;

                        $preview = $row['snippet'] !== '…' ? $row['snippet'] : ($row['preview'] ?? '');
                        $results[] = array_merge([
                            'id'      => $pg_id,
                            'path'    => $row['path'],
                            'space'   => $sp,
                            'header'  => $row['title'],
                            'preview' => $preview,
                        ], page_meta($data));
                    }
                    echo json_encode([
                        'success'     => true,
                        'data'        => $results,
                        'engine'      => 'sqlite',
                        'cross_space' => $all_spaces_req,
                    ]);
                } else {
                    // ── Basic (per-file stripos) path ─────────────────────────
                    $all_pages = $indexer->getAllPages();
                    $results = [];
                    foreach ($all_pages as $id => $data) {
                        if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') {
                            continue;
                        }
                        if (wiki_is_template_path($data['path'])) continue;
                        $full_path = sanitize_path($data['path']);
                        if (!file_exists($full_path)) continue;
                        $content = file_get_contents($full_path);

                        $content_pos = stripos($content, $query);
                        $path_pos    = stripos($data['path'], $query);

                        if ($content_pos !== false || $path_pos !== false) {
                            $lines  = explode("\n", $content);
                            $header = '';
                            foreach ($lines as $line) {
                                if (substr(trim($line), 0, 1) === '#') { $header = trim($line); break; }
                            }

                            $preview = '';
                            if ($content_pos !== false) {
                                $start   = max(0, $content_pos - 50);
                                $length  = strlen($query) + 100;
                                $snippet = htmlspecialchars(substr($content, $start, $length));
                                $preview = '...' . preg_replace(
                                    '/' . preg_quote($query, '/') . '/i',
                                    '<mark>$0</mark>', $snippet
                                ) . '...';
                            } else {
                                $pl = [];
                                foreach ($lines as $line) {
                                    if (!empty($header) && count($pl) < 2 && trim($line) !== '') {
                                        $pl[] = trim($line);
                                    }
                                }
                                $preview = htmlspecialchars(implode(' ', $pl));
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
                }
                break;

            case 'advanced_search':
                // Deterministic advanced search powering .search content files.
                // Compact query language (see parse_search_query): free text +
                // tag:<v> + updated:<Nd> + src:<slug>. No LLM.
                $adv_raw    = trim($_REQUEST['q'] ?? '');
                $adv_parsed = parse_search_query($adv_raw);
                if ($adv_parsed['src'] === null && $adv_parsed['text'] === '' && !$adv_parsed['filters']) {
                    echo json_encode(['success' => false, 'message' => 'Empty query. Enter text, tag:<name>, updated:<days>, or src:<source>.']);
                    break;
                }

                // ── MCP source path ─────────────────────────────────────────
                if ($adv_parsed['src'] !== null) {
                    if (get_current_role() === 'reader') {
                        echo json_encode(['success' => false, 'message' => 'Searching MCP sources requires editor access.']);
                        break;
                    }
                    $adv_srv = null;
                    foreach (_load_mcp_servers() as $_asrv) {
                        if (_mcp_slug($_asrv['name'] ?? '') === $adv_parsed['src']) { $adv_srv = $_asrv; break; }
                    }
                    if (!$adv_srv) {
                        echo json_encode(['success' => false, 'message' => "Unknown MCP source '{$adv_parsed['src']}'."]);
                        break;
                    }
                    $adv_native = !empty($adv_srv['wiki_native']);

                    // Structured filters only work against an Astucia Wiki source.
                    if ($adv_parsed['filters'] && !$adv_native) {
                        echo json_encode(['success' => false, 'message' => "The MCP source \"{$adv_srv['name']}\" doesn't support tag: or updated: filters. Remove them, or mark the server as an Astucia Wiki in Admin → AI → MCP Servers."]);
                        break;
                    }

                    if ($adv_native) {
                        // Speak our own dialect: call wiki_search_pages directly.
                        $adv_args = [];
                        if ($adv_parsed['text'] !== '') $adv_args['query'] = $adv_parsed['text'];
                        if ($adv_parsed['days'] > 0)     $adv_args['updated_within_days'] = $adv_parsed['days'];
                        if ($adv_parsed['tags'])         $adv_args['tags'] = $adv_parsed['tags'];
                        $adv_txt = _mcp_call_tool($adv_srv, 'wiki_search_pages', $adv_args);
                        if (str_starts_with($adv_txt, 'Error:')) {
                            echo json_encode(['success' => false, 'message' => $adv_txt]);
                            break;
                        }
                        $adv_rows = json_decode($adv_txt, true);
                        if (is_array($adv_rows)) {
                            echo json_encode(['success' => true, 'mode' => 'wiki', 'source' => $adv_srv['name'], 'data' => $adv_rows]);
                        } else {
                            echo json_encode(['success' => true, 'mode' => 'text', 'source' => $adv_srv['name'], 'text' => $adv_txt]);
                        }
                        break;
                    }

                    // Generic MCP: text-only against a search-like tool.
                    // Hybrid tool selection — use the server's configured search
                    // tool if set, else a name heuristic (exact "search" wins,
                    // then any name matching search/find/query/lookup/retrieve).
                    $adv_tools    = _mcp_fetch_tools($adv_srv);
                    $adv_cfg_tool = trim($adv_srv['search_tool'] ?? '');
                    $adv_cfg_arg  = trim($adv_srv['search_arg'] ?? '');
                    $adv_tool     = null;
                    if ($adv_cfg_tool !== '') {
                        foreach ($adv_tools as $_at) {
                            if (strcasecmp($_at['name'], $adv_cfg_tool) === 0) { $adv_tool = $_at; break; }
                        }
                        if (!$adv_tool) {
                            echo json_encode(['success' => false, 'message' => "Configured search tool \"{$adv_cfg_tool}\" not found on \"{$adv_srv['name']}\"."]);
                            break;
                        }
                    } else {
                        foreach ($adv_tools as $_at) {
                            if (strcasecmp($_at['name'], 'search') === 0) { $adv_tool = $_at; break; }
                        }
                        if (!$adv_tool) {
                            foreach ($adv_tools as $_at) {
                                if (preg_match('/search|find|query|lookup|retrieve/i', $_at['name'])) { $adv_tool = $_at; break; }
                            }
                        }
                        if (!$adv_tool) {
                            echo json_encode(['success' => false, 'message' => "No search tool found on \"{$adv_srv['name']}\". Set one under this server in Admin → AI → MCP Servers."]);
                            break;
                        }
                    }
                    // Pick the argument to receive the text: configured arg wins,
                    // else "query", else the tool's first property.
                    $adv_props = $adv_tool['params']['properties'] ?? [];
                    if (is_object($adv_props)) $adv_props = (array)$adv_props;
                    $adv_argname = $adv_cfg_arg !== '' ? $adv_cfg_arg
                        : (isset($adv_props['query']) ? 'query' : (array_key_first($adv_props) ?: 'query'));
                    $adv_txt = _mcp_call_tool($adv_srv, $adv_tool['name'], [$adv_argname => $adv_parsed['text']]);
                    echo json_encode([
                        'success' => !str_starts_with($adv_txt, 'Error:'),
                        'mode'    => 'text',
                        'source'  => $adv_srv['name'],
                        'tool'    => $adv_tool['name'],
                        'text'    => $adv_txt,
                        'message' => str_starts_with($adv_txt, 'Error:') ? $adv_txt : null,
                    ]);
                    break;
                }

                // ── Current wiki path ───────────────────────────────────────
                $adv_data = wiki_search_pages($adv_parsed['text'], $indexer, $space_dir, $adv_parsed['days'], $adv_parsed['tags']);
                echo json_encode(['success' => true, 'mode' => 'wiki', 'source' => _sidx_space(), 'data' => $adv_data]);
                break;

            case 'advanced_search_read':
                // Fetch a single remote page from a wiki-native MCP source (for the
                // Advanced Search result lightbox). Editor+, like remote searching.
                if (get_current_role() === 'reader') {
                    echo json_encode(['success' => false, 'message' => 'Reading remote pages requires editor access.']);
                    break;
                }
                $asr_slug  = strtolower(trim($_REQUEST['src'] ?? ''));
                $asr_path  = ltrim(str_replace('..', '', $_REQUEST['path'] ?? ''), '/');
                $asr_space = trim($_REQUEST['remote_space'] ?? '');
                if ($asr_path === '') {
                    echo json_encode(['success' => false, 'message' => 'Missing page path.']);
                    break;
                }
                $asr_srv = null;
                foreach (_load_mcp_servers() as $_rs) {
                    if (_mcp_slug($_rs['name'] ?? '') === $asr_slug) { $asr_srv = $_rs; break; }
                }
                if (!$asr_srv) {
                    echo json_encode(['success' => false, 'message' => "Unknown MCP source '{$asr_slug}'."]);
                    break;
                }
                if (empty($asr_srv['wiki_native'])) {
                    echo json_encode(['success' => false, 'message' => "\"{$asr_srv['name']}\" is not an Astucia Wiki source."]);
                    break;
                }
                $asr_txt = _mcp_call_tool($asr_srv, 'wiki_read_page', ['path' => $asr_path], $asr_space);
                if (str_starts_with($asr_txt, 'Error:')) {
                    echo json_encode(['success' => false, 'message' => $asr_txt]);
                    break;
                }
                echo json_encode(['success' => true, 'content' => $asr_txt, 'path' => $asr_path, 'source' => $asr_srv['name']]);
                break;

            case 'mcp_list_tools':
                // Editor+ MCP Tool Explorer: list a server's tools with schemas.
                if (get_current_role() === 'reader') {
                    echo json_encode(['success' => false, 'message' => 'MCP Tool Explorer requires editor access.']);
                    break;
                }
                $mlt_slug = strtolower(trim($_REQUEST['source'] ?? ''));
                $mlt_srv  = null;
                foreach (_load_mcp_servers() as $_ms) {
                    if (_mcp_slug($_ms['name'] ?? '') === $mlt_slug) { $mlt_srv = $_ms; break; }
                }
                if (!$mlt_srv) { echo json_encode(['success' => false, 'message' => 'Unknown MCP source.']); break; }
                echo json_encode(['success' => true, 'source' => $mlt_srv['name'], 'tools' => _mcp_fetch_tools($mlt_srv)]);
                break;

            case 'mcp_invoke_tool':
                // Editor+ MCP Tool Explorer: call one tool with JSON arguments.
                if (get_current_role() === 'reader') {
                    echo json_encode(['success' => false, 'message' => 'MCP Tool Explorer requires editor access.']);
                    break;
                }
                $mit_slug = strtolower(trim($_POST['source'] ?? ''));
                $mit_tool = trim($_POST['tool'] ?? '');
                $mit_args = json_decode($_POST['arguments'] ?? '{}', true);
                if (!is_array($mit_args)) $mit_args = [];
                if (!$mit_tool) { echo json_encode(['success' => false, 'message' => 'Tool name is required.']); break; }
                $mit_srv = null;
                foreach (_load_mcp_servers() as $_ms) {
                    if (_mcp_slug($_ms['name'] ?? '') === $mit_slug) { $mit_srv = $_ms; break; }
                }
                if (!$mit_srv) { echo json_encode(['success' => false, 'message' => 'Unknown MCP source.']); break; }
                $mit_txt = _mcp_call_tool($mit_srv, $mit_tool, $mit_args);
                echo json_encode([
                    'success' => !str_starts_with($mit_txt, 'Error:'),
                    'text'    => $mit_txt,
                    'message' => str_starts_with($mit_txt, 'Error:') ? $mit_txt : null,
                ]);
                break;

            case 'get_mentions':
                // Every space the user can read, pages and chat threads alike — see
                // mentions.php for what counts and how "new" is decided.
                $mention_name = trim($_GET['name'] ?? '');
                $mention_uid  = (int)($_GET['uid'] ?? 0);
                $mn_since     = wiki_mentions_since($mention_uid);
                $mn_allowed   = AUTHENTICATION_ENABLED
                    ? actor_spaces_filter(get_current_role(), $ai_auth_user) : null;
                echo json_encode([
                    'success' => true,
                    'data'    => wiki_scan_mentions($mention_name, $mention_uid, $mn_allowed, $mn_since),
                    'since'   => $mn_since,
                ]);
                break;

            case 'realtime_ticket': {
                // A subscriber ticket for whoever is asking. The response says whether
                // realtime is available at all, so the client can decide between
                // subscribing and staying on its poller without a second round trip.
                if (!wiki_realtime_enabled()) {
                    echo json_encode(['success' => true, 'enabled' => false]);
                    break;
                }
                $rt_role    = AUTHENTICATION_ENABLED ? get_current_role() : 'admin';
                $rt_allowed = AUTHENTICATION_ENABLED
                    ? actor_spaces_filter($rt_role, $ai_auth_user) : null;
                $rt_actor   = get_current_actor();
                $rt_uid     = (int)($rt_actor['uid'] ?? 0);
                $rt_token   = wiki_realtime_subscribe_token($rt_allowed, $rt_uid);

                // A browser gets the ticket in a cookie the hub reads for itself: a query
                // parameter would put a bearer credential into every access log and
                // Referer. A token client has no cookie jar, so it is told the value and
                // sends it as a bearer header.
                if (!$ai_auth_user) {
                    setcookie('mercureAuthorization', $rt_token, [
                        'expires'  => time() + wiki_realtime_ticket_ttl(),
                        'path'     => wiki_realtime_public_url(),
                        'secure'   => !empty($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                }
                echo json_encode([
                    'success'  => true,
                    'enabled'  => true,
                    'url'      => wiki_realtime_public_url(),
                    'uid'      => $rt_uid,
                    'ttl'      => wiki_realtime_ticket_ttl(),
                    // Only a token client needs the value; a browser already has the cookie
                    // and handing the same credential to JavaScript would put it in reach
                    // of any XSS on the page.
                    'token'    => $ai_auth_user ? $rt_token : null,
                ]);
                break;
            }

            case 'get_mention_count':
                // The sidebar badge. Runs on every page load, so it takes the cheap
                // path: index.json timestamps rule out untouched files before any of
                // them is opened.
                $mc_name    = trim($_GET['name'] ?? '');
                $mc_uid     = (int)($_GET['uid'] ?? 0);
                $mc_since   = wiki_mentions_since($mc_uid);
                $mc_allowed = AUTHENTICATION_ENABLED
                    ? actor_spaces_filter(get_current_role(), $ai_auth_user) : null;
                // Without a marker every mention would read as new, which on an
                // established wiki is a badge nobody can clear.
                $mc_rows = $mc_since > 0
                    ? wiki_scan_mentions($mc_name, $mc_uid, $mc_allowed, $mc_since, true) : [];
                // The newest one rides along so a client polling this can say where the
                // mention is, not just that the number went up. Rows are sorted newest
                // first, and the scan has already read it — no extra work.
                $mc_latest = null;
                if ($mc_rows) {
                    $mc_top = $mc_rows[0];
                    $mc_latest = [
                        'id'     => $mc_top['id'],
                        'space'  => $mc_top['space'],
                        'path'   => $mc_top['path'],
                        // The row keeps the raw heading line for the results list; a
                        // toast wants the title, not "# Q3 Report".
                        'header' => ltrim($mc_top['header'], "# \t"),
                    ];
                }
                echo json_encode([
                    'success' => true,
                    'count'   => count($mc_rows),
                    'since'   => $mc_since,
                    'latest'  => $mc_latest,
                ]);
                break;

            case 'mark_mentions_seen':
                // Not an $edit_action: this is the reader's own state, not content, and
                // a reader has mentions too.
                $ms_uid = (int)($_POST['uid'] ?? 0);
                $ms_me  = (int)(get_current_actor()['uid'] ?? 0);
                if ($ms_uid <= 0 || ($ms_me > 0 && $ms_uid !== $ms_me)) {
                    throw new Exception('Can only mark your own mentions as seen.');
                }
                // One second back, deliberately. Index stamps come from filemtime, which
                // has 1-second resolution, so a mention written in the same second as
                // this click would compare equal to the marker and be skipped for good.
                // Erring one second early can only re-show something already read — and
                // that clears the next time the panel is opened; erring late loses a
                // mention silently and forever.
                wiki_user_set_stamp($ms_uid, 'mentionsSeenAt', time() - 1);
                echo json_encode(['success' => true, 'count' => 0]);
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
                if ($ext_save === 'json') {
                    // Data pages must stay well-formed; validate and normalise before writing
                    // (text-mode edits can produce invalid JSON — the grid/tree modes can't).
                    $decoded_save = json_decode($content, true);
                    if ($decoded_save === null && strtolower(trim($content)) !== 'null') {
                        throw new Exception('Invalid JSON: ' . json_last_error_msg());
                    }
                    $content = json_encode($decoded_save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                if (file_put_contents($file_path, $content) !== false) {
                    $actor = get_current_actor();
                    $indexer->updateModified($_GET['file'], $actor['uid'], $actor['name']);
                    // Report the bytes just written, so the client that wrote them can
                    // re-baseline the open-page watcher. Without this the watcher still
                    // holds the stamp from page load, sees its own save as an external
                    // change on the next poll, and reloads the page under the author.
                    // clearstatcache() is required: PHP caches stat results per request,
                    // so filemtime() here can otherwise return the mtime from before
                    // this write. `size` counts $content after the .json re-encode above,
                    // which is what actually landed on disk.
                    clearstatcache(true, $file_path);
                    echo json_encode([
                        'success'     => true,
                        'message'     => 'File saved successfully.',
                        'lastUpdated' => filemtime($file_path),
                        'size'        => strlen($content),
                    ]);
                    if ($search_idx && in_array($ext_save, ['md', 'json'], true)) {
                        try { $search_idx->upsertPage(_sidx_space(), $rel_save, $content); } catch (\Throwable $_e) {}
                    }
                    if (in_array($ext_save, ['md', 'drawio', 'json'], true) && $indexer->getGitCommit($rel_save, true)) {
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

            case 'list_drawio_templates':
                $tpl_dir_dg = rtrim($space_dir, '/') . '/templates';
                $tpl_names_dg = [];
                if (is_dir($tpl_dir_dg)) {
                    foreach (glob($tpl_dir_dg . '/*.drawio') as $f) {
                        $tpl_names_dg[] = pathinfo($f, PATHINFO_FILENAME);
                    }
                    sort($tpl_names_dg);
                    $di_dg = array_search('default', $tpl_names_dg);
                    if ($di_dg !== false) {
                        array_splice($tpl_names_dg, $di_dg, 1);
                        array_unshift($tpl_names_dg, 'default');
                    }
                }
                echo json_encode(['success' => true, 'templates' => $tpl_names_dg]);
                break;

            case 'share_page':
                if (!is_mail_configured()) {
                    echo json_encode(['success' => false, 'message' => 'Email is not configured.']);
                    break;
                }
                if (AUTHENTICATION_ENABLED && !isset($_SESSION['user'])) {
                    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
                    break;
                }
                $sh_subject     = trim($_POST['subject'] ?? '');
                $sh_to          = $_POST['to'] ?? 'everyone';
                $sh_include_self = !empty($_POST['include_self']) && $_POST['include_self'] === '1';
                $sh_message     = trim($_POST['message'] ?? '');
                $sh_page_id     = trim($_POST['page_id'] ?? '');
                $sh_sender_name = $_SESSION['user']['name'] ?? 'Someone';
                $sh_current_uid = (int)($_SESSION['user']['uid'] ?? 0);

                $sh_users_file = defined('WIKI_SYSTEM_DATA') ? WIKI_SYSTEM_DATA . 'users.json' : '';
                $sh_all_users  = ($sh_users_file && file_exists($sh_users_file))
                    ? (json_decode(file_get_contents($sh_users_file), true)['users'] ?? [])
                    : [];
                $sh_human = array_values(array_filter($sh_all_users, fn($u) =>
                    empty($u['is_ai']) && empty($u['is_system']) && !empty($u['email'])
                ));

                if ($sh_to === 'everyone') {
                    $sh_recipients = $sh_human;
                } else {
                    $sh_uids = json_decode($sh_to, true) ?? [];
                    $sh_recipients = array_values(array_filter($sh_human, fn($u) => in_array((int)($u['uid'] ?? 0), $sh_uids, true)));
                }


                $sh_scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $sh_base     = $sh_scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                $sh_space    = $_POST['space'] ?? '';
                $sh_qs       = $sh_page_id ? 'pageid=' . urlencode($sh_page_id) : '';
                if ($sh_space) $sh_qs .= ($sh_qs ? '&' : '') . 'space=' . urlencode($sh_space);
                $sh_page_url = $sh_base . '/index.php' . ($sh_qs ? '?' . $sh_qs : '');

                $sh_sender_html  = htmlspecialchars($sh_sender_name);
                $sh_app_html     = htmlspecialchars(APP_TITLE);
                $sh_msg_html = '';
                if ($sh_message) {
                    // Split on URLs before escaping so & in query strings isn't mangled
                    $sh_parts  = preg_split('#(https?://\S+)#i', $sh_message, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $sh_linked = '';
                    foreach ($sh_parts as $sh_i => $sh_part) {
                        if ($sh_i % 2 === 0) {
                            $sh_linked .= htmlspecialchars($sh_part, ENT_QUOTES, 'UTF-8');
                        } else {
                            $sh_href    = htmlspecialchars($sh_part, ENT_QUOTES, 'UTF-8');
                            $sh_linked .= '<a href="' . $sh_href . '">' . $sh_href . '</a>';
                        }
                    }
                    $sh_msg_html = '<p style="white-space:pre-wrap">' . $sh_linked . '</p>';
                }
                $sh_body         = "<p><strong>{$sh_sender_html}</strong> shared a page with you on <strong>{$sh_app_html}</strong>.</p>"
                                 . $sh_msg_html;

                $sh_sent = 0; $sh_failed = 0;
                foreach ($sh_recipients as $sh_r) {
                    if (send_email(wiki_user_notify_email($sh_r), $sh_r['name'] ?? '', $sh_subject, $sh_body)) {
                        $sh_sent++;
                    } else {
                        $sh_failed++;
                    }
                }
                echo json_encode(['success' => true, 'sent' => $sh_sent, 'failed' => $sh_failed]);
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
                        if ($search_idx) { try { $search_idx->upsertPage(_sidx_space(), ltrim(str_replace('..','', $file_path_raw),'/'), $content); } catch(\Throwable $_e){} }
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

            case 'save_message_page':
                // Save a chat message's text as a markdown page ('create') or
                // append it to a page ('append'). Target space is resolved the
                // usual way from the request's `space` param, so this works
                // cross-space. The folder/filename come in via `path`.
                $smp_path_raw = trim($_POST['path'] ?? '');
                $smp_text     = (string)($_POST['text'] ?? '');
                $smp_mode     = (($_POST['mode'] ?? 'create') === 'append') ? 'append' : 'create';
                if ($smp_path_raw === '') { echo json_encode(['success' => false, 'message' => 'A filename is required.']); break; }
                if (!preg_match('/\.md$/i', $smp_path_raw)) $smp_path_raw .= '.md';

                $smp_abs    = sanitize_path($smp_path_raw);
                $smp_exists = file_exists($smp_abs);

                if ($smp_mode === 'create' && $smp_exists) {
                    echo json_encode(['success' => false, 'message' => 'A page with that name already exists.']);
                    break;
                }

                if ($smp_mode === 'append' && $smp_exists) {
                    $smp_existing = (string)file_get_contents($smp_abs);
                    $smp_content  = rtrim($smp_existing, "\n") . "\n\n" . $smp_text . "\n";
                } else {
                    // New page, or "append" to a page that doesn't exist yet.
                    $smp_dir = dirname($smp_abs);
                    if (!is_dir($smp_dir)) { echo json_encode(['success' => false, 'message' => 'Destination folder does not exist.']); break; }
                    $smp_content = $smp_text . "\n";
                }

                if (file_put_contents($smp_abs, $smp_content) === false) {
                    throw new Exception('Failed to save page.');
                }

                $actor = get_current_actor();
                if ($smp_exists) $indexer->updateModified($smp_path_raw, $actor['uid'], $actor['name']);
                else             $indexer->addPage($smp_path_raw, $actor['uid'], $actor['name']);

                echo json_encode(['success' => true, 'message' => 'Saved.', 'path' => $smp_path_raw, 'created' => !$smp_exists]);

                $smp_rel = ltrim(str_replace('..', '', $smp_path_raw), '/');
                if ($search_idx) { try { $search_idx->upsertPage(_sidx_space(), $smp_rel, $smp_content); } catch (\Throwable $_e) {} }
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                $git_name  = $actor['name'] ?? 'Wiki';
                $git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email'])) ? $_SESSION['user']['email'] : 'wiki@localhost';
                git_auto_commit($smp_abs, $git_name, $git_email, ($smp_exists ? 'Append to ' : 'Create ') . basename($smp_path_raw));
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
                $path_raw = $_POST['path'] ?? '';
                $path_sanitized = sanitize_path($path_raw);
                // A missing, empty or dot-only path resolves to the Space root, and this
                // action deletes recursively — so `?action=delete` with no parameter at all
                // used to wipe an entire Space and report success. Note that sanitize_path()
                // strips '..' to nothing, so `path=..` and `path=/` arrive here the same
                // way; comparing the *resolved* path against the root catches all of them
                // at once, where checking the raw string for emptiness would not.
                $_del_root = rtrim(realpath($space_dir) ?: $space_dir, '/');
                $_del_real = rtrim(realpath($path_sanitized) ?: $path_sanitized, '/');
                if (trim((string)$path_raw) === '' || $_del_real === $_del_root) {
                    echo json_encode(['success' => false, 'message' => 'No file specified.']);
                    break;
                }
                function delete_recursive($dir, $indexer, $base_dir, $sidx = null) {
                    if (!is_dir($dir)) {
                        $rel = str_replace($base_dir . '/', '', $dir);
                        $indexer->removePage($rel);
                        if ($sidx) {
                            try { $sidx->deletePage(basename(rtrim($base_dir, '/')), $rel); } catch (\Throwable $_e) {}
                        }
                        // Also delete associated uploads folder if it exists
                        $uploads_dir = $dir . '.uploads';
                        if (is_dir($uploads_dir)) {
                            delete_recursive($uploads_dir, $indexer, $base_dir, $sidx);
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
                        if (!delete_recursive($dir . DIRECTORY_SEPARATOR . $item, $indexer, $base_dir, $sidx)) return false;
                    }
                    return rmdir($dir);
                }

                if (file_exists($path_sanitized)) {
                    if (delete_recursive($path_sanitized, $indexer, $space_dir, $search_idx)) {
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
                $src_space_name = _sidx_space();
                $tgt_space_name = ($is_cross_space && $target_space_name !== '')
                    ? basename($target_space_name) : $src_space_name;

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
                                if ($search_idx) {
                                    try { $search_idx->movePageCrossSpace($src_space_name, $ppath, $tgt_space_name, $new_entry_rel); } catch (\Throwable $_e) {}
                                }
                            }
                        } else {
                            $indexer->updateFolderPath($old_path_raw, $new_path_raw);
                            if ($search_idx) {
                                $old_pfx = ltrim(str_replace('..', '', $old_path_raw), '/') . '/';
                                $new_pfx = ltrim(str_replace('..', '', $new_path_raw), '/') . '/';
                                try { $search_idx->moveFolderPaths($src_space_name, $old_pfx, $new_pfx); } catch (\Throwable $_e) {}
                            }
                        }
                    } else {
                        $old_rel = ltrim(str_replace('..', '', $old_path_raw), '/');
                        if ($is_cross_space) {
                            $actor = get_current_actor();
                            $target_indexer->addPage($new_path_rel, $actor['uid'], $actor['name']);
                            $indexer->removePage($old_rel);
                            if ($search_idx) {
                                try { $search_idx->movePageCrossSpace($src_space_name, $old_rel, $tgt_space_name, $new_path_rel); } catch (\Throwable $_e) {}
                            }
                        } else {
                            $indexer->updatePath($old_path_raw, $new_path_raw);
                            if ($search_idx) {
                                $new_rel = ltrim(str_replace('..', '', $new_path_raw), '/');
                                try { $search_idx->movePage($src_space_name, $old_rel, $new_rel); } catch (\Throwable $_e) {}
                            }
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
                    if ($search_idx) {
                        $cp_space = ($target_space_name !== '') ? basename($target_space_name) : _sidx_space();
                        $cp_ext   = pathinfo($new_path_rel, PATHINFO_EXTENSION);
                        $cp_raw   = ($cp_ext === 'md') ? (file_get_contents($new_path_sanitized) ?: '') : '';
                        try { $search_idx->upsertPage($cp_space, $new_path_rel, $cp_raw); } catch (\Throwable $_e) {}
                    }
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

            case 'upload_page':
                // Drag-and-drop upload of Markdown pages onto a folder in the file tree.
                // Deliberately narrow: .md only. That keeps anything executable out of
                // PAGES_DIR even if that directory is ever served directly, and means an
                // uploaded file is always something the wiki can actually render.
                if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new Exception('Upload failed.');
                }
                if (!is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
                    throw new Exception('Not an uploaded file.');
                }
                // The extension is checked on the FINAL name, after basename() and after
                // stripping separators and control characters — so "notes.md.php" is
                // rejected on its real extension rather than passing on a middle segment.
                $up_name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string)$_FILES['file']['name']));
                $up_name = trim(str_replace(['/', '\\'], '', $up_name));
                if ($up_name === '' || $up_name[0] === '.') throw new Exception('Invalid file name.');
                if (strtolower(pathinfo($up_name, PATHINFO_EXTENSION)) !== 'md') {
                    throw new Exception('Only .md files can be uploaded as pages.');
                }

                $up_folder_rel = trim(str_replace('..', '', (string)($_POST['folder'] ?? '')), '/');
                $up_dir = $up_folder_rel === '' ? rtrim($space_dir, '/') : sanitize_path($up_folder_rel);
                if (!is_dir($up_dir)) throw new Exception('Target folder not found.');

                // Never overwrite. A drop that silently replaced a page would be
                // unrecoverable without git, and the gesture is too easy to make by
                // accident; the "name (1)" convention matches the space merge.
                $up_final = $up_name;
                $up_n     = 0;
                while (file_exists($up_dir . '/' . $up_final)) {
                    $up_final = pathinfo($up_name, PATHINFO_FILENAME) . ' (' . (++$up_n) . ').md';
                }

                $up_abs = $up_dir . '/' . $up_final;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $up_abs)) {
                    throw new Exception('Could not save the uploaded file.');
                }
                @chmod($up_abs, 0644);

                // Same four steps as create_file: without them the page has no id, is
                // missing from search, and never reaches git.
                $up_rel   = ($up_folder_rel === '' ? '' : $up_folder_rel . '/') . $up_final;
                $up_actor = get_current_actor();
                $indexer->addPage($up_rel, $up_actor['uid'], $up_actor['name']);
                // The audit hook could not know the name: it is chosen here, and a
                // collision changes it. Recorded after addPage() so the id resolves too.
                wiki_audit_object($_audit, $up_rel);
                echo json_encode([
                    'success' => true,
                    'path'    => $up_rel,
                    'name'    => $up_final,
                    'renamed' => $up_final !== $up_name,
                ]);
                if ($search_idx) {
                    try { $search_idx->upsertPage(_sidx_space(), $up_rel, (string)@file_get_contents($up_abs)); }
                    catch (\Throwable $_e) {}
                }
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                $up_git_name  = $up_actor['name'] ?? 'Wiki';
                $up_git_email = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email']))
                    ? $_SESSION['user']['email'] : 'wiki@localhost';
                git_auto_commit($up_abs, $up_git_name, $up_git_email, 'Upload ' . $up_final);
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
                // path is PAGES_DIR-relative (includes space name), not space_dir-relative,
                // so resolve against PAGES_DIR directly instead of sanitize_path().
                $dff_rel  = ltrim(str_replace('..', '', $_POST['path'] ?? ''), '/');
                $file_path = rtrim(PAGES_DIR, '/') . '/' . $dff_rel;
                if (!is_file($file_path)) throw new Exception('File not found.');
                unlink($file_path);
                echo json_encode(['success' => true]);
                break;

            case 'admin_get_users':
                $uf_data = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                $human_users = array_values(array_filter($uf_data['users'] ?? [], fn($u) => empty($u['is_ai']) && empty($u['is_system'])));
                // Normalize auth field for legacy records that predate the auth field
                $human_users = array_map(function($u) {
                    if (!isset($u['auth'])) {
                        $u['auth'] = (isset($u['sub']) && $u['sub']) ? 'oidc' : 'otp';
                    }
                    return $u;
                }, $human_users);
                echo json_encode(['success' => true, 'data' => $human_users]);
                break;

            case 'admin_save_users':
                $incoming_users = json_decode($_POST['users'] ?? '[]', true);
                if (!is_array($incoming_users)) throw new Exception('Invalid users data.');
                $existing_uf = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $non_human_preserved = array_values(array_filter($existing_uf['users'] ?? [], fn($u) => !empty($u['is_ai']) || !empty($u['is_system'])));
                $existing_by_sub = [];
                $existing_by_uid = [];
                $max_uid_sv = 0;
                foreach ($existing_uf['users'] ?? [] as $eu) {
                    if (!empty($eu['is_ai']) || !empty($eu['is_system'])) continue;
                    if ($eu['sub'] ?? '') $existing_by_sub[$eu['sub']] = $eu;
                    if ($eu['uid'] ?? '') $existing_by_uid[(int)$eu['uid']] = $eu;
                    if ((int)($eu['uid'] ?? 0) > $max_uid_sv) $max_uid_sv = (int)$eu['uid'];
                }
                $merged = [];
                foreach ($incoming_users as $u) {
                    $auth = $u['auth'] ?? ((isset($u['sub']) && $u['sub']) ? 'oidc' : 'otp');
                    $role = in_array($u['role'] ?? '', ['admin', 'editor', 'reader']) ? $u['role'] : 'editor';
                    $sv_spaces = array_key_exists('spaces', $u)
                        ? (is_array($u['spaces']) ? array_values(array_filter($u['spaces'], 'is_string')) : null)
                        : null;
                    if ($auth === 'oidc') {
                        $sub = trim($u['sub'] ?? '');
                        if (!$sub) continue;
                        $base = $existing_by_sub[$sub] ?? ['sub' => $sub, 'name' => $u['name'] ?? '', 'email' => $u['email'] ?? ''];
                        $base['role'] = $role;
                        $base['auth'] = 'oidc';
                        if (array_key_exists('spaces', $u)) $base['spaces'] = $sv_spaces;
                    } else {
                        $name  = trim($u['name'] ?? '');
                        $email = trim($u['email'] ?? '');
                        if (!$name || !$email) continue;
                        $uid = (int)($u['uid'] ?? 0);
                        $base = ($uid && isset($existing_by_uid[$uid])) ? $existing_by_uid[$uid] : [];
                        if (!$uid || !$base) {
                            $uid = ++$max_uid_sv;
                        }
                        $base['uid']   = $uid;
                        $base['name']  = $name;
                        $base['email'] = $email;
                        $base['role']  = $role;
                        $base['auth']  = 'otp';
                        if (array_key_exists('spaces', $u)) $base['spaces'] = $sv_spaces;
                    }
                    $merged[] = $base;
                }

                // No two OTP accounts may claim the same login address. `email` is what
                // find_otp_user() matches on, so a duplicate makes the lookup return
                // whichever record comes first in the file — one person silently landing
                // in another's account, with that account's role and Spaces.
                //
                // Scoped to OTP records on purpose. An OIDC record is matched on `sub`, so
                // sharing an address with one is harmless — and forbidding it would be a
                // false alarm on a real pattern: one person's provider address also being
                // an OTP account's login. The moment an account is switched *to* OTP both
                // records are OTP and this check runs, which is exactly when it matters.
                $seen_login = [];
                foreach ($merged as $mu) {
                    if (($mu['auth'] ?? 'oidc') !== 'otp') continue;
                    $key = strtolower(trim((string)($mu['email'] ?? '')));
                    if ($key === '') continue;
                    if (isset($seen_login[$key])) {
                        throw new Exception('Two OTP accounts share the login email "' . $key
                            . '". A login address identifies exactly one account — give one of them its own,'
                            . ' or use the notification email for a shared inbox.');
                    }
                    $seen_login[$key] = true;
                }

                $merged = array_merge($merged, $non_human_preserved);
                // Keep every top-level key (notably `schema`, the migration marker) rather
                // than rebuilding the document from just the user list.
                $existing_uf['users'] = $merged;
                if (file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($existing_uf, JSON_PRETTY_PRINT)) === false) {
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
                            'name'        => $pu['name']       ?? '',
                            // The address they may change, not the one they log in with.
                            'email'       => wiki_user_notify_email($pu),
                            'loginEmail'  => $pu['email']      ?? '',
                            'fontFamily'  => $pu['fontFamily'] ?? 'sans',
                            'fontSize'    => $pu['fontSize']   ?? '11pt',
                            'dailyDigest' => !empty($pu['dailyDigest']),
                            'notifyAgentJobs' => !empty($pu['notifyAgentJobs']),
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
                $new_digest     = (($_POST['dailyDigest'] ?? '') === '1');
                $new_job_notify = (($_POST['notifyAgentJobs'] ?? '') === '1');
                if (!in_array($new_font,      ['sans','serif','mono']))                          $new_font      = 'sans';
                if (!in_array($new_font_size, ['10pt','11pt','12pt','14pt','16pt']))          $new_font_size = '11pt';
                if ($new_email && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address.');
                }
                $pref_data2 = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                $found_pref = false;
                foreach ($pref_data2['users'] as &$pu2) {
                    if (($pu2['sub'] ?? '') === $me_sub2) {
                        // notifyEmail, never email: on an OTP account `email` is the
                        // credential the login lookup matches on, so a preference must not
                        // be able to write it. Clearing the box falls back to the identity.
                        if ($new_email) $pu2['notifyEmail'] = $new_email;
                        else            unset($pu2['notifyEmail']);
                        $pu2['fontFamily']  = $new_font;
                        $pu2['fontSize']    = $new_font_size;
                        $pu2['dailyDigest'] = $new_digest;
                        $pu2['notifyAgentJobs'] = $new_job_notify;
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

            case 'admin_audit_config':
                echo json_encode([
                    'success' => true,
                    'enabled' => wiki_audit_enabled(),
                    'dates'   => wiki_audit_dates(),
                    'dir'     => defined('LOG_DIR') && LOG_DIR ? rtrim(LOG_DIR, '/\\') . '/audit' : null,
                ]);
                break;

            case 'admin_set_audit_enabled':
                $ae_on = in_array($_POST['enabled'] ?? '', ['1', 'true'], true);
                if (!$ae_on) wiki_audit_log('audit_disabled', 'success', ['object_category' => 'settings']);
                if (!wiki_setting_set('audit_log', $ae_on)) {
                    throw new Exception('Could not save the setting — is WIKI_SYSTEM_DATA writable?');
                }
                // Recorded in the log itself, so switching it off is visible in the trail
                // rather than looking like a quiet day. Written after the flag flips on,
                // and before it flips off, so both land in the file.
                if ($ae_on) wiki_audit_log('audit_enabled', 'success', ['object_category' => 'settings']);
                echo json_encode(['success' => true, 'enabled' => $ae_on]);
                break;

            case 'admin_get_audit_entries':
                $al_date = trim($_GET['date'] ?? date('Y-m-d'));
                $al_user = trim($_GET['user'] ?? '');
                echo json_encode([
                    'success' => true,
                    'date'    => $al_date,
                    'users'   => wiki_audit_users($al_date),
                    'data'    => wiki_audit_read($al_date, $al_user),
                ]);
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
                (new WikiGraph($space_dir, $indexer))->invalidateCache();
                $sqlite_msg = '';
                if ($search_idx) {
                    try {
                        $sp_name = _sidx_space();
                        // If called without a specific space, rebuild everything.
                        if ($sp_name === basename(rtrim(PAGES_DIR, '/'))) {
                            $sqlite_count = $search_idx->rebuildAll();
                        } else {
                            $sqlite_count = $search_idx->rebuildSpace($sp_name);
                        }
                        $sqlite_msg = " SQLite FTS index rebuilt ({$sqlite_count} pages).";
                    } catch (\Throwable $_e) {
                        $sqlite_msg = " SQLite FTS rebuild failed: " . $_e->getMessage();
                    }
                }
                header("Content-Type: text/plain");
                echo "Indexing complete. Found and indexed {$count} pages.{$sqlite_msg}";
                exit;

            case 'admin_reindex':
                if (rtrim($space_dir, '/') === rtrim(PAGES_DIR, '/')) {
                    throw new Exception('A space name is required for reindex.');
                }
                $ri_count = $indexer->rebuildIndex($space_dir);
                (new WikiGraph($space_dir, $indexer))->invalidateCache();
                $ri_sqlite_msg = '';
                $ri_sqlite_count = null;
                $ri_users_cleaned = 0;
                $sp_name = _sidx_space();
                if ($search_idx) {
                    try {
                        $ri_sqlite_count = $search_idx->rebuildSpace($sp_name);
                    } catch (\Throwable $_e) {
                        $ri_sqlite_msg = $_e->getMessage();
                    }
                }
                // Remove stale space references from users.json on every reindex.
                if (defined('WIKI_SYSTEM_DATA') && file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
                    $ri_actual = [];
                    foreach (scandir(PAGES_DIR) as $_rd) {
                        if ($_rd === '.' || $_rd === '..' || $_rd[0] === '.') continue;
                        if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $_rd)) $ri_actual[] = $_rd;
                    }
                    $ri_uf = json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []];
                    $ri_changed = false;
                    foreach ($ri_uf['users'] as &$ri_user) {
                        if (!isset($ri_user['spaces']) || !is_array($ri_user['spaces'])) continue;
                        $ri_before = $ri_user['spaces'];
                        $ri_user['spaces'] = array_values(array_intersect($ri_user['spaces'], $ri_actual));
                        if ($ri_user['spaces'] !== $ri_before) {
                            $ri_users_cleaned += count($ri_before) - count($ri_user['spaces']);
                            $ri_changed = true;
                        }
                    }
                    unset($ri_user);
                    if ($ri_changed) {
                        file_put_contents(WIKI_SYSTEM_DATA . 'users.json', json_encode($ri_uf, JSON_PRETTY_PRINT));
                    }
                }
                echo json_encode([
                    'success'       => true,
                    'count'         => $ri_count,
                    'sqlite_count'  => $ri_sqlite_count,
                    'sqlite_error'  => $ri_sqlite_msg ?: null,
                    'users_cleaned' => $ri_users_cleaned,
                ]);
                break;

            case 'list_spaces':
                // Runs before anything else the client does on load — in particular
                // before get_start_page — so a fresh install lands in a real Space and
                // its start page is created there rather than at the unreachable root.
                wiki_ensure_default_space();
                $spaces_list = [];
                foreach (scandir(PAGES_DIR) as $_sf) {
                    if ($_sf === '.' || $_sf === '..' || $_sf[0] === '.') continue;
                    if (is_dir(PAGES_DIR . '/' . $_sf)) $spaces_list[] = $_sf;
                }
                sort($spaces_list);
                // Filter by user's allowed spaces (admins see all).
                if (AUTHENTICATION_ENABLED) {
                    $_ls_allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
                    if ($_ls_allowed !== null) {
                        $spaces_list = array_values(array_filter($spaces_list, fn($s) => in_array($s, $_ls_allowed, true)));
                    }
                }
                // `readonly` rides along as a separate array so `data` stays a plain
                // list of names for its nine existing callers. Every client needs the
                // flag, not just admins: it drives the frozen-space UI.
                $_ls_readonly = array_values(array_filter($spaces_list, 'wiki_space_is_readonly'));
                echo json_encode(['success' => true, 'data' => $spaces_list, 'readonly' => $_ls_readonly]);
                break;

            case 'get_all_tags':
                // Scan index.json across all accessible spaces and return the union of all tags.
                $_gt_spaces = [];
                foreach (scandir(PAGES_DIR) as $_gt_sf) {
                    if ($_gt_sf === '.' || $_gt_sf === '..' || $_gt_sf[0] === '.') continue;
                    if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $_gt_sf)) $_gt_spaces[] = $_gt_sf;
                }
                if (AUTHENTICATION_ENABLED) {
                    $_gt_allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
                    if ($_gt_allowed !== null) {
                        $_gt_spaces = array_filter($_gt_spaces, fn($s) => in_array($s, $_gt_allowed, true));
                    }
                }
                $_gt_tags = [];
                foreach ($_gt_spaces as $_gt_sp) {
                    $_gt_idx = rtrim(PAGES_DIR, '/') . '/' . $_gt_sp . '/index.json';
                    if (!file_exists($_gt_idx)) continue;
                    $_gt_data = json_decode(file_get_contents($_gt_idx), true) ?? [];
                    foreach ($_gt_data as $_gt_entry) {
                        if (!empty($_gt_entry['tags']) && is_array($_gt_entry['tags'])) {
                            foreach ($_gt_entry['tags'] as $_gt_tag) {
                                $_gt_tags[$_gt_tag] = true;
                            }
                        }
                    }
                }
                $_gt_list = array_keys($_gt_tags);
                sort($_gt_list, SORT_STRING | SORT_FLAG_CASE);
                echo json_encode(['success' => true, 'data' => $_gt_list]);
                break;

            case 'get_tag_cloud':
                // Like get_all_tags but returns [{tag, count}] sorted by tag name.
                $_tc_spaces = [];
                foreach (scandir(PAGES_DIR) as $_tc_sf) {
                    if ($_tc_sf === '.' || $_tc_sf === '..' || $_tc_sf[0] === '.') continue;
                    if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $_tc_sf)) $_tc_spaces[] = $_tc_sf;
                }
                if (AUTHENTICATION_ENABLED) {
                    $_tc_allowed = actor_spaces_filter(get_current_role(), $ai_auth_user);
                    if ($_tc_allowed !== null) {
                        $_tc_spaces = array_values(array_filter($_tc_spaces, fn($s) => in_array($s, $_tc_allowed, true)));
                    }
                }
                $_tc_counts = [];
                foreach ($_tc_spaces as $_tc_sp) {
                    $_tc_idx = rtrim(PAGES_DIR, '/') . '/' . $_tc_sp . '/index.json';
                    if (!file_exists($_tc_idx)) continue;
                    $_tc_data = json_decode(file_get_contents($_tc_idx), true) ?? [];
                    foreach ($_tc_data as $_tc_entry) {
                        if (!empty($_tc_entry['tags']) && is_array($_tc_entry['tags'])) {
                            foreach ($_tc_entry['tags'] as $_tc_tag) {
                                $_tc_counts[$_tc_tag] = ($_tc_counts[$_tc_tag] ?? 0) + 1;
                            }
                        }
                    }
                }
                ksort($_tc_counts, SORT_STRING | SORT_FLAG_CASE);
                $_tc_result = [];
                foreach ($_tc_counts as $_tc_tag => $_tc_n) {
                    $_tc_result[] = ['tag' => $_tc_tag, 'count' => $_tc_n];
                }
                echo json_encode(['success' => true, 'data' => $_tc_result]);
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
                if ($search_idx) {
                    try { $search_idx->renameSpace($rs_safe_old, $rs_safe_new); } catch (\Throwable $_e) {}
                }
                // Per-space settings are keyed by name, so they have to follow it.
                wiki_space_settings_rename($rs_safe_old, $rs_safe_new);
                // So is the external-change stamp: without this the renamed space
                // starts with no stamp (one needless full scan) and the old one is
                // left behind forever, one more with every rename.
                $rs_stamp_old = index_sync_stamp_path($rs_old_dir);
                $rs_stamp_new = index_sync_stamp_path($rs_new_dir);
                if ($rs_stamp_old && $rs_stamp_new && is_file($rs_stamp_old)) {
                    @rename($rs_stamp_old, $rs_stamp_new);
                }
                if ($rs_stamp_old) @unlink($rs_stamp_old . '.lock');
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
                // Start page + a copy of the app templates (see wiki_scaffold_space).
                $actor = get_current_actor();
                wiki_scaffold_space($new_space_dir, $actor['uid'] ?? null, $actor['name'] ?? null);
                echo json_encode(['success' => true]);
                break;

            case 'admin_ai_builtin_instructions':
                // What an AI user is actually told, before its own system prompt. Built
                // by the same functions the live requests use, so this cannot drift from
                // what is really sent — which is the only reason it is worth showing.
                $bi_space = trim($_GET['sample_space'] ?? '');
                if ($bi_space === '') {
                    foreach (scandir(PAGES_DIR) as $bi_f) {
                        if ($bi_f === '.' || $bi_f === '..' || $bi_f[0] === '.') continue;
                        if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $bi_f)) { $bi_space = $bi_f; break; }
                    }
                }
                if ($bi_space === '') $bi_space = 'Main';
                $bi_actor = get_current_actor()['name'] ?? 'Alice';
                echo json_encode([
                    'success' => true,
                    'space'   => $bi_space,
                    'chat'    => wiki_chat_context_prompt($bi_space, 'Standup', 'Team'),
                    'job'     => wiki_job_context_prompt($bi_space, 'Team', $bi_actor),
                    'tools'   => array_map(
                        fn($t) => ['name' => $t['name'], 'description' => $t['description']],
                        wiki_tool_definitions()),
                ]);
                break;

            case 'admin_space_settings':
                // Everything the Space settings dialog needs, for every space, in one
                // call: the read-only flag, a page count, and whether the space is its
                // own git repository — which is what blocks it from being merged away.
                $ss_out = [];
                foreach (scandir(PAGES_DIR) as $ss_f) {
                    if ($ss_f === '.' || $ss_f === '..' || $ss_f[0] === '.') continue;
                    $ss_dir = rtrim(PAGES_DIR, '/') . '/' . $ss_f;
                    if (!is_dir($ss_dir)) continue;
                    $ss_index = $ss_dir . '/index.json';
                    $ss_out[] = [
                        'name'     => $ss_f,
                        'readonly' => wiki_space_is_readonly($ss_f),
                        'own_git'  => is_dir($ss_dir . '/.git'),
                        'pages'    => is_file($ss_index)
                            ? count(json_decode((string)file_get_contents($ss_index), true) ?: [])
                            : 0,
                    ];
                }
                echo json_encode([
                    'success'  => true,
                    'data'     => $ss_out,
                    'root_git' => is_dir(rtrim(PAGES_DIR, '/') . '/.git'),
                ]);
                break;

            case 'admin_set_space_readonly':
                $sro_name = basename(trim($_POST['space_name'] ?? ''));
                $sro_on   = in_array($_POST['readonly'] ?? '', ['1', 'true'], true);
                if ($sro_name === '' || !is_dir(rtrim(PAGES_DIR, '/') . '/' . $sro_name)) {
                    throw new Exception('Space not found.');
                }
                if (!wiki_space_set_readonly($sro_name, $sro_on)) {
                    throw new Exception('Could not save space settings — is WIKI_SYSTEM_DATA writable?');
                }
                echo json_encode(['success' => true, 'readonly' => $sro_on]);
                break;

            case 'admin_merge_space_preflight':
                // Dry run: works out where every file would land and reports the
                // collisions, so the confirmation dialog can show the real numbers.
                require_once __DIR__ . '/space_merge.php';
                $mp_src   = trim($_REQUEST['source'] ?? '');
                $mp_tgt   = trim($_REQUEST['target'] ?? '');
                $mp_block = space_merge_blocker($mp_src, $mp_tgt);
                if ($mp_block !== null) {
                    echo json_encode(['success' => true, 'blocked' => $mp_block]);
                    break;
                }
                $mp_plan    = space_merge_plan($mp_src, $mp_tgt);
                $mp_renames = [];
                foreach ($mp_plan['moves'] as $mp_m) {
                    if (!$mp_m['renamed'] || count($mp_renames) >= 50) continue;
                    $mp_renames[] = ['from' => $mp_m['from'], 'to' => $mp_m['to']];
                }
                echo json_encode([
                    'success' => true,
                    'blocked' => null,
                    'pages'   => $mp_plan['pages'],
                    'files'   => count($mp_plan['moves']),
                    'renamed' => $mp_plan['renamed'],
                    'skipped' => count($mp_plan['skips']),
                    'renames' => $mp_renames,
                    'git'     => space_merge_git_root(basename($mp_tgt)) !== null,
                ]);
                break;

            case 'admin_merge_space':
                require_once __DIR__ . '/space_merge.php';
                // A large space is hundreds of renames plus a search-index rebuild.
                @set_time_limit(600);
                $ms_src   = trim($_POST['source'] ?? '');
                $ms_tgt   = trim($_POST['target'] ?? '');
                $ms_block = space_merge_blocker($ms_src, $ms_tgt);
                if ($ms_block !== null) {
                    echo json_encode(['success' => false, 'blocked' => $ms_block]);
                    break;
                }
                $ms_actor = get_current_actor();
                $ms_actor['email'] = (AUTHENTICATION_ENABLED && !empty($_SESSION['user']['email']))
                    ? $_SESSION['user']['email'] : 'wiki@localhost';
                $ms_plan = space_merge_plan($ms_src, $ms_tgt);
                echo json_encode(space_merge_execute($ms_src, $ms_tgt, $ms_plan, $search_idx, $ms_actor));
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
                        'spaces'        => array_key_exists('spaces', $u) ? $u['spaces'] : null,
                        'ai_config'     => array_merge($cfg, ['api_key_set' => $has_key]),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $ai_users_out]);
                break;

            case 'admin_save_ai_user':
                $ai_name      = trim($_POST['name'] ?? '');
                $ai_role      = in_array($_POST['role'] ?? '', ['editor', 'reader']) ? $_POST['role'] : 'editor';
                $ai_uid       = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                $ai_source_uid = isset($_POST['source_uid']) && $_POST['source_uid'] !== '' ? (int)$_POST['source_uid'] : null;
                $ai_cfg_in    = json_decode($_POST['ai_config'] ?? '{}', true) ?? [];
                $ai_spaces_raw = json_decode($_POST['spaces'] ?? 'null', true);
                $ai_spaces_in  = is_array($ai_spaces_raw) ? array_values(array_filter(array_map('strval', $ai_spaces_raw))) : null;
                if (!$ai_name) throw new Exception('AI user name is required.');
                $uf_sai = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                if ($ai_uid !== null) {
                    $found_ai = false;
                    foreach ($uf_sai['users'] as &$u) {
                        if (empty($u['is_ai']) || (int)($u['uid'] ?? -1) !== $ai_uid) continue;
                        $u['name']   = $ai_name;
                        $u['role']   = $ai_role;
                        $u['spaces'] = $ai_spaces_in;
                        $ec = $u['ai_config'] ?? [];
                        $nc = [
                            'provider'         =>        $ai_cfg_in['provider']         ?? $ec['provider']         ?? 'openai',
                            'api_url'          => trim($ai_cfg_in['api_url']            ?? $ec['api_url']          ?? ''),
                            'model'            => trim($ai_cfg_in['model']              ?? $ec['model']            ?? ''),
                            'system_prompt'    =>        $ai_cfg_in['system_prompt']    ?? $ec['system_prompt']    ?? '',
                            'system_prompt_space' => trim($ai_cfg_in['system_prompt_space'] ?? $ec['system_prompt_space'] ?? ''),
                            'system_prompt_page'  => trim($ai_cfg_in['system_prompt_page']  ?? $ec['system_prompt_page']  ?? ''),
                            'context_messages' => (int)( $ai_cfg_in['context_messages'] ?? $ec['context_messages'] ?? 10),
                            'max_tokens'       => (int)( $ai_cfg_in['max_tokens']       ?? $ec['max_tokens']       ?? 4096),
                            'temperature'      => (float)($ai_cfg_in['temperature']      ?? $ec['temperature']      ?? 0.7),
                            // Every chat mention of this AI is queued as a one-off job
                            // instead of answered inline — see post_chat_message. It says
                            // *when* the answer arrives; reasoning_effort says how hard
                            // the model works, and the two are independent.
                            'always_background' => !empty($ai_cfg_in['always_background'] ?? $ec['always_background'] ?? false),
                            'reasoning_effort'  => in_array($ai_cfg_in['reasoning_effort'] ?? $ec['reasoning_effort'] ?? '', WIKI_AI_EFFORTS, true)
                                ? (string)($ai_cfg_in['reasoning_effort'] ?? $ec['reasoning_effort'] ?? '') : '',
                            'mcp_server_ids'    => array_values(array_filter(array_map('strval', $ai_cfg_in['mcp_server_ids'] ?? $ec['mcp_server_ids'] ?? []))),
                            'mcp_instructions'  => (array)($ai_cfg_in['mcp_instructions'] ?? $ec['mcp_instructions'] ?? []),
                            'extra_headers'     => _sanitize_extra_headers($ai_cfg_in['extra_headers'] ?? $ec['extra_headers'] ?? []),
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
                    // If cloning and no key entered, inherit the key from the source user
                    $new_api_key = $ai_cfg_in['api_key'] ?? '';
                    if ($new_api_key === '' && $ai_source_uid !== null) {
                        foreach ($uf_sai['users'] as $src) {
                            if (!empty($src['is_ai']) && (int)($src['uid'] ?? -1) === $ai_source_uid) {
                                $new_api_key = $src['ai_config']['api_key'] ?? '';
                                break;
                            }
                        }
                    }
                    $uf_sai['users'][] = [
                        'uid'           => $max_uid_ai + 1,
                        'name'          => $ai_name,
                        'role'          => $ai_role,
                        'is_ai'         => true,
                        'spaces'        => $ai_spaces_in,
                        'service_token' => generate_ai_service_token(),
                        'ai_config'     => [
                            'provider'         =>        $ai_cfg_in['provider']         ?? 'openai',
                            'api_url'          => trim($ai_cfg_in['api_url']            ?? ''),
                            'api_key'          => $new_api_key,
                            'model'            => trim($ai_cfg_in['model']              ?? ''),
                            'system_prompt'    =>        $ai_cfg_in['system_prompt']    ?? '',
                            'system_prompt_space' => trim($ai_cfg_in['system_prompt_space'] ?? ''),
                            'system_prompt_page'  => trim($ai_cfg_in['system_prompt_page']  ?? ''),
                            'context_messages' => (int)( $ai_cfg_in['context_messages'] ?? 10),
                            'max_tokens'       => (int)( $ai_cfg_in['max_tokens']       ?? 4096),
                            'temperature'      => (float)($ai_cfg_in['temperature']      ?? 0.7),
                            'always_background' => !empty($ai_cfg_in['always_background'] ?? false),
                            'reasoning_effort'  => in_array($ai_cfg_in['reasoning_effort'] ?? '', WIKI_AI_EFFORTS, true)
                                ? (string)($ai_cfg_in['reasoning_effort'] ?? '') : '',
                            'mcp_server_ids'   => array_values(array_filter(array_map('strval', $ai_cfg_in['mcp_server_ids'] ?? []))),
                            'mcp_instructions' => (array)($ai_cfg_in['mcp_instructions'] ?? []),
                            'extra_headers'    => _sanitize_extra_headers($ai_cfg_in['extra_headers'] ?? []),
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
                    $sys_out[] = [
                        'uid'           => $u['uid']           ?? null,
                        'name'          => $u['name']          ?? '',
                        'role'          => $u['role']          ?? 'editor',
                        'service_token' => $u['service_token'] ?? '',
                        'spaces'        => array_key_exists('spaces', $u) ? $u['spaces'] : null,
                    ];
                }
                echo json_encode(['success' => true, 'data' => $sys_out]);
                break;

            case 'admin_save_api_account':
                $sys_name = trim($_POST['name'] ?? '');
                $sys_role = in_array($_POST['role'] ?? '', ['editor', 'reader']) ? $_POST['role'] : 'editor';
                $sys_uid  = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                $sys_spaces_raw = json_decode($_POST['spaces'] ?? 'null', true);
                $sys_spaces_in  = is_array($sys_spaces_raw) ? array_values(array_filter(array_map('strval', $sys_spaces_raw))) : null;
                if (!$sys_name) throw new Exception('System user name is required.');
                $uf_ssys = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? ['users' => []]) : ['users' => []];
                if ($sys_uid !== null) {
                    $found_ssys = false;
                    foreach ($uf_ssys['users'] as &$u) {
                        if (empty($u['is_system']) || (int)($u['uid'] ?? -1) !== $sys_uid) continue;
                        $u['name']   = $sys_name;
                        $u['role']   = $sys_role;
                        $u['spaces'] = $sys_spaces_in;
                        $found_ssys = true;
                        break;
                    }
                    unset($u);
                    if (!$found_ssys) throw new Exception('System user not found.');
                } else {
                    $max_uid_sys = 0;
                    foreach ($uf_ssys['users'] as $eu) { if (isset($eu['uid']) && $eu['uid'] > $max_uid_sys) $max_uid_sys = $eu['uid']; }
                    $uf_ssys['users'][] = ['uid' => $max_uid_sys + 1, 'name' => $sys_name, 'role' => $sys_role, 'is_system' => true, 'spaces' => $sys_spaces_in, 'service_token' => generate_api_service_token()];
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

            case 'list_my_jobs':
                // The caller's own one-off jobs — what /jobs shows. Not an admin action:
                // a job you queued is yours to check on, and a reader can queue nothing,
                // so there is nothing here they could see that they did not cause.
                $mj_uid  = (int)(get_current_actor()['uid'] ?? 0);
                $mj_rows = [];
                // One indexer per space, reused across rows: the notification toast links
                // to the thread a job was started from, and navigation here is by page id,
                // not by path. A job's space is not necessarily the request's.
                $mj_indexers = [];
                $mj_chat_id = function ($space, $chat) use (&$mj_indexers) {
                    if ($chat === '') return null;
                    if (!array_key_exists($space, $mj_indexers)) {
                        $dir = $space === '' ? rtrim(PAGES_DIR, '/') : rtrim(PAGES_DIR, '/') . '/' . $space;
                        $mj_indexers[$space] = is_dir($dir) ? new PageIndexer($dir) : null;
                    }
                    return $mj_indexers[$space] ? ($mj_indexers[$space]->getId($chat) ?: null) : null;
                };
                foreach (agent_job_queue_read() as $mj) {
                    // uid 0 is "authentication disabled", where everyone is the same
                    // local user — matching on it is correct rather than a leak.
                    if ((int)($mj['requested_by']['uid'] ?? -1) !== $mj_uid) continue;
                    $mj_rows[] = [
                        'id'          => $mj['id'] ?? '',
                        'state'       => $mj['state'] ?? '',
                        'ai_user'     => $mj['ai_user_name'] ?? 'AI',
                        'space'       => $mj['space'] ?? '',
                        'chat'        => $mj['reply_to']['chat'] ?? '',
                        'chat_id'     => $mj_chat_id((string)($mj['space'] ?? ''), (string)($mj['reply_to']['chat'] ?? '')),
                        'created_at'  => $mj['created_at'] ?? null,
                        'finished_at' => $mj['finished_at'] ?? null,
                        'error'       => $mj['error'] ?? null,
                        // The prompt can be long — and for a background chat mention it
                        // carries the thread transcript — so only enough to recognise it.
                        'prompt'      => mb_substr(trim((string)($mj['prompt'] ?? '')), 0, 160),
                        'has_log'     => $mj['log_file'] !== null && $mj['log_file'] !== '',
                    ];
                }
                usort($mj_rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
                echo json_encode([
                    'success' => true,
                    'data'    => $mj_rows,
                    // So the list can say "queued, but nothing is running them".
                    'runner_ok' => agent_job_heartbeat() !== null,
                ]);
                break;

            case 'get_my_job_log':
                // The same file admin_get_oneoff_job_log reads, authorised by ownership
                // instead of by role. Safe to show the requester: it holds their own
                // prompt, the result or error, and a token/tool breakdown — no
                // credentials and no system-prompt text.
                if (!defined('LOG_DIR') || !LOG_DIR) throw new Exception('LOG_DIR is not configured.');
                $ml_id  = trim($_REQUEST['id'] ?? '');
                $ml_uid = (int)(get_current_actor()['uid'] ?? 0);
                $ml_job = null;
                foreach (agent_job_queue_read() as $mlj) {
                    if (($mlj['id'] ?? '') === $ml_id) { $ml_job = $mlj; break; }
                }
                // Ownership is checked against the queue entry, never against the id in
                // the request — the id alone must not be enough to read someone's log.
                if (!$ml_job || (int)($ml_job['requested_by']['uid'] ?? -1) !== $ml_uid) {
                    throw new Exception('No such job of yours.');
                }
                $ml_path = rtrim(LOG_DIR, '/') . '/agent-jobs/_oneoff/'
                         . preg_replace('/[^a-zA-Z0-9_-]/', '-', $ml_id) . '.log';
                if (!is_file($ml_path)) throw new Exception('No log for this job yet — it may still be waiting to run.');
                $ml_body = (string)file_get_contents($ml_path);
                if (strlen($ml_body) > 200000) $ml_body = "… (truncated to last 200 KB) …\n" . substr($ml_body, -200000);
                echo json_encode(['success' => true, 'content' => $ml_body]);
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
                // One-off /aiJob jobs share this panel: same runner, same logs, but a
                // separate queue (see agent_jobs.php). The heartbeat is included so an
                // admin can tell "nothing queued" from "the crontab entry is missing".
                $aj_oneoff = agent_job_queue_read();
                usort($aj_oneoff, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
                echo json_encode([
                    'success'          => true,
                    'jobs'             => $aj_data['jobs'] ?? [],
                    'ai_users'         => $ai_map,
                    'spaces'           => $sp_list,
                    'server_time'      => date('Y-m-d H:i:s'),
                    'server_timezone'  => date_default_timezone_get(),
                    'oneoff'           => array_slice($aj_oneoff, 0, 30),
                    'oneoff_queued'    => count(array_filter($aj_oneoff, fn($j) => ($j['state'] ?? '') === 'queued')),
                    'runner_heartbeat' => agent_job_heartbeat(),
                    'runner_interval'  => agent_job_runner_interval(),
                ]);
                break;

            case 'admin_get_oneoff_job_log':
                // One-off logs are keyed by job id under a reserved folder, so they need
                // their own lookup — admin_get_agent_job_logs resolves names from
                // agent_jobs.json, which one-offs are deliberately not in.
                if (!defined('LOG_DIR') || !LOG_DIR) throw new Exception('LOG_DIR is not configured.');
                $ol_id = preg_replace('/[^a-zA-Z0-9_-]/', '-', trim($_REQUEST['id'] ?? ''));
                if ($ol_id === '') throw new Exception('Missing id.');
                $ol_path = rtrim(LOG_DIR, '/') . '/agent-jobs/_oneoff/' . $ol_id . '.log';
                if (!is_file($ol_path)) throw new Exception('No log for this job (it may not have run yet).');
                $ol_body = (string)file_get_contents($ol_path);
                if (strlen($ol_body) > 200000) $ol_body = "… (truncated to last 200 KB) …\n" . substr($ol_body, -200000);
                echo json_encode(['success' => true, 'file' => basename($ol_path), 'content' => $ol_body]);
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

                // Per-job status file the client polls (the job runs detached below).
                $run_status_dir  = WIKI_SYSTEM_DATA . 'agent_job_runs/';
                if (!is_dir($run_status_dir)) @mkdir($run_status_dir, 0755, true);
                $run_status_file = $run_status_dir . preg_replace('/[^a-zA-Z0-9_-]/', '', $run_job_id) . '.json';
                $run_started_at  = date('c');
                file_put_contents($run_status_file, json_encode(['state' => 'running', 'started_at' => $run_started_at]));

                // Answer the browser immediately, then detach and run server-side.
                // Closing the session first releases its lock so the user can keep
                // editing/chatting; ignore_user_abort + no time limit let the job
                // outlive the request and the admin lightbox being closed.
                echo json_encode(['success' => true, 'started' => true]);
                ignore_user_abort(true);
                set_time_limit(0);
                if (session_id()) session_write_close();
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

                try {
                    $run_result = run_agent_job($run_job, $run_ai_user, $run_indexer2, $run_space_dir2);
                } catch (\Throwable $e) {
                    // Detached run: make sure a crash still flips the status file off
                    // "running" so the client's poll resolves instead of hanging.
                    $run_result = ['reply' => null, 'error' => 'Job crashed: ' . $e->getMessage()];
                }
                $run_status = $run_result['error'] ? 'error' : 'ok';
                $run_ts     = date('c');
                $safe_jn2   = preg_replace('/[^a-zA-Z0-9_-]/', '-', $run_job['name'] ?? 'job');
                $log_dir2   = rtrim(LOG_DIR, '/') . '/agent-jobs/' . $safe_jn2 . '/';
                $log_file2  = $log_dir2 . date('Y-m-d-His') . '.log';
                if (!is_dir($log_dir2)) mkdir($log_dir2, 0755, true);
                $log_body2  = "[{$run_ts}] Job: {$run_job['name']}\n[{$run_ts}] Status: {$run_status}\n[{$run_ts}] AI User: " . ($run_ai_user['name'] ?? 'AI') . "\n\n";
                $log_body2 .= $run_result['error'] ? "ERROR:\n{$run_result['error']}\n" : "RESULT:\n{$run_result['reply']}\n";
                if (!empty($run_result['debug'])) $log_body2 .= "\n" . $run_result['debug'];
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
                // Re-read jobs before updating: we detached, so another request may
                // have edited agent_jobs.json in the meantime.
                $aj_data4 = file_exists($aj_file4) ? (json_decode(file_get_contents($aj_file4), true) ?? ['jobs' => []]) : ['jobs' => []];
                foreach ($aj_data4['jobs'] as &$_uj) {
                    if (($_uj['id'] ?? '') === $run_job_id) {
                        $_uj['last_run']      = $run_ts;
                        $_uj['last_status']   = $run_status;
                        $_uj['last_log_file'] = $log_file2;
                        break;
                    }
                }
                unset($_uj);
                file_put_contents($aj_file4, json_encode($aj_data4, JSON_PRETTY_PRINT));
                file_put_contents($run_status_file, json_encode([
                    'state'       => $run_status,
                    'started_at'  => $run_started_at,
                    'finished_at' => $run_ts,
                    'reply'       => $run_result['reply'] ?? '',
                    'error'       => $run_result['error'] ?? null,
                    'log_file'    => $log_file2,
                ]));
                break;

            case 'admin_agent_job_status':
                $st_id = trim($_REQUEST['id'] ?? '');
                if (!$st_id) throw new Exception('Missing id.');
                $st_file = WIKI_SYSTEM_DATA . 'agent_job_runs/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $st_id) . '.json';
                if (!file_exists($st_file)) { echo json_encode(['success' => true, 'state' => 'idle']); break; }
                $st = json_decode(file_get_contents($st_file), true) ?: ['state' => 'idle'];
                echo json_encode(array_merge(['success' => true], $st));
                break;

            case 'admin_get_agent_job_logs':
                // Lists a job's run logs, or returns one log's content when ?file= is
                // given. Logs live in LOG_DIR/agent-jobs/<safe-job-name>/.
                if (!defined('LOG_DIR') || !LOG_DIR) { echo json_encode(['success' => true, 'logs' => [], 'hint' => 'LOG_DIR is not configured.']); break; }
                $ajl_id = trim($_REQUEST['id'] ?? '');
                if (!$ajl_id) throw new Exception('Missing id.');
                $ajl_file = WIKI_SYSTEM_DATA . 'agent_jobs.json';
                $ajl_data = file_exists($ajl_file) ? (json_decode(file_get_contents($ajl_file), true) ?? ['jobs' => []]) : ['jobs' => []];
                $ajl_job  = null;
                foreach ($ajl_data['jobs'] as $_jj) { if (($_jj['id'] ?? '') === $ajl_id) { $ajl_job = $_jj; break; } }
                if (!$ajl_job) throw new Exception('Job not found.');
                $ajl_safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $ajl_job['name'] ?? 'job');
                $ajl_dir  = rtrim(LOG_DIR, '/') . '/agent-jobs/' . $ajl_safe . '/';

                $ajl_want = trim($_REQUEST['file'] ?? '');
                if ($ajl_want !== '') {
                    $ajl_name = basename($ajl_want); // constrain to this job's dir
                    $ajl_path = $ajl_dir . $ajl_name;
                    if (substr($ajl_name, -4) !== '.log' || !is_file($ajl_path)) throw new Exception('Log file not found.');
                    $ajl_content = (string)file_get_contents($ajl_path);
                    if (strlen($ajl_content) > 200000) $ajl_content = "… (truncated to last 200 KB) …\n" . substr($ajl_content, -200000);
                    echo json_encode(['success' => true, 'file' => $ajl_name, 'content' => $ajl_content]);
                    break;
                }

                $ajl_list = [];
                if (is_dir($ajl_dir)) {
                    foreach (scandir($ajl_dir) ?: [] as $_lf) {
                        if (substr($_lf, -4) !== '.log') continue;
                        $_lp = $ajl_dir . $_lf;
                        $ajl_list[] = ['file' => $_lf, 'mtime' => filemtime($_lp), 'size' => filesize($_lp)];
                    }
                    usort($ajl_list, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
                    $ajl_list = array_slice($ajl_list, 0, 100);
                }
                echo json_encode(['success' => true, 'logs' => $ajl_list]);
                break;

            case 'admin_realtime_status': {
                // What an operator cannot otherwise see: whether this PHP process can
                // reach the hub, and whether the browser's path to it resolves. Reported
                // as separate facts rather than one "ok", because the two halves fail for
                // different reasons — a publish goes to MERCURE_INTERNAL_URL directly
                // while a subscribe goes through nginx at MERCURE_PUBLIC_URL, and the
                // documented packaging trap is nginx's dot-path rule swallowing the
                // second while the first works perfectly.
                $rt_conf = [
                    'enabled'       => wiki_realtime_enabled(),
                    'flag'          => defined('ENABLE_REALTIME') && ENABLE_REALTIME,
                    'key_set'       => wiki_realtime_key() !== '',
                    'internal_url'  => defined('MERCURE_INTERNAL_URL') ? (string)MERCURE_INTERNAL_URL : '',
                    'public_url'    => wiki_realtime_public_url(),
                    'ticket_ttl'    => wiki_realtime_ticket_ttl(),
                    'curl'          => function_exists('curl_init'),
                ];
                // A real publish, to the caller's own diagnostic topic: nothing else
                // proves the token is accepted. Harmless if nobody is listening.
                $rt_probe = wiki_realtime_enabled()
                    ? wiki_realtime_post(wiki_rt_topic_diag((int)(get_current_actor()['uid'] ?? 0)),
                                         ['kind' => 'probe'])
                    : ['ok' => false, 'code' => 0, 'ms' => 0, 'error' => '', 'reason' => 'disabled'];

                // The subscriber's side of the path, as the browser would ask for it. A
                // hub answers a GET with no topic as 400; anything that is not the hub
                // (404 from nginx, 502) is the interesting result.
                $rt_sub = ['code' => 0, 'error' => '', 'reason' => 'skipped', 'url' => ''];
                $rt_pub_url = $rt_conf['public_url'];

                // Where to ask, in order. A *relative* MERCURE_PUBLIC_URL is a path on
                // this very server, so loopback on the port nginx is actually bound to is
                // the reliable way to reach it: the Host header carries the **published**
                // port, which behind a port mapping — every Docker install — is not
                // listening inside the container and reports a false "nothing answered".
                // The Host-header form is kept as a fallback for a deployment where the
                // public URL is served by something other than this process.
                $rt_cands = [];
                if ($rt_pub_url !== '') {
                    if (preg_match('#^https?://#i', $rt_pub_url)) {
                        $rt_cands[] = $rt_pub_url;
                    } else {
                        $rt_port   = (int)($_SERVER['SERVER_PORT'] ?? 80);
                        $rt_scheme = $rt_port === 443 ? 'https' : 'http';
                        $rt_cands[] = $rt_scheme . '://127.0.0.1'
                            . ($rt_port === 80 || $rt_port === 443 ? '' : ':' . $rt_port) . $rt_pub_url;
                        $rt_cands[] = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://'
                            . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . $rt_pub_url;
                    }
                }
                $rt_sub['url'] = $rt_cands[0] ?? '';

                if ($rt_conf['enabled'] && function_exists('curl_init') && $rt_cands) {
                    // Two attempts per candidate, because a TLS verification failure says
                    // nothing about the question being asked. This is an unauthenticated
                    // GET whose body is discarded — the only thing wanted is the status
                    // code — so certificate trust adds no security to it, while a chain
                    // the server cannot verify would hide whether nginx forwards the path
                    // at all. A browser succeeds where curl fails because browsers use
                    // their own root store and chase a missing intermediate via the
                    // certificate's AIA extension; that is a real problem, but a
                    // different one, so it gets its own state rather than "unreachable".
                    $rt_probe_get = function (string $url, bool $verify): array {
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER    => true,
                            CURLOPT_TIMEOUT           => 3,
                            CURLOPT_CONNECTTIMEOUT_MS => 1000,
                            CURLOPT_SSL_VERIFYPEER    => $verify,
                            CURLOPT_SSL_VERIFYHOST    => $verify ? 2 : 0,
                        ]);
                        curl_exec($ch);
                        $out = ['code'  => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                                'error' => curl_error($ch), 'errno' => curl_errno($ch)];
                        return $out;
                    };
                    foreach ($rt_cands as $rt_try) {
                        $rt_got   = $rt_probe_get($rt_try, true);
                        $rt_tlsok = true;
                        // The SSL/certificate family: bad CA bundle, unverifiable peer,
                        // certificate problem, handshake failure.
                        if ($rt_got['code'] === 0 && in_array($rt_got['errno'], [35, 51, 58, 60, 77, 83], true)) {
                            $rt_retry = $rt_probe_get($rt_try, false);
                            if ($rt_retry['code'] !== 0) {
                                $rt_tlsok = false;
                                $rt_retry['error'] = $rt_got['error'];   // keep the real reason
                                $rt_got = $rt_retry;
                            }
                        }
                        // 400/401 both mean "a hub answered". 404 is nginx not forwarding.
                        $rt_hub = in_array($rt_got['code'], [200, 400, 401], true);
                        $rt_sub = [
                            'code'   => $rt_got['code'],
                            'error'  => $rt_got['error'],
                            'url'    => $rt_try,
                            'reason' => $rt_hub
                                ? ($rt_tlsok ? 'hub' : 'hub_unverified')
                                : ($rt_got['code'] === 0 ? 'unreachable' : 'not_hub'),
                        ];
                        // A hub answered, or something that is not a hub did — either way
                        // that is the answer. Only an unreachable address is worth
                        // retrying at the next candidate.
                        if ($rt_sub['reason'] !== 'unreachable') break;
                    }
                }
                echo json_encode(['success' => true, 'data' => [
                    'config' => $rt_conf, 'publish' => $rt_probe, 'subscribe' => $rt_sub,
                    'topic'  => wiki_rt_topic_diag((int)(get_current_actor()['uid'] ?? 0)),
                ]]);
                break;
            }

            case 'admin_realtime_test': {
                // The round trip: this publishes, the admin's own browser is subscribed to
                // the topic, and the page reports whether it arrived. Nothing short of
                // this proves the whole path — PHP to hub to nginx to EventSource.
                $rt_nonce = bin2hex(random_bytes(8));
                $rt_uid   = (int)(get_current_actor()['uid'] ?? 0);
                $rt_res   = wiki_realtime_post(wiki_rt_topic_diag($rt_uid),
                                               ['kind' => 'test', 'nonce' => $rt_nonce]);
                echo json_encode(['success' => true, 'data' => [
                    'nonce' => $rt_nonce, 'publish' => $rt_res,
                    'topic' => wiki_rt_topic_diag($rt_uid),
                ]]);
                break;
            }

            case 'admin_get_mcp_servers':
                $mcp_out = array_map(fn($s) => [
                    'id'             => $s['id']        ?? '',
                    'name'           => $s['name']       ?? '',
                    'url'            => $s['url']        ?? '',
                    'auth_token_set' => !empty($s['auth_token']),
                    'auth_header'    => $s['auth_header'] ?? 'Authorization',
                    'auth_prefix'    => array_key_exists('auth_prefix', $s) ? $s['auth_prefix'] : 'Bearer',
                    'extra_headers'  => $s['extra_headers'] ?? [],
                    'wiki_native'    => !empty($s['wiki_native']),
                    'search_tool'    => $s['search_tool'] ?? '',
                    'search_arg'     => $s['search_arg']  ?? '',
                    'instructions'   => $s['instructions'] ?? '',
                    'created_at'     => $s['created_at'] ?? '',
                ], _load_mcp_servers());
                echo json_encode(['success' => true, 'data' => $mcp_out]);
                break;

            case 'admin_save_mcp_server':
                $mcp_id    = trim($_POST['id']         ?? '');
                $mcp_name  = trim($_POST['name']       ?? '');
                $mcp_url   = trim($_POST['url']        ?? '');
                $mcp_token = $_POST['auth_token']      ?? '';
                $mcp_auth_header = trim($_POST['auth_header'] ?? '');
                $mcp_auth_prefix = array_key_exists('auth_prefix', $_POST) ? trim($_POST['auth_prefix']) : 'Bearer';
                $mcp_extra_headers = _sanitize_extra_headers(json_decode($_POST['extra_headers'] ?? '[]', true));
                $mcp_native = !empty($_POST['wiki_native']) && $_POST['wiki_native'] !== '0' && $_POST['wiki_native'] !== 'false';
                $mcp_search_tool = trim($_POST['search_tool'] ?? '');
                $mcp_search_arg  = trim($_POST['search_arg']  ?? '');
                // Shared guidance for every AI user that enables this server — see
                // wiki_mcp_guidance(). Capped: it goes into every system prompt.
                $mcp_instructions = mb_substr(trim($_POST['instructions'] ?? ''), 0, 4000);
                if (!$mcp_name) throw new Exception('MCP server name is required.');
                if (!$mcp_url)  throw new Exception('MCP server URL is required.');
                $mcp_servers = _load_mcp_servers();
                if ($mcp_id !== '') {
                    $found_mcp = false;
                    foreach ($mcp_servers as &$_ms) {
                        if (($_ms['id'] ?? '') !== $mcp_id) continue;
                        $_ms['name'] = $mcp_name;
                        $_ms['url']  = $mcp_url;
                        $_ms['auth_header'] = $mcp_auth_header;
                        $_ms['auth_prefix'] = $mcp_auth_prefix;
                        $_ms['extra_headers'] = $mcp_extra_headers;
                        $_ms['wiki_native'] = $mcp_native;
                        $_ms['search_tool'] = $mcp_search_tool;
                        $_ms['search_arg']  = $mcp_search_arg;
                        $_ms['instructions'] = $mcp_instructions;
                        if ($mcp_token !== '') $_ms['auth_token'] = $mcp_token;
                        $found_mcp = true;
                        break;
                    }
                    unset($_ms);
                    if (!$found_mcp) throw new Exception('MCP server not found.');
                } else {
                    $mcp_servers[] = [
                        'id'          => bin2hex(random_bytes(16)),
                        'name'        => $mcp_name,
                        'url'         => $mcp_url,
                        'auth_token'  => $mcp_token,
                        'auth_header' => $mcp_auth_header,
                        'auth_prefix' => $mcp_auth_prefix,
                        'extra_headers' => $mcp_extra_headers,
                        'wiki_native' => $mcp_native,
                        'search_tool' => $mcp_search_tool,
                        'search_arg'  => $mcp_search_arg,
                        'instructions' => $mcp_instructions,
                        'created_at'  => date('c'),
                    ];
                }
                _save_mcp_servers($mcp_servers);
                echo json_encode(['success' => true]);
                break;

            case 'admin_delete_mcp_server':
                $del_mcp_id = trim($_POST['id'] ?? '');
                if (!$del_mcp_id) throw new Exception('Missing id.');
                _save_mcp_servers(array_values(array_filter(_load_mcp_servers(), fn($s) => ($s['id'] ?? '') !== $del_mcp_id)));
                echo json_encode(['success' => true]);
                break;

            case 'admin_test_mcp_server':
                $test_mcp_id    = trim($_POST['id']         ?? '');
                if ($test_mcp_id !== '') {
                    $test_server = null;
                    foreach (_load_mcp_servers() as $_ts) {
                        if (($_ts['id'] ?? '') === $test_mcp_id) { $test_server = $_ts; break; }
                    }
                    if (!$test_server) throw new Exception('MCP server not found.');
                } else {
                    // Unsaved server — test the form values as entered.
                    $test_server = [
                        'url'         => trim($_POST['url']        ?? ''),
                        'auth_token'  => $_POST['auth_token']      ?? '',
                        'auth_header' => trim($_POST['auth_header'] ?? ''),
                        'auth_prefix' => array_key_exists('auth_prefix', $_POST) ? trim($_POST['auth_prefix']) : 'Bearer',
                        'extra_headers' => _sanitize_extra_headers(json_decode($_POST['extra_headers'] ?? '[]', true)),
                    ];
                }
                $test_mcp_url = $test_server['url'] ?? '';
                if (!$test_mcp_url) throw new Exception('URL is required.');
                $test_res = _mcp_jsonrpc_test($test_mcp_url, _mcp_auth_header($test_server), 'tools/list', [], _extra_header_lines($test_server['extra_headers'] ?? []));
                if (!$test_res['ok']) {
                    echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $test_res['error']]);
                    break;
                }
                $raw_tools  = $test_res['data']['tools'] ?? [];
                $tools_out  = array_map(fn($t) => [
                    'name'        => $t['name']        ?? '',
                    'description' => $t['description'] ?? '',
                ], $raw_tools);
                echo json_encode(['success' => true, 'tool_count' => count($tools_out), 'tools' => $tools_out]);
                break;

            case 'admin_test_ai_user':
                $test_ai_provider = trim($_POST['provider'] ?? 'openai');
                $test_ai_url      = trim($_POST['api_url']  ?? '');
                $test_ai_key      = $_POST['api_key'] ?? '';
                $test_ai_model    = trim($_POST['model'] ?? '');
                $test_ai_uid      = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : null;
                if (!$test_ai_url)   throw new Exception('API URL is required.');
                if (!$test_ai_model) throw new Exception('Model is required.');
                if (!$test_ai_key && $test_ai_uid !== null) {
                    // Key field left blank ("keep existing") — resolve the stored key
                    // server-side; it is never sent back to the browser.
                    $uf_test_ai = file_exists(WIKI_SYSTEM_DATA . 'users.json') ? (json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) ?? []) : [];
                    foreach ($uf_test_ai['users'] ?? [] as $_tu) {
                        if (!empty($_tu['is_ai']) && (int)($_tu['uid'] ?? -1) === $test_ai_uid) {
                            $test_ai_key = $_tu['ai_config']['api_key'] ?? '';
                            break;
                        }
                    }
                }
                if (!$test_ai_key) throw new Exception('API key is required.');
                $test_ai_extra = _extra_header_lines(json_decode($_POST['extra_headers'] ?? '[]', true));
                $test_ai_res = _test_ai_connection($test_ai_provider, $test_ai_url, $test_ai_key, $test_ai_model, $test_ai_extra);
                if (!$test_ai_res['ok']) {
                    echo json_encode(['success' => false, 'message' => $test_ai_res['error']]);
                    break;
                }
                echo json_encode(['success' => true, 'reply' => $test_ai_res['reply']]);
                break;

            default:
                throw new Exception('Invalid action.');
        }
        wiki_audit_finish($_audit, 'success', '', $indexer, $_audit_space);
    } catch (Exception $e) {
        wiki_audit_finish($_audit, 'failure', $e->getMessage(), null, $_audit_space);
        header("HTTP/1.1 500 Internal Server Error");
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>