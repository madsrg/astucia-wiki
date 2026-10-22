#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The wiki-wide taxonomy (taxonomy.php): nested tags, the three modes, the admin
# operations that rewrite tags across every Space, export/import, and the reserved
# taxonomy.json at the root of PAGES_DIR.
#
# Every refusal is paired with a positive control, and every destructive operation has
# its own term, so an operation that silently did nothing cannot pass by accident.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Alpha; fixture_space Bravo
for p in a1 a2 a3 a4 a5; do fixture_page "Alpha/$p.md" "# $p"; done
for p in b1 b2;          do fixture_page "Bravo/$p.md" "# $p"; done

fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Ed","role":"editor","auth":"oidc"},
  {"uid":3,"sub":"sub-3","name":"Rae","role":"reader","auth":"oidc"},
  {"uid":4,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_taxtest",
   "ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin; ED=$WIKI_ROOT/jar-ed; RAE=$WIKI_ROOT/jar-rae
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=sub-2&name=Ed&role=editor"
fixture_login "$RAE"   "uid=3&sub=sub-3&name=Rae&role=reader"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Alpha' > /dev/null
get_as "$ADMIN" 'api.php?action=indexfiles&space=Bravo' > /dev/null

TAX="$WIKI_PAGES/taxonomy.json"
id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == sys.argv[2]))' "$WIKI_PAGES/$1/index.json" "$2"
}
tags_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(json.dumps(next(v for v in ix.values() if v.get("path") == sys.argv[2]).get("tags", []), ensure_ascii=False))' \
        "$WIKI_PAGES/$1/index.json" "$2"
}
# tag <jar> <space> <page> <json list>
tag() { post_as "$1" "api.php?action=update_tags&space=$2" "id=$(id_of "$2" "$3")&tags=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$4")"; }
node() { post_as "$ADMIN" 'api.php?action=admin_taxonomy_node' "$1"; }
paths() {
    python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
def walk(ns, p):
    for n in ns:
        q = p + "/" + n["name"] if p else n["name"]
        print(q + ("#H" if n.get("heading") else ""))
        walk(n.get("children", []), q)
walk(d.get("nodes", []), "")' "$TAX" | tr '\n' ' '
}

# ── free mode ────────────────────────────────────────────────────────────────
section 'free mode: a new tag joins the tree'
r=$(tag "$ED" Alpha a1.md '["Finance/Budget"]')
assert_contains "an editor can tag"                  '"success":true' "$r"
assert_contains "  the tree now has Finance, as a folder" 'Finance#H ' "$(paths)"
assert_contains "  and Finance/Budget below it"      'Finance/Budget ' "$(paths)"
r=$(tag "$ED" Alpha a2.md '["finance/budget","  Finance  /  Tax "]')
assert_eq       "matching is case-insensitive and takes the tree's spelling" \
                '["Finance/Budget", "Finance/Tax"]' "$(tags_of Alpha a2.md)"
assert_contains "  and a new child lands under the existing parent" 'Finance/Tax ' "$(paths)"
assert_not_contains "  without a second, lower-case Finance" 'finance ' "$(paths)"

# ── admin operations ─────────────────────────────────────────────────────────
section 'only admins edit the tree'
r=$(post_as "$ED" 'api.php?action=admin_taxonomy_node' 'op=add&name=Sneaky')
assert_not_contains "an editor cannot add a term"    '"success":true' "$r"
assert_not_contains "  and it is not there"          'Sneaky' "$(paths)"
r=$(node 'op=add&name=Department&heading=1')
assert_contains "an admin can"                       '"success":true' "$r"
assert_contains "  and it is a heading"              'Department#H' "$(paths)"
node 'op=add&parent=Department&name=HR' > /dev/null
node 'op=add&parent=Department&name=Sales' > /dev/null
node 'op=add&name=Unused' > /dev/null

section 'a folder groups but is never a tag'
r=$(tag "$ED" Alpha a3.md '["Department"]')
assert_contains "it is refused"                      '"refused":true' "$r"
assert_eq       "  and nothing was written"          '[]' "$(tags_of Alpha a3.md)"
r=$(tag "$ED" Alpha a3.md '["department/hr"]')
assert_eq       "while a term below it is fine"      '["Department/HR"]' "$(tags_of Alpha a3.md)"

section 'rename rewrites tags in every Space'
tag "$ED" Bravo b1.md '["Finance/Budget"]' > /dev/null
r=$(node 'op=update&path=Finance&name=Money')
assert_contains "the rename succeeds"                '"success":true' "$r"
assert_contains "  and reports the pages it changed" '"rewritten":3' "$r"
assert_eq       "Alpha's page follows"               '["Money/Budget", "Money/Tax"]' "$(tags_of Alpha a2.md)"
assert_eq       "Bravo's page follows"               '["Money/Budget"]' "$(tags_of Bravo b1.md)"
assert_not_contains "the old name is gone from the tree" 'Finance' "$(paths)"
assert_contains "a rename keeps a folder a folder"   'Money#H ' "$(paths)"

