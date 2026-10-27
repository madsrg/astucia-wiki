<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Scheduled agent jobs: when does a schedule fire. One engine behind two ways of saying it —
// an Outlook-style recurrence and a cron expression — so the runner, the admin list's
// "next run" and the form's preview cannot disagree.
//
// The shape stored as a job's "schedule" in agent_jobs.json:
//
//   type      'minutes' | 'hourly' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'cron'
//   interval  every N minutes / hours / days / weeks / months / years (default 1)
//   time      'HH:MM' — the time of day; for minutes/hourly, where the day's run of
//             repeats starts
//   until     'HH:MM' — minutes/hourly only: the last time a repeat may fall on
//   days      [0..6]  — weekly: the days it runs; minutes/hourly: an optional day filter
//   day       1..31   — monthly/yearly "on day N" (clamped to the month's length)
//   nth       1..4 | -1 (last) with weekday 0..6 | 'day' | 'weekday' — monthly/yearly
//             "on the second Tuesday", "on the last weekday"
//   month     1..12   — yearly
//   start/end 'YYYY-MM-DD' — optional range; start also anchors "every N" (every 2 weeks
//             counts from the week of start)
//   cron      'm h dom mon dow' — five fields, or @hourly / @daily / @weekly / @monthly /
//             @yearly
//
// The original three types (daily, weekly with days, monthly with day) are a subset of
// this, so existing jobs read unchanged.
//
// Weekdays are 0=Sun … 6=Sat throughout, as date('w') and cron have them. A week, for
// "every N weeks", starts on Monday. Repeats within a day restart at `time` each day:
// "every 5 hours from 00:00" is 00, 05, 10, 15, 20 every day, not a rolling 5-hour chain.
// Everything is server-local time, which is what the form shows.

/** How far back a missed occurrence still runs: a runner down overnight catches up once. */
const SCHEDULE_CATCHUP_SECONDS = 86400;
/** How far ahead next-run looks. Feb 29 every 4 years needs 8 years in the worst case. */
const SCHEDULE_LOOKAHEAD_DAYS = 3000;
/** The shortest repeat accepted. Every scheduled run is an LLM call. */
const SCHEDULE_MIN_MINUTES = 5;

// -- Cron ---------------------------------------------------------------------

/**
 * Parse a five-field cron expression. Returns ['min' => set, 'hour' => set, 'dom' => set,
 * 'mon' => set, 'dow' => set, 'dom_any' => bool, 'dow_any' => bool], each set an array
 * keyed by value. Throws on anything it does not understand rather than guessing.
 */
function schedule_cron_parse(string $expr): array {
    $expr = strtolower(trim(preg_replace('/\s+/', ' ', $expr)));
    $macros = ['@hourly' => '0 * * * *', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *',
               '@weekly' => '0 0 * * 0', '@monthly' => '0 0 1 * *',
               '@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *'];
    if (isset($macros[$expr])) $expr = $macros[$expr];
    $f = explode(' ', $expr);
    if (count($f) !== 5) throw new Exception('A cron expression has five fields: minute hour day-of-month month day-of-week.');

    $names = [
        'mon' => ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                  'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12],
        'dow' => ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6],
    ];
    $spec = ['min' => [0, 59], 'hour' => [0, 23], 'dom' => [1, 31], 'mon' => [1, 12], 'dow' => [0, 7]];
    $out  = [];
    foreach (array_keys($spec) as $i => $key) {
        [$lo, $hi] = $spec[$key];
        $field = $f[$i];
        if (isset($names[$key])) $field = strtr($field, $names[$key]);
        $set = [];
        foreach (explode(',', $field) as $part) {
            if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $part, $m)) {
                throw new Exception("Cron field " . ($i + 1) . " ('{$f[$i]}') is not understood.");
            }
            $step = isset($m[2]) ? (int)$m[2] : 1;
            if ($step < 1) throw new Exception("Cron field " . ($i + 1) . ": a step must be at least 1.");
            if ($m[1] === '*') { $a = $lo; $b = $hi; }
            elseif (strpos($m[1], '-') !== false) { [$a, $b] = array_map('intval', explode('-', $m[1])); }
            else { $a = (int)$m[1]; $b = isset($m[2]) ? $hi : $a; } // "5/15" is 5, 20, 35, 50
            if ($a < $lo || $b > $hi || $a > $b) {
                throw new Exception("Cron field " . ($i + 1) . " ('{$f[$i]}') is outside {$lo}–{$hi}.");
            }
            for ($v = $a; $v <= $b; $v += $step) $set[$key === 'dow' && $v === 7 ? 0 : $v] = true;
        }
        $out[$key] = $set;
    }
    // Vixie cron: when both day fields are restricted, a day matching either one counts.
    $out['dom_any'] = $f[2][0] === '*';
    $out['dow_any'] = $f[4][0] === '*';
    return $out;
}

