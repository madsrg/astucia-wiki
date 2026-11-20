#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Page generators render again by themselves (page_generators.php, TRACKING):
#  - a save of the template, of the source list, or of a list its lookups read, renders
#    the generator once the response has gone — no button, no workflow;
#  - the owner is whoever last saved the template: an editor who cannot read the source,
#    or an AI user, saving it stops the generator rather than publishing under somebody
#    else's authority;
#  - a deleted template archives its pages;
#  - what raises no event — a file changed on disk, a template dropped in — is caught by
#    the cron tick, and a generator that could not render (a frozen Space) is retried
#    without anything changing.
# tests/page_generators.test.sh covers the render itself.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space CRM; fixture_space Customers

fixture_users '{"users":[
 {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
 {"uid":2,"name":"Eve","role":"editor","auth":"otp","email":"eve@example.com","spaces":["Customers"]},
 {"uid":3,"name":"Carl","role":"editor","auth":"otp","email":"carl@example.com","spaces":["Customers","CRM"]},
 {"uid":-7,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_testbot",
  "ai_config":{"provider":"openai","api_url":"http://127.0.0.1:9/x","api_key":"k","model":"m"}}]}'
ADMIN=$WIKI_ROOT/admin.jar; EVE=$WIKI_ROOT/eve.jar; CARL=$WIKI_ROOT/carl.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EVE"   "uid=2&name=Eve&role=editor&auth=otp&spaces=Customers"
fixture_login "$CARL"  "uid=3&name=Carl&role=editor&auth=otp&spaces=Customers,CRM"

cat > "$WIKI_PAGES/CRM/Staff.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_2":"Mona"},{"id":2,"col_2":"Nils"}],"nextItemId":3}
JSON
for s in CRM Customers; do get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null; done
id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next((k for k, v in ix.items() if v.get("path") == sys.argv[2]), ""))' "$WIKI_PAGES/$1/index.json" "$2"
}
STAFF=$(id_of CRM Staff.list)

# The source list's JSON, for a save or a direct write.
list_json() {
    python3 - "$STAFF" "$1" <<'PY'
import json, sys
cols = [{"id":"col_2","name":"Name","type":"text_single","role":"title"},
        {"id":"col_3","name":"Salary","type":"text_single"},
        {"id":"col_4","name":"Manager","type":"lookup","lookup":{"list":int(sys.argv[1]),"multiple":False}}]
print(json.dumps({"columns": cols, "items": json.loads(sys.argv[2]), "nextItemId": 100}))
PY
}
list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-1","col_4":[1]}]' > "$WIKI_PAGES/CRM/Accounts.list"
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
ACCOUNTS=$(id_of CRM Accounts.list)

mkdir -p "$WIKI_PAGES/Customers/Templates"
cat > "$WIKI_PAGES/Customers/Templates/Account.md" <<EOF
---
generator_source: CRM:$ACCOUNTS
generator_folder: Accounts
---
placeholder
EOF
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
TPL=$(id_of Customers Templates/Account.md)

