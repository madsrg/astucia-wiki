#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# A list's "Group of fields" (type `object`) as it is shown and exported: one line of
# summary text per entry, the same in the browser (modules/list/fields.js) and on the server
# (lists.php) — which is why the first section runs one set of cases through both and
# requires the same answer — CSV prints that text, and XML writes the group as the
# structure it is. (The static export is deprecated and not covered.)
#
# Node is optional (the twin check skips without it), as is Docker: XML needs PHP's
# SimpleXML, which CI has; without it locally the XML half runs in the php:8.x-cli images
# when they are there, and skips otherwise.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh
APP=$(cd .. && pwd)

# ── Cases: [column, value, expected text] ────────────────────────────────────
CASES=$(mktemp); trap 'rm -f "$CASES"' EXIT
cat > "$CASES" <<'JSON'
[
 [{"id":"a","type":"object","fields":[{"id":"s","name":"Street"},{"id":"c","name":"City"},{"id":"z","name":"Zip"}]},
  {"s":"Main St 1","c":"Oslo","z":"0150"}, "Main St 1, Oslo"],
 [{"id":"a","type":"object","summary":["c","z"],"fields":[{"id":"s","name":"Street"},{"id":"c","name":"City"},{"id":"z","name":"Zip"}]},
  {"s":"Main St 1","c":"Oslo","z":"0150"}, "Oslo, 0150"],
 [{"id":"a","type":"object","summary":["gone","c"],"fields":[{"id":"s","name":"Street"},{"id":"c","name":"City"}]},
  {"s":"Main St 1","c":"Oslo"}, "Oslo"],
 [{"id":"a","type":"object","fields":[{"id":"s","name":"Street"},{"id":"c","name":"City"}]},
  {"s":"","c":"Oslo"}, "Oslo"],
 [{"id":"a","type":"object","fields":[{"id":"s","name":"Street"}]}, null, ""],
 [{"id":"a","type":"object","fields":[{"id":"s","name":"Street"}]}, "not a group", ""],
 [{"id":"a","type":"object","fields":[{"id":"s","name":"Street"}]}, [{"s":"x"}], ""],
 [{"id":"k","type":"object","multiple":true,"fields":[{"id":"n","name":"Number"},{"id":"e","name":"Expires"}]},
  [{"id":4,"n":"1234","e":"2027-01"},{"id":7,"n":"9876","e":"2028-05"}], "1234, 2027-01; 9876, 2028-05"],
 [{"id":"k","type":"object","multiple":true,"fields":[{"id":"n","name":"Number"}]},
  [{"id":4,"n":"1234"},"junk",{"id":5,"n":"5555"}], "1234; 5555"],
 [{"id":"k","type":"object","multiple":true,"fields":[{"id":"n","name":"Number"}]},
  {"n":"not a list"}, ""],
 [{"id":"k","type":"object","multiple":true,"fields":[{"id":"n","name":"Number"}]}, [], ""]
]
JSON

section 'One summary, browser and server alike'
php_out=$(CASES="$CASES" APP="$APP" php -r '
    require getenv("APP") . "/lists.php";
    foreach (json_decode(file_get_contents(getenv("CASES")), true) as [$col, $value, $want]) {
        echo json_encode(wiki_list_cell($col, [$col["id"] => $value], [$col], [])), "\n";
    }')
i=0
while IFS= read -r line; do
    want=$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1]))[int(sys.argv[2])][2]))' "$CASES" "$i")
    assert_eq "server: case $((i + 1))" "$want" "$line"
    i=$((i + 1))
done <<< "$php_out"

if command -v node >/dev/null 2>&1; then
    STUB=$(mktemp --suffix=.mjs)
    cat > "$STUB" <<'JS'
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
globalThis.window = globalThis;
globalThis.document = { addEventListener() {}, dispatchEvent() {}, documentElement: { lang: 'en' }, querySelectorAll: () => [] };
const f = await import(process.argv[2] + '/modules/list/fields.js');
const cases = JSON.parse((await import('node:fs')).readFileSync(process.argv[3], 'utf8'));
for (const [col, value] of cases) console.log(JSON.stringify(f.cellText(col, { [col.id]: value }, [col])));
JS
    js_out=$(node "$STUB" "$APP" "$CASES" 2>&1); rm -f "$STUB"
    assert_eq 'the browser gives the same text for every case' "$php_out" "$js_out"
