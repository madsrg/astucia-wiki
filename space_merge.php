<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// SPACE MERGE — move every page of one space into another, then delete the source.
//
// Two passes: space_merge_plan() decides where every file lands and touches nothing,
// space_merge_execute() applies that plan. The dialog shows the plan first, and the
// plan is what makes the collision rules reviewable.
//
// Rules, and why:
//  - Same-named FOLDERS merge; only colliding FILES are renamed to "name (1).ext".
//    Renaming the folder instead would split related content across two trees just
//    because one file inside it collided.
//  - A colliding file whose bytes are identical to its twin is dropped rather than
//    duplicated. Every space is scaffolded with the same templates/ and start page,
//    so without this every merge would leave a shelf of "Page Template (1).md".
//  - A page's attachments (`page.md.uploads/`) and cached diagram export
//    (`page.drawio.svg`) follow the page's new name — they are planned from the
//    page's destination, never on their own.
//  - Page ids are carried over when the number is free in the target index. Ids are
//    random 6-digit integers per space, so nearly all survive, and with them the
//    `?pageid=` links, `{include:ID}` transclusions and wikilink hrefs that point
//    into the merged space. Tags and created/updated authorship come along too —
//    unlike the single-page cross-space `move`, which mints a fresh id.
//  - A source space that is its own git repository is refused: deleting the space
//    would delete its history with it. A repo at PAGES_DIR is fine, because both
//    spaces already share that history.
//
// There is no rollback. A failure part-way leaves the files that already moved in
// the target and stops; the caller reports what happened.
// =================================================================

require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/space_settings.php';

function space_merge_dir(string $name): string {
    return rtrim(PAGES_DIR, '/') . '/' . basename(trim($name));
}

/**
 * Why this merge cannot run, or null when it can.
 * Returns an i18n-friendly code, not prose — api.php maps it for the client.
 */
function space_merge_blocker(string $src_name, string $tgt_name): ?string {
    $src = basename(trim($src_name));
    $tgt = basename(trim($tgt_name));
    if ($src === '' || $tgt === '')                     return 'invalid';
    if ($src === $tgt)                                  return 'same';
    if ($src[0] === '.' || $tgt[0] === '.')             return 'invalid';
    if (!is_dir(space_merge_dir($src)))                 return 'no-source';
    if (!is_dir(space_merge_dir($tgt)))                 return 'no-target';
    if (is_dir(space_merge_dir($src) . '/.git'))        return 'source-git';
    if (wiki_space_is_readonly($src))                   return 'source-readonly';
    if (wiki_space_is_readonly($tgt))                   return 'target-readonly';
    return null;
}

// The repo that should record the merge, or null. Mirrors find_git_root()'s order
// (space first, then PAGES_DIR) without its dependency on the request's $space_dir.
function space_merge_git_root(string $tgt_name): ?string {
    $tgt = space_merge_dir($tgt_name);
    if (is_dir($tgt . '/.git'))                        return $tgt;
    if (is_dir(rtrim(PAGES_DIR, '/') . '/.git'))       return rtrim(PAGES_DIR, '/');
    return null;
}

function _spm_identical(string $a, string $b): bool {
    if (!is_file($a) || !is_file($b)) return false;
    if (filesize($a) !== filesize($b)) return false;
    return md5_file($a) === md5_file($b);
}

// "notes.md" → "notes (2).md". Dot-files ('.filesfolder') have no basename to
// split, so the whole name is treated as the base.
function _spm_numbered(string $name, int $n): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    if ($base === '') return $name . " ($n)";
    return $base . " ($n)" . ($ext !== '' ? '.' . $ext : '');
}

/**
 * Recursive planner. $src_rel and $dst_rel differ once a parent has been renamed.
 * $taken guards against two source entries claiming one new name in the same run.
 */
