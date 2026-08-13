<?php
// AI Agent Job Runner — add to crontab to run every 15 minutes:
// */15 * * * * php /path/to/run_ai_agent_jobs.php >> /var/log/wiki-agent-jobs.log 2>&1
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/ai_core.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/agent_jobs.php';

// -- Schedule check -----------------------------------------------------------

function is_job_due(array $job, int $now): bool {
    $schedule = $job['schedule'] ?? null;
    if (!$schedule || empty($schedule['type']) || empty($schedule['time'])) return false;

    $parts = explode(':', $schedule['time']);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);

    $year  = (int)date('Y', $now);
    $month = (int)date('n', $now);
    $day   = (int)date('j', $now);
    $dow   = (int)date('w', $now); // 0=Sun … 6=Sat

    switch ($schedule['type']) {
        case 'daily':
            break;
        case 'weekly':
            $days = array_map('intval', $schedule['days'] ?? []);
            if (!in_array($dow, $days, true)) return false;
            break;
        case 'monthly':
            $target     = max(1, (int)($schedule['day'] ?? 1));
            $in_month   = (int)date('t', $now);
            $actual_day = min($target, $in_month);
            if ($day !== $actual_day) return false;
            break;
        default:
            return false;
    }

    $scheduled_ts = mktime($h, $m, 0, $month, $day, $year);
    if ($now < $scheduled_ts) return false; // not reached yet today

    $last_run = !empty($job['last_run']) ? (int)strtotime($job['last_run']) : 0;
    return $last_run < $scheduled_ts;
}

// -- Lock ---------------------------------------------------------------------

$lock_file = WIKI_SYSTEM_DATA . 'agent_jobs.lock';
$lock_fh   = fopen($lock_file, 'c');
if (!$lock_fh || !flock($lock_fh, LOCK_EX | LOCK_NB)) {
    echo date('c') . " [agent-jobs] Already running (lock held). Exiting.\n";
    exit(0);
}

// Proof-of-life for the web side: /aiJob turns this into "your job starts in
// x minutes", and its absence into an honest "the runner is not running".
agent_job_touch_heartbeat();

// -- Load data ----------------------------------------------------------------

$jobs_file  = WIKI_SYSTEM_DATA . 'agent_jobs.json';
$users_file = WIKI_SYSTEM_DATA . 'users.json';

$jobs_data  = file_exists($jobs_file)  ? (json_decode(file_get_contents($jobs_file),  true) ?? ['jobs'  => []]) : ['jobs'  => []];
$users_data = file_exists($users_file) ? (json_decode(file_get_contents($users_file), true) ?? ['users' => []]) : ['users' => []];

$jobs = $jobs_data['jobs'] ?? [];
$now  = time();

echo date('c') . " [agent-jobs] Checking " . count($jobs) . " job(s). Server: " . date('H:i') . " " . date_default_timezone_get() . "\n";

// No early exit when there are no scheduled jobs: the one-off /aiJob queue is
// drained further down and must still be serviced.

foreach ($jobs as $idx => &$job) {
    if (empty($job['enabled'])) continue;

    if (!is_job_due($job, $now)) {
        echo date('c') . " [agent-jobs] Skipping '{$job['name']}' (not due).\n";
        continue;
    }

    $job_name = $job['name'] ?? 'unnamed';
    echo date('c') . " [agent-jobs] Running job: {$job_name}\n";

    // Find the AI user
    $ai_user = null;
    foreach ($users_data['users'] ?? [] as $u) {
        if (!empty($u['is_ai']) && (int)($u['uid'] ?? -1) === (int)($job['ai_user_uid'] ?? 0)) {
            $ai_user = $u;
            break;
        }
    }
    if (!$ai_user) {
        echo date('c') . " [agent-jobs] AI user not found for job '{$job_name}'. Skipping.\n";
        continue;
    }

    // Resolve space_dir
    $safe_space = basename($job['space'] ?? basename(PAGES_DIR));
    $space_dir  = rtrim(PAGES_DIR, '/') . '/' . $safe_space;
    if (!is_dir($space_dir)) $space_dir = rtrim(PAGES_DIR, '/');

    // Run
    $indexer = new PageIndexer($space_dir);
    $result  = run_agent_job($job, $ai_user, $indexer, $space_dir);
    $run_ts  = date('c');
    $status  = $result['error'] ? 'error' : 'ok';

    echo date('c') . " [agent-jobs] Job '{$job_name}' finished: {$status}\n";

    // Write log to LOG_DIR/agent-jobs/
    $safe_jn  = preg_replace('/[^a-zA-Z0-9_-]/', '-', $job_name);
    $log_dir  = rtrim(LOG_DIR, '/') . '/agent-jobs/' . $safe_jn . '/';
    $log_file = $log_dir . date('Y-m-d-His') . '.log';
    if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);

    $log_body  = "[{$run_ts}] Job: {$job_name}\n";
    $log_body .= "[{$run_ts}] Status: {$status}\n";
    $log_body .= "[{$run_ts}] AI User: " . ($ai_user['name'] ?? 'AI') . "\n\n";
    $log_body .= $result['error']
        ? "ERROR:\n" . $result['error'] . "\n"
        : "RESULT:\n" . $result['reply'] . "\n";
    if (!empty($result['debug'])) $log_body .= "\n" . $result['debug'];
    file_put_contents($log_file, $log_body);

    // Send failure alert to ADMIN_EMAIL
    if ($result['error'] && defined('ADMIN_EMAIL') && ADMIN_EMAIL && is_mail_configured()) {
        $subj  = APP_TITLE . ' — Agent job failed: ' . $job_name;
        $body  = '<h2>Agent Job Failed</h2>'
               . '<p><strong>Job:</strong> '     . htmlspecialchars($job_name)             . '</p>'
               . '<p><strong>Run time:</strong> ' . htmlspecialchars($run_ts)               . '</p>'
               . '<p><strong>AI User:</strong> '  . htmlspecialchars($ai_user['name'] ?? 'AI') . '</p>'
               . '<p><strong>Error:</strong></p>'
               . '<pre style="background:#fff5f5;padding:0.8rem;border-radius:4px">'
               . htmlspecialchars($result['error']) . '</pre>'
               . '<p><strong>Log file:</strong> <code>' . htmlspecialchars($log_file) . '</code></p>';
        send_email(ADMIN_EMAIL, 'Admin', $subj, $body);
    }

    // Update job metadata
    $job['last_run']      = $run_ts;
    $job['last_status']   = $status;
    $job['last_log_file'] = $log_file;
}
unset($job);

