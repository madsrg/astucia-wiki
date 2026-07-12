<?php
// =================================================================
// ASTUCIA WIKI — LLM PROVIDER REGISTRY
// Loads llm_providers.json (provider id → response family, label, default
// endpoint) so provider variants can be added/adjusted without code changes.
// The response *families* themselves (how requests are built and replies
// parsed) live in code (ai_core.php): anthropic, openai-chat, openai-responses.
// If the JSON is missing or invalid, a built-in default set is used so the app
// always works out of the box.
// =================================================================

function llm_providers(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        ['id' => 'openai',           'family' => 'openai-chat',      'label' => 'OpenAI / compatible — Chat Completions (OpenAI, OpenRouter, Groq, Ollama, …)', 'default_url' => 'https://api.openai.com/v1/chat/completions'],
        ['id' => 'openai-responses', 'family' => 'openai-responses', 'label' => 'OpenAI — Responses API (/v1/responses)',                                       'default_url' => 'https://api.openai.com/v1/responses'],
        ['id' => 'anthropic',        'family' => 'anthropic',        'label' => 'Anthropic (Claude)',                                                            'default_url' => 'https://api.anthropic.com/v1/messages'],
    ];

    $file = __DIR__ . '/llm_providers.json';
    if (is_file($file)) {
        $j = json_decode(file_get_contents($file), true);
        if (isset($j['providers']) && is_array($j['providers'])) {
            $clean = [];
            foreach ($j['providers'] as $p) {
                if (empty($p['id'])) continue;
                $clean[] = [
                    'id'          => $p['id'],
                    'family'      => $p['family']      ?? 'openai-chat',
                    'label'       => $p['label']       ?? $p['id'],
                    'default_url' => $p['default_url'] ?? '',
                ];
            }
            if ($clean) { $cache = $clean; return $cache; }
        }
    }
    $cache = $defaults;
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
