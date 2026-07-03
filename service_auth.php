<?php
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

// Returns the Space allowlist for the current actor, or null if unrestricted
// (admin role, or no restriction configured). Used to gate the ?space= param
// consistently for session users, AI Users, and API Accounts alike.
function actor_spaces_filter(string $role, ?array $service_user): ?array {
    if ($role === 'admin') return null;
    if ($service_user) return $service_user['spaces'] ?? null;
    return $_SESSION['user']['spaces'] ?? null;
}
