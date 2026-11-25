<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * AI-to-AI hand-offs in a chat thread.
 *
 * An AI user's reply that mentions another AI user as #Name makes that AI answer next, the
 * way a person's message does. Only a *reply* hands off — the answer that lands in the
 * thread, inline or from a background job — not text an AI writes into a chat with a tool,
 * which is a side effect rather than its turn in the conversation.
 *
 * The rules, each because the alternative loops or surprises:
 *   - **A hop limit.** The reply that hands off carries a `handoff` record; the answer it
 *     starts is one hop further. Past WIKI_AI_HANDOFF_MAX (ai_core.php) nothing is handed
 *     on, so two AI users that mention each other stop after a few turns instead of never.
 *   - **Never to itself**, and **never from an error** — a "⚠️ …" reply is the wiki
 *     reporting a failure, not the AI asking for help.
 *   - **Mentions in code are not hand-offs.** An AI explaining the syntax writes `#Name`.
 *   - **The person who started the chain** stays its requester (`handoff.origin`): a queued
 *     hand-off counts against their per-user queue cap and notifies them, as their own
 *     /aiJob would. It is never the AI — an AI has no queue cap of its own to spend.
 *   - **A switched-off AI** gets the same notice in the thread a person's mention gets.
 *
 * Where the next answer runs: from an inline reply, inline as well (trigger_ai_response in
 * api.php, in the request that already detached for the first one) unless that AI always
 * works in the background; from a background job's reply, always as a queued job, since the
 * cron runner has no inline path.
 */

require_once __DIR__ . '/ai_core.php';      // WIKI_AI_HANDOFF_MAX, the transcript helpers
require_once __DIR__ . '/agent_jobs.php';   // the queue
require_once __DIR__ . '/mentions.php';     // wiki_match_ai_mention()
require_once __DIR__ . '/realtime.php';     // wiki_chat_write()

function _wiki_handoff_users(): array {
    $f = WIKI_SYSTEM_DATA . 'users.json';
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d['users'] ?? null) ? $d['users'] : [];
}

/** Read-modify-write a chat under the lock job delivery takes, so the two cannot interleave. */
function _wiki_handoff_chat(string $chat_file, callable $fn) {
    $lock = @fopen($chat_file . '.joblock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $data = json_decode((string)@file_get_contents($chat_file), true);
        if (!is_array($data['messages'] ?? null)) return null;
        return $fn($data);
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        @unlink($chat_file . '.joblock');   // as agent_job_deliver_to_chat() does
    }
}

/** The reply text with code taken out: a #Name shown as code is an example, not a hand-off. */
function wiki_handoff_text(string $text): string {
    $text = preg_replace('/^(```|~~~)[^\n]*\n.*?^\1[ \t]*$/ms', ' ', $text);
    return preg_replace('/`[^`\n]*`/', ' ', $text);
}

/**
 * Message $reply_id in $chat_file is $from_ai's finished reply: if it hands off, add the next
 * AI's pending placeholder and say who and where. Null when it does not — no mention, the
 * limit reached, an error reply, or a switched-off AI (which gets its notice instead).
 *
 * @param array|null $origin  who started the chain, when the caller knows (a job's requester);
 *                            otherwise the reply's own record, else the last person who spoke.
 * @return array|null ['target', 'placeholder_id', 'hop', 'origin', 'from', 'reply', 'data']
 */
function wiki_ai_handoff_prepare(string $chat_file, int $reply_id, array $from_ai, ?array $origin = null): ?array {
    $users = _wiki_handoff_users();
    $ai_uids = [];
    foreach ($users as $u) if (!empty($u['is_ai'])) $ai_uids[(int)($u['uid'] ?? 0)] = true;
    $from_uid = (int)($from_ai['uid'] ?? 0);

    return _wiki_handoff_chat($chat_file, function (array $data) use ($chat_file, $reply_id, $from_ai, $from_uid, $origin, $users, $ai_uids) {
        $reply = null;
        $last_person = null;
        foreach ($data['messages'] as $m) {
            if ((int)($m['id'] ?? -1) === $reply_id) { $reply = $m; break; }
            $uid = (int)($m['uid'] ?? 0);
            if (empty($m['pending']) && empty($m['is_debug']) && empty($m['notice']) && !isset($ai_uids[$uid])) {
                $last_person = ['uid' => $uid, 'name' => (string)($m['name'] ?? '')];
            }
        }
        if ($reply === null || !empty($reply['pending']) || !empty($reply['is_debug'])) return null;
        $text = (string)($reply['text'] ?? '');
        if (trim($text) === '' || strpos(ltrim($text), '⚠️') === 0) return null;
        $hop = (int)($reply['handoff']['hop'] ?? 0);
        if ($hop >= WIKI_AI_HANDOFF_MAX) return null;

        // Never itself: the resolver is given everyone else.
        $others = array_values(array_filter($users, fn($u) => (int)($u['uid'] ?? 0) !== $from_uid));
        $target = wiki_match_ai_mention(wiki_handoff_text($text), $others);
        if ($target === null) return null;

        $origin = $reply['handoff']['origin'] ?? $origin ?? $last_person ?? ['uid' => 0, 'name' => ''];
        $next_id = (int)($data['nextMessageId'] ?? 1);
        if (wiki_ai_disabled($target)) {
            // As for a person's mention (post_chat_message): the thread says why nobody answers.
            $data['messages'][] = ['id' => $next_id, 'uid' => (int)($target['uid'] ?? 0), 'name' => (string)$target['name'],
                'timestamp' => date('c'), 'text' => 'AI User ' . $target['name'] . ' is currently disabled.', 'notice' => 'ai_disabled'];
            $data['nextMessageId'] = $next_id + 1;
            wiki_chat_write($chat_file, $data);
            return null;
        }
        $from = ['uid' => $from_uid, 'name' => (string)($from_ai['name'] ?? 'AI')];
        $data['messages'][] = [
            'id' => $next_id, 'uid' => (int)($target['uid'] ?? 0), 'name' => (string)$target['name'],
            'timestamp' => date('c'), 'text' => '', 'pending' => true,
            'handoff' => ['hop' => $hop + 1, 'from' => $from, 'origin' => $origin],
        ];
        $data['nextMessageId'] = $next_id + 1;
        wiki_chat_write($chat_file, $data);
        return ['target' => $target, 'placeholder_id' => $next_id, 'hop' => $hop + 1, 'origin' => $origin,
                'from' => $from, 'reply' => $text, 'data' => $data];
    });
}

