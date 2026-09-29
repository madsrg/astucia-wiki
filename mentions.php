<?php
require_once __DIR__ . '/frontmatter.php';
// Outright, not behind function_exists: that guard is what made frontmatter_expose_ai
// answer "off" in mcp.php and the cron runner while reading "on" in a web request.
require_once __DIR__ . '/settings.php';
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// MENTIONS — where a user was named, and what is new since they last looked.
//
// One scanner serves both callers: the My Mentions list and the sidebar badge. It
// walks each space's index.json rather than the filesystem, because the index already
// carries an `updated` stamp per page — so the badge, which runs on every page load,
// can skip every file that has not changed since the user last looked instead of
// opening the whole wiki. The list, which is a deliberate click, reads everything.
//
// Both sigils count. `@Name` is what the composer writes for a person now; `#Name` is
// what it wrote before the two were split, and every chat and comment from before then
// still uses it (see "Mention sigils" in CLAUDE.md).
// =================================================================

// Pages and chats carry mentions. A .list or .drawio cannot.
const MENTION_EXTS = ['md', 'chat'];

// How far back the list and the badge look. There is no stored mention record — a
// mention is found by reading content — so "expire" can only mean a cutoff on the scan,
// and that is what this is: a mention older than the cutoff stops being listed, while
// the text that contains it is untouched and still turns up in search.
//
// It bounds two things at once. The obvious one is noise: on an established wiki
// `get_mentions` returned every mention that had ever been written, newest first, for
// ever. The other is cost — the list reads every page and chat in every space the user
// can read, and the cutoff is applied to the *index entry* before a file is opened, so
// it is the same pre-filter `$only_new` already uses and it skips the same work.
//
// 0 means no limit, which is what every wiki did before this existed.
const WIKI_MENTION_DAYS_DEFAULT = 90;

/** The cutoff as a unix timestamp, or 0 for "no limit". */
function wiki_mention_cutoff(): int {
    $days = (int)wiki_setting('mention_retention_days', WIKI_MENTION_DAYS_DEFAULT);
    if ($days <= 0) return 0;
    return time() - ($days * 86400);
}

// Same rule as wiki_is_template_path(), spelled out here rather than depended on: this
// file is also loaded by the digest cron, which has no reason to pull in the whole AI
// tool set, and a missing dependency would have silently started matching templates.
//
// memory/ is skipped for a sharper reason than tidiness: an AI that remembers "Alice
// prefers short summaries" would otherwise notify Alice — again on every edit of that
// memory, and again in her daily digest.
function _mention_is_template(string $path): bool {
    $rel = ltrim($path, '/');
    return str_starts_with($rel, 'templates/') || str_starts_with($rel, 'memory/');
}

/** Every space this actor may read, as names. null $allowed means unrestricted. */
function wiki_mention_spaces(?array $allowed): array {
    $out = [];
    foreach (scandir(PAGES_DIR) as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        if (!is_dir(rtrim(PAGES_DIR, '/') . '/' . $f)) continue;
        if ($allowed !== null && !in_array($f, $allowed, true)) continue;
        $out[] = $f;
    }
    sort($out);
    return $out;
}

/**
 * The trailing boundary of a `[@#]Name` mention.
 *
 * `\b` is wrong here, and the way it is wrong is expensive: it sees a word boundary
 * between "0" and "-", so "#gpt120-think" also matched an AI user named "gpt120". The
 * detection loop takes the first match in users.json order, so the wrong AI answered —
 * inline, ignoring the intended one's "always run in the background", which is what put
 * a job-less placeholder in the thread reading "Working…". The client never had the bug:
 * it extracts the whole token with /[#@]([\w.-]*\w)/, so the two disagreed about who
 * was being addressed while both looked correct on their own.
 *
 * A name token may contain "." and "-" only between word characters (the same grammar
 * the client uses), so the boundary is "not a word character, and not a . or - that
 * continues into one". That still lets a mention end a sentence: "@Alice." matches Alice,
 * while "@Alice-Smith" and "@Alice2" do not.
 */
const WIKI_MENTION_END = '(?!\\w)(?![.-]\\w)';

