<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * The setup checklist (modules/setup): what an administrator still has to do before the wiki
 * shows what it can do, each item worked out from the configuration itself — nothing here is
 * a record of having "done the step", so an item that is undone later shows as undone again.
 *
 *   email      wiki_mail_config() — config.php when that configures a provider (changed
 *              there), else the email wizard's own settings (mailer.php)
 *   llm        an LLM provider (llm_connections.json)
 *   ai         an enabled AI user with instructions (users.json)
 *   taxonomy   a tag tree with terms in it (taxonomy.json)
 *   workflows  an enabled workflow (workflows.json)
 *   cron       the background runner's heartbeat — without it AI jobs, schedules, workflows
 *              and generators never run, and nothing else says so
 *   auth       sign-in on; `'off'` is a warning rather than a step, since a private wiki on
 *              a trusted network may mean it
 *
 * **Show at startup** is per administrator (`setupStartup` on the user record), or the wiki's
 * own setting when sign-in is off and there is no record to keep it on. Unset, it is on only
 * while the wiki looks new — no LLM provider and no AI user — so a wiki upgraded with all of
 * that in place does not greet its administrators with a checklist. And it stops opening by
 * itself once every step is done, whatever the box says. The first time the checklist opens
 * with that default in effect, it is stored (wiki_setup_pin_default), so finishing the first
 * step does not untick the box for the rest.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth_modes.php';
require_once __DIR__ . '/accounts.php';     // wiki_user_is_person()
require_once __DIR__ . '/llm_connections.php';
require_once __DIR__ . '/agent_jobs.php';
require_once __DIR__ . '/taxonomy.php';
if (is_file(__DIR__ . '/workflows.php')) require_once __DIR__ . '/workflows.php';

/** The steps, in the order the checklist shows them. `cron` and `auth` are checks, not steps. */
const WIKI_SETUP_ITEMS = ['llm', 'ai', 'email', 'taxonomy', 'workflows', 'cron', 'auth'];

/** users.json, read once per request. */
function _wiki_setup_users(): array {
    static $users = null;
    if ($users === null) {
        $d = defined('WIKI_SYSTEM_DATA') && is_file(WIKI_SYSTEM_DATA . 'users.json')
            ? json_decode((string)file_get_contents(WIKI_SYSTEM_DATA . 'users.json'), true) : null;
        $users = is_array($d['users'] ?? null) ? $d['users'] : [];
    }
    return $users;
}

/** Terms in the tag tree, folders included. */
function _wiki_setup_tax_count(array $nodes): int {
    $n = 0;
    foreach ($nodes as $node) {
        $n++;
        if (!empty($node['children']) && is_array($node['children'])) $n += _wiki_setup_tax_count($node['children']);
    }
    return $n;
}

/**
 * Each item: `id`, `done`, and what the checklist needs to say about it — a count, or for
 * `cron` its state and the crontab line for this install, for `auth` the mode.
 */
