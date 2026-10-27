#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Moving a page or a folder into another Space.
#
# Ids are per Space, so the target index has to mint a new one — but everything else in the
# index entry is the page's own. The single-file branch used to call addPage() with the
# *mover* as creator and no tags at all, and the folder branch kept the creator but still
# dropped the tags: a move silently untagged every page it touched. The taxonomy is
# wiki-wide, so a tag accepted in one Space is valid in the other and is carried as is.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_space Bravo
fixture_users '{"users":[
  {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
  {"uid":2,"name":"Mover","role":"admin","auth":"otp","email":"mover@example.com"}]}'
JAR=$WIKI_ROOT/jar;   fixture_login "$JAR"  "uid=1&auth=otp&name=Admin&role=admin"
MOVER=$WIKI_ROOT/mjar; fixture_login "$MOVER" "uid=2&auth=otp&name=Mover&role=admin"

get_as "$JAR" 'api.php?action=list_spaces' > /dev/null
# Created by Admin through the API — a page adopted by indexfiles has no creator at all —
# so Admin is the creator a move must keep.
mkdir -p "$WIKI_PAGES/Main/Folder"
post_as "$JAR" 'api.php?action=create_file&space=Main' 'path=Trip.md'          > /dev/null
post_as "$JAR" 'api.php?action=create_file&space=Main' 'path=Folder/Inside.md' > /dev/null

# entry <space> <path> <field> — a field of the page's index entry, as JSON.
entry() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
e = next((v for v in ix.values() if v.get("path") == sys.argv[2]), None)
print("MISSING" if e is None else json.dumps(e.get(sys.argv[3])))' "$WIKI_PAGES/$1/index.json" "$2" "$3"
}
id_of() { python3 -c 'import json,sys
ix = json.load(open(sys.argv[1])); print(next((k for k, v in ix.items() if v.get("path") == sys.argv[2]), ""))' \
    "$WIKI_PAGES/$1/index.json" "$2"; }
tag() { post_as "$JAR" 'api.php?action=update_tags&space=Main' "id=$(id_of Main "$1")&tags=$2"; }
mv()  { post_as "$MOVER" 'api.php?action=move&space=Main' "old_path=$1&new_path=$2&target_space=Bravo"; }

assert_contains "created by Admin (control)"   '"Admin"' "$(entry Main Trip.md createdBy)"
assert_contains "tagging the page (control)"   '"success":true' "$(tag Trip.md '["travel","2026"]')"
assert_contains "tagging the folder's page"    '"success":true' "$(tag Folder/Inside.md '["inner"]')"

section 'a single page'
assert_contains "the move succeeds (control)"  '"success":true' "$(mv Trip.md Trip.md)"
assert_eq "it is in the target index"          '["travel", "2026"]' "$(entry Bravo Trip.md tags)"
assert_contains "its creator is kept, not the mover" '"Admin"' "$(entry Bravo Trip.md createdBy)"
assert_eq "and it is gone from the source"     'MISSING' "$(entry Main Trip.md tags)"

section 'a folder'
assert_contains "the move succeeds (control)"  '"success":true' "$(mv Folder Folder)"
assert_eq "the page inside keeps its tags"     '["inner"]' "$(entry Bravo Folder/Inside.md tags)"
assert_contains "and its creator"              '"Admin"'   "$(entry Bravo Folder/Inside.md createdBy)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