section 'move and merge'
r=$(node 'op=move&path=Money/Tax&parent=Department')
assert_contains "a move succeeds"                    '"success":true' "$r"
assert_eq       "  and the page follows it"          '["Money/Budget", "Department/Tax"]' "$(tags_of Alpha a2.md)"
r=$(node 'op=move&path=Department&parent=Department/HR')
assert_contains "a term cannot move into itself"     'into itself' "$r"
r=$(node 'op=merge&path=Department/Tax&target=Money/Budget')
assert_contains "a merge succeeds"                   '"success":true' "$r"
assert_eq       "  folding a page's two tags into one" '["Money/Budget"]' "$(tags_of Alpha a2.md)"
assert_not_contains "  and the source is gone"       'Department/Tax' "$(paths)"

section 'delete'
r=$(node 'op=delete&path=Money')
assert_contains "a term in use is refused"           'Merge it into another term' "$r"
assert_contains "  and kept"                         'Money/Budget ' "$(paths)"
r=$(node 'op=delete&path=Unused')
assert_contains "an unused term is deleted"          '"success":true' "$r"
assert_not_contains "  and gone"                     'Unused' "$(paths)"

# ── modes ────────────────────────────────────────────────────────────────────
section 'controlled mode'
r=$(post_as "$ED" 'api.php?action=admin_taxonomy_mode' 'mode=controlled')
assert_not_contains "an editor cannot change the mode" '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_mode' 'mode=controlled')
assert_contains "an admin can"                       '"mode":"controlled"' "$r"
before=$(paths)
r=$(tag "$ED" Alpha a4.md '["Brand New"]')
assert_contains "a tag that is not a term is refused" '"refused":true' "$r"
assert_eq       "  nothing was written to the page"  '[]' "$(tags_of Alpha a4.md)"
assert_eq       "  and the tree did not grow"        "$before" "$(paths)"
r=$(tag "$ED" Alpha a4.md '["money/budget"]')
assert_eq       "a term is still accepted"           '["Money/Budget"]' "$(tags_of Alpha a4.md)"
# A tag that predates the mode stays, and the page can still be re-tagged around it.
python3 - "$WIKI_PAGES/Alpha/index.json" <<'PY'
import json,sys
p=sys.argv[1]; ix=json.load(open(p))
for v in ix.values():
    if v.get("path")=="a5.md": v["tags"]=["legacy"]
json.dump(ix,open(p,"w"))
PY
r=$(tag "$ED" Alpha a5.md '["legacy","Money/Budget"]')
assert_eq       "an existing out-of-tree tag is kept, not refused" '["legacy", "Money/Budget"]' "$(tags_of Alpha a5.md)"

section 'workflows are checked when saved'
wf='{"name":"T","enabled":false,"space":"*","trigger":{"type":"page_created"},"filters":{},"actions":[{"type":"tag","add":["Nope"]}]}'
r=$(post_as "$ADMIN" 'api.php?action=admin_save_workflow' "workflow=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$wf")")
assert_contains "a tag action naming a non-term is refused" 'not a term in the taxonomy' "$r"

section 'AI users'
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_taxtest" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_get_taxonomy","arguments":{}}}' "$WIKI_URL/mcp.php")
# The tool's text arrives JSON-encoded, slashes escaped.
assert_contains "wiki_get_taxonomy lists the terms"  'Money\/Budget' "$r"
assert_contains "  marks folders"                    'Department (folder, not a tag)' "$r"
assert_contains "  and states the mode"              'Mode: controlled' "$r"
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_taxtest" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wiki_add_tags","arguments":{"path":"b2.md","tags":["Invented"]}}}' \
    "$WIKI_URL/mcp.php?space=Bravo")
assert_contains "an AI tag outside the tree is refused with a pointer" 'wiki_get_taxonomy' "$r"
assert_eq       "  and not written"                  '[]' "$(tags_of Bravo b2.md)"

post_as "$ADMIN" 'api.php?action=admin_taxonomy_mode' 'mode=free' > /dev/null

# ── reading ──────────────────────────────────────────────────────────────────
section 'reading the tree'
r=$(get_as "$RAE" 'api.php?action=get_taxonomy')
assert_contains "a reader gets the tree"             '"name":"Money"' "$r"
assert_contains "  with usage counts"                '"money\/budget":' "$r"
r=$(get_as "$ED" 'api.php?action=get_pages_by_tag&space=Alpha&tag=Money')
assert_contains "a parent term finds its children's pages" '"path":"a2.md"' "$r"
assert_not_contains "  but not a prefix that is not a parent" '"path":"a3.md"' "$r"

