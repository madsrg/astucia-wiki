#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The job runner at a 2-minute interval, and the two things that shortening it breaks.
#
# 1. Overlap. With a single lock a tick that finds it held exits, so the queue is strictly
#    serial and a slow job delays everything behind it however often cron fires. Slots cap
#    concurrency instead of forbidding it.
# 2. Liveness. agent_job_runner_stalled() is "heartbeat older than three ticks", and the
#    heartbeat used to be written once per tick. At two minutes that is a six-minute
#    threshold, so any job running longer than six minutes made a *busy* runner look dead
#    — and agent_job_abandon_if_stalled() would then destroy every queued job behind it,
#    reporting that nothing was going to run them. The runner now refreshes the stamp from
#    inside the run, via the progress callback run_agent_job takes.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main

section 'the shipped defaults'
assert_contains "interval is 2 minutes" "define('AGENT_JOB_RUNNER_INTERVAL_MINUTES', 2);" "$(cat "$WIKI_APP/config.php")"
assert_contains "two runner slots"      "define('AGENT_JOB_RUNNER_SLOTS', 2);"            "$(cat "$WIKI_APP/config.php")"
assert_contains "cron example matches"  '*/2 * * * *'                                     "$(cat "$WIKI_APP/config.php")"

section 'a busy runner is not a dead runner'
hb() { printf '{"last_run":"%s","interval":2}' "$(python3 -c "
import datetime,sys
print((datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(minutes=float(sys.argv[1]))).isoformat())" "$1")" \
    > "$WIKI_SYS/agent_jobs_heartbeat.json"; }
stalled() { (cd "$WIKI_APP" && php -r '
require "config.php"; require "indexer.php"; require "agent_jobs.php";
echo agent_job_runner_stalled() ? "stalled" : "alive";'); }
ls "$WIKI_SYS" | grep -q heartbeat || hb 0
hb 0;  assert_eq "fresh heartbeat"                 "alive"   "$(stalled)"
hb 4;  assert_eq "4 min at a 2 min interval"       "alive"   "$(stalled)"
hb 10; assert_eq "10 min really is stopped"        "stalled" "$(stalled)"
rm -f "$WIKI_SYS/agent_jobs_heartbeat.json"
assert_eq       "never ran at all"                 "stalled" "$(stalled)"

# A stub LLM: two calls, so the run makes several progress steps.
cat > "$WIKI_APP/__test_llm.php" <<'PHP'
<?php
$dir = dirname(__DIR__);
$n = (int)@file_get_contents($dir . '/llm-calls.txt') + 1;
file_put_contents($dir . '/llm-calls.txt', (string)$n);
header('Content-Type: application/json');
if ($n === 1) {
    echo json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => 'c1', 'type' => 'function',
                          'function' => ['name' => 'wiki_list_pages', 'arguments' => '{}']]]]]]]);
} else {
    echo json_encode(['choices' => [['finish_reason' => 'stop',
                                     'message' => ['role' => 'assistant', 'content' => 'Done.']]]]);
}
PHP

section 'the heartbeat is refreshed from inside the run'
out=$(cd "$WIKI_APP" && STUB_URL="$WIKI_URL/__test_llm.php" php -r '
require "config.php"; require "indexer.php"; require "space_settings.php";
require "ai_core.php"; require "mailer.php"; require "agent_jobs.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ai = ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true,
       "ai_config" => ["provider" => "openai", "model" => "m", "api_key" => "k",
                       "api_url" => getenv("STUB_URL")]];
$job = ["id" => "j1", "prompt" => "hi", "space" => "Main"];
$ticks = 0;
$res = run_agent_job($job, $ai, new PageIndexer($space_dir), $space_dir,
                     function () use (&$ticks) { $ticks++; agent_job_touch_heartbeat(); });
