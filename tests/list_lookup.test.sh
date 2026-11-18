#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Lookup fields on the server: export.php (CSV / XML) writes what a field *shows* — the
# target items' titles, a projected "also show" field, "(deleted #N)" for an item that is
# gone — and resolves the target only within the list's own Space. A page id belonging to
# another Space must not reveal that Space's list, however the file was edited.
# tests/list_fields_ui.test.sh covers the browser.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start off || exit 1
trap fixture_stop EXIT

fixture_space Sales; fixture_space Secret
cat > "$WIKI_PAGES/Sales/Customers.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Title","type":"text_single","role":"title"},
            {"id":"col_3","name":"City","type":"text_single"},
            {"id":"col_4","name":"Code","type":"text_single"}],
 "items":[{"id":1,"col_1":1,"col_2":"Acme","col_3":"Oslo","col_4":"AC"},
          {"id":2,"col_1":2,"col_2":"Beta Corp","col_3":"Bergen","col_4":"BC"}],
 "nextItemId":3}
JSON
cat > "$WIKI_PAGES/Secret/Vault.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Title","type":"text_single"}],
 "items":[{"id":1,"col_1":1,"col_2":"SECRET-VALUE"}],"nextItemId":2}
JSON
curl -s "$WIKI_URL/api.php?action=list&space=Sales"  > /dev/null
curl -s "$WIKI_URL/api.php?action=list&space=Secret" > /dev/null
id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == sys.argv[2]))' "$WIKI_PAGES/$1/index.json" "$2"
}
CUST=$(id_of Sales Customers.list)
VAULT=$(id_of Secret Vault.list)

# Orders: one lookup to Customers (several values, shows Title) with City projected; one
# lookup showing Code instead; and one hand-edited to point at the other Space's list.
cat > "$WIKI_PAGES/Sales/Orders.list" <<JSON
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Title","type":"text_single","role":"title"},
            {"id":"col_3","name":"Customer","type":"lookup","lookup":{"list":$CUST,"field":"col_2","multiple":true}},
            {"id":"col_4","name":"Customer: City","type":"lookup_field","source":"col_3","field":"col_3"},
            {"id":"col_5","name":"Code","type":"lookup","lookup":{"list":$CUST,"field":"col_4","multiple":false}},
            {"id":"col_6","name":"Leak","type":"lookup","lookup":{"list":$VAULT,"field":"col_2","multiple":false}}],
 "items":[{"id":1,"col_1":1,"col_2":"First order","col_3":[1,2],"col_5":[2],"col_6":[1]},
          {"id":2,"col_1":2,"col_2":"Second order","col_3":[9],"col_5":[],"col_6":[]}],
 "nextItemId":3}
JSON

csv=$(curl -s "$WIKI_URL/export.php?path=Orders.list&format=csv&space=Sales")
xml=$(curl -s "$WIKI_URL/export.php?path=Orders.list&format=xml&space=Sales")

section 'CSV'
assert_contains     "the header names every field"                'ItemId,Title,Customer,"Customer: City",Code,Leak' "$csv"
assert_contains     "a lookup exports its targets' titles, joined" '"Acme; Beta Corp"' "$csv"
assert_contains     "the projected field follows it"              '"Oslo; Bergen"' "$csv"
assert_contains     "a lookup may show another field than Title"  ',BC,' "$csv"
assert_contains     "a deleted target item says so"               '"(deleted #9)"' "$csv"
assert_not_contains "the ids themselves are not exported"         '"[1,2]"' "$csv"

section 'another Space is never read'
assert_not_contains "the other Space's value does not appear"     'SECRET-VALUE' "$csv"
assert_contains     "  the cell falls back to the bare id"        ',#1' "$csv"

# XML export needs PHP's SimpleXML extension; without it export.php fails for every list,
# lookups or not, and these would pass on an empty response. Skip rather than pretend.
section 'XML'
if php -m 2>/dev/null | grep -qi '^simplexml$'; then
    assert_not_contains "the other Space's value is not in XML either" 'SECRET-VALUE' "$xml"
    assert_contains     "a lookup exports its titles"                 '<Customer>Acme; Beta Corp</Customer>' "$xml"
    assert_contains     "  and the projection"                        '<CustomerCity>Oslo; Bergen</CustomerCity>' "$xml"
else
    printf '  %s\n' "$(_dim 'skipped — PHP has no SimpleXML extension, so XML export cannot run here')"
fi

section 'a list without lookups is unchanged'
plain=$(curl -s "$WIKI_URL/export.php?path=Customers.list&format=csv&space=Sales")
assert_contains     "Customers exports as before"                 '2,"Beta Corp",Bergen,BC' "$plain"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
