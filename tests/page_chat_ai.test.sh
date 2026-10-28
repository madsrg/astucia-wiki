#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# An AI can talk in a page's own chat, and is told which one that is.
#
# Before this, a page chat came into being only when a person clicked the panel open, and
# no AI tool could create one — so "give feedback on every page that is saved" had nowhere
# on the page to put it. Three pieces, each covered here:
#
#   - **`wiki_post_chat`**: posts into a `.chat`, or into a `.md` page's own chat, creating
#     that one thread if needed. It is a write tool, so every guard the others have —
#     editor role, frozen Space, the skills folder — must hold for it too; each refusal is
#     paired with a success on the same shape of request.
#   - **The workflow option**: an AI or chat action can post in the triggering page's own
#     chat, created on first use, and the thread path takes `{{…}}` variables.
#   - **The prompts** state the page id and the page chat, so the model can link the page
#     by id and knows where its reply is going.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_space Frozen

# A stub LLM that records what it was sent and always answers the same thing.
cat > "$WIKI_APP/__test_llm.php" <<'PHP'
<?php
file_put_contents(dirname(__DIR__) . '/llm-request.json', file_get_contents('php://input'));
header('Content-Type: application/json');
echo json_encode(['choices' => [['finish_reason' => 'stop',
    'message' => ['role' => 'assistant', 'content' => 'Feedback: reads well.']]]]);
PHP

