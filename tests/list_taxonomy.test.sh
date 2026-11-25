#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# A list's Choice field taking its values from a taxonomy folder (taxonomy_lists.php): the
# save check (a value a save adds must be an item of the folder; one value unless several are
# allowed; a value already held passes), arrays in a group's sub-field, the read side (cells,
# exports, generated pages), the AI tool — and the taxonomy's admin operations following into
# lists: an item renamed or merged renames the values, a folder renamed or moved retargets the
# columns, and deleting what a list uses is refused. Every refusal leaves the files as they were.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start off || exit 1
trap fixture_stop EXIT
fixture_space Main
curl -s "$WIKI_URL/api.php?action=list_spaces" > /dev/null

node() { curl -s -X POST --data "$1" "$WIKI_URL/api.php?action=admin_taxonomy_node"; }
ok()   { python3 -c 'import json,sys; d=json.load(sys.stdin); print("ok" if d.get("success") else "no: " + str(d.get("message")))'; }
# Department/{Finance/{Budget,Payroll}, Sales}; Region/{Nordic}; Team/{Alpha}
for args in 'op=add&name=Department&heading=1' 'op=add&parent=Department&name=Finance&heading=1' \
            'op=add&parent=Department/Finance&name=Budget' 'op=add&parent=Department/Finance&name=Payroll' \
            'op=add&parent=Department&name=Sales' 'op=add&name=Region&heading=1' 'op=add&parent=Region&name=Nordic' \
            'op=add&name=Archive&heading=1' 'op=add&name=Spare&heading=1' 'op=add&parent=Spare&name=S1' \
            'op=add&name=Outer&heading=1' 'op=add&parent=Outer&name=Inner&heading=1' 'op=add&parent=Outer/Inner&name=I1'; do
    R=$(node "$args")
    [ "$(echo "$R" | ok)" = ok ] || echo "setup failed: $args → $R" >&2
done

LIST="$WIKI_PAGES/Main/Staff.list"
cat > "$LIST" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
  {"id":"col_2","name":"Name","type":"text_single","role":"title"},
  {"id":"col_3","name":"Dept","type":"choice","taxonomy":"Department"},
  {"id":"col_4","name":"Areas","type":"choice","taxonomy":"Department","multiple":true},
  {"id":"col_5","name":"Unit","type":"choice","taxonomy":"Department/Finance"},
  {"id":"col_6","name":"Postings","type":"object","multiple":true,"fields":[
     {"id":"sf_w","name":"Where","type":"choice","taxonomy":"Department","multiple":true},
     {"id":"sf_n","name":"Note","type":"text_single"}]},
  {"id":"col_7","name":"Spare","type":"choice","taxonomy":"Spare"},
  {"id":"col_8","name":"Deep","type":"choice","taxonomy":"Outer/Inner"}],
 "items":[{"id":1,"col_1":1,"col_2":"Ann","col_3":"Budget","col_4":["Budget","Sales"],"col_5":"Payroll",
           "col_6":[{"id":1,"sf_w":["Payroll","Budget"],"sf_n":"x"}]},
          {"id":2,"col_1":2,"col_2":"Bo","col_3":"Gone"}],
 "nextItemId":3,"nextEntryId":2}
JSON
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Main" > /dev/null

save_list() { curl -s -o "$WIKI_ROOT/save.json" -w '%{http_code}' -X POST --data-binary @"$1" \
    "$WIKI_URL/api.php?action=save&space=Main&file=Staff.list"; }
variant() {   # variant <python on d> → a file holding the changed list
    python3 - "$LIST" "$1" "$WIKI_ROOT/variant.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
exec(sys.argv[2])
open(sys.argv[3], "w").write(json.dumps(d))
PY
    echo "$WIKI_ROOT/variant.json"
}
code() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print((d.get("list_invalid") or {}).get("code", "saved" if d.get("success") else d.get("message")))' "$WIKI_ROOT/save.json"; }
cell() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); it=next(i for i in d["items"] if i["id"]==int(sys.argv[2])); v=it.get(sys.argv[3]); print(json.dumps(v if len(sys.argv)<5 else v[0].get(sys.argv[4]), ensure_ascii=False))' "$LIST" "$@"; }
col() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); c=next(c for c in d["columns"] if c["id"]==sys.argv[2]); print(c.get("taxonomy") if len(sys.argv)<4 else next(f for f in c["fields"] if f["id"]==sys.argv[3]).get("taxonomy"))' "$LIST" "$@"; }