echo "REPLY=", (string)($res["reply"] ?? ""), "\n";
echo "TICKS=", $ticks, "\n";
echo "STALLED=", agent_job_runner_stalled() ? "yes" : "no", "\n";
' 2>&1)
assert_contains "the job ran"                      'REPLY=Done.'  "$out"
# preparing + 2x(calling_api, received) + the tool call = more than one.
ticks=$(printf '%s' "$out" | sed -n 's/^TICKS=//p')
if [ "${ticks:-0}" -ge 4 ]; then _pass "progress reported $ticks times during the run";
else _fail "progress reported during the run" "ticks=$ticks"; fi
assert_contains "heartbeat left fresh"             'STALLED=no'   "$out"

section 'runner slots'
# Hold a slot the way a still-running runner would, then see which slot the next tick takes.
# Never `wait` with no arguments in a test: fixture_start runs the web server as a
# background job of this same shell, so bare `wait` blocks on php -S and never returns.
HOLDERS=""
holder() {  # holder <lockfile> <seconds>
    php -r '$fh=fopen($argv[1],"c"); if(!flock($fh,LOCK_EX|LOCK_NB)){exit(1);} sleep((int)$argv[2]);' "$1" "$2" &
    HOLDERS="$HOLDERS $!"
    sleep 0.4
}
release_holders() {
    for _h in $HOLDERS; do kill "$_h" 2>/dev/null; wait "$_h" 2>/dev/null; done
    HOLDERS=""
}
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }

r=$(runner); assert_contains "an idle system uses slot 1" 'Runner slot 1/2' "$r"
assert_contains "  …and it is the scheduler"  'scheduled job(s)' "$r"

holder "$WIKI_SYS/agent_jobs.lock" 3
r=$(runner)
assert_contains "slot 1 busy -> takes slot 2"      'Runner slot 2/2' "$r"
assert_contains     "  …and leaves scheduling to slot 1" "Scheduled jobs: slot 1" "$r"

holder "$WIKI_SYS/agent_jobs.lock.2" 3
r=$(runner)
assert_contains "both slots busy -> exits"         'runner slot(s) busy' "$r"
release_holders

section 'effort is the AI user setting, not the route'
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":-7,"name":"fastbot","role":"editor","is_ai":true,
   "ai_config":{"provider":"openai","model":"m","api_key":"k","always_background":true}},
  {"uid":-6,"name":"deepbot","role":"editor","is_ai":true,
   "ai_config":{"provider":"openai","model":"m","api_key":"k","always_background":true,"reasoning_effort":"medium"}}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"
fixture_page 'Main/Topic.md' '# The page under discussion'

queued_thinking() {  # queued_thinking <thread> <text>
    printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
    printf '{"messages":[],"nextMessageId":1,"topic":"T"}' > "$WIKI_PAGES/Main/$1.chat"
    post_as "$JAR" 'api.php?action=post_chat_message&space=Main' "file=$1.chat&text=$2" > /dev/null
    python3 -c "
import json
j=json.load(open('$WIKI_SYS/agent_jobs_queue.json'))['jobs']
print(json.dumps(j[0]['thinking']) if j else 'NO JOB')"
}
assert_eq "effort off -> no thinking"    "null"                              "$(queued_thinking t1 '%23fastbot go')"
assert_eq "effort medium -> medium"      '{"enabled": true, "effort": "medium"}' "$(queued_thinking t2 '%23deepbot go')"

section '/aiJob carries the same context the background route does'
printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"
printf '%s' '{"messages":[{"id":1,"uid":1,"name":"Admin","text":"the Q3 numbers look off","timestamp":"2026-01-01T00:00:00+00:00"}],"nextMessageId":2,"topic":"Topic"}' \
    > "$WIKI_PAGES/Main/Topic.chat"
post_as "$JAR" 'api.php?action=queue_agent_job&space=Main' \
    'file=Topic.chat&ai_user=deepbot&prompt=investigate that' > /dev/null
job=$(python3 -c "
import json;print(json.dumps(json.load(open('$WIKI_SYS/agent_jobs_queue.json'))['jobs'][0]))")
assert_contains "the attached page travels"   'The page under discussion' "$job"
assert_contains "the thread travels"          'the Q3 numbers look off'   "$job"
assert_contains "the typed prompt is last"    'The request: investigate that' "$job"
assert_contains "effort from the AI user"     '"effort": "medium"'        "$job"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
