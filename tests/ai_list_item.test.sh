#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# wiki_update_list_item: an AI user changing one item of a .list — the tool a generated page's
# chat is pointed at, since the page itself cannot be written. Every field type is converted
# the way the item form would store it, a comment is stamped by the server as the AI user, a
# refused change leaves the file byte-for-byte as it was, and a change marks the generators
# reading the list for re-rendering.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"

cat > "$WIKI_PAGES/Main/People.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_2":"Ann"},{"id":2,"col_2":"Bo"}],"nextItemId":3}
JSON
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
PEOPLE=$(python3 -c 'import json,sys;ix=json.load(open(sys.argv[1]));print(next(k for k,v in ix.items() if v["path"]=="People.list"))' "$WIKI_PAGES/Main/index.json")
cat > "$WIKI_PAGES/Main/Deals.list" <<JSON
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
  {"id":"col_2","name":"Deal","type":"text_single","role":"title"},
  {"id":"col_3","name":"Notes","type":"text_multi"},
  {"id":"col_4","name":"Price","type":"number","decimals":1},
  {"id":"col_5","name":"Won","type":"boolean"},
  {"id":"col_6","name":"Stage","type":"choice","options":["Lead","Offer","Closed"]},
  {"id":"col_7","name":"Due","type":"date"},
  {"id":"col_8","name":"Owner","type":"lookup","lookup":{"list":$PEOPLE,"multiple":false}},
  {"id":"col_9","name":"Owner: Name","type":"lookup_field","source":"col_8","field":"col_2"},
  {"id":"col_10","name":"Address","type":"object","fields":[{"id":"sf_a","name":"City","type":"text_single"}]}],
 "items":[{"id":1,"col_1":1,"col_2":"Big","col_6":"Lead","col_8":[1]},{"id":2,"col_1":2,"col_2":"Small"}],
 "nextItemId":3}
JSON
cat > "$WIKI_PAGES/Main/Quiet.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],"items":[{"id":1,"col_2":"Q"}],"nextItemId":2,"comments":false}
JSON
fixture_page 'Main/Plain.md' '# Plain'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
DEALS=$(python3 -c 'import json,sys;ix=json.load(open(sys.argv[1]));print(next(k for k,v in ix.items() if v["path"]=="Deals.list"))' "$WIKI_PAGES/Main/index.json")
# A generator reading Deals, so a change can be seen to mark it for re-rendering.
printf -- '---\ngenerator_source: Main:%s\ngenerator_folder: Out\n---\n# {{deal}} {{price}}\n' "$DEALS" > "$WIKI_PAGES/Main/Tpl.md"
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
TPL=$(python3 -c 'import json,sys;ix=json.load(open(sys.argv[1]));print(next(k for k,v in ix.items() if v["path"]=="Tpl.md"))' "$WIKI_PAGES/Main/index.json")
post_as "$JAR" "api.php?action=generator_render&space=Main" "id=$TPL" > /dev/null

