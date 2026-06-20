<?php
// AI Agent Job Runner — add to crontab to run every 15 minutes:
// */15 * * * * php /path/to/run_ai_agent_jobs.php >> /var/log/wiki-agent-jobs.log 2>&1
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/ai_core.php';
require_once __DIR__ . '/mailer.php';

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

// -- Load data ----------------------------------------------------------------

$jobs_file  = WIKI_SYSTEM_DATA . 'agent_jobs.json';
$users_file = WIKI_SYSTEM_DATA . 'users.json';

$jobs_data  = file_exists($jobs_file)  ? (json_decode(file_get_contents($jobs_file),  true) ?? ['jobs'  => []]) : ['jobs'  => []];
$users_data = file_exists($users_file) ? (json_decode(file_get_contents($users_file), true) ?? ['users' => []]) : ['users' => []];

$jobs = $jobs_data['jobs'] ?? [];
$now  = time();

echo date('c') . " [agent-jobs] Checking " . count($jobs) . " job(s). Server: " . date('H:i') . " " . date_default_timezone_get() . "\n";

if (empty($jobs)) {
    flock($lock_fh, LOCK_UN); fclose($lock_fh);
    exit(0);
}

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

flock($lock_fh, LOCK_UN);
fclose($lock_fh);
echo date('c') . " [agent-jobs] Done.\n";
