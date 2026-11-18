<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// MAPS — a ```map block in a Markdown page, drawn with Leaflet (modules/maps)
//
// The page stores settings, not provider HTML: address, coordinates, zoom, size, type. So a
// page survives a change of map provider, reads as text in Obsidian or a diff, and needs
// no raw HTML.
//
// This file is the server half:
// - the admin's settings (settings.json → `maps`): on/off, the address lookup service, and
//   the tile servers for the three map types — blank means the built-in default;
// - the address lookup (`geocode`), made **once, when an author inserts a map**: the block
//   stores the coordinates, so a reader never causes a lookup. It goes through the server
//   rather than the browser because Nominatim's usage policy asks a client to identify
//   itself and to stay under one request a second — which only one place can promise for
//   the whole wiki — and so results can be cached. The User-Agent names the software,
//   never the wiki, its users or the person searching.
// Tiles are fetched by each reader's browser; that is inherent in any embedded map, and is
// why an admin can switch maps off or point them at their own tile server.
// =================================================================
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/outbound_http.php';

const WIKI_MAP_TYPES = ['streets', 'satellite', 'topo'];

const WIKI_MAP_GEOCODER_DEFAULT = 'https://nominatim.openstreetmap.org/search';

// Built-in tile layers. The attributions are part of each provider's terms of use and are
// shown on every map. HTML because Leaflet renders attribution as HTML — these are ours;
// an admin's text is escaped (wiki_map_layers()).
const WIKI_MAP_TILES_DEFAULT = [
    'streets'   => ['url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'max' => 19,
                    'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'],
    'satellite' => ['url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', 'max' => 19,
                    'attribution' => 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'],
    'topo'      => ['url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', 'max' => 17,
                    'attribution' => 'Map data &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, SRTM | Style &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)'],
];

const WIKI_GEOCODE_CACHE_DAYS = 30;
const WIKI_GEOCODE_CACHE_MAX  = 1000;

/** The stored settings, every key present. Blank strings mean "the built-in default". */
function wiki_map_settings(): array {
    $raw = wiki_setting('maps', []);
    $raw = is_array($raw) ? $raw : [];
    $tiles = [];
    foreach (WIKI_MAP_TYPES as $type) {
        $t = is_array($raw['tiles'][$type] ?? null) ? $raw['tiles'][$type] : [];
        $tiles[$type] = ['url' => (string)($t['url'] ?? ''), 'attribution' => (string)($t['attribution'] ?? '')];
    }
    return [
        'enabled'  => ($raw['enabled'] ?? true) !== false,     // on unless switched off
        'geocoder' => (string)($raw['geocoder'] ?? ''),
        'tiles'    => $tiles,
    ];
}

/**
 * Validate and store settings from the admin form. Throws with a sentence for the admin.
 * A tile URL must be http(s) and carry {z}, {x} and {y}, or every map would be blank.
 */
function wiki_map_settings_save(array $in): bool {
    $geo = trim((string)($in['geocoder'] ?? ''));
    if ($geo !== '' && !preg_match('#^https?://[^\s]+$#i', $geo)) {
        throw new InvalidArgumentException('The address lookup service must be an http:// or https:// address.');
    }
    $tiles = [];
    foreach (WIKI_MAP_TYPES as $type) {
        $url  = trim((string)($in['tiles'][$type]['url'] ?? ''));
        $attr = trim((string)($in['tiles'][$type]['attribution'] ?? ''));
        if ($url !== '') {
            if (!preg_match('#^https?://[^\s]+$#i', $url)
                || !str_contains($url, '{z}') || !str_contains($url, '{x}') || !str_contains($url, '{y}')) {
                throw new InvalidArgumentException("The $type tile address must be http(s) and contain {z}, {x} and {y}.");
            }
            if ($attr === '') {
                throw new InvalidArgumentException("Give the $type tiles an attribution — tile providers require one.");
            }
        }
        $tiles[$type] = ['url' => $url, 'attribution' => $url === '' ? '' : mb_substr($attr, 0, 300)];
    }
    return wiki_setting_set('maps', [
        'enabled'  => !empty($in['enabled']),
        'geocoder' => $geo,
        'tiles'    => $tiles,
    ]);
}

/**
 * What the browser needs to draw maps: whether they are on, and the effective tile layer
 * for each type. Printed into index.php — there is nothing secret in a tile URL, and a
 * request per page for three strings would be waste.
 */
function wiki_map_client_config(): array {
    $s = wiki_map_settings();
    $layers = [];
    foreach (WIKI_MAP_TYPES as $type) {
        $custom = $s['tiles'][$type];
        $layers[$type] = $custom['url'] !== ''
            ? ['url' => $custom['url'], 'max' => 19,
               'attribution' => htmlspecialchars($custom['attribution'], ENT_QUOTES)]   // the admin's text, as text
            : WIKI_MAP_TILES_DEFAULT[$type];
    }
    return ['enabled' => $s['enabled'], 'layers' => $layers];
}

function wiki_geocoder_url(): string {
    $g = wiki_map_settings()['geocoder'];
    return $g !== '' ? $g : WIKI_MAP_GEOCODER_DEFAULT;
}

function _wiki_geocode_cache_file(): ?string {
    return (defined('WIKI_SYSTEM_DATA') && WIKI_SYSTEM_DATA) ? rtrim(WIKI_SYSTEM_DATA, '/') . '/geocode_cache.json' : null;
}

/**
 * Look an address up. Returns up to five [label, lat, lon, kind]; throws with a sentence
 * when the service cannot be reached or answers nonsense.
 *
 * Cached for WIKI_GEOCODE_CACHE_DAYS per (service, query, language), and serialised
 * wiki-wide on a lock that keeps at least a second between requests to the service.
 */
function wiki_geocode(string $query, string $lang = ''): array {
    $query = trim(preg_replace('/\s+/u', ' ', $query));
    $service = wiki_geocoder_url();
    $key = sha1($service . "\n" . mb_strtolower($query) . "\n" . $lang);
    $file = _wiki_geocode_cache_file();

    $cache = ($file && is_file($file)) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $hit = $cache[$key] ?? null;
    if (is_array($hit) && ($hit['at'] ?? 0) > time() - WIKI_GEOCODE_CACHE_DAYS * 86400) {
        return ['results' => $hit['results'], 'cached' => true];
    }

    // One request a second, for the whole wiki: Nominatim's policy, and polite to any
    // self-hosted service too. The lock is held across the request, so a second author
    // waits for the first rather than both firing at once.
    $lock = $file ? @fopen($file . '.lock', 'c+') : false;
    if ($lock) {
        flock($lock, LOCK_EX);
        $last = (float)stream_get_contents($lock);
        $wait = 1.0 - (microtime(true) - $last);
        if ($wait > 0) usleep((int)($wait * 1e6));
    }
    try {
        $url = $service . (str_contains($service, '?') ? '&' : '?') . http_build_query([
            'q' => $query, 'format' => 'jsonv2', 'limit' => 5, 'addressdetails' => 0,
        ] + ($lang !== '' ? ['accept-language' => $lang] : []));
        $version = is_file(__DIR__ . '/VERSION') ? trim((string)file_get_contents(__DIR__ . '/VERSION')) : '';
        // The software, and where to read about it — never the wiki's name, its host, the
        // user or anything else about who is searching.
        $res = wiki_http_request('GET', $url, [
            'User-Agent' => 'AstuciaWiki' . ($version !== '' ? '/' . $version : '') . ' (+https://astucia.wiki)',
            'Accept'     => 'application/json',
        ]);
    } finally {
        if ($lock) {
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true)); fflush($lock);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    if ($res['status'] !== 200) throw new RuntimeException('The address lookup service answered HTTP ' . $res['status'] . '.');
    $data = json_decode($res['body'], true);
    if (!is_array($data)) throw new RuntimeException('The address lookup service did not answer with a list of places.');

    $results = [];
    foreach (array_slice($data, 0, 5) as $r) {
        if (!is_array($r) || !is_numeric($r['lat'] ?? null) || !is_numeric($r['lon'] ?? null)) continue;
        $results[] = [
            'label' => mb_substr((string)($r['display_name'] ?? ''), 0, 300),
            'lat'   => round((float)$r['lat'], 6),
            'lon'   => round((float)$r['lon'], 6),
            'kind'  => mb_substr((string)($r['type'] ?? ($r['category'] ?? '')), 0, 40),
        ];
    }

    if ($file) {
        $cache = ($file && is_file($file)) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
        $cache[$key] = ['at' => time(), 'results' => $results];
        if (count($cache) > WIKI_GEOCODE_CACHE_MAX) {
            uasort($cache, fn($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
            $cache = array_slice($cache, 0, WIKI_GEOCODE_CACHE_MAX, true);
        }
        @file_put_contents($file, json_encode($cache), LOCK_EX);
    }
    return ['results' => $results, 'cached' => false];
}

/**
 * The static export has no Leaflet: a ```map block becomes a link to the place on
 * openstreetmap.org, which is what a reader of a static page can actually use.
 */
function wiki_map_static_blocks(string $markdown): string {
    return preg_replace_callback('/^```map[ \t]*\r?\n(.*?)\r?\n```[ \t]*$/ms', function ($m) {
        $o = [];
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            if (preg_match('/^\s*([a-z]+)\s*:\s*(.*?)\s*$/i', $line, $kv)) $o[strtolower($kv[1])] = $kv[2];
        }
        if (!is_numeric($o['lat'] ?? null) || !is_numeric($o['lon'] ?? null)) return $m[0];
        $lat = (float)$o['lat']; $lon = (float)$o['lon'];
        $zoom = max(1, min(19, (int)($o['zoom'] ?? 15)));
        $label = $o['label'] ?? ($o['address'] ?? sprintf('%.5f, %.5f', $lat, $lon));
        $href = sprintf('https://www.openstreetmap.org/?mlat=%F&mlon=%F#map=%d/%F/%F', $lat, $lon, $zoom, $lat, $lon);
        return '<p class="static-map"><a href="' . htmlspecialchars($href) . '">&#128205; '
             . htmlspecialchars($label) . '</a></p>';
    }, $markdown);
}