else
    printf '  %s\n' "$(_dim 'browser twin skipped — no node on PATH')"
fi

# ── Through the wiki ──────────────────────────────────────────────────────────
fixture_start off || exit 1
trap 'rm -f "$CASES"; fixture_stop' EXIT
fixture_space Sales
cat > "$WIKI_PAGES/Sales/Customers.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Name","type":"text_single","role":"title"},
            {"id":"col_3","name":"Address","type":"object","summary":["sf_s","sf_c"],
             "fields":[{"id":"sf_s","name":"Street","type":"text_single"},
                       {"id":"sf_c","name":"City","type":"text_single"},
                       {"id":"sf_z","name":"Zip","type":"text_single"}]},
            {"id":"col_4","name":"Debit cards","type":"object","multiple":true,
             "fields":[{"id":"sf_n","name":"Number","type":"text_single"},
                       {"id":"sf_e","name":"Expires","type":"date"}]}],
 "items":[{"id":1,"col_1":1,"col_2":"Acme","col_3":{"sf_s":"Main St 1","sf_c":"Oslo","sf_z":"0150"},
           "col_4":[{"id":4,"sf_n":"1234","sf_e":"2027-01-31"},{"id":7,"sf_n":"<b>9876</b>","sf_e":"2028-05-31"}]},
          {"id":2,"col_1":2,"col_2":"Beta","col_3":null,"col_4":[]}],
 "nextItemId":3,"nextEntryId":8}
JSON
curl -s "$WIKI_URL/api.php?action=list_spaces" > /dev/null
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Sales" > /dev/null

section 'CSV'
csv=$(curl -s "$WIKI_URL/export.php?path=Customers.list&format=csv&space=Sales")
assert_contains 'a group is one column, by its name'       'ItemId,Name,Address,"Debit cards"' "$csv"
assert_contains '  holding its summary'                    '"Main St 1, Oslo"' "$csv"
assert_contains '  several entries joined with "; "'       '"1234, 2027-01-31; <b>9876</b>, 2028-05-31"' "$csv"
assert_contains 'an item without a group has empty cells'  '2,Beta,,' "$csv"

section 'XML: a group as the structure it is'
XML_CHECK='require getenv("APP") . "/lists.php";
    $raw = json_decode(file_get_contents(getenv("LIST")), true);
    $flat = $raw;
    foreach ($flat["items"] as &$it) foreach ($raw["columns"] as $c) $it[$c["id"]] = wiki_list_cell($c, $it, $raw["columns"], []);
    echo wiki_list_xml($raw, $flat);'
xml_ok() {   # the assertions, on whichever XML we could produce
    assert_contains "$1: a single group nests its sub-fields"  '<Address><Street>Main St 1</Street><City>Oslo</City><Zip>0150</Zip></Address>' "$2"
    assert_contains "$1: several entries, each with its id"   '<Debitcards><entry id="4"><Number>1234</Number><Expires>2027-01-31</Expires></entry><entry id="7">' "$2"
    assert_contains "$1: markup in a value is escaped"        '&lt;b&gt;9876&lt;/b&gt;' "$2"
    assert_contains "$1: other fields unchanged"              '<Name>Acme</Name>' "$2"
    assert_contains "$1: an empty group is an empty element"   '<Address/>' "$2"
}
if php -m 2>/dev/null | grep -qi '^simplexml$'; then
    xml_ok 'export.php' "$(curl -s "$WIKI_URL/export.php?path=Customers.list&format=xml&space=Sales")"
elif command -v docker >/dev/null 2>&1; then
    ran=0
    for v in 8.3 8.4 8.5; do
        docker image inspect "php:$v-cli" >/dev/null 2>&1 || continue
        out=$(docker run --rm -v "$APP:/app:ro" -v "$WIKI_PAGES/Sales/Customers.list:/list.json:ro" \
              -e APP=/app -e LIST=/list.json "php:$v-cli" php -r "$XML_CHECK" 2>&1)
        xml_ok "PHP $v" "$out"; ran=1
    done
    [ $ran = 1 ] || printf '  %s\n' "$(_dim 'skipped — no SimpleXML here, and no php:8.x-cli image')"
else
    printf '  %s\n' "$(_dim 'skipped — PHP has no SimpleXML extension and docker is not available')"