section 'Saving a value from the folder'
assert_eq 'a value anywhere in the folder is saved'          '200saved' "$(save_list "$(variant 'd["items"][0]["col_3"]="Payroll"')")$(code)"
assert_eq '  (control: it is on disk)'                       '"Payroll"' "$(cell 1 col_3)"
BEFORE=$(md5sum < "$LIST")
refused() {   # refused <label> <python> <code>
    local st; st=$(save_list "$(variant "$2")")
    assert_eq "$1" "$3" "$(code)"
    assert_eq '  and nothing was written' "$BEFORE" "$(md5sum < "$LIST")"
}
refused 'a value from another folder is refused'          'd["items"][0]["col_3"]="Nordic"'               'not_in_taxonomy'
refused 'a folder is not a value'                         'd["items"][0]["col_3"]="Finance"'              'not_in_taxonomy'
refused 'a value outside a subfolder field is refused'    'd["items"][0]["col_5"]="Sales"'                'not_in_taxonomy'
refused 'one value where one is allowed'                  'd["items"][0]["col_3"]=["Budget","Sales"]'     'tax_one_value'
refused 'a new value in a multi field is checked too'     'd["items"][0]["col_4"]=["Budget","Nordic"]'    'not_in_taxonomy'
refused "…and in a group's sub-field"                     'd["items"][0]["col_6"][0]["sf_w"]=["Nordic"]'  'not_in_taxonomy'
refused 'a list in a sub-field that takes one value is not plain' \
    'd["columns"][5]["fields"][1]["type"]="text_single"; d["items"][0]["col_6"][0]["sf_n"]=["a","b"]' 'value_not_text'
assert_eq "a value already held passes, though no longer in the folder" '200saved' \
    "$(save_list "$(variant 'd["items"][1]["col_2"]="Bo B"')")$(code)"
assert_eq '  (it is still there)'                            '"Gone"' "$(cell 2 col_3)"
assert_eq 'several values in a group sub-field are saved'    '200saved' \
    "$(save_list "$(variant 'd["items"][0]["col_6"][0]["sf_w"]=["Sales","Payroll"]')")$(code)"

