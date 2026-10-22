#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Deleting a Space (Space settings → Advanced).
#
# A soft delete: the folder moves to WIKI_SYSTEM_DATA/deleted_spaces/, and everything that
# named the space forgets it. The assertions that matter most are the ones about what must
# NOT happen — a non-admin deleting, the last or a frozen space going, a user whose only
# space it was suddenly seeing every space, and a browser still open on the deleted space
# writing its next save to the root of PAGES_DIR. Each denial has a positive control.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Keep; fixture_space Doomed; fixture_space Frozen
fixture_page "Keep/page.md"           '# Keep'
fixture_page "Doomed/page.md"         '# Doomed
DOOMED-CONTENT'
fixture_page "Doomed/sub/deep.md"     '# Deep'
fixture_page "Frozen/page.md"         '# Frozen'

fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Ed","role":"editor","auth":"oidc"},
  {"uid":3,"sub":"sub-3","name":"Only","role":"editor","auth":"oidc","spaces":["Doomed"]},
  {"uid":4,"sub":"sub-4","name":"Both","role":"editor","auth":"oidc","spaces":["Keep","Doomed"]},
  {"uid":5,"sub":"sub-5","name":"Open","role":"editor","auth":"oidc"}
]}'
cat > "$WIKI_SYS/agent_jobs.json" <<'JSON'
{"jobs":[{"id":"j1","name":"Nightly","space":"Doomed","enabled":true},
         {"id":"j2","name":"Other","space":"Keep","enabled":true}]}
JSON

ADMIN=$WIKI_ROOT/jar-admin; ED=$WIKI_ROOT/jar-ed
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=sub-2&name=Ed&role=editor"

# ── refusals ─────────────────────────────────────────────────────────────────
section 'who may delete, and what'
r=$(post_as "$ED" 'api.php?action=admin_delete_space' 'name=Doomed&confirm=Doomed')
assert_not_contains "an editor is refused"            '"success":true' "$r"
assert_file_exists  "  and the space is still there"  "$WIKI_PAGES/Doomed/page.md"

r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=Doomed&confirm=doomed')
assert_contains     "a mistyped name is refused"      '"blocked":"confirm-mismatch"' "$r"
assert_file_exists  "  and the space is still there"  "$WIKI_PAGES/Doomed/page.md"

post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Frozen&readonly=1' > /dev/null
r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=Frozen&confirm=Frozen')
assert_contains     "a frozen space is refused"       '"blocked":"source-readonly"' "$r"
assert_file_exists  "  and the space is still there"  "$WIKI_PAGES/Frozen/page.md"

r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=..&confirm=..')
assert_not_contains "'..' is refused"                 '"success":true' "$r"
assert_file_exists  "  and PAGES_DIR is intact"       "$WIKI_PAGES/Keep/page.md"

# ── the delete ───────────────────────────────────────────────────────────────
section 'deleting a space'
r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=Doomed&confirm=Doomed')
assert_contains     "it succeeds"                     '"success":true' "$r"
assert_contains     "  and names a space to go to"    '"next":"' "$r"
assert_file_missing "the folder left PAGES_DIR"       "$WIKI_PAGES/Doomed"
trash=$(ls -d "$WIKI_SYS"/deleted_spaces/Doomed-* 2>/dev/null | head -1)
assert_file_exists  "it is in deleted_spaces"         "${trash:-/nonexistent}/page.md"
assert_file_exists  "  subfolders and all"            "${trash:-/nonexistent}/sub/deep.md"
ls=$(get_as "$ADMIN" 'api.php?action=list_spaces')
assert_not_contains "list_spaces no longer has it"    '"Doomed"' "$ls"
assert_contains     "  but still has the others"      '"Keep"' "$ls"

section 'what named it'
users=$(cat "$WIKI_SYS/users.json")
only=$(python3 -c 'import json,sys; u=[x for x in json.load(open(sys.argv[1]))["users"] if x["uid"]==3][0]; print(json.dumps(u.get("spaces","MISSING")))' "$WIKI_SYS/users.json")
assert_eq           "a user whose only space it was keeps an EMPTY list, not none" '[]' "$only"
both=$(python3 -c 'import json,sys; u=[x for x in json.load(open(sys.argv[1]))["users"] if x["uid"]==4][0]; print(json.dumps(u.get("spaces")))' "$WIKI_SYS/users.json")
assert_eq           "a user with two keeps the other"  '["Keep"]' "$both"
open=$(python3 -c 'import json,sys; u=[x for x in json.load(open(sys.argv[1]))["users"] if x["uid"]==5][0]; print("spaces" in u)' "$WIKI_SYS/users.json")
assert_eq           "an unrestricted user stays unrestricted" 'False' "$open"
jobs=$(python3 -c 'import json,sys; print({j["id"]:j["enabled"] for j in json.load(open(sys.argv[1]))["jobs"]})' "$WIKI_SYS/agent_jobs.json")
assert_eq           "its scheduled job is switched off, the other is not" "{'j1': False, 'j2': True}" "$jobs"

section 'a browser still open on the deleted space'
r=$(curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary '# stray' \
    "$WIKI_URL/api.php?action=save&space=Doomed&file=stray.md")
assert_contains     "a save is refused"               '"space_missing":"Doomed"' "$r"
assert_file_missing "  and nothing lands at the root" "$WIKI_PAGES/stray.md"
r=$(curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary '# fine' \
    "$WIKI_URL/api.php?action=save&space=Keep&file=fine.md")
assert_file_exists  "  while a save to a live space works" "$WIKI_PAGES/Keep/fine.md"

section 'the last space'
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Frozen&readonly=0' > /dev/null
r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=Frozen&confirm=Frozen')
assert_contains     "unfrozen, the second-to-last goes" '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_delete_space' 'name=Keep&confirm=Keep')
assert_contains     "the last one is refused"         '"blocked":"last-space"' "$r"
assert_file_exists  "  and is still there"            "$WIKI_PAGES/Keep/page.md"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
