#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# AI-to-AI hand-offs (ai_handoff.php): an AI user's reply that mentions another AI user as
# #Name makes that AI answer next, up to WIKI_AI_HANDOFF_MAX hops in a row. Driven end to end
# against a stand-in LLM whose answer depends on the model, so each AI user says what the
# test needs it to say:
#
#   writer   → mentions #Reviewer        reviewer → mentions #Writer   (they ping-pong)
#   coder    → #Reviewer only in code    selfish  → mentions itself
#   tooff    → mentions the disabled AI  tobg     → mentions the background AI
#   bg       → mentions #Quiet           quiet    → mentions nobody
#
# The stand-in runs on its own server: the wiki's php -S is single-threaded, and an inline
# reply calling back into it would wait on itself.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
fixture_space Main

LLM_DIR=$WIKI_ROOT/llm; mkdir -p "$LLM_DIR"
cat > "$LLM_DIR/router.php" <<'PHP'
<?php
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$model = $in['model'] ?? '';
file_put_contents(__DIR__ . '/calls', $model . "\n", FILE_APPEND);
$say = [
    'writer'   => 'Draft written. #Reviewer please check it.',
    'reviewer' => 'Reviewed. Back to you, #Writer.',
    'coder'    => "Write `#Reviewer` to ask for a review, like this:\n```\n#Reviewer check this\n```",
    'selfish'  => 'Noted, #Selfish will remember.',
    'tooff'    => 'Passing this to #Sleeper.',
    'tobg'     => 'This needs the long-running one: #Bgbot please do it.',
    'bg'       => 'Done in the background. #Quiet, over to you.',
    'quiet'    => 'All finished.',
][$model] ?? 'OK';
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $say]]]]);
return true;
PHP
LLM_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$LLM_DIR" && exec php -S "127.0.0.1:$LLM_PORT" router.php ) > "$LLM_DIR/log" 2>&1 &
LLM_PID=$!
trap 'kill "$LLM_PID" 2>/dev/null; fixture_stop' EXIT
until curl -sf --max-time 2 -X POST "http://127.0.0.1:$LLM_PORT/" -d '{}' >/dev/null 2>&1; do sleep 0.1; done
: > "$LLM_DIR/calls"

printf '[{"id":"c1","name":"Stub","provider":"openai","api_url":"http://127.0.0.1:%s/v1/chat/completions","api_key":"k","extra_headers":[]}]' "$LLM_PORT" > "$WIKI_SYS/llm_connections.json"
ai() { printf '{"uid":%s,"name":"%s","role":"editor","is_ai":true%s,"ai_config":{"connection_id":"c1","model":"%s","max_tokens":200%s}}' "$1" "$2" "$4" "$3" "${5:-}"; }
fixture_users "{\"schema\":3,\"users\":[
 {\"uid\":1,\"name\":\"Admin\",\"role\":\"admin\",\"auth\":\"otp\",\"email\":\"admin@example.com\"},
 {\"uid\":2,\"name\":\"Eddie\",\"role\":\"editor\",\"auth\":\"otp\",\"email\":\"eddie@example.com\"},
 $(ai -11 Writer writer ''),
 $(ai -12 Reviewer reviewer ''),
 $(ai -13 Coder coder ''),
 $(ai -14 Selfish selfish ''),
 $(ai -15 Passer tooff ''),
 $(ai -16 Sleeper quiet ',"disabled":true'),
 $(ai -17 Router tobg ''),
 $(ai -18 Bgbot bg '' ',"always_background":true'),
 $(ai -19 Quiet quiet '')]}"
ADMIN=$WIKI_ROOT/admin.jar; EDIT=$WIKI_ROOT/edit.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EDIT"  "uid=2&name=Eddie&role=editor&auth=otp"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null