// -- Normalising --------------------------------------------------------------

/** 'H:MM' / 'HH:MM' → minutes since midnight, or null. */
function schedule_hm(?string $s): ?int {
    if (!is_string($s) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    return ($h <= 23 && $i <= 59) ? $h * 60 + $i : null;
}

/** Validate a schedule from the admin form and return it in its stored shape. Throws. */
function schedule_normalize($in): array {
    if (!is_array($in)) throw new Exception('Schedule is missing.');
    $type = (string)($in['type'] ?? '');
    $types = ['minutes', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'cron'];
    if (!in_array($type, $types, true)) throw new Exception('Unknown schedule type.');

    $out = ['type' => $type];
    if ($type === 'cron') {
        $expr = trim(preg_replace('/\s+/', ' ', (string)($in['cron'] ?? '')));
        schedule_cron_parse($expr); // throws with a message the form shows
        $out['cron'] = $expr;
    } else {
        $interval = max(1, (int)($in['interval'] ?? 1));
        if ($type === 'minutes' && $interval < SCHEDULE_MIN_MINUTES) {
            throw new Exception('The shortest repeat is every ' . SCHEDULE_MIN_MINUTES . ' minutes.');
        }
        $out['interval'] = $interval;

        $time = schedule_hm($in['time'] ?? null);
        if ($time === null) $time = in_array($type, ['minutes', 'hourly'], true) ? 0 : null;
        if ($time === null) throw new Exception('A time of day (HH:MM) is required.');
        $out['time'] = sprintf('%02d:%02d', intdiv($time, 60), $time % 60);

        $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])),
            fn($d) => $d >= 0 && $d <= 6)));
        sort($days);

        if ($type === 'minutes' || $type === 'hourly') {
            $until = schedule_hm($in['until'] ?? null) ?? 23 * 60 + 59;
            if ($until < $time) throw new Exception('"Until" must be later than the start time.');
            $out['until'] = sprintf('%02d:%02d', intdiv($until, 60), $until % 60);
            if ($days && count($days) < 7) $out['days'] = $days;
        } elseif ($type === 'weekly') {
            if (!$days) throw new Exception('Pick at least one day of the week.');
            $out['days'] = $days;
        } elseif ($type === 'monthly' || $type === 'yearly') {
            if (isset($in['nth']) && $in['nth'] !== '' && $in['nth'] !== null) {
                $nth = (int)$in['nth'];
                if (!in_array($nth, [1, 2, 3, 4, -1], true)) throw new Exception('Unknown week of the month.');
                $wd = $in['weekday'] ?? 'day';
                if (!in_array($wd, ['day', 'weekday'], true)) {
                    $wd = (int)$wd;
                    if ($wd < 0 || $wd > 6) throw new Exception('Unknown weekday.');
                }
                $out['nth'] = $nth;
                $out['weekday'] = $wd;
            } else {
                $out['day'] = min(31, max(1, (int)($in['day'] ?? 1)));
            }
            if ($type === 'yearly') $out['month'] = min(12, max(1, (int)($in['month'] ?? 1)));
        }
    }

    foreach (['start', 'end'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) {
            throw new Exception('Dates must be YYYY-MM-DD.');
        }
        $out[$k] = $v;
    }
    if (isset($out['start'], $out['end']) && $out['end'] < $out['start']) {
        throw new Exception('The end date is before the start date.');
    }
    return $out;
}

// -- Matching -----------------------------------------------------------------