function wiki_setup_status(): array {
    $items = [];

    $conns = function_exists('wiki_llm_connections') ? wiki_llm_connections() : [];
    $items['llm'] = ['done' => count($conns) > 0, 'count' => count($conns)];

    // An AI user counts once it can answer: switched on, on a provider, with instructions
    // of its own (typed, or a page) — the role it is there for.
    $ai_all = 0; $ai_ready = 0;
    foreach (_wiki_setup_users() as $u) {
        if (empty($u['is_ai'])) continue;
        $ai_all++;
        $cfg = $u['ai_config'] ?? [];
        if (!empty($u['disabled']) || trim((string)($cfg['connection_id'] ?? '')) === '') continue;
        if (trim((string)($cfg['system_prompt'] ?? '')) === '' && trim((string)($cfg['system_prompt_page'] ?? '')) === '') continue;
        $ai_ready++;
    }
    $items['ai'] = ['done' => $ai_ready > 0, 'count' => $ai_ready, 'total' => $ai_all];

    // Where it is set decides what the checklist can offer: config.php is changed there.
    $mail = wiki_mail_config();
    $items['email'] = ['done' => $mail['source'] !== '', 'source' => $mail['source'],
                       'provider' => $mail['source'] !== '' ? mail_provider_name() : ''];

    $terms = function_exists('wiki_tax_nodes') ? _wiki_setup_tax_count(wiki_tax_nodes()) : 0;
    $items['taxonomy'] = ['done' => $terms > 0, 'count' => $terms];

    $wf_all = function_exists('wiki_workflows_all') ? wiki_workflows_all() : [];
    $wf_on  = count(array_filter($wf_all, fn($w) => !empty($w['enabled'])));
    $items['workflows'] = ['done' => $wf_on > 0, 'count' => $wf_on, 'total' => count($wf_all)];

    $hb = agent_job_heartbeat();
    $items['cron'] = [
        'done'  => !agent_job_runner_stalled(),
        'state' => $hb === null ? 'never' : (agent_job_runner_stalled() ? 'stalled' : 'ok'),
        'last'  => $hb['last_run'] ?? null,
        // The line for this install, so it can be copied as it is.
        'line'  => '*/' . agent_job_runner_interval() . ' * * * * php ' . __DIR__ . '/run_ai_agent_jobs.php',
    ];

    $mode = wiki_auth_mode();
    $items['auth'] = ['done' => $mode !== 'off' && $mode !== 'invalid', 'mode' => $mode];

    $out = [];
    foreach (WIKI_SETUP_ITEMS as $id) $out[] = ['id' => $id] + $items[$id];
    return $out;
}

/** Every step done — the two checks included. */
function wiki_setup_complete(?array $status = null): bool {
    foreach ($status ?? wiki_setup_status() as $it) if (empty($it['done'])) return false;
    return true;
}

/** No LLM provider and no AI user: what a wiki looks like before anyone has set it up. */
function wiki_setup_looks_new(): bool {
    if (function_exists('wiki_llm_connections') && wiki_llm_connections()) return false;
    foreach (_wiki_setup_users() as $u) if (!empty($u['is_ai'])) return false;
    return true;
}

/** The person record behind this session, or null (sign-in off, or not a person). */
function _wiki_setup_me(): ?array {
    $uid = (int)($_SESSION['user']['uid'] ?? 0);
    if ($uid <= 0) return null;
    foreach (_wiki_setup_users() as $u) {
        if (wiki_user_is_person($u) && (int)($u['uid'] ?? 0) === $uid) return $u;
    }
    return null;
}

/** The box as this administrator left it — or, never touched, whether the wiki looks new. */
function wiki_setup_startup_pref(): bool {
    $me = _wiki_setup_me();
    $stored = $me !== null ? ($me['setupStartup'] ?? null) : wiki_setting('setup_startup');
    return is_bool($stored) ? $stored : wiki_setup_looks_new();
}

/**
 * The checklist is open with the default in effect: keep that default. Otherwise "on while the
 * wiki looks new" would untick itself the moment the first step is done — adding an LLM
 * provider makes the wiki stop looking new — with every other step still to go.
 */
function wiki_setup_pin_default(): void {
    $me = _wiki_setup_me();
    $stored = $me !== null ? ($me['setupStartup'] ?? null) : wiki_setting('setup_startup');
    if (!is_bool($stored) && wiki_setup_looks_new()) wiki_setup_set_startup_pref(true);
}

/** Store the box: on the administrator's record, or the wiki's when there is none. */
function wiki_setup_set_startup_pref(bool $on): bool {
    $uid = (int)($_SESSION['user']['uid'] ?? 0);
    if ($uid <= 0) return wiki_setting_set('setup_startup', $on);
    $f = WIKI_SYSTEM_DATA . 'users.json';
    $fp = @fopen($f, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    $found = false;
    if (is_array($d['users'] ?? null)) {
        foreach ($d['users'] as &$u) {
            if (wiki_user_is_person($u) && (int)($u['uid'] ?? 0) === $uid) {
                $u['setupStartup'] = $on;
                $found = true;
                break;
            }
        }
        unset($u);
    }
    if ($found) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($d, JSON_PRETTY_PRINT));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $found ? true : wiki_setting_set('setup_startup', $on);
}

/** Open the checklist when this page loads? The box, and something still left to do. */
function wiki_setup_autoshow(): bool {
    return wiki_setup_startup_pref() && !wiki_setup_complete();
}
