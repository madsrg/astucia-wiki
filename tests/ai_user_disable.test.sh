#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Switching an AI user off in Admin → AI Users.
#
# There is no one place an AI user is run: about a dozen routes each read users.json
# themselves. So "off" is asked of every one of them through wiki_ai_disabled(), and this
# suite walks them: a chat mention, a token (REST and MCP), /aiJob, Explain, a queued job,
# a scheduled job, Run now, and a workflow's AI action. Every refusal is paired with the
# same request succeeding once the AI is switched back on — a request malformed in some
# unrelated way would otherwise pass for a refusal.
#
# Plus the one subtle rule: a switched-off AI is still *matched* by name, so "#Bot Pro"
# cannot fall through to an enabled "Bot".

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main

# The stub LLM runs on a server of its own. The wiki's `php -S` is single-threaded, so a
# request that calls back into it for an answer (an inline reply, Explain) waits on itself.
LLM_DIR=$WIKI_ROOT/llm; mkdir -p "$LLM_DIR"
cat > "$LLM_DIR/router.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/calls', "call\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop',
    'message' => ['role' => 'assistant', 'content' => 'Answered.']]]]);
return true;
PHP
LLM_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$LLM_DIR" && exec php -S "127.0.0.1:$LLM_PORT" router.php ) > "$LLM_DIR/log" 2>&1 &
LLM_PID=$!
trap 'kill "$LLM_PID" 2>/dev/null; fixture_stop' EXIT
until curl -sf --max-time 2 -X POST "http://127.0.0.1:$LLM_PORT/" -d x >/dev/null 2>&1; do sleep 0.1; done
: > "$LLM_DIR/calls"
CFG="\"ai_config\":{\"provider\":\"openai\",\"api_url\":\"http://127.0.0.1:$LLM_PORT/v1/chat/completions\",\"api_key\":\"k\",\"model\":\"m\"}"
fixture_users "{\"users\":[
 {\"uid\":1,\"name\":\"Admin\",\"role\":\"admin\",\"auth\":\"otp\",\"email\":\"admin@example.com\"},
 {\"uid\":2,\"name\":\"Eddie\",\"role\":\"editor\",\"auth\":\"otp\",\"email\":\"eddie@example.com\"},
 {\"uid\":-7,\"name\":\"Bot\",\"role\":\"editor\",\"is_ai\":true,\"service_token\":\"wk_ai_bot\",$CFG},
 {\"uid\":-8,\"name\":\"Bot Pro\",\"role\":\"editor\",\"is_ai\":true,$CFG}]}"
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"
ADMIN=$WIKI_ROOT/admin.jar; EDIT=$WIKI_ROOT/edit.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EDIT"  "uid=2&name=Eddie&role=editor&auth=otp"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null
fixture_page 'Main/Doc.md' '# Doc'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

toggle() { post_as "$ADMIN" 'api.php?action=admin_toggle_ai_user' "uid=$1&enabled=$2"; }
calls()  { local n; n=$(grep -c . "$LLM_DIR/calls" 2>/dev/null); echo "${n:-0}"; }
# say <text> — post in a fresh thread; reports who got a placeholder and ai_disabled
say() {
    printf '{"messages":[],"nextMessageId":1,"topic":"T"}' > "$WIKI_PAGES/Main/T.chat"
    post_as "$EDIT" 'api.php?action=post_chat_message&space=Main' \
        "file=T.chat&text=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" \
    | python3 -c 'import json,sys
d=json.load(sys.stdin)
ms=(d.get("data") or {}).get("messages",[])
ai=[m["name"] for m in ms if m.get("uid") in (-7,-8) and not m.get("notice")]
note=[m["text"] for m in ms if m.get("notice") == "ai_disabled"]
print("posted=%s ai=%s notice=%s disabled=%s" % (len([m for m in ms if not m.get("notice")]), ",".join(ai) or "-", "|".join(note) or "-", d.get("ai_disabled") or "-"))'
}
mcp() { curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer wk_ai_bot" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$WIKI_URL/mcp.php?space=Main"; }
rest() { curl -s -H "Authorization: Bearer wk_ai_bot" "$WIKI_URL/api.php?action=list_spaces"; }

section 'switching off is admin-only, recorded, and kept'
assert_not_contains "an editor cannot"            '"success":true' "$(post_as "$EDIT" 'api.php?action=admin_toggle_ai_user' 'uid=-7&enabled=0')"
r=$(toggle -7 0)
assert_contains "an administrator can"            '"disabled":true' "$r"
assert_contains "  …it is stored on the record"   '"disabled": true' "$(python3 -c 'import json,sys
print(json.dumps([u for u in json.load(open(sys.argv[1]))["users"] if u["uid"]==-7][0]))' "$WIKI_SYS/users.json" | sed 's/"disabled":true/"disabled": true/')"
assert_contains "  …and audited"                  '"change_type":"ai_user_disabled"' "$(cat "$WIKI_ROOT"/logs/audit/*.log)"
assert_contains "the user list says so"           '"disabled":true' "$(get_as "$EDIT" 'api.php?action=get_user_list' | python3 -c 'import json,sys
print(json.dumps([u for u in json.load(sys.stdin)["data"] if u["uid"]==-7][0]).replace(" ",""))')"
assert_contains "the admin list says so"          '"disabled":true' "$(get_as "$ADMIN" 'api.php?action=admin_get_ai_users' | python3 -c 'import json,sys
print(json.dumps([u for u in json.load(sys.stdin)["data"] if u["uid"]==-7][0]).replace(" ",""))')"

section 'a chat mention'
r=$(say '#Bot hello')
assert_contains "the message is posted"           'posted=1' "$r"
assert_contains "  …nobody is set to answer"      'ai=-' "$r"
assert_contains "  …and the thread is told why"   'notice=AI User Bot is currently disabled.' "$r"
assert_contains "  …in a message marked as a notice, under its name" '"notice": "ai_disabled"' "$(python3 -m json.tool "$WIKI_PAGES/Main/T.chat")"
ctx=$(cd "$WIKI_APP" && php -r 'require "config.php"; require_once "ai_core.php";
$d = json_decode(file_get_contents($argv[1]), true);
echo json_encode(wiki_chat_context_slice($d["messages"], 10));' "$WIKI_PAGES/Main/T.chat")
assert_contains     "an AI later shown the thread sees the question" '#Bot hello' "$ctx"
assert_not_contains "  …but not the notice, which the AI never said" 'currently disabled' "$ctx"
toggle -8 0 > /dev/null; toggle -7 1 > /dev/null
r=$(say '#Bot Pro hello')
assert_contains "a switched-off longer name does not fall through to an enabled shorter one" 'ai=- notice=AI User Bot Pro is currently disabled.' "$r"
r=$(say '#Bot hello')
assert_contains "switched back on, it answers (control)" 'ai=Bot' "$r"
toggle -8 1 > /dev/null

section 'its token, REST and MCP'
toggle -7 0 > /dev/null
assert_not_contains "REST refuses it"             '"success":true' "$(rest)"
assert_eq           "MCP refuses it"              "401" "$(mcp)"
toggle -7 1 > /dev/null
assert_contains     "switched back on, REST works (control)" '"success":true' "$(rest)"
assert_eq           "  …and MCP (control)"        "200" "$(mcp)"

section '/aiJob and Explain'
printf '{"messages":[],"nextMessageId":1,"topic":"T"}' > "$WIKI_PAGES/Main/J.chat"
toggle -7 0 > /dev/null
r=$(post_as "$EDIT" 'api.php?action=queue_agent_job&space=Main' 'file=J.chat&ai_user=Bot&prompt=go')
assert_contains "/aiJob refuses it, by name"      'is switched off' "$r"
r=$(post_as "$EDIT" 'api.php?action=ai_explain&space=Main' 'text=entropy&uid=-7')
assert_contains "Explain will not use it"         'No AI user is available' "$r"
before=$(calls)
r=$(post_as "$EDIT" 'api.php?action=ai_explain&space=Main' 'text=entropy')
assert_eq       "Explain's own pick skips it"     "1" "$(( $(calls) - before ))"
toggle -7 1 > /dev/null
r=$(post_as "$EDIT" 'api.php?action=queue_agent_job&space=Main' 'file=J.chat&ai_user=Bot&prompt=go')
assert_contains "switched back on, /aiJob queues (control)" '"success":true' "$r"

section 'jobs already queued, scheduled, and Run now'
# The job queued just above is still waiting; switch Bot off before the runner gets to it.
toggle -7 0 > /dev/null
printf '%s' '{"jobs":[{"id":"s1","name":"Nightly","enabled":true,"ai_user_uid":-7,"space":"Main","prompt":"x","schedule":{"type":"minutes","interval":5}}]}' \
    > "$WIKI_SYS/agent_jobs.json"
before=$(calls)
out=$(cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1)
assert_contains "a scheduled job is skipped, quietly" "is switched off; skipping job 'Nightly'" "$out"
assert_eq       "  …and no model was called"      "0" "$(( $(calls) - before ))"
q=$(python3 -c 'import json,sys;j=json.load(open(sys.argv[1]))["jobs"][-1];print(j["state"], j.get("error",""))' "$WIKI_SYS/agent_jobs_queue.json")
assert_contains "a queued job fails, saying why"  'error The AI user "Bot" was switched off before this job ran.' "$q"
assert_contains "  …and its placeholder resolves" 'switched off before this job ran' "$(cat "$WIKI_PAGES/Main/J.chat")"
r=$(post_as "$ADMIN" 'api.php?action=admin_run_agent_job' 'id=s1')
assert_contains "Run now refuses it"              'is switched off' "$r"
toggle -7 1 > /dev/null
r=$(post_as "$ADMIN" 'api.php?action=admin_run_agent_job' 'id=s1')
assert_contains "switched back on, Run now starts (control)" '"success":true' "$r"

section 'a workflow AI action is skipped, not failed'
WF=$(post_as "$ADMIN" 'api.php?action=admin_save_workflow' "workflow=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("""{"name":"Review","enabled":true,"space":"Main","debounce":0,"trigger":{"types":["page_updated"]},"actions":[{"type":"ai","ai_uid":-7,"prompt":"review"}]}"""))')" \
    | python3 -c 'import json,sys;print(json.load(sys.stdin)["workflow"]["id"])')
toggle -7 0 > /dev/null
curl -s -b "$EDIT" -X POST --data-binary 'edited' "$WIKI_URL/api.php?action=save&space=Main&file=Doc.md" > /dev/null
(cd "$WIKI_APP" && php run_ai_agent_jobs.php > /dev/null 2>&1)
st=$(python3 -c 'import json,sys
runs=[r for r in json.load(open(sys.argv[1]))["runs"] if r["workflow_id"]==sys.argv[2] and r.get("finished_at")]
r=runs[-1]; print(r["state"], (r.get("results") or [{}])[0].get("detail") or r.get("error"))' "$WIKI_SYS/workflow_queue.json" "$WF")
assert_contains "the run is skipped"              'skipped' "$st"
assert_contains "  …saying why"                   'is switched off' "$st"
assert_eq "  …and it does not count toward the switch-off" "0" "$(python3 -c 'import json,sys
print(([w for w in json.load(open(sys.argv[1]))["workflows"] if w["id"]==sys.argv[2]][0].get("stats") or {}).get("failures") or 0)' "$WIKI_SYS/workflows.json" "$WF")"

section 'saving the form'
conn=$(python3 -c 'import json,sys;print([u for u in json.load(open(sys.argv[1]))["users"] if u["uid"]==-7][0]["ai_config"]["connection_id"])' "$WIKI_SYS/users.json")
save() { post_as "$ADMIN" 'api.php?action=admin_save_ai_user' "uid=-7&name=Bot&role=editor&spaces=null&ai_config=$(python3 -c 'import json,sys,urllib.parse;print(urllib.parse.quote(json.dumps({"connection_id":sys.argv[1],"model":"m"})))' "$conn")$1"; }
state() { python3 -c 'import json,sys;print([u for u in json.load(open(sys.argv[1]))["users"] if u["uid"]==-7][0].get("disabled",False))' "$WIKI_SYS/users.json"; }
save '' > /dev/null
assert_eq "a save that does not mention it keeps it off" "True" "$(state)"
save '&disabled=0' > /dev/null
assert_eq "the form's switch turns it on"   "False" "$(state)"
save '&disabled=1' > /dev/null
assert_eq "  …and off"                      "True"  "$(state)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