/**
 * Which AI user a message addresses, or null if none.
 *
 * Longest name first, so a name that is a prefix of another cannot claim the mention
 * even where the boundary alone would allow it (names may contain spaces, which the
 * boundary cannot see past). One resolver, because api.php decides *twice* whether a
 * message triggers an AI — once to write the placeholder and once to route it — and the
 * two disagreeing is the failure this replaces.
 */
function wiki_match_ai_mention(string $text, array $users): ?array {
    $ais = array_values(array_filter($users,
        fn($u) => !empty($u['is_ai']) && trim((string)($u['name'] ?? '')) !== ''));
    usort($ais, fn($a, $b) => mb_strlen((string)$b['name']) <=> mb_strlen((string)$a['name']));
    foreach ($ais as $u) {
        if (preg_match('/(^|[\s,])[@#]' . preg_quote((string)$u['name'], '/') . WIKI_MENTION_END . '/iu', $text)) {
            return $u;
        }
    }
    return null;
}

/**
 * Scan for mentions of one user.
 *
 * @param int  $since     Unix time the user last looked; 0 disables the new/old split.
 * @param bool $only_new  Skip anything unchanged since $since without opening it.
 *                        The cheap path, for the badge.
 * @return array Rows shaped like search results, newest first.
 */
/**
 * @param int $cutoff Unix time before which a mention is not reported; 0 for no limit,
 *                    -1 (the default) to use the wiki's own setting. The daily digest
 *                    passes its own 24-hour line and wants nothing else applied on top.
 */
function wiki_scan_mentions(string $name, int $uid, ?array $allowed_spaces,
                            int $since = 0, bool $only_new = false, int $cutoff = -1): array {
    if ($name === '' && $uid <= 0) return [];
    if ($cutoff < 0) $cutoff = wiki_mention_cutoff();
    $rows = [];
    $name_re = $name !== '' ? '/[@#]' . preg_quote($name, '/') . WIKI_MENTION_END . '/i' : null;

    foreach (wiki_mention_spaces($allowed_spaces) as $space) {
        $dir   = rtrim(PAGES_DIR, '/') . '/' . $space;
        $index = is_file($dir . '/index.json')
            ? (json_decode((string)@file_get_contents($dir . '/index.json'), true) ?: []) : [];

        foreach ($index as $id => $data) {
            $path = $data['path'] ?? '';
            if ($path === '') continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, MENTION_EXTS, true)) continue;
            if (_mention_is_template($path)) continue;

            $updated = (int)($data['updated'] ?? 0);
            // The whole point of the index pre-filter: an untouched file cannot hold a
            // mention the user has not already seen, so it is never opened.
            if ($only_new && $since > 0 && $updated <= $since) continue;
            // The same pre-filter for the age cutoff, and it is valid for a chat as well
            // as a page: a thread's index stamp is its last write, so a file untouched
            // since the cutoff cannot contain a *message* newer than it either.
            if ($cutoff > 0 && $updated < $cutoff) continue;

            $abs = $dir . '/' . ltrim($path, '/');
            if (!is_file($abs)) continue;
            $raw = (string)@file_get_contents($abs);
            // An `@name` inside front matter is metadata, not somebody addressing a person.
            if ($ext === 'md') $raw = wiki_fm_body($raw);

            $hit = $ext === 'chat'
                ? _mention_hit_chat($raw, $name_re, $since)
                : _mention_hit_page($raw, $name_re, $uid, $name, $since, $updated);
            if ($hit === null) continue;
            if ($only_new && !$hit['is_new']) continue;
            // And again per row, because a chat is judged per message: a thread written
            // to yesterday can have named you two years ago, and the pre-filter above
            // cannot see that. A page has one stamp, so for it this is the same test.
            if ($cutoff > 0 && (int)($hit['at'] ?: $updated) < $cutoff) continue;

            $rows[] = [
                'id'        => (string)$id,
                'space'     => $space,
                'path'      => $path,
                'header'    => $hit['header'] !== '' ? $hit['header'] : basename($path, '.' . $ext),
                'preview'   => $hit['preview'],
                'is_new'    => $hit['is_new'],
                'created'   => $data['created']   ?? null,
                'updated'   => $hit['at'] ?: $updated,
                'createdBy' => $data['createdBy'] ?? null,
                'updatedBy' => $data['updatedBy'] ?? null,
                'tags'      => $data['tags']      ?? [],
            ];
        }
    }
    usort($rows, fn($a, $b) => (int)$b['updated'] <=> (int)$a['updated']);
    return $rows;
}

