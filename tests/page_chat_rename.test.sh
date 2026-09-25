#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# A page's chat thread follows the page when it is renamed.
#
# Nothing records that `Notes.chat` belongs to `Notes.md` — the pairing is the filename,
# derived by swapping the extension on both the client and the server. So a rename that
# moves only the page does not break anything loudly: it silently orphans the
# conversation. The page opens an empty chat, the old thread sits in the tree under a name
# whose page no longer exists, and the only way back is knowing what the file used to be
# called.
#
# The thread is not a plain sidecar like `page.md.uploads/`. It is an indexed page, so the
# assertions here are mostly about the things a bare `rename()` would miss:
#
#   - the **id survives** (`updatePath`, not remove-and-add) — `?pageid=` links, an
#     `{include:ID}` tag and a queued job's `chat_id` all point at it;
#   - a **queued one-off job** addressing the thread is repointed, or the cron runner
#     writes its answer into a file that is not there any more;
#   - a **collision refuses the whole rename** rather than half-applying it.
#
# Both rename entry points are covered: the `move` action the UI uses, and the
# `wiki_rename_page` tool an AI uses. They share page_chat.php precisely so they cannot
# drift, and this suite is what says so.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_space Bravo
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"}]}'

JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&auth=otp&name=Admin&role=admin"

# A thread with one real message, so "the file moved" and "the conversation moved" are
# different assertions.
chat_file() {
    printf '{"topic":"%s","nextMessageId":2,"messages":[{"id":1,"uid":1,"name":"Admin","text":"%s","timestamp":"2026-01-01 10:00:00"}]}' \
        "$1" "$2" > "$WIKI_PAGES/$3"
}

fixture_page 'Main/Notes.md'  '# Notes'
chat_file 'Notes' 'keep this conversation' 'Main/Notes.chat'
fixture_page 'Main/Lonely.md' '# No chat here'
fixture_page 'Main/Clash.md'  '# Clash'
chat_file 'Clash' 'clash thread' 'Main/Clash.chat'
chat_file 'Occupied' 'someone else' 'Main/Taken.chat'   # no Taken.md — an orphan already
fixture_page 'Main/Data.list' '{"columns":[],"rows":[]}'
fixture_page 'Main/Trip.md'   '# Trip'
chat_file 'Trip' 'cross-space thread' 'Main/Trip.chat'

get_as "$JAR" 'api.php?action=list_spaces' > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Main'  > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Bravo' > /dev/null

# id of a path in a space's index, or empty when it is not indexed at all.
id_of() {
    python3 -c 'import json,sys
try: ix = json.load(open(sys.argv[1]))
except Exception: print(""); raise SystemExit
print(next((k for k, v in ix.items() if v.get("path") == sys.argv[2]), ""))' \
        "$WIKI_PAGES/${2:-Main}/index.json" "$1"
}
mv_page() { post_as "$JAR" "api.php?action=move&space=${3:-Main}" "old_path=$1&new_path=$2${4:+&target_space=$4}"; }

# ── the move action ──────────────────────────────────────────────────────────
section 'renaming a page takes its chat thread with it'
chat_id_before=$(id_of 'Notes.chat')
[ -n "$chat_id_before" ] && _pass "the thread starts out indexed" \
    || _fail "the thread starts out indexed" "no id for Notes.chat"

r=$(mv_page 'Notes.md' 'Ideas.md')
assert_contains "the rename succeeded" '"success":true' "$r"
assert_file_exists "the thread is at the new name" "$WIKI_PAGES/Main/Ideas.chat"
[ -e "$WIKI_PAGES/Main/Notes.chat" ] && _fail "and no longer at the old one" "Notes.chat still there" \
    || _pass "and no longer at the old one"
assert_contains "with the conversation in it" 'keep this conversation' "$(cat "$WIKI_PAGES/Main/Ideas.chat")"

section 'the thread keeps its id, because links point at it'
# updatePath, never removePage+addPage: ?pageid= links, {include:ID} tags and a /jobs
# entry's chat_id all name the thread by id.
assert_eq "same id at the new path" "$chat_id_before" "$(id_of 'Ideas.chat')"
assert_eq "and nothing is left under the old path" "" "$(id_of 'Notes.chat')"

section 'a page with no thread renames exactly as before'
# The positive control: without it, a helper that had stopped running would pass every
# assertion about what it does not do.
r=$(mv_page 'Lonely.md' 'Solo.md')
assert_contains "the rename succeeded" '"success":true' "$r"
assert_file_exists "the page moved" "$WIKI_PAGES/Main/Solo.md"
[ -e "$WIKI_PAGES/Main/Solo.chat" ] && _fail "and no thread was invented" "Solo.chat appeared" \
    || _pass "and no thread was invented"

section 'a file that is not a Markdown page is untouched by any of this'
r=$(mv_page 'Data.list' 'Numbers.list')
assert_contains "the rename succeeded" '"success":true' "$r"
assert_file_exists "the list moved" "$WIKI_PAGES/Main/Numbers.list"

# ── the collision ────────────────────────────────────────────────────────────
section 'a thread already at the destination refuses the whole rename'
# Refused before anything moves. Moving the page and leaving the thread behind would
# produce exactly the orphan this feature exists to prevent, and silently.
r=$(mv_page 'Clash.md' 'Taken.md')
assert_not_contains "the rename is refused" '"success":true' "$r"
assert_contains "and says which thread is in the way" 'Taken.chat' "$r"
assert_file_exists "the page did not move" "$WIKI_PAGES/Main/Clash.md"
assert_file_exists "its thread did not move" "$WIKI_PAGES/Main/Clash.chat"
[ -e "$WIKI_PAGES/Main/Taken.md" ] && _fail "nothing was created at the destination" "Taken.md exists" \
    || _pass "nothing was created at the destination"
