#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The audit log, and specifically the field the viewer is useless without: which page an
# entry is about. Most actions are told their target in the request, so wiki_audit_begin()
# reads it there. An upload is the exception — the final name is chosen server-side and a
# collision changes it — and it shipped with no object at all: two drops produced two
# identical "a page was created" lines naming neither page.
#
# Every case here pairs the assertion with a positive control on an action that does take
# its path from the request, so a request malformed in some unrelated way cannot pass the
# check by never reaching the hook.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

# Auth on, because half of what the log is for is recording who did it and what was
# refused — with AUTHENTICATION off every caller is an unrestricted anonymous one.
fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
mkdir -p "$WIKI_PAGES/Main/Notes"
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},{"uid":9,"sub":"s9","name":"Reader","role":"reader","auth":"oidc"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

# Audit logging is a runtime setting, off by default — turn it on the way the admin panel does.
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"

log() { cat "$WIKI_ROOT"/logs/audit/*.log 2>/dev/null; }
# Every field of the one entry whose api_action matches, so a later entry cannot satisfy
# an assertion meant for an earlier one.
entry() { log | grep -F "\"api_action\":\"$1\"" | sed -n "${2:-1}p"; }

upload() {  # upload <filename>
    printf '# hello\n' > "$WIKI_ROOT/src.md"
    curl -s -b "$JAR" -c "$JAR" --max-time 15 \
        -F "file=@$WIKI_ROOT/src.md;filename=$1" -F 'folder=Notes' \
        "$WIKI_URL/api.php?action=upload_page&space=Main"
}

section 'an ordinary create names its page (positive control)'
r=$(post_as "$JAR" 'api.php?action=create_file&space=Main' 'path=Notes/typed.md&content=x')
assert_contains "create_file succeeded"      '"success":true'                "$r"
assert_contains "logged with its path"       '"object":"Notes/typed.md"'     "$(entry create_file)"
assert_contains "logged with its page id"    '"object_id":"'                 "$(entry create_file)"

section 'an uploaded page is named too'
r=$(upload notes.md)
assert_contains "upload succeeded"           '"success":true'                "$r"
assert_contains "logged with its path"       '"object":"Notes/notes.md"'     "$(entry upload_page)"
assert_contains "logged with its page id"    '"object_id":"'                 "$(entry upload_page)"
assert_contains "logged as a create"         '"action":"create"'             "$(entry upload_page)"
assert_contains "logged with its space"      '"space":"Main"'                "$(entry upload_page)"
assert_contains "attributed to the uploader" '"user":"Admin"'                "$(entry upload_page)"

section 'a collision is logged as the page that actually landed'
r=$(upload notes.md)
assert_contains "renamed on collision"       '"renamed":true'                "$r"
assert_contains "logged under the new name"  '"object":"Notes/notes (1).md"' "$(entry upload_page 2)"
# The whole point: the second entry must not be a copy of the first.
assert_not_contains "not the dropped name"   '"object":"Notes/notes.md"'     "$(entry upload_page 2)"

section 'the id in the log resolves to the file on disk'
id=$(entry upload_page 2 | sed -n 's/.*"object_id":"\([0-9]*\)".*/\1/p')
assert_eq "index agrees with the log" "Notes/notes (1).md" \
    "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get(sys.argv[2],{}).get("path",""))' \
        "$WIKI_PAGES/Main/index.json" "$id" 2>/dev/null)"

section 'a refused upload is logged as a failure, naming what was attempted'
fixture_login "$WIKI_ROOT/jar-r" "uid=9&sub=s9&name=Reader&role=reader"
printf '# hello\n' > "$WIKI_ROOT/src.md"
r=$(curl -s -b "$WIKI_ROOT/jar-r" -c "$WIKI_ROOT/jar-r" --max-time 15 \
    -F "file=@$WIKI_ROOT/src.md;filename=reader.md" -F 'folder=Notes' \
    "$WIKI_URL/api.php?action=upload_page&space=Main")
assert_contains "reader refused"             '"success":false'               "$r"
assert_file_missing "nothing written"        "$WIKI_PAGES/Main/Notes/reader.md"
denied=$(log | grep -F '"api_action":"upload_page"' | sed -n 3p)
assert_contains "denial logged"              '"status":"failure"'            "$denied"
# It never reached the write, so there is no landed name — the attempted one is the only
# thing that can be recorded, and a denial with no filename is the entry nobody can act on.
assert_contains "names the attempted file"   '"object":"Notes/reader.md"'    "$denied"
assert_contains "attributed to the reader"   '"user":"Reader"'               "$denied"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