/** A Markdown page: the name in the text, or the uid in a comment tag's notify list. */
function _mention_hit_page(string $raw, ?string $name_re, int $uid, string $name,
                           int $since, int $updated): ?array {
    $by_name = $name_re !== null && (bool)preg_match($name_re, $raw);
    $by_uid  = $uid > 0 && (bool)preg_match(
        '/\{user_comment:\d+:[A-Za-z0-9+\/=]*:(?:\d+,)*' . preg_quote((string)$uid, '/') . '(?:,\d+)*\}/', $raw);
    if (!$by_name && !$by_uid) return null;

    $header = '';
    foreach (explode("\n", $raw) as $line) {
        $t = trim($line);
        if ($t !== '' && $t[0] === '#' && substr($t, 0, 2) !== '#{') { $header = $t; break; }
    }

    $preview = '';
    if ($by_name) {
        $pos = false;
        foreach (['@', '#'] as $sigil) {
            $pos = stripos($raw, $sigil . $name);
            if ($pos !== false) break;
        }
        if ($pos !== false) {
            $snippet = htmlspecialchars(substr($raw, max(0, $pos - 40), strlen($name) + 90));
            $preview = '...' . preg_replace('/([@#]' . preg_quote($name, '/') . ')/i',
                                            '<mark>$1</mark>', $snippet) . '...';
        }
    } elseif (preg_match('/\{user_comment:\d+:([A-Za-z0-9+\/=]*):(?:\d+,)*' . preg_quote((string)$uid, '/') . '/', $raw, $m)) {
        $decoded = base64_decode($m[1]);
        if ($decoded !== false) $preview = htmlspecialchars(mb_substr($decoded, 0, 120));
    }

    // A page has one timestamp for the whole file, so "new" here means the page changed
    // since the user last looked — not necessarily that the mention itself is new. A
    // chat can do better, because each message carries its own time.
    return ['header' => $header, 'preview' => $preview,
            'is_new' => $since > 0 && $updated > $since, 'at' => 0];
}

/** A chat thread: match per message, so an old mention in a busy thread stays old. */
function _mention_hit_chat(string $raw, ?string $name_re, int $since): ?array {
    if ($name_re === null) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['messages']) || !is_array($data['messages'])) return null;

    $latest = null;
    $is_new = false;
    foreach ($data['messages'] as $msg) {
        $text = (string)($msg['text'] ?? '');
        if ($text === '' || !preg_match($name_re, $text)) continue;
        $at = isset($msg['timestamp']) ? (int)strtotime((string)$msg['timestamp']) : 0;
        if ($latest === null || $at >= $latest['at']) {
            $latest = ['at' => $at, 'text' => $text, 'name' => (string)($msg['name'] ?? '')];
        }
        if ($since > 0 && $at > $since) $is_new = true;
    }
    if ($latest === null) return null;

    $body = htmlspecialchars(mb_substr(trim(preg_replace('/\s+/', ' ', $latest['text'])), 0, 140));
    return [
        'header'  => trim((string)($data['topic'] ?? '')),
        'preview' => ($latest['name'] !== '' ? '<strong>' . htmlspecialchars($latest['name']) . ':</strong> ' : '') . $body,
        'is_new'  => $is_new,
        'at'      => $latest['at'],
    ];
}

// --- Announcing a mention as it is written ------------------------------------
//
// The badge finds a mention by *scanning* — which is why it is polled, and why a tab
// with no timer running (a backgrounded one) learns nothing. The scan is unavoidable
// for finding an old mention, but it is not how the wiki should find out about one it
// is in the middle of writing: at that moment the text is in hand and the answer costs
// one regex per user.
//
// So the two paths a person can mention somebody through — posting a chat message and
// saving a page — publish `wiki/user/<uid>/mention` themselves, and the badge's timer
// becomes the safety net it is everywhere else rather than the only mechanism.
// `wiki_mention_users` (an AI naming somebody) has published this topic since the topic
// existed; this is the same event from the two human paths.

