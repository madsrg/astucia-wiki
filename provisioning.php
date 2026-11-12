<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// AUTOMATIC ACCESS FOR SSO SIGN-INS — rules on the provider's claims.
//
// Without rules, a new SSO identity files an access request and waits for an admin. With
// them, a first sign-in whose claims match a rule gets an account at once, as Reader or
// Editor in the Spaces the rule names. People who match no rule either file a request as
// before or are turned away with none ('unmatched' => 'request' | 'deny').
//
// Stored as one `sso_provisioning` object in settings.json: an admin decision at runtime,
// like the audit-log switch — granting access is already the admin's job, so the panel
// is the right place (unlike the security level itself, which needs the server).
//
// Rules worth knowing before changing anything here:
//   - Never Admin. A rule grants reader or editor; admin stays a person's decision.
//   - First sign-in only. Once the account exists the admin owns its role and Spaces;
//     rules are not re-applied at later sign-ins.
//   - An email-domain rule never matches when the provider says email_verified is false:
//     some providers let a user type their own address, and a domain rule on an unverified
//     claim would admit anyone who claims it. For Entra ID the robust criteria are `tid`,
//     `groups` and `roles`, which the user cannot set.
//   - An identity whose address matches an existing account still becomes a request, so
//     the admin can link it — accounts are never joined on an address match, and a second
//     account for the same person is the split the linking work exists to prevent.
//   - An explicitly denied request stays denied; a request the admin chose to link keeps
//     its link. Rules only decide for identities nobody has decided about.
// =================================================================

require_once __DIR__ . '/settings.php';

const WIKI_PROV_OPS       = ['equals', 'email_domain'];
const WIKI_PROV_ROLES     = ['reader', 'editor'];
const WIKI_PROV_MAX_RULES = 50;

/** The stored configuration, normalised for reading. */
function wiki_prov_config(): array {
    $c = wiki_setting('sso_provisioning', []);
    $c = is_array($c) ? $c : [];
    return [
        'rules'     => array_values(array_filter((array)($c['rules'] ?? []), 'is_array')),
        'unmatched' => ($c['unmatched'] ?? 'request') === 'deny' ? 'deny' : 'request',
    ];
}

/**
 * Validate what the admin panel sends. Throws on anything that would not mean what it
 * says; returns the clean configuration to store. $spaces are the Space names that exist.
 */
function wiki_prov_normalize(array $in, array $spaces): array {
    $rules = [];
    foreach (array_values((array)($in['rules'] ?? [])) as $n => $r) {
        if (!is_array($r)) continue;
        $claim = trim((string)($r['claim'] ?? ''));
        $value = trim((string)($r['value'] ?? ''));
        $op    = (string)($r['op'] ?? 'equals');
        $role  = (string)($r['role'] ?? 'reader');
        $no    = $n + 1;
        if ($claim === '' && $value === '') continue;                 // an empty row
        if (!preg_match('~^[A-Za-z0-9_.:/#-]{1,200}$~', $claim)) throw new Exception("Rule {$no}: the claim name is not valid.");
        if ($value === '' || strlen($value) > 500) throw new Exception("Rule {$no}: give a value to match.");
        if (!in_array($op, WIKI_PROV_OPS, true)) throw new Exception("Rule {$no}: unknown comparison.");
        if (!in_array($role, WIKI_PROV_ROLES, true)) throw new Exception("Rule {$no}: a rule can grant Reader or Editor, never Admin.");
        if ($op === 'email_domain') {
            $value = strtolower(ltrim($value, '@'));
            if (!preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~', $value)) throw new Exception("Rule {$no}: \"{$value}\" is not a domain.");
        }
        $sp = $r['spaces'] ?? null;
        if ($sp !== null) {
            $sp = array_values(array_unique(array_filter(array_map('strval', (array)$sp), fn($s) => $s !== '')));
            foreach ($sp as $s) if (!in_array($s, $spaces, true)) throw new Exception("Rule {$no}: there is no Space \"{$s}\".");
            if (!$sp) throw new Exception("Rule {$no}: choose at least one Space, or all of them.");
        }
        $rules[] = ['claim' => $claim, 'op' => $op, 'value' => $value, 'role' => $role, 'spaces' => $sp];
    }
    if (count($rules) > WIKI_PROV_MAX_RULES) throw new Exception('At most ' . WIKI_PROV_MAX_RULES . ' rules.');
    return ['rules' => $rules, 'unmatched' => ($in['unmatched'] ?? 'request') === 'deny' ? 'deny' : 'request'];
}

/**
 * Every value a claim holds, as strings. A claim name is looked up literally first —
 * Entra and AD FS use URLs with dots as claim names — then as a dot path into nested
 * objects. A list (groups, roles) gives each of its scalar members.
 */
function wiki_prov_claim_values(array $claims, string $name): array {
    if (array_key_exists($name, $claims)) {
        $v = $claims[$name];
    } else {
        $v = $claims;
        foreach (explode('.', $name) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) return [];
            $v = $v[$part];
        }
    }
    $out = [];
    foreach (is_array($v) ? $v : [$v] as $x) {
        if (is_bool($x)) $out[] = $x ? 'true' : 'false';
        elseif (is_scalar($x)) $out[] = trim((string)$x);
    }
    return $out;
}

/** Does one rule match these claims? */
function wiki_prov_rule_matches(array $rule, array $claims): bool {
    $values = wiki_prov_claim_values($claims, (string)$rule['claim']);
    if (!$values) return false;
    $want = strtolower(trim((string)$rule['value']));
    if ($rule['op'] === 'email_domain') {
        // An address the provider says it has not verified proves nothing about its domain.
        if (strtolower((string)$rule['claim']) === 'email' && array_key_exists('email_verified', $claims)
            && !in_array($claims['email_verified'], [true, 'true', 1, '1'], true)) {
            return false;
        }
        foreach ($values as $v) {
            $at = strrpos($v, '@');
            if ($at !== false && strtolower(substr($v, $at + 1)) === $want) return true;
        }
        return false;
    }
    foreach ($values as $v) if (strtolower($v) === $want) return true;
    return false;
}

/** The first rule these claims match (with its 1-based number in `n`), or null. */
function wiki_prov_match(array $claims, array $rules): ?array {
    foreach (array_values($rules) as $i => $r) {
        if (is_array($r) && wiki_prov_rule_matches($r, $claims)) return $r + ['n' => $i + 1];
    }
    return null;
}

/**
 * A copy of the claims fit to keep in an access request, so the admin can see what the
 * provider sends when writing rules. Protocol fields and anything token-like are dropped,
 * and sizes are capped — a request file is not a token store.
 */
function wiki_prov_claims_snapshot(array $claims): array {
    $skip = ['nonce', 'at_hash', 'c_hash', 's_hash', 'iat', 'nbf', 'exp', 'auth_time', 'sid', 'uti', 'rh',
             'aio', 'xms_tcdt', 'access_token', 'id_token', 'refresh_token'];
    $out = [];
    foreach ($claims as $k => $v) {
        if (count($out) >= 40) break;
        $k = (string)$k;
        if (in_array(strtolower($k), $skip, true)) continue;
        if (is_array($v)) {
            $list = array_values(array_filter($v, 'is_scalar'));
            if (!$list) continue;
            $out[$k] = array_map(fn($x) => mb_substr((string)$x, 0, 200), array_slice($list, 0, 50));
        } elseif (is_scalar($v) || is_bool($v)) {
            $out[$k] = is_bool($v) ? $v : mb_substr((string)$v, 0, 200);
        }
    }
    return $out;
}
