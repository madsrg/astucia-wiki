#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The setup checklist (setup.php): every item worked out from the configuration, never from a
# record of having done the step; "Show at startup" per administrator, on by default only
# while the wiki looks new, and never once everything is done; and administrators only.
#
# Each item is driven from both sides — added, then taken away again — since an item that
# could only ever turn green would pass a checklist that never looks.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Second","role":"admin","auth":"oidc"},
  {"uid":3,"sub":"s3","name":"Ed","role":"editor","auth":"oidc"}]}'
A1=$WIKI_ROOT/jar-a1; A2=$WIKI_ROOT/jar-a2; ED=$WIKI_ROOT/jar-e
fixture_login "$A1" "uid=1&sub=s1&name=Admin&role=admin"
fixture_login "$A2" "uid=2&sub=s2&name=Second&role=admin"
fixture_login "$ED" "uid=3&sub=s3&name=Ed&role=editor"

status() { get_as "$1" 'api.php?action=admin_setup_status'; }
# item <json> <id> <field> — one field of one item, as JSON
item() { printf '%s' "$1" | php -r '$d = json_decode(stream_get_contents(STDIN), true);
    foreach ($d["items"] ?? [] as $i) if ($i["id"] === $argv[1]) { echo json_encode($i[$argv[2]] ?? null); exit; } echo "missing";' "$2" "$3"; }
autoshow() { get_as "$1" 'index.php' | grep -o 'WIKI_SETUP_AUTOSHOW = [a-z]*' | cut -d' ' -f3; }
userfield() { php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d["users"] as $u) if ($u["uid"] == $argv[2]) { echo json_encode($u[$argv[3]] ?? null); exit; }' "$WIKI_SYS/users.json" "$1" "$2"; }
add_ai() {  # add_ai <uid> <disabled true|false> <prompt>
    php -r '$f = $argv[1]; $d = json_decode(file_get_contents($f), true);
        $d["users"][] = ["uid" => (int)$argv[2], "name" => "Bot" . $argv[2], "is_ai" => true, "role" => "editor",
            "disabled" => $argv[3] === "true", "ai_config" => ["connection_id" => "c1", "model" => "m", "system_prompt" => $argv[4]]];
        file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT));' "$WIKI_SYS/users.json" "$@"; }

section "administrators only"
r=$(status "$ED")
assert_not_contains "an editor is refused the status" '"items"' "$r"
assert_eq "an editor's page has no checklist icon" '0' "$(get_as "$ED" 'index.php' | grep -c 'id="setup-btn"')"
assert_eq "and never opens it"                     'false' "$(autoshow "$ED")"
assert_eq "an administrator's page has the icon"   '1' "$(get_as "$A1" 'index.php' | grep -c 'id="setup-btn"')"
r=$(post_as "$ED" 'api.php?action=admin_setup_startup' 'on=0')
assert_not_contains "an editor cannot set the box" '"success":true' "$r"

section "a new wiki: nothing done, the checklist opens"
r=$(status "$A1")
assert_contains "it answers" '"success":true' "$r"
for id in llm ai email taxonomy workflows cron; do
    assert_eq "$id is not done" 'false' "$(item "$r" "$id" done)"
done
assert_eq "sign-in is on in this wiki"   'true'    "$(item "$r" auth done)"
assert_eq "the runner never ran"         '"never"' "$(item "$r" cron state)"
assert_contains "the crontab line names this install's runner" 'run_ai_agent_jobs.php' "$(item "$r" cron line)"
assert_contains "not complete"           '"complete":false' "$r"
assert_contains "Show at startup defaults to on" '"startup":true' "$r"
assert_eq "so the page opens it"         'true' "$(autoshow "$A1")"
# Opening it keeps the default, so the first step done does not untick the box for the rest.
assert_eq "opening it stores the default" 'true' "$(userfield 1 setupStartup)"
assert_contains "the provider cards come with it" '"id":"anthropic"' "$r"
assert_eq "seven of them" '7' "$(printf '%s' "$r" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)["presets"]);')"
assert_contains "Ollama takes no key" '"no_key":true' "$r"

section "Show at startup is per administrator"
r=$(post_as "$A1" 'api.php?action=admin_setup_startup' 'on=0')
assert_contains "unticked" '"success":true' "$r"
assert_eq "kept on the administrator's record" 'false' "$(userfield 1 setupStartup)"
assert_eq "their page no longer opens it"      'false' "$(autoshow "$A1")"
assert_eq "the other administrator's still does" 'true' "$(autoshow "$A2")"
assert_eq "nothing written for them"           'null' "$(userfield 2 setupStartup)"
post_as "$A1" 'api.php?action=admin_setup_startup' 'on=1' > /dev/null
assert_eq "ticked again"                       'true' "$(autoshow "$A1")"

