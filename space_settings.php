<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PER-SPACE SETTINGS — WIKI_SYSTEM_DATA/spaces.json
//
// Keyed by space name:  { "Archive": { "readonly": true } }
//
// Kept beside users.json rather than inside the space, because a read-only flag is
// configuration, not content: a file in the space would be committed to that space's
// git repo, travel with an rsync of PAGES_DIR, and show up in the file tree.
// Spaces with no entry behave normally, so the file is absent on most installs.
//
// Shared by api.php (guard + admin actions), wiki_ai_tools.php (AI/MCP writes) and
// run_ai_agent_jobs.php (cron) — the three places that can write into a space.
// =================================================================

function wiki_space_settings_file(): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/spaces.json';
}

// Cached per request: the read-only guard runs on every mutating call.
function wiki_space_settings_all(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $file  = wiki_space_settings_file();
    $cache = ($file && is_file($file))
        ? (json_decode((string)@file_get_contents($file), true) ?: [])
        : [];
    return $cache;
}

function wiki_space_settings_save(array $all): bool {
    $file = wiki_space_settings_file();
    if (!$file) return false;
    // Drop empty records so the file stays a list of exceptions.
    foreach ($all as $name => $rec) {
        if (!is_array($rec) || !array_filter($rec)) unset($all[$name]);
    }
    $ok = @file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT)) !== false;
    if ($ok) wiki_space_settings_all(true);
    return $ok;
}

function wiki_space_settings_get(string $space): array {
    $all = wiki_space_settings_all();
    return is_array($all[$space] ?? null) ? $all[$space] : [];
}

/**
 * Is this space frozen?
 *
 * Applies to every actor including administrators — the point of the mode is that
 * nothing can change the content while it is on. An admin turns it off in the Space
 * settings dialog, which is deliberately not gated by it.
 *
 * $space is a space *name*; '' (a root-level wiki, no space) is never read-only.
 */
function wiki_space_is_readonly(?string $space): bool {
    $space = trim((string)$space);
    if ($space === '') return false;
    return !empty(wiki_space_settings_get(basename($space))['readonly']);
}

// Convenience for the callers that hold a directory rather than a name.
function wiki_space_dir_is_readonly(?string $space_dir): bool {
    if (!$space_dir) return false;
    $dir = rtrim($space_dir, '/');
    if ($dir === rtrim(PAGES_DIR, '/')) return false;   // the root itself is not a space
    return wiki_space_is_readonly(basename($dir));
}

function wiki_space_set_readonly(string $space, bool $readonly): bool {
    $space = basename(trim($space));
    if ($space === '') return false;
    $all = wiki_space_settings_all(true);
    $rec = is_array($all[$space] ?? null) ? $all[$space] : [];
    if ($readonly) $rec['readonly'] = true;
    else           unset($rec['readonly']);
    $all[$space] = $rec;
    return wiki_space_settings_save($all);
}

// Keep settings attached to the space through a rename (mirrors what rename_space
// already does for the `spaces` arrays in users.json).
function wiki_space_settings_rename(string $old, string $new): void {
    $old = basename($old);
    $new = basename($new);
    $all = wiki_space_settings_all(true);
    if (!array_key_exists($old, $all)) return;
    $all[$new] = $all[$old];
    unset($all[$old]);
    wiki_space_settings_save($all);
}

function wiki_space_settings_forget(string $space): void {
    $space = basename($space);
    $all   = wiki_space_settings_all(true);
    if (!array_key_exists($space, $all)) return;
    unset($all[$space]);
    wiki_space_settings_save($all);
}