function _spm_plan_dir(string $src_dir, string $tgt_dir, string $src_rel, string $dst_rel,
                       array &$out, array &$taken): void {
    $abs = $src_rel === '' ? $src_dir : $src_dir . '/' . $src_rel;
    $entries = @scandir($abs);
    if ($entries === false) return;

    $files = [];
    $dirs  = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e === '.git') continue;
        // The index and graph sidecars belong to the space being dissolved, not to
        // its content; the target keeps its own.
        if ($src_rel === '' && ($e === 'index.json' || $e === 'graph.json')) continue;
        if (is_dir($abs . '/' . $e)) $dirs[] = $e; else $files[] = $e;
    }

    // Entries owned by a page, keyed by the entry name.
    $attached = [];
    foreach ($dirs as $d) {
        if (substr($d, -8) === '.uploads' && in_array(substr($d, 0, -8), $files, true)) {
            $attached[$d] = substr($d, 0, -8);
        }
    }
    foreach ($files as $f) {
        if (substr($f, -4) === '.svg' && in_array(substr($f, 0, -4), $files, true)) {
            $attached[$f] = substr($f, 0, -4);
        }
    }

    // A destination name for one entry, honouring collisions in the target and in
    // this plan. $identical is set when the twin already there is byte-for-byte the
    // same file, which the caller turns into a skip rather than a move.
    $dest_for = function (string $name, bool $is_dir, ?string $src_abs) use ($tgt_dir, $dst_rel, &$taken, &$identical): string {
        $identical = false;
        $prefix    = $dst_rel === '' ? '' : $dst_rel . '/';
        $n         = 0;
        $candidate = $name;
        while (true) {
            $abs_t = $tgt_dir . '/' . $prefix . $candidate;
            $clash = isset($taken[$prefix . $candidate]) || file_exists($abs_t);
            // A folder merges into a folder of the same name; only a *file* sitting
            // where the folder wants to be forces a rename.
            if ($clash && $is_dir && is_dir($abs_t) && !isset($taken[$prefix . $candidate])) break;
            if (!$clash) break;
            if ($n === 0 && !$is_dir && $src_abs !== null && _spm_identical($src_abs, $abs_t)) {
                $identical = true;
                break;
            }
            $candidate = _spm_numbered($name, ++$n);
        }
        $taken[$prefix . $candidate] = true;
        return $candidate;
    };

    $map = [];   // source entry name => destination entry name

    foreach ($files as $f) {
        if (isset($attached[$f])) continue;
        $identical = false;
        $dest = $dest_for($f, false, $abs . '/' . $f);
        $map[$f] = $dest;
        $from = ($src_rel === '' ? '' : $src_rel . '/') . $f;
        $to   = ($dst_rel === '' ? '' : $dst_rel . '/') . $dest;
        if ($identical) $out['skips'][] = ['from' => $from, 'to' => $to];
        else            $out['moves'][] = ['from' => $from, 'to' => $to, 'renamed' => $dest !== $f];
    }

    foreach ($dirs as $d) {
        if (isset($attached[$d])) continue;
        $identical = false;
        $dest = $dest_for($d, true, null);
        $map[$d] = $dest;
        _spm_plan_dir($src_dir, $tgt_dir,
            ($src_rel === '' ? '' : $src_rel . '/') . $d,
            ($dst_rel === '' ? '' : $dst_rel . '/') . $dest,
            $out, $taken);
    }

    foreach ($attached as $entry => $owner) {
        $owner_dest = $map[$owner] ?? $owner;
        $from = ($src_rel === '' ? '' : $src_rel . '/') . $entry;
        if (substr($entry, -8) === '.uploads') {
            $dest = $owner_dest . '.uploads';
            $to   = ($dst_rel === '' ? '' : $dst_rel . '/') . $dest;
            $taken[$to] = true;
            // Recurse rather than move wholesale: the owner may have been skipped as
            // a duplicate, in which case its attachments merge into the twin's folder.
            _spm_plan_dir($src_dir, $tgt_dir,
                ($src_rel === '' ? '' : $src_rel . '/') . $entry, $to, $out, $taken);
        } else {
            $dest = $owner_dest . '.svg';
            $to   = ($dst_rel === '' ? '' : $dst_rel . '/') . $dest;
            // A derived export cache: if the target already has one for this page it
            // is authoritative, so the source copy is simply dropped.
            if (file_exists($tgt_dir . '/' . $to)) $out['skips'][] = ['from' => $from, 'to' => $to];
            else $out['moves'][] = ['from' => $from, 'to' => $to, 'renamed' => $dest !== $entry];
            $taken[$to] = true;
        }
    }
}

/**
 * @return array{moves:array,skips:array,renamed:int,pages:int}
 *         `pages` counts indexable content files, which is the number worth showing.
 */
function space_merge_plan(string $src_name, string $tgt_name): array {
    $out   = ['moves' => [], 'skips' => []];
    $taken = [];
    _spm_plan_dir(space_merge_dir($src_name), space_merge_dir($tgt_name), '', '', $out, $taken);

    $renamed = 0;
    $pages   = 0;
    foreach ($out['moves'] as $m) {
        if ($m['renamed']) $renamed++;
        if (_spm_is_page($m['to'])) $pages++;
    }
    $out['renamed'] = $renamed;
    $out['pages']   = $pages;
    return $out;
}

// A content file that carries a page id — attachments never do, whatever their
// extension, so anything inside a *.uploads folder is excluded.
function _spm_is_page(string $rel): bool {
    foreach (explode('/', $rel) as $seg) {
        if (substr($seg, -8) === '.uploads') return false;
    }
    return in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), PageIndexer::CONTENT_EXTS, true);
}

