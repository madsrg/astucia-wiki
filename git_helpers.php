<?php
// =================================================================
// GIT HELPERS — shared by api.php and mcp.php
// Auto-commit wiki file changes into whichever git repo owns the space
// (the space directory itself, or PAGES_DIR if spaces live inside one repo).
// =================================================================

// Returns ['root' => $dir, 'prefix' => $relpath_prefix] or null if no git repo found.
// Checks $space_dir first, then PAGES_DIR — stops there (never climbs higher).
function find_git_root(): ?array {
    global $space_dir;
    $space = rtrim($space_dir, '/');
    if (is_dir($space . '/.git')) {
        return ['root' => $space, 'prefix' => ''];
    }
    $pages = rtrim(PAGES_DIR, '/');
    if (is_dir($pages . '/.git')) {
        return ['root' => $pages, 'prefix' => basename($space) . '/'];
    }
    return null;
}

function git_run(array $args, string $cwd): array {
    $parts = array_map('escapeshellarg', $args);
    $cmd   = 'git ' . implode(' ', $parts);
    $desc  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env   = [
        'PATH'                => '/usr/local/bin:/usr/bin:/bin:' . (getenv('PATH') ?: ''),
        'HOME'                => getenv('HOME') ?: sys_get_temp_dir(),
        'GIT_TERMINAL_PROMPT' => '0',
    ];
    $proc = proc_open($cmd, $desc, $pipes, $cwd, $env);
    if (!is_resource($proc)) return ['output' => '', 'code' => -1];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['output' => trim($out), 'code' => proc_close($proc)];
}

function git_auto_commit(string $abs_path, string $git_name, string $git_email, string $commit_msg): void {
    global $space_dir;
    $git_root = find_git_root();
    if (!$git_root) return;
    $rel         = ltrim(str_replace(rtrim($space_dir, '/') . '/', '', $abs_path), '/');
    $git_relpath = $git_root['prefix'] . $rel;
    git_run(['add', $git_relpath], $git_root['root']);
    git_run([
        '-c', 'user.name=' . $git_name,
        '-c', 'user.email=' . $git_email,
        'commit', '-m', $commit_msg,
    ], $git_root['root']);
}

function git_move_commit(string $old_abs, string $new_abs, string $git_name, string $git_email): void {
    $git_root = find_git_root();
    if (!$git_root) return;
    $root_prefix = rtrim($git_root['root'], '/') . '/';
    // Compute paths relative to git root; only stage paths that live inside it
    $old_rel = strpos($old_abs, $root_prefix) === 0 ? substr($old_abs, strlen($root_prefix)) : null;
    $new_rel = strpos($new_abs, $root_prefix) === 0 ? substr($new_abs, strlen($root_prefix)) : null;
    if ($old_rel === null) return;
    $to_stage = array_values(array_filter([$old_rel, $new_rel]));
    git_run(array_merge(['add'], $to_stage), $git_root['root']);
    $old_name = basename($old_abs);
    $new_name = basename($new_abs);
    $msg = $old_name === $new_name ? "Move $old_name" : "Rename $old_name → $new_name";
    git_run([
        '-c', 'user.name=' . $git_name,
        '-c', 'user.email=' . $git_email,
        'commit', '-m', $msg,
    ], $git_root['root']);
}