enc() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
save() { curl -s -b "$1" -c "$1" -X POST --data-binary "$4" "$WIKI_URL/api.php?action=save&space=$2&file=$(enc "$3")"; }
save_token() { curl -s -X POST -H "Authorization: Bearer $1" --data-binary "$4" "$WIKI_URL/api.php?action=save&space=$2&file=$(enc "$3")"; }
runner() { (cd "$WIKI_APP" && php run_ai_agent_jobs.php 2>&1); }
state() { python3 -c 'import json,sys
g = json.load(open(sys.argv[1]))["generators"].get(sys.argv[2], {})
for k in sys.argv[3].split("."): g = g.get(k, "") if isinstance(g, dict) else ""
print(g if g is not None else "null")' "$WIKI_SYS/generators.json" "Customers:$TPL" "$1" 2>/dev/null; }
page() { cat "$WIKI_PAGES/Customers/Accounts/$1.md" 2>/dev/null; }

section 'Saving the template registers it and renders it'
assert_file_missing 'no pages before'                    "$WIKI_PAGES/Customers/Accounts"
r=$(save "$CARL" Customers Templates/Account.md '# {{name}}
Manager: {{manager}}')
assert_contains 'the save itself succeeds'               '"success":true' "$r"
assert_contains 'the page is there when the save returns' '# Acme' "$(page Acme)"
assert_eq '  its owner is the person who saved it'       'Carl' "$(state owner.name)"
assert_eq '  and the generator is clean'                 'False' "$(state dirty)"

section 'Saving a list it reads renders it again'
r=$(save "$CARL" CRM Accounts.list "$(list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-1","col_4":[2]}]')")
assert_contains 'the list saves'                         '"success":true' "$r"
assert_contains 'the source changed: the page follows'   'Manager: Nils' "$(page Acme)"
r=$(save "$CARL" CRM Staff.list '{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],"items":[{"id":1,"col_2":"Mona"},{"id":2,"col_2":"Nils Holm"}],"nextItemId":3}')
assert_contains 'a list its lookups read changed: so does the page' 'Manager: Nils Holm' "$(page Acme)"
M1=$(stat -c %Y "$WIKI_PAGES/Customers/Accounts/Acme.md"); sleep 1
save "$CARL" Customers Templates/Other.md 'An unrelated page' > /dev/null
assert_eq 'an unrelated save renders nothing'            "$M1" "$(stat -c %Y "$WIKI_PAGES/Customers/Accounts/Acme.md")"

section 'The owner is whoever last saved the template'
# Eve can edit Customers but not read CRM. Adding a field she cannot see must not publish it.
r=$(save "$EVE" Customers Templates/Account.md '# {{name}}
Manager: {{manager}}
Pay: {{salary}}')
assert_contains "Eve's save itself is allowed (it is her Space)" '"success":true' "$r"
assert_eq '  she becomes the owner'                      'Eve' "$(state owner.name)"
assert_eq '  so the generator stops'                     'owner_no_source' "$(state error)"
assert_not_contains '  and the salary is not published'  'SALARY-1' "$(page Acme)"
# Carl, who may read CRM, vouches for it by saving it.
save "$CARL" Customers Templates/Account.md '# {{name}}
Manager: {{manager}}
Pay: {{salary}}' > /dev/null
assert_contains 'control: when Carl saves it, it is published' 'SALARY-1' "$(page Acme)"

# An AI user is not a person: it may edit the template, and the generator waits for one.
r=$(save_token wk_ai_testbot Customers Templates/Account.md '# {{name}} (by AI)
Pay: {{salary}}')
assert_contains "the AI user's save goes through"        '"success":true' "$r"
assert_eq '  nobody owns the generator now'              'owner_missing' "$(state error)"
assert_not_contains '  and nothing was rendered'         'by AI' "$(page Acme)"
r=$(post_as "$CARL" 'api.php?action=generator_render&space=Customers' "id=$TPL")
assert_eq 'control: a person pressing Re-render takes it over' 'ok' "$(echo "$r" | python3 -c 'import json,sys;print(json.load(sys.stdin)["status"])')"
assert_contains '  and renders the AI edit'              'by AI' "$(page Acme)"

section 'What raises no event is the cron tick'
list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-2","col_4":[]},{"id":2,"col_2":"Beta","col_3":"S","col_4":[]}]' > "$WIKI_PAGES/CRM/Accounts.list"
assert_not_contains 'a file changed on disk: nothing yet' 'SALARY-2' "$(page Acme)"
out=$(runner)
assert_contains 'the tick notices'                       'changed outside the wiki' "$out"
assert_contains '  and renders'                          'SALARY-2' "$(page Acme)"
assert_file_exists '  including the new row'             "$WIKI_PAGES/Customers/Accounts/Beta.md"
out=$(runner)
assert_not_contains 'the next tick has nothing to say'   'Generator' "$out"

# A template that arrived without a save: found by the hourly scan, without an owner.
mkdir -p "$WIKI_PAGES/Customers/Dropped"
printf -- "---\ngenerator_source: CRM:%s\ngenerator_folder: FromDisk\n---\n# {{name}}\n" "$ACCOUNTS" > "$WIKI_PAGES/Customers/Dropped/T.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
DROPPED=$(id_of Customers Dropped/T.md)
rm -f "$WIKI_SYS/generators.discover"     # the scan is hourly; make this tick the hour
out=$(runner)
assert_contains 'a dropped-in template is found'         "Customers:$DROPPED: template found" "$out"
assert_file_missing '  but renders nothing without a person to vouch for it' "$WIKI_PAGES/Customers/FromDisk"
post_as "$CARL" 'api.php?action=generator_render&space=Customers' "id=$DROPPED" > /dev/null
assert_file_exists 'control: once a person runs it, it does' "$WIKI_PAGES/Customers/FromDisk/Acme.md"

section 'A generator that could not render is retried'
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Customers&readonly=1' > /dev/null
list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-3","col_4":[]},{"id":2,"col_2":"Beta","col_3":"S","col_4":[]}]' > "$WIKI_PAGES/CRM/Accounts.list"
runner > /dev/null
assert_eq 'frozen: it waits'                             'readonly' "$(state status)"
assert_not_contains '  without writing'                  'SALARY-3' "$(page Acme)"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Customers&readonly=0' > /dev/null
runner > /dev/null
assert_contains 'thawed: the next tick catches up, nothing else changed' 'SALARY-3' "$(page Acme)"

# Nothing on disk changes when an owner is given access, so the drift check cannot see it;
# only the retry of a generator that failed brings it back.
save "$EVE" Customers Templates/Account.md '# {{name}}
Pay: {{salary}} (Eve)' > /dev/null
assert_eq 'Eve saves it again: stopped'                  'owner_no_source' "$(state error)"
python3 - "$WIKI_SYS/users.json" <<'PY'
import json, sys; p = sys.argv[1]; d = json.load(open(p))
for u in d["users"]:
    if u.get("name") == "Eve": u["spaces"] = ["Customers", "CRM"]
json.dump(d, open(p, "w"))
PY
runner > /dev/null
assert_contains 'given access to CRM, the next tick renders as her' '(Eve)' "$(page Acme)"

section 'Renaming a Space carries its generators'
# Saved as the admin: a signed-in session keeps the Space list it had at sign-in, so Carl's
# still says CRM after the rename (the generator's owner check reads users.json, which the
# rename does update).
rename_space() { post_as "$ADMIN" 'api.php?action=rename_space' "old_name=$1&new_name=$2"; }
TPLFILE="$WIKI_PAGES/Customers/Templates/Account.md"
assert_contains 'the source Space is renamed'            '"success":true' "$(rename_space CRM Sales)"
assert_contains "  the template's source follows"        "generator_source: Sales:$ACCOUNTS" "$(cat "$TPLFILE")"
r=$(save "$ADMIN" Sales Accounts.list "$(list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-R1","col_4":[]},{"id":2,"col_2":"Beta","col_3":"S","col_4":[]}]')")
assert_contains '  a save in the renamed Space still renders' 'SALARY-R1' "$(page Acme)"
out=$(runner)
assert_not_contains '  and the rename itself is no change to render' 'Customers:'"$TPL" "$out"
assert_contains 'the target Space is renamed'            '"success":true' "$(rename_space Customers Clients)"
r=$(save "$ADMIN" Sales Accounts.list "$(list_json '[{"id":1,"col_2":"Acme","col_3":"SALARY-R2","col_4":[]},{"id":2,"col_2":"Beta","col_3":"S","col_4":[]}]')")
assert_contains '  its generator renders in the new place' 'SALARY-R2' "$(cat "$WIKI_PAGES/Clients/Accounts/Acme.md")"
assert_eq '  under its new key'                          'Clients' \
    "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["generators"]["Clients:"+sys.argv[2]]["space"])' "$WIKI_SYS/generators.json" "$TPL")"
rename_space Clients Customers > /dev/null; rename_space Sales CRM > /dev/null
assert_contains 'and back again'                         "generator_source: CRM:$ACCOUNTS" "$(cat "$TPLFILE")"

section 'Deleting the template archives its pages'
r=$(post_as "$CARL" 'api.php?action=delete&space=Customers' 'path=Templates/Account.md')
assert_contains 'the template is deleted'                '"success":true' "$r"
assert_file_exists '  its pages stay'                    "$WIKI_PAGES/Customers/Accounts/Acme.md"
assert_contains '  archived'                             "archived_from: $TPL" "$(page Acme)"
assert_contains '  because the template went, not the row' 'archived_reason: template_gone' "$(page Acme)"
assert_not_contains '  so no list is named'                'archived_list' "$(page Acme)"
assert_not_contains '  no longer generated'              'generated_by' "$(page Acme)"
assert_eq '  and the generator is forgotten'             '' "$(state space)"

exit $(( ASSERT_FAIL > 0 ))
