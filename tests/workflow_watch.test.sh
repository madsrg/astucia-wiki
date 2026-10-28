#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# A failed workflow run reaches an administrator: the server half of the failure toast.
#
# Workflows run in the cron runner, minutes after the save that started them, so a failure
# used to be visible only in the run history. modules/workflow_watch now raises a sticky
# toast with a link to the run's log. What it stands on, and what this suite pins:
#
#   - **`admin_workflow_failures`** lists recent failed runs, and only failed ones — admin
#     only, since workflows and their logs are;
#   - **every failed run has a log**, not just one with an AI action — most actions write
#     nothing to the log, so the link would otherwise have nothing behind it;
#   - **the runner tells each administrator**, on their own user topic, so an open browser
#     hears at once rather than at its next poll. Asserted against a stub hub, by uid,
#     because telling the wrong person is worse than telling nobody.
#
# The toast itself is the browser half, tests/workflow_watch_ui.test.sh.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap 'kill "$HUB_PID" 2>/dev/null; fixture_stop' EXIT
fixture_space Main
fixture_users '{"users":[
 {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
 {"uid":4,"name":"Second","role":"admin","auth":"otp","email":"second@example.com"},
 {"uid":2,"name":"Eddie","role":"editor","auth":"otp","email":"eddie@example.com"}]}'
ADMIN=$WIKI_ROOT/admin.jar; EDIT=$WIKI_ROOT/edit.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EDIT"  "uid=2&name=Eddie&role=editor&auth=otp"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null
fixture_page 'Main/Docs/a.md' 'Alpha'
fixture_page 'Main/Team.chat' '{"messages":[],"nextMessageId":1,"topic":"Team"}'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

# ── a stub hub that records what PHP sends it ────────────────────────────────
HUB_DIR=$WIKI_ROOT/hub; mkdir -p "$HUB_DIR"
cat > "$HUB_DIR/router.php" <<'PHP'
<?php
file_put_contents(__DIR__ . '/posts', urldecode(file_get_contents('php://input')) . "\n", FILE_APPEND);
http_response_code(200); echo 'id';
return true;
PHP
HUB_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$HUB_DIR" && exec php -S "127.0.0.1:$HUB_PORT" router.php ) > "$HUB_DIR/log" 2>&1 &
HUB_PID=$!
until curl -sf --max-time 2 -X POST "http://127.0.0.1:$HUB_PORT/.well-known/mercure" -d x=1 >/dev/null 2>&1; do sleep 0.1; done
python3 - "$WIKI_APP/config.php" "$(php -r 'echo bin2hex(random_bytes(32));')" "http://127.0.0.1:$HUB_PORT" <<'PY'
import re, sys
p, key, url = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read()
s = re.sub(r"define\('ENABLE_REALTIME',[^;]*\);",     "define('ENABLE_REALTIME', true);", s)
s = re.sub(r"define\('MERCURE_JWT_KEY',[^;]*\);",     "define('MERCURE_JWT_KEY', '%s');" % key, s)
s = re.sub(r"define\('MERCURE_INTERNAL_URL',[^;]*\);","define('MERCURE_INTERNAL_URL', '%s');" % url, s)
open(p, 'w').write(s)
PY
: > "$HUB_DIR/posts"
topics() { grep -o 'topic=[^&]*' "$HUB_DIR/posts" 2>/dev/null | sort -u; }

new_wf() { post_as "$ADMIN" 'api.php?action=admin_save_workflow' \
    "workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")"; }
wf_id() { python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("workflow",{}).get("id","") or "ERR:"+d.get("message",""))'; }
save_page() { curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary "$2" "$WIKI_URL/api.php?action=save&space=Main&file=$1"; }
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }
failures() { get_as "${1:-$ADMIN}" 'api.php?action=admin_workflow_failures'; }
# field <json> <key> — of the first failure listed
field() { python3 -c 'import json,sys
d=json.loads(sys.argv[1]); r=(d.get("runs") or [{}])[0]
print(r.get(sys.argv[2], ""))' "$1" "$2"; }

# One that fails — a chat action into a thread that does not exist — and one that works.
BAD=$(new_wf '{"name":"Broken","enabled":true,"space":"Main","debounce":0,"trigger":{"types":["page_updated"]},
  "filters":{"folder":"Docs"},"actions":[{"type":"chat","chat":"Gone.chat","text":"hello"}]}' | wf_id)
GOOD=$(new_wf '{"name":"Fine","enabled":true,"space":"Main","debounce":0,"trigger":{"types":["page_updated"]},
  "filters":{"folder":"Docs"},"actions":[{"type":"chat","chat":"Team.chat","text":"saved"}]}' | wf_id)

section 'nothing to report before anything has failed'
r=$(failures)
assert_contains "the list answers"  '"success":true' "$r"
assert_contains "  …and is empty"   '"runs":[]'      "$r"

save_page Docs/a.md 'Alpha, edited' > /dev/null
runner > /dev/null

section 'a failed run is listed, with what failed'
r=$(failures)
assert_eq       "exactly the failed run"     "$BAD"   "$(field "$r" workflow_id)"
assert_contains "  …not the one that worked" '"runs":[{' "$r"
assert_not_contains "  …(the good one is absent)" "\"workflow_id\":\"$GOOD\"" "$r"
assert_eq       "named by the workflow"      "Broken" "$(field "$r" workflow_name)"
assert_eq       "the page"                   "Docs/a.md" "$(field "$r" path)"
assert_contains "the error"                  'Gone.chat' "$(field "$r" error)"
assert_eq       "it has a log, though no AI action ran" "True" "$(field "$r" has_log)"

section 'the log says what failed'
RUN=$(field "$r" id)
log=$(get_as "$ADMIN" "api.php?action=admin_get_workflow_runs&id=$BAD&log=$RUN" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("log",""))')
assert_contains "the outcome section"        '--- Outcome' "$log"
assert_contains "  …naming the action"       'Action 1 (chat): error' "$log"
assert_contains "  …and the error"           'ERROR: Action 1 (chat): The chat "Gone.chat" does not exist.' "$log"

section 'the administrators are told, each on their own topic'
assert_contains "the first admin"            'topic=wiki/user/1/workflow' "$(topics)"
assert_contains "the second admin"           'topic=wiki/user/4/workflow' "$(topics)"
assert_not_contains "not the editor"         'topic=wiki/user/2/workflow' "$(topics)"
assert_contains "  …through a hub that is receiving at all (control)" 'topic=wiki/Main/' "$(topics)"

: > "$HUB_DIR/posts"
post_as "$ADMIN" 'api.php?action=admin_toggle_workflow' "id=$BAD&enabled=0" > /dev/null
save_page Docs/a.md 'Alpha, again' > /dev/null
runner > /dev/null
assert_not_contains "a run that worked tells nobody" '/workflow' "$(topics)"
assert_eq "  …and adds nothing to the list" "1" "$(failures | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["runs"]))')"

section 'administrators only'
r=$(failures "$EDIT")
assert_not_contains "an editor is refused"   '"success":true' "$r"
assert_contains     "  …while an admin is not (control)" '"success":true' "$(failures)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
