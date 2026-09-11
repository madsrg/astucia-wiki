<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// AI Agent Job Runner — add to crontab to run every 15 minutes:
// */15 * * * * php /path/to/run_ai_agent_jobs.php >> /var/log/wiki-agent-jobs.log 2>&1
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/space_settings.php';
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

// One lock per runner slot, tried in order. A tick takes the lowest free slot; if every
// slot is held, this tick has nothing to add and exits. With a single lock the queue was
// strictly serial, so one slow job stalled every job behind it no matter how often cron
// fired — which is the thing a short interval on its own cannot fix.
//
// Claiming is already safe for any number of runners: jobs are taken from the queue in
// one locked read-modify-write (agent_job_queue_mutate), so two runners cannot be handed
// the same job.
$lock_slot = 0;
$lock_fh   = null;
for ($_s = 1; $_s <= agent_job_runner_slots(); $_s++) {
    $_fh = fopen(WIKI_SYSTEM_DATA . 'agent_jobs.lock' . ($_s === 1 ? '' : '.' . $_s), 'c');
    if (!$_fh) continue;
    if (flock($_fh, LOCK_EX | LOCK_NB)) { $lock_fh = $_fh; $lock_slot = $_s; break; }
    fclose($_fh);
}
if ($lock_fh === null) {
    echo date('c') . " [agent-jobs] All " . agent_job_runner_slots()
        . " runner slot(s) busy. Exiting.\n";
    exit(0);
}
echo date('c') . " [agent-jobs] Runner slot {$lock_slot}/" . agent_job_runner_slots() . ".\n";

// Slot 1 alone runs the *scheduled* jobs. Their due-check is a read-modify-write of
// agent_jobs.json with no lock of its own, so two runners could both find a job due and
// run it twice. Serialising that on one slot keeps it exactly as safe as it was, and
// costs nothing: the scheduler pass is cheap, and it never had more than one runner.
$is_scheduler = ($lock_slot === 1);

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

echo date('c') . " [agent-jobs] " . ($is_scheduler
        ? "Checking " . count($jobs) . " scheduled job(s)."
        : "Scheduled jobs: slot 1's job, skipping.")
    . " Server: " . date('H:i') . " " . date_default_timezone_get() . "\n";

// No early exit when there are no scheduled jobs: the one-off /aiJob queue is
// drained further down and must still be serviced.

foreach (($is_scheduler ? $jobs : []) as $idx => &$job) {
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

    // A frozen Space is frozen for the runner too. Skipping the whole job — rather
    // than letting it run and refusing each write tool — keeps it from burning an
    // LLM call on work it cannot save.
    if (wiki_space_dir_is_readonly($space_dir)) {
        echo date('c') . " [agent-jobs] Space '{$safe_space}' is read-only. Skipping job '{$job_name}'.\n";
        continue;
    }

    // Run
    $indexer = new PageIndexer($space_dir);
    agent_job_touch_heartbeat();
    $result  = run_agent_job($job, $ai_user, $indexer, $space_dir, 'agent_job_touch_heartbeat');
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

// Recover orphans first: a job left 'running' whose process died.
//
// This used to lean on "we hold the only lock, so nothing else is running". With slots
// that is no longer true, so the age test stands alone — and it is the real test anyway:
// AGENT_JOB_RUNNING_TIMEOUT_MIN is longer than any run the per-call timeout and iteration
// cap allow, so a job past it is hung or gone whichever runner started it.
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
    agent_job_announce($orphan);
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

foreach ($oneoff_batch as $_started) agent_job_announce($_started);

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
    $oj_space_dir = agent_job_space_dir((string)($oj['space'] ?? ''));
    if (!$oj_ai) {
        $oj_error = 'The AI user for this job no longer exists.';
    } elseif (wiki_space_dir_is_readonly($oj_space_dir)) {
        // Fail the job rather than run it: it could not save its result. The error
        // still travels back into the thread, which resolves the pending placeholder
        // that was written before the Space was frozen — leaving that spinning
        // forever would be the worse outcome.
        $oj_error = 'The space "' . basename(rtrim($oj_space_dir, '/')) . '" is read-only, so this job was not run.';
    } else {
        try {
            agent_job_touch_heartbeat();
            $oj_result = run_agent_job($oj, $oj_ai, new PageIndexer($oj_space_dir), $oj_space_dir,
                                       'agent_job_touch_heartbeat');
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
    agent_job_announce(['id' => $oj_id, 'state' => $oj_status,
                        'requested_by' => $oj['requested_by'] ?? []]);

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
