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
#
# It also covers what a transcript *line* says, which is the other half of the same
# problem: people forget `/newTopic`, so an AI is routinely handed nine messages about
# last week followed by an unrelated question. Every line now carries how long ago it
# was written, which turns "is this still the same conversation" from a guess into
# evidence — and the five places that built a line have become one, which is what made
# that a small change rather than five.

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

section "the attached page travels with the thread, whichever way it is displayed"
# A page chat can be a panel beside its page or a page in the main area, and the two are
# a purely client-side difference: both post the same `post_chat_message` with the same
# `file=`, and the server pairs a thread with its page by filename alone. So the page's
# content reaches the AI identically from either view — asserted here because the two
# presentations look different enough that it is a fair thing to doubt, and because
# nothing else covered wiki_page_context_prompt() at all.
fixture_page 'Main/Report.md' '# Report

REPORTBODY the quarterly numbers.'
printf '%s' '{"messages":[],"nextMessageId":1}' > "$WIKI_PAGES/Main/Report.chat"
printf '%s' '{"messages":[],"nextMessageId":1}' > "$WIKI_PAGES/Main/Freestanding.chat"
mkdir -p "$WIKI_PAGES/Main/Sub"
fixture_page 'Main/Sub/Deep.md' '# Deep

DEEPBODY nested page.'
printf '%s' '{"messages":[],"nextMessageId":1}' > "$WIKI_PAGES/Main/Sub/Deep.chat"

ctx=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "indexer.php"; require "llm_providers.php";
require "wiki_ai_tools.php"; require "ai_core.php";
$dir = rtrim(PAGES_DIR, "/") . "/Main";
$one = fn($f) => str_replace("\n", " ", wiki_page_context_prompt($dir . "/" . $f, $dir));
echo "PAGED=",  $one("Report.chat"), "\n";
echo "ALONE=[", $one("Freestanding.chat"), "]\n";
echo "NESTED=", $one("Sub/Deep.chat"), "\n";')

assert_contains "a page chat carries the page's content" 'REPORTBODY'  "$ctx"
assert_contains "  and the path to write back to"        'Report.md'   "$ctx"
# The pairing is the filename, so a thread with no page beside it gets nothing — the
# same rule that decides whether the dock button appears in the UI.
assert_contains "a standalone thread carries nothing"    'ALONE=[]'    "$ctx"
# dirname-relative, not root-relative: a nested thread must find the page beside *it*.
assert_contains "a nested page chat finds its own page"  'DEEPBODY'    "$ctx"
assert_contains "  with the nested path"                 'Sub/Deep.md' "$ctx"

section 'one implementation, not three'
assert_eq "only the helper knows the sentinel" "1" \
    "$(command grep -c 'is_new_topic' "$WIKI_APP/ai_core.php")"

section 'an age on every line'
# The evidence half of the fix. A model cannot tell a message from thirty seconds ago
# from one three weeks old, and a long gap before the latest message is the strongest
# available sign that the subject has changed.
ages=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "llm_providers.php"; require "ai_core.php";
$now = strtotime("2026-06-01T12:00:00+00:00");
$at  = fn($s) => date("c", strtotime($s, $now));
$rows = [
  ["name" => "A", "timestamp" => $at("-20 seconds")],
  ["name" => "B", "timestamp" => $at("-5 minutes")],
  ["name" => "C", "timestamp" => $at("-1 minute")],
  ["name" => "D", "timestamp" => $at("-3 hours")],
  ["name" => "E", "timestamp" => $at("-2 days")],
  ["name" => "F", "timestamp" => $at("-3 weeks")],
  ["name" => "G", "timestamp" => $at("-8 months")],
  ["name" => "H", "timestamp" => $at("+10 minutes")],
  ["name" => "I", "timestamp" => "not a date"],
  ["name" => "J"],
];
foreach ($rows as $r) echo $r["name"], "=[", wiki_chat_age_label($r["timestamp"] ?? null, $now), "]\n";')
assert_contains "under a minute reads as just now" 'A=[just now]'    "$ages"
assert_contains "minutes"                          'B=[5 minutes ago]' "$ages"
assert_contains "  singular, not \"1 minutes\""    'C=[1 minute ago]'  "$ages"
assert_contains "hours"                            'D=[3 hours ago]'   "$ages"
assert_contains "days"                             'E=[2 days ago]'    "$ages"
assert_contains "weeks"                            'F=[3 weeks ago]'   "$ages"
assert_contains "months"                           'G=[8 months ago]'  "$ages"
# Clock skew between the writer and this process, which is ordinary across machines.
# "just now" is the honest reading; "-10 minutes ago" is nonsense a model would act on.
assert_contains "a timestamp in the future"        'H=[just now]'      "$ages"
# An age that cannot be established is omitted, never guessed: the label is trusted.
assert_contains "an unparseable timestamp gets none" 'I=[]'            "$ages"
assert_contains "  and so does a missing one"        'J=[]'            "$ages"

