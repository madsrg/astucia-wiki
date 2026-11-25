#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# wiki_create_list: an AI user creating a new, empty .list with typed fields. The list must
# come out as the person's New → List + List properties would write it (so it opens and
# saves in the editor), an existing path is never replaced — not even by two creates racing
# for it — a bad field definition creates nothing, a lookup only reaches a list in the same
# Space, and the create is indexed, audited and available over MCP.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_space Other
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":-7,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_mkbot","ai_config":{}},
  {"uid":-8,"name":"Peeker","role":"reader","is_ai":true,"service_token":"wk_ai_mkpeek","ai_config":{}}]}'
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"

cat > "$WIKI_PAGES/Main/People.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_1":1,"col_2":"Ann"},{"id":2,"col_1":2,"col_2":"Bo"}],"nextItemId":3}
JSON
cat > "$WIKI_PAGES/Other/Elsewhere.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],"items":[],"nextItemId":1}
JSON
fixture_page 'Main/Existing.md' '# Not a list'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Other' > /dev/null
pid_of() { python3 -c 'import json,sys;ix=json.load(open(sys.argv[1]));print(next(k for k,v in ix.items() if v["path"]==sys.argv[2]))' "$WIKI_PAGES/$1/index.json" "$2"; }
PEOPLE=$(pid_of Main People.list)
# A taxonomy with a folder a choice can take its values from.
cat > "$WIKI_PAGES/taxonomy.json" <<'JSON'
{"mode":"free","nodes":[{"name":"Severity","heading":true,"children":[{"name":"Minor"},{"name":"Major"}]}]}
JSON

