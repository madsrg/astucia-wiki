<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// AUTHENTICATION MODES — the site's one security level, read from config.php.
//
//   off        nobody signs in (a private network, a personal wiki)
//   sso        single sign-on only (Entra ID, AD FS, Google, Keycloak … — any OIDC provider)
//   sso+email  single sign-on for staff, email codes for people without an SSO account
//   email      email codes only
//   password   local username/password only
//
// The order of strength is sso > email > password: a password with "forgot password" by
// email is everything an email code is, plus guessing. So password never combines with
// anything, and the one mixed mode, sso+email, gives each account exactly ONE method —
// an account is only as strong as the weakest way into it, so an SSO account that also
// took email codes would be an email account.
//
// The mode lives in config.php on purpose and is never an admin-panel setting: lowering a
// site's security level should need access to the server, not just an admin session.
//
// The old names still work, so no install has to touch its config: oidc → sso,
// otp → email, both → sso+email. An unknown value is 'invalid', which signs nobody in —
// AUTHENTICATION_ENABLED stays true for it, so it fails closed rather than open.
// =================================================================

const WIKI_AUTH_MODES   = ['off', 'sso', 'sso+email', 'email', 'password'];
const WIKI_AUTH_ALIASES = ['oidc' => 'sso', 'otp' => 'email', 'both' => 'sso+email'];

/** The configured mode in the current vocabulary, or 'invalid'. */
function wiki_auth_mode(): string {
    $m = defined('AUTHENTICATION') ? strtolower(trim((string)AUTHENTICATION)) : 'off';
    $m = WIKI_AUTH_ALIASES[$m] ?? $m;
    return in_array($m, WIKI_AUTH_MODES, true) ? $m : 'invalid';
}

/** Does this site offer a sign-in method at all? $method: 'sso' | 'email' | 'password'. */
function wiki_auth_offers(string $method): bool {
    $mode = wiki_auth_mode();
    switch ($method) {
        case 'sso':      return $mode === 'sso' || $mode === 'sso+email';
        case 'email':    return $mode === 'email' || $mode === 'sso+email';
        case 'password': return $mode === 'password';
    }
    return false;
}
