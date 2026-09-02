<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// LLM TRACE — the full conversation with the model, written to a wiki page.
//
// `/debug` already appends a token-accounting table to the thread. That answers "what did
// this cost"; it cannot answer "what did we actually send". This records every exchange —
// each API request and response, each tool call and its result, each MCP round trip — and
// writes them to a Markdown page in a `debug/` subfolder beside the chat, so the
// transcript is readable, searchable and diffable like any other page.
//
// Collected in memory during one request and written once at the end: the trace is only
// worth having whole, and a half-written page after a failed call is worse than none.
//
// The one hard rule: **redact credentials**. The request headers carry the provider API
// key, and this transcript becomes a wiki page that every editor can read. A leaked key is
// a worse outcome than any debugging problem it could solve.
// =================================================================

// Per-body cap. A trace holds the full system prompt and any page context, which is the
// point — but a runaway response should not produce a megabyte page nobody can open.
const LLM_TRACE_MAX_BODY = 100000;

function wiki_trace_start(): void {
    $GLOBALS['_wiki_llm_trace'] = [];
    $GLOBALS['_wiki_llm_trace_on'] = true;
}

function wiki_trace_active(): bool {
    return !empty($GLOBALS['_wiki_llm_trace_on']);
}

function wiki_trace_stop(): void {
    $GLOBALS['_wiki_llm_trace_on'] = false;
}

function wiki_trace_all(): array {
    return $GLOBALS['_wiki_llm_trace'] ?? [];
}

function wiki_trace_add(array $entry): void {
    if (!wiki_trace_active()) return;
    $entry['at'] = date('H:i:s');
    $GLOBALS['_wiki_llm_trace'][] = $entry;
}

/** Anything that looks like a credential becomes a stub — see the note at the top. */
function wiki_trace_redact_headers(array $headers): array {
    $out = [];
    foreach ($headers as $h) {
        $out[] = preg_replace_callback(
            '/^([^:]*(?:authorization|api[-_ ]?key|token|secret|cookie)[^:]*:\s*)(.+)$/i',
            fn($m) => $m[1] . '«redacted»',
            (string)$h);
    }
    return $out;
}

function _trace_block(?string $body): string {
    $body = (string)$body;
    if ($body === '') return '_(empty)_';
    if (strlen($body) > LLM_TRACE_MAX_BODY) {
        $body = substr($body, 0, LLM_TRACE_MAX_BODY) . "\n… truncated at " . LLM_TRACE_MAX_BODY . " bytes …";
    }
    // Pretty-print JSON when it is JSON: a single-line payload is unreadable, and the
    // whole point of writing this to a page is that a person reads it.
    $decoded = json_decode($body, true);
    if ($decoded !== null) {
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($pretty !== false) $body = $pretty;
    }
    // Fenced with four backticks: a payload frequently contains a ``` fence of its own
    // (the model quoting Markdown back at us), which would end a three-backtick block early.
    return "````json\n" . $body . "\n````";
}

/**
 * Render the collected trace as a Markdown page.
 *
 * @param string $stats The existing /debug accounting table, appended at the end.
 */
function wiki_trace_markdown(string $user, string $ai_name, string $model, string $provider,
                             string $space, string $chat, string $prompt, string $stats): string {
    $md  = "# LLM debug — " . date('Y-m-d H:i:s') . "\n\n";
    $md .= "| | |\n|---|---|\n";
    $md .= "| **Requested by** | " . str_replace('|', '\\|', $user) . " |\n";
    $md .= "| **AI user** | " . str_replace('|', '\\|', $ai_name) . " |\n";
    $md .= "| **Model** | `" . str_replace('|', '\\|', $model) . "` (" . str_replace('|', '\\|', $provider) . ") |\n";
    $md .= "| **Space** | " . str_replace('|', '\\|', $space ?: '(root)') . " |\n";
    $md .= "| **Chat** | " . str_replace('|', '\\|', $chat) . " |\n\n";
    $md .= "## Prompt\n\n";
    // Quoted rather than fenced so the prompt reads as prose, with blank lines preserved.
    $md .= implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", trim($prompt)))) . "\n\n";

    $n = 0;
    foreach (wiki_trace_all() as $e) {
        $n++;
        if (($e['type'] ?? '') === 'llm') {
            $md .= "## {$n}. LLM call — " . ($e['at'] ?? '') . "\n\n";
            $md .= "`POST " . ($e['url'] ?? '') . "`\n\n";
            if (!empty($e['headers'])) {
                $md .= "**Headers**\n\n````\n" . implode("\n", $e['headers']) . "\n````\n\n";
            }
            $md .= "**Request**\n\n" . _trace_block($e['request'] ?? '') . "\n\n";
            $md .= "**Response** — HTTP " . ($e['http'] ?? '?')
                 . (isset($e['ms']) ? ', ' . round($e['ms'] / 1000, 2) . ' s' : '') . "\n\n";
            $md .= _trace_block($e['response'] ?? '') . "\n\n";
            if (!empty($e['error'])) $md .= "**Transport error:** " . $e['error'] . "\n\n";
        } elseif (($e['type'] ?? '') === 'tool') {
            $md .= "## {$n}. Tool — `" . ($e['name'] ?? '?') . "`"
                 . (!empty($e['mcp']) ? ' _(MCP: ' . $e['mcp'] . ')_' : '') . " — " . ($e['at'] ?? '') . "\n\n";
            $md .= "**Input**\n\n" . _trace_block(json_encode($e['input'] ?? [])) . "\n\n";
            $md .= "**Output**\n\n````\n" . substr((string)($e['output'] ?? ''), 0, LLM_TRACE_MAX_BODY) . "\n````\n\n";
        }
    }
    if ($n === 0) $md .= "_No calls were recorded._\n\n";

    $md .= "---\n\n## Statistics\n\n" . $stats . "\n";
    return $md;
}

/**
 * Write the trace beside the chat. Returns the relative path, or null if it could not be
 * written. Never throws — a debug artefact must not break the reply it describes.
 *
 * The name increments rather than overwriting: each run is its own record, and a debug
 * page silently replaced by the next attempt is the opposite of an audit trail.
 */
function wiki_trace_write_page(string $space_dir, string $chat_file, string $markdown, $indexer, array $ai_user): ?string {
    if (function_exists('wiki_space_dir_is_readonly') && wiki_space_dir_is_readonly($space_dir)) return null;
    // A subfolder beside the chat rather than in with the content: these accumulate one
    // page per exchange while /debug is on, and a folder keeps them out of the way of the
    // pages people are actually reading. Created on first write, so a wiki that never
    // turns /debug on never grows one.
    $dir = dirname($chat_file) . '/debug';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;

    $base = 'LLM debug';
    $name = $base . '.md';
    $i    = 0;
    while (file_exists($dir . '/' . $name)) {
        $name = $base . '(' . (++$i) . ').md';
        if ($i > 999) return null;           // something is wrong; stop rather than spin
    }
    $abs = $dir . '/' . $name;
    if (@file_put_contents($abs, $markdown) === false) return null;

    $rel = ltrim(str_replace(rtrim($space_dir, '/'), '', $abs), '/');
    if ($indexer) $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
    // A page appearing in the wiki should be traceable like any other.
    if (function_exists('wiki_audit_log')) {
        wiki_audit_log('create', 'success', [
            'object' => $rel, 'object_category' => 'page', 'api_action' => 'llm_trace',
            'space'  => basename(rtrim($space_dir, '/')),
        ]);
    }
    return $rel;
}
