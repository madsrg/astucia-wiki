#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# A folder sits at the top level or inside another folder, never inside an item.
#
# Every route that could put one there is refused — add, change type (both directions),
# move, merge, import — each with a positive control beside it, because a refusal that
# fires for an unrelated reason would pass on its own. A typed nested tag creates its
# missing parents as items once inside an item. And a tree saved before the rule still
# loads and still tags: it is reported, not refused.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_page 'Main/a.md' '# A'
fixture_users '{"users":[{"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"}]}'
ADMIN=$WIKI_ROOT/jar-admin
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

enc() { python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
node() { post_as "$ADMIN" 'api.php?action=admin_taxonomy_node' "$1"; }
paths() {
    python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
def walk(ns, p):
    for n in ns:
        q = p + "/" + n["name"] if p else n["name"]
        print(q + ("#F" if n.get("heading") else ""))
        walk(n.get("children", []), q)
walk(d.get("nodes", []), "")' "$WIKI_PAGES/taxonomy.json" | tr '\n' ' '
}
id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == sys.argv[2]))' "$WIKI_PAGES/Main/index.json" "$1"
}
RULE='not inside an item'

post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(enc '{"nodes":[
  {"name":"Dept","heading":true,"children":[{"name":"Finance"},{"name":"Sub","heading":true,"children":[{"name":"X"}]}]},
  {"name":"Loose"},
  {"name":"Box","heading":true,"children":[{"name":"Inner","heading":true}]}]}')" > /dev/null

section 'add'
r=$(node 'op=add&parent=Dept/Finance&name=Regions&heading=1')
assert_contains "a folder inside an item is refused" "$RULE" "$r"
r=$(node 'op=add&parent=Dept/Finance&name=Budget')
assert_contains "  while an item there is fine"     '"success":true' "$r"
r=$(node 'op=add&parent=Dept&name=Group&heading=1')
assert_contains "  and a folder inside a folder"    '"success":true' "$r"

section 'changing the type'
r=$(node 'op=update&path=Dept/Finance/Budget&heading=1')
assert_contains "an item inside an item cannot become a folder" "$RULE" "$r"
r=$(node 'op=update&path=Dept/Group&heading=')
assert_contains "  a folder with no folders in it can become an item" '"success":true' "$r"
r=$(node 'op=update&path=Box&heading=')
assert_contains "a folder holding a folder cannot become an item" 'contains folders' "$r"
assert_contains "  and stays a folder"              'Box#F ' "$(paths)"

section 'move'
r=$(node 'op=move&path=Box/Inner&parent=Loose')
assert_contains "a folder cannot move into an item" "$RULE" "$r"
r=$(node 'op=move&path=Box/Inner&parent=Dept')
assert_contains "  but into a folder it can"        '"success":true' "$r"
r=$(node 'op=move&path=Dept/Finance/Budget&parent=Loose')
assert_contains "  and an item into an item"        '"success":true' "$r"

section 'merge'
r=$(node 'op=merge&path=Dept&target=Loose')
assert_contains "merging a folder that holds folders into an item is refused" 'contains folders' "$r"
r=$(node 'op=merge&path=Dept/Sub&target=Loose')
assert_contains "  one holding only items is fine"  '"success":true' "$r"
assert_contains "  its items land in the item"      'Loose/X ' "$(paths)"

section 'typed nested tags'
post_as "$ADMIN" "api.php?action=update_tags&space=Main" "id=$(id_of a.md)&tags=$(enc '["Dept/Finance/Regions/EU","New/Thing"]')" > /dev/null
t=$(paths)
assert_contains "a parent created inside an item is an item" 'Dept/Finance/Regions ' "$t"
assert_not_contains "  not a folder"                'Dept/Finance/Regions#F' "$t"
assert_contains "while one at the top level is still a folder" 'New#F ' "$t"

section 'import'
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "dry_run=1&data=$(enc '{"nodes":[{"name":"I","children":[{"name":"F","heading":true}]}]}')")
assert_contains "a file with a folder inside an item is refused" "$RULE" "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "dry_run=1&data=$(enc '{"nodes":[{"name":"F","heading":true,"children":[{"name":"I"}]}]}')")
assert_contains "  one without is fine"             '"dry_run":true' "$r"

section 'a tree from before the rule'
cat > "$WIKI_PAGES/taxonomy.json" <<'JSON'
{"version":1,"nodes":[{"name":"Old","children":[{"name":"Grouping","heading":true,"children":[{"name":"Leaf"}]}]}]}
JSON
r=$(get_as "$ADMIN" 'api.php?action=admin_taxonomy')
assert_contains "it still loads"                    '"name":"Grouping"' "$r"
assert_contains "  and the misplaced folder is reported" '"misplaced":["Old\/Grouping"]' "$r"
r=$(post_as "$ADMIN" "api.php?action=update_tags&space=Main" "id=$(id_of a.md)&tags=$(enc '["Old/Grouping/Leaf"]')")
assert_contains "  tagging with it still works"     '"success":true' "$r"
r=$(node 'op=add&parent=Old&name=Other')
assert_contains "  and so do other edits"           '"success":true' "$r"
r=$(node 'op=update&path=Old/Grouping&heading=')
assert_contains "making it an item fixes it"        '"success":true' "$r"
assert_contains "  and nothing is reported any more" '"misplaced":[]' "$(get_as "$ADMIN" 'api.php?action=admin_taxonomy')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