// Save updated jobs
$jobs_data['jobs'] = $jobs;
file_put_contents($jobs_file, json_encode($jobs_data, JSON_PRETTY_PRINT));

// =============================================================================
// One-off jobs (/aiJob from the chat prompt) — see agent_jobs.php
// =============================================================================

// Writes the outcome back to the requester: into the chat thread if it is still
// there, otherwise by email so a finished job is never silently dropped.
function oneoff_deliver(array $job, ?string $reply, ?string $error): bool {
    $delivered = agent_job_deliver_to_chat($job, $reply, $error);
    if (!$delivered) {
        echo date('c') . " [agent-jobs]   thread gone — falling back to email for {$job['id']}\n";
    }
    // Chat delivery failed → email regardless of the user's opt-in, since the
    // result would otherwise be lost entirely.
    agent_job_notify_requester($job, $reply, $error, !$delivered);
    return $delivered;
}

// Recover orphans first. We hold the runner lock, so no other runner is active:
// anything still 'running' past the timeout lost its process.
$oneoff_orphans = agent_job_queue_mutate(function (array &$jobs) {
    $orphans = [];
    foreach ($jobs as &$j) {
        if (($j['state'] ?? '') !== 'running') continue;
        $age_min = (time() - (int)strtotime((string)($j['started_at'] ?? ''))) / 60;
        if ($age_min < AGENT_JOB_RUNNING_TIMEOUT_MIN) continue;
        $j['state']       = 'error';
        $j['error']       = 'The job did not finish — the runner stopped before it completed.';
        $j['finished_at'] = date('c');
        $orphans[]        = $j;
    }
    unset($j);
    return $orphans;
});
foreach ($oneoff_orphans as $orphan) {
    echo date('c') . " [agent-jobs] Recovered orphaned one-off job {$orphan['id']}.\n";
    oneoff_deliver($orphan, null, $orphan['error']);
}

// Claim this tick's batch in one locked pass, so a second runner (or the web
// side) can never hand out the same job twice.
$oneoff_batch = agent_job_queue_mutate(function (array &$jobs) {
    $take = [];
    foreach ($jobs as &$j) {
        if (count($take) >= AGENT_JOB_MAX_PER_RUN) break;
        if (($j['state'] ?? '') !== 'queued') continue;
        $j['state']      = 'running';
        $j['started_at'] = date('c');
        $take[]          = $j;
    }
    unset($j);
    return $take;
});

$oneoff_waiting = 0;
foreach (agent_job_queue_read() as $_q) if (($_q['state'] ?? '') === 'queued') $oneoff_waiting++;
echo date('c') . " [agent-jobs] One-off queue: " . count($oneoff_batch) . " starting, {$oneoff_waiting} still waiting.\n";