fixture_users "{\"users\":[
 {\"uid\":1,\"name\":\"Admin\",\"role\":\"admin\",\"auth\":\"otp\",\"email\":\"admin@example.com\"},
 {\"uid\":-7,\"name\":\"Bot\",\"role\":\"editor\",\"is_ai\":true,\"service_token\":\"wk_ai_bot\",
  \"ai_config\":{\"provider\":\"openai\",\"api_url\":\"$WIKI_URL/__test_llm.php\",\"api_key\":\"k\",\"model\":\"m\"}},
 {\"uid\":-8,\"name\":\"Peeker\",\"role\":\"reader\",\"is_ai\":true,\"service_token\":\"wk_ai_peeker\",
  \"ai_config\":{\"provider\":\"openai\",\"api_url\":\"$WIKI_URL/__test_llm.php\",\"api_key\":\"k\",\"model\":\"m\"}},
 {\"uid\":-9,\"name\":\"Later\",\"role\":\"editor\",\"is_ai\":true,
  \"ai_config\":{\"provider\":\"openai\",\"api_url\":\"$WIKI_URL/__test_llm.php\",\"api_key\":\"k\",\"model\":\"m\",\"always_background\":true}}]}"
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"

ADMIN=$WIKI_ROOT/admin.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null

thread() { printf '{"topic":"%s","nextMessageId":2,"messages":[{"id":1,"uid":1,"name":"Admin","text":"%s","timestamp":"2026-01-01T10:00:00+00:00"}]}' "$1" "$2" > "$WIKI_PAGES/$3"; }
fixture_page 'Main/Plan.md'  '# Plan'
fixture_page 'Main/Has.md'   '# Has a chat'
thread 'Has' 'first message' 'Main/Has.chat'
thread 'Team' 'team talk' 'Main/Team.chat'
fixture_page 'Main/data.json' '{"a":1}'
fixture_page 'Main/Docs/Spec.md' '# Spec body text'
fixture_page 'Main/Docs/table.list' '{"columns":[],"rows":[]}'
fixture_page 'Frozen/Doc.md' '# Doc'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main'   > /dev/null
get_as "$ADMIN" 'api.php?action=indexfiles&space=Frozen' > /dev/null

# post <token> <target> <message> [space] — the tool result's text
post() {
    curl -s -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        -d "$(python3 -c 'import json,sys; print(json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_post_chat","arguments":{"target":sys.argv[1],"message":sys.argv[2]}}}))' "$2" "$3")" \
        "$WIKI_URL/mcp.php?space=${4:-Main}" \
    | python3 -c 'import json,sys
d=json.load(sys.stdin); r=d.get("result") or {}
print(" ".join(c.get("text","") for c in r.get("content",[])) or json.dumps(d))'
}
# msgs <space/path.chat> — "name(uid): text" per message
msgs() { python3 -c 'import json,sys
try: d=json.load(open(sys.argv[1]))
except Exception: print("(no thread)"); raise SystemExit
[print("%s(%s): %s" % (m.get("name"), m.get("uid"), m.get("text"))) for m in d["messages"]]' "$WIKI_PAGES/$1"; }
id_of() { python3 -c 'import json,sys
ix=json.load(open(sys.argv[1]))
print(next((k for k,v in ix.items() if v.get("path")==sys.argv[2]),""))' "$WIKI_PAGES/${2:-Main}/index.json" "$1"; }
audit() { cat "$WIKI_ROOT"/logs/audit/*.log 2>/dev/null | grep -F '"tool":"wiki_post_chat"'; }

# ── the tool ─────────────────────────────────────────────────────────────────
section 'wiki_post_chat is offered'
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_bot" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$WIKI_URL/mcp.php?space=Main")
assert_contains "tools/list names it" '"wiki_post_chat"' "$r"

section "posting on a page with no chat creates the page's chat"
assert_file_missing "Plan.md starts without a chat" "$WIKI_PAGES/Main/Plan.chat"
r=$(post wk_ai_bot 'Plan.md' 'Nice plan.')
assert_contains "it says it posted, and created the thread" 'Posted in Plan.chat (the page had no chat yet' "$r"
assert_contains "the message is there, as the AI user" 'Bot(-7): Nice plan.' "$(msgs Main/Plan.chat)"
pid=$(id_of Plan.chat)
[ -n "$pid" ] && _pass "the new thread is indexed, so it has an id" || _fail "the new thread is indexed, so it has an id" "no id"
assert_contains "it starts as the panel makes one: messages not committed" '"git_commit": false' "$(cat "$WIKI_PAGES/Main/Plan.chat")"
assert_eq "its creation is audited, once" "1" "$(audit | grep -c '"object":"Plan.chat"')"
assert_contains "  …as a create" '"action":"create"' "$(audit | grep -F '"object":"Plan.chat"')"

r=$(post wk_ai_bot 'Plan.md' 'One more thing.')
assert_contains "a second post goes into the same thread" 'Posted in Plan.chat.' "$r"
assert_not_contains "  …without creating anything" 'created' "$r"
assert_eq "  …so it now holds two messages" "2" "$(msgs Main/Plan.chat | wc -l | tr -d ' ')"
assert_eq "  …and the id did not change" "$pid" "$(id_of Plan.chat)"
assert_eq "posting is not audited, only creating" "1" "$(audit | grep -c '"object":"Plan.chat"')"

section 'an existing thread is appended to, not replaced'
post wk_ai_bot 'Has.md' 'Appended.' > /dev/null
assert_contains "the old message is kept" 'Admin(1): first message' "$(msgs Main/Has.chat)"
assert_contains "the new one follows"     'Bot(-7): Appended.' "$(msgs Main/Has.chat)"
r=$(post wk_ai_bot 'Team.chat' 'Hello team.')
assert_contains "a .chat target works directly" 'Posted in Team.chat.' "$r"
assert_contains "  …and the message landed" 'Bot(-7): Hello team.' "$(msgs Main/Team.chat)"

section 'what it refuses'
r=$(post wk_ai_bot 'Nope.chat' 'x')
assert_contains "a .chat that does not exist" 'chat thread not found' "$r"
assert_file_missing "  …and none is made up" "$WIKI_PAGES/Main/Nope.chat"
r=$(post wk_ai_bot 'Ghost.md' 'x')
assert_contains "a page that does not exist" 'does not exist' "$r"
assert_file_missing "  …gets no chat" "$WIKI_PAGES/Main/Ghost.chat"
assert_contains "a JSON page has no page chat" 'target must be a .md page' "$(post wk_ai_bot 'data.json' 'x')"
assert_contains "an empty message" '"message" is required' "$(post wk_ai_bot 'Plan.md' '   ')"
long=$(python3 -c 'print("x"*20001)')
assert_contains "a message over the ceiling" 'longer than 20000' "$(post wk_ai_bot 'Plan.md' "$long")"
assert_contains "a reader-role AI user" 'read-only (reader) role' "$(post wk_ai_peeker 'Plan.md' 'x')"
assert_contains "a memory page" 'memory pages have no chat' "$(post wk_ai_bot 'memory/fact.md' 'x')"
mkdir -p "$WIKI_PAGES/Main/skills/s"; printf -- '---\nname: s\ndescription: d\n---\nbody\n' > "$WIKI_PAGES/Main/skills/s/SKILL.md"
r=$(post wk_ai_bot 'skills/s/SKILL.md' 'x')
assert_contains "a skill (admin-only, whatever the role)" 'only an administrator can change' "$r"
assert_file_missing "  …and no chat appears beside it" "$WIKI_PAGES/Main/skills/s/SKILL.chat"

post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Frozen&readonly=1' > /dev/null
r=$(post wk_ai_bot 'Doc.md' 'x' Frozen)
assert_contains "a frozen Space" 'is read-only' "$r"
assert_file_missing "  …creates nothing there" "$WIKI_PAGES/Frozen/Doc.chat"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Frozen&readonly=0' > /dev/null
assert_contains "  …and the same post works once it thaws (control)" 'Posted in Doc.chat' "$(post wk_ai_bot 'Doc.md' 'thawed' Frozen)"

# ── workflows ────────────────────────────────────────────────────────────────
save_page() { curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary "$3" \
    "$WIKI_URL/api.php?action=save&space=$1&file=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$2")"; }
new_wf() { post_as "$ADMIN" 'api.php?action=admin_save_workflow' \
    "workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")"; }
wf_id() { python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("workflow",{}).get("id","") or "ERR:"+d.get("message",""))'; }
# last_run <workflow id> [path] — by path as well, since several runs finish in one second
last_run() { python3 - "$WIKI_SYS/workflow_queue.json" "$1" "${2:-}" <<'PY'
import json, sys
runs = [r for r in json.load(open(sys.argv[1]))['runs'] if r.get('workflow_id') == sys.argv[2] and r.get('finished_at')
        and (not sys.argv[3] or r.get('path') == sys.argv[3])]
runs.sort(key=lambda r: r['finished_at'])
print(json.dumps(runs[-1] if runs else {}))
PY
}
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }
# What the stub LLM was last sent, as plain text: every message's content, decoded.
sent() { python3 -c 'import json,sys
try: d=json.load(open(sys.argv[1]))
except Exception: raise SystemExit
for m in d.get("messages",[]):
    c=m.get("content")
    print(c if isinstance(c,str) else json.dumps(c))' "$WIKI_ROOT/llm-request.json"; }
# preview <workflow json> <path> — the Test preview, as admin
preview() { post_as "$ADMIN" 'api.php?action=admin_test_workflow' \
    "workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")&test_space=Main&test_path=$2"; }
toggle() { post_as "$ADMIN" 'api.php?action=admin_toggle_workflow' "id=$1&enabled=$2" > /dev/null; }

section "a workflow's AI reply can go into the page's own chat"
FB=$(new_wf '{"name":"Feedback","enabled":true,"space":"Main","debounce":0,
  "trigger":{"types":["page_created","page_updated"]},"filters":{"folder":"Docs","exclude_ai":true},
  "actions":[{"type":"ai","ai_uid":-7,"prompt":"Give feedback on {{page}}.","chat":"ignored.chat","page_chat":true}]}' | wf_id)
case "$FB" in ERR:*|'') _fail "the workflow saves" "$FB" ;; *) _pass "the workflow saves" ;; esac
stored=$(python3 -c 'import json,sys
for w in json.load(open(sys.argv[1]))["workflows"]:
    if w["id"]==sys.argv[2]: print(json.dumps(w["actions"][0]))' "$WIKI_SYS/workflows.json" "$FB")
assert_contains "the choice is stored"                 '"page_chat": true' "$stored"
assert_contains "  …and the path beside it is dropped" '"chat": ""' "$stored"

assert_file_missing "Spec.md has no chat yet" "$WIKI_PAGES/Main/Docs/Spec.chat"
save_page Main Docs/Spec.md '# Spec, revised' > /dev/null
runner > /dev/null
req=$(sent)
spec_id=$(id_of Docs/Spec.md)
assert_contains "the prompt gives the page id" "Its page id is $spec_id." "$req"
assert_contains "  …says the page has no chat yet, and what it would be" 'has no chat thread yet; wiki_post_chat would create "Docs/Spec.chat"' "$req"
assert_contains "  …and that the reply is posted there for it" "Your final reply will be posted in the page's chat thread \"Docs/Spec.chat\" for you" "$req"
assert_contains "the reply is in the page's chat" "Bot(-7): Feedback: reads well." "$(msgs Main/Docs/Spec.chat)"
assert_contains "  …marked as a workflow post" '"workflow": true' "$(cat "$WIKI_PAGES/Main/Docs/Spec.chat")"
[ -n "$(id_of Docs/Spec.chat)" ] && _pass "  …which is indexed" || _fail "  …which is indexed" "no id"
assert_eq "the run is ok" "ok" "$(last_run "$FB" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("state"))')"

save_page Main Docs/Spec.md '# Spec, third' > /dev/null
runner > /dev/null
assert_eq "the next run posts into the same thread" "2" "$(msgs Main/Docs/Spec.chat | wc -l | tr -d ' ')"
assert_contains "  …and the prompt now names it as existing" "The page's chat thread is \"Docs/Spec.chat\"." "$(sent)"

save_page Main Docs/table.list '{"columns":[],"rows":[]}' > /dev/null
runner > /dev/null
r=$(last_run "$FB" Docs/table.list)
assert_contains "a non-Markdown page gets no chat — the run says so" 'Reply not posted: The page is not a Markdown page' "$r"
assert_eq "  …and is still ok" "ok" "$(printf '%s' "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("state"))')"
assert_file_missing "  …with no thread made" "$WIKI_PAGES/Main/Docs/table.chat"
toggle "$FB" 0

section 'the chat action can post in the page chat, and its thread path takes variables'
CH=$(new_wf '{"name":"Note","enabled":true,"space":"Main","debounce":0,"trigger":{"types":["page_updated"]},
  "filters":{"folder":"Docs"},
  "actions":[{"type":"chat","page_chat":true,"text":"{{actor}} saved {{page}} (id {{page_id}}, chat {{page_chat}})"},
             {"type":"chat","chat":"{{page}}-log.chat","text":"logged"}]}' | wf_id)
case "$CH" in ERR:*|'') _fail "a chat action with no thread but the page-chat box saves" "$CH" ;; *) _pass "a chat action with no thread but the page-chat box saves" ;; esac
thread 'Log' 'start' 'Main/Spec-log.chat'
save_page Main Docs/Spec.md '# Spec, fourth' > /dev/null
runner > /dev/null
assert_contains "the message is in the page chat, variables filled" \
    "Note(0): Admin saved Spec (id $spec_id, chat Docs/Spec.chat)" "$(msgs Main/Docs/Spec.chat)"
assert_contains "a templated thread path resolves per page" 'Note(0): logged' "$(msgs Main/Spec-log.chat)"
toggle "$CH" 0
r=$(new_wf '{"name":"Bad","space":"Main","trigger":{"types":["page_updated"]},"actions":[{"type":"chat","text":"x"}]}')
assert_contains "neither a thread nor the box is still refused" 'choose a .chat page' "$r"

section 'the Test preview names the page chat without creating it'
PV='{"name":"Preview","space":"Main","trigger":{"types":["page_updated"]},
  "actions":[{"type":"ai","ai_uid":-7,"prompt":"x","page_chat":true}]}'
r=$(preview "$PV" Plan.md)
assert_contains "an existing page chat is named" '"chat":"Plan.chat"' "$r"
fixture_page 'Main/Fresh.md' '# Fresh'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null
r=$(preview "$PV" Fresh.md)
assert_contains "a missing one is flagged as to be created" 'page_chat_new' "$r"
assert_file_missing "  …and the preview did not create it" "$WIKI_PAGES/Main/Fresh.chat"
r=$(preview "$PV" data.json)
assert_contains "a non-Markdown page is flagged" 'no_page_chat' "$r"

# ── the page-chat prompt ─────────────────────────────────────────────────────
section "an AI answering in a page chat is told the page's id and the thread's"
# Queued rather than answered inline, so the prompt it would get is on the queue entry.
printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
post_as "$ADMIN" 'api.php?action=post_chat_message&space=Main' 'file=Plan.chat&text=%23Later%20thoughts%3F' > /dev/null
ctx=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["jobs"][-1].get("page_context",""))' "$WIKI_SYS/agent_jobs_queue.json")
assert_contains "the page's id"   "Its page id is $(id_of Plan.md)." "$ctx"
assert_contains "the thread's id" "This chat thread is \"Plan.chat\", page id $(id_of Plan.chat)." "$ctx"
assert_contains "the page itself, as before (control)" '# Plan' "$ctx"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