/** A calendar day as a plain day count, so intervals ignore DST and time zones. */
function schedule_day_index(int $y, int $m, int $d): int {
    return intdiv(gmmktime(0, 0, 0, $m, $d, $y), 86400);
}

/** The day of the month a "nth weekday" rule lands on, or 0 if this month has none. */
function schedule_nth_day(int $y, int $m, int $nth, $weekday): int {
    $len = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $hits = [];
    for ($d = 1; $d <= $len; $d++) {
        $w = (int)date('w', mktime(0, 0, 0, $m, $d, $y));
        if ($weekday === 'day' || ($weekday === 'weekday' && $w >= 1 && $w <= 5) || $weekday === $w) $hits[] = $d;
    }
    if (!$hits) return 0;
    if ($nth === -1) return end($hits);
    return $hits[$nth - 1] ?? 0;
}

/** Whether a monthly/yearly day rule picks this day. */
function schedule_month_day_matches(array $s, int $y, int $m, int $d): bool {
    if (isset($s['nth'])) {
        $wd = $s['weekday'] ?? 'day';
        if (!in_array($wd, ['day', 'weekday'], true)) $wd = (int)$wd;
        return schedule_nth_day($y, $m, (int)$s['nth'], $wd) === $d;
    }
    $len = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    return $d === min(max(1, (int)($s['day'] ?? 1)), $len);
}

/**
 * The times (minutes since midnight, ascending) a schedule fires on one calendar day —
 * empty when the day is not one of its days. Everything else is built on this.
 */
function schedule_day_times(array $s, int $y, int $m, int $d, ?array $cron = null): array {
    $date = sprintf('%04d-%02d-%02d', $y, $m, $d);
    if (!empty($s['start']) && $date < $s['start']) return [];
    if (!empty($s['end'])   && $date > $s['end'])   return [];

    $type = $s['type'] ?? '';
    $dow  = (int)date('w', mktime(0, 0, 0, $m, $d, $y));

    if ($type === 'cron') {
        $c = $cron ?? schedule_cron_parse((string)($s['cron'] ?? ''));
        if (empty($c['mon'][$m])) return [];
        $dom_ok = !empty($c['dom'][$d]);
        $dow_ok = !empty($c['dow'][$dow]);
        if ($c['dom_any'] || $c['dow_any'] ? !($dom_ok && $dow_ok) : !($dom_ok || $dow_ok)) return [];
        $times = [];
        foreach (array_keys($c['hour']) as $h) foreach (array_keys($c['min']) as $i) $times[] = $h * 60 + $i;
        sort($times);
        return $times;
    }

    $interval = max(1, (int)($s['interval'] ?? 1));
    $time     = schedule_hm($s['time'] ?? null) ?? 0;
    // "Every N" counts from the start date; without one, from a fixed epoch.
    [$sy, $sm, $sd] = !empty($s['start']) ? array_map('intval', explode('-', $s['start'])) : [1970, 1, 1];
    $di = schedule_day_index($y, $m, $d);
    $si = schedule_day_index($sy, $sm, $sd);

    switch ($type) {
        case 'minutes':
        case 'hourly':
            if (!empty($s['days']) && !in_array($dow, array_map('intval', $s['days']), true)) return [];
            $step  = $type === 'hourly' ? $interval * 60 : max(SCHEDULE_MIN_MINUTES, $interval);
            $until = schedule_hm($s['until'] ?? null) ?? 23 * 60 + 59;
            return $until >= $time ? range($time, $until, $step) : [];
        case 'daily':
            if (($di - $si) % $interval !== 0) return [];
            break;
        case 'weekly':
            if (!in_array($dow, array_map('intval', $s['days'] ?? []), true)) return [];
            // 1970-01-01 was a Thursday; +3 makes Monday the first day of a week.
            if ((intdiv($di + 3, 7) - intdiv($si + 3, 7)) % $interval !== 0) return [];
            break;
        case 'monthly':
            if ((($y * 12 + $m) - ($sy * 12 + $sm)) % $interval !== 0) return [];
            if (!schedule_month_day_matches($s, $y, $m, $d)) return [];
            break;
        case 'yearly':
            if (($y - $sy) % $interval !== 0 || $m !== (int)($s['month'] ?? 1)) return [];
            if (!schedule_month_day_matches($s, $y, $m, $d)) return [];
            break;
        default:
            return [];
    }
    return [$time];
}

