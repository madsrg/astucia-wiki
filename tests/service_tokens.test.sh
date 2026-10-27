#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Service tokens (wk_ai_… AI users, wk_sys_… API accounts) are stored only as a hash.
#
# They used to sit in users.json in plain text and come back in every admin listing, so a
# copy of that file — a backup, a support bundle — or any admin page load handed out working
# credentials. Now the full token exists in exactly one response: the one that created or
# regenerated it. Existing tokens are converted in place and keep working.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main

# schema 3: the connection migrations have run, so only the token conversion is in play.
printf '%s' '[{"id":"c1","name":"Test","provider":"openai","api_key":"k"}]' > "$WIKI_SYS/llm_connections.json"
fixture_users '{"schema":3,"users":[
  {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"a@example.com"},
  {"uid":2,"name":"OldBot","role":"editor","is_ai":true,"service_token":"wk_ai_legacy0000000000000000000000000000000000001","ai_config":{"connection_id":"c1","model":"m"}},
  {"uid":3,"name":"OldSys","role":"editor","is_system":true,"service_token":"wk_sys_legacy000000000000000000000000000000000002"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&auth=otp&name=Admin&role=admin"

LEG_AI=wk_ai_legacy0000000000000000000000000000000000001
LEG_SYS=wk_sys_legacy000000000000000000000000000000000002
as_token() { get_token "$1" 'api.php?action=list_spaces'; }
users()    { cat "$WIKI_SYS/users.json"; }

section 'existing plain tokens are converted, and keep working'
assert_contains "a legacy AI token authenticates"     '"success":true' "$(as_token "$LEG_AI")"
assert_not_contains "after a request, no plain token is left" "$LEG_AI"  "$(users)"
assert_not_contains "  …for the API account either"           "$LEG_SYS" "$(users)"
assert_not_contains "  …nor the old field"                    '"service_token"' "$(users)"
assert_contains "the hash is stored"                  "$(printf '%s' "$LEG_AI" | sha256sum | cut -d' ' -f1)" "$(users)"
assert_contains "the AI token still works, hashed"    '"success":true' "$(as_token "$LEG_AI")"
assert_contains "so does the API account's"           '"success":true' "$(as_token "$LEG_SYS")"
assert_contains "positive control: a wrong token is refused" 'Authentication required' "$(as_token wk_ai_nottherightone000000000000000000000000000000)"
assert_eq "no backup file keeps the plain tokens" "" "$(command grep -l "$LEG_AI" "$WIKI_SYS"/* 2>/dev/null)"

section 'the listings carry a hint, never the token'
r=$(get_as "$JAR" 'api.php?action=admin_get_ai_users')
assert_contains     "the AI list has the hint"        '"token_hint":"wk_ai_' "$r"
assert_contains     "  …ending in the token's last four" '0001"' "$r"
assert_not_contains "and no token"                    'service_token' "$r"
r=$(get_as "$JAR" 'api.php?action=admin_get_api_accounts')
assert_contains     "the API list has the hint"       '"token_hint":"wk_sys_' "$r"
assert_contains     "  …ending in the token's last four" '0002"' "$r"
assert_not_contains "and no token"                    'service_token' "$r"

section 'a new AI user: the token is in the create response, once'
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' 'name=NewBot&role=editor&ai_config={"connection_id":"c1","model":"m"}')
assert_contains "created (control)"                   '"success":true' "$r"
NEW_AI=$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
case "$NEW_AI" in wk_ai_*) _pass "the response holds a wk_ai_ token";; *) _fail "the response holds a wk_ai_ token" "$r";; esac
assert_not_contains "users.json does not"             "$NEW_AI" "$(users)"
assert_contains "it authenticates"                    '"success":true' "$(as_token "$NEW_AI")"
assert_not_contains "and no listing shows it again"   "$NEW_AI" "$(get_as "$JAR" 'api.php?action=admin_get_ai_users')"
r=$(post_as "$JAR" 'api.php?action=admin_save_ai_user' "uid=$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin)["uid"])')&name=NewBot2&role=editor&ai_config={\"connection_id\":\"c1\",\"model\":\"m\"}")
assert_not_contains "editing it returns no token"     '"token"' "$r"
assert_contains "and the token survives the edit"     '"success":true' "$(as_token "$NEW_AI")"

section 'regenerating'
r=$(post_as "$JAR" 'api.php?action=admin_regenerate_ai_token' 'uid=2')
REGEN=$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
assert_contains "the new token is returned with its hint" '"hint":"wk_ai_' "$r"
assert_contains "the new one works"                   '"success":true' "$(as_token "$REGEN")"
assert_contains "the old one stops at once"           'Authentication required' "$(as_token "$LEG_AI")"
assert_not_contains "and the new one is not stored"   "$REGEN" "$(users)"

section 'a new API account'
r=$(post_as "$JAR" 'api.php?action=admin_save_api_account' 'name=Robot&role=reader')
NEW_SYS=$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("token",""))')
case "$NEW_SYS" in wk_sys_*) _pass "the response holds a wk_sys_ token";; *) _fail "the response holds a wk_sys_ token" "$r";; esac
assert_not_contains "users.json does not"             "$NEW_SYS" "$(users)"
assert_contains "it authenticates"                    '"success":true' "$(as_token "$NEW_SYS")"

section 'MCP accepts a hashed token too'
r=$(curl -s --max-time 15 -H "Authorization: Bearer $NEW_SYS" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$WIKI_URL/mcp.php?space=Main")
assert_contains "tools/list answers"                  'wiki_read_page' "$r"

section 'agent instructions need no token from the server'
r=$(get_as "$JAR" 'api.php?action=api_agent_instructions')
assert_contains "a placeholder the client fills in"   'YOUR_TOKEN_HERE' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