foreach ($oneoff_batch as $oj) {
    $oj_id = $oj['id'] ?? 'unknown';
    echo date('c') . " [agent-jobs] Running one-off job {$oj_id} for " . ($oj['requested_by']['name'] ?? '?') . ".\n";

    // Re-read users.json per job: it may have changed since this tick started.
    $oj_users = is_file($users_file)
        ? (json_decode((string)file_get_contents($users_file), true)['users'] ?? []) : [];
    $oj_ai = null;
    foreach ($oj_users as $_ou) {
        if (!empty($_ou['is_ai']) && (int)($_ou['uid'] ?? -1) === (int)($oj['ai_user_uid'] ?? 0)) { $oj_ai = $_ou; break; }
    }

    $oj_reply = null;
    $oj_error = null;
    $oj_debug = '';
    if (!$oj_ai) {
        $oj_error = 'The AI user for this job no longer exists.';
    } else {
        $oj_space_dir = agent_job_space_dir((string)($oj['space'] ?? ''));
        try {
            $oj_result = run_agent_job($oj, $oj_ai, new PageIndexer($oj_space_dir), $oj_space_dir);
            $oj_reply  = $oj_result['reply'] ?? null;
            $oj_error  = $oj_result['error'] ?? null;
            $oj_debug  = (string)($oj_result['debug'] ?? '');
        } catch (\Throwable $e) {
            // A crash must still resolve the placeholder rather than leave it spinning.
            $oj_error = 'Job crashed: ' . $e->getMessage();
        }
    }
    $oj_status = $oj_error === null ? 'ok' : 'error';
    $oj_ts     = date('c');
    echo date('c') . " [agent-jobs] One-off job {$oj_id} finished: {$oj_status}\n";

    // Log alongside the scheduled jobs' logs, under a reserved folder name.
    $oj_log_file = null;
    if (defined('LOG_DIR') && LOG_DIR) {
        $oj_log_dir = rtrim(LOG_DIR, '/') . '/agent-jobs/_oneoff/';
        if (!is_dir($oj_log_dir)) @mkdir($oj_log_dir, 0755, true);
        $oj_log_file = $oj_log_dir . preg_replace('/[^a-zA-Z0-9_-]/', '-', $oj_id) . '.log';
        $oj_body  = "[{$oj_ts}] One-off job: {$oj_id}\n"
                  . "[{$oj_ts}] Status: {$oj_status}\n"
                  . "[{$oj_ts}] Requested by: " . ($oj['requested_by']['name'] ?? '?') . "\n"
                  . "[{$oj_ts}] AI User: " . ($oj_ai['name'] ?? $oj['ai_user_name'] ?? 'AI') . "\n"
                  . "[{$oj_ts}] Space: " . (($oj['space'] ?? '') !== '' ? $oj['space'] : '(root)') . "\n"
                  . "[{$oj_ts}] Thread: " . ($oj['reply_to']['chat'] ?? '?') . "\n\n"
                  . "PROMPT:\n" . ($oj['prompt'] ?? '') . "\n\n"
                  . ($oj_error !== null ? "ERROR:\n{$oj_error}\n" : "RESULT:\n{$oj_reply}\n")
                  . ($oj_debug !== '' ? "\n" . $oj_debug : '');
        @file_put_contents($oj_log_file, $oj_body);
    }

    $oj_delivered = oneoff_deliver($oj, $oj_reply, $oj_error);

    agent_job_queue_mutate(function (array &$jobs) use ($oj_id, $oj_status, $oj_error, $oj_ts, $oj_log_file, $oj_delivered) {
        foreach ($jobs as &$j) {
            if (($j['id'] ?? '') !== $oj_id) continue;
            $j['state']       = $oj_status;
            $j['error']       = $oj_error;
            $j['finished_at'] = $oj_ts;
            $j['log_file']    = $oj_log_file;
            $j['delivered']   = $oj_delivered;
            break;
        }
        unset($j);
    });

    if ($oj_error !== null && defined('ADMIN_EMAIL') && ADMIN_EMAIL && is_mail_configured()) {
        $oj_h = fn($s) => htmlspecialchars((string)$s);
        send_email(ADMIN_EMAIL, 'Admin', APP_TITLE . ' — One-off AI job failed',
              '<h2>One-off AI Job Failed</h2>'
            . '<p><strong>Job:</strong> ' . $oj_h($oj_id) . '</p>'
            . '<p><strong>Requested by:</strong> ' . $oj_h($oj['requested_by']['name'] ?? '?') . '</p>'
            . '<p><strong>AI User:</strong> ' . $oj_h($oj_ai['name'] ?? $oj['ai_user_name'] ?? 'AI') . '</p>'
            . '<p><strong>Run time:</strong> ' . $oj_h($oj_ts) . '</p>'
            . '<p><strong>Error:</strong></p><pre style="background:#fff5f5;padding:0.8rem;border-radius:4px">'
            . $oj_h($oj_error) . '</pre>'
            . ($oj_log_file ? '<p><strong>Log file:</strong> <code>' . $oj_h($oj_log_file) . '</code></p>' : ''));
    }
}

flock($lock_fh, LOCK_UN);
fclose($lock_fh);
echo date('c') . " [agent-jobs] Done.\n";