CHAT=$WIKI_PAGES/Main/T.chat
# say <text> — Eddie posts in a fresh thread. php -S has no fastcgi_finish_request, so the
# request returns only once every inline answer, hand-offs included, is in the file.
say() {
    printf '{"messages":[],"nextMessageId":1,"topic":"T"}' > "$CHAT"
    : > "$LLM_DIR/calls"
    post_as "$EDIT" 'api.php?action=post_chat_message&space=Main' \
        "file=T.chat&text=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")" > /dev/null
}
# The thread as name[hop]:state, one per message — hop from the handoff record, state
# pending/notice/job when it applies.
thread() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $o = [];
    foreach ($d["messages"] as $m) {
        $s = $m["name"];
        if (isset($m["handoff"])) $s .= "[" . $m["handoff"]["hop"] . "]";
        if (!empty($m["pending"])) $s .= ":pending";
        if (!empty($m["notice"])) $s .= ":notice";
        if (!empty($m["job_id"]) && !empty($m["pending"])) $s .= ":job";   // a delivered reply keeps its job_id
        $o[] = $s;
    } echo implode(",", $o);' "$CHAT"; }
field() { php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d["messages"] as $m) if ($m["id"] == $argv[2]) { echo json_encode($m["handoff"][$argv[3]] ?? null); exit; }' "$CHAT" "$1" "$2"; }
calls() { grep -c . "$LLM_DIR/calls" 2>/dev/null || echo 0; }
queue() { php -r '$d = json_decode(@file_get_contents($argv[1]), true) ?: []; foreach ($d["jobs"] ?? [] as $j) if (($j["state"] ?? "") === "queued") echo $j["ai_user_name"], "<", $j["requested_by"]["name"], "@", $j["handoff"]["hop"] ?? 0, " ";' "$WIKI_SYS/agent_jobs_queue.json"; }
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }

section 'a hand-off, and the hop limit'
say '#Writer please draft the notes'
assert_eq "the writer answers, hands to the reviewer, and back — three hops, then it stops" \
    'Eddie,Writer,Reviewer[1],Writer[2],Reviewer[3]' "$(thread)"
assert_eq "four model calls, no more" '4' "$(calls)"
assert_eq "the last hop is the limit" '3' "$(field 5 hop)"
assert_eq "each hand-off names who handed off" '{"uid":-12,"name":"Reviewer"}' "$(field 4 from)"
assert_eq "and who started the chain"          '{"uid":2,"name":"Eddie"}' "$(field 5 origin)"
assert_not_contains "nothing left pending" ':pending' "$(thread)"

section 'not a hand-off'
say '#Coder how do I ask for a review?'
assert_eq "a #Name inside code is an example" 'Eddie,Coder' "$(thread)"
say '#Quiet hello'
assert_eq "a reply that mentions nobody"      'Eddie,Quiet' "$(thread)"
say '#Selfish remember this'
assert_eq "an AI never hands off to itself"   'Eddie,Selfish' "$(thread)"
assert_eq "one call each"                     '1' "$(calls)"

section 'a switched-off AI'
say '#Passer route this'
assert_eq "the thread says why nobody answers" 'Eddie,Passer,Sleeper:notice' "$(thread)"
assert_eq "and no model is called for it"      '1' "$(calls)"

section 'to an AI that works in the background'
say '#Router who should do this?'
assert_eq "its answer is queued: a placeholder with a job" 'Eddie,Router,Bgbot[1]:pending:job' "$(thread)"
assert_eq "queued for the person who started it, one hop in" 'Bgbot<Eddie@1 ' "$(queue)"

section 'from a background reply'
out=$(runner)
assert_contains "the runner hands off after delivering" 'hand-off from Bgbot to Quiet (hop 2)' "$out"
assert_eq "always queued from there, still for Eddie" 'Quiet<Eddie@2 ' "$(queue)"
assert_eq "the thread" 'Eddie,Router,Bgbot[1],Quiet[2]:pending:job' "$(thread)"
runner > /dev/null
assert_eq "Quiet answers and the chain ends" 'Eddie,Router,Bgbot[1],Quiet[2]' "$(thread)"
assert_eq "nothing more queued" '' "$(queue)"

section 'AI users are told'
r=$(get_as "$ADMIN" 'api.php?action=admin_ai_builtin_instructions&uid=-11&space=Main')
assert_contains "that a #Name hands off"  'hands the conversation to it' "$r"
assert_contains "and the limit"           'Hand-offs stop after 3 in a row' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
