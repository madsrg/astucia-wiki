<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// SPACE DELETE — take a space out of the wiki, keeping its files.
//
// A soft delete: the directory is moved, whole, to WIKI_SYSTEM_DATA/deleted_spaces/
// <name>-<timestamp>/, outside the content tree. Restoring is moving it back and
// running indexfiles. That covers both git layouts the hard way would not:
//  - a repo at PAGES_DIR records the removal as a commit, and the folder is still
//    there besides;
//  - a space that is its own repo keeps its .git inside the moved folder, where a
//    plain delete would have taken the history with it (which is why merge refuses).
//
// Refused for the last space (wiki_ensure_default_space() would quietly replace it
// with an empty Main) and for a frozen one (a freeze exists to stop content going away).
//
// Everything else that names the space is cleaned up, not repointed — there is no
// target. The one trap is access lists: `spaces` missing means *every* space, `[]`
// means none, so a list emptied by the delete must stay `[]`.
// =================================================================

require_once __DIR__ . '/space_merge.php';

/** Names of every space, as list_spaces sees them. */
function space_delete_all_spaces(): array {
    $out = [];
    foreach ((@scandir(PAGES_DIR) ?: []) as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.') continue;
        if (is_dir(rtrim(PAGES_DIR, '/') . '/' . $e)) $out[] = $e;
    }
    return $out;
}

/** Why this space cannot be deleted, as a code for the client, or null. */
function space_delete_blocker(string $name): ?string {
    $n = basename(trim($name));
    if ($n === '' || $n !== trim($name) || $n[0] === '.') return 'invalid';
    if (!is_dir(space_merge_dir($n)))                       return 'no-source';
    if (wiki_space_is_readonly($n))                         return 'source-readonly';
    if (count(space_delete_all_spaces()) <= 1)              return 'last-space';
    if (!defined('WIKI_SYSTEM_DATA'))                       return 'no-trash';
    return null;
}

function space_delete_trash_dir(): string {
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/deleted_spaces';
}

// Recursive copy, for when rename() cannot cross from PAGES_DIR to WIKI_SYSTEM_DATA
// (two Docker volumes are two filesystems). Symlinks are copied as links.
function _spd_copytree(string $from, string $to): bool {
    if (is_link($from)) return @symlink((string)readlink($from), $to);
    if (!is_dir($from)) return @copy($from, $to);
    if (!is_dir($to) && !@mkdir($to, 0755, true)) return false;
    foreach ((@scandir($from) ?: []) as $e) {
        if ($e === '.' || $e === '..') continue;
        if (!_spd_copytree($from . '/' . $e, $to . '/' . $e)) return false;
    }
    return true;
}

/**
 * Move the space out of the wiki and forget it.
 *
 * @return array{success:bool,message:string,blocked?:string,trash?:string,next?:?string}
 */
