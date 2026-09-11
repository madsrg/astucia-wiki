#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Where MCP guidance lives.
#
# It used to live only on the AI user (`ai_config.mcp_instructions[serverId]`), so ten AI
# users sharing a server meant ten copies of the same paragraph and ten edits to change
# it. The server record now carries the text that describes the *server*, and the per-AI
# box is what is true of that one AI. They append rather than override — which is what
# keeps this out of the unset-vs-empty tri-state the chat-retention setting needed, since
# an empty per-AI box can only mean "nothing extra".

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

section 'the server record carries the shared text'
r=$(post_as "$JAR" 'api.php?action=admin_save_mcp_server' \
    'name=Jira&url=https://mcp.example.com&instructions=Dates are ISO-8601.')
assert_contains "server saved" '"success":true' "$r"
srv=$(get_as "$JAR" 'api.php?action=admin_get_mcp_servers')
assert_contains "instructions returned to the admin UI" 'Dates are ISO-8601.' "$srv"
assert_not_contains "but never the token"               'auth_token"'         "$srv"
SRV_ID=$(printf '%s' "$srv" | python3 -c "import json,sys; print(json.load(sys.stdin)['data'][0]['id'])")

# Editing an unrelated field must not wipe it.
post_as "$JAR" "api.php?action=admin_save_mcp_server" \
    "id=$SRV_ID&name=Jira&url=https://mcp.example.com&instructions=Dates are ISO-8601." > /dev/null
assert_contains "survives a re-save" 'Dates are ISO-8601.' \
    "$(get_as "$JAR" 'api.php?action=admin_get_mcp_servers')"

section 'the two texts compose, server first'
# wiki_mcp_guidance() is the one assembler both run paths use, so this pins the contract.
probe() {  # probe <server-text> <per-ai-text>
    (cd "$WIKI_APP" && SRV="$1" AI="$2" php -r '
require "config.php"; require "llm_providers.php"; require "ai_core.php";
$servers = [["id" => "s1", "name" => "Jira", "instructions" => getenv("SRV")]];
$per_ai  = getenv("AI") === "" ? [] : ["s1" => getenv("AI")];
echo json_encode(wiki_mcp_guidance($servers, $per_ai));')
}
assert_eq "both, server first" \
    '"\n\nMCP tool guidance:\n[Jira] Dates are ISO-8601.\n[Jira] You are read-only."' \
    "$(probe 'Dates are ISO-8601.' 'You are read-only.')"
assert_eq "server only"      '"\n\nMCP tool guidance:\n[Jira] Dates are ISO-8601."' "$(probe 'Dates are ISO-8601.' '')"
assert_eq "per-AI only"      '"\n\nMCP tool guidance:\n[Jira] You are read-only."'  "$(probe '' 'You are read-only.')"
assert_eq "neither: no block, not an empty header" '""'                             "$(probe '' '')"
assert_eq "whitespace counts as empty"             '""'                             "$(probe '   ' "$(printf '\n\t')")"

section 'both run paths use that one assembler'
# The inline chat path and the job path each used to build this text themselves.
assert_eq "no second copy of the header" "1" \
    "$(command grep -c 'MCP tool guidance:' "$WIKI_APP/ai_core.php")"
assert_eq "inline path calls the helper" "1" \
    "$(command grep -c 'wiki_mcp_guidance(\$enabled_c' "$WIKI_APP/api.php")"
assert_eq "job path calls the helper"    "1" \
    "$(command grep -c 'wiki_mcp_guidance(\$enabled' "$WIKI_APP/ai_core.php")"
assert_eq "api.php builds none itself"   "0" \
    "$(command grep -c 'MCP tool guidance:' "$WIKI_APP/api.php")"

section 'one server, many AI users: the text is stored once'
ai() { post_as "$JAR" 'api.php?action=admin_save_ai_user' \
    "name=$1&role=editor&ai_config=$(python3 -c "
import json,urllib.parse,sys
print(urllib.parse.quote(json.dumps({'provider':'openai','model':'m','api_key':'k',
      'mcp_server_ids':[sys.argv[1]],'mcp_instructions':{}})))" "$SRV_ID")" > /dev/null; }
ai Bot1; ai Bot2; ai Bot3
users=$(cat "$WIKI_SYS/users.json")
assert_eq "three AI users enabled it" "3" "$(printf '%s' "$users" | grep -c '"is_ai": true')"
assert_not_contains "and none holds a copy of the shared text" 'Dates are ISO-8601.' "$users"
assert_contains     "which lives in the server file only"      'Dates are ISO-8601.' "$(cat "$WIKI_SYS/mcp_servers.json")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