/**
 * Queue the hand-off as a one-off job, the way an always-in-the-background AI's chat reply
 * is queued: the thread so far as the prompt, the handing-off reply quoted as the latest
 * message, the placeholder marked with the job's id. The chain's starter is the requester,
 * and their queue cap applies — over it, the placeholder says so rather than waiting.
 */
function wiki_ai_handoff_queue(array $plan, string $chat_file, string $space_dir): bool {
    $target = $plan['target'];
    $origin = $plan['origin'];
    $mine = 0;
    foreach (agent_job_queue_read() as $q) {
        if (($q['state'] ?? '') === 'queued' && (int)($q['requested_by']['uid'] ?? -1) === (int)($origin['uid'] ?? 0)) $mine++;
    }
    $root  = rtrim(PAGES_DIR, '/');
    $sdir  = rtrim($space_dir, '/');
    $space = trim(substr($sdir, strlen($root)), '/');
    $rel   = ltrim(substr($chat_file, strlen($sdir)), '/');
    $job_id = 'oneoff_' . date('Ymd-His') . '_' . bin2hex(random_bytes(3));
    $over = $mine >= AGENT_JOB_MAX_QUEUED_PER_USER;

    _wiki_handoff_chat($chat_file, function (array $data) use ($chat_file, $plan, $job_id, $over) {
        foreach ($data['messages'] as &$m) {
            if ((int)($m['id'] ?? -1) !== $plan['placeholder_id']) continue;
            if ($over) {
                unset($m['pending']);
                $m['text'] = 'There are already ' . AGENT_JOB_MAX_QUEUED_PER_USER
                    . ' background jobs waiting for this conversation\'s requester, so this hand-off was not queued.';
            } else {
                $m['job_id'] = $job_id;
            }
            break;
        }
        unset($m);
        wiki_chat_write($chat_file, $data);
    });
    if ($over) return false;

    $lines = [];
    $ctx = wiki_chat_context_slice($plan['data']['messages'], (int)($target['ai_config']['context_messages'] ?? 10));
    array_pop($ctx);   // the handing-off reply, quoted on its own below
    $now = time();
    foreach ($ctx as $m) $lines[] = wiki_chat_context_line($m, $now);
    $prompt = ($lines ? "Earlier in this chat thread:\n" . implode("\n", $lines) . "\n\n" : '')
            . 'Latest message from ' . $plan['from']['name'] . ': ' . $plan['reply'];

    agent_job_queue_mutate(function (array &$jobs) use ($job_id, $origin, $target, $space, $prompt, $rel, $plan, $chat_file, $space_dir) {
        $jobs[] = [
            'id'           => $job_id,
            'state'        => 'queued',
            'created_at'   => date('c'),
            'requested_by' => ['uid' => (int)($origin['uid'] ?? 0), 'name' => (string)($origin['name'] ?? '')],
            'ai_user_uid'  => (int)($target['uid'] ?? 0),
            'ai_user_name' => (string)($target['name'] ?? 'AI'),
            'space'        => $space,
            'prompt'       => $prompt,
            'skill'        => null,
            'page_context' => wiki_page_context_prompt($chat_file, $space_dir),
            'reply_to'     => ['chat' => $rel, 'message_id' => $plan['placeholder_id']],
            'handoff'      => ['hop' => $plan['hop'], 'from' => $plan['from']],
            'thinking'     => wiki_ai_thinking_config($target['ai_config'] ?? []),
            'started_at'   => null, 'finished_at' => null, 'log_file' => null, 'error' => null,
        ];
    });
    return true;
}

/** The AI user behind a uid, or null. */
function wiki_handoff_ai_user(int $uid): ?array {
    foreach (_wiki_handoff_users() as $u) {
        if (!empty($u['is_ai']) && (int)($u['uid'] ?? 0) === $uid) return $u;
    }
    return null;
}
