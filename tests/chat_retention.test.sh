#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Chat auto-purge. It deletes content irreversibly, so what it must *not* delete is tested
# as carefully as what it must.
#
# Two halves, on purpose. The policy values the UI offers are 100/200/300 messages, which
# makes the selection logic awkward to exercise over HTTP — so that is unit-tested against
# `wiki_chat_apply_policy()` with small numbers, and the HTTP half proves the wiring with
# the real values.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

# ── unit: the selection logic ────────────────────────────────────────────────
section 'what a policy keeps and removes (unit)'
unit=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "chat_retention.php";
$now = time();
$ts = fn($d) => date("c", $now - $d * 86400);
$m = [];
foreach ([400,300,200,150,120,100,80,40,20,10,3,0] as $i => $age)
    $m[] = ["id" => $i + 1, "ts" => $ts($age), "text" => "m"];
$m[1]["sticky"] = true;                                        // id 2, 300 days old
$m[] = ["id" => 90, "ts" => $ts(500), "pending" => true, "job_id" => "j1"];
$m[] = ["id" => 91, "ts" => $ts(500), "is_debug" => true];

$ids = fn($a) => implode(",", array_column($a, "id"));
[$k, $r] = wiki_chat_apply_policy($m, "count:5");
echo "count_kept=",   $ids($k), "\n";
[$k2, ]  = wiki_chat_apply_policy($m, "days:30");
echo "days_kept=",    $ids($k2), "\n";
[$k3, $r3] = wiki_chat_apply_policy($m, "");
echo "off_removed=",  count($r3), "\n";
[$k4, ]  = wiki_chat_apply_policy($m, "count:1");
echo "one_kept=",     $ids($k4), "\n";
')
has() { printf '%s' "$2" | tr ',' '\n' | grep -qx "$1"; }
CK=$(printf '%s' "$unit" | sed -n 's/^count_kept=//p')
DK=$(printf '%s' "$unit" | sed -n 's/^days_kept=//p')
OK=$(printf '%s' "$unit" | sed -n 's/^off_removed=//p')
K1=$(printf '%s' "$unit" | sed -n 's/^one_kept=//p')

if has 2  "$CK"; then _pass "count: keeps a pinned message"; else _fail "count: keeps a pinned message" "kept=$CK"; fi
if has 90 "$CK"; then _pass "count: keeps a pending job placeholder"; else _fail "count: keeps a pending job placeholder" "kept=$CK"; fi
if has 12 "$CK"; then _pass "count: keeps the newest"; else _fail "count: keeps the newest" "kept=$CK"; fi
if has 1  "$CK"; then _fail "count: drops the oldest" "kept=$CK"; else _pass "count: drops the oldest"; fi
# The debug transcript is not *protected* — but in this fixture it is also the newest
# message, so a count policy keeps it like any other recent one. Its lack of protection is
# what the age policy below demonstrates.
if has 91 "$CK"; then _pass "count: treats a debug transcript as an ordinary message"; else _fail "count: debug transcript" "kept=$CK"; fi
# 5 ordinary + the pinned one + the pending one; the pin must not eat the budget.
assert_eq "count:5 keeps 5 ordinary plus the 2 protected" "7" "$(printf '%s' "$CK" | tr ',' '\n' | grep -c .)"
assert_eq "count:1 keeps 1 ordinary plus the 2 protected" "3" "$(printf '%s' "$K1" | tr ',' '\n' | grep -c .)"

if has 9  "$DK"; then _pass "days: keeps a 20-day-old message"; else _fail "days: keeps a 20-day-old message" "kept=$DK"; fi
if has 8  "$DK"; then _fail "days: drops a 40-day-old message" "kept=$DK"; else _pass "days: drops a 40-day-old message"; fi
if has 2  "$DK"; then _pass "days: keeps a pinned 300-day-old message"; else _fail "days: keeps a pinned 300-day-old" "kept=$DK"; fi
if has 90 "$DK"; then _pass "days: keeps a pending 500-day-old placeholder"; else _fail "days: keeps a pending placeholder" "kept=$DK"; fi
if has 91 "$DK"; then _fail "days: purges an old debug transcript" "kept=$DK"; else _pass "days: purges an old debug transcript"; fi
assert_eq "off removes nothing" "0" "$OK"

