<?php
// =================================================================
// ASTUCIA WIKI — DAILY DIGEST RUNNER
// Emails each subscribed user a once-a-day summary of pages created or
// updated in the last 24 hours, across every Space they can access.
//
// Add to crontab to run once a day (e.g. 07:00 server time):
//   0 7 * * * php /path/to/run_daily_digest.php >> /var/log/wiki-digest.log 2>&1
//
// A user opts in with "Subscribe to daily updates" in My Preferences
// (stored as dailyDigest in users.json). Their own edits are excluded, and
// the list is capped at 20 most-recent changes with an "and N more" note.
// =================================================================
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/mailer.php';

const DIGEST_WINDOW = 86400; // 24 hours
const DIGEST_MAX    = 20;

if (!is_mail_configured()) {
    fwrite(STDERR, "Daily digest: email is not configured — aborting.\n");
    exit(1);
}

$now    = time();
$cutoff = $now - DIGEST_WINDOW;

// Absolute base URL for page links (no web context in cron). Prefer an explicit
// APP_BASE_URL; otherwise derive the origin from the OIDC redirect URI.
$base_url = '';
if (defined('APP_BASE_URL') && APP_BASE_URL) {
    $base_url = rtrim(APP_BASE_URL, '/');
} elseif (defined('OIDC_REDIRECT_URI') && OIDC_REDIRECT_URI) {
    $base_url = rtrim(dirname(OIDC_REDIRECT_URI), '/');
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Human-friendly relative time.
function digest_ago(int $ts, int $now): string {
    $d = max(0, $now - $ts);
    if ($d < 3600)  return max(1, (int)round($d / 60)) . 'm ago';
    if ($d < 86400) return (int)round($d / 3600) . 'h ago';
    return (int)round($d / 86400) . 'd ago';
}

// --- Enumerate spaces (top-level dirs under PAGES_DIR) -----------------------
$pages_root = rtrim(PAGES_DIR, '/');
$spaces = [];
foreach (scandir($pages_root) ?: [] as $d) {
    if ($d === '' || $d[0] === '.') continue;
    if (is_dir("$pages_root/$d")) $spaces[] = $d;
}

// --- Scan each space once for recent changes --------------------------------
// space => [ ['id','path','title','updated','is_new','uid'], ... ]
$space_changes = [];
foreach ($spaces as $space) {
    $idx = new PageIndexer("$pages_root/$space");
    foreach ($idx->getAllPages() as $id => $data) {
        if (empty($data['path'])) continue;
        if (str_starts_with(ltrim($data['path'], '/'), 'templates/')) continue; // templates aren't content
        $updated = (int)($data['updated'] ?? 0);
        if ($updated < $cutoff) continue;
        if (!is_file("$pages_root/$space/" . ltrim($data['path'], '/'))) continue;
        $ext = pathinfo($data['path'], PATHINFO_EXTENSION);
        $space_changes[$space][] = [
            'id'      => (string)$id,
            'path'    => $data['path'],
            'title'   => basename($data['path'], '.' . $ext),
            'updated' => $updated,
            'is_new'  => ((int)($data['created'] ?? 0) >= $cutoff),
            'uid'     => (int)($data['updatedBy']['uid'] ?? 0),
        ];
    }
}

// --- Load users -------------------------------------------------------------
$users_file = (defined('WIKI_SYSTEM_DATA') ? WIKI_SYSTEM_DATA : '') . 'users.json';
$users = (is_file($users_file)) ? (json_decode(file_get_contents($users_file), true)['users'] ?? []) : [];

$sent = 0;
foreach ($users as $u) {
    if (!empty($u['is_ai']) || !empty($u['is_system'])) continue; // humans only
    if (empty($u['dailyDigest'])) continue;                       // opted in?
    if (empty($u['email']))       continue;

    $uid  = (int)($u['uid'] ?? 0);
    $role = $u['role'] ?? 'reader';

    // Accessible spaces: admins and users with spaces=null see everything;
    // otherwise the per-user allowlist (same rule as the app's ACL).
    $allowed = ($role === 'admin' || !array_key_exists('spaces', $u) || $u['spaces'] === null)
        ? $spaces
        : array_values(array_intersect($spaces, (array)$u['spaces']));

    // Collect changes, excluding pages the user themselves last edited.
    $items = [];
    foreach ($allowed as $space) {
        foreach ($space_changes[$space] ?? [] as $c) {
            if ($uid !== 0 && $c['uid'] === $uid) continue; // exclude own edits
            $items[] = $c + ['space' => $space];
        }
    }
    if (!$items) continue; // nothing new → no email

    usort($items, fn($a, $b) => $b['updated'] <=> $a['updated']);
    $total = count($items);
    $items = array_slice($items, 0, DIGEST_MAX);

    // Group the (already recency-sorted) items by space, keeping group order by
    // each group's most-recent change.
    $groups = [];
    foreach ($items as $it) $groups[$it['space']][] = $it;

    $app  = defined('APP_TITLE') ? APP_TITLE : 'Astucia Wiki';
    $rows = '';
    foreach ($groups as $space => $list) {
        $rows .= '<h3 style="margin:20px 0 6px;font-size:15px;color:#2d3748;border-bottom:1px solid #e2e8f0;padding-bottom:4px;">' . $h($space) . '</h3>';
        foreach ($list as $it) {
            $badge = $it['is_new']
                ? '<span style="display:inline-block;font-size:11px;font-weight:600;color:#fff;background:#48bb78;border-radius:4px;padding:1px 6px;margin-right:6px;">New</span>'
                : '<span style="display:inline-block;font-size:11px;font-weight:600;color:#fff;background:#4299e1;border-radius:4px;padding:1px 6px;margin-right:6px;">Updated</span>';
            $link = $base_url
                ? $base_url . '/index.php?pageid=' . urlencode($it['id']) . '&space=' . urlencode($space)
                : '';
            $title = $link
                ? '<a href="' . $h($link) . '" style="color:#3182ce;text-decoration:none;">' . $h($it['title']) . '</a>'
                : $h($it['title']);
            $rows .= '<div style="margin:5px 0;font-size:14px;color:#2d3748;">'
                   . $badge . $title
                   . ' <span style="color:#a0aec0;font-size:12px;">· ' . $h(digest_ago($it['updated'], $now)) . '</span></div>';
        }
    }

    $more = $total > DIGEST_MAX
        ? '<p style="margin-top:16px;color:#718096;font-size:13px;">…and ' . ($total - DIGEST_MAX) . ' more change' . ($total - DIGEST_MAX === 1 ? '' : 's') . ' in the last 24 hours.</p>'
        : '';

    $count_label = $total . ' update' . ($total === 1 ? '' : 's');
    $subject = "{$app} — {$count_label} in the last 24 hours";
    $html = '<div style="font-family:\'Segoe UI\',system-ui,-apple-system,sans-serif;max-width:600px;margin:0 auto;">'
          . '<h2 style="font-size:18px;color:#1a202c;">' . $h($app) . ' — daily digest</h2>'
          . '<p style="color:#718096;font-size:13px;margin-top:-6px;">Pages created or updated in the last 24 hours.</p>'
          . $rows . $more
          . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0 12px;">'
          . '<p style="color:#a0aec0;font-size:12px;">You are receiving this because you subscribed to daily updates in My Preferences. Turn it off there any time.</p>'
          . '</div>';

    if (send_email($u['email'], $u['name'] ?? '', $subject, $html)) {
        $sent++;
    } else {
        fwrite(STDERR, "Daily digest: failed to send to {$u['email']}\n");
    }
}

echo "Daily digest complete: {$sent} email(s) sent at " . date('c', $now) . ".\n";
