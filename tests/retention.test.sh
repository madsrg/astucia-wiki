#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Retention policies: how long the wiki keeps its own logs and run histories.
#
# What this pins, in order of how badly it would go wrong:
#
#   - **The defaults delete nothing.** Upgrading must not remove a single log file; every
#     log is kept forever until an administrator sets a limit.
#   - **Only the wiki's own files, only whole files, never today's.** Files that merely sit
#     in LOG_DIR (nginx's, cron's) are left alone, and so is the audit file being written.
#   - **The tab shows the effect before it is saved**, and saving is audited.
#   - **The cron runner sweeps once a day**, and the run histories obey their settings.
#
# Each deletion is checked against a file that must survive beside it, so a sweep that
# deletes everything — or nothing — cannot pass.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[
 {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
 {"uid":2,"name":"Eddie","role":"editor","auth":"otp","email":"eddie@example.com"}]}'
ADMIN=$WIKI_ROOT/admin.jar; EDIT=$WIKI_ROOT/edit.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EDIT"  "uid=2&name=Eddie&role=editor&auth=otp"
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null

LOGS=$WIKI_ROOT/logs
TODAY=$(date +%F)
mkdir -p "$LOGS/agent-jobs/Old job" "$LOGS/agent-jobs/_oneoff" "$LOGS/workflows/wf_gone" "$LOGS/workflows/wf_live" "$LOGS/audit"
old() { printf 'old\n' > "$1"; touch -d '2020-01-01' "$1"; }
# Dated by name: an old day and today's. A today-named file with an old mtime proves the
# name wins over the mtime (a restore resets mtimes).
old "$LOGS/2020-01-01_access.log";     printf 'x\n' > "$LOGS/${TODAY}_access.log"; touch -d '2020-01-01' "$LOGS/${TODAY}_access.log"
old "$LOGS/wiki_errors_2020-01-01.log"; printf 'x\n' > "$LOGS/wiki_errors_${TODAY}.log"
old "$LOGS/audit/2020-01-01.log"
# Dated by mtime: per-run logs.
old "$LOGS/agent-jobs/Old job/2020-01-01-120000.log"
old "$LOGS/agent-jobs/_oneoff/aj_old.log";  printf 'x\n' > "$LOGS/agent-jobs/_oneoff/aj_new.log"
old "$LOGS/workflows/wf_gone/wr_old.log";   printf 'x\n' > "$LOGS/workflows/wf_live/wr_new.log"
# Not the wiki's: the image's nginx log and cron's stdout, both old.
old "$LOGS/nginx-access.log"; old "$LOGS/cron-agent-jobs.log"