# Run one tool call as an AI user; $1 = JSON input, $2 = role (default editor).
tool() {
    (cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ai = ["uid" => -9, "name" => "Bot", "role" => $argv[2], "is_ai" => true];
echo execute_ai_tool("wiki_update_list_item", json_decode($argv[1], true), $ai, new PageIndexer($space_dir), $space_dir);
' "$1" "${2:-editor}" 2>&1)
}
item() { python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
it = next(i for i in d["items"] if i["id"] == int(sys.argv[2]))
print(json.dumps(it.get(sys.argv[3]) if len(sys.argv) > 3 else it, ensure_ascii=False))' "$WIKI_PAGES/Main/$1" "$2" ${3:+"$3"}; }
LIST="$WIKI_PAGES/Main/Deals.list"

gen_state() { python3 -c 'import json,sys; g=json.load(open(sys.argv[1])).get("generators", {}); print(json.dumps(g.get("Main:"+sys.argv[2], {}), indent=1))' "$WIKI_SYS/generators.json" "$TPL"; }

section 'Setting fields'
assert_contains 'control: the generator starts rendered, not dirty' '"dirty": false' "$(gen_state)"
R=$(tool '{"path":"Deals.list","item_id":1,"fields":{"Notes":"Line one\nline two","price":"1 250,55","Won":"yes","stage":"offer","Due":"2026-12-01","Owner":2}}')
assert_contains 'the change is reported'                 'Item 1 of Deals.list updated: set "Notes", "Price", "Won", "Stage", "Due", "Owner"' "$R"
assert_eq 'multi-line text kept as written'              '"Line one\nline two"' "$(item Deals.list 1 col_3)"
assert_eq 'a number from text, to its decimals'          '1250.6'           "$(item Deals.list 1 col_4)"
assert_eq 'Yes/No from "yes"'                            'true'             "$(item Deals.list 1 col_5)"
assert_eq 'a choice matched to its option'               '"Offer"'          "$(item Deals.list 1 col_6)"
assert_eq 'a date'                                       '"2026-12-01"'     "$(item Deals.list 1 col_7)"
assert_eq 'a lookup as the ids the form stores'          '[2]'              "$(item Deals.list 1 col_8)"
assert_contains 'the generator reading the list is marked to re-render' '"dirty": true' "$(gen_state)"
R=$(tool '{"path":"Deals.list","item_id":1,"fields":{"Stage":"Offer"}}')
assert_contains 'the same value again changes nothing'   'Nothing to change' "$R"
R=$(tool '{"path":"Deals.list","item_id":1,"fields":{"Notes":null,"Due":""}}')
assert_contains 'null and "" clear a field'              'set "Notes", "Due"' "$R"
assert_eq '  gone from the item'                         'null' "$(item Deals.list 1 col_3)"

section 'Refused, and nothing written'
BEFORE=$(md5sum < "$LIST")
refused() {
    R=$(tool "$2" "${4:-editor}")
    assert_contains "$1" "$3" "$R"
    assert_eq "  the list is as it was" "$BEFORE" "$(md5sum < "$LIST")"
}
refused 'a choice that is not an option'       '{"path":"Deals.list","item_id":1,"fields":{"Stage":"Won"}}'      'is one of: "Lead", "Offer", "Closed"'
refused 'a field the list does not have'       '{"path":"Deals.list","item_id":1,"fields":{"Colour":"red"}}'     'Its fields are: "ItemId", "Deal"'
refused 'one bad field stops the whole change' '{"path":"Deals.list","item_id":1,"fields":{"Notes":"ok","Price":"lots"}}' '"Price" takes a number'
refused 'a date in another form'               '{"path":"Deals.list","item_id":1,"fields":{"Due":"1/12/2026"}}'  'YYYY-MM-DD'
refused 'a lookup id its list does not have'   '{"path":"Deals.list","item_id":1,"fields":{"Owner":9}}'          'has no item 9'
refused 'two items in a single lookup'         '{"path":"Deals.list","item_id":1,"fields":{"Owner":[1,2]}}'      'takes one item'
refused 'ItemId'                               '{"path":"Deals.list","item_id":1,"fields":{"ItemId":5}}'         'is numbered by the list'
refused 'an "also show" field'                 '{"path":"Deals.list","item_id":1,"fields":{"Owner: Name":"X"}}'  'change that lookup'
refused 'a group of fields'                    '{"path":"Deals.list","item_id":1,"fields":{"Address":{"sf_a":"Oslo"}}}' 'only the item form'
refused 'an empty Title'                       '{"path":"Deals.list","item_id":1,"fields":{"Deal":""}}'          "the list's Title"
refused 'an item that is not there'            '{"path":"Deals.list","item_id":42,"fields":{"Notes":"x"}}'       'no item with id 42'
refused 'nothing asked'                        '{"path":"Deals.list","item_id":1}'                               'give fields to change, a comment, or both'
refused 'a reader AI user'                     '{"path":"Deals.list","item_id":1,"fields":{"Notes":"x"}}'        'read-only (reader) role' reader
R=$(tool '{"path":"Plain.md","item_id":1,"fields":{"Notes":"x"}}')
assert_contains 'a page that is not a list'                'must name a .list' "$R"
assert_eq '  untouched'                                    '# Plain' "$(cat "$WIKI_PAGES/Main/Plain.md")"

section 'Comments'
R=$(tool '{"path":"Deals.list","item_id":2,"comment":"Called them, they want a quote."}')
assert_contains 'a comment is added'                       'Item 2 of Deals.list updated: added a comment' "$R"
C=$(item Deals.list 2 _comments)
assert_contains '  stamped by the server as the AI user'   '"by": -9, "name": "Bot"' "$C"
assert_contains '  with its text'                          'Called them, they want a quote.' "$C"
assert_contains '  an id and a time'                       '"id": 1' "$C"
assert_contains '  and a time'                             '"at": "20' "$C"
R=$(tool '{"path":"Quiet.list","item_id":1,"comment":"hello"}')
assert_contains 'a list with comments off takes none'      'comments turned off' "$R"
assert_not_contains '  none written'                       'hello' "$(cat "$WIKI_PAGES/Main/Quiet.list")"

section 'A read-only Space'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=1' > /dev/null
BEFORE=$(md5sum < "$LIST")
refused 'a frozen Space is refused'                        '{"path":"Deals.list","item_id":1,"fields":{"Notes":"x"}}' 'is read-only'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=0' > /dev/null
R=$(tool '{"path":"Deals.list","item_id":1,"fields":{"Notes":"thawed"}}')
assert_contains 'control: thawed, the same change goes through' 'updated: set "Notes"' "$R"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
