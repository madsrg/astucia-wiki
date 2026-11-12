<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// Give an account a temporary password from the server's command line — the way into a
// fresh wiki in the `password` mode, where nobody can be given a password until an admin
// exists, and the way back in for an admin who has lost theirs.
//
//   php tools/set_password.php <email>
//   php tools/set_password.php <email> --create "Full Name" [--role admin|editor|reader]
//
// In Docker:
//   docker exec -u www-data astucia-wiki php tools/set_password.php you@example.com --create "Your Name"
//
// Run it as the web server's user, so the files it writes stay readable to the wiki.
// The password it prints works once: signing in with it opens the form to choose a new
// one. --create makes the account if no account signs in with that address, as an admin
// unless --role says otherwise. Server access is the authority here, as it is for the
// mode itself in config.php.
// =================================================================

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'logger.php';
require_once 'accounts.php';
require_once 'passwords.php';

$args  = array_slice($argv, 1);
$email = '';
$create = null;
$role  = 'admin';
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--create') $create = trim((string)($args[++$i] ?? ''));
    elseif ($args[$i] === '--role') $role = (string)($args[++$i] ?? '');
    elseif ($email === '') $email = strtolower(trim($args[$i]));
}
$fail = function (string $msg) { fwrite(STDERR, $msg . "\n"); exit(1); };

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail("usage: php tools/set_password.php <email> [--create \"Full Name\"] [--role admin|editor|reader]");
}
if (wiki_auth_mode() !== 'password') {
    $fail("This wiki's AUTHENTICATION is '" . wiki_auth_mode() . "', not 'password'. Set it in config.php first.");
}
if (!in_array($role, ['admin', 'editor', 'reader'], true)) $fail("--role must be admin, editor or reader.");

$user = wiki_find_user_by_otp_email(wiki_users_list(), $email);
if (!$user) {
    if ($create === null) $fail("No account signs in with $email. Add --create \"Full Name\" to make one.");
    if ($create === '') $fail('--create needs a name.');
    $user = wiki_users_mutate(function (array &$doc) use ($email, $create, $role) {
        $max = 0;
        foreach ($doc['users'] as $u) $max = max($max, (int)($u['uid'] ?? 0));
        $new = wiki_user_with_methods(['uid' => $max + 1, 'name' => $create, 'email' => $email, 'role' => $role, 'spaces' => null], '', $email);
        $doc['users'][] = $new;
        return $new;
    });
    echo "Created {$role} account \"{$create}\" ({$email}).\n";
}

$temp = wiki_pw_generate();
wiki_password_set((int)$user['uid'], $temp, true);
write_access_log('PW_TEMP_SET', $email, (string)($user['name'] ?? ''), 'from the command line');
echo "Temporary password for {$email}:\n\n    {$temp}\n\n"
   . "It works once: signing in with it asks for a new password. Any other sessions of this\n"
   . "account have been signed out.\n";