section 'and the line that carries it'
line=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "llm_providers.php"; require "ai_core.php";
$now = strtotime("2026-06-01T12:00:00+00:00");
echo "WITH=",    wiki_chat_context_line(["name" => "Ann", "text" => "hello",
                     "timestamp" => date("c", strtotime("-2 days", $now))], $now), "\n";
echo "WITHOUT=", wiki_chat_context_line(["name" => "Ann", "text" => "hello"], $now), "\n";
echo "SRC=",     wiki_chat_context_line(["name" => "Ann", "text" => "src:web look at this",
                     "timestamp" => date("c", $now)], $now), "\n";')
assert_contains "the age sits after the name"   'WITH=Ann (2 days ago): hello' "$line"
# Unchanged from before the ages existed, so a thread with no timestamps is not made
# worse by this.
assert_contains "no timestamp, no parenthesis"  'WITHOUT=Ann: hello'           "$line"
# The composer's marker was stripped on the three inline branches and not on the two job
# ones, so a queued answer saw tokens an inline one never did. One function, one answer.
assert_contains "the src: marker is stripped"   'SRC=Ann (just now): look at this' "$line"

section 'the prompt says what the ages are for'
# The labels are only evidence if something tells the model to weigh them.
prompts=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "indexer.php"; require "llm_providers.php";
require "wiki_ai_tools.php"; require "ai_core.php";
echo "CHAT=", str_replace("\n", " ", wiki_chat_context_prompt("Main", "Sales", "")), "\n";
echo "JOB=",  str_replace("\n", " ", wiki_job_context_prompt("Main", "", "Admin")), "\n";')
assert_contains "the chat prompt warns of an unrelated topic" \
                'can be about unrelated topics' "$prompts"
assert_contains "  and names the latest message as the request" \
                'most recent message is the request' "$prompts"
assert_contains "  and says a gap is evidence" 'strong evidence' "$prompts"
# Both paths, or the same AI answers differently depending only on how it was invoked —
# the failure this whole file exists for.
assert_contains "the job prompt says it too" 'JOB=' "$prompts"
job_line=$(printf '%s' "$prompts" | grep '^JOB=')
assert_contains "  the same warning"   'can be about unrelated topics'   "$job_line"
assert_contains "  the same rule"      'most recent message is the request' "$job_line"

section 'a real transcript carries the ages through'
# End to end rather than unit: what a queued job is actually handed.
printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
post_as "$JAR" 'api.php?action=queue_agent_job&space=Main' \
    'file=Sales.chat&ai_user=deepbot&prompt=investigate' > /dev/null
prompt=$(python3 -c "
import json;print(json.load(open('$WIKI_SYS/agent_jobs_queue.json'))['jobs'][0]['prompt'])")
# Those fixture messages are dated 2026-01-01, so by any wall clock they are months old.
assert_contains "the history lines are aged" ' ago): ' "$prompt"

section 'one line builder, not five'
# Three wire families inline plus two job paths. The count is the guard: a sixth caller
# formatting its own line is how the src: marker came to be stripped on some paths and
# not others.
assert_eq "every transcript line goes through the helper" "5" \
    "$(command grep -c 'wiki_chat_context_line(' "$WIKI_APP/api.php")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
