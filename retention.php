<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// RETENTION — how long the wiki keeps its own logs and run histories
//
// Admin → Content → Retention policies. Before it, nothing under LOG_DIR was ever deleted
// — access and error logs, agent-job and workflow run logs, the audit log all grew for as
// long as the wiki ran — and the two run histories were trimmed by limits fixed in code.
//
// - **Keep forever is the default** for every log, so upgrading deletes nothing; an admin
//   opts in per log. The histories keep the limits they always had (7 days / 100 jobs,
//   14 days / 1000 workflow runs), now changeable.
// - **Whole files only, by age, never rewritten.** That is what makes the audit log safe
//   to include: a forwarder such as Splunk tracks a file by offset, so *editing* a written
//   file makes it re-read or skip events — deleting a past day it has finished with does
//   not. Today's file is never a candidate. Shortening the audit trail is itself audited.
// - **Once a day, from the cron runner** (`wiki_retention_sweep_if_due()`, scheduler slot
//   only), so no request ever waits on it. "Clean up now" in the tab runs the same sweep.
// - Out of scope: files the wiki does not write itself — nginx's and PHP's own logs, and
//   cron's stdout. Each is one ever-growing file, which is logrotate's job.
// =================================================================

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit.php';

const WIKI_RETENTION_KEY = 'retention';

/**
 * The log families, each a directory under LOG_DIR and a filename pattern. Files whose name
 * carries the day (`(\d{4}-\d{2}-\d{2})` captured) are dated by it, which survives a copy
 * or a restore that resets mtimes; the per-run logs are dated by mtime.
 */
const WIKI_RETENTION_LOGS = [
    'access_log'    => ['dir' => '',           'pattern' => '/^(\d{4}-\d{2}-\d{2})_access\.log$/',  'recursive' => false],
    'error_log'     => ['dir' => '',           'pattern' => '/^wiki_errors_(\d{4}-\d{2}-\d{2})\.log$/', 'recursive' => false],
    'agent_job_log' => ['dir' => 'agent-jobs', 'pattern' => '/\.log$/',                            'recursive' => true],
    'workflow_log'  => ['dir' => 'workflows',  'pattern' => '/\.log$/',                            'recursive' => true],
    'audit_log'     => ['dir' => 'audit',      'pattern' => '/^(\d{4}-\d{2}-\d{2})\.log$/',        'recursive' => false],
];

/** Every setting, with its default and bounds. `0` days on a log means keep forever. */
const WIKI_RETENTION_FIELDS = [
    'access_log_days'       => ['default' => 0,    'min' => 0,  'max' => 3650],
    'error_log_days'        => ['default' => 0,    'min' => 0,  'max' => 3650],
    'agent_job_log_days'    => ['default' => 0,    'min' => 0,  'max' => 3650],
    'workflow_log_days'     => ['default' => 0,    'min' => 0,  'max' => 3650],
    'audit_log_days'        => ['default' => 0,    'min' => 0,  'max' => 3650],
    // The histories are files the wiki rewrites on every job and run, so they always have
    // a bound — "forever" is not offered for them.
    'job_history_days'      => ['default' => 7,    'min' => 1,  'max' => 365],
    'job_history_keep'      => ['default' => 100,  'min' => 10, 'max' => 10000],
    'workflow_history_days' => ['default' => 14,   'min' => 1,  'max' => 365],
    'workflow_history_keep' => ['default' => 1000, 'min' => 10, 'max' => 10000],
];

/** All settings, defaults filled in. */
function wiki_retention_all(): array {
    $stored = wiki_setting(WIKI_RETENTION_KEY, []);
    if (!is_array($stored)) $stored = [];
    $out = [];
    foreach (WIKI_RETENTION_FIELDS as $k => $f) {
        $v = array_key_exists($k, $stored) ? (int)$stored[$k] : $f['default'];
        $out[$k] = max($f['min'], min($f['max'], $v));
    }
    return $out;
}

function wiki_retention(string $key): int {
    return wiki_retention_all()[$key] ?? (int)(WIKI_RETENTION_FIELDS[$key]['default'] ?? 0);
}

/**
 * Save the posted values (only the keys given), clamped to their bounds. Each value that
 * actually changed is audited on its own, like the other settings in this tab.
 *
 * @return array the settings as stored afterwards
 */
function wiki_retention_save(array $in): array {
    $before = wiki_retention_all();
    $after  = $before;
    foreach (WIKI_RETENTION_FIELDS as $k => $f) {
        if (!array_key_exists($k, $in) || !is_numeric($in[$k])) continue;
        $after[$k] = max($f['min'], min($f['max'], (int)$in[$k]));
    }
    if ($after === $before) return $after;
    if (!wiki_setting_set(WIKI_RETENTION_KEY, $after)) {
        throw new Exception('Could not save the setting — is WIKI_SYSTEM_DATA writable?');
    }
    foreach ($after as $k => $v) {
        if ($v === $before[$k]) continue;
        wiki_audit_log('update', 'success', ['object' => 'settings.json', 'object_type' => 'setting',
            'change_type' => 'retention.' . $k, 'value' => (string)$v, 'old_value' => (string)$before[$k]]);
    }
    return $after;
}