assert_contains "and the thread in the way is untouched" 'someone else' "$(cat "$WIKI_PAGES/Main/Taken.chat")"

# ── what else names a thread by path ─────────────────────────────────────────
section 'a queued job addressing the thread is repointed'
# The cron runner writes its answer into the placeholder message inside reply_to.chat.
# Leave that pointing at the old name and the answer lands nowhere — the one failure the
# runner cannot report, because the file it would report into is the one that is gone.
python3 - "$WIKI_SYS/agent_jobs_queue.json" <<'PY'
import json, sys
json.dump({"jobs": [
  {"id": "job-1", "state": "queued", "space": "Main", "prompt": "summarise",
   "requested_by": {"uid": 1, "name": "Admin"},
   "reply_to": {"chat": "Chatty.chat", "message_id": 7},
   "created_at": "2026-01-01 10:00:00"},
  {"id": "job-2", "state": "queued", "space": "Main", "prompt": "other thread",
   "requested_by": {"uid": 1, "name": "Admin"},
   "reply_to": {"chat": "Elsewhere.chat", "message_id": 3},
   "created_at": "2026-01-01 10:00:00"}
]}, open(sys.argv[1], 'w'))
PY
fixture_page 'Main/Chatty.md' '# Chatty'
chat_file 'Chatty' 'has a job waiting' 'Main/Chatty.chat'
# A status file for a run in flight: the pending bubble polls it by path.
printf '{"step":"executing_tool"}' > "$WIKI_PAGES/Main/Chatty.chat.ai-status.7"
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null

r=$(mv_page 'Chatty.md' 'Chatterbox.md')
assert_contains "the rename succeeded" '"success":true' "$r"
q=$(cat "$WIKI_SYS/agent_jobs_queue.json")
assert_contains "the job now names the new path" '"chat": "Chatterbox.chat"' "$q"
assert_not_contains "and not the old one"        '"chat": "Chatty.chat"'     "$q"
assert_contains "a job on another thread is left alone" '"chat": "Elsewhere.chat"' "$q"
assert_file_exists "the in-flight status file follows too" \
    "$WIKI_PAGES/Main/Chatterbox.chat.ai-status.7"

# ── across spaces ────────────────────────────────────────────────────────────
section 'moving a page to another space takes the thread along'
r=$(mv_page 'Trip.md' 'Trip.md' 'Main' 'Bravo')
assert_contains "the move succeeded" '"success":true' "$r"
assert_file_exists "the thread is in the target space" "$WIKI_PAGES/Bravo/Trip.chat"
[ -e "$WIKI_PAGES/Main/Trip.chat" ] && _fail "and gone from the source" "Main/Trip.chat still there" \
    || _pass "and gone from the source"
# Ids are scoped to one index, so a cross-space move mints a new one — as the page does.
[ -n "$(id_of 'Trip.chat' Bravo)" ] && _pass "indexed in the target space" \
    || _fail "indexed in the target space" "no id in Bravo"
assert_eq "and dropped from the source index" "" "$(id_of 'Trip.chat')"

# ── the AI tool takes the same path ──────────────────────────────────────────
section 'wiki_rename_page moves the thread too'
# api.php and the tool are two entry points to one rename. They share page_chat.php so
# they cannot drift; this is the assertion that says so.
fixture_page 'Main/Draft.md' '# Draft'
chat_file 'Draft' 'tool-renamed thread' 'Main/Draft.chat'
chat_file 'Blocked' 'in the way' 'Main/Blocked.chat'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
tool_id_before=$(id_of 'Draft.chat')

out=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "indexer.php"; require "space_settings.php";
require "search_index.php"; require "llm_providers.php"; require "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ix = new PageIndexer($space_dir);
$ai = ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true];
$call = fn($t, $in) => execute_ai_tool($t, $in, $ai, $ix, $space_dir);
echo "RENAME=", $call("wiki_rename_page", ["path" => "Draft.md", "new_path" => "Final.md"]), "\n";
echo "CLASH=",  $call("wiki_rename_page", ["path" => "Final.md", "new_path" => "Blocked.md"]), "\n";
' 2>&1)

assert_contains "the tool renamed the page"        'Renamed Draft.md to Final.md' "$out"
assert_contains "and says the thread came with it" 'Final.chat'                   "$out"
assert_file_exists "the thread is at the new name" "$WIKI_PAGES/Main/Final.chat"
[ -e "$WIKI_PAGES/Main/Draft.chat" ] && _fail "and not at the old one" "Draft.chat still there" \
    || _pass "and not at the old one"
assert_contains "with its conversation" 'tool-renamed thread' "$(cat "$WIKI_PAGES/Main/Final.chat")"
assert_eq "the id survived here too" "$tool_id_before" "$(id_of 'Final.chat')"

section 'and the tool refuses a collision as flatly as the UI does'
assert_contains "the tool refuses"    'Error: a chat thread already exists' "$out"
assert_file_exists "the page stayed"  "$WIKI_PAGES/Main/Final.md"
[ -e "$WIKI_PAGES/Main/Blocked.md" ] && _fail "and nothing was created" "Blocked.md exists" \
    || _pass "and nothing was created"
assert_contains "the thread in the way is intact" 'in the way' "$(cat "$WIKI_PAGES/Main/Blocked.chat")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