/**
 * Which people a piece of text addresses.
 *
 * Both sigils, and the shared `WIKI_MENTION_END` boundary — the same pattern
 * wiki_scan_mentions() builds per user, so "who does this mention" has one answer
 * rather than a second one that agrees until it doesn't. AI users are skipped: `#Ai`
 * routes a request to a model, and there is nobody to notify.
 *
 * Reads users.json directly rather than through wiki_all_users(), which lives in
 * wiki_ai_tools.php — this file is also loaded by the digest cron and by api.php's
 * bootstrap, neither of which has any reason to pull in the AI tool set.
 *
 * @return int[] uids, deduplicated.
 */
function wiki_mention_uids_in(string $text): array {
    if (trim($text) === '') return [];
    $f = wiki_users_file();
    if (!$f) return [];
    $uids = [];
    foreach ((json_decode((string)file_get_contents($f), true)['users'] ?? []) as $u) {
        if (!empty($u['is_ai'])) continue;
        $n = (string)($u['name'] ?? '');
        $uid = (int)($u['uid'] ?? 0);
        if ($n === '' || $uid <= 0) continue;
        if (preg_match('/[@#]' . preg_quote($n, '/') . WIKI_MENTION_END . '/i', $text)) {
            $uids[$uid] = true;
        }
    }
    return array_keys($uids);
}

/**
 * Tell the people this write newly mentions.
 *
 * `$before` is what the text said a moment ago, so re-saving a page that has named
 * Alice since March does not notify her again on every subsequent edit. A chat message
 * passes '' — a new message is new in its entirety.
 *
 * `$exclude_uid` is the author: writing your own name is not a notification, and an AI
 * user quoting a mention back into the thread is not either.
 *
 * Fire-and-forget like every other publish (see wiki_realtime_publish): a save must not
 * fail because a hint could not be delivered, and the badge's poll still finds it.
 */
function wiki_mention_announce(string $after, string $before = '', int $exclude_uid = 0): void {
    if (!function_exists('wiki_realtime_publish')) return;
    $fresh = array_diff(wiki_mention_uids_in($after), wiki_mention_uids_in($before));
    foreach ($fresh as $uid) {
        if ($uid === $exclude_uid) continue;
        wiki_realtime_publish(wiki_rt_topic_mention($uid), ['type' => 'mention']);
    }
}

// --- Per-user state -----------------------------------------------------------
// Two stamps live on the user record: `lastLogin`, written by auth.php, and
// `mentionsSeenAt`, written when the user opens the panel. The first seeds the second
// so a user who has never opened it is not shown every mention the wiki ever had.

function wiki_users_file(): ?string {
    if (!defined('WIKI_SYSTEM_DATA')) return null;
    $f = rtrim(WIKI_SYSTEM_DATA, '/') . '/users.json';
    return is_file($f) ? $f : null;
}

function wiki_user_record(int $uid): ?array {
    $f = wiki_users_file();
    if (!$f || $uid <= 0) return null;
    foreach ((json_decode((string)file_get_contents($f), true)['users'] ?? []) as $u) {
        if ((int)($u['uid'] ?? -1) === $uid) return $u;
    }
    return null;
}

function wiki_user_set_stamp(int $uid, string $key, int $ts): bool {
    $f = wiki_users_file();
    if (!$f || $uid <= 0) return false;
    $data  = json_decode((string)file_get_contents($f), true) ?? ['users' => []];
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    $found = false;
    foreach ($users as &$u) {
        if ((int)($u['uid'] ?? -1) !== $uid) continue;
        $u[$key] = $ts;
        $found = true;
        break;
    }
    unset($u);
    if (!$found) return false;
    $data['users'] = $users;
    return @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

/** The line between seen and new: when the panel was last opened, else last login. */
function wiki_mentions_since(int $uid): int {
    $u = wiki_user_record($uid);
    if (!$u) return 0;
    return (int)($u['mentionsSeenAt'] ?? $u['lastLogin'] ?? 0);
}