# Run one tool call as an AI user; $1 = tool, $2 = JSON input, $3 = role (default editor).
tool() {
    (cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ai = ["uid" => -7, "name" => "Bot", "role" => $argv[3], "is_ai" => true];
echo execute_ai_tool($argv[1], json_decode($argv[2], true), $ai, new PageIndexer($space_dir), $space_dir);
' "$1" "$2" "${3:-editor}" 2>&1)
}
col() { python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
c = next((c for c in d["columns"] if c["name"] == sys.argv[2]), None)
print(json.dumps(c.get(sys.argv[3]) if c and len(sys.argv) > 3 else c, ensure_ascii=False, sort_keys=True))' "$WIKI_PAGES/Main/$1" "$2" ${3:+"$3"}; }

section 'Creating a list'
R=$(tool wiki_create_list "{\"path\":\"Registers/Risks.list\",\"title\":\"Risk\",\"fields\":[
  {\"name\":\"Risk\",\"type\":\"text\"},
  {\"name\":\"Details\",\"type\":\"multiline\",\"description\":\"What could happen\"},
  {\"name\":\"Impact\",\"type\":\"choice\",\"options\":[\"Low\",\"Medium\",\"High\",\"low\",\" \"]},
  {\"name\":\"Severity\",\"type\":\"choice\",\"taxonomy\":\"Severity\",\"multiple\":true},
  {\"name\":\"Cost\",\"type\":\"number\",\"decimals\":2,\"unit\":\"kr\"},
  {\"name\":\"Mitigated\",\"type\":\"yesno\"},
  {\"name\":\"Due\",\"type\":\"date\"},
  {\"name\":\"Owner\",\"type\":\"lookup\",\"list\":\"People.list\"},
  {\"name\":\"Reviewers\",\"type\":\"lookup\",\"list\":\"$PEOPLE\",\"multiple\":true}]}")
assert_contains 'the create is reported'            'List created: Registers/Risks.list (page id' "$R"
assert_contains '  naming its Title'                 '"Risk" (Title)' "$R"
L=Registers/Risks.list
assert_eq 'ItemId first, numbered by the list'       '{"id": "col_1", "name": "ItemId", "showInListView": true, "type": "autoincrement"}' "$(col $L ItemId)"
assert_eq 'the Title has its role'                   '"title"'          "$(col $L Risk role)"
assert_eq 'multiline text'                           '"text_multi"'     "$(col $L Details type)"
assert_eq '  with its description'                   '"What could happen"' "$(col $L Details description)"
assert_eq 'choice options, blanks and repeats dropped' '["Low", "Medium", "High"]' "$(col $L Impact options)"
assert_eq 'a choice bound to a taxonomy folder'      '"Severity"'       "$(col $L Severity taxonomy)"
assert_eq '  allowing several'                       'true'             "$(col $L Severity multiple)"
assert_eq 'a number with decimals and unit'          '2 "kr"' "$(col $L Cost decimals) $(col $L Cost unit)"
assert_eq 'Yes/No'                                   '"boolean"'        "$(col $L Mitigated type)"
assert_eq 'a lookup by path, showing its Title'      "{\"field\": \"col_2\", \"list\": $PEOPLE, \"multiple\": false}" "$(col $L Owner lookup)"
assert_eq 'a lookup by page id, several'             "{\"field\": \"col_2\", \"list\": $PEOPLE, \"multiple\": true}"  "$(col $L Reviewers lookup)"
assert_eq 'empty, counter at 1, not committed'       '[] 1 false' "$(python3 -c 'import json,sys;d=json.load(open(sys.argv[1]));print(json.dumps(d["items"]),d["nextItemId"],json.dumps(d["git_commit"]))' "$WIKI_PAGES/Main/$L")"
assert_contains 'in the page index'                  '"Registers\/Risks.list"' "$(cat "$WIKI_PAGES/Main/index.json")"
r=$(get_as "$JAR" "api.php?action=get&space=Main&file=Registers/Risks.list")
assert_contains 'opens through the API like any list' '\"Impact\"' "$r"

R=$(tool wiki_add_list_item '{"path":"Registers/Risks.list","fields":{"Risk":"Flood","Impact":"High","Severity":["major"],"Cost":"1234,5","Owner":1}}')
assert_contains 'and items can be added to it'      'Item 1 added' "$R"

R=$(tool wiki_create_list '{"path":"Simple.list","fields":[{"name":"Name","type":"text"},{"name":"Note","type":"text"}]}')
assert_contains 'without "title", the first text field is the Title' '"Name" (Title), "Note"' "$R"

section 'Never replaces anything'
BEFORE=$(md5sum < "$WIKI_PAGES/Main/People.list")
R=$(tool wiki_create_list '{"path":"People.list","fields":[{"name":"X","type":"text"}]}')
assert_contains 'an existing list is refused'        'already exists' "$R"
assert_eq '  and is as it was'                       "$BEFORE" "$(md5sum < "$WIKI_PAGES/Main/People.list")"
# Eight creates racing for one path: exactly one wins, the rest are told it exists.
pids=""
for i in 1 2 3 4 5 6 7 8; do
    tool wiki_create_list "{\"path\":\"Race.list\",\"fields\":[{\"name\":\"Racer $i\",\"type\":\"text\"}]}" > "$WIKI_ROOT/race$i.out" &
    pids="$pids $!"
done
for p in $pids; do wait "$p"; done
assert_eq 'eight racing creates: one wins'           '1' "$(cat "$WIKI_ROOT"/race*.out | grep -o 'List created' | wc -l)"
assert_eq '  seven are refused'                      '7' "$(cat "$WIKI_ROOT"/race*.out | grep -o 'already exists' | wc -l)"
winner=$(grep -l 'List created' "$WIKI_ROOT"/race*.out | sed 's/.*race\([0-9]\).out/\1/')
assert_eq '  the file is the winner'"'"'s'           "\"Racer $winner\"" "$(col Race.list "Racer $winner" name)"

section 'Refused, and nothing created'
nothing() {  # nothing <label> <path> <json> <expected>
    R=$(tool wiki_create_list "$3" "${5:-editor}")
    assert_contains "$1" "$4" "$R"
    assert_eq "  no file" 'no' "$([ -e "$WIKI_PAGES/Main/$2" ] && echo yes || echo no)"
}
nothing 'not a .list path'           'Bad.md'   '{"path":"Bad.md","fields":[{"name":"A","type":"text"}]}'                       'must end in .list'
nothing 'no fields'                  'N.list'   '{"path":"N.list","fields":[]}'                                                 'non-empty array'
nothing 'an unknown type'            'N.list'   '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"B","type":"group"}]}' 'the types are "text", "multiline"'
nothing 'two fields with one name'   'N.list'   '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"a","type":"date"}]}' 'two fields are named "a"'
nothing 'a field called ItemId'      'N.list'   '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"ItemId","type":"number"}]}' 'added by the list itself'
nothing 'a choice without options'   'N.list'   '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"C","type":"choice"}]}' 'give its "options"'
nothing 'a taxonomy folder that is not there' 'N.list' '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"C","type":"choice","taxonomy":"Nope"}]}' 'is not a folder of the taxonomy'
nothing 'no text field for a Title'  'N.list'   '{"path":"N.list","fields":[{"name":"D","type":"date"}]}'                       'needs a Title'
nothing 'a Title that is not text'   'N.list'   '{"path":"N.list","title":"D","fields":[{"name":"A","type":"text"},{"name":"D","type":"date"}]}' 'must be a "text" field'
nothing 'a Title that is not a field' 'N.list'  '{"path":"N.list","title":"Z","fields":[{"name":"A","type":"text"}]}'           'no field "Z" to be the Title'
nothing 'a lookup to a page that is not a list' 'N.list' '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"L","type":"lookup","list":"Existing.md"}]}' 'not a list in this space'
nothing 'a lookup into another Space' 'N.list'  '{"path":"N.list","fields":[{"name":"A","type":"text"},{"name":"L","type":"lookup","list":"../Other/Elsewhere.list"}]}' 'not a list in this space'
nothing 'a reader'                   'N.list'   '{"path":"N.list","fields":[{"name":"A","type":"text"}]}'                       'read-only (reader) role' reader
nothing 'the memory folder'          'memory/M.list' '{"path":"memory/M.list","fields":[{"name":"A","type":"text"}]}'           'memory folder'

section 'A read-only Space'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=1' > /dev/null
nothing 'a frozen Space is refused'  'Frozen.list' '{"path":"Frozen.list","fields":[{"name":"A","type":"text"}]}'               'is read-only'
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=0' > /dev/null
R=$(tool wiki_create_list '{"path":"Frozen.list","fields":[{"name":"A","type":"text"}]}')
assert_contains 'control: thawed, the same create goes through' 'List created' "$R"

section 'Audit log'
audit=$(cat "$WIKI_ROOT"/logs/audit/*.log 2>/dev/null)
line=$(grep '"tool":"wiki_create_list"' <<<"$audit" | grep 'Registers/Risks.list' | head -1)
assert_contains 'the create is recorded'             'Registers/Risks.list' "$line"
assert_contains '  as a create'                      '"action":"create"' "$line"

section 'Through the MCP server'
mcp() { curl -s -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d "$2" "$WIKI_URL/mcp.php?space=Main"; }
r=$(mcp wk_ai_mkbot '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
assert_contains 'listed for MCP clients'             '"wiki_create_list"' "$r"
r=$(mcp wk_ai_mkbot '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wiki_create_list","arguments":{"path":"Via MCP.list","fields":[{"name":"Name","type":"text"}]}}}')
assert_contains 'an editor token creates'            'List created: Via MCP.list' "$r"
r=$(mcp wk_ai_mkpeek '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wiki_create_list","arguments":{"path":"Peek.list","fields":[{"name":"Name","type":"text"}]}}}')
assert_contains 'a reader token is refused'          'read-only (reader) role' "$r"
assert_eq '  and nothing is created'                 'no' "$([ -e "$WIKI_PAGES/Main/Peek.list" ] && echo yes || echo no)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
