<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// OUTBOUND HTTP — a request to a URL that came from configuration, made safely.
//
// Used by the workflow "Web request" action. The URL is an admin's, but a request the
// server makes is a request *from inside the network*: without care it reaches the cloud
// metadata service (169.254.169.254), the Mercure hub on loopback, or anything else only
// the server can see. So:
//
//   - http and https only, no credentials in the URL (put them in a header);
//   - the host is resolved here, and every address it resolves to must be public — not
//     loopback, private, link-local, CGNAT, or otherwise reserved — unless the config
//     says WEB_REQUEST_ALLOW_PRIVATE;
//   - the connection is pinned to the address that was checked (CURLOPT_RESOLVE), so DNS
//     cannot answer differently between the check and the request (rebinding);
//   - redirects are not followed, so a public host cannot bounce the request inward;
//   - short timeouts, and the response is read only up to a cap.
// =================================================================

const WIKI_HTTP_CONNECT_TIMEOUT = 5;
const WIKI_HTTP_TIMEOUT         = 15;
const WIKI_HTTP_MAX_RESPONSE    = 65536;

function wiki_http_allow_private(): bool {
    return defined('WEB_REQUEST_ALLOW_PRIVATE') && WEB_REQUEST_ALLOW_PRIVATE;
}

/** Is this address one a request from the server may go to? */
function wiki_http_ip_public(string $ip): bool {
    // An IPv4 address written as IPv6 (::ffff:127.0.0.1) is judged as the IPv4 it is.
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = substr($ip, 7);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        // Ranges PHP's filter does not count as private or reserved.
        foreach ([['100.64.0.0', 10], ['198.18.0.0', 15], ['192.0.0.0', 24]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) return false;
        }
    } else {
        $l = strtolower($ip);
        if (str_starts_with($l, 'fc') || str_starts_with($l, 'fd') || str_starts_with($l, 'fe8')
            || str_starts_with($l, 'fe9') || str_starts_with($l, 'fea') || str_starts_with($l, 'feb')) return false;
    }
    return true;
}

/**
 * Check a URL and decide where to connect. Returns [scheme, host, port, ip]; throws with a
 * sentence an admin can act on when the URL may not be requested.
 */
function wiki_http_target(string $url): array {
    $p = parse_url($url);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if (!$p || !in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
        throw new Exception('Only http:// and https:// addresses can be requested.');
    }
    if (isset($p['user']) || isset($p['pass'])) throw new Exception('Put credentials in a header, not in the address.');
    $host = trim((string)$p['host'], '[]');
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = [];
        foreach ((array)@dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (!empty($r['ip']))   $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) { $v4 = @gethostbynamel($host); if ($v4) $ips = $v4; }
        if (!$ips) throw new Exception("The host \"{$host}\" could not be resolved.");
    }
    if (!wiki_http_allow_private()) {
        // Every address, not just the first: a host that resolves to one public and one
        // internal address must not get a second chance at the internal one.
        foreach ($ips as $ip) {
            if (!wiki_http_ip_public($ip)) {
                throw new Exception("\"{$host}\" is an internal address ({$ip}). Requests to the server's own network are "
                    . 'off; set WEB_REQUEST_ALLOW_PRIVATE in the configuration to allow them.');
            }
        }
    }
    return [$scheme, $host, $port, $ips[0]];
}

/**
 * Make the request. $headers is [name => value]. Returns ['status' => int, 'body' => string
 * (capped), 'truncated' => bool]; throws on a transport failure or a refused address.
 */
function wiki_http_request(string $method, string $url, array $headers = [], ?string $body = null): array {
    [$scheme, $host, $port, $ip] = wiki_http_target($url);
    if (!function_exists('curl_init')) throw new Exception('The PHP curl extension is not available.');

    $ch = curl_init($url);
    $hdr = [];
    foreach ($headers as $k => $v) $hdr[] = $k . ': ' . $v;
    $buf = '';
    $truncated = false;
    $version = is_file(__DIR__ . '/VERSION') ? trim((string)file_get_contents(__DIR__ . '/VERSION')) : '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_HTTPHEADER      => $hdr,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_CONNECTTIMEOUT  => WIKI_HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT         => WIKI_HTTP_TIMEOUT,
        CURLOPT_RESOLVE         => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
        CURLOPT_USERAGENT       => 'AstuciaWiki-Workflow' . ($version !== '' ? '/' . $version : ''),
        CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$buf, &$truncated) {
            if (strlen($buf) < WIKI_HTTP_MAX_RESPONSE) $buf .= substr($chunk, 0, WIKI_HTTP_MAX_RESPONSE - strlen($buf));
            else $truncated = true;
            return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
    elseif (defined('CURLOPT_PROTOCOLS'))  curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    if ($body !== null && $method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false) throw new Exception('The request to ' . $host . ' failed: ' . ($err ?: 'no response') . '.');
    return ['status' => $status, 'body' => $buf, 'truncated' => $truncated];
}

/** The URL without its query string — what is safe to log, since tokens often live there. */
function wiki_http_url_for_log(string $url): string {
    $q = strpos($url, '?');
    return $q === false ? $url : substr($url, 0, $q) . '?…';
}
