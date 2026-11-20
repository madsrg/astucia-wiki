#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Number, Yes/No and Comments fields in lists, on the server (lists.php, the `save` action,
# page_generators.php):
#
#  - a Number holds a number and a Yes/No a boolean; anything else is refused;
#  - a Comment is stamped by the server — id, author, time — never by the writer, so nobody
#    can post one in somebody else's name, reword one, or remove one that is not theirs
#    (an administrator may remove any);
#  - CSV and XML read them as text, and a page generator's template gets them as what they
#    are: {{price}} with its unit, {{#paid}} as a section, {{#notes}} as a list — plus
#    {{_edit}}, the button that opens the row's item.
#
# Every refusal is paired with a positive control, and every refusal has its own target, so a
# write that wrongly succeeds cannot change what a later assertion reads.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space CRM; fixture_space Pages
fixture_users '{"users":[
 {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
 {"uid":2,"name":"Eve","role":"editor","auth":"otp","email":"eve@example.com"},
 {"uid":3,"name":"Carl","role":"editor","auth":"otp","email":"carl@example.com"}]}'
ADMIN=$WIKI_ROOT/admin.jar; EVE=$WIKI_ROOT/eve.jar; CARL=$WIKI_ROOT/carl.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EVE"   "uid=2&name=Eve&role=editor&auth=otp"
fixture_login "$CARL"  "uid=3&name=Carl&role=editor&auth=otp"

COLS='[{"id":"col_1","name":"ItemId","type":"autoincrement"},
  {"id":"col_2","name":"Name","type":"text_single","role":"title"},
  {"id":"col_3","name":"Price","type":"number","decimals":2,"unit":"kr"},
  {"id":"col_4","name":"Paid","type":"boolean"},
  {"id":"col_5","name":"Notes","type":"comments"}]'
cat > "$WIKI_PAGES/CRM/Orders.list" <<JSON
{"columns":$COLS,
 "items":[{"id":1,"col_1":1,"col_2":"Ann","col_3":1250.5,"col_4":true},
          {"id":2,"col_1":2,"col_2":"Bo","col_3":null,"col_4":false}],
 "nextItemId":3}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null