section 'a typed parent is a folder'
r=$(tag "$ED" Alpha a1.md '["Money"]')
assert_contains "so typing it alone is refused"       'is a folder' "$r"

section 'unclassified and adopt'
r=$(get_as "$ADMIN" 'api.php?action=admin_taxonomy')
assert_contains "a tag in use outside the tree is listed" '"tag":"legacy"' "$r"
# A parent pages use directly must be adopted as an item, not the folder a typed
# nested tag would make it.
python3 - "$WIKI_PAGES/Bravo/index.json" <<'PY'
import json,sys
p=sys.argv[1]; ix=json.load(open(p))
for v in ix.values():
    if v.get("path")=="b2.md": v["tags"]=["Proj","Proj/X"]
json.dump(ix,open(p,"w"))
PY
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_adopt' '')
assert_contains "adopting adds them"                 '"added":3' "$r"
assert_contains "  to the tree, as an item"          'legacy ' "$(paths)"
assert_contains "  a used parent as an item too"     'Proj Proj/X ' "$(paths)"

# ── the reserved file ────────────────────────────────────────────────────────
section 'taxonomy.json is not a page'
r=$(curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary '{"nodes":[]}' "$WIKI_URL/api.php?action=save&file=taxonomy.json")
assert_contains "save refuses it"                    'reserved for the taxonomy' "$r"
assert_contains "  and the tree is intact"           'Money/Budget ' "$(paths)"
r=$(get_as "$ADMIN" 'api.php?action=get&file=taxonomy.json')
assert_contains "get refuses it"                     'reserved for the taxonomy' "$r"
r=$(curl -s -X POST -H "Authorization: Bearer wk_ai_taxtest" -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wiki_write_json","arguments":{"path":"taxonomy.json","content":"{}"}}}' "$WIKI_URL/mcp.php")
assert_contains "an AI write is refused"             'not a page' "$r"
assert_contains "  and the tree is intact"           'Money/Budget ' "$(paths)"
get_as "$ADMIN" 'api.php?action=indexfiles' > /dev/null
assert_not_contains "a root reindex does not index it" 'taxonomy.json' "$(cat "$WIKI_PAGES/index.json" 2>/dev/null)"
r=$(post_as "$ADMIN" 'api.php?action=create_space' 'name=taxonomy.json')
assert_contains "a Space cannot take its name"       'reserved' "$r"
r=$(curl -s -b "$ADMIN" -c "$ADMIN" -X POST --data-binary '{"a":1}' "$WIKI_URL/api.php?action=save&space=Alpha&file=taxonomy.json")
assert_file_exists "  while a page of that name inside a Space is fine" "$WIKI_PAGES/Alpha/taxonomy.json"

# ── export / import ──────────────────────────────────────────────────────────
section 'import'
r=$(post_as "$ED" 'api.php?action=admin_taxonomy_import' 'dry_run=1&data=%5B%5D')
assert_not_contains "an editor cannot import"        '"success":true' "$r"
bad='{"format":"astucia-taxonomy","nodes":[{"name":"A/B"},{"name":"X"},{"name":"x"},{"children":[]}]}'
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "dry_run=1&data=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$bad")")
assert_contains "an invalid file is refused"         '"success":false' "$r"
assert_contains "  naming the slash"                 'contains \"\/\"' "$r"
assert_contains "  the duplicate"                    'appears twice' "$r"
assert_contains "  and the nameless item"            'has no name' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$bad")")
assert_contains "  even without dry_run"             '"success":false' "$r"
assert_contains "  and the tree is untouched"        'Money/Budget ' "$(paths)"
good='{"format":"astucia-taxonomy","version":1,"nodes":[{"name":"Status","heading":true,"children":[{"name":"Draft"},{"name":"Final","description":"Signed off"}]}]}'
enc=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$good")
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "dry_run=1&data=$enc")
assert_contains "a dry run validates"                '"dry_run":true' "$r"
assert_contains "  counts the terms"                 '"terms":3' "$r"
assert_contains "  and says how many tags in use would be unclassified" '"unclassified":' "$r"
assert_contains "  without replacing anything"       'Money/Budget ' "$(paths)"
r=$(post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$enc")
assert_contains "the real import succeeds"           '"success":true' "$r"
assert_eq       "  and replaces the tree"            'Status#H Status/Draft Status/Final ' "$(paths)"
assert_eq       "  leaving pages' tags alone"        '["Money/Budget"]' "$(tags_of Bravo b1.md)"
r=$(get_as "$ADMIN" 'api.php?action=admin_taxonomy')
assert_contains "  which now show as unclassified"   '"tag":"Money\/Budget"' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
