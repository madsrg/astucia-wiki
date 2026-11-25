#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# wiki_add_list_item (US-0002): an AI user adding an item to an existing .list. The
# acceptance criteria, one section each: the add returns the new id; only the Title is
# required; computed columns (ItemId, "also show") are refused; a bad value names its field
# and writes nothing; a reader is refused; two adds at once never share an id or lose an
# item; the add is in the audit log; generated pages are re-rendered; and the same tool
# works through the MCP server.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":-7,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_addbot","ai_config":{}},
  {"uid":-8,"name":"Peeker","role":"reader","is_ai":true,"service_token":"wk_ai_addpeek","ai_config":{}}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"

cat > "$WIKI_PAGES/Main/People.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_2":"Ann"},{"id":2,"col_2":"Bo"}],"nextItemId":3}
JSON
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
pid_of() { python3 -c 'import json,sys;ix=json.load(open(sys.argv[1]));print(next(k for k,v in ix.items() if v["path"]==sys.argv[2]))' "$WIKI_PAGES/Main/index.json" "$1"; }
PEOPLE=$(pid_of People.list)
cat > "$WIKI_PAGES/Main/Risks.list" <<JSON
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
  {"id":"col_2","name":"Risk","type":"text_single","role":"title"},
  {"id":"col_3","name":"Impact","type":"choice","options":["Low","Medium","High"]},
  {"id":"col_4","name":"Score","type":"number"},
  {"id":"col_5","name":"Owner","type":"lookup","lookup":{"list":$PEOPLE,"multiple":false}},
  {"id":"col_6","name":"Owner: Name","type":"lookup_field","source":"col_5","field":"col_2"},
  {"id":"col_7","name":"Due","type":"date"}],
 "items":[{"id":1,"col_1":1,"col_2":"Server room floods","col_3":"High"}],
 "nextItemId":2}
JSON
# A counter that is behind the ids in the file, as a list edited by hand can be.
cat > "$WIKI_PAGES/Main/Behind.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_2":"a"},{"id":7,"col_2":"b"}],"nextItemId":3}
JSON
cat > "$WIKI_PAGES/Main/Quiet.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],"items":[],"nextItemId":1,"comments":false}
JSON
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
RISKS=$(pid_of Risks.list)
# A generator reading Risks, so an add can be seen to mark it for re-rendering.
printf -- '---\ngenerator_source: Main:%s\ngenerator_folder: Out\n---\n# {{risk}}\n' "$RISKS" > "$WIKI_PAGES/Main/Tpl.md"
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
TPL=$(pid_of Tpl.md)
post_as "$JAR" "api.php?action=generator_render&space=Main" "id=$TPL" > /dev/null

