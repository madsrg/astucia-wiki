<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// LLM CONNECTIONS ("LLM Providers" in the admin panel)
//
// A connection is *how the wiki reaches a model*: the wire family, the endpoint, the
// API key and any gateway headers. An AI user is *who answers*: it names a connection
// and picks the model, its tuning, its prompt and its tools. Several AI users usually
// share one key, and before this split each carried its own copy — rotating a key meant
// editing every AI user, and cloning one meant copying a secret.
//
// The model deliberately stays on the AI user: one key serves many models, so a
// connection-per-model would only move the duplication up a level.
//
// Stored in WIKI_SYSTEM_DATA/llm_connections.json. Named "connections" in code because
// llm_providers.php already owns the word "provider" — the registry of wire families
// (openai, openai-responses, anthropic) that a connection's `provider` field points into.
// =================================================================

require_once __DIR__ . '/llm_providers.php';
// Outright, not behind function_exists(): the migration below must run the email one
// first, and a guard that answered "absent" in the cron runner would mark schema 3 and
// skip that step for ever.
require_once __DIR__ . '/service_auth.php';

/** The fields that belong to a connection and no longer to an AI user. */
const WIKI_LLM_CONNECTION_FIELDS = ['provider', 'api_url', 'api_key', 'extra_headers'];

function wiki_llm_connections_file(): string {
    return WIKI_SYSTEM_DATA . 'llm_connections.json';
}

/** Every connection, keys included. Never hand this to a browser — see wiki_llm_connection_public(). */
function wiki_llm_connections(): array {
    if (!defined('WIKI_SYSTEM_DATA')) return [];
    $f = wiki_llm_connections_file();
    if (!is_file($f)) return [];
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? array_values(array_filter($j, 'is_array')) : [];
}

