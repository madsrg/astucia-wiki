<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// SERVICE TOKEN AUTH — shared by api.php (session + token) and mcp.php (token-only)
// Resolves an AI user (wk_ai_…) or API account (wk_sys_…) from the Authorization header.
// =================================================================

function resolve_service_token_auth(): ?array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$hdr && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $hdr  = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    if (!str_starts_with($hdr, 'Bearer wk_') || !defined('WIKI_SYSTEM_DATA') || !file_exists(WIKI_SYSTEM_DATA . 'users.json')) {
        return null;
    }
    $token = substr($hdr, 7);
    foreach ((json_decode(file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true)['users'] ?? []) as $u) {
        if ((!empty($u['is_ai']) || !empty($u['is_system'])) && ($u['service_token'] ?? '') === $token) {
            return $u;
        }
    }
    return null;
}

// --- Space containment -------------------------------------------------------
// The allowlist is enforced on the ?space= parameter, which is only half the job: a path
// can reach into a Space without naming it. With no ?space= the base is PAGES_DIR itself,
// so `Bravo/secret.md` walks straight into Bravo; and a base of `…/Alpha` is a string
// prefix of `…/Alpha2`, so a prefix-only containment test lets a sibling Space through.
// These two answer the question that actually matters: which Space does the *resolved*
// path land in, and may this actor read it.

/**
 * The Space a path belongs to: its name, '' for content sitting directly in PAGES_DIR
 * (a wiki that predates Spaces), or null when the path is outside PAGES_DIR altogether.
 * Takes a path that has already been resolved — the caller decides whether that means
 * realpath() for something that exists or a lexical clean-up for something being created.
 */
function wiki_path_space(string $abs_path): ?string {
    $root = realpath(PAGES_DIR);
    if ($root === false) return null;
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    $abs  = rtrim($abs_path, DIRECTORY_SEPARATOR);
    if ($abs === $root) return '';
    // The separator is the point: without it '/pages/Alpha2/x' passes as being inside
    // '/pages/Alpha'.
    if (strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    $first = explode(DIRECTORY_SEPARATOR, substr($abs, strlen($root) + 1))[0];
    return is_dir($root . DIRECTORY_SEPARATOR . $first) ? $first : '';
}

/**
 * Whether an actor holding $allowed (null = unrestricted) may read a path in $space.
 * Root-level content ('') stays readable: an allowlist names Spaces, and a wiki that has
 * pages outside them predates the feature — restricting those would be a new denial
 * rather than a leak being closed. A path outside PAGES_DIR (null) is never allowed.
 */
function wiki_space_allowed(?array $allowed, ?string $space): bool {
    if ($space === null) return false;
    if ($allowed === null || $space === '') return true;
    return in_array($space, $allowed, true);
}

// Returns the Space allowlist for the current actor, or null if unrestricted
// (admin role, or no restriction configured). Used to gate the ?space= param
// consistently for session users, AI Users, and API Accounts alike.
function actor_spaces_filter(string $role, ?array $service_user): ?array {
    if ($role === 'admin') return null;
    if ($service_user) return $service_user['spaces'] ?? null;
    return $_SESSION['user']['spaces'] ?? null;
}
