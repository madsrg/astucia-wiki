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

/**
 * How a Space treats page metadata (front matter).
 *
 * Per-Space rather than wiki-wide from the start, because the granularity is what the
 * feature is about: one Space can be a mirror of an Obsidian vault, where the wiki should
 * keep its hands off the `---` block, while another is exported to a static site and wants
 * its metadata maintained. A wiki-wide switch forces the same policy on both.
 *
 * Both settings default to **off**, and a Space with no record in spaces.json is off —
 * which is what makes a new Space off without anything being written for it.
 *
 * A root-level wiki (no Space) has no record to carry a setting and is therefore always
 * off. That only affects installs whose content predates Spaces, since the UI cannot
 * select PAGES_DIR itself once any Space exists.
 */
const WIKI_FM_EDIT_MODES = ['off', 'manual'];
const WIKI_FM_AUTOSTAMP_MODES = ['off', 'on'];

function wiki_space_fm_edit(?string $space): string {
    $space = trim((string)$space);
    if ($space === '') return 'off';
    $mode = wiki_space_settings_get(basename($space))['fm_edit'] ?? 'off';
    return in_array($mode, WIKI_FM_EDIT_MODES, true) ? $mode : 'off';
}

// Convenience for the callers that hold a directory rather than a name (mirrors
// wiki_space_dir_is_readonly).
function wiki_space_dir_fm_edit(?string $space_dir): string {
    if (!$space_dir) return 'off';
    $dir = rtrim($space_dir, '/');
    if ($dir === rtrim(PAGES_DIR, '/')) return 'off';
    return wiki_space_fm_edit(basename($dir));
}

function wiki_space_set_fm_edit(string $space, string $mode): bool {
    $space = basename(trim($space));
    if ($space === '') return false;
    if (!in_array($mode, WIKI_FM_EDIT_MODES, true)) return false;
    $all = wiki_space_settings_all(true);
    $rec = is_array($all[$space] ?? null) ? $all[$space] : [];
    // 'off' removes the key rather than storing it, so spaces.json stays a list of
    // exceptions and wiki_space_settings_save() can drop a record that holds nothing.
    if ($mode === 'off') unset($rec['fm_edit']);
    else                 $rec['fm_edit'] = $mode;
    $all[$space] = $rec;
    return wiki_space_settings_save($all);
}

/**
 * Does this Space maintain `created` / `createdBy` / `updated` / `updatedBy` in a page's
 * own front matter when the page is saved?
 *
 * Independent of fm_edit rather than a third mode of it, because the combination is the
 * case people want: maintain your own `status:` by hand *and* have the timestamps kept
 * current. Three exclusive modes forbid exactly that.
 */
function wiki_space_fm_autostamp(?string $space): string {
    $space = trim((string)$space);
    if ($space === '') return 'off';
    $mode = wiki_space_settings_get(basename($space))['fm_autostamp'] ?? 'off';
    return in_array($mode, WIKI_FM_AUTOSTAMP_MODES, true) ? $mode : 'off';
}

function wiki_space_dir_fm_autostamp(?string $space_dir): string {
    if (!$space_dir) return 'off';
    $dir = rtrim($space_dir, '/');
    if ($dir === rtrim(PAGES_DIR, '/')) return 'off';
    return wiki_space_fm_autostamp(basename($dir));
}

function wiki_space_set_fm_autostamp(string $space, string $mode): bool {
    $space = basename(trim($space));
    if ($space === '') return false;
    if (!in_array($mode, WIKI_FM_AUTOSTAMP_MODES, true)) return false;
    $all = wiki_space_settings_all(true);
    $rec = is_array($all[$space] ?? null) ? $all[$space] : [];
    if ($mode === 'off') unset($rec['fm_autostamp']);
    else                 $rec['fm_autostamp'] = $mode;
    $all[$space] = $rec;
    return wiki_space_settings_save($all);
}

/**
 * Does this space keep an AI memory store?
 *
 * Off unless the record says otherwise, so a new space — and a space that predates the
 * feature — has no memory folder and no memory tools, without anything being written for
 * it. Per space rather than wiki-wide because the *store* is per space: memories live in
 * `memory/` inside the space they were learned in, which is what keeps them inside the
 * Space isolation every other read already obeys.
 *
 * This is only half the switch. An AI User also has to have learning enabled
 * (`wiki_ai_memory_enabled()`); the space says whether memories may be kept here, the AI
 * User says whether it keeps any. Both, for the same reason a frozen space overrides an
 * editor: the space owns its content, the AI User owns its behaviour.
 */
function wiki_space_memory(?string $space): bool {
    $space = trim((string)$space);
    if ($space === '') return false;
    return !empty(wiki_space_settings_get(basename($space))['memory']);
}

function wiki_space_dir_memory(?string $space_dir): bool {
    if (!$space_dir) return false;
    $dir = rtrim($space_dir, '/');
    if ($dir === rtrim(PAGES_DIR, '/')) return false;   // a root-level wiki has no record
    return wiki_space_memory(basename($dir));
}

function wiki_space_set_memory(string $space, bool $on): bool {
    $space = basename(trim($space));
    if ($space === '') return false;
    $all = wiki_space_settings_all(true);
    $rec = is_array($all[$space] ?? null) ? $all[$space] : [];
    if ($on) $rec['memory'] = true;
    else     unset($rec['memory']);
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
