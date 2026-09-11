<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// SYSTEM PROMPT GALLERY — a starting point for an AI user's system prompt
//
// A curated list of role prompts (Product Owner, Developer, …) maintained centrally and
// published as one JSON document. Choosing one **copies its text into the textarea**: a
// snapshot, never a live link. An upstream edit must not be able to change how somebody's
// AI user behaves without them touching it.
//
// This is the only part of the wiki that talks to the vendor, so it is built to be
// obvious and optional:
//
//   - **Server-side.** The browser never contacts astucia.wiki, so a reader's IP is not
//     disclosed, no CORS is involved, and it still works when the browser sits on an
//     isolated network but the server does not.
//   - **Only on request.** Nothing is fetched on page load; only when an administrator
//     opens the gallery.
//   - **Switch-off-able.** SYSTEM_PROMPT_GALLERY_URL = '' uses the bundled copy alone and
//     the wiki makes no outbound request at all.
//   - **Works offline.** A copy ships in the image, so an air-gapped install still has a
//     gallery. The remote fetch is "is there a newer list", not "is there a list".
// =================================================================

const WIKI_GALLERY_CACHE_TTL = 86400;   // a day; the list changes rarely
const WIKI_GALLERY_TIMEOUT   = 6;       // seconds — a dialog must not hang on a dead host

function wiki_gallery_url(): string {
    return defined('SYSTEM_PROMPT_GALLERY_URL') ? trim((string)SYSTEM_PROMPT_GALLERY_URL) : '';
}

function wiki_gallery_cache_file(): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/system_prompts_cache.json';
}

/** The copy that ships with the wiki. Always present, so there is always a gallery. */
function wiki_gallery_bundled(): array {
    $f = __DIR__ . '/system_prompts.json';
    if (!is_file($f)) return ['schema' => 1, 'updated' => null, 'prompts' => []];
    return wiki_gallery_valid(json_decode((string)@file_get_contents($f), true)) ?: [];
}

/**
 * Accept only a document shaped the way the picker expects, and only the fields it uses.
 * A remote document is untrusted input: it is rendered into an admin dialog and copied
 * into a system prompt, so anything unexpected is dropped here rather than downstream.
 */
function wiki_gallery_valid($doc): ?array {
    if (!is_array($doc) || !isset($doc['prompts']) || !is_array($doc['prompts'])) return null;
    $out = [];
    foreach ($doc['prompts'] as $p) {
        if (!is_array($p)) continue;
        $id     = trim((string)($p['id'] ?? ''));
        $title  = trim((string)($p['title'] ?? ''));
        $prompt = (string)($p['prompt'] ?? '');
        if ($id === '' || $title === '' || trim($prompt) === '') continue;
        $out[] = [
            'id'          => mb_substr($id, 0, 64),
            'title'       => mb_substr($title, 0, 120),
            'description' => mb_substr(trim((string)($p['description'] ?? '')), 0, 400),
            'category'    => mb_substr(trim((string)($p['category'] ?? '')), 0, 60),
            'prompt'      => mb_substr($prompt, 0, 20000),
        ];
    }
    if (!$out) return null;
    return [
        'schema'  => (int)($doc['schema'] ?? 1),
        'updated' => mb_substr(trim((string)($doc['updated'] ?? '')), 0, 40),
        'prompts' => $out,
    ];
}

function wiki_gallery_read_cache(): ?array {
    $f = wiki_gallery_cache_file();
    if (!$f || !is_file($f)) return null;
    $c = json_decode((string)@file_get_contents($f), true);
    if (!is_array($c) || !isset($c['fetched_at'], $c['doc'])) return null;
    $doc = wiki_gallery_valid($c['doc']);
    return $doc ? ['fetched_at' => (int)$c['fetched_at'], 'doc' => $doc] : null;
}

function wiki_gallery_write_cache(array $doc): void {
    $f = wiki_gallery_cache_file();
    if ($f) @file_put_contents($f, json_encode(['fetched_at' => time(), 'doc' => $doc]));
}

/** One HTTPS GET. Returns null on any failure — the caller always has a fallback. */
function wiki_gallery_fetch(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => WIKI_GALLERY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => WIKI_GALLERY_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'AstuciaWiki/gallery',
        // A hostile or misconfigured endpoint must not be able to exhaust memory.
        CURLOPT_BUFFERSIZE     => 16384,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => fn($c, $dlTotal, $dlNow) => $dlNow > 1048576 ? 1 : 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false || $code < 200 || $code >= 300) return null;
    return wiki_gallery_valid(json_decode((string)$body, true));
}

/**
 * The gallery, and where it came from.
 *
 * Order: a fresh cache, then the network, then a stale cache, then the bundled copy. The
 * stale-before-bundled step matters — a wiki that has seen a newer list once should keep
 * showing it when the network is briefly unavailable.
 *
 * @return array{prompts:array,source:string,updated:?string,url:string}
 */
function wiki_gallery_get(bool $force = false): array {
    $url    = wiki_gallery_url();
    $cached = wiki_gallery_read_cache();

    if (!$force && $cached && (time() - $cached['fetched_at']) < WIKI_GALLERY_CACHE_TTL) {
        return $cached['doc'] + ['source' => 'cache', 'url' => $url];
    }
    if ($url !== '') {
        $doc = wiki_gallery_fetch($url);
        if ($doc) {
            wiki_gallery_write_cache($doc);
            return $doc + ['source' => 'remote', 'url' => $url];
        }
    }
    if ($cached) return $cached['doc'] + ['source' => 'cache-stale', 'url' => $url];
    $bundled = wiki_gallery_bundled();
    return $bundled + ['source' => 'bundled', 'url' => $url];
}