# ── HTTP: the wiring, with the values the UI actually offers ─────────────────
mkchat() { python3 - "$WIKI_PAGES/Main/T.chat" "$1" <<'PY'
import json, sys, datetime as dt
now = dt.datetime.now(dt.timezone.utc)
n = int(sys.argv[2])
m = [{"id": i, "uid": 1, "name": "A", "text": f"m{i}",
      "ts": (now - dt.timedelta(days=(n - i))).isoformat()} for i in range(1, n + 1)]
json.dump({"topic": "T", "messages": m, "nextMessageId": n + 1}, open(sys.argv[1], "w"))
PY
}
count_msgs() { python3 -c "
import json; print(len(json.load(open('$WIKI_PAGES/Main/T.chat'))['messages']))"; }

section 'preview reports without changing anything'
mkchat 120
r=$(get_as "$JAR" 'api.php?action=chat_retention_preview&space=Main&file=T.chat&policy=count:100')
assert_contains "reports what would go" '"removes":20' "$r"
assert_eq       "changed nothing"       "120" "$(count_msgs)"

section 'saving applies the policy immediately'
r=$(post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=count:100')
assert_contains "reports the removal" '"removed":20' "$r"
assert_eq       "thread is trimmed"   "100" "$(count_msgs)"
assert_contains "stored in the .chat file" '"retention":"count:100"' "$(tr -d ' \n' < "$WIKI_PAGES/Main/T.chat")"

section 'it keeps applying as messages arrive'
post_as "$JAR" 'api.php?action=post_chat_message&space=Main' 'file=T.chat&text=another' > /dev/null
assert_eq "still capped after a post" "100" "$(count_msgs)"

section 'off means off'
mkchat 120
post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=' > /dev/null
assert_eq "nothing removed" "120" "$(count_msgs)"

section 'the wiki-wide default'
r=$(post_as "$JAR" 'api.php?action=admin_chat_retention' 'policy=count:100')
assert_contains "saved"                 '"success":true' "$r"
assert_contains "written to settings"   'count:100'      "$(cat "$WIKI_SYS/settings.json" 2>/dev/null)"

section 'a thread that chose "off" is not overridden by the default'
post_as "$JAR" 'api.php?action=post_chat_message&space=Main' 'file=T.chat&text=still off' > /dev/null
assert_eq "kept everything plus the new message" "121" "$(count_msgs)"

section 'an unconfigured thread inherits the default'
mkchat 120
python3 -c "
import json; p='$WIKI_PAGES/Main/T.chat'; d=json.load(open(p)); d.pop('retention', None)
json.dump(d, open(p,'w'))"
post_as "$JAR" 'api.php?action=post_chat_message&space=Main' 'file=T.chat&text=inherit' > /dev/null
assert_eq "trimmed to the default" "100" "$(count_msgs)"

section 'inherit — the way back to following the wiki default'
mkchat 120
# A thread pinned to "off" cannot be un-pinned without this; before it existed the first
# save of the dialog fixed a thread's policy forever.
post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=' > /dev/null
assert_contains "explicitly off is stored" '"retention":""' "$(tr -d ' \n' < "$WIKI_PAGES/Main/T.chat")"
r=$(post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=inherit')
assert_contains     "inherit is accepted"  '"success":true' "$r"
assert_not_contains "the key is removed"   '"retention"'    "$(tr -d ' \n' < "$WIKI_PAGES/Main/T.chat")"
# The wiki default is count:100 from the section above, so inheriting must now trim.
assert_eq "and the default applies at once" "100" "$(count_msgs)"

section 'the dialog can tell "unset" from "off"'
mkchat 30
r=$(get_as "$JAR" 'api.php?action=chat_messages&space=Main&file=T.chat')
assert_contains "unset reports null"     '"retention":null'         "$r"
assert_contains "and names the default"  '"retention_default":"count:100"' "$r"
post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=' > /dev/null
r=$(get_as "$JAR" 'api.php?action=chat_messages&space=Main&file=T.chat')
assert_contains "off reports an empty string" '"retention":""' "$r"

section 'previewing inherit resolves the default'
mkchat 120
python3 -c "
import json; p='$WIKI_PAGES/Main/T.chat'; d=json.load(open(p)); d.pop('retention', None)
json.dump(d, open(p,'w'))"
r=$(get_as "$JAR" 'api.php?action=chat_retention_preview&space=Main&file=T.chat&policy=inherit')
assert_contains "reports what it resolved to" '"resolved":"count:100"' "$r"
assert_contains "flags that it is inherited"  '"inherited":true'       "$r"
assert_contains "and previews the real effect" '"removes":20'          "$r"

section 'an unknown policy is refused, not stored'
mkchat 10
r=$(post_as "$JAR" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=count:7')
assert_contains "rejected"          '"success":false' "$r"
assert_not_contains "not persisted" 'retention'       "$(tr -d ' \n' < "$WIKI_PAGES/Main/T.chat")"
assert_eq           "nothing removed" "10" "$(count_msgs)"

section 'a reader cannot set a retention policy'
fixture_login "$WIKI_ROOT/jar-r" "uid=9&sub=s9&name=Reader&role=reader"
r=$(post_as "$WIKI_ROOT/jar-r" 'api.php?action=set_chat_retention&space=Main' 'file=T.chat&policy=count:100')
assert_contains "refused for a reader" '"success":false' "$r"
r=$(post_as "$WIKI_ROOT/jar-r" 'api.php?action=admin_chat_retention' 'policy=count:200')
assert_contains "wiki-wide default is admin-only" '"success":false' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
