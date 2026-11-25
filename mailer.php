<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Unified email helper — SendGrid and Mailgun, over their HTTP APIs.
 *
 * Where the settings come from — one resolved configuration, wiki_mail_config():
 *   1. config.php (or the container's environment): MAIL_PROVIDER, SENDGRID_* / MAILGUN_*.
 *      When those configure a provider completely they win, and the wiki's own settings
 *      cannot be changed from the UI — the server's configuration is the authority.
 *   2. Otherwise WIKI_SYSTEM_DATA/mail_settings.json, written by the email wizard in the
 *      setup checklist (admin_mail_save). It holds the API key, so it is written 0600 and
 *      the key never leaves the server again: the UI gets a hint of its last characters.
 *
 * Usage: send_email($to, $to_name, $subject, $html_body) — true when the provider accepted it.
 * wiki_mail_send() is the same with the provider's own error, for a configuration that need
 * not be saved yet (the wizard tests before it saves).
 */

// SendGrid and Mailgun can also be set in config.php; Postmark, Brevo and Resend only from the
// wizard. Each is one JSON (or form) POST over HTTPS — no SMTP, no library.
const WIKI_MAIL_PROVIDERS = ['sendgrid', 'mailgun', 'postmark', 'brevo', 'resend'];
const WIKI_MAIL_NAMES = ['sendgrid' => 'SendGrid', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark',
                         'brevo' => 'Brevo', 'resend' => 'Resend'];

/** The provider config.php names: MAIL_PROVIDER, or whichever API key is there. */
function _mailer_provider(): string {
    if (defined('MAIL_PROVIDER') && MAIL_PROVIDER) return strtolower(MAIL_PROVIDER);
    if (defined('SENDGRID_API_KEY') && SENDGRID_API_KEY) return 'sendgrid';
    if (defined('MAILGUN_API_KEY')  && MAILGUN_API_KEY)  return 'mailgun';
    return '';
}

/** config.php's settings in the common shape, or null when it does not configure a provider. */
function _wiki_mail_from_constants(): ?array {
    $c = fn(string $name, string $default = '') => defined($name) && constant($name) ? (string)constant($name) : $default;
    $title = defined('APP_TITLE') ? APP_TITLE : 'Wiki';
    switch (_mailer_provider()) {
        case 'sendgrid':
            // EU unless told otherwise: the endpoint was the EU one before SENDGRID_REGION
            // existed, and a configuration that worked must keep working.
            return ['provider' => 'sendgrid', 'api_key' => $c('SENDGRID_API_KEY'),
                    'from_email' => $c('SENDGRID_FROM_EMAIL'), 'from_name' => $c('SENDGRID_FROM_NAME', $title),
                    'domain' => '', 'region' => strtolower($c('SENDGRID_REGION', 'eu')) === 'global' ? 'global' : 'eu'];
        case 'mailgun':
            return ['provider' => 'mailgun', 'api_key' => $c('MAILGUN_API_KEY'),
                    'from_email' => $c('MAILGUN_FROM_EMAIL'), 'from_name' => $c('MAILGUN_FROM_NAME', $title),
                    'domain' => $c('MAILGUN_DOMAIN'), 'region' => strtolower($c('MAILGUN_REGION', 'us')) === 'eu' ? 'eu' : 'us'];
    }
    return null;
}

function wiki_mail_settings_file(): string {
    return rtrim(defined('WIKI_SYSTEM_DATA') ? WIKI_SYSTEM_DATA : sys_get_temp_dir(), '/') . '/mail_settings.json';
}

/** The settings the wizard saved, in the common shape, or null. */
function wiki_mail_stored(): ?array {
    $f = wiki_mail_settings_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || !in_array($d['provider'] ?? '', WIKI_MAIL_PROVIDERS, true)) return null;
    return wiki_mail_normalize($d);
}

/** Any input in the common shape, every field a string, the region one the provider has. */
function wiki_mail_normalize(array $d): array {
    $provider = in_array($d['provider'] ?? '', WIKI_MAIL_PROVIDERS, true) ? $d['provider'] : '';
    $region = strtolower(trim((string)($d['region'] ?? '')));
    // Only SendGrid and Mailgun have regions; the others have one endpoint.
    $region = match ($provider) {
        'mailgun'  => $region === 'eu' ? 'eu' : 'us',
        'sendgrid' => $region === 'eu' ? 'eu' : 'global',
        default    => '',
    };
    // A sender name becomes part of a From header: no line breaks, no quotes or brackets.
    $name = trim(preg_replace('/[\r\n"<>]+/', ' ', (string)($d['from_name'] ?? '')));
    return [
        'provider'   => $provider,
        'api_key'    => trim((string)($d['api_key'] ?? '')),
        'from_email' => trim((string)($d['from_email'] ?? '')),
        'from_name'  => mb_substr($name, 0, 100),
        'domain'     => trim((string)($d['domain'] ?? '')),
        'region'     => $region,
    ];
}

