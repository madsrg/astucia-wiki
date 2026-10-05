#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# LLM Providers ("connections"): the endpoint, key and gateway headers moved off each AI
# user onto a shared record, and the one-shot migration that moves an existing install.
#
# The migration is the part an upgrade depends on, so most of this is about it: that it
# groups exactly the AI users that were already the same connection, that the keys leave
# users.json only after they are safe in the new file, that it survives being interrupted
# between those two writes, and that it still runs the email migration it sits behind.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
JAR=$WIKI_ROOT/jar

# A stub model endpoint that answers with the key it was sent, so a call can prove which
# connection it went through.
mkdir -p "$WIKI_ROOT/stub"
cat > "$WIKI_ROOT/stub/__test_llm.php" <<'PHP'
<?php
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop',
    'message' => ['role' => 'assistant', 'content' => 'KEY=' . substr($auth, 7)]]]]);
PHP
# On a server of its own: `php -S` handles one request at a time, so the wiki calling a
# stub it is itself serving would wait on its own request.
STUB_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1])')
( cd "$WIKI_ROOT/stub" && exec php -S "127.0.0.1:$STUB_PORT" ) > "$WIKI_ROOT/stub.log" 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null; fixture_stop' EXIT
until curl -s --max-time 1 "http://127.0.0.1:$STUB_PORT/__test_llm.php" > /dev/null 2>&1; do sleep 0.1; done
STUB="http://127.0.0.1:$STUB_PORT/__test_llm.php"

# Legacy shape, no `schema`: what an install looks like before upgrading.
#  - Ann and Ben share a key; Ben left the URL empty, Ann typed the default into it.
#    That is one connection, not two that are secretly the same.
#  - Cat has a different key on the same family — a different account.
#  - Dot goes through a gateway (the stub) with a header.
#  - Alice is an OIDC human, so the email migration has something to do.
legacy_users() {
cat <<JSON
{"users":[
 {"uid":1,"sub":"s1","name":"Alice","role":"admin","auth":"oidc","email":"alice@example.com"},
 {"uid":-1,"name":"Ann","role":"editor","is_ai":true,"service_token":"wk_ai_ann",
  "ai_config":{"provider":"openai","api_url":"https://api.openai.com/v1/chat/completions","api_key":"sk-shared-1111","model":"gpt-a","system_prompt":"I am Ann"}},
 {"uid":-2,"name":"Ben","role":"editor","is_ai":true,
  "ai_config":{"provider":"openai","api_url":"","api_key":"sk-shared-1111","model":"gpt-b","mcp_server_ids":["m1"]}},
 {"uid":-3,"name":"Cat","role":"editor","is_ai":true,
  "ai_config":{"provider":"openai","api_url":"","api_key":"sk-other-2222","model":"gpt-c"}},
 {"uid":-4,"name":"Dot","role":"editor","is_ai":true,
  "ai_config":{"provider":"openai","api_url":"$STUB","api_key":"sk-gateway-3333","model":"stub-m",
               "extra_headers":[{"name":"X-Team","value":"t1"}]}}
]}
JSON
}
fixture_users "$(legacy_users)"
fixture_login "$JAR" "uid=1&sub=s1&name=Alice&role=admin"

# Any request through the api.php bootstrap runs the migration.
get_as "$JAR" 'api.php?action=list_spaces' > /dev/null

py() { python3 -c "import json,sys; u=json.load(open('$WIKI_SYS/users.json')); c=json.load(open('$WIKI_SYS/llm_connections.json')) if __import__('os').path.exists('$WIKI_SYS/llm_connections.json') else []; $1"; }
ai()  { echo "[x for x in u['users'] if x.get('name')=='$1'][0]['ai_config']"; }

section 'migration groups exactly the AI users that were one connection'
assert_eq "schema is now 3"                       '3'    "$(py "print(u.get('schema'))")"
assert_eq "three connections, not four"           '3'    "$(py 'print(len(c))')"
assert_eq "Ann and Ben share one (default URL ≡ empty URL)" 'True' "$(py "print($(ai Ann)['connection_id']==$(ai Ben)['connection_id'])")"
assert_eq "Cat's different key is its own"        'False' "$(py "print($(ai Ann)['connection_id']==$(ai Cat)['connection_id'])")"
assert_eq "Dot's gateway headers went with it"    't1'   "$(py "print([x for x in c if x['id']==$(ai Dot)['connection_id']][0]['extra_headers'][0]['value'])")"
assert_eq "names are distinct"                    '3'    "$(py "print(len({x['name'] for x in c}))")"