function wiki_retention_log_root(): ?string {
    if (!defined('LOG_DIR') || !LOG_DIR) return null;
    $root = rtrim(LOG_DIR, '/');
    return is_dir($root) ? $root : null;
}

/**
 * The files of one log family: [abs path, day it belongs to (Y-m-d), bytes].
 */
function wiki_retention_files(string $item): array {
    $def  = WIKI_RETENTION_LOGS[$item] ?? null;
    $root = wiki_retention_log_root();
    if (!$def || $root === null) return [];
    $dir = $def['dir'] === '' ? $root : $root . '/' . $def['dir'];
    if (!is_dir($dir)) return [];
    $paths = [];
    if ($def['recursive']) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile() && !$f->isLink()) $paths[] = $f->getPathname();
    } else {
        foreach ((array)scandir($dir) as $n) {
            if (is_file($dir . '/' . $n) && !is_link($dir . '/' . $n)) $paths[] = $dir . '/' . $n;
        }
    }
    $out = [];
    foreach ($paths as $p) {
        if (!preg_match($def['pattern'], basename($p), $m)) continue;
        $day = isset($m[1]) ? $m[1] : date('Y-m-d', (int)@filemtime($p));
        $out[] = [$p, $day, (int)@filesize($p)];
    }
    return $out;
}

/** The first day that is kept with a limit of $days; anything dated before it goes. */
function wiki_retention_cutoff_day(int $days): string {
    return date('Y-m-d', strtotime('today') - ($days - 1) * 86400);
}

/**
 * What a family holds, and what a limit of $days would remove — for the tab, which shows
 * both before anything is saved. `0` days removes nothing.
 */
function wiki_retention_scan(string $item, int $days): array {
    $files = wiki_retention_files($item);
    $out = ['files' => count($files), 'bytes' => array_sum(array_column($files, 2)),
            'old_files' => 0, 'old_bytes' => 0, 'oldest' => null];
    $cut = $days > 0 ? wiki_retention_cutoff_day($days) : null;
    foreach ($files as [, $day, $bytes]) {
        if ($out['oldest'] === null || $day < $out['oldest']) $out['oldest'] = $day;
        if ($cut !== null && $day < $cut) { $out['old_files']++; $out['old_bytes'] += $bytes; }
    }
    return $out;
}

/**
 * Delete every log file past its family's limit. Returns [item => files deleted].
 *
 * Empty directories left in the per-run families are removed too (a deleted workflow's
 * folder, a renamed job's), never the family's own root.
 */
function wiki_retention_sweep(): array {
    $cfg = wiki_retention_all();
    $deleted = [];
    foreach (array_keys(WIKI_RETENTION_LOGS) as $item) {
        $days = $cfg[$item . '_days'] ?? 0;
        if ($days <= 0) continue;
        $cut = wiki_retention_cutoff_day($days);
        $n = 0;
        foreach (wiki_retention_files($item) as [$path, $day]) {
            if ($day < $cut && @unlink($path)) $n++;
        }
        if ($n === 0) continue;
        $deleted[$item] = $n;
        $def = WIKI_RETENTION_LOGS[$item];
        if ($def['recursive'] && ($root = wiki_retention_log_root()) !== null) {
            $base = $root . '/' . $def['dir'];
            $dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                                                  RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($dirs as $d) if ($d->isDir() && !$d->isLink()) @rmdir($d->getPathname());   // fails unless empty
        }
    }
    if ($deleted) {
        // One entry per sweep that removed anything. For the audit log this is the record
        // that the trail was shortened, written into the trail itself.
        wiki_audit_log('delete', 'success', ['object' => 'logs', 'object_type' => 'logs',
            'change_type' => 'retention_sweep',
            'value' => implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($deleted), $deleted))]);
    }
    return $deleted;
}

/**
 * The cron runner's hook: sweep at most once per calendar day. The stamp lives in
 * WIKI_SYSTEM_DATA, and is written before the sweep, so a sweep that dies part-way is not
 * retried every two minutes for the rest of the day.
 *
 * @return array|null what was deleted, or null when it was not due
 */
function wiki_retention_sweep_if_due(): ?array {
    if (!defined('WIKI_SYSTEM_DATA')) return null;
    $stamp = rtrim(WIKI_SYSTEM_DATA, '/') . '/retention_sweep.json';
    $last = is_file($stamp) ? (json_decode((string)file_get_contents($stamp), true)['day'] ?? '') : '';
    if ($last === date('Y-m-d')) return null;
    @file_put_contents($stamp, json_encode(['day' => date('Y-m-d'), 'at' => date('c')]));
    return wiki_retention_sweep();
}
