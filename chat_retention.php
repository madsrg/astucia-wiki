<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// CHAT AUTO-PURGE — how long a thread keeps its messages
//
// A policy is one string, because a thread has one retention rule and two half-configured
// controls are a way to get a thread you did not mean to trim:
//
//   ''          off (the default, and what every existing thread has)
//   'count:100' keep the newest 100 messages
//   'days:30'   keep messages from the last 30 days
//
// Per thread it lives in the `.chat` file itself, beside `topic` and `git_commit`, so it
// travels with the file through rsync, git and a Space merge. Wiki-wide it lives in
// settings.json as the default for threads that have not chosen.
//
// Applied when a thread is written to, not on a timer: the file is already open and being
// written at that moment, so it costs nothing. The consequence, stated plainly in the UI,
// is that a thread nobody posts to is never trimmed — which is the case where it matters
// least, and the alternative is scanning every .chat in every Space on a schedule.
// =================================================================

require_once __DIR__ . '/settings.php';

const WIKI_CHAT_POLICY_KEY = 'chat_retention_default';

// What the UI offers. Anything else is refused rather than silently stored, so a typo in a
// request cannot install a policy nobody can see or explain.
const WIKI_CHAT_POLICIES = ['', 'count:100', 'count:200', 'count:300', 'days:30', 'days:90', 'days:180'];

function wiki_chat_policy_valid($policy): bool {
    return is_string($policy) && in_array($policy, WIKI_CHAT_POLICIES, true);
}

/** The wiki-wide default, '' when an administrator has not set one. */
function wiki_chat_policy_default(): string {
    $v = wiki_setting(WIKI_CHAT_POLICY_KEY, '');
    return wiki_chat_policy_valid($v) ? $v : '';
}

/**
 * The policy in force for one thread.
 *
 * A thread's own `retention` wins, including when it is '' — that is the thread saying
 * "off", not "unset", so a wiki-wide default can never quietly re-enable trimming on a
 * thread an admin deliberately exempted. Only a thread that has never been configured
 * inherits.
 */
function wiki_chat_policy_for(array $chat_data): string {
    if (array_key_exists('retention', $chat_data) && wiki_chat_policy_valid($chat_data['retention'])) {
        return $chat_data['retention'];
    }
    return wiki_chat_policy_default();
}

/**
 * Split a thread's messages into those a policy keeps and those it removes.
 *
 * Three kinds are never removed, whatever the policy says:
 *   - `sticky`  — somebody pinned it on purpose.
 *   - `pending` with a `job_id` — a queued job writes its answer back into that
 *     placeholder later. Purge it and the runner writes into a message that is gone.
 *   - anything newer than the cutoff, obviously.
 * `is_debug` transcripts are purged like any other message; they are noise by design.
 *
 * Returns [kept, removed]. Order is preserved; a kept message never changes position.
 */
function wiki_chat_apply_policy(array $messages, string $policy): array {
    if ($policy === '' || !$messages) return [$messages, []];

    $protected = static function (array $m): bool {
        if (!empty($m['sticky'])) return true;
        if (!empty($m['pending']) && !empty($m['job_id'])) return true;
        return false;
    };

    $doomed = [];   // message index => true

    if (str_starts_with($policy, 'count:')) {
        $keep = (int)substr($policy, 6);
        if ($keep <= 0) return [$messages, []];
        // Count from the end over the messages the policy is allowed to touch: a pinned
        // message is kept regardless, so counting it against the budget would silently
        // shrink how much conversation a thread retains.
        $budget = $keep;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($protected($messages[$i])) continue;
            if ($budget > 0) { $budget--; continue; }
            $doomed[$i] = true;
        }
    } elseif (str_starts_with($policy, 'days:')) {
        $days = (int)substr($policy, 5);
        if ($days <= 0) return [$messages, []];
        $cutoff = time() - ($days * 86400);
        foreach ($messages as $i => $m) {
            if ($protected($m)) continue;
            // A message with no usable timestamp is kept: age cannot be established, and
            // deleting on a guess is the wrong way to be wrong.
            $ts = isset($m['ts']) ? strtotime((string)$m['ts']) : false;
            if ($ts !== false && $ts < $cutoff) $doomed[$i] = true;
        }
    } else {
        return [$messages, []];
    }

    $kept = $removed = [];
    foreach ($messages as $i => $m) {
        if (isset($doomed[$i])) $removed[] = $m; else $kept[] = $m;
    }
    return [array_values($kept), array_values($removed)];
}

/**
 * Apply the thread's policy in place. Returns how many messages were removed.
 *
 * The caller is about to write $chat_data anyway, so this never touches the disk itself —
 * one write, and no chance of a purge landing without the change that triggered it.
 */
function wiki_chat_autopurge(array &$chat_data): int {
    $policy = wiki_chat_policy_for($chat_data);
    if ($policy === '') return 0;
    [$kept, $removed] = wiki_chat_apply_policy($chat_data['messages'] ?? [], $policy);
    if (!$removed) return 0;
    $chat_data['messages'] = $kept;
    return count($removed);
}