# Run one tool call as an AI user; $1 = JSON input, $2 = role (default editor).
tool() {
    (cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ai = ["uid" => -7, "name" => "Bot", "role" => $argv[2], "is_ai" => true];
echo execute_ai_tool("wiki_add_list_item", json_decode($argv[1], true), $ai, new PageIndexer($space_dir), $space_dir);
' "$1" "${2:-editor}" 2>&1)
}
item() { python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
it = next((i for i in d["items"] if i["id"] == int(sys.argv[2])), None)
print(json.dumps(it.get(sys.argv[3]) if it is not None and len(sys.argv) > 3 else it, ensure_ascii=False))' "$WIKI_PAGES/Main/$1" "$2" ${3:+"$3"}; }
count() { python3 -c 'import json,sys; print(len(json.load(open(sys.argv[1]))["items"]))' "$WIKI_PAGES/Main/$1"; }
LIST="$WIKI_PAGES/Main/Risks.list"
gen_state() { python3 -c 'import json,sys; g=json.load(open(sys.argv[1])).get("generators", {}); print(json.dumps(g.get("Main:"+sys.argv[2], {}), indent=1))' "$WIKI_SYS/generators.json" "$TPL"; }

section 'Adding an item'
assert_contains 'control: the generator starts rendered, not dirty' '"dirty": false' "$(gen_state)"
R=$(tool '{"path":"Risks.list","fields":{"Risk":"Supplier goes bust","impact":"medium","Score":"12","Owner":2,"Due":"2026-12-31"}}')
assert_contains 'the new id is returned'                 'Item 2 added to Risks.list. Its id is 2.' "$R"
assert_eq 'the title'                                    '"Supplier goes bust"' "$(item Risks.list 2 col_2)"
assert_eq 'a choice matched to its option'               '"Medium"'         "$(item Risks.list 2 col_3)"
assert_eq 'a number from text'                           '12'               "$(item Risks.list 2 col_4)"
assert_eq 'a lookup as the ids the form stores'          '[2]'              "$(item Risks.list 2 col_5)"
assert_eq 'ItemId numbered like the item form does'      '2'                "$(item Risks.list 2 col_1)"
assert_eq 'the counter moves on'                         '3' "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["nextItemId"])' "$LIST")"
assert_contains 'the generator reading the list is marked to re-render' '"dirty": true' "$(gen_state)"

R=$(tool '{"path":"Risks.list","fields":{"Risk":"Only a title"}}')
assert_contains 'only the Title is required'             'Item 3 added' "$R"
assert_eq '  the rest stays empty'                       '{"id": 3, "col_1": 3, "col_2": "Only a title"}' "$(item Risks.list 3)"

R=$(tool '{"path":"Risks.list","fields":{"Risk":"With a note"},"comment":"Raised in the Q4 review."}')
assert_contains 'an optional first comment'              'Item 4 added' "$R"
c=$(item Risks.list 4 _comments)
assert_contains '  stamped by the server as the AI user' '"name": "Bot"' "$c"
assert_contains '  with its text'                        'Raised in the Q4 review.' "$c"

R=$(tool '{"path":"Behind.list","fields":{"Name":"c"}}')
assert_contains 'a counter behind the file: past the highest id' 'Item 8 added' "$R"

section 'Refused, and nothing written'
BEFORE=$(md5sum < "$LIST")
refused() {
    R=$(tool "$2" "${4:-editor}")
    assert_contains "$1" "$3" "$R"
    assert_eq "  the list is as it was" "$BEFORE" "$(md5sum < "$LIST")"
}
refused 'no Title'                             '{"path":"Risks.list","fields":{"Impact":"Low"}}'             "\"Risk\" is the list's Title"
refused 'an empty Title'                       '{"path":"Risks.list","fields":{"Risk":"  "}}'                "\"Risk\" is the list's Title"
refused 'a choice that is not an option'       '{"path":"Risks.list","fields":{"Risk":"x","Impact":"Huge"}}'  '"Impact" is one of: "Low", "Medium", "High"'
refused 'a value of the wrong type names the field' '{"path":"Risks.list","fields":{"Risk":"x","Score":"lots"}}' '"Score" takes a number'
refused 'a field the list does not have'       '{"path":"Risks.list","fields":{"Risk":"x","Colour":"red"}}'   'Its fields are: "ItemId", "Risk"'
refused 'ItemId'                               '{"path":"Risks.list","fields":{"Risk":"x","ItemId":99}}'     'is numbered by the list'
refused 'an "also show" field'                 '{"path":"Risks.list","fields":{"Risk":"x","Owner: Name":"Q"}}' 'change that lookup'
refused 'a lookup id its list does not have'   '{"path":"Risks.list","fields":{"Risk":"x","Owner":9}}'        'has no item 9'
refused 'no fields at all'                     '{"path":"Risks.list"}'                                        'including the Title field'
refused 'a reader'                             '{"path":"Risks.list","fields":{"Risk":"x"}}'                  'read-only (reader) role' reader
R=$(tool '{"path":"New.list","fields":{"Name":"x"}}')
assert_contains 'a list that does not exist is not created' 'it does not create one' "$R"
assert_eq '  and no file appears'               'no' "$([ -e "$WIKI_PAGES/Main/New.list" ] && echo yes || echo no)"
R=$(tool '{"path":"Quiet.list","fields":{"Name":"x"},"comment":"hi"}')
assert_contains 'a comment on a list without comments' 'comments turned off' "$R"
assert_eq '  nothing added'                    '0' "$(count Quiet.list)"

section 'A read-only Space'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=1' > /dev/null
refused 'a frozen Space is refused'            '{"path":"Risks.list","fields":{"Risk":"x"}}'                  'is read-only'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=0' > /dev/null

section 'Two adds at once'
# Eight AI jobs adding to the same list at the same moment: every item arrives, each with
# its own id. Without the lock around read-allocate-write, two of them read the same list,
# allocate the same id, and the second write drops the first one's item.
N_BEFORE=$(count Risks.list)
pids=""
for i in 1 2 3 4 5 6 7 8; do
    tool "{\"path\":\"Risks.list\",\"fields\":{\"Risk\":\"parallel $i\"}}" > "$WIKI_ROOT/par$i.out" &
    pids="$pids $!"
done
for p in $pids; do wait "$p"; done
assert_eq 'all eight items are there'          "$((N_BEFORE + 8))" "$(count Risks.list)"
assert_eq 'no id is used twice' 'unique' "$(python3 -c '
import json,sys; ids=[i["id"] for i in json.load(open(sys.argv[1]))["items"]]
print("unique" if len(ids)==len(set(ids)) else "dup %s" % ids)' "$LIST")"
assert_eq 'each reported id is the one it got' 'ok' "$(python3 -c '
import json,re,sys
d={i["id"]:i["col_2"] for i in json.load(open(sys.argv[1]))["items"]}
bad=[]
for n in range(1,9):
    m=re.search(r"Its id is (\d+)", open(sys.argv[2]+"/par%d.out"%n).read())
    if not m or d.get(int(m.group(1)))!="parallel %d"%n: bad.append(n)
print("ok" if not bad else "bad %s"%bad)' "$LIST" "$WIKI_ROOT")"

section 'Audit log'
audit=$(cat "$WIKI_ROOT"/logs/audit/*.log 2>/dev/null)
assert_contains 'the add is recorded'          '"tool":"wiki_add_list_item"' "$audit"
assert_contains '  with the list it changed'   'Risks.list' "$audit"

section 'Through the MCP server'
mcp() { curl -s -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d "$2" "$WIKI_URL/mcp.php?space=Main"; }
r=$(mcp wk_ai_addbot '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
assert_contains 'listed for MCP clients'       '"wiki_add_list_item"' "$r"
r=$(mcp wk_ai_addbot '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wiki_add_list_item","arguments":{"path":"People.list","fields":{"Name":"Cy"}}}}')
assert_contains 'an editor token adds'         'Item 3 added to People.list' "$r"
assert_eq '  and it is there'                  '"Cy"' "$(item People.list 3 col_2)"
r=$(mcp wk_ai_addpeek '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wiki_add_list_item","arguments":{"path":"People.list","fields":{"Name":"Di"}}}}')
assert_contains 'a reader token is refused'    'read-only (reader) role' "$r"
assert_eq '  and nothing is added'             '3' "$(count People.list)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
