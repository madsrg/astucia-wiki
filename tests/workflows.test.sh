#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Workflows: a write queues a run, the cron runner performs it.
#
# Driven the way they are used — real api.php writes from a browser session, then a real
# run_ai_agent_jobs.php tick — because every promise here is about the path between the
# two: that the indexer funnel sees each kind of write, that a burst of saves is one run,
# that a workflow cannot trigger itself, and that a write it makes looks like any other.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_space Other

# A stub LLM that records what it was sent, so the AI action's prompt can be read back.
cat > "$WIKI_APP/__test_llm.php" <<'PHP'
<?php
file_put_contents(dirname(__DIR__) . '/llm-request.json', file_get_contents('php://input'));
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop',
    'message' => ['role' => 'assistant', 'content' => 'Summary: all good.']]]]);
PHP

fixture_users "{\"users\":[
 {\"uid\":1,\"name\":\"Admin\",\"role\":\"admin\",\"auth\":\"otp\",\"email\":\"admin@example.com\"},
 {\"uid\":2,\"name\":\"Eddie\",\"role\":\"editor\",\"auth\":\"otp\",\"email\":\"eddie@example.com\"},
 {\"uid\":-7,\"name\":\"Bot\",\"role\":\"editor\",\"is_ai\":true,\"service_token\":\"wk_ai_testbot\",
  \"ai_config\":{\"provider\":\"openai\",\"api_url\":\"$WIKI_URL/__test_llm.php\",\"api_key\":\"k\",\"model\":\"m\"}}]}"

ADMIN=$WIKI_ROOT/admin.jar; EDIT=$WIKI_ROOT/edit.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EDIT"  "uid=2&name=Eddie&role=editor&auth=otp"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null

fixture_page 'Main/Team.chat' '{"messages":[],"nextMessageId":1,"topic":"Team"}'
fixture_page 'Main/Docs/a.md' 'Alpha'
fixture_page 'Main/Docs/b.md' 'Bravo'
fixture_page 'Main/Notes/n.md' 'Note'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

# save_page <jar> <space> <path> <body>
save_page() { curl -s -b "$1" -c "$1" -X POST --data-binary "$4" \
    "$WIKI_URL/api.php?action=save&space=$2&file=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$3")"; }
# new_wf <json> — save as admin, print the id
new_wf() { post_as "$ADMIN" 'api.php?action=admin_save_workflow' \
    "workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")"; }
wf_id() { python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("workflow",{}).get("id","") or "ERR:"+d.get("message",""))'; }
toggle() { post_as "$ADMIN" 'api.php?action=admin_toggle_workflow' "id=$1&enabled=$2" > /dev/null; }
queue() { python3 - "$WIKI_SYS/workflow_queue.json" "$@" <<'PY'
import json, sys
try: runs = json.load(open(sys.argv[1]))['runs']
except Exception: runs = []
wf = sys.argv[2]; what = sys.argv[3]
mine = [r for r in runs if r.get('workflow_id') == wf]
if what == 'queued':  print(sum(1 for r in mine if r['state'] == 'queued'))
elif what == 'events': print(','.join(str(r.get('events')) for r in mine if r['state'] == 'queued'))
elif what == 'states': print(','.join(sorted(r['state'] for r in mine)))
elif what == 'last':
    done = sorted([r for r in mine if r.get('finished_at')], key=lambda r: r['finished_at'])
    print(json.dumps(done[-1] if done else {}))
PY
}
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }
# The thread's messages as plain text — .chat is JSON, where '/' is stored as '\/'.
chat_text() { python3 -c 'import json,sys;[print(m["name"]+": "+m["text"]) for m in json.load(open(sys.argv[1]))["messages"]]' "$WIKI_PAGES/Main/Team.chat"; }
tags_of() { python3 -c '
import json,sys
for e in json.load(open(sys.argv[1])).values():
    if e["path"] == sys.argv[2]: print(",".join(e.get("tags", [])))' "$WIKI_PAGES/Main/index.json" "$1"; }

section 'administrators only'
assert_contains "an editor cannot list them"  'Admin access required' "$(get_as "$EDIT" 'api.php?action=admin_get_workflows')"
assert_contains "nor create one"              'Admin access required' \
    "$(post_as "$EDIT" 'api.php?action=admin_save_workflow' 'workflow={}')"
assert_contains "an admin can (control)"      '"success":true' "$(get_as "$ADMIN" 'api.php?action=admin_get_workflows')"
assert_contains "a definition is validated"   'needs a name' "$(new_wf '{"trigger":{"type":"page_updated"},"actions":[]}')"
assert_contains "an action is required"       'at least one action' \
    "$(new_wf '{"name":"x","space":"Main","trigger":{"type":"page_updated"},"actions":[]}')"

section 'a save queues a run; a burst of saves is one run'
TAGGER=$(new_wf '{"name":"Tag reviewed","enabled":true,"space":"Main","debounce":0,
  "trigger":{"type":"page_updated"},"filters":{"folder":"Docs"},
  "actions":[{"type":"tag","add":["reviewed"]}]}' | wf_id)
assert_not_contains "saved" 'ERR' "$TAGGER"
save_page "$EDIT" Main Docs/a.md 'Alpha 2' > /dev/null
assert_eq "one run queued"                    "1" "$(queue "$TAGGER" queued)"
save_page "$EDIT" Main Docs/a.md 'Alpha 3' > /dev/null
assert_eq "a second save folds into it"       "1" "$(queue "$TAGGER" queued)"
assert_eq "  …counting both events"           "2" "$(queue "$TAGGER" events)"
save_page "$EDIT" Main Notes/n.md 'Note 2' > /dev/null
assert_eq "outside the folder: nothing"       "1" "$(queue "$TAGGER" queued)"
r=$(runner)
assert_contains "the runner reports it"       "'Tag reviewed' on Docs/a.md: ok" "$r"
assert_eq "the page is tagged"                "reviewed" "$(tags_of Docs/a.md)"

section 'a workflow never triggers itself, and chains stop'
# The tag it just added is an index write; the tagger listens for updates, not tags,
# so build a pair that would ping-pong: tag → front matter → (update) → tag …
FM=$(new_wf '{"name":"Mark done","enabled":true,"space":"Main","debounce":0,
  "trigger":{"type":"tag_added","tag":"reviewed"},
  "actions":[{"type":"frontmatter","field":"status","value":"done"}]}' | wf_id)
save_page "$EDIT" Main Docs/b.md 'Bravo 2' > /dev/null
runner > /dev/null    # tagger: tags b → queues Mark done
assert_eq "the tag queued the second workflow" "1" "$(queue "$FM" queued)"
runner > /dev/null    # Mark done: writes front matter → an update the tagger would match
assert_contains "front matter was written"    'status: done' "$(cat "$WIKI_PAGES/Main/Docs/b.md")"
assert_eq "…and the tagger did not fire on it" "0" "$(queue "$TAGGER" queued)"
assert_contains "the write is credited to the workflow" '"name": "Workflow: Mark done"' "$(cat "$WIKI_PAGES/Main/index.json")"

section 'a rename is a rename, not a delete'
toggle "$TAGGER" 0; toggle "$FM" 0
REN=$(new_wf '{"name":"Renamed","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_renamed"},
  "actions":[{"type":"tag","add":["moved"]}]}' | wf_id)
DEL=$(new_wf '{"name":"Deleted","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_deleted"},
  "actions":[{"type":"chat","chat":"Team.chat","text":"{{path}} was deleted by {{actor}}"}]}' | wf_id)
post_as "$EDIT" 'api.php?action=move&space=Main' 'old_path=Docs/b.md&new_path=Docs/b2.md' > /dev/null
assert_eq "the rename queued"                 "1" "$(queue "$REN" queued)"
assert_eq "the delete workflow did not"       "0" "$(queue "$DEL" queued)"
post_as "$EDIT" 'api.php?action=delete&space=Main' 'path=Notes/n.md' > /dev/null
assert_eq "a real delete does"                "1" "$(queue "$DEL" queued)"
runner > /dev/null
assert_eq "the renamed page was tagged"       "reviewed,moved" "$(tags_of Docs/b2.md)"
assert_contains "the chat names who and what" 'Notes/n.md was deleted by Eddie' "$(chat_text)"
toggle "$REN" 0; toggle "$DEL" 0

section 'a file only being given an id is not a page being created'
CRE=$(new_wf '{"name":"Created","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_created"},
  "actions":[{"type":"tag","add":["new"]}]}' | wf_id)
fixture_page 'Main/Docs/dropped.md' 'Arrived from outside'
get_as "$EDIT" 'api.php?action=list&space=Main' > /dev/null
assert_eq "listing a new file queues nothing" "0" "$(queue "$CRE" queued)"
post_as "$EDIT" 'api.php?action=create_file&space=Main' 'path=Docs/c.md' > /dev/null
assert_eq "creating a page does"              "1" "$(queue "$CRE" queued)"
toggle "$CRE" 0
assert_eq "switching it off drops nothing already queued" "1" "$(queue "$CRE" queued)"
runner > /dev/null
assert_contains "…but the run is skipped"     'skipped' "$(queue "$CRE" states)"
save_page "$EDIT" Main Docs/c.md 'x' > /dev/null
post_as "$EDIT" 'api.php?action=create_file&space=Main' 'path=Docs/d.md' > /dev/null
assert_eq "switched off, nothing new queues"  "0" "$(queue "$CRE" queued)"

section 'front matter: fires on the transition, not on every save'
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_edit' 'space_name=Main&mode=manual' > /dev/null
fixture_page 'Main/Docs/policy.md' $'---\nstatus: draft\n---\nPolicy'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null
APPR=$(new_wf '{"name":"Approved","enabled":true,"space":"Main","debounce":0,
  "trigger":{"type":"fm_changed","field":"status","value":"approved"},
  "actions":[{"type":"chat","chat":"Team.chat","text":"{{page}}: {{old_value}} -> {{new_value}} ({{actor}})"}]}' | wf_id)
save_page "$EDIT" Main Docs/policy.md 'Policy v2' > /dev/null
assert_eq "an edit that leaves it alone: nothing" "0" "$(queue "$APPR" queued)"
setfm() { post_as "$EDIT" 'api.php?action=set_frontmatter&space=Main' \
    "file=Docs/policy.md&updates=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "{\"status\":\"$1\"}")" > /dev/null; }
setfm review
assert_eq "to another value: nothing"         "0" "$(queue "$APPR" queued)"
setfm approved
assert_eq "to the value it waits for: a run"  "1" "$(queue "$APPR" queued)"
runner > /dev/null
assert_contains "it saw where it came from"   'policy: review -> approved (Eddie)' "$(chat_text)"
save_page "$EDIT" Main Docs/policy.md 'Policy v3' > /dev/null
assert_eq "staying approved does not fire again" "0" "$(queue "$APPR" queued)"
toggle "$APPR" 0

section 'an AI instruction gets the page, and its reply can go to a thread'
AIWF=$(new_wf '{"name":"Summarise","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_updated"},
  "filters":{"folder":"Docs","exclude_ai":true},
  "actions":[{"type":"ai","ai_uid":-7,"prompt":"Summarise {{page}} for the team.","chat":"Team.chat"}]}' | wf_id)
save_page "$EDIT" Main Docs/a.md 'The quarterly numbers are in.' > /dev/null
runner > /dev/null
req=$(cat "$WIKI_ROOT/llm-request.json" 2>/dev/null)
assert_contains "the instruction, rendered"   'Summarise a for the team.' "$req"
assert_contains "the workflow says why"       'wiki workflow' "$req"
assert_contains "the page content is included" 'The quarterly numbers are in.' "$req"
assert_contains "the reply is posted"         'Summary: all good.' "$(chat_text)"
assert_eq "the run is recorded ok"            "ok" "$(queue "$AIWF" last | python3 -c 'import json,sys;print(json.load(sys.stdin).get("state"))')"
# An AI user's own edit is excluded by the filter — written with its token, as it would.
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_testbot" --data-binary 'AI wrote this' \
        "$WIKI_URL/api.php?action=save&space=Main&file=Docs/a.md")
assert_contains "the AI user's save went through (control)" '"success":true' "$r"
assert_eq "an AI user's edit is filtered out" "0" "$(queue "$AIWF" queued)"
save_page "$EDIT" Main Docs/a.md 'A person again' > /dev/null
assert_eq "…while a person's still queues (control)" "1" "$(queue "$AIWF" queued)"
post_as "$ADMIN" 'api.php?action=admin_delete_workflow' "id=$AIWF" > /dev/null
assert_eq "deleting it drops its queued run"  "0" "$(queue "$AIWF" queued)"

section 'rate limit and automatic switch-off'
LIM=$(new_wf '{"name":"Limited","enabled":true,"space":"Main","debounce":0,"max_per_hour":1,
  "trigger":{"type":"page_updated"},"filters":{"folder":"Docs"},"actions":[{"type":"tag","add":["x"]}]}' | wf_id)
save_page "$EDIT" Main Docs/a.md 'r1' > /dev/null; save_page "$EDIT" Main Docs/c.md 'r2' > /dev/null
runner > /dev/null
assert_eq "one ran, one hit the limit"        "ok,skipped" "$(queue "$LIM" states)"
toggle "$LIM" 0
BAD=$(new_wf '{"name":"Broken","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_updated"},
  "actions":[{"type":"chat","chat":"Gone.chat","text":"hi"}]}' | wf_id)
for p in a c d policy b2; do save_page "$EDIT" Main "Docs/$p.md" "fail $p" > /dev/null; done
runner > /dev/null
wfs=$(get_as "$ADMIN" 'api.php?action=admin_get_workflows')
assert_contains "five failures switch it off" 'failed runs in a row' "$wfs"
assert_eq "…and it is off" "False" "$(printf '%s' "$wfs" | python3 -c '
import json,sys
print([w for w in json.load(sys.stdin)["workflows"] if w["name"]=="Broken"][0]["enabled"])')"

section 'read-only spaces'
RO=$(new_wf '{"name":"RO","enabled":true,"space":"Main","debounce":0,"trigger":{"type":"page_updated"},
  "actions":[{"type":"tag","add":["ro"]}]}' | wf_id)
save_page "$EDIT" Main Docs/a.md 'before freezing' > /dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=1' > /dev/null
runner > /dev/null
assert_contains "a write action is skipped"   'read-only' "$(queue "$RO" last)"
assert_not_contains "and nothing was written" 'ro' "$(tags_of Docs/a.md | tr ',' '\n' | grep -x ro)"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=0' > /dev/null
toggle "$RO" 0

section 'the Test button renders without doing'
prev=$(post_as "$ADMIN" 'api.php?action=admin_test_workflow' "test_space=Main&test_path=Docs/a.md&workflow=$(python3 -c 'import urllib.parse,json;print(urllib.parse.quote(json.dumps({"name":"Mail","space":"Main","trigger":{"type":"page_updated"},"actions":[{"type":"email","to_users":[2],"subject":"{{page}} changed","body":"See {{url}}"}]})))')")
assert_contains "the subject is rendered"     '"subject":"a changed"' "$prev"
assert_contains "the link is the stable id"   'index.php?pageid=' "$prev"
assert_contains "the recipient is resolved"   'eddie@example.com' "$prev"
assert_contains "and it says mail is off"     'mail_not_configured' "$prev"
prev=$(post_as "$ADMIN" 'api.php?action=admin_test_workflow' "test_space=Main&test_path=Notes/x.md&workflow=$(python3 -c 'import urllib.parse,json;print(urllib.parse.quote(json.dumps({"name":"T","space":"Main","trigger":{"type":"page_updated"},"filters":{"folder":"Docs"},"actions":[{"type":"tag","add":["a"]}]})))')")
assert_contains "a page out of scope says which filter" '"filter_miss":"folder"' "$prev"

section 'Run now: the saved workflow, for real, on one page'
MAN=$(new_wf '{"name":"Manual","enabled":false,"space":"Main","trigger":{"type":"page_updated"},
  "filters":{"folder":"Elsewhere"},"actions":[{"type":"tag","add":["manual-run"]}]}' | wf_id)
r=$(post_as "$EDIT" 'api.php?action=admin_run_workflow' "id=$MAN&test_space=Main&test_path=Docs/a.md")
assert_contains "editors cannot"                  'Admin access required' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_run_workflow' "id=$MAN&test_space=Main&test_path=Docs/nope.md")
assert_contains "a page that does not exist is refused" 'does not exist' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_run_workflow' "id=wf_unsaved&test_space=Main&test_path=Docs/a.md")
assert_contains "only a saved workflow runs"      'Save the workflow' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_run_workflow' "id=$MAN&test_space=Main&test_path=Docs/a.md")
# Switched off and the page outside its folder: an explicit run ignores both.
assert_contains "it ran at once, switched off and out of scope" '"state":"ok"' "$r"
assert_contains "the action really happened"      'manual-run' "$(tags_of Docs/a.md)"
assert_contains "it is in History, marked manual" '"manual": true' "$(cat "$WIKI_SYS/workflow_queue.json")"
assert_not_contains "and it is not a real run's stats" 'last_run' \
    "$(get_as "$ADMIN" 'api.php?action=admin_get_workflows' | python3 -c 'import json,sys;print([w for w in json.load(sys.stdin)["workflows"] if w["name"]=="Manual"][0].get("stats"))')"
AIMAN=$(new_wf '{"name":"AI manual","enabled":false,"space":"Main","trigger":{"type":"page_updated"},
  "actions":[{"type":"ai","ai_uid":-7,"prompt":"Look at {{page}}","chat":"Team.chat"}]}' | wf_id)
r=$(post_as "$ADMIN" 'api.php?action=admin_run_workflow' "id=$AIMAN&test_space=Main&test_path=Docs/a.md")
assert_contains "an AI action is queued, not run in the request" '"state":"queued"' "$r"
runner > /dev/null
assert_eq "…and the runner does it"               "ok" "$(queue "$AIMAN" last | python3 -c 'import json,sys;print(json.load(sys.stdin).get("state"))')"

section 'renaming a space carries its workflows'
post_as "$ADMIN" 'api.php?action=rename_space' 'old_name=Other&new_name=Elsewhere' > /dev/null
OTH=$(new_wf '{"name":"Other space","enabled":false,"space":"Elsewhere","trigger":{"type":"page_updated"},"actions":[{"type":"tag","add":["a"]}]}' | wf_id)
assert_not_contains "a renamed space is a valid scope" 'ERR' "$OTH"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