fi

section 'Saving: a list that breaks a group is refused'
# Every case starts from the list as it is on disk, breaks one thing, and posts it to
# `save`. A refusal must say why and leave the file byte for byte as it was.
TARGET="$WIKI_PAGES/Sales/Customers.list"
save_list() { curl -s -o "$WIKI_ROOT/save.json" -w '%{http_code}' -X POST --data-binary @"$1" \
    "$WIKI_URL/api.php?action=save&space=Sales&file=Customers.list"; }
variant() {   # variant <python expression on `d`> → a file holding the changed list
    python3 - "$TARGET" "$1" "$WIKI_ROOT/variant.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
exec(sys.argv[2])
open(sys.argv[3], "w").write(d if isinstance(d, str) else json.dumps(d))
PY
    echo "$WIKI_ROOT/variant.json"
}
reason() { python3 -c 'import json,sys
d = json.load(open(sys.argv[1])); r = d.get("list_invalid") or {}
print(r.get("code", "none") + "|" + str(r.get("item")) + "|" + str(r.get("field")))' "$WIKI_ROOT/save.json"; }
refused() {   # refused <label> <expected code|item|field> <python>
    local before; before=$(sha1sum "$TARGET" | cut -d' ' -f1)
    local code; code=$(save_list "$(variant "$3")")
    assert_eq "$1" "422 $2" "$code $(reason)"
    assert_eq "  …and the file is untouched" "$before" "$(sha1sum "$TARGET" | cut -d' ' -f1)"
}

code=$(save_list "$(variant 'd["items"][0]["col_2"] = "Acme A/S"')")
assert_eq 'control: a valid change saves'                 '200' "$code"
assert_contains '  …and is written'                       'Acme A/S' "$(cat "$TARGET")"

refused 'not JSON'                                        'not_list|None|None' 'd = "{not json"'
refused 'no columns'                                      'not_list|None|None' 'd = {"items": []}'
refused 'a single group holding a list'                   'group_not_object|1|Address' 'd["items"][0]["col_3"] = [{"sf_s": "x"}]'
refused 'several entries not in a list'                   'group_not_list|1|Debit cards' 'd["items"][0]["col_4"] = {"sf_n": "1"}'
refused 'an entry without an id'                          'entry_no_id|1|Debit cards' 'd["items"][0]["col_4"][0].pop("id")'
refused 'an entry id used twice'                          'entry_id_taken|2|Debit cards' 'd["items"][1]["col_4"] = [{"id": 4, "sf_n": "dup"}]'
refused 'a sub-field the group does not have'            'unknown_subfield|1|Address' 'd["items"][0]["col_3"]["sf_secret"] = "x"'
refused 'a value that is not plain'                       'value_not_text|1|Debit cards' 'd["items"][0]["col_4"][0]["sf_n"] = {"nested": 1}'
refused 'a group with no fields'                          'bad_group|None|Address' 'next(c for c in d["columns"] if c["id"] == "col_3")["fields"] = []'
refused 'a sub-field type a group cannot hold'            'bad_group|None|Address' 'next(c for c in d["columns"] if c["id"] == "col_3")["fields"][0]["type"] = "lookup"'
refused 'an entry counter that would reuse an id'         'counter_behind|None|None' 'd["nextEntryId"] = 7'
assert_contains 'the reason is said in English for API callers' 'The list was not saved: item 1, field' \
    "$(save_list "$(variant 'd["items"][0]["col_3"]["sf_secret"] = "x"')" > /dev/null; cat "$WIKI_ROOT/save.json")"

code=$(save_list "$(variant 'd["items"][1]["col_3"] = {}; d["items"][1]["col_4"] = None; d.pop("nextEntryId")')")
assert_eq 'control: an empty group, no entries and no counter are fine' '200' "$code"
# Fields that are not groups are as lenient as they always were.
cat > "$WIKI_PAGES/Sales/Plain.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single"}],"items":[{"id":1,"col_2":{"odd":"but allowed"}}]}
JSON
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Sales" > /dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-binary @"$WIKI_PAGES/Sales/Plain.list" \
    "$WIKI_URL/api.php?action=save&space=Sales&file=Plain.list")
assert_eq 'control: a list without groups saves as before' '200' "$code"

exit $(( ASSERT_FAIL > 0 ))