/**
 * Files in the source that the plan did not account for — a page written into the
 * space between plan and execute, say. The space's own index.json / graph.json don't
 * count: they describe a space that is being dissolved.
 *
 * Checked before anything is deleted, so a surprise leaves the source space exactly
 * as it was rather than half-dismantled.
 */
function _spm_leftovers(string $dir, string $rel = ''): array {
    $out = [];
    foreach ((@scandir($dir) ?: []) as $e) {
        if ($e === '.' || $e === '..') continue;
        $abs = $dir . '/' . $e;
        $r   = $rel === '' ? $e : $rel . '/' . $e;
        if (is_dir($abs)) {
            $out = array_merge($out, _spm_leftovers($abs, $r));
        } elseif ($rel !== '' || ($e !== 'index.json' && $e !== 'graph.json')) {
            $out[] = $r;
        }
    }
    return $out;
}

// Plain recursive delete of the emptied source space. Only ever called once
// _spm_leftovers() has confirmed there is nothing in it worth keeping.
function _spm_rmtree(string $dir): bool {
    $ok = true;
    foreach ((@scandir($dir) ?: []) as $e) {
        if ($e === '.' || $e === '..') continue;
        $abs = $dir . '/' . $e;
        $ok  = (is_dir($abs) ? _spm_rmtree($abs) : @unlink($abs)) && $ok;
    }
    return @rmdir($dir) && $ok;
}

/**
 * Apply a plan. Order matters: content moves first, then the indexes, then the
 * source directory, then the bookkeeping that names the space.
 *
 * @return array{success:bool,message:string,moved:int,renamed:int,skipped:int,leftovers:array}
 */
function space_merge_execute(string $src_name, string $tgt_name, array $plan, $search_idx, array $actor): array {
    $src = basename(trim($src_name));
    $tgt = basename(trim($tgt_name));
    $src_dir = space_merge_dir($src);
    $tgt_dir = space_merge_dir($tgt);

    $result = ['success' => false, 'message' => '', 'moved' => 0, 'renamed' => 0,
               'skipped' => count($plan['skips']), 'leftovers' => []];

    // One merge at a time: PageIndexer::saveIndex() is an unlocked whole-file write,
    // so two merges into the same target would lose entries from one of them.
    $lock = defined('WIKI_SYSTEM_DATA') ? @fopen(rtrim(WIKI_SYSTEM_DATA, '/') . '/space-merge.lock', 'c') : false;
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $result['message'] = 'Another space merge is already running.';
        return $result;
    }

    try {
        // 1. Content.
        foreach ($plan['moves'] as $m) {
            $from = $src_dir . '/' . $m['from'];
            $to   = $tgt_dir . '/' . $m['to'];
            $parent = dirname($to);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                $result['message'] = 'Could not create ' . $m['to'] . ' in the target space. ' .
                                     $result['moved'] . ' file(s) had already been moved.';
                return $result;
            }
            if (!@rename($from, $to)) {
                $result['message'] = 'Could not move ' . $m['from'] . '. ' .
                                     $result['moved'] . ' file(s) had already been moved.';
                return $result;
            }
            $result['moved']++;
            if ($m['renamed']) $result['renamed']++;
        }
        foreach ($plan['skips'] as $s) {
            @unlink($src_dir . '/' . $s['from']);
        }

        // 2. Indexes. One write into the target, carrying ids where they are free.
        $src_idx = new PageIndexer($src_dir);
        $tgt_idx = new PageIndexer($tgt_dir);
        $moved_paths = [];
        foreach ($plan['moves'] as $m) {
            if (_spm_is_page($m['from'])) $moved_paths[$m['from']] = $m['to'];
        }
        $import = [];
        foreach ($src_idx->getAllPages() as $pid => $entry) {
            $path = $entry['path'] ?? '';
            if (!isset($moved_paths[$path])) continue;      // skipped duplicate, or not a page
            $entry['path'] = $moved_paths[$path];
            $import[(int)$pid] = $entry;
        }
        $tgt_idx->importPages($import);

        // 3. The source space itself.
        $leftovers = _spm_leftovers($src_dir);
        if ($leftovers) {
            $result['leftovers'] = array_slice($leftovers, 0, 20);
            $result['message'] = 'Content moved, but the source space still holds files that '
                               . 'were not part of the merge, so it was left in place.';
            return $result;
        }
        if (!_spm_rmtree($src_dir)) {
            $result['message'] = 'Content moved, but the source space directory could not be removed.';
            return $result;
        }

        // 4. Search index: drop the source, rebuild the target in one pass. Cheaper
        //    and more reliable than a movePageCrossSpace per file.
        if ($search_idx) {
            try { $search_idx->deleteSpace($src); } catch (\Throwable $_e) {}
            try { $search_idx->rebuildSpace($tgt); } catch (\Throwable $_e) {}
        }

        // 5. The link-graph cache is keyed by page id and now describes a tree that
        //    has grown; drop it so graph.php rebuilds on the next request.
        @unlink($tgt_dir . '/graph.json');

        // 6. Everything else that names the source space.
        space_merge_repoint_references($src, $tgt, $plan['moves']);

        // 7. Record it, if a repo owns the target.
        $git_root = space_merge_git_root($tgt);
        if ($git_root) {
            require_once __DIR__ . '/git_helpers.php';
            git_run(['add', '-A', '.'], $git_root);
            git_run([
                '-c', 'user.name='  . ($actor['name'] ?? 'Wiki'),
                '-c', 'user.email=' . ($actor['email'] ?? 'wiki@localhost'),
                'commit', '-m', "Merge space $src into $tgt",
            ], $git_root);
        }

        $result['success'] = true;
        return $result;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/**
 * Rewrite every stored reference to a space that no longer exists.
 *
 * Space access lists are rewritten to the target rather than dropped: a user whose
 * only granted space was the source would otherwise be left with access to nothing,
 * having done nothing wrong. (A logged-in session keeps its old list until the next
 * login, which only affects which spaces that one session offers.)
 */