function wiki_llm_connections_save(array $conns): bool {
    if (!defined('WIKI_SYSTEM_DATA')) return false;
    $f   = wiki_llm_connections_file();
    $tmp = $f . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode(array_values($conns),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    return $ok && @rename($tmp, $f);
}

function wiki_llm_connection(string $id): ?array {
    if ($id === '') return null;
    foreach (wiki_llm_connections() as $c) {
        if (($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

/** A connection as the admin panel may see it: the key is reduced to whether one is set. */
function wiki_llm_connection_public(array $c): array {
    $out = $c;
    unset($out['api_key']);
    $out['api_key_set'] = !empty($c['api_key']);
    return $out;
}

/**
 * The config an LLM call actually runs with: the AI user's own `ai_config` with the
 * connection's fields laid over it. Every call site reads through this, so the payload
 * builders keep reading `$config['api_key']` exactly as before.
 *
 * A record with no `connection_id` keeps its inline fields — that is a not-yet-migrated
 * record (a cron tick that beat the first web request), and it must keep working. A
 * `connection_id` that names nothing yields an empty key, which every caller already
 * reports as "no API key"; `connection_missing` lets it say why.
 */
function wiki_ai_effective_config(array $ai_user): array {
    $cfg = is_array($ai_user['ai_config'] ?? null) ? $ai_user['ai_config'] : [];
    $id  = (string)($cfg['connection_id'] ?? '');
    if ($id === '') return $cfg;
    $c = wiki_llm_connection($id);
    if ($c === null) {
        foreach (WIKI_LLM_CONNECTION_FIELDS as $k) unset($cfg[$k]);
        $cfg['api_key'] = '';
        $cfg['connection_missing'] = true;
        return $cfg;
    }
    foreach (WIKI_LLM_CONNECTION_FIELDS as $k) $cfg[$k] = $c[$k] ?? ($k === 'extra_headers' ? [] : '');
    if (($cfg['provider'] ?? '') === '') $cfg['provider'] = 'openai';
    $cfg['connection_name'] = (string)($c['name'] ?? '');
    return $cfg;
}

/** uid => name of every AI user naming this connection — what a delete has to refuse over. */
function wiki_llm_connection_users(string $id, ?array $users = null): array {
    if ($users === null) {
        $f = WIKI_SYSTEM_DATA . 'users.json';
        $users = is_file($f) ? (json_decode((string)file_get_contents($f), true)['users'] ?? []) : [];
    }
    $out = [];
    foreach ($users as $u) {
        if (empty($u['is_ai'])) continue;
        if ((string)($u['ai_config']['connection_id'] ?? '') === $id) $out[(int)($u['uid'] ?? 0)] = (string)($u['name'] ?? '');
    }
    return $out;
}

function wiki_llm_connection_new_id(): string {
    return 'llm_' . bin2hex(random_bytes(8));
}

/**
 * What makes two inline configs "the same connection". The URL is resolved to the
 * registry default first, so an AI user that left the box empty and one that typed the
 * default into it land on one connection rather than two that are secretly identical.
 */
function _wiki_llm_fingerprint(array $c): string {
    $provider = (string)($c['provider'] ?? '') ?: 'openai';
    $url      = trim((string)($c['api_url'] ?? '')) ?: llm_default_url($provider);
    $hdrs     = is_array($c['extra_headers'] ?? null) ? $c['extra_headers'] : [];
    return hash('sha256', json_encode([$provider, rtrim($url, '/'), (string)($c['api_key'] ?? ''), $hdrs]));
}

/** "OpenAI", "Anthropic" — the registry label up to its first qualifier. */
function _wiki_llm_short_label(string $provider): string {
    $label = (string)(llm_provider($provider)['label'] ?? $provider);
    $short = trim(preg_split('/\s+[—(\/]\s*/u', $label)[0] ?? $label);
    return $short !== '' ? $short : $provider;
}

/**
 * One-shot migration: inline connection fields on AI users → shared connections.
 * Marked by `schema: 3` in users.json, run from the api.php bootstrap and the cron runner.
 *
 * - AI users whose (provider, URL, key, headers) agree share one connection. Nothing is
 *   merged that differs in any of the four — a different key is a different account.
 * - Crash-safe in the only order that can be: connections are written *before* the user
 *   records are stripped, and a re-run reuses a connection whose fingerprint already
 *   exists. So a crash between the two writes leaves the keys in users.json and the next
 *   request finishes the job without creating duplicates.
 * - Runs after the email migration (schema 2), which it calls first: that one returns
 *   early at `schema >= 2`, so marking 3 first would skip it for ever.
 * - Writes `users.json.pre-connections.bak` once, before its first change — the keys are
 *   about to leave that file.
 * - Serialised with a lock: two first requests arriving together would otherwise each
 *   mint a connection id for the same key.
 */
function wiki_migrate_llm_connections(): void {
    if (!defined('WIKI_SYSTEM_DATA')) return;
    wiki_migrate_user_emails();
    $file = WIKI_SYSTEM_DATA . 'users.json';
    if (!is_file($file)) return;
    // Cheap common path: already done.
    $peek = json_decode((string)file_get_contents($file), true);
    if (!is_array($peek) || ($peek['schema'] ?? 0) >= 3) return;

    $lock = @fopen(WIKI_SYSTEM_DATA . '.llm-migrate.lock', 'c');
    if ($lock === false) return;
    try {
        if (!flock($lock, LOCK_EX)) return;
        $raw  = (string)file_get_contents($file);      // re-read under the lock
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['schema'] ?? 0) >= 3) return;

        $conns  = wiki_llm_connections();
        $by_fp  = [];
        foreach ($conns as $c) $by_fp[_wiki_llm_fingerprint($c)] = $c['id'];
        $names  = array_map(fn($c) => (string)($c['name'] ?? ''), $conns);
        $changed_users = false;
        $added = false;

        // By index — see wiki_migrate_user_emails() for why not by reference.
        foreach (($data['users'] ?? []) as $i => $u) {
            if (empty($u['is_ai'])) continue;
            $cfg = is_array($u['ai_config'] ?? null) ? $u['ai_config'] : [];
            if (($cfg['connection_id'] ?? '') !== '') continue;
            $fp = _wiki_llm_fingerprint($cfg);
            if (!isset($by_fp[$fp])) {
                $provider = (string)($cfg['provider'] ?? '') ?: 'openai';
                $url      = trim((string)($cfg['api_url'] ?? ''));
                $base     = _wiki_llm_short_label($provider);
                $name     = $base;
                // Same family twice means different endpoints or keys; the host is the
                // first thing that tells an admin which is which.
                if (in_array($name, $names, true)) {
                    $host = parse_url($url ?: llm_default_url($provider), PHP_URL_HOST) ?: '';
                    if ($host !== '') $name = "$base ($host)";
                }
                for ($n = 2; in_array($name, $names, true); $n++) $name = "$base ($n)";
                $id = wiki_llm_connection_new_id();
                $conns[] = [
                    'id'            => $id,
                    'name'          => $name,
                    'provider'      => $provider,
                    'api_url'       => $url,
                    'api_key'       => (string)($cfg['api_key'] ?? ''),
                    'extra_headers' => is_array($cfg['extra_headers'] ?? null) ? array_values($cfg['extra_headers']) : [],
                    'created_at'    => date('c'),
                    'migrated'      => true,
                ];
                $names[]    = $name;
                $by_fp[$fp] = $id;
                $added      = true;
            }
            foreach (WIKI_LLM_CONNECTION_FIELDS as $k) unset($cfg[$k]);
            $cfg['connection_id'] = $by_fp[$fp];
            $data['users'][$i]['ai_config'] = $cfg;
            $changed_users = true;
        }

        // Connections first: if this write fails, the keys are still in users.json.
        if ($added && !wiki_llm_connections_save($conns)) return;

        if ($changed_users) {
            $bak = $file . '.pre-connections.bak';
            if (!file_exists($bak)) @file_put_contents($bak, $raw);
        }
        $data['schema'] = 3;
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $file);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
