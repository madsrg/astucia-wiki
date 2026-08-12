<?php
// =================================================================
// ASTUCIA WIKI — ONE-OFF AI AGENT JOBS (QUEUE)
//
// A one-off job is a single expensive, reasoning-heavy request queued from the
// chat prompt with /aiJob. The web request only *accepts* it; the cron runner
// (run_ai_agent_jobs.php) executes it on its next tick and writes the answer
// back into the chat thread it came from.
//
// This file is the queue itself, shared by the producer (api.php) and the
// consumer (run_ai_agent_jobs.php). Recurring scheduled jobs are a separate
// thing living in agent_jobs.json — one-offs have no schedule, are created by
// end users rather than admins, and are pruned once delivered.
// =================================================================

// How many one-off jobs one runner tick will execute. Keeps a burst of queued
// jobs from overrunning the cron window; the rest wait for the next tick.
const AGENT_JOB_MAX_PER_RUN = 3;
// A job still marked 'running' after this long lost its runner (crash, kill,
// timeout). The next tick fails it so the chat placeholder resolves.
const AGENT_JOB_RUNNING_TIMEOUT_MIN = 60;
// Per-user backpressure: how many jobs one requester may have waiting.
const AGENT_JOB_MAX_QUEUED_PER_USER = 3;
// Delivered jobs are kept for a while so the admin panel can show history.
const AGENT_JOB_HISTORY_KEEP = 100;
const AGENT_JOB_HISTORY_DAYS = 7;

// Minutes between runner ticks. Must match the crontab entry — it is what the
// "will be processed in x minutes" estimate is built from.
function agent_job_runner_interval(): int {
    $m = defined('AGENT_JOB_RUNNER_INTERVAL_MINUTES') ? (int)AGENT_JOB_RUNNER_INTERVAL_MINUTES : 15;
    return $m > 0 ? $m : 15;
}

function agent_job_queue_path(): string     { return WIKI_SYSTEM_DATA . 'agent_jobs_queue.json'; }
function agent_job_heartbeat_path(): string { return WIKI_SYSTEM_DATA . 'agent_jobs_heartbeat.json'; }

// --- Queue read / write --------------------------------------------------------

function agent_job_queue_read(): array {
    $f = agent_job_queue_path();
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d['jobs'] ?? null) ? $d['jobs'] : [];
}

// Drop delivered jobs that are old or beyond the history cap. Queued and running
// jobs are never pruned — only finished ones.
function _agent_job_prune(array $jobs): array {
    $cutoff = time() - (AGENT_JOB_HISTORY_DAYS * 86400);
    $live = $done = [];
    foreach ($jobs as $j) {
        if (in_array($j['state'] ?? '', ['queued', 'running'], true)) { $live[] = $j; continue; }
        $ts = strtotime($j['finished_at'] ?? $j['created_at'] ?? '') ?: 0;
        if ($ts >= $cutoff) $done[] = $j;
    }
    usort($done, fn($a, $b) => strcmp((string)($b['finished_at'] ?? ''), (string)($a['finished_at'] ?? '')));
    return array_values(array_merge($live, array_slice($done, 0, AGENT_JOB_HISTORY_KEEP)));
}

/**
 * Read-modify-write the queue under an exclusive lock.
 *
 * The web request (enqueue) and the cron runner (claim/complete) both write this
 * file, so every mutation goes through here. The callback must take the job list
 * by reference — `function (array &$jobs) { … }` — and may return a value, which
 * is passed back to the caller.
 */
function agent_job_queue_mutate(callable $fn) {
    $file = agent_job_queue_path();
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $jobs = agent_job_queue_read();
        $ret  = $fn($jobs);
        @file_put_contents($file, json_encode(['jobs' => _agent_job_prune($jobs)], JSON_PRETTY_PRINT));
        return $ret;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

// --- Heartbeat / ETA ----------------------------------------------------------

// Called by the runner on every tick, so the web side can tell a scheduled
// runner from a crontab entry nobody ever added.
function agent_job_touch_heartbeat(): void {
    if (!defined('WIKI_SYSTEM_DATA')) return;
    @file_put_contents(agent_job_heartbeat_path(), json_encode([
        'last_run' => date('c'),
        'interval' => agent_job_runner_interval(),
    ]));
}

function agent_job_heartbeat(): ?array {
    $f = agent_job_heartbeat_path();
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) && !empty($d['last_run']) ? $d : null;
}

/**
 * Minutes until a job queued now is expected to start, or null when that can't
 * be promised — no heartbeat yet, or the runner has missed enough ticks that it
 * is presumably not scheduled. Callers surface null as "queued, but the runner
 * does not appear to be running" rather than inventing a time.
 *
 * @param int $ahead Jobs already queued in front of this one.
 */
