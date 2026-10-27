#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Scheduled agent jobs: the recurrence engine in schedule.php (Outlook-style rules and cron
# expressions), the due-check the runner makes with it, and the admin endpoints that
# validate, preview and report it.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main

# next <schedule-json> [count] — upcoming runs after Tue 2026-10-27 10:07, one per line.
next() { (cd "$WIKI_APP" && php -r '
require "schedule.php"; date_default_timezone_set("Europe/Copenhagen");
try {
    $s = schedule_normalize(json_decode($argv[1], true));
    foreach (schedule_next($s, strtotime("2026-10-27 10:07:00"), (int)$argv[2]) as $t) echo date("D Y-m-d H:i", $t), "\n";
} catch (Exception $e) { echo "ERR ", $e->getMessage(), "\n"; }' "$1" "${2:-3}"); }

# due <job-json> — whether the runner would run it at Tue 2026-10-27 10:07.
due() { (cd "$WIKI_APP" && php -r '
require "schedule.php"; date_default_timezone_set("Europe/Copenhagen");
echo schedule_job_is_due(json_decode($argv[1], true), strtotime("2026-10-27 10:07:00")) ? "due" : "not due";' "$1"); }

section 'recurrence rules'
assert_eq "hourly" "Tue 2026-10-27 11:00
Tue 2026-10-27 12:00" "$(next '{"type":"hourly"}' 2)"
assert_eq "every 3 hours restarts at the window each day" "Tue 2026-10-27 12:00
Tue 2026-10-27 15:00
Tue 2026-10-27 18:00
Tue 2026-10-27 21:00
Wed 2026-10-28 00:00" "$(next '{"type":"hourly","interval":3}' 5)"
assert_eq "every 30 min in office hours, weekdays only" "Tue 2026-10-27 16:30
Tue 2026-10-27 17:00
Wed 2026-10-28 08:00" "$(next '{"type":"minutes","interval":30,"time":"08:00","until":"17:00","days":[1,2,3,4,5]}' 15 | tail -3)"
assert_eq "every other week, Tue+Thu, anchored on start" "Thu 2026-10-29 09:00
Tue 2026-11-10 09:00
Thu 2026-11-12 09:00" "$(next '{"type":"weekly","interval":2,"days":[2,4],"time":"09:00","start":"2026-10-26"}')"
assert_eq "second Tuesday of the month" "Tue 2026-11-10 09:00
Tue 2026-12-08 09:00" "$(next '{"type":"monthly","nth":2,"weekday":2,"time":"09:00"}' 2)"
assert_eq "last weekday of the month" "Fri 2026-10-30 16:00
Mon 2026-11-30 16:00" "$(next '{"type":"monthly","nth":-1,"weekday":"weekday","time":"16:00"}' 2)"
assert_eq "day 31 clamps to short months" "Sat 2026-10-31 08:00
Mon 2026-11-30 08:00" "$(next '{"type":"monthly","day":31,"time":"08:00"}' 2)"
assert_eq "every 3 months from the start month" "Tue 2027-01-05 08:00
Mon 2027-04-05 08:00" "$(next '{"type":"monthly","interval":3,"day":5,"time":"08:00","start":"2026-10-01"}' 2)"
assert_eq "yearly" "Mon 2027-03-15 07:00" "$(next '{"type":"yearly","month":3,"day":15,"time":"07:00"}' 1)"
assert_eq "end date stops it" "Wed 2026-10-28 08:00" "$(next '{"type":"daily","time":"08:00","end":"2026-10-28"}' 5)"

section 'the original three shapes read unchanged'
assert_eq "daily"   "Wed 2026-10-28 08:00" "$(next '{"type":"daily","time":"08:00"}' 1)"
assert_eq "weekly"  "Mon 2026-11-02 08:00" "$(next '{"type":"weekly","days":[1],"time":"08:00"}' 1)"
assert_eq "monthly" "Sun 2026-11-01 08:00" "$(next '{"type":"monthly","day":1,"time":"08:00"}' 1)"

section 'cron expressions'
assert_eq "steps, ranges and names" "Tue 2026-10-27 10:20
Tue 2026-10-27 10:40
Wed 2026-10-28 09:00" "$(next '{"type":"cron","cron":"*/20 9-10 * * mon-fri"}')"
assert_eq "both day fields set: either matches (Vixie)" "Fri 2026-10-30 12:00
Sun 2026-11-01 12:00
Fri 2026-11-06 12:00" "$(next '{"type":"cron","cron":"0 12 1 * fri"}')"
assert_eq "a/n starts at a"       "Tue 2026-10-27 10:20" "$(next '{"type":"cron","cron":"5/15 * * * *"}' 1)"
assert_eq "7 is Sunday too"       "Sun 2026-11-01 00:00" "$(next '{"type":"cron","cron":"0 0 * * 7"}' 1)"
assert_eq "@weekly"               "Sun 2026-11-01 00:00" "$(next '{"type":"cron","cron":"@weekly"}' 1)"
assert_contains "four fields refused"   "ERR A cron expression has five fields" "$(next '{"type":"cron","cron":"* * * *"}')"
assert_contains "out of range refused"  "ERR Cron field 1"                      "$(next '{"type":"cron","cron":"60 * * * *"}')"
assert_contains "nonsense refused"      "ERR Cron field 2"                      "$(next '{"type":"cron","cron":"0 x * * *"}')"
assert_contains "too frequent refused"  "ERR The shortest repeat"               "$(next '{"type":"minutes","interval":2}')"
assert_contains "weekly needs a day"    "ERR Pick at least one day"             "$(next '{"type":"weekly","time":"08:00"}')"

section 'the due-check'
assert_eq "hourly, last ran at 09:00"           "due"     "$(due '{"schedule":{"type":"hourly"},"last_run":"2026-10-27T09:00:30+01:00"}')"
assert_eq "hourly, already ran at 10:00"        "not due" "$(due '{"schedule":{"type":"hourly"},"last_run":"2026-10-27T10:00:30+01:00"}')"
assert_eq "old job never run: today's 08:00 counts, as before" "due" "$(due '{"schedule":{"type":"daily","time":"08:00"}}')"
assert_eq "created after today's 08:00: waits for tomorrow"    "not due" "$(due '{"schedule":{"type":"daily","time":"08:00"},"since":"2026-10-27T09:30:00+01:00"}')"
assert_eq "runner down overnight catches up once" "due"     "$(due '{"schedule":{"type":"daily","time":"23:00"},"last_run":"2026-10-20T23:00:00+01:00"}')"
assert_eq "but not beyond 24 hours"               "not due" "$(due '{"schedule":{"type":"weekly","days":[1],"time":"08:00"},"last_run":"2026-10-01T08:00:00+02:00"}')"
assert_eq "a broken cron never fires"             "not due" "$(due '{"schedule":{"type":"cron","cron":"bogus"}}')"

section 'admin endpoints'
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc"},
  {"uid":-5,"name":"bot","role":"editor","is_ai":true,"ai_config":{"provider":"openai","model":"m","api_key":"k"}}]}'
JAR=$WIKI_ROOT/jar;  fixture_login "$JAR"  "uid=1&sub=s1&name=Admin&role=admin"
EJAR=$WIKI_ROOT/ejar; fixture_login "$EJAR" "uid=2&sub=s2&name=Ed&role=editor"

r=$(get_as "$JAR" "api.php?action=admin_preview_schedule&schedule=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("{\"type\":\"hourly\"}"))')")
assert_contains "preview lists runs"           '"next":["' "$r"
assert_eq       "  …five of them"              "5" "$(printf '%s' "$r" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["next"]))')"
r=$(get_as "$JAR" "api.php?action=admin_preview_schedule&schedule=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("{\"type\":\"cron\",\"cron\":\"0 25 * * *\"}"))')")
assert_contains "preview reports a cron error" 'Cron field 2' "$r"
r=$(get_as "$EJAR" "api.php?action=admin_preview_schedule&schedule=%7B%22type%22%3A%22hourly%22%7D")
assert_not_contains "preview is admin-only"   '"next"' "$r"

save() { post_as "$JAR" 'api.php?action=admin_save_agent_job' "$1"; }
r=$(save 'name=Hourly&enabled=true&ai_user_uid=-5&space=Main&prompt=go&schedule={"type":"hourly","interval":"2","bogus":1}')
assert_contains "an hourly job saves" '"success":true' "$r"
jobs() { python3 -c "import json;print(json.dumps([j.get('$1') for j in json.load(open('$WIKI_SYS/agent_jobs.json'))['jobs']]))"; }
assert_eq       "  …normalised as stored" '[{"type": "hourly", "interval": 2, "time": "00:00", "until": "23:59"}]' "$(jobs schedule)"
assert_contains "  …with a since stamp" '"20' "$(jobs since)"
r=$(save 'name=Bad&enabled=true&ai_user_uid=-5&space=Main&prompt=go&schedule={"type":"cron","cron":"* * *"}')
assert_contains "a bad cron is refused on save" 'five fields' "$r"
assert_eq       "  …and nothing was written" "1" "$(python3 -c "import json;print(len(json.load(open('$WIKI_SYS/agent_jobs.json'))['jobs']))")"
r=$(get_as "$JAR" 'api.php?action=admin_get_agent_jobs')
assert_contains "the list carries the next run" '"next_run":"20' "$r"

section 'the runner'
printf '%s' '{"jobs":[
  {"id":"a","name":"DueJob","enabled":true,"ai_user_uid":999,"space":"Main","prompt":"x","schedule":{"type":"minutes","interval":5}},
  {"id":"b","name":"LaterJob","enabled":true,"ai_user_uid":999,"space":"Main","prompt":"x","since":"2999-01-01T00:00:00+00:00","schedule":{"type":"minutes","interval":5}}]}' \
    > "$WIKI_SYS/agent_jobs.json"
r=$(cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1)
# The AI user does not exist, so a due job stops right after the due-check — no LLM call.
assert_contains "a due job is picked up"  "Running job: DueJob" "$r"
assert_contains "one that is not is left" "Skipping 'LaterJob' (not due)" "$r"

exit $(( ASSERT_FAIL > 0 ))