/** Is this configuration enough to send? Returns '' when it is, else what is missing. */
function wiki_mail_incomplete(array $cfg): string {
    if (!in_array($cfg['provider'] ?? '', WIKI_MAIL_PROVIDERS, true)) return 'provider';
    if (($cfg['api_key'] ?? '') === '') return 'api_key';
    if (!filter_var($cfg['from_email'] ?? '', FILTER_VALIDATE_EMAIL)) return 'from_email';
    if ($cfg['provider'] === 'mailgun' && !preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $cfg['domain'] ?? '')) return 'domain';
    return '';
}

/**
 * The configuration in force, with `source`: 'config' (config.php, complete), 'wiki' (the
 * wizard's), or '' (none). A config.php that names a provider but leaves out a setting does
 * not block the wizard's — it would otherwise leave a wiki with no way to send at all.
 */
function wiki_mail_config(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $const = _wiki_mail_from_constants();
    if ($const !== null && wiki_mail_incomplete($const) === '') return $cache = $const + ['source' => 'config'];
    $stored = wiki_mail_stored();
    if ($stored !== null && wiki_mail_incomplete($stored) === '') return $cache = $stored + ['source' => 'wiki'];
    return $cache = ['provider' => '', 'api_key' => '', 'from_email' => '', 'from_name' => '', 'domain' => '', 'region' => '', 'source' => ''];
}

/** Store the wizard's settings ('' provider removes them). The key is kept when left blank. */
function wiki_mail_store(array $in): bool {
    $f = wiki_mail_settings_file();
    if (($in['provider'] ?? '') === '') { $ok = !is_file($f) || @unlink($f); wiki_mail_config(true); return $ok; }
    $cfg = wiki_mail_normalize($in);
    if ($cfg['api_key'] === '') $cfg['api_key'] = wiki_mail_stored()['api_key'] ?? '';
    $cfg['updated_at'] = date('c');
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT)) === false) return false;
    @chmod($tmp, 0600);
    $ok = @rename($tmp, $f);
    wiki_mail_config(true);
    return $ok;
}

/** What the UI may see: everything but the key, which is a hint of its last characters. */
function wiki_mail_public(array $cfg): array {
    $key = (string)($cfg['api_key'] ?? '');
    return [
        'source'     => $cfg['source'] ?? '',
        'provider'   => $cfg['provider'] ?? '',
        'from_email' => $cfg['from_email'] ?? '',
        'from_name'  => $cfg['from_name'] ?? '',
        'domain'     => $cfg['domain'] ?? '',
        'region'     => $cfg['region'] ?? '',
        'key_set'    => $key !== '',
        'key_hint'   => $key !== '' ? '…' . substr($key, -4) : '',
    ];
}

function is_mail_configured(): bool {
    return wiki_mail_config()['source'] !== '';
}

function mail_provider_name(): string {
    return WIKI_MAIL_NAMES[wiki_mail_config()['provider']] ?? 'None';
}

function mail_from_email(): string {
    return wiki_mail_config()['from_email'];
}

function send_email(string $to, string $to_name, string $subject, string $html_body): bool {
    $cfg = wiki_mail_config();
    if ($cfg['source'] === '') return false;
    return wiki_mail_send($cfg, $to, $to_name, $subject, $html_body)['ok'];
}

/**
 * Send through the given configuration: ['ok' => bool, 'error' => string] — the provider's
 * own message when it refuses, so a wrong key or an unverified sender reads as what it is.
 */