section 'Reading it'
R=$(curl -s "$WIKI_URL/export.php?space=Main&path=Staff.list&format=csv")
assert_contains 'CSV joins several values'                   'Budget; Sales' "$R"
assert_contains "  and a group's summary shows them"         'Sales, Payroll' "$R"
cat > "$WIKI_PAGES/Main/T.md" <<'MD'
---
generator_source: Main:LIST_ID
generator_folder: Out
---
DEPT={{dept}} AREAS={{areas}} WHERE={{#postings}}{{where}}{{/postings}}
MD
LID=$(python3 -c 'import json,sys; ix=json.load(open(sys.argv[1])); print(next(k for k,v in ix.items() if v["path"]=="Staff.list"))' "$WIKI_PAGES/Main/index.json")
sed -i "s/LIST_ID/$LID/" "$WIKI_PAGES/Main/T.md"
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Main" > /dev/null
TID=$(python3 -c 'import json,sys; ix=json.load(open(sys.argv[1])); print(next(k for k,v in ix.items() if v["path"]=="T.md"))' "$WIKI_PAGES/Main/index.json")
curl -s -X POST -d "id=$TID" "$WIKI_URL/api.php?action=generator_render&space=Main" > /dev/null
assert_contains 'a generated page prints the values'         'DEPT=Payroll AREAS=Budget; Sales WHERE=Sales; Payroll' "$(cat "$WIKI_PAGES/Main/Out/Ann.md")"

section 'An AI user setting it'
ai() {
    (cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "wiki_ai_tools.php";
$dir = rtrim(PAGES_DIR, "/") . "/Main";
echo execute_ai_tool("wiki_update_list_item", json_decode($argv[1], true), ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true], new PageIndexer($dir), $dir);
' "$1" 2>&1)
}
assert_contains 'a term from the folder, any spelling'       'set "Dept", "Areas"' "$(ai '{"path":"Staff.list","item_id":2,"fields":{"Dept":"sales","Areas":["budget","PAYROLL"]}}')"
assert_eq '  stored as the tree spells it'                   '"Sales"' "$(cell 2 col_3)"
assert_eq '  several as a list'                              '["Budget", "Payroll"]' "$(cell 2 col_4)"
assert_contains 'a term from elsewhere is refused, with the folder named' 'takes items of the taxonomy folder "Department"' \
    "$(ai '{"path":"Staff.list","item_id":2,"fields":{"Dept":"Nordic"}}')"
assert_contains 'several where one is allowed is refused'    'takes one value' "$(ai '{"path":"Staff.list","item_id":2,"fields":{"Dept":["Sales","Budget"]}}')"

section 'The taxonomy follows into lists'
R=$(curl -s "$WIKI_URL/api.php?action=admin_taxonomy" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(json.dumps({"c": d.get("list_counts"), "r": d.get("list_refs")}, ensure_ascii=False))')
assert_contains 'the admin pane counts list values'          '"department/finance/budget": 2' "$R"
assert_contains '  and names the fields bound to a folder'   '"department": ["Main/Staff.list › Dept", "Main/Staff.list › Areas", "Main/Staff.list › Postings › Where"]' "$R"
MT=$(stat -c %Y "$LIST")
sleep 1
assert_eq 'renaming an item'                                 'ok' "$(node 'op=update&path=Department/Finance/Budget&name=Budgeting' | ok)"
assert_eq '  renames it in a field'                          '"Sales"' "$(cell 2 col_3)"
assert_eq '  in a field taking several'                      '["Budgeting", "Payroll"]' "$(cell 2 col_4)"
assert_eq "  and in a group's sub-field"                     '["Sales", "Payroll"]' "$(cell 1 col_6 sf_w)"
assert_eq '  and in item 1'                                  '["Budgeting", "Sales"]' "$(cell 1 col_4)"
assert_eq '  keeping the file time (a vocabulary change is not an edit)' "$MT" "$(stat -c %Y "$LIST")"
assert_eq 'merging an item into another'                     'ok' "$(node 'op=merge&path=Department/Finance/Payroll&target=Department/Finance/Budgeting' | ok)"
assert_eq '  takes the target name, once'                    '["Budgeting"]' "$(cell 2 col_4)"
assert_eq '  in a single field too'                          '"Budgeting"' "$(cell 1 col_5)"
assert_eq 'renaming the folder'                              'ok' "$(node 'op=update&path=Department&name=Departments' | ok)"
assert_eq '  retargets the field'                            'Departments' "$(col col_3)"
assert_eq '  and one bound to a folder inside it'            'Departments/Finance' "$(col col_5)"
assert_eq "  and a group's sub-field"                        'Departments' "$(col col_6 sf_w)"
assert_eq 'moving a subfolder'                               'ok' "$(node 'op=move&path=Departments/Finance&parent=Region' | ok)"
assert_eq '  retargets the field bound to it'                'Region/Finance' "$(col col_5)"
assert_eq '  and leaves the others'                          'Departments' "$(col col_3)"
assert_eq '  (values are names, so nothing else changed)'    '"Budgeting"' "$(cell 1 col_5)"
assert_eq 'a save after all that still passes'               '200saved' "$(save_list "$(variant 'd["items"][0]["col_2"]="Ann A"')")$(code)"

section 'Deleting what a list uses is refused'
BEFORE_TAX=$(md5sum < "$WIKI_PAGES/taxonomy.json")
assert_contains 'an item a list value names'                 'list value(s) use' "$(node 'op=delete&path=Departments/Sales' | ok)"
assert_contains 'a folder whose items lists use'             'list value(s) use "Departments"' "$(node 'op=delete&path=Departments' | ok)"
assert_contains 'a folder a list field is bound to, though no value uses it' 'Staff.list › Spare' "$(node 'op=delete&path=Spare' | ok)"
assert_contains '  or one holding such a folder'             'Staff.list › Deep' "$(node 'op=delete&path=Outer' | ok)"
assert_contains 'a bound folder turned into an item'         'must stay a folder' "$(node 'op=update&path=Spare&heading=' | ok)"
assert_contains 'a bound folder merged into an item'         'without one' "$(node 'op=merge&path=Outer/Inner&target=Region/Nordic' | ok)"
assert_contains 'a used item turned into a folder'           'list value(s) use' "$(node 'op=update&path=Departments/Sales&heading=1' | ok)"
assert_eq '  the tree is as it was'                          "$BEFORE_TAX" "$(md5sum < "$WIKI_PAGES/taxonomy.json")"
assert_eq 'control: an unused term deletes'                  'ok' "$(node 'op=delete&path=Archive' | ok)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
