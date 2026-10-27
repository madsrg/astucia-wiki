#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Admin → Deleted Pages → Restore (git_restore_deleted).
#
# The restore used to index the page under its *absolute* filesystem path. Nothing looked
# wrong — the file was back — but index.json gained an entry no lookup could ever match,
# the page had no id (so ?pageid= links and tags were gone), and the next folder listing
# quietly adopted it under a second, different id.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

command -v git >/dev/null 2>&1 || { printf '  %s\n' "$(_dim 'skipped — no git')"; exit 0; }

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
# The SQLite index, because that is the one a restore has to update itself; the basic
# engine reads the files and would find the page whatever the restore did.
sed -i "s/^define('SEARCH_ENGINE'.*/define('SEARCH_ENGINE', 'sqlite');/" "$WIKI_APP/config.php"
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"a@example.com"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&name=Admin&role=admin&auth=otp"

fixture_page 'Main/Docs/gone.md' 'Restore me'
G() { git -C "$WIKI_PAGES/Main" -c user.name=T -c user.email=t@example.com "$@" > /dev/null 2>&1; }
G init; G add -A; G commit -m add
G rm Docs/gone.md; G commit -m delete
HASH=$(git -C "$WIKI_PAGES/Main" rev-parse HEAD)
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null

r=$(post_as "$JAR" 'api.php?action=git_restore_deleted&space=Main' "hash=$HASH&file=Docs/gone.md")
assert_contains "the restore succeeds (control)"   '"success":true' "$r"
assert_file_exists "the file is back"              "$WIKI_PAGES/Main/Docs/gone.md"

paths() { python3 -c 'import json,sys;print("\n".join(sorted(e["path"] for e in json.load(open(sys.argv[1])).values())))' "$WIKI_PAGES/Main/index.json"; }
assert_contains     "indexed under its relative path" 'Docs/gone.md' "$(paths)"
assert_not_contains "no absolute path in the index"   "$WIKI_PAGES"  "$(paths)"
assert_contains     "credited to who restored it" '"name": "Admin"' "$(cat "$WIKI_PAGES/Main/index.json")"
get_as "$JAR" 'api.php?action=list&space=Main' > /dev/null
assert_eq "a listing does not give it a second id" "1" "$(paths | grep -c 'gone.md')"
r=$(get_as "$JAR" 'api.php?action=search&space=Main&query=Restore')
assert_contains "and it is searchable again"       'gone.md' "$r"

deleted() { get_as "$JAR" "api.php?action=git_deleted_files&space=$1"; }
del()     { post_as "$JAR" "api.php?action=delete&space=$1" "path=$2"; }

section 'a delete in the wiki is committed (repo in the Space)'
# Deleting used to remove the file without a commit, so the repository still held the page
# and Deleted Pages — which reads deletions out of git log — never listed it.
fixture_page 'Main/Docs/doomed.md' 'Delete me'
mkdir -p "$WIKI_PAGES/Main/Docs/doomed.md.uploads"; echo x > "$WIKI_PAGES/Main/Docs/doomed.md.uploads/a.txt"
fixture_page 'Main/Docs/kept.md' 'Keep me'
G add -A; G commit -m 'add doomed'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
assert_contains "the delete succeeds (control)"   '"success":true' "$(del Main Docs/doomed.md)"
assert_eq "the deletion is committed, attachments with it" "" \
    "$(git -C "$WIKI_PAGES/Main" status --porcelain -- Docs/doomed.md Docs/doomed.md.uploads)"
assert_contains "in one commit, named for the page" 'Delete doomed.md' "$(git -C "$WIKI_PAGES/Main" log -1 --format=%s)"
r=$(deleted Main)
assert_contains     "Deleted Pages lists it"            'doomed.md' "$r"
assert_not_contains "a page that still exists is not"   'kept.md'   "$r"

section 'a repo at PAGES_DIR, holding every Space'
# There the log names Space/<path>; the existence check used to look in PAGES_DIR/<path>,
# outside the Space, so a page that still existed was listed as deleted.
rm -rf "$WIKI_PAGES/Main/.git"
fixture_space Other
fixture_page 'Other/back.md' 'I came back'
fixture_page 'Other/gone2.md' 'I am gone'
P() { git -C "$WIKI_PAGES" -c user.name=T -c user.email=t@example.com "$@" > /dev/null 2>&1; }
P init; P add -A; P commit -m add
P rm Other/back.md; P commit -m 'delete back'
fixture_page 'Other/back.md' 'I came back'       # restored by hand, not committed
get_as "$JAR" 'api.php?action=indexfiles&space=Other' > /dev/null
assert_contains "the delete succeeds (control)"   '"success":true' "$(del Other gone2.md)"
assert_eq "committed at the root repo too" "" "$(git -C "$WIKI_PAGES" status --porcelain -- Other/gone2.md)"
r=$(deleted Other)
assert_contains     "lists the page deleted in the Space"     'gone2.md' "$r"
assert_not_contains "not one that exists again in the Space"  'back.md'  "$r"

section 'a JSON page has a working tracking switch'
# A .json page is committed on save like a .md, but the switch stored nothing for it and
# the page reported tracking as off — the button showed the opposite of what happened.
fixture_page 'Other/data.json' '{"a": 1}'
P add -A; P commit -m 'add data'
get_as "$JAR" 'api.php?action=indexfiles&space=Other' > /dev/null
jget() { get_as "$JAR" 'api.php?action=get&space=Other&file=data.json'; }
assert_contains "tracked by default, and says so"  '"git_commit":true' "$(jget)"
r=$(post_as "$JAR" 'api.php?action=set_git_commit&space=Other' 'file=data.json&enabled=0')
assert_contains "switching it off (control)"       '"success":true' "$r"
assert_contains "the page now reports it off"      '"git_commit":false' "$(jget)"
before=$(git -C "$WIKI_PAGES" rev-list --count HEAD)
post_as "$JAR" 'api.php?action=save&space=Other&file=data.json' '{"a": 2}' > /dev/null
assert_contains "the save happened (control)"      '"a": 2' "$(cat "$WIKI_PAGES/Other/data.json")"
assert_eq "and made no commit" "$before" "$(git -C "$WIKI_PAGES" rev-list --count HEAD)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
