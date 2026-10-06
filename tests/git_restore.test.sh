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

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