settings() { get_as "$ADMIN" "api.php?action=admin_retention_settings${1:+&$1}"; }
jget() { python3 -c 'import json,sys
d=json.loads(sys.argv[1])
for k in sys.argv[2].split("."): d=d[k]
print(d)' "$1" "$2"; }
audit() { cat "$LOGS"/audit/*.log 2>/dev/null; }

section 'the defaults keep everything'
r=$(settings)
assert_contains "the settings answer" '"success":true' "$r"
for k in access_log_days error_log_days agent_job_log_days workflow_log_days audit_log_days; do
    assert_eq "  $k is 0 (forever)" "0" "$(jget "$r" settings.$k)"
done
assert_eq "  job history keeps 7 days"        "7"    "$(jget "$r" settings.job_history_days)"
assert_eq "  …and 100 jobs"                   "100"  "$(jget "$r" settings.job_history_keep)"
assert_eq "  workflow history keeps 14 days"  "14"   "$(jget "$r" settings.workflow_history_days)"
assert_eq "  …and 1000 runs"                  "1000" "$(jget "$r" settings.workflow_history_keep)"
assert_eq "the access logs are counted"       "2" "$(jget "$r" scan.access_log.files)"
assert_eq "  …and nothing would go"           "0" "$(jget "$r" scan.access_log.old_files)"
r=$(post_as "$ADMIN" 'api.php?action=admin_retention_settings' 'run=1')
assert_contains "a sweep with the defaults runs" '"success":true' "$r"
assert_file_exists "  …and deletes nothing" "$LOGS/2020-01-01_access.log"
assert_file_exists "  …not even the audit log's old day" "$LOGS/audit/2020-01-01.log"

section 'the tab shows the effect before saving'
r=$(settings 'access_log_days=30&agent_job_log_days=30')
assert_eq "a 30-day access limit would remove the old day" "1" "$(jget "$r" scan.access_log.old_files)"
assert_eq "  …not today's, whatever its mtime"             "2" "$(jget "$r" scan.access_log.files)"
assert_eq "the old job logs would go"                      "2" "$(jget "$r" scan.agent_job_log.old_files)"
assert_eq "  …and nothing was saved by looking"            "0" "$(jget "$r" settings.access_log_days)"

section 'saving is clamped and audited'
r=$(post_as "$ADMIN" 'api.php?action=admin_retention_settings' \
    'access_log_days=30&error_log_days=30&agent_job_log_days=30&workflow_log_days=30&audit_log_days=30&job_history_keep=1&workflow_history_days=-4')
assert_eq "a log limit is stored"                 "30" "$(jget "$r" settings.access_log_days)"
assert_eq "a history cap below 10 is raised to 10" "10" "$(jget "$r" settings.job_history_keep)"
assert_eq "a history window below 1 day is raised to 1" "1" "$(jget "$r" settings.workflow_history_days)"
assert_contains "each change is in the audit log" '"change_type":"retention.access_log_days"' "$(audit)"
assert_contains "  …with the value it replaced"   '"old_value":"0"' "$(audit | grep -F 'retention.access_log_days')"

section 'the sweep: old files of the wiki only'
r=$(post_as "$ADMIN" 'api.php?action=admin_retention_settings' 'run=1')
assert_eq "it reports what it deleted" "1" "$(jget "$r" deleted.access_log)"
assert_file_missing "an old access log is gone"      "$LOGS/2020-01-01_access.log"
assert_file_exists  "  …today's stays"               "$LOGS/${TODAY}_access.log"
assert_file_missing "an old error log is gone"       "$LOGS/wiki_errors_2020-01-01.log"
assert_file_exists  "  …today's stays"               "$LOGS/wiki_errors_${TODAY}.log"
assert_file_missing "an old audit day is gone"       "$LOGS/audit/2020-01-01.log"
assert_file_exists  "  …today's — being written — stays" "$LOGS/audit/${TODAY}.log"
assert_file_missing "an old job run log is gone"     "$LOGS/agent-jobs/_oneoff/aj_old.log"
assert_file_exists  "  …a recent one stays"          "$LOGS/agent-jobs/_oneoff/aj_new.log"
assert_file_missing "  …and a folder it emptied goes with it" "$LOGS/agent-jobs/Old job"
assert_file_missing "an old workflow run log is gone" "$LOGS/workflows/wf_gone/wr_old.log"
assert_file_exists  "  …a recent one stays"          "$LOGS/workflows/wf_live/wr_new.log"
assert_file_exists  "the families' own folders stay" "$LOGS/agent-jobs"
assert_file_exists  "nginx's log is not the wiki's to delete" "$LOGS/nginx-access.log"
assert_file_exists  "  …nor cron's"                  "$LOGS/cron-agent-jobs.log"
assert_contains "the sweep is recorded in the audit log" '"change_type":"retention_sweep"' "$(audit)"

section 'the cron runner sweeps once a day'
old "$LOGS/2020-01-02_access.log"
rm -f "$WIKI_SYS/retention_sweep.json"
out=$(cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1)
assert_contains "the first tick of the day sweeps" 'Retention sweep: access_log 1' "$out"
assert_file_missing "  …and the file is gone" "$LOGS/2020-01-02_access.log"
old "$LOGS/2020-01-03_access.log"
out=$(cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1)
assert_not_contains "the next tick does not" 'Retention sweep' "$out"
assert_file_exists "  …so a file that appeared since waits for tomorrow" "$LOGS/2020-01-03_access.log"

section 'the run histories obey their settings'
# _wiki_wf_prune and _agent_job_prune read the settings saved above: workflow history 1 day,
# job history capped at 10.
r=$(cd "$WIKI_APP" && php -r '
require "config.php"; require_once "workflows.php"; require_once "agent_jobs.php";
$runs = [["state"=>"ok","finished_at"=>date("c", time()-3*86400)], ["state"=>"ok","finished_at"=>date("c")], ["state"=>"queued"]];
$jobs = [];
for ($i = 0; $i < 15; $i++) $jobs[] = ["state"=>"ok","finished_at"=>date("c", time()-$i*60)];
echo count(_wiki_wf_prune($runs)), " ", count(_agent_job_prune($jobs));')
assert_eq "a 1-day workflow window drops a 3-day-old run, keeps the live one" "2" "${r% *}"
assert_eq "a cap of 10 keeps 10 of 15 jobs" "10" "${r#* }"

section 'administrators only'
assert_not_contains "an editor is refused" '"success":true' "$(get_as "$EDIT" 'api.php?action=admin_retention_settings')"
assert_not_contains "  …and cannot sweep"  '"success":true' "$(post_as "$EDIT" 'api.php?action=admin_retention_settings' 'run=1')"
assert_contains     "  …while an admin can (control)" '"success":true' "$(settings)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