section "an LLM provider"
r=$(post_as "$A1" 'api.php?action=admin_save_llm_connection' 'name=Main&provider=openai&api_url=&api_key=sk-test&extra_headers=[]')
assert_contains "saved through the existing action" '"success":true' "$r"
r=$(status "$A1")
assert_eq "llm done"  'true' "$(item "$r" llm done)"
assert_eq "count 1"   '1'    "$(item "$r" llm count)"
# The wiki no longer looks new: an administrator who never touched the box is not greeted.
assert_eq "an untouched administrator's default turns off" 'false' "$(autoshow "$A2")"
assert_eq "one who ticked it keeps it"                      'true'  "$(autoshow "$A1")"

section "AI users: only one that can answer counts"
add_ai 50 true 'You review pages.'
r=$(status "$A1")
assert_eq "switched off: not ready" 'false' "$(item "$r" ai done)"
assert_eq "but counted as present"  '1'     "$(item "$r" ai total)"
add_ai 51 false ''
assert_eq "no instructions: not ready" 'false' "$(item "$(status "$A1")" ai done)"
add_ai 52 false 'You write release notes.'
r=$(status "$A1")
assert_eq "on, with instructions: ready" 'true' "$(item "$r" ai done)"
assert_eq "one of three"                 '1'    "$(item "$r" ai count)"

section "taxonomy"
printf '{"nodes":[{"name":"Department","children":[{"name":"Finance"},{"name":"Sales"}]}]}' > "$WIKI_PAGES/taxonomy.json"
r=$(status "$A1")
assert_eq "terms: done" 'true' "$(item "$r" taxonomy done)"
assert_eq "three terms" '3'    "$(item "$r" taxonomy count)"

section "workflows: an enabled one"
printf '{"workflows":[{"id":"w1","name":"Off","enabled":false}]}' > "$WIKI_SYS/workflows.json"
assert_eq "a switched-off workflow is not enough" 'false' "$(item "$(status "$A1")" workflows done)"
printf '{"workflows":[{"id":"w1","name":"Off","enabled":false},{"id":"w2","name":"On","enabled":true}]}' > "$WIKI_SYS/workflows.json"
assert_eq "an enabled one is" 'true' "$(item "$(status "$A1")" workflows done)"

section "the background runner"
php -r 'file_put_contents($argv[1], json_encode(["last_run" => date("c"), "interval" => 2]));' "$WIKI_SYS/agent_jobs_heartbeat.json"
r=$(status "$A1")
assert_eq "a fresh heartbeat: running" 'true' "$(item "$r" cron done)"
assert_eq "state ok"                   '"ok"' "$(item "$r" cron state)"
php -r 'file_put_contents($argv[1], json_encode(["last_run" => date("c", time() - 3600), "interval" => 2]));' "$WIKI_SYS/agent_jobs_heartbeat.json"
r=$(status "$A1")
assert_eq "an hour old: stopped"  'false'     "$(item "$r" cron done)"
assert_eq "state stalled"         '"stalled"' "$(item "$r" cron state)"
php -r 'file_put_contents($argv[1], json_encode(["last_run" => date("c"), "interval" => 2]));' "$WIKI_SYS/agent_jobs_heartbeat.json"

section "everything done: it stops opening by itself"
sed -i "s#^// define('SENDGRID_API_KEY'.*#define('SENDGRID_API_KEY', 'SG.test');#; s#^// define('SENDGRID_FROM_EMAIL'.*#define('SENDGRID_FROM_EMAIL', 'wiki@example.com');#" "$WIKI_APP/config.php"
# opcache would keep serving the config.php it compiled first.
printf '<?php if (function_exists("opcache_reset")) opcache_reset(); echo "ok";' > "$WIKI_APP/__test_opcache.php"
curl -s "$WIKI_URL/__test_opcache.php" > /dev/null
r=$(status "$A1")
assert_eq "email done once it is configured" 'true' "$(item "$r" email done)"
assert_contains "complete"                   '"complete":true' "$r"
assert_contains "the box is still ticked"    '"startup":true'  "$r"
assert_eq "but the page does not open it"    'false' "$(autoshow "$A1")"

section "undone later, it shows as undone again"
rm -f "$WIKI_PAGES/taxonomy.json"
r=$(status "$A1")
assert_eq "taxonomy back to not done" 'false' "$(item "$r" taxonomy done)"
assert_eq "and the page opens it again" 'true' "$(autoshow "$A1")"

fixture_stop
section "sign-in off: a warning, and the box kept for the wiki"
fixture_start off || exit 1
fixture_space Main
NOAUTH=$WIKI_ROOT/jar-n
r=$(status "$NOAUTH")
assert_eq "sign-in off is not done" 'false'  "$(item "$r" auth done)"
assert_eq "its mode is named"       '"off"'  "$(item "$r" auth mode)"
r=$(post_as "$NOAUTH" 'api.php?action=admin_setup_startup' 'on=0')
assert_contains "the box is saved" '"success":true' "$r"
assert_eq "in the wiki's settings" 'false' "$(php -r 'echo json_encode(json_decode(file_get_contents($argv[1]), true)["setup_startup"] ?? null);' "$WIKI_SYS/settings.json")"
assert_eq "and read back"          'false' "$(autoshow "$NOAUTH")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
