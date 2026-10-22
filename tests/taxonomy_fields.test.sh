#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Front-matter fields bound to a branch of the taxonomy (taxonomy step 3).
#
# `department` bound to the Department folder means `department:` takes the name of an
# item inside it. The rules worth a test each: the binding is validated (reserved and
# duplicate names); a value set through the wiki must be one of the items, spelled as the
# tree spells it, and a list only where the field allows several; renaming or merging a
# value follows into every page's block (not a frozen Space's) with mtime kept; a value in
# use cannot be deleted; search can filter on it, a value finding the items below it.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Alpha; fixture_space Bravo
fixture_page 'Alpha/p1.md' '---
department: Finance
status: Draft
---
# P1'
fixture_page 'Alpha/p2.md' '---
department: Payroll
---
# P2'
fixture_page 'Alpha/p3.md' '# P3'
fixture_page 'Alpha/p4.md' '# P4'
fixture_page 'Bravo/b1.md' '---
department: Finance
---
# B1'
fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Ed","role":"editor","auth":"oidc"},
  {"uid":3,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_fields",
   "ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin; ED=$WIKI_ROOT/jar-ed
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=sub-2&name=Ed&role=editor"
for s in Alpha Bravo; do
    get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null
    post_as "$ADMIN" 'api.php?action=admin_set_space_fm_edit' "space_name=$s&mode=manual" > /dev/null
done
touch -d '2020-01-01 00:00:00' "$WIKI_PAGES"/Alpha/*.md "$WIKI_PAGES"/Bravo/*.md

enc() { python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
imp() { post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(enc "$1")$2"; }
setfm() { post_as "$ED" "api.php?action=set_frontmatter&space=$1" "file=$2&updates=$(enc "$3")&removals=%5B%5D"; }
file() { cat "$WIKI_PAGES/$1"; }
mtime() { stat -c %Y "$WIKI_PAGES/$1"; }
node() { post_as "$ADMIN" 'api.php?action=admin_taxonomy_node' "$1"; }

section 'binding a field is validated'
r=$(imp '{"nodes":[{"name":"A","heading":true,"field":"tags"}]}' '&dry_run=1')
assert_contains "a reserved name is refused"        'is reserved' "$r"
r=$(imp '{"nodes":[{"name":"A","heading":true,"field":"dept"},{"name":"B","heading":true,"field":"DEPT"}]}' '&dry_run=1')
assert_contains "a field bound twice is refused"    'already bound' "$r"
r=$(imp '{"nodes":[{"name":"A","field":"dept","children":[{"name":"B"}]}]}' '&dry_run=1')
assert_contains "a field on an item is refused — only a folder can be bound" 'only a folder can be bound' "$r"
r=$(imp '{"nodes":[{"name":"A","heading":true,"field":"two words"}]}' '&dry_run=1')
assert_contains "a name with a space is refused"    'no spaces' "$r"
r=$(imp '{"nodes":[
  {"name":"Department","heading":true,"field":"department","children":[
     {"name":"Finance","children":[{"name":"Payroll"}]},{"name":"HR"}]},
  {"name":"Status","heading":true,"field":"status","children":[{"name":"Draft"},{"name":"Final"}]},
  {"name":"Audience","heading":true,"field":"audience","multiple":true,"children":[{"name":"Staff"},{"name":"Managers"}]}]}' '')
assert_contains "a valid tree imports"              '"success":true' "$r"
r=$(get_as "$ADMIN" 'api.php?action=get_taxonomy')
assert_contains "and the binding comes back"        '"field":"department"' "$r"

section 'setting a value through the Metadata panel'
r=$(setfm Alpha p3.md '{"department":"finance"}')
assert_contains "a value of the branch is accepted" '"success":true' "$r"
assert_contains "  in the tree's spelling"          'department: Finance' "$(file Alpha/p3.md)"
r=$(setfm Alpha p3.md '{"department":"Nope"}')
assert_contains "a value outside it is refused"     'not a value of \"department\"' "$r"
assert_contains "  naming the values"               'Finance, Payroll, HR' "$r"
assert_contains "  and the file keeps the old one"  'department: Finance' "$(file Alpha/p3.md)"
r=$(setfm Alpha p3.md '{"department":["Finance","HR"]}')
assert_contains "a list on a one-value field is refused" 'takes one value' "$r"
r=$(setfm Alpha p3.md '{"audience":["staff","Managers"]}')
assert_contains "a list where the field allows it"  '"success":true' "$r"
assert_contains "  spelled by the tree"             'audience: [Staff, Managers]' "$(file Alpha/p3.md)"
r=$(setfm Alpha p3.md '{"Department":"Status"}')
assert_contains "a folder is not a value either"    'not a value' "$r"
r=$(setfm Alpha p3.md '{"owner":"Anyone at all"}')
assert_contains "a field that is not bound is free" '"success":true' "$r"

section 'renaming a value follows into the pages'
before=$(mtime Alpha/p1.md)
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Bravo&readonly=1' > /dev/null
r=$(node 'op=update&path=Department/Finance&name=Money')
assert_contains "the rename succeeds"               '"success":true' "$r"
assert_contains "Alpha's page follows"              'department: Money' "$(file Alpha/p1.md)"
assert_contains "  with its other fields as they were" 'status: Draft' "$(file Alpha/p1.md)"
assert_eq       "  and its mtime kept"              "$before" "$(mtime Alpha/p1.md)"
assert_contains "a frozen Space's page is left alone" 'department: Finance' "$(file Bravo/b1.md)"
assert_contains "a child value is not touched"      'department: Payroll' "$(file Alpha/p2.md)"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Bravo&readonly=0' > /dev/null

section 'merging a value'
r=$(node 'op=merge&path=Status/Draft&target=Status/Final')
assert_contains "the merge succeeds"                '"success":true' "$r"
assert_contains "  and the page's value follows"    'status: Final' "$(file Alpha/p1.md)"

section 'deleting'
r=$(node 'op=delete&path=Department/Money/Payroll')
assert_contains "a value in use is refused"         'as a field value' "$r"
r=$(node 'op=delete&path=Department/HR')
assert_contains "an unused one is deleted"          '"success":true' "$r"
r=$(get_as "$ADMIN" 'api.php?action=admin_taxonomy')
assert_contains "usage is reported to the editor"   '"field_counts":{' "$r"
# p1 and p3 (set to Finance above, then renamed with it)
assert_contains "  per value"                       '"money":2' "$r"

section 'search'
q() { get_as "$ED" "api.php?action=advanced_search&space=Alpha&q=$(enc "$1")"; }
r=$(q 'department:Money')
assert_contains "department:Money finds the page"   '"path":"p1.md"' "$r"
assert_contains "  and the one below it (Payroll)"  '"path":"p2.md"' "$r"
assert_not_contains "  but not a page without it"   '"path":"p4.md"' "$r"
r=$(q 'department:payroll')
assert_contains "a child value finds only its own"  '"path":"p2.md"' "$r"
assert_not_contains "  not its parent's"            '"path":"p1.md"' "$r"
r=$(q 'audience:Staff')
assert_contains "a list field matches any of its values" '"path":"p3.md"' "$r"
r=$(q 'owner:Anyone')
assert_not_contains "an unbound field is not a filter" '"path":"p3.md"' "$r"

section 'AI users'
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_fields" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_get_taxonomy","arguments":{}}}' "$WIKI_URL/mcp.php")
assert_contains "wiki_get_taxonomy lists the fields" 'department (one of): Money, Payroll' "$r"
assert_contains "  and which take several"          'audience (one or more of): Staff, Managers' "$r"
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_fields" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wiki_search_pages","arguments":{"fields":{"department":"Money"}}}}' \
    "$WIKI_URL/mcp.php?space=Alpha")
assert_contains "wiki_search_pages filters on a field" 'p1.md' "$r"
assert_not_contains "  and leaves out the rest"     'p4.md' "$r"

section 'workflows'
wf() { post_as "$ADMIN" 'api.php?action=admin_save_workflow' "workflow=$(enc "$1")"; }
r=$(wf '{"name":"W","enabled":false,"space":"*","trigger":{"type":"page_created"},"actions":[{"type":"frontmatter","field":"status","value":"Nope"}]}')
assert_contains "a fixed value outside the branch is refused when saved" 'not a value of \"status\"' "$r"
r=$(wf '{"name":"W2","enabled":false,"space":"*","trigger":{"type":"page_created"},"actions":[{"type":"frontmatter","field":"status","value":"final"}]}')
assert_contains "a value in it is accepted"        '"success":true' "$r"
r=$(wf '{"name":"W3","enabled":false,"space":"*","trigger":{"type":"page_created"},"actions":[{"type":"frontmatter","field":"status","value":"{page.name}"}]}')
assert_contains "a template waits until it runs"   '"success":true' "$r"

section 'binding through the editor'
r=$(node 'op=update&path=Status&field=updated')
assert_contains "a reserved name is refused"        'reserved' "$r"
r=$(node 'op=update&path=Department/Money&field=moneyarea')
assert_contains "an item cannot be bound"           'only a folder can be bound' "$r"
r=$(node 'op=add&parent=Department&name=Bound&field=x')
assert_contains "  nor added already bound"         'nly a folder can be bound' "$r"
r=$(node 'op=update&path=Department&heading=')
assert_contains "a bound folder cannot become an item" 'Remove the binding first' "$r"
r=$(node 'op=update&path=Status&field=')
assert_contains "a binding can be removed"          '"success":true' "$r"
r=$(setfm Alpha p3.md '{"status":"Anything"}')
assert_contains "  after which the field is free"   '"success":true' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