function space_merge_repoint_references(string $src, string $tgt, array $moves = []): void {
    if (!defined('WIKI_SYSTEM_DATA')) return;
    $sys = rtrim(WIKI_SYSTEM_DATA, '/') . '/';

    // A queued job replies into a specific .chat file, which the merge may have
    // renamed out from under it; without this the runner finds nothing and the
    // pending placeholder in that thread is never resolved.
    $chat_moves = [];
    foreach ($moves as $m) {
        if ($m['from'] !== $m['to'] && substr($m['to'], -5) === '.chat') {
            $chat_moves[$m['from']] = $m['to'];
        }
    }

    // users.json — humans, AI users and API accounts all live here.
    if (is_file($sys . 'users.json')) {
        $uf = json_decode((string)file_get_contents($sys . 'users.json'), true) ?? ['users' => []];
        // Iterate a real variable, not `$uf['users'] ?? []` — a by-reference foreach
        // over that expression writes into a temporary and the edits vanish.
        $users   = is_array($uf['users'] ?? null) ? $uf['users'] : [];
        $changed = false;
        foreach ($users as &$u) {
            if (!isset($u['spaces']) || !is_array($u['spaces'])) continue;
            if (!in_array($src, $u['spaces'], true)) continue;
            $next = [];
            foreach ($u['spaces'] as $s) {
                $s = ($s === $src) ? $tgt : $s;
                if (!in_array($s, $next, true)) $next[] = $s;
            }
            $u['spaces'] = $next;
            $changed = true;
        }
        unset($u);
        if ($changed) {
            $uf['users'] = $users;
            file_put_contents($sys . 'users.json', json_encode($uf, JSON_PRETTY_PRINT));
        }
    }

    // Scheduled agent jobs.
    if (is_file($sys . 'agent_jobs.json')) {
        $aj      = json_decode((string)file_get_contents($sys . 'agent_jobs.json'), true) ?? ['jobs' => []];
        $jobs    = is_array($aj['jobs'] ?? null) ? $aj['jobs'] : [];
        $changed = false;
        foreach ($jobs as &$j) {
            if (($j['space'] ?? '') !== $src) continue;
            // Only the space name: a scheduled job holds no page path of its own.
            $j['space'] = $tgt;
            $changed = true;
        }
        unset($j);
        if ($changed) {
            $aj['jobs'] = $jobs;
            file_put_contents($sys . 'agent_jobs.json', json_encode($aj, JSON_PRETTY_PRINT));
        }
    }

    // Queued one-off jobs — through the shared mutator, the cron runner writes it too.
    if (function_exists('agent_job_queue_mutate')) {
        try {
            agent_job_queue_mutate(function (array &$jobs) use ($src, $tgt, $chat_moves) {
                foreach ($jobs as &$j) {
                    if (($j['space'] ?? '') !== $src) continue;
                    $j['space'] = $tgt;
                    $chat = ltrim((string)($j['reply_to']['chat'] ?? ''), '/');
                    if ($chat !== '' && isset($chat_moves[$chat])) {
                        $j['reply_to']['chat'] = $chat_moves[$chat];
                    }
                }
                unset($j);
            });
        } catch (\Throwable $_e) {}
    }

    // The external-change stamp for a space that is gone.
    if (function_exists('index_sync_stamp_path')) {
        $stamp = index_sync_stamp_path(space_merge_dir($src));
        if ($stamp) { @unlink($stamp); @unlink($stamp . '.lock'); }
    }

    wiki_space_settings_forget($src);
}
