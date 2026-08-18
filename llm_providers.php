<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// ASTUCIA WIKI — LLM PROVIDER REGISTRY
// Loads llm_providers.json (provider id → response family, label, default
// endpoint) so provider variants can be added/adjusted without code changes.
// The response *families* themselves (how requests are built and replies
// parsed) live in code (ai_core.php): anthropic, openai-chat, openai-responses.
// If the JSON is missing or invalid, a built-in default set is used so the app
// always works out of the box.
//
// The same file also carries "model_rules": which tuning parameters a given
// MODEL accepts. That is deliberately separate from the provider — one
// Anthropic endpoint serves models that require temperature and models that
// reject it — see llm_model_rules().
// =================================================================

// Reads llm_providers.json once and returns ['providers' => [...], 'model_rules' => [...]].
// Either key is an empty array when the file is missing or that section is
// absent/malformed; callers substitute their own built-in defaults.
function _llm_registry(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = ['providers' => [], 'model_rules' => []];
    $file  = __DIR__ . '/llm_providers.json';
    if (!is_file($file)) return $cache;
    $j = json_decode(file_get_contents($file), true);
    if (!is_array($j)) return $cache;

    if (isset($j['providers']) && is_array($j['providers'])) {
        foreach ($j['providers'] as $p) {
            if (empty($p['id'])) continue;
            $cache['providers'][] = [
                'id'          => $p['id'],
                'family'      => $p['family']      ?? 'openai-chat',
                'label'       => $p['label']       ?? $p['id'],
                'default_url' => $p['default_url'] ?? '',
            ];
        }
    }
    if (isset($j['model_rules']) && is_array($j['model_rules'])) {
        foreach ($j['model_rules'] as $r) {
            if (empty($r['match']) || !is_string($r['match'])) continue;
            $cache['model_rules'][] = $r;
        }
    }
    return $cache;
}

function llm_providers(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        ['id' => 'openai',           'family' => 'openai-chat',      'label' => 'OpenAI / compatible — Chat Completions (OpenAI, OpenRouter, Groq, Ollama, …)', 'default_url' => 'https://api.openai.com/v1/chat/completions'],
        ['id' => 'openai-responses', 'family' => 'openai-responses', 'label' => 'OpenAI — Responses API (/v1/responses)',                                       'default_url' => 'https://api.openai.com/v1/responses'],
        ['id' => 'anthropic',        'family' => 'anthropic',        'label' => 'Anthropic (Claude)',                                                            'default_url' => 'https://api.anthropic.com/v1/messages'],
    ];

    $cache = _llm_registry()['providers'] ?: $defaults;
    return $cache;
}

// Resolve a provider id to its profile; unknown ids default to the openai-chat
// family so a stale/typo'd config still behaves sensibly rather than erroring.
function llm_provider(string $id): array {
    foreach (llm_providers() as $p) {
        if ($p['id'] === $id) return $p;
    }
    return ['id' => $id, 'family' => 'openai-chat', 'label' => $id, 'default_url' => 'https://api.openai.com/v1/chat/completions'];
}

function llm_family(string $id): string {
    return llm_provider($id)['family'] ?: 'openai-chat';
}

function llm_default_url(string $id): string {
    return llm_provider($id)['default_url'] ?: 'https://api.openai.com/v1/chat/completions';
}

// --- Per-model request rules ---------------------------------------------------

// Vendor/region namespaces that gateways prepend to a model id. Stripping them
// lets one rule pattern cover the same model however it was reached.
const LLM_MODEL_NAMESPACES = [
    'anthropic', 'openai', 'google', 'meta', 'meta-llama', 'mistral', 'mistralai',
    'cohere', 'amazon', 'deepseek', 'qwen', 'xai', 'bedrock', 'vertex_ai', 'azure',
    'us', 'eu', 'apac', 'global',
];

// Reduce a configured model id to the bare model name that model_rules match
// against: "anthropic/claude-opus-5", "us.anthropic.claude-opus-5" and
// "claude-opus-5@20260101" all become "claude-opus-5".
function llm_model_basename(string $model): string {
    $m = strtolower(trim($model));
    // A "/" prefix is unambiguous — no model id contains a slash.
    if (($pos = strrpos($m, '/')) !== false) $m = substr($m, $pos + 1);
    // A dotted prefix is only a namespace when the leading segment is a known
    // vendor/region: plenty of model ids ("gpt-4.1") contain dots of their own.
    while (($pos = strpos($m, '.')) !== false && in_array(substr($m, 0, $pos), LLM_MODEL_NAMESPACES, true)) {
        $m = substr($m, $pos + 1);
    }
    // Version/tag suffixes: Vertex "…@20260101", Bedrock "…-v2:0", Ollama "llama3:8b".
    return (string)preg_replace('/[@:].*$/', '', $m);
}

/**
 * Which tuning parameters this model accepts.
 *
 * Keys: sampling (bool — may we send temperature/top_p/top_k?), thinking
 * ('adaptive' | 'budget_tokens' | null), effort (bool — output_config.effort),
 * reasoning_effort (bool — OpenAI reasoning_effort / reasoning.effort).
 *
 * An unmatched model gets the conservative defaults, which are the behaviour
 * that predates this table: send temperature, never ask for thinking. Requests
 * are additionally retried without a parameter the API rejects, so a model the
 * table has never heard of still works — see _is_sampling_param_error().
 */
function llm_model_rules(string $model): array {
    static $cache = [];
    if (isset($cache[$model])) return $cache[$model];

    $defaults = ['sampling' => true, 'thinking' => null, 'effort' => false, 'reasoning_effort' => false];
    $builtin  = [
        ['match' => '^claude-(fable-5|mythos-5|opus-5|opus-4-7|opus-4-8|sonnet-5)', 'sampling' => false, 'thinking' => 'adaptive', 'effort' => true],
        ['match' => '^claude-(opus-4-6|sonnet-4-6)',                                'sampling' => true,  'thinking' => 'adaptive', 'effort' => true],
        ['match' => '^claude-',                                                     'sampling' => true,  'thinking' => 'budget_tokens'],
        ['match' => '^(o[1-9]($|[-.])|gpt-5)',                                      'sampling' => false, 'reasoning_effort' => true],
    ];

    $name  = llm_model_basename($model);
    $rules = _llm_registry()['model_rules'] ?: $builtin;
    foreach ($rules as $rule) {
        // A malformed pattern must not take the AI features down with it.
        if (@preg_match('#' . str_replace('#', '\#', $rule['match']) . '#i', $name) !== 1) continue;
        $cache[$model] = array_merge($defaults, array_intersect_key($rule, $defaults));
        return $cache[$model];
    }
    $cache[$model] = $defaults;
    return $cache[$model];
}