section 'the keys left users.json, and only users.json'
assert_not_contains "no key in users.json"        'sk-shared-1111' "$(cat "$WIKI_SYS/users.json")"
assert_not_contains "no URL left inline"          'api_url'        "$(cat "$WIKI_SYS/users.json")"
assert_contains     "the key is in the new file"  'sk-shared-1111' "$(cat "$WIKI_SYS/llm_connections.json")"
assert_contains     "a one-time backup holds the old shape" 'sk-shared-1111' "$(cat "$WIKI_SYS/users.json.pre-connections.bak" 2>/dev/null)"
assert_eq "the AI user's own settings stayed"     'I am Ann|gpt-b|m1' \
    "$(py "print($(ai Ann)['system_prompt'] + '|' + $(ai Ben)['model'] + '|' + $(ai Ben)['mcp_server_ids'][0])")"
assert_eq "the service token is untouched"        'wk_ai_ann' "$(py "print([x for x in u['users'] if x.get('name')=='Ann'][0]['service_token'])")"

section 'the email migration it sits behind still ran'
assert_eq "OIDC user's notifyEmail captured"      'alice@example.com' "$(py "print(u['users'][0].get('notifyEmail'))")"

section 'it runs once'
before=$(md5sum < "$WIKI_SYS/llm_connections.json")
get_as "$JAR" 'api.php?action=list_spaces' > /dev/null
assert_eq "a second request changes nothing"      "$before" "$(md5sum < "$WIKI_SYS/llm_connections.json")"

section 'interrupted between its two writes, it finishes without duplicating'
# The crash window: connections written, users.json not yet stripped.
cp "$WIKI_SYS/users.json.pre-connections.bak" "$WIKI_SYS/users.json"
get_as "$JAR" 'api.php?action=list_spaces' > /dev/null
assert_eq "still three connections"               '3'    "$(py 'print(len(c))')"
assert_not_contains "and the keys are gone again" 'sk-shared-1111' "$(cat "$WIKI_SYS/users.json")"

section 'an LLM call goes through the connection'
r=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "ai_core.php";
$u = json_decode(file_get_contents(WIKI_SYSTEM_DATA . "users.json"), true)["users"];
$dot = array_values(array_filter($u, fn($x) => ($x["name"] ?? "") === "Dot"))[0];
$r = _ai_quick_reply($dot, "s", "hi", 50);
echo $r["ok"] ? $r["reply"] : "ERR " . $r["error"];' 2>&1)
assert_eq "the connection's key was sent"         'KEY=sk-gateway-3333' "$r"
# Positive control for the fallback: an un-migrated record still works inline.
r=$(cd "$WIKI_APP" && STUB="$STUB" php -r '
require "config.php"; require "ai_core.php";
$r = _ai_quick_reply(["name" => "Old", "ai_config" => ["provider" => "openai", "api_url" => getenv("STUB"),
                     "api_key" => "sk-inline-9", "model" => "m"]], "s", "hi", 50);
echo $r["ok"] ? $r["reply"] : "ERR " . $r["error"];' 2>&1)
assert_eq "an inline record still works"          'KEY=sk-inline-9' "$r"

section 'the admin API never hands out a key'
r=$(get_as "$JAR" 'api.php?action=admin_get_llm_connections')
assert_contains     "lists the connections"       '"used_by"'      "$r"
assert_contains     "with who uses them"          'Ann'            "$r"
assert_not_contains "no key"                      'sk-shared-1111' "$r"
assert_contains     "only whether one is set"     '"api_key_set":true' "$r"
r=$(get_as "$JAR" 'api.php?action=admin_get_ai_users')
assert_contains     "AI users show the connection name" '"connection_name"' "$r"
assert_not_contains "and no key"                  'sk-gateway-3333' "$r"

section 'a connection in use cannot be deleted'
ANN_CONN=$(py "print($(ai Ann)['connection_id'])")
r=$(post_as "$JAR" 'api.php?action=admin_delete_llm_connection' "id=$ANN_CONN")
assert_contains "refused, naming who"             'Ben'  "$r"
assert_eq "still there"                           '3'    "$(py 'print(len(c))')"
r=$(post_as "$JAR" 'api.php?action=admin_save_llm_connection' 'name=Spare&provider=anthropic&api_url=&api_key=sk-ant-x&extra_headers=[]')
SPARE=$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("id",""))')
assert_contains "a new one can be made"           '"success":true' "$r"
r=$(post_as "$JAR" 'api.php?action=admin_save_llm_connection' 'name=spare&provider=openai&api_url=&api_key=k&extra_headers=[]')
assert_contains "names are unique, case-blind"    'already has that name' "$r"
r=$(post_as "$JAR" 'api.php?action=admin_delete_llm_connection' "id=$SPARE")
assert_contains "an unused one can be deleted"    '"success":true' "$r"
assert_eq "and is gone"                           '3'    "$(py 'print(len(c))')"