LIST="$WIKI_PAGES/CRM/Orders.list"
# save_as <jar> <python expression over `d`, the list on disk, returning the new list>
save_as() {
    local body
    body=$(python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
exec("out = " + sys.argv[2])
print(json.dumps(out))' "$LIST" "$2")
    curl -s -b "$1" -c "$1" --max-time 15 -X POST -H 'Content-Type: application/json' --data-binary "$body" \
        "$WIKI_URL/api.php?action=save&space=CRM&file=Orders.list"
}
gj() { python3 -c 'import json,sys
d = json.load(sys.stdin)
for k in sys.argv[1].split("."):
    d = d.get(k) if isinstance(d, dict) else (d[int(k)] if isinstance(d, list) and len(d) > int(k) else None)
print("" if d is None else (json.dumps(d) if isinstance(d, (dict, list, bool)) else d))' "$1"; }
disk() { python3 -c 'import json,sys
d = json.load(open(sys.argv[1]))
exec("print(json.dumps(" + sys.argv[2] + "))")' "$LIST" "$1"; }
# set item field: a python expression building the new list from d
put() { echo "dict(d, items=[dict(i, **{'$2': $3}) if i['id'] == $1 else i for i in d['items']])"; }

section 'Number and Yes/No values'
assert_eq 'a number is saved (positive control)'          'true'   "$(save_as "$EVE" "$(put 1 col_3 99.5)" | gj success)"
assert_eq '  and stored as a number'                       '99.5'   "$(disk "d['items'][0]['col_3']")"
R=$(save_as "$EVE" "$(put 2 col_3 '"12"')")
assert_eq 'a number given as text is refused'              'value_not_number' "$(echo "$R" | gj list_invalid.code)"
assert_eq '  naming the field'                             'Price'  "$(echo "$R" | gj list_invalid.field)"
assert_eq '  and nothing is written'                       'null'   "$(disk "d['items'][1]['col_3']")"
assert_eq 'Yes/No as true is saved'                        'true'   "$(save_as "$EVE" "$(put 2 col_4 True)" | gj success)"
assert_eq 'Yes/No as "yes" is refused'                     'value_not_boolean' "$(save_as "$EVE" "$(put 2 col_4 '"yes"')" | gj list_invalid.code)"
assert_eq '  and the value is still true'                  'true'   "$(disk "d['items'][1]['col_4']")"
save_as "$EVE" "$(put 2 col_4 False)" > /dev/null

section 'A comment is stamped by the server'
R=$(save_as "$EVE" "$(put 1 col_5 '[{"text": "  First call done  "}]')")
assert_eq 'a new comment is saved as text alone'           'true'   "$(echo "$R" | gj success)"
assert_eq '  given an id'                                  '1'      "$(disk "d['items'][0]['col_5'][0]['id']")"
assert_eq '  the author'                                   '[2, "Eve"]' "$(disk "[d['items'][0]['col_5'][0]['by'], d['items'][0]['col_5'][0]['name']]")"
assert_contains '  and the time, in UTC'                   'Z"'     "$(disk "d['items'][0]['col_5'][0]['at']")"
assert_eq '  its text trimmed'                             '"First call done"' "$(disk "d['items'][0]['col_5'][0]['text']")"
assert_eq '  the counter moved past it'                    '2'      "$(disk "d['nextEntryId']")"
assert_eq '  and the response carries the list as written' 'Eve'    "$(echo "$R" | gj list.items.0.col_5.0.name)"

R=$(save_as "$CARL" "$(put 1 col_5 "d['items'][0]['col_5'] + [{'text': 'Carl here'}]")")
assert_eq "another person's comment is added after it"     '[1, 2]' "$(disk "[c['id'] for c in d['items'][0]['col_5']]")"
assert_eq '  in their own name'                            '"Carl"' "$(disk "d['items'][0]['col_5'][1]['name']")"

section 'What nobody may do to a comment'
R=$(save_as "$EVE" "$(put 2 col_5 '[{"id": 50, "by": 1, "name": "Admin", "at": "2020-01-01T00:00:00Z", "text": "forged"}]')")
assert_eq 'a comment posted with a stamp of its own is refused' 'comment_changed' "$(echo "$R" | gj list_invalid.code)"
assert_eq '  and item 2 has no comments'                   'null'   "$(disk "d['items'][1].get('col_5')")"
R=$(save_as "$EVE" "$(put 2 col_5 '[{"text": "x", "name": "Admin"}]')")
assert_eq 'a new comment naming its own author is refused' 'comment_bad' "$(echo "$R" | gj list_invalid.code)"
R=$(save_as "$EVE" "$(put 1 col_5 "[dict(d['items'][0]['col_5'][0], text='Reworded')] + d['items'][0]['col_5'][1:]")")
assert_eq 'rewording a comment is refused'                 'comment_changed' "$(echo "$R" | gj list_invalid.code)"
R=$(save_as "$EVE" "$(put 1 col_5 "[dict(d['items'][0]['col_5'][0], name='Somebody')] + d['items'][0]['col_5'][1:]")")
assert_eq 're-attributing one is refused'                  'comment_changed' "$(echo "$R" | gj list_invalid.code)"
R=$(save_as "$EVE" "$(put 1 col_5 "[d['items'][0]['col_5'][0], dict(d['items'][0]['col_5'][1], at='2001-01-01T00:00:00Z')]")")
assert_eq 're-dating one is refused'                       'comment_changed' "$(echo "$R" | gj list_invalid.code)"
R=$(save_as "$EVE" "$(put 2 col_5 "[d['items'][0]['col_5'][0]]")")
assert_eq "copying a comment to another item is refused"   'comment_changed' "$(echo "$R" | gj list_invalid.code)"
R=$(save_as "$EVE" "$(put 1 col_5 '[{"text": "   "}]')")
assert_eq 'an empty new comment is refused'                'comment_empty' "$(echo "$R" | gj list_invalid.code)"
assert_eq '  and the two comments are as they were'        '["First call done", "Carl here"]' "$(disk "[c['text'] for c in d['items'][0]['col_5']]")"

section 'Removing a comment'
R=$(save_as "$EVE" "$(put 1 col_5 "[d['items'][0]['col_5'][0]]")")
assert_eq "Eve may not remove Carl's comment"              'comment_remove_denied' "$(echo "$R" | gj list_invalid.code)"
assert_eq '  told whose it is'                             'Carl'   "$(echo "$R" | gj list_invalid.detail)"
assert_eq '  and it is still there'                        '2'      "$(disk "len(d['items'][0]['col_5'])")"
R=$(save_as "$CARL" "$(put 1 col_5 "[d['items'][0]['col_5'][0]]")")
assert_eq 'Carl may remove his own'                        'true'   "$(echo "$R" | gj success)"
assert_eq '  leaving Eve’s'                                '["First call done"]' "$(disk "[c['text'] for c in d['items'][0]['col_5']]")"
save_as "$CARL" "$(put 1 col_5 "d['items'][0]['col_5'] + [{'text': 'Second try'}]")" > /dev/null
assert_eq 'a removed id is never handed out again'         '[1, 3]' "$(disk "[c['id'] for c in d['items'][0]['col_5']]")"
R=$(save_as "$ADMIN" "$(put 1 col_5 "[d['items'][0]['col_5'][1]]")")
assert_eq "an administrator may remove anyone's"           'true'   "$(echo "$R" | gj success)"
assert_eq '  and Eve’s is gone'                            '["Second try"]' "$(disk "[c['text'] for c in d['items'][0]['col_5']]")"
save_as "$EVE" "$(put 1 col_5 "d['items'][0]['col_5'] + [{'text': 'Paid <b>in</b> full\\\\nthanks'}]")" > /dev/null
R=$(save_as "$EVE" "$(put 1 col_2 '"Ann Larsen"')")
assert_eq 'saving another field leaves the comments alone' 'true'   "$(echo "$R" | gj success)"
assert_eq '  (and nothing was stamped)'                    ''       "$(echo "$R" | gj list)"

section 'Exports read them as text'
CSV=$(get_as "$EVE" "export.php?space=CRM&path=Orders.list&format=csv")
assert_contains 'CSV: a number with its decimals, no unit'  '99.50' "$CSV"
assert_contains 'CSV: Yes / No'                             'Yes'   "$CSV"
assert_contains 'CSV: a comment as name (time): text'       'Carl ('  "$CSV"
assert_contains '  its text'                                '): Second try' "$CSV"
if php -m 2>/dev/null | grep -qi simplexml; then
    XML=$(get_as "$EVE" "export.php?space=CRM&path=Orders.list&format=xml")
    assert_contains 'XML: each comment an element, with who'   'by="Eve"' "$XML"
    assert_contains '  and its text'                            '>Second try</comment>' "$XML"
else
    printf '  %s\n' "$(_dim 'XML skipped — no SimpleXML')"
fi

section 'A template gets them as what they are'
cat > "$WIKI_PAGES/Pages/Order.md" <<'EOF'
---
generator_source: "CRM:LISTID"
generator_folder: Out
---
# {{_title}} {{_edit}}
P={{price}} V={{price.value}} U={{price.unit}}
{{#paid}}PAID{{/paid}}{{^paid}}OPEN{{/paid}} said={{paid}}
{{#price}}HASPRICE{{/price}}{{^price}}NOPRICE{{/price}}
{{#notes}}[{{_index}} {{name}}: {{text}}]{{/notes}}{{^notes}}NONOTES{{/notes}}
ALL={{notes}}
EOF
ORDERS=$(python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == "Orders.list"))' "$WIKI_PAGES/CRM/index.json")
sed -i "s/LISTID/$ORDERS/" "$WIKI_PAGES/Pages/Order.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Pages' > /dev/null
TPL=$(python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == "Order.md"))' "$WIKI_PAGES/Pages/index.json")
R=$(post_as "$ADMIN" 'api.php?action=generator_render&space=Pages' "id=$TPL")
assert_eq 'the generator renders'                          'ok'     "$(echo "$R" | gj status)"
ANN=$(cat "$WIKI_PAGES/Pages/Out/Ann Larsen.md" 2>/dev/null); [ -n "${LIST_TYPES_DEBUG:-}" ] && echo "$ANN" >&2
BO=$(cat "$WIKI_PAGES/Pages/Out/Bo.md" 2>/dev/null)
assert_contains '{{price}} is the number with its unit'     'P=99\.50 kr V=99\.50 U=kr' "$ANN"
assert_contains '{{#paid}} is a section, {{paid}} Yes'      'PAID said=Yes' "$ANN"
assert_contains '  and {{^paid}} its opposite, {{paid}} No' 'OPEN said=No' "$BO"
assert_contains 'a number with no value hides its section' 'NOPRICE' "$BO"
assert_contains '  one with a value shows it'               'HASPRICE' "$ANN"
assert_contains '{{#notes}} is the comments, in order'      '[1 Carl: Second try][2 Eve: Paid' "$ANN"
assert_contains '  their text escaped for Markdown'         'Paid &lt;b&gt;in&lt;/b&gt; full' "$ANN"
assert_not_contains '  never as markup'                     'Paid <b>' "$ANN"
assert_contains '  none is {{^notes}}'                      'NONOTES' "$BO"
assert_contains '{{notes}} is all of them on a line'        'ALL=Carl (' "$ANN"
assert_contains '{{_edit}} is a button for the row'         "<button type=\"button\" class=\"wk-edit\" data-space=\"CRM\" data-list=\"$ORDERS\" data-item=\"1\"></button>" "$ANN"
assert_not_contains '  and is no unknown field'             'unknown_field' "$R"

section 'Templates in HTML'
python3 - "$WIKI_PAGES/Pages/Order.md" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read().replace('generator_folder: Out', 'generator_folder: OutH\nformat: html')
s = s.replace('# {{_title}} {{_edit}}', '<h1>{{_title}} {{_edit}}</h1><p title="{{_edit}}">t</p>')
open(p, 'w').write(s)
PY
R=$(post_as "$ADMIN" 'api.php?action=generator_render&space=Pages' "id=$TPL")
assert_eq 'an HTML template with {{_edit}} renders'        'ok'     "$(echo "$R" | gj status)"
H=$(cat "$WIKI_PAGES/Pages/OutH/Ann Larsen.md" 2>/dev/null)
assert_contains '  the button between tags'                '<h1>Ann Larsen <button type="button" class="wk-edit"' "$H"
assert_contains '  nothing in an attribute'                '<p title="">t</p>' "$H"
assert_contains '  a comment escaped as HTML text'         'Paid &lt;b&gt;in&lt;/b&gt; full' "$H"

section 'The starter templates'
C=$(post_as "$ADMIN" 'api.php?action=generator_create&space=Pages' "source=CRM:$ORDERS&path=templates/StarterMd&folder=SM&format=md")
SMD=$(cat "$WIKI_PAGES/Pages/templates/StarterMd.md" 2>/dev/null)
assert_contains 'Markdown: the edit button beside the title' '# {{_title}} {{_edit}}' "$SMD"
assert_contains '  the comments as a list below'            '{{#notes}}' "$SMD"
assert_not_contains '  not as a table row'                  '| {{notes}} |' "$SMD"
C=$(post_as "$ADMIN" 'api.php?action=generator_create&space=Pages' "source=CRM:$ORDERS&path=templates/StarterHtml&folder=SH&format=html")
SH=$(cat "$WIKI_PAGES/Pages/templates/StarterHtml.md" 2>/dev/null)
assert_contains 'HTML: the edit button in the heading'      '<h1>{{_title}} {{_edit}}</h1>' "$SH"
assert_contains '  each comment a wk-comment'               'class="wk-comment"' "$SH"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
