<?php
/**
 * Unified email helper — supports SendGrid and Mailgun (HTTP APIs).
 *
 * Provider selection:
 *   1. Set MAIL_PROVIDER to 'sendgrid' or 'mailgun' in config.php (explicit).
 *   2. If MAIL_PROVIDER is not defined, auto-detects from which API key is present.
 *
 * Usage: send_email($to, $to_name, $subject, $html_body)
 */

function _mailer_provider(): string {
    if (defined('MAIL_PROVIDER') && MAIL_PROVIDER) return strtolower(MAIL_PROVIDER);
    if (defined('SENDGRID_API_KEY') && SENDGRID_API_KEY) return 'sendgrid';
    if (defined('MAILGUN_API_KEY')  && MAILGUN_API_KEY)  return 'mailgun';
    return '';
}

function is_mail_configured(): bool {
    switch (_mailer_provider()) {
        case 'sendgrid':
            return defined('SENDGRID_API_KEY')    && SENDGRID_API_KEY
                && defined('SENDGRID_FROM_EMAIL') && SENDGRID_FROM_EMAIL;
        case 'mailgun':
            return defined('MAILGUN_API_KEY')     && MAILGUN_API_KEY
                && defined('MAILGUN_DOMAIN')      && MAILGUN_DOMAIN
                && defined('MAILGUN_FROM_EMAIL')  && MAILGUN_FROM_EMAIL;
        default:
            return false;
    }
}

function mail_provider_name(): string {
    switch (_mailer_provider()) {
        case 'sendgrid': return 'SendGrid';
        case 'mailgun':  return 'Mailgun';
        default:         return 'None';
    }
}

function mail_from_email(): string {
    switch (_mailer_provider()) {
        case 'sendgrid': return defined('SENDGRID_FROM_EMAIL') ? SENDGRID_FROM_EMAIL : '';
        case 'mailgun':  return defined('MAILGUN_FROM_EMAIL')  ? MAILGUN_FROM_EMAIL  : '';
        default:         return '';
    }
}

function send_email(string $to, string $to_name, string $subject, string $html_body): bool {
    switch (_mailer_provider()) {
        case 'sendgrid': return _send_sendgrid($to, $to_name, $subject, $html_body);
        case 'mailgun':  return _send_mailgun($to, $to_name, $subject, $html_body);
        default:         return false;
    }
}

function _send_sendgrid(string $to, string $to_name, string $subject, string $html_body): bool {
    if (!defined('SENDGRID_API_KEY') || !SENDGRID_API_KEY) return false;
    if (!defined('SENDGRID_FROM_EMAIL') || !SENDGRID_FROM_EMAIL) return false;

    $from_name = defined('SENDGRID_FROM_NAME') && SENDGRID_FROM_NAME
        ? SENDGRID_FROM_NAME
        : (defined('APP_TITLE') ? APP_TITLE : 'Wiki');

    $payload = json_encode([
        'personalizations' => [['to' => [['email' => $to, 'name' => $to_name]]]],
        'from'             => ['email' => SENDGRID_FROM_EMAIL, 'name' => $from_name],
        'subject'          => $subject,
        'content'          => [['type' => 'text/html', 'value' => $html_body]],
    ]);

    $ch = curl_init('https://api.eu.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SENDGRID_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status >= 200 && $status < 300;
}

function _send_mailgun(string $to, string $to_name, string $subject, string $html_body): bool {
    if (!defined('MAILGUN_API_KEY')    || !MAILGUN_API_KEY)    return false;
    if (!defined('MAILGUN_DOMAIN')     || !MAILGUN_DOMAIN)     return false;
    if (!defined('MAILGUN_FROM_EMAIL') || !MAILGUN_FROM_EMAIL) return false;

    $region   = (defined('MAILGUN_REGION') && strtolower(MAILGUN_REGION) === 'eu') ? 'eu' : 'us';
    $base     = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
    $url      = $base . '/v3/' . urlencode(MAILGUN_DOMAIN) . '/messages';

    $from_name = defined('MAILGUN_FROM_NAME') && MAILGUN_FROM_NAME
        ? MAILGUN_FROM_NAME
        : (defined('APP_TITLE') ? APP_TITLE : 'Wiki');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => 'api:' . MAILGUN_API_KEY,
        CURLOPT_POSTFIELDS     => [
            'from'    => "{$from_name} <" . MAILGUN_FROM_EMAIL . '>',
            'to'      => $to_name ? "{$to_name} <{$to}>" : $to,
            'subject' => $subject,
            'html'    => $html_body,
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 200;
}
