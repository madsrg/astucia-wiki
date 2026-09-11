<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// ASTUCIA WIKI — container configuration
// =================================================================
//
// Copied to config.php by the entrypoint on first start, unless a config.php has
// been mounted. Every value comes from an environment variable, so the container
// is configured with -e / environment: rather than by editing a file inside it.
//
// Mount your own config.php over /var/www/html/config.php if you would rather
// keep the file-based setup; the entrypoint leaves it alone if it exists.

$env = static function (string $name, ?string $default = null): ?string {
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
};
$bool = static function (string $name, bool $default = false) use ($env): bool {
    $v = strtolower((string)$env($name, $default ? 'true' : 'false'));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
};
// Directory constants must end in a slash — the app concatenates paths directly.
$dir = static function (string $name, string $default) use ($env): string {
    return rtrim((string)$env($name, $default), '/') . '/';
};

// --- Core ---------------------------------------------------------------------
define('APP_TITLE',   $env('APP_TITLE', 'Astucia Wiki'));
define('ENVIRONMENT', $env('ENVIRONMENT', 'production'));
define('PAGES_DIR',         $dir('PAGES_DIR', '/data/pages'));
define('WIKI_SYSTEM_DATA',  $dir('WIKI_SYSTEM_DATA', '/data/system'));
define('LOG_DIR',           $dir('LOG_DIR', '/data/logs'));

// --- Authentication -----------------------------------------------------------
// 'off' | 'oidc' | 'otp' | 'both'. Read unconditionally by index.php and auth.php,
// so it must always be defined. AUTHENTICATION_ENABLED is derived, never set by hand.
$auth = strtolower((string)$env('AUTHENTICATION', 'off'));
if (!in_array($auth, ['off', 'oidc', 'otp', 'both'], true)) $auth = 'off';
define('AUTHENTICATION', $auth);
define('AUTHENTICATION_ENABLED', AUTHENTICATION !== 'off');
define('ANONYMOUS_ACCESS_ENABLED', $bool('ANONYMOUS_ACCESS_ENABLED', false));
define('SESSION_TIMEOUT', (int)$env('SESSION_TIMEOUT', '3600'));

// OIDC constants are read without a defined() guard, so they are always declared —
// empty when OIDC is not in use. Leaving them out crashes the login flow instead of
// reporting a misconfiguration.
define('OIDC_PROVIDER_URL',  $env('OIDC_PROVIDER_URL', ''));
define('OIDC_CLIENT_ID',     $env('OIDC_CLIENT_ID', ''));
define('OIDC_CLIENT_SECRET', $env('OIDC_CLIENT_SECRET', ''));
define('OIDC_REDIRECT_URI',  $env('OIDC_REDIRECT_URI', ''));
if ($env('APP_BASE_URL') !== null) define('APP_BASE_URL', $env('APP_BASE_URL'));

// --- Search -------------------------------------------------------------------
// 'sqlite' enables the FTS5 index (search.sqlite lives in WIKI_SYSTEM_DATA).
define('SEARCH_ENGINE', $env('SEARCH_ENGINE', 'sqlite'));
// Empty makes the wiki fetch nothing and use the copy bundled in the image.
define('SYSTEM_PROMPT_GALLERY_URL', $env('SYSTEM_PROMPT_GALLERY_URL', 'https://astucia.wiki/system_prompts.json'));

// --- Realtime push --------------------------------------------------------------
// The image ships a Mercure hub and starts it under supervisord. The key is generated on
// first boot into the data volume, never baked into a layer; the entrypoint exports it to
// both processes so they agree.
define('ENABLE_REALTIME', filter_var($env('ENABLE_REALTIME', 'true'), FILTER_VALIDATE_BOOLEAN));
define('MERCURE_JWT_KEY', $env('MERCURE_JWT_KEY', ''));
define('MERCURE_INTERNAL_URL', $env('MERCURE_INTERNAL_URL', 'http://127.0.0.1:3000'));
define('MERCURE_PUBLIC_URL', $env('MERCURE_PUBLIC_URL', '/.well-known/mercure'));
define('REALTIME_TICKET_TTL', (int)$env('REALTIME_TICKET_TTL', '3600'));

// --- External change detection ------------------------------------------------
// Minimum seconds between filesystem scans for content changed outside the wiki.
// Relevant in a container: a bind-mounted PAGES_DIR is exactly the case where
// files arrive without the wiki being involved.
define('INDEX_SYNC_INTERVAL_SECONDS', (int)$env('INDEX_SYNC_INTERVAL_SECONDS', '30'));

// --- AI agent jobs ------------------------------------------------------------
// Must match the crontab interval; the entrypoint writes the crontab from this
// same variable, so the two cannot drift apart.
define('AGENT_JOB_RUNNER_INTERVAL_MINUTES', (int)$env('AGENT_JOB_RUNNER_INTERVAL_MINUTES', '2'));
define('AGENT_JOB_RUNNER_SLOTS', (int)$env('AGENT_JOB_RUNNER_SLOTS', '2'));
define('AI_DEBUG_RAW_ERRORS', $bool('AI_DEBUG_RAW_ERRORS', false));

// --- Email (optional; is_mail_configured() gates all sending) -----------------
define('ADMIN_EMAIL',   $env('ADMIN_EMAIL', ''));
define('MAIL_PROVIDER', $env('MAIL_PROVIDER', ''));      // 'sendgrid' | 'mailgun' | ''
define('SENDGRID_API_KEY',    $env('SENDGRID_API_KEY', ''));
define('SENDGRID_FROM_EMAIL', $env('SENDGRID_FROM_EMAIL', ''));
define('SENDGRID_FROM_NAME',  $env('SENDGRID_FROM_NAME', APP_TITLE));
define('MAILGUN_API_KEY',    $env('MAILGUN_API_KEY', ''));
define('MAILGUN_DOMAIN',     $env('MAILGUN_DOMAIN', ''));
define('MAILGUN_REGION',     $env('MAILGUN_REGION', 'us'));
define('MAILGUN_FROM_EMAIL', $env('MAILGUN_FROM_EMAIL', ''));
define('MAILGUN_FROM_NAME',  $env('MAILGUN_FROM_NAME', APP_TITLE));

// --- Diagnostics --------------------------------------------------------------
// Paths the admin Diagnostics pane reads. Inside the container nginx logs to
// LOG_DIR so they are visible from the volume as well as `docker logs`.
define('NGINX_ACCESS_LOG', $env('NGINX_ACCESS_LOG', LOG_DIR . 'nginx-access.log'));
define('NGINX_ERROR_LOG',  $env('NGINX_ERROR_LOG',  LOG_DIR . 'nginx-error.log'));