section 'editing a connection keeps the key unless a new one is typed'
DOT_CONN=$(py "print($(ai Dot)['connection_id'])")
post_as "$JAR" 'api.php?action=admin_save_llm_connection' \
    "id=$DOT_CONN&name=Gateway&provider=openai&api_url=$STUB&api_key=&extra_headers=[]" > /dev/null
assert_eq "renamed"                               'Gateway' "$(py "print([x for x in c if x['id']=='$DOT_CONN'][0]['name'])")"
assert_eq "key kept"                              'sk-gateway-3333' "$(py "print([x for x in c if x['id']=='$DOT_CONN'][0]['api_key'])")"

section 'saving an AI user'
enc() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc '{"connection_id":"llm_nope","model":"gpt-c"}')")
assert_contains "refuses a connection that does not exist" 'Choose an LLM provider' "$r"
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$DOT_CONN\",\"model\":\"gpt-c\",\"api_key\":\"sk-smuggled\"}")")
assert_contains "accepts a real one"              '"success":true' "$r"
assert_eq "and moved Cat onto it"                 "$DOT_CONN" "$(py "print($(ai Cat)['connection_id'])")"
assert_not_contains "a posted key is not stored on the AI user" 'sk-smuggled' "$(cat "$WIKI_SYS/users.json")"
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "name=Eve&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$ANN_CONN\",\"model\":\"gpt-e\"}")")
assert_contains "a new AI user can be created"    '"success":true' "$r"
assert_eq "naming the connection"                 "$ANN_CONN" "$(py "print($(ai Eve)['connection_id'])")"

section 'an AI user can carry an avatar from the vendored set'
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$DOT_CONN\",\"model\":\"gpt-c\",\"avatar\":\"fox\"}")")
assert_contains "a listed id is accepted"         '"success":true' "$r"
assert_eq "and stored"                            'fox' "$(py "print($(ai Cat).get('avatar'))")"
r=$(get_as "$JAR" 'api.php?action=get_user_list')
assert_contains "chat can see it"                 '"avatar":"fox"' "$r"
r=$(get_as "$JAR" 'api.php?action=admin_get_ai_users')
assert_contains "the picker gets the options"     '"avatars":["robot"' "$r"
assert_eq "exactly the vendored hundred"          '100' "$(printf '%s' "$r" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["avatars"]))')"
for bad in 'not-an-icon' '../../config' 'fox.svg'; do
    r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$DOT_CONN\",\"model\":\"gpt-c\",\"avatar\":\"$bad\"}")")
    assert_contains "refuses '$bad'"              'Unknown avatar' "$r"
done
assert_eq "and kept the one it had"               'fox' "$(py "print($(ai Cat).get('avatar'))")"
post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$DOT_CONN\",\"model\":\"gpt-c\"}")" > /dev/null
assert_eq "a save that does not mention it keeps it" 'fox' "$(py "print($(ai Cat).get('avatar'))")"
post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=-3&name=Cat&role=editor&spaces=null&ai_config=$(enc "{\"connection_id\":\"$DOT_CONN\",\"model\":\"gpt-c\",\"avatar\":\"\"}")" > /dev/null
assert_eq "an empty one clears it"                '' "$(py "print($(ai Cat).get('avatar'))")"

section 'the test action resolves the stored key'
r=$(post_as "$JAR" 'api.php?action=admin_test_llm_connection' "id=$DOT_CONN&model=stub-m")
assert_contains "tests with the saved key"        'sk-gateway-3333' "$r"
r=$(post_as "$JAR" 'api.php?action=admin_test_llm_connection' "id=$DOT_CONN&model=stub-m&api_key=sk-typed-4")
assert_contains "or with one typed into the form" 'sk-typed-4'      "$r"

section 'readers cannot reach any of it'
fixture_login "$WIKI_ROOT/jar2" "uid=2&name=Rita&role=reader"
r=$(get_as "$WIKI_ROOT/jar2" 'api.php?action=admin_get_llm_connections')
assert_not_contains "no list for a reader"        'used_by' "$r"
assert_contains     "it is an admin action"       'Admin access required' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
