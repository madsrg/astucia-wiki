#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# What a thread's AI context contains — and that all three run paths agree on it.
#
# The inline reply path honoured `/newTopic` (only messages after the last sentinel), but
# both job paths built their own transcript and did not. So resetting a topic changed what
# an inline answer saw and not what a queued one saw: the same AI, the same thread, a
# different context depending only on how it was invoked.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":-6,"name":"deepbot","role":"editor","is_ai":true,
   "ai_config":{"provider":"openai","model":"m","api_key":"k","always_background":true,"context_messages":10}}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

# A thread with an abandoned topic, a /newTopic reset carrying its own text, and the
# conversation that followed.
cat > "$WIKI_PAGES/Main/Sales.chat" <<'EOF'
{"messages":[
 {"id":1,"uid":1,"name":"Admin","text":"OLDTOPIC budget spreadsheet chatter","timestamp":"2026-01-01T00:00:00+00:00"},
 {"id":2,"uid":1,"name":"Admin","text":"OLDTOPIC more of the same","timestamp":"2026-01-01T00:01:00+00:00"},
 {"id":3,"uid":1,"name":"Admin","text":"/newTopic NEWSUBJECT the 2026 sales figures","timestamp":"2026-01-01T00:02:00+00:00","is_new_topic":true},
 {"id":4,"uid":1,"name":"Admin","text":"AFTERRESET Q1 was strong","timestamp":"2026-01-01T00:03:00+00:00"},
 {"id":5,"uid":-9,"name":"deepbot","text":"AFTERRESET noted","timestamp":"2026-01-01T00:04:00+00:00"},
 {"id":6,"uid":1,"name":"Admin","text":"DEBUGREPORT","timestamp":"2026-01-01T00:05:00+00:00","is_debug":true}
],"nextMessageId":7,"topic":"Sales 2026"}
EOF

section 'the slice itself'
out=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "llm_providers.php"; require "ai_core.php";
$msgs = json_decode(file_get_contents(rtrim(PAGES_DIR,"/") . "/Main/Sales.chat"), true)["messages"];
$msgs[] = ["id" => 7, "uid" => -9, "name" => "deepbot", "text" => "", "pending" => true, "job_id" => "j"];
foreach (wiki_chat_context_slice($msgs, 10) as $m) echo $m["name"], "|", $m["text"], "\n";')
assert_not_contains "drops the abandoned topic"   'OLDTOPIC'    "$out"
assert_contains     "keeps what came after"       'AFTERRESET'  "$out"
assert_contains     "keeps the sentinel's own text" 'NEWSUBJECT' "$out"
assert_not_contains "  …without the command"      '/newTopic'   "$out"
assert_not_contains "drops /debug reports"        'DEBUGREPORT' "$out"
assert_not_contains "drops pending placeholders"  '"pending"'   "$out"
assert_eq "the topic line comes first" "Admin|NEWSUBJECT the 2026 sales figures" "$(printf '%s' "$out" | head -1)"

section 'a queued job now sees the same thread an inline reply would'
printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
post_as "$JAR" 'api.php?action=post_chat_message&space=Main' \
    'file=Sales.chat&text=%23deepbot summarise the figures' > /dev/null
prompt=$(python3 -c "
import json;print(json.load(open('$WIKI_SYS/agent_jobs_queue.json'))['jobs'][0]['prompt'])")
assert_not_contains "the abandoned topic is gone"  'OLDTOPIC'    "$prompt"
assert_contains     "the reset's subject is there" 'NEWSUBJECT'  "$prompt"
assert_contains     "and the messages after it"    'AFTERRESET'  "$prompt"
assert_contains     "the new request is quoted"    'summarise the figures' "$prompt"
# The transcript must not repeat the message that is quoted as "Latest message from …";
# counted, because that quote itself contains the shorter string.
assert_eq "  …exactly once, not twice" "1" \
    "$(printf '%s' "$prompt" | grep -c 'summarise the figures')"

section '/aiJob sees it too'
printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
post_as "$JAR" 'api.php?action=queue_agent_job&space=Main' \
    'file=Sales.chat&ai_user=deepbot&prompt=investigate' > /dev/null
prompt=$(python3 -c "
import json;print(json.load(open('$WIKI_SYS/agent_jobs_queue.json'))['jobs'][0]['prompt'])")
assert_not_contains "the abandoned topic is gone"  'OLDTOPIC'   "$prompt"
assert_contains     "the reset's subject is there" 'NEWSUBJECT' "$prompt"
assert_contains     "the typed request is last"    'The request: investigate' "$prompt"

section 'one implementation, not three'
assert_eq "only the helper knows the sentinel" "1" \
    "$(command grep -c 'is_new_topic' "$WIKI_APP/ai_core.php")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