function wiki_mail_send(array $cfg, string $to, string $to_name, string $subject, string $html_body): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl is not available on this server.'];
    $missing = wiki_mail_incomplete($cfg);
    if ($missing !== '') return ['ok' => false, 'error' => 'Missing: ' . $missing];
    $from_name = $cfg['from_name'] !== '' ? $cfg['from_name'] : (defined('APP_TITLE') ? APP_TITLE : 'Wiki');
    $to_name = trim(preg_replace('/[\r\n"<>]+/', ' ', $to_name));

    // MAIL_API_BASE_URL (config.php, optional): a relay that speaks the provider's API in place
    // of the provider's own host — a proxy, or the test suite's stand-in.
    $relay = defined('MAIL_API_BASE_URL') && MAIL_API_BASE_URL ? rtrim((string)MAIL_API_BASE_URL, '/') : '';

    $from = "{$from_name} <{$cfg['from_email']}>";
    $json = ['Content-Type: application/json', 'Accept: application/json'];
    $auth = null;
    switch ($cfg['provider']) {
        case 'sendgrid':
            $url = $relay !== '' ? $relay . '/v3/mail/send'
                 : ($cfg['region'] === 'eu' ? 'https://api.eu.sendgrid.com/v3/mail/send' : 'https://api.sendgrid.com/v3/mail/send');
            $headers = array_merge($json, ['Authorization: Bearer ' . $cfg['api_key']]);
            $body = json_encode([
                'personalizations' => [['to' => [array_filter(['email' => $to, 'name' => $to_name])]]],
                'from'             => ['email' => $cfg['from_email'], 'name' => $from_name],
                'subject'          => $subject,
                'content'          => [['type' => 'text/html', 'value' => $html_body]],
            ]);
            break;
        case 'mailgun':
            $base = $relay !== '' ? $relay : ($cfg['region'] === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net');
            $url = $base . '/v3/' . rawurlencode($cfg['domain']) . '/messages';
            $headers = [];
            $auth = 'api:' . $cfg['api_key'];
            $body = ['from' => $from, 'to' => $to_name !== '' ? "{$to_name} <{$to}>" : $to,
                     'subject' => $subject, 'html' => $html_body];
            break;
        case 'postmark':
            // A *server* token; "outbound" is the transactional stream every server has.
            $url = ($relay !== '' ? $relay : 'https://api.postmarkapp.com') . '/email';
            $headers = array_merge($json, ['X-Postmark-Server-Token: ' . $cfg['api_key']]);
            $body = json_encode(['From' => $from, 'To' => $to_name !== '' ? "{$to_name} <{$to}>" : $to,
                                 'Subject' => $subject, 'HtmlBody' => $html_body, 'MessageStream' => 'outbound']);
            break;
        case 'brevo':
            $url = ($relay !== '' ? $relay : 'https://api.brevo.com') . '/v3/smtp/email';
            $headers = array_merge($json, ['api-key: ' . $cfg['api_key']]);
            $body = json_encode(['sender' => ['name' => $from_name, 'email' => $cfg['from_email']],
                                 'to' => [array_filter(['email' => $to, 'name' => $to_name])],
                                 'subject' => $subject, 'htmlContent' => $html_body]);
            break;
        case 'resend':
            $url = ($relay !== '' ? $relay : 'https://api.resend.com') . '/emails';
            $headers = array_merge($json, ['Authorization: Bearer ' . $cfg['api_key']]);
            $body = json_encode(['from' => $from, 'to' => [$to_name !== '' ? "{$to_name} <{$to}>" : $to],
                                 'subject' => $subject, 'html' => $html_body]);
            break;
        default:
            return ['ok' => false, 'error' => 'Unknown provider.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
    if ($auth !== null) curl_setopt($ch, CURLOPT_USERPWD, $auth);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 15]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    if ($raw === false || $status === 0) return ['ok' => false, 'error' => $cerr ?: 'No response from the provider.'];
    if ($status >= 200 && $status < 300) return ['ok' => true, 'error' => ''];
    // SendGrid {"errors":[{"message"}]}; Postmark {"Message"}; Mailgun, Brevo and Resend
    // {"message"} — Mailgun sometimes plain text ("Forbidden").
    $j = json_decode((string)$raw, true);
    $msg = is_array($j) ? (string)($j['errors'][0]['message'] ?? $j['Message'] ?? $j['message'] ?? '')
                        : trim(strip_tags((string)$raw));
    return ['ok' => false, 'error' => "HTTP {$status}" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 300) : '')];
}

/** The body of every test email — the one from Diagnostics and the wizard's. */
function wiki_mail_test_body(): string {
    $title = htmlspecialchars(defined('APP_TITLE') ? APP_TITLE : 'Wiki');
    return '<p>This is a test email from <strong>' . $title . '</strong>.</p>'
         . '<p>If you received it, email from the wiki works.</p>';
}