/** A cron schedule parsed once for a scan, or null; a broken expression never fires. */
function schedule_cron_for(array $s): ?array {
    if (($s['type'] ?? '') !== 'cron') return null;
    try { return schedule_cron_parse((string)($s['cron'] ?? '')); }
    catch (Exception $e) { return ['min' => [], 'hour' => [], 'dom' => [], 'mon' => [], 'dow' => [], 'dom_any' => true, 'dow_any' => true]; }
}

/**
 * The occurrence that makes a job due right now, or null. It is the latest occurrence at or
 * before $now, provided it is later than $floor (the last run, or when the schedule was set)
 * and no older than the catch-up window — a runner that was down catches up once, not once
 * per missed occurrence, and a job re-enabled after a month does not fire for last month.
 */
function schedule_due_at(array $s, int $now, int $floor): ?int {
    $cron = schedule_cron_for($s);
    $oldest = max($floor + 1, $now - SCHEDULE_CATCHUP_SECONDS);
    for ($back = 0; $back <= 1; $back++) {
        $day = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now) - $back, (int)date('Y', $now));
        [$y, $m, $d] = array_map('intval', explode('-', date('Y-n-j', $day)));
        $times = schedule_day_times($s, $y, $m, $d, $cron);
        for ($i = count($times) - 1; $i >= 0; $i--) {
            $ts = mktime(intdiv($times[$i], 60), $times[$i] % 60, 0, $m, $d, $y);
            if ($ts > $now) continue;
            return $ts >= $oldest ? $ts : null;
        }
    }
    return null;
}

/** The next $count occurrences strictly after $after, ascending. */
function schedule_next(array $s, int $after, int $count = 1): array {
    $cron = schedule_cron_for($s);
    $out = [];
    for ($k = 0; $k <= SCHEDULE_LOOKAHEAD_DAYS && count($out) < $count; $k++) {
        $day = mktime(0, 0, 0, (int)date('n', $after), (int)date('j', $after) + $k, (int)date('Y', $after));
        [$y, $m, $d] = array_map('intval', explode('-', date('Y-n-j', $day)));
        if (!empty($s['end']) && sprintf('%04d-%02d-%02d', $y, $m, $d) > $s['end']) break;
        foreach (schedule_day_times($s, $y, $m, $d, $cron) as $t) {
            $ts = mktime(intdiv($t, 60), $t % 60, 0, $m, $d, $y);
            if ($ts <= $after) continue;
            $out[] = $ts;
            if (count($out) >= $count) break;
        }
    }
    return $out;
}

/**
 * Everything a job's due-check needs that is not its schedule: the floor below which an
 * occurrence has already been dealt with. `since` is stamped when a job is created, its
 * schedule changes or it is switched back on, so none of those fires for a time already
 * past. A job from before `since` existed, never run, counts from the start of today —
 * which is what the old check did.
 */
function schedule_job_floor(array $job, int $now): int {
    $floor = !empty($job['last_run']) ? (int)strtotime($job['last_run']) : 0;
    if (!empty($job['since'])) $floor = max($floor, (int)strtotime($job['since']));
    if ($floor === 0) $floor = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now)) - 1;
    return $floor;
}

function schedule_job_is_due(array $job, int $now): bool {
    $s = $job['schedule'] ?? null;
    if (!is_array($s) || empty($s['type'])) return false;
    return schedule_due_at($s, $now, schedule_job_floor($job, $now)) !== null;
}

/** When a job will next run, or null if its schedule has nothing left. */
function schedule_job_next_run(array $job, int $now): ?int {
    $s = $job['schedule'] ?? null;
    if (!is_array($s) || empty($s['type'])) return null;
    // An occurrence that is due but not yet picked up is the next run.
    $due = schedule_due_at($s, $now, schedule_job_floor($job, $now));
    if ($due !== null) return $due;
    return schedule_next($s, $now)[0] ?? null;
}