function agent_job_eta_minutes(int $ahead = 0): ?int {
    $interval = agent_job_runner_interval();
    $hb = agent_job_heartbeat();
    if (!$hb) return null;
    $since = (time() - (int)strtotime($hb['last_run'])) / 60;
    if ($since > ($interval * 2) + 1) return null; // missed two ticks — not running
    // Jobs beyond one tick's capacity spill into later ticks.
    $wait = max(0, $interval - $since) + (intdiv($ahead, AGENT_JOB_MAX_PER_RUN) * $interval);
    return max(1, (int)ceil($wait));
}

// --- Path helpers -------------------------------------------------------------

// '' means the space root (PAGES_DIR itself); anything else is one directory
// under it. basename() keeps a stored value from escaping the content root.
function agent_job_space_dir(string $space): string {
    $root = rtrim(PAGES_DIR, '/');
    $space = trim($space);
    if ($space === '') return $root;
    $dir = $root . '/' . basename($space);
    return is_dir($dir) ? $dir : $root;
}

// Absolute path of the .chat file a job replies into, or null if it is gone
// (renamed or deleted while the job was queued).
function agent_job_chat_path(array $job): ?string {
    $rel = ltrim(str_replace('..', '', (string)($job['reply_to']['chat'] ?? '')), '/');
    if ($rel === '' || substr($rel, -5) !== '.chat') return null;
    $abs = agent_job_space_dir((string)($job['space'] ?? '')) . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

// --- Delivery ----------------------------------------------------------------

/**
 * Replace the job's pending placeholder message with the result.
 *
 * Returns false when the answer could not be delivered — the thread was renamed
 * or deleted, or the placeholder is gone (purged, or the user deleted it). The
 * caller then falls back to email so a completed job is never silently lost.
 */
function agent_job_deliver_to_chat(array $job, ?string $reply, ?string $error): bool {
    $chat = agent_job_chat_path($job);
    if ($chat === null) return false;
    $msg_id = (int)($job['reply_to']['message_id'] ?? 0);

    $lock = @fopen($chat . '.joblock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $data = json_decode((string)file_get_contents($chat), true);
        if (!is_array($data['messages'] ?? null)) return false;
        $found = false;
        foreach ($data['messages'] as &$m) {
            if ((int)($m['id'] ?? -1) !== $msg_id) continue;
            $m['text']      = $error !== null
                ? '⚠️ Job failed: ' . $error
                : (trim((string)$reply) !== '' ? $reply : '(the job produced no output)');
            $m['timestamp'] = date('c');
            unset($m['pending']);
            $found = true;
            break;
        }
        unset($m);
        if (!$found) return false;
        return file_put_contents($chat, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        @unlink($chat . '.joblock');
    }
}

// Email the requester the outcome. Opt-in per user via "notifyAgentJobs" in
// users.json (same shape as the daily-digest flag). $forced is set when chat
// delivery failed, so the result reaches them regardless of the opt-in.
function agent_job_notify_requester(array $job, ?string $reply, ?string $error, bool $forced = false): bool {
    if (!function_exists('send_email') || !is_mail_configured()) return false;
    $uid = $job['requested_by']['uid'] ?? null;
    if ($uid === null) return false;
    $users_file = WIKI_SYSTEM_DATA . 'users.json';
    if (!is_file($users_file)) return false;

    $user = null;
    foreach (json_decode((string)file_get_contents($users_file), true)['users'] ?? [] as $u) {
        if ((int)($u['uid'] ?? -1) === (int)$uid) { $user = $u; break; }
    }
    if (!$user || empty($user['email'])) return false;
    if (!$forced && empty($user['notifyAgentJobs'])) return false;

    $h    = fn($s) => htmlspecialchars((string)$s);
    $app  = defined('APP_TITLE') ? APP_TITLE : 'Wiki';
    $ok   = $error === null;
    $subj = $app . ' — AI job ' . ($ok ? 'finished' : 'failed');
    $body = '<h2>AI job ' . ($ok ? 'finished' : 'failed') . '</h2>'
          . '<p><strong>Asked:</strong> ' . $h(mb_strimwidth((string)($job['prompt'] ?? ''), 0, 300, '…')) . '</p>'
          . '<p><strong>AI user:</strong> ' . $h($job['ai_user_name'] ?? 'AI') . '</p>'
          . '<p><strong>Thread:</strong> ' . $h($job['reply_to']['chat'] ?? '(unknown)') . '</p>'
          . ($forced ? '<p><em>The chat thread this job was started from is no longer available, '
                     . 'so the result is only in this email.</em></p>' : '')
          . ($ok ? '<p><strong>Result:</strong></p><pre style="white-space:pre-wrap;background:#f7fafc;padding:0.8rem;border-radius:4px">'
                   . $h(mb_strimwidth((string)$reply, 0, 20000, '…')) . '</pre>'
                 : '<pre style="background:#fff5f5;padding:0.8rem;border-radius:4px">' . $h($error) . '</pre>');
    return send_email($user['email'], $user['name'] ?? '', $subj, $body);
}