function space_delete_execute(string $name, $search_idx, array $actor): array {
    $block = space_delete_blocker($name);
    if ($block !== null) return ['success' => false, 'blocked' => $block, 'message' => ''];

    $name = basename(trim($name));
    $dir  = space_merge_dir($name);

    // Shares the merge's lock: both remove a space, and a merge into a space being
    // deleted would move content into a directory that is about to leave.
    $lock = @fopen(rtrim(WIKI_SYSTEM_DATA, '/') . '/space-merge.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['success' => false, 'message' => 'Another space merge or delete is already running.'];
    }

    try {
        $trash = space_delete_trash_dir();
        if (!is_dir($trash) && !@mkdir($trash, 0755, true) && !is_dir($trash)) {
            return ['success' => false, 'message' => 'Could not create ' . $trash . ' — is WIKI_SYSTEM_DATA writable?'];
        }
        $dest = $trash . '/' . $name . '-' . date('Ymd-His');
        for ($i = 2; file_exists($dest); $i++) $dest = $trash . '/' . $name . '-' . date('Ymd-His') . '-' . $i;

        if (!@rename($dir, $dest)) {
            // Different filesystem: copy, and only remove the original once the copy
            // is complete. A half copy is discarded and the space left untouched.
            if (!_spd_copytree($dir, $dest)) {
                if (is_dir($dest)) _spm_rmtree($dest);
                return ['success' => false, 'message' => 'Could not move the space to ' . $trash . '.'];
            }
            if (!_spm_rmtree($dir)) {
                return ['success' => false, 'trash' => $dest,
                        'message' => 'The space was copied to ' . $dest . ' but could not be removed from the wiki.'];
            }
        }

        if ($search_idx) {
            try { $search_idx->deleteSpace($name); } catch (\Throwable $_e) {}
        }
        space_delete_forget_references($name);

        // A repo at PAGES_DIR records the removal. A space that was its own repo took
        // its .git with it into the trash, so there is nothing to commit there.
        $root = rtrim(PAGES_DIR, '/');
        if (is_dir($root . '/.git')) {
            require_once __DIR__ . '/git_helpers.php';
            git_run(['add', '-A', '--', $name], $root);
            git_run([
                '-c', 'user.name='  . ($actor['name'] ?? 'Wiki'),
                '-c', 'user.email=' . ($actor['email'] ?? 'wiki@localhost'),
                'commit', '-m', "Delete space $name",
            ], $root);
        }

        if (function_exists('wiki_audit_log')) {
            wiki_audit_log('delete', 'success', ['object_category' => 'space', 'object' => $name]);
        }

        $rest = space_delete_all_spaces();
        return ['success' => true, 'message' => '', 'trash' => $dest, 'next' => $rest[0] ?? null];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/**
 * Drop every stored reference to a space that is gone. The counterpart of
 * space_merge_repoint_references(), with nowhere to point them.
 */
function space_delete_forget_references(string $name): void {
    $sys = rtrim(WIKI_SYSTEM_DATA, '/') . '/';

    // users.json. Remove the name but keep the list, even when it ends up empty —
    // dropping the key would turn "only that space" into "every space". Leaving the
    // name in is no better: a space created later under the same name would inherit it.
    if (is_file($sys . 'users.json')) {
        $uf = json_decode((string)file_get_contents($sys . 'users.json'), true) ?? ['users' => []];
        $users   = is_array($uf['users'] ?? null) ? $uf['users'] : [];
        $changed = false;
        foreach ($users as &$u) {
            if (!isset($u['spaces']) || !is_array($u['spaces'])) continue;
            if (!in_array($name, $u['spaces'], true)) continue;
            $u['spaces'] = array_values(array_filter($u['spaces'], fn($s) => $s !== $name));
            $changed = true;
        }
        unset($u);
        if ($changed) {
            $uf['users'] = $users;
            file_put_contents($sys . 'users.json', json_encode($uf, JSON_PRETTY_PRINT));
        }
    }

    // Scheduled agent jobs: switched off, not deleted — the prompt is someone's work,
    // and an admin may want to point it at another space.
    if (is_file($sys . 'agent_jobs.json')) {
        $aj      = json_decode((string)file_get_contents($sys . 'agent_jobs.json'), true) ?? ['jobs' => []];
        $jobs    = is_array($aj['jobs'] ?? null) ? $aj['jobs'] : [];
        $changed = false;
        foreach ($jobs as &$j) {
            if (($j['space'] ?? '') !== $name || empty($j['enabled'])) continue;
            $j['enabled'] = false;
            $changed = true;
        }
        unset($j);
        if ($changed) {
            $aj['jobs'] = $jobs;
            file_put_contents($sys . 'agent_jobs.json', json_encode($aj, JSON_PRETTY_PRINT));
        }
    }

    // Queued one-off jobs would only fail when the runner reached them. Their chat
    // placeholder went with the space, so there is nobody to report back to.
    if (function_exists('agent_job_queue_mutate')) {
        try {
            agent_job_queue_mutate(function (array &$jobs) use ($name) {
                foreach ($jobs as &$j) {
                    if (($j['space'] ?? '') !== $name || ($j['state'] ?? '') !== 'queued') continue;
                    $j['state']       = 'error';
                    $j['error']       = 'The space "' . $name . '" was deleted before this job ran.';
                    $j['finished_at'] = date('c');
                }
                unset($j);
            });
        } catch (\Throwable $_e) {}
    }

    // Workflows scoped to it: switched off with a reason, like the runner does.
    if (function_exists('wiki_workflows_mutate') && is_file(wiki_wf_path('workflows.json'))) {
        wiki_workflows_mutate(function (array &$list) use ($name) {
            foreach ($list as &$wf) {
                if (($wf['space'] ?? '') !== $name || empty($wf['enabled'])) continue;
                $wf['enabled'] = false;
                $wf['disabled_reason'] = 'The space "' . $name . '" was deleted.';
            }
            unset($wf);
        });
    }

    if (function_exists('index_sync_stamp_path')) {
        $stamp = index_sync_stamp_path(space_merge_dir($name));
        if ($stamp) { @unlink($stamp); @unlink($stamp . '.lock'); }
    }

    wiki_space_settings_forget($name);
}
