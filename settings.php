<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// GLOBAL SETTINGS — WIKI_SYSTEM_DATA/settings.json
//
// The first wiki-wide setting an administrator can change from the UI, and therefore the
// home for the ones after it. Everything in config.php is deliberately server-operator
// territory (paths, credentials, auth mode) and needs a deploy to change; this is for
// things an admin toggles at runtime.
//
// Not to be confused with spaces.json, which is per-Space (see space_settings.php).
//
// Absent file, absent key and unwritable directory all read as "not set", so a wiki that
// never touches this behaves exactly as it did before the file existed.
// =================================================================

function wiki_settings_file(): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/settings.json';
}

function wiki_settings_all(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $f = wiki_settings_file();
    $cache = ($f && is_file($f))
        ? (json_decode((string)@file_get_contents($f), true) ?: [])
        : [];
    return $cache;
}

function wiki_setting(string $key, $default = null) {
    $all = wiki_settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function wiki_setting_set(string $key, $value): bool {
    $f = wiki_settings_file();
    if (!$f) return false;
    $all = wiki_settings_all(true);
    $all[$key] = $value;
    $ok = @file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT)) !== false;
    if ($ok) wiki_settings_all(true);
    return $ok;
}
