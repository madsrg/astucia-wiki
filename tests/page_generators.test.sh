#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Page generators (page_generators.php): a template page in one Space renders one page per
# row of a .list in another, through `generator_render`.
#
# What is pinned:
#  - who may run one — a person who can edit the target Space *and* read the source Space,
#    since the generated pages publish the source's data to the target's readers;
#  - that a render is a diff: unchanged rows write nothing, a changed row rewrites one page,
#    a renamed row renames its page and keeps its id, a new row creates one, a removed row
#    archives its page rather than deleting it;
#  - that a page's own front matter survives, a page the generator does not own is never
#    overwritten, and a page whose `generated_by` was removed by hand is left alone;
#  - that data is escaped in the file, lookups resolve in the source Space, and one render
#    is one git commit;
#  - that workflows do not fire on generator writes (one template edit would otherwise
#    queue a run per page).

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space CRM; fixture_space Customers; fixture_space Secret

fixture_users '{"users":[
 {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
 {"uid":2,"name":"Eve","role":"editor","auth":"otp","email":"eve@example.com","spaces":["Customers"]},
 {"uid":3,"name":"Carl","role":"editor","auth":"otp","email":"carl@example.com","spaces":["Customers","CRM"]},
 {"uid":4,"name":"Rita","role":"reader","auth":"otp","email":"rita@example.com"},
 {"uid":-7,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_testbot",
  "ai_config":{"provider":"openai","api_url":"http://127.0.0.1:9/x","api_key":"k","model":"m"}}]}'
ADMIN=$WIKI_ROOT/admin.jar; EVE=$WIKI_ROOT/eve.jar; CARL=$WIKI_ROOT/carl.jar; RITA=$WIKI_ROOT/rita.jar
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin&auth=otp"
fixture_login "$EVE"   "uid=2&name=Eve&role=editor&auth=otp&spaces=Customers"
fixture_login "$CARL"  "uid=3&name=Carl&role=editor&auth=otp&spaces=Customers,CRM"
fixture_login "$RITA"  "uid=4&name=Rita&role=reader&auth=otp"

cat > "$WIKI_PAGES/CRM/Staff.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_1":1,"col_2":"Mona"},{"id":2,"col_1":2,"col_2":"Nils"}],"nextItemId":3}
JSON
cat > "$WIKI_PAGES/Secret/Vault.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],
 "items":[{"id":1,"col_2":"SECRET-ROW"}],"nextItemId":2}
JSON
printf 'Not a list\n' > "$WIKI_PAGES/CRM/Readme.md"
for s in CRM Customers Secret; do get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null; done

id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next((k for k, v in ix.items() if v.get("path") == sys.argv[2]), ""))' "$WIKI_PAGES/$1/index.json" "$2"
}
STAFF=$(id_of CRM Staff.list); VAULT=$(id_of Secret Vault.list); README=$(id_of CRM Readme.md)

# The source list. set_items rewrites its rows; everything else stays.
set_items() {
    python3 - "$WIKI_PAGES/CRM/Accounts.list" "$STAFF" "$1" <<'PY'
import json, sys
cols = [{"id":"col_1","name":"ItemId","type":"autoincrement"},
        {"id":"col_2","name":"Name","type":"text_single","role":"title"},
        {"id":"col_3","name":"E-mail","type":"text_single"},
        {"id":"col_4","name":"Notes","type":"text_multi"},
        {"id":"col_5","name":"Account manager","type":"lookup","lookup":{"list":int(sys.argv[2]),"multiple":False}}]
items = json.loads(sys.argv[3])
json.dump({"columns": cols, "items": items, "nextItemId": 100}, open(sys.argv[1], "w"))
PY
}
set_items '[{"id":1,"col_2":"Acme","col_3":"info@acme.test","col_4":"","col_5":[1]},
            {"id":2,"col_2":"Beta Corp","col_3":"hi@beta.test","col_4":"Key account","col_5":[2]}]'
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
ACCOUNTS=$(id_of CRM Accounts.list)

# The template, in the target Space.
write_template() {
    mkdir -p "$WIKI_PAGES/Customers/Templates"
    cat > "$WIKI_PAGES/Customers/Templates/Account.md" <<EOF
---
generator_source: $1
generator_folder: ${2:-Accounts}
---
# {{name}}

E-mail: {{e_mail}} · Manager: {{account_manager}}
{{#notes}}

> {{notes}}
{{/notes}}
EOF
}
write_template "CRM:$ACCOUNTS"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
TPL=$(id_of Customers Templates/Account.md)

# Content history for the target Space, to count commits.
git -C "$WIKI_PAGES/Customers" init -q
git -C "$WIKI_PAGES/Customers" -c user.name=t -c user.email=t@t add -A
git -C "$WIKI_PAGES/Customers" -c user.name=t -c user.email=t@t commit -qm init
commits() { git -C "$WIKI_PAGES/Customers" rev-list --count HEAD; }

render() { post_as "$1" 'api.php?action=generator_render&space=Customers' "id=${2:-$TPL}"; }
field() { python3 -c 'import json,sys
d = json.load(sys.stdin)
for k in sys.argv[1].split("."): d = d.get(k, "") if isinstance(d, dict) else ""
print(d if not isinstance(d, (dict, list)) else json.dumps(d))' "$1"; }
fm() { python3 -c 'import sys,re
raw = open(sys.argv[1]).read()
m = re.match(r"---\n(.*?)\n---\n", raw, re.S)
for line in (m.group(1).splitlines() if m else []):
    k, _, v = line.partition(":")
    if k.strip() == sys.argv[2]: print(v.strip().strip("\"")); break' "$WIKI_PAGES/Customers/$1" "$2"; }
mtime() { stat -c %Y "$WIKI_PAGES/Customers/$1"; }

section 'Who may run it'
OUT=$(render "$RITA")
assert_not_contains 'a reader cannot'                          '"success":true' "$OUT"
OUT=$(render "$EVE")
assert_eq 'an editor who cannot read the source Space cannot' 'owner_no_source' "$(echo "$OUT" | field error)"
assert_file_missing '  and nothing was written'              "$WIKI_PAGES/Customers/Accounts"
# Positive control: the same request by someone with both Spaces.
OUT=$(render "$CARL")
assert_eq 'an editor of both Spaces can'                    'ok' "$(echo "$OUT" | field status)"
assert_eq '  two pages created'                             '2'  "$(echo "$OUT" | field counts.created)"
assert_eq 'one render, one commit'                          '2'  "$(commits)"
assert_contains '  named after the template'                'Template: Account' \
    "$(git -C "$WIKI_PAGES/Customers" log -1 --format='%an %s')"

section 'The pages'
assert_file_exists 'a page per row, named by the Title'       "$WIKI_PAGES/Customers/Accounts/Acme.md"
assert_file_exists '  and the other'                          "$WIKI_PAGES/Customers/Accounts/Beta Corp.md"
ACME=$(cat "$WIKI_PAGES/Customers/Accounts/Acme.md")
assert_contains 'the row is rendered'                         '# Acme' "$ACME"
assert_contains 'a lookup shows its target, read in the source Space' 'Manager: Mona' "$ACME"
assert_contains 'the e-mail is escaped (no mention)'          'info&#64;acme.test' "$ACME"
assert_not_contains 'an empty section is left out'            '> ' "$ACME"
assert_contains 'a non-empty one is not'                      '> Key account' "$(cat "$WIKI_PAGES/Customers/Accounts/Beta Corp.md")"
assert_eq 'generated_by names the template'                   "$TPL" "$(fm Accounts/Acme.md generated_by)"
assert_eq 'generated_row names the row'                       '1'    "$(fm Accounts/Acme.md generated_row)"
ACME_ID=$(id_of Customers Accounts/Acme.md)
assert_contains 'indexed, written by the template'            'Template: Account' \
    "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))[sys.argv[2]]["updatedBy"]["name"])' "$WIKI_PAGES/Customers/index.json" "$ACME_ID")"

section 'A render is a diff'
M1=$(mtime Accounts/Acme.md); sleep 1
OUT=$(render "$CARL")
assert_eq 'nothing changed: nothing written'                  '2' "$(echo "$OUT" | field counts.unchanged)"
assert_eq '  the file is untouched'                           "$M1" "$(mtime Accounts/Acme.md)"
assert_eq '  and no commit'                                   '2' "$(commits)"

set_items '[{"id":1,"col_2":"Acme","col_3":"sales@acme.test","col_4":"","col_5":[1]},
            {"id":2,"col_2":"Beta Corp","col_3":"hi@beta.test","col_4":"Key account","col_5":[2]}]'
OUT=$(render "$CARL")
assert_eq 'one row changed: one page updated'                 '1' "$(echo "$OUT" | field counts.updated)"
assert_eq '  the other unchanged'                             '1' "$(echo "$OUT" | field counts.unchanged)"
assert_contains '  with the new value'                        'sales&#64;acme.test' "$(cat "$WIKI_PAGES/Customers/Accounts/Acme.md")"

# The page's own front matter belongs to the page.
python3 - "$WIKI_PAGES/Customers/Accounts/Acme.md" <<'PY'
import sys; p = sys.argv[1]; s = open(p).read()
open(p, "w").write(s.replace("---\n", "---\ntags: [vip]\n", 1))
PY
set_items '[{"id":1,"col_2":"Acme Ltd","col_3":"sales@acme.test","col_4":"","col_5":[2]},
            {"id":2,"col_2":"Beta Corp","col_3":"hi@beta.test","col_4":"Key account","col_5":[2]},
            {"id":3,"col_2":"Gamma","col_3":"g@gamma.test","col_4":"","col_5":[]}]'
OUT=$(render "$CARL")
assert_eq 'a renamed row renames its page'                    '1' "$(echo "$OUT" | field counts.renamed)"
assert_file_missing '  the old name is gone'                  "$WIKI_PAGES/Customers/Accounts/Acme.md"
assert_eq '  and the page keeps its id'                       "$ACME_ID" "$(id_of Customers 'Accounts/Acme Ltd.md')"
assert_eq '  its tags survive the rewrite'                    '[vip]' "$(fm 'Accounts/Acme Ltd.md' tags)"
assert_contains '  the change of manager is rendered'         'Manager: Nils' "$(cat "$WIKI_PAGES/Customers/Accounts/Acme Ltd.md")"
assert_eq 'a new row creates a page'                          '1' "$(echo "$OUT" | field counts.created)"
assert_file_exists '  Gamma'                                  "$WIKI_PAGES/Customers/Accounts/Gamma.md"

section 'A removed row is archived, not deleted'
set_items '[{"id":1,"col_2":"Acme Ltd","col_3":"sales@acme.test","col_4":"","col_5":[2]},
            {"id":2,"col_2":"Beta Corp","col_3":"hi@beta.test","col_4":"Key account","col_5":[2]}]'
OUT=$(render "$CARL")
assert_eq 'one archived'                                      '1' "$(echo "$OUT" | field counts.archived)"
assert_file_exists '  the page is still there'                "$WIKI_PAGES/Customers/Accounts/Gamma.md"
assert_eq '  no longer generated'                             '' "$(fm Accounts/Gamma.md generated_by)"
assert_eq '  archived from the template'                      "$TPL" "$(fm Accounts/Gamma.md archived_from)"
assert_eq '  because its row was deleted'                    'row_removed' "$(fm Accounts/Gamma.md archived_reason)"
assert_eq '  from this list'                                 'Accounts' "$(fm Accounts/Gamma.md archived_list)"
assert_contains '  which `get` passes on'                    '"reason":"row_removed","list":"Accounts"' \
    "$(get_as "$CARL" 'api.php?action=get&space=Customers&file=Accounts/Gamma.md')"
OUT=$(render "$CARL")
assert_eq '  and the next render leaves it alone'             '0' "$(echo "$OUT" | field counts.archived)"

section 'Names: clashes, unowned pages, unsafe characters'
fixture_page 'Customers/Accounts/Taken.md' 'Somebody else wrote this.'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
set_items '[{"id":1,"col_2":"Acme Ltd","col_3":"","col_4":"","col_5":[]},
            {"id":2,"col_2":"Beta Corp","col_3":"","col_4":"","col_5":[]},
            {"id":4,"col_2":"Taken","col_3":"","col_4":"","col_5":[]},
            {"id":5,"col_2":"Twin","col_3":"","col_4":"","col_5":[]},
            {"id":6,"col_2":"Twin","col_3":"","col_4":"","col_5":[]},
            {"id":7,"col_2":"../.hidden/x <b>|</b> @alice","col_3":"","col_4":"","col_5":[]}]'
OUT=$(render "$CARL")
assert_eq "a page it doesn't own is never overwritten"       'Somebody else wrote this.' "$(cat "$WIKI_PAGES/Customers/Accounts/Taken.md")"
assert_file_exists '  the row gets its id in the name'        "$WIKI_PAGES/Customers/Accounts/Taken (4).md"
assert_file_exists 'two rows with one name: the first'        "$WIKI_PAGES/Customers/Accounts/Twin.md"
assert_file_exists '  the second carries its id'              "$WIKI_PAGES/Customers/Accounts/Twin (6).md"
assert_file_exists 'separators and markup leave the name'     "$WIKI_PAGES/Customers/Accounts/hidden x b b @alice.md"
assert_contains '  and the page body is escaped'              '&lt;b&gt;\|&lt;' "$(cat "$WIKI_PAGES/Customers/Accounts/hidden x b b @alice.md")"
assert_not_contains '  no mention in the body'                '@alice' "$(sed 1,4d "$WIKI_PAGES/Customers/Accounts/hidden x b b @alice.md")"

section 'A page whose generated_by was removed by hand is left alone'
python3 - "$WIKI_PAGES/Customers/Accounts/Twin.md" <<'PY'
import sys, re; p = sys.argv[1]; s = open(p).read()
open(p, "w").write(re.sub(r"generated_by: .*\n", "", s).replace("# Twin", "# Twin, by hand"))
PY
OUT=$(render "$CARL")
assert_eq 'counted as detached'                               '1' "$(echo "$OUT" | field counts.detached)"
assert_contains '  its text is kept'                          'by hand' "$(cat "$WIKI_PAGES/Customers/Accounts/Twin.md")"
assert_eq '  and no replacement page is made'                 '0' "$(echo "$OUT" | field counts.created)"

section 'Refused definitions write nothing'
mk_tpl() { mkdir -p "$WIKI_PAGES/Customers/Bad"; printf -- "---\n%s\n---\n%s\n" "$2" "$3" > "$WIKI_PAGES/Customers/Bad/$1.md"; }
mk_tpl secret   "generator_source: Secret:$VAULT
generator_folder: Leak"   '{{name}}'
mk_tpl notlist  "generator_source: CRM:$README
generator_folder: X1"     '{{name}}'
mk_tpl dotdot   "generator_source: CRM:$ACCOUNTS
generator_folder: ../Secret"  '{{name}}'
mk_tpl dotdir   "generator_source: CRM:$ACCOUNTS
generator_folder: .git/x"     '{{name}}'
mk_tpl skills   "generator_source: CRM:$ACCOUNTS
generator_folder: skills/x"   '{{name}}'
mk_tpl broken   "generator_source: CRM:$ACCOUNTS
generator_folder: X2"     '{{#name}} unclosed'
mk_tpl plain    "title: Just a page"  'Hello'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
err() { render "$CARL" "$(id_of Customers "Bad/$1.md")" | field "${2:-error}"; }
assert_eq 'a source Space the owner cannot read'              'owner_no_source'  "$(err secret)"
assert_file_missing '  nothing from it lands'                 "$WIKI_PAGES/Customers/Leak"
assert_eq 'a source that is not a list'                       'source_not_list'  "$(err notlist)"
assert_eq 'a folder with ..'                                  'bad_folder'       "$(err dotdot)"
assert_eq 'a hidden folder'                                   'bad_folder'       "$(err dotdir)"
assert_eq 'the admin-only skills folder'                      'bad_folder'       "$(err skills)"
assert_eq 'a template that does not parse'                    'template_error'   "$(err broken)"
assert_eq '  with the line in the file (body starts on 5)'     '5'                "$(err broken line)"
assert_file_missing '  and nothing written'                   "$WIKI_PAGES/Customers/X2"
assert_eq 'a page that is not a template'                     'not_template'     "$(err plain)"
assert_eq 'an id that is not a page'                          'template_missing' "$(render "$CARL" 99999 | field error)"
# An admin may read every Space, so the same Secret template renders for them — the check
# is about the owner's access, not a blanket refusal of cross-Space sources.
assert_eq 'control: an admin may use the Secret list'         'ok'  "$(render "$ADMIN" "$(id_of Customers Bad/secret.md)" | field status)"

section 'Read-only target Space'
set_items '[{"id":1,"col_2":"Acme Ltd","col_3":"frozen@acme.test","col_4":"","col_5":[]}]'
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Customers&readonly=1' > /dev/null
OUT=$(render "$CARL")
assert_contains 'refused by the read-only guard'              'readonly_space' "$OUT"
# The cron runner (step 3) calls the engine without passing that guard, so the engine
# checks too.
OUT=$(cd "$WIKI_APP" && php -r 'require "config.php"; require "page_generators.php";
    echo json_encode(wiki_gen_render("Customers", (int)$argv[1], ["uid" => 3, "name" => "Carl"]));' "$TPL")
assert_eq 'the engine itself refuses too'                     'readonly' "$(echo "$OUT" | field status)"
assert_not_contains '  and wrote nothing'                     'frozen' "$(cat "$WIKI_PAGES/Customers/Accounts/Acme Ltd.md")"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Customers&readonly=0' > /dev/null
assert_eq 'control: allowed again once thawed'                'ok' "$(render "$CARL" | field status)"

section 'Workflows do not fire on generator writes'
wf=$(post_as "$ADMIN" 'api.php?action=admin_save_workflow' "workflow=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("{\"name\":\"Watch\",\"enabled\":true,\"space\":\"Customers\",\"debounce\":0,\"trigger\":{\"types\":[\"page_created\",\"page_updated\"]},\"actions\":[{\"type\":\"tag\",\"add\":[\"seen\"]}]}"))')")
WF=$(echo "$wf" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("workflow",{}).get("id",""))')
queued() { python3 -c 'import json,sys
try: runs = json.load(open(sys.argv[1]))["runs"]
except Exception: runs = []
print(sum(1 for r in runs if r.get("workflow_id") == sys.argv[2] and r["state"] == "queued"))' "$WIKI_SYS/workflow_queue.json" "$WF"; }
set_items '[{"id":1,"col_2":"Acme Ltd","col_3":"new@acme.test","col_4":"","col_5":[]},
            {"id":8,"col_2":"Delta","col_3":"","col_4":"","col_5":[]},
            {"id":9,"col_2":"Echo","col_3":"","col_4":"","col_5":[]}]'
OUT=$(render "$CARL")
assert_contains 'the render wrote pages'                      '"created":2' "$OUT"
assert_eq '  and no workflow run was queued'                  '0' "$(queued)"
# Positive control: an ordinary save in the same Space does queue one.
curl -s -b "$CARL" -c "$CARL" -X POST --data-binary 'Edited' \
    "$WIKI_URL/api.php?action=save&space=Customers&file=Accounts/Taken.md" > /dev/null
assert_eq 'control: a person saving a page does'              '1' "$(queued)"

section 'The lock: a generated page is not edited directly'
# Targets, one per destructive action: Acme Ltd.md (rewrites), Delta.md (delete), Echo.md
# (move). Ordinary pages for the controls.
fixture_page 'Customers/Notes.md'   'Plain notes'
fixture_page 'Customers/Scratch.md' 'Delete me'
fixture_page 'Customers/Mover.md'   'Move me'
fixture_page 'Customers/Linker.md'  'See [[Notes]]'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
GEN="$WIKI_PAGES/Customers/Accounts/Acme Ltd.md"; DELTA="$WIKI_PAGES/Customers/Accounts/Delta.md"
BEFORE=$(cat "$GEN")
enc() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
save() { curl -s -b "$1" -c "$1" -X POST --data-binary "$3" "$WIKI_URL/api.php?action=save&space=Customers&file=$(enc "$2")"; }
mcp_call() { curl -s -X POST -H 'Authorization: Bearer wk_ai_testbot' -H 'Content-Type: application/json' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" "$WIKI_URL/mcp.php?space=Customers"; }

G=$(get_as "$CARL" "api.php?action=get&space=Customers&file=$(enc 'Accounts/Acme Ltd.md')")
assert_contains '`get` says the page is generated'      '"state":"generated"' "$G"
assert_contains '  and by which template'                '"template_path":"Templates\/Account.md"' "$G"
assert_eq 'an ordinary page says nothing'                'None' "$(get_as "$CARL" 'api.php?action=get&space=Customers&file=Notes.md' | python3 -c 'import json,sys;print(json.load(sys.stdin).get("generated"))')"

assert_contains 'save is refused'                        '"generated"' "$(save "$CARL" 'Accounts/Acme Ltd.md' 'Hand edit')"
assert_contains 'control: saving an ordinary page works' '"success":true' "$(save "$CARL" Notes.md 'Plain notes, edited')"
assert_contains 'appending a chat message is refused'    '"generated"' \
    "$(post_as "$CARL" 'api.php?action=save_message_page&space=Customers' 'path=Accounts/Acme Ltd&mode=append&text=From chat')"
assert_contains 'control: appending to an ordinary page works' '"success":true' \
    "$(post_as "$CARL" 'api.php?action=save_message_page&space=Customers' 'path=Notes&mode=append&text=From chat')"
assert_contains 'delete is refused'                      '"generated"' "$(post_as "$CARL" 'api.php?action=delete&space=Customers' 'path=Accounts/Delta.md')"
assert_file_exists '  the page is still there'           "$DELTA"
assert_contains 'control: deleting an ordinary page works' '"success":true' "$(post_as "$CARL" 'api.php?action=delete&space=Customers' 'path=Scratch.md')"
assert_contains 'move is refused'                        '"generated"' \
    "$(post_as "$CARL" 'api.php?action=move&space=Customers' 'old_path=Accounts/Echo.md&new_path=Accounts/Moved.md')"
assert_file_missing '  nothing moved'                    "$WIKI_PAGES/Customers/Accounts/Moved.md"
assert_contains 'control: moving an ordinary page works' '"success":true' \
    "$(post_as "$CARL" 'api.php?action=move&space=Customers' 'old_path=Mover.md&new_path=Moved.md')"
HASH=$(git -C "$WIKI_PAGES/Customers" log -1 --format=%H -- 'Accounts/Acme Ltd.md')
assert_contains 'git restore is refused'                 '"generated"' \
    "$(post_as "$CARL" 'api.php?action=git_restore&space=Customers' "file=Accounts/Acme Ltd.md&hash=$HASH")"
assert_eq '  the page is exactly as the generator left it' "$BEFORE" "$(cat "$GEN")"

assert_contains 'an AI user cannot rewrite it'           'generated from a template' "$(mcp_call wiki_write_page '{"path":"Accounts/Acme Ltd.md","content":"# AI"}')"
assert_contains '  nor rename it'                        'generated from a template' "$(mcp_call wiki_rename_page '{"path":"Accounts/Acme Ltd.md","new_path":"Accounts/AI.md"}')"
assert_contains '  nor append a mention to it'           'generated from a template' "$(mcp_call wiki_mention_users '{"target":"Accounts/Acme Ltd.md","users":["Carl"],"message":"look"}')"
assert_eq '  the page is untouched'                      "$BEFORE" "$(cat "$GEN")"
assert_contains 'control: the AI user writes an ordinary page' 'Page updated' "$(mcp_call wiki_write_page '{"path":"Notes.md","content":"# AI notes"}')"

# Front matter is the page's own — the Metadata panel's action is not refused.
r=$(post_as "$CARL" 'api.php?action=update_tags&space=Customers' "id=$(id_of Customers 'Accounts/Acme Ltd.md')&tags=%5B%22vip%22%5D")
assert_contains 'tagging a generated page works'         '"success":true' "$r"

# Rename retargeting rewrites links in ordinary pages and leaves generated ones to their template.
post_as "$CARL" 'api.php?action=move&space=Customers' 'old_path=Notes.md&new_path=NotesNew.md' > /dev/null
python3 - "$GEN" <<'PY'
import sys; p = sys.argv[1]; s = open(p).read(); open(p, "w").write(s + "\nSee [[Notes]]\n")
PY
GEN_WITH_LINK=$(cat "$GEN")
post_as "$CARL" 'api.php?action=retarget_wikilinks&space=Customers' 'old_path=Notes.md&new_path=NotesNew.md&apply=1' > /dev/null
assert_contains 'control: an ordinary page has its link retargeted' '[[NotesNew]]' "$(cat "$WIKI_PAGES/Customers/Linker.md")"
assert_eq 'a generated page is skipped'                  "$GEN_WITH_LINK" "$(cat "$GEN")"

section 'Copy and Detach'
r=$(post_as "$CARL" 'api.php?action=copy_page&space=Customers' 'source_path=Accounts/Acme Ltd.md&new_path=Accounts/Acme copy.md')
assert_contains 'a generated page can be copied'         '"success":true' "$r"
assert_not_contains '  the copy is an ordinary page'     'generated_by' "$(cat "$WIKI_PAGES/Customers/Accounts/Acme copy.md")"
assert_contains '  the original is still generated'      'generated_by' "$(cat "$GEN")"

assert_not_contains 'a reader cannot detach'             '"success":true' \
    "$(post_as "$RITA" 'api.php?action=generator_detach&space=Customers' 'path=Accounts/Delta.md')"
assert_contains 'an editor detaches it'                  '"success":true' \
    "$(post_as "$CARL" 'api.php?action=generator_detach&space=Customers' 'path=Accounts/Delta.md')"
assert_eq '  it records where it came from'              "$TPL" "$(fm Accounts/Delta.md detached_from)"
assert_eq '  and is no longer generated'                 '' "$(fm Accounts/Delta.md generated_by)"
assert_contains '  so it can be edited'                  '"success":true' "$(save "$CARL" Accounts/Delta.md '# Delta, mine now')"
OUT=$(render "$CARL")
assert_eq 'the next render leaves it alone'              '0' "$(echo "$OUT" | field counts.created)"
assert_contains '  and keeps the edit'                   'mine now' "$(cat "$DELTA")"
assert_contains 'detaching an ordinary page is refused'  'not_generated' \
    "$(post_as "$CARL" 'api.php?action=generator_detach&space=Customers' 'path=Linker.md')"
# A detached page moved elsewhere is still somebody's own: its row stays out.
post_as "$CARL" 'api.php?action=move&space=Customers' 'old_path=Accounts/Delta.md&new_path=Mine/Delta.md' > /dev/null
OUT=$(render "$CARL")
assert_file_exists 'a detached page that was moved'       "$WIKI_PAGES/Customers/Mine/Delta.md"
assert_eq '  still keeps its row out of the generator' '1' "$(echo "$OUT" | field counts.detached)"
assert_file_missing '  no new page in its place'          "$DELTA"
# Deleted, there is nobody's version left: the row is the generator's again.
assert_contains 'the detached page is deleted'           '"success":true' \
    "$(post_as "$CARL" 'api.php?action=delete&space=Customers' 'path=Mine/Delta.md')"
OUT=$(render "$CARL")
assert_eq '  and the next render makes its page afresh' '1' "$(echo "$OUT" | field counts.created)"
assert_eq '  a generated one'                         "$TPL" "$(fm Accounts/Delta.md generated_by)"
assert_eq '  and no longer counts it as detached'     '0' "$(echo "$OUT" | field counts.detached)"

section 'For the UI: status, preview, sources, create'
G=$(get_as "$CARL" "api.php?action=get&space=Customers&file=Templates/Account.md")
gj() { python3 -c 'import json,sys
d = json.load(sys.stdin)
for k in sys.argv[1].split("."): d = d.get(k) if isinstance(d, dict) else None
print(json.dumps(d) if isinstance(d, (dict, list)) else d)' "$1"; }
assert_eq 'a template says it is one'                    'ok' "$(echo "$G" | gj generator.status)"
assert_eq '  names its source list, to someone who may read it' 'Accounts.list' "$(echo "$G" | gj generator.source.path)"
assert_eq '  and where it writes'                        'Accounts' "$(echo "$G" | gj generator.folder)"
G=$(get_as "$EVE" "api.php?action=get&space=Customers&file=Templates/Account.md")
assert_eq "to Eve, who cannot read CRM, the list's path is withheld" 'None' "$(echo "$G" | gj generator.source.path)"
assert_eq '  and marked unreadable'                      'False' "$(echo "$G" | gj generator.source.readable)"
B=$(get_as "$CARL" "api.php?action=get&space=Customers&file=Bad/broken.md")
assert_eq 'a template that failed says why'                'template_error' "$(echo "$B" | gj generator.error)"
assert_eq '  and is not "rendering": it is retried, not pending' 'False' "$(echo "$B" | gj generator.pending)"
assert_eq 'an ordinary page has no generator'            'None' "$(get_as "$CARL" 'api.php?action=get&space=Customers&file=Linker.md' | gj generator)"

P=$(get_as "$CARL" "api.php?action=generator_preview&space=Customers&id=$TPL&row=1")
assert_eq 'a preview renders one row'                    'Acme Ltd' "$(echo "$P" | gj name)"
assert_contains '  with its body'                        '# Acme Ltd' "$(echo "$P" | gj body)"
assert_contains '  and offers every row'                 '"title": "Acme Ltd"' "$(echo "$P" | gj rows)"
MB=$(cat "$GEN")
assert_eq '  and writes nothing'                         "$MB" "$(cat "$GEN")"
assert_eq "Eve cannot preview a list she cannot read"    'no_source_access' "$(get_as "$EVE" "api.php?action=generator_preview&space=Customers&id=$TPL" | gj error)"
assert_not_contains 'a reader cannot preview'            'Acme' "$(get_as "$RITA" "api.php?action=generator_preview&space=Customers&id=$TPL")"

S=$(get_as "$CARL" 'api.php?action=generator_sources')
assert_contains 'the sources include the CRM list'       '"path":"Accounts.list"' "$S"
assert_contains '  with its fields as template keys'     '"key":"account_manager"' "$S"
assert_not_contains '  never its rows'                   'new@acme' "$S"
assert_not_contains "Eve's sources leave out CRM"        '"space":"CRM"' "$(get_as "$EVE" 'api.php?action=generator_sources')"
assert_contains '  control: but list Spaces she can read' '"success":true' "$(get_as "$EVE" 'api.php?action=generator_sources')"

C=$(post_as "$EVE" 'api.php?action=generator_create&space=Customers' "source=CRM:$ACCOUNTS&path=Templates/Eve&folder=EveOut")
assert_eq 'Eve cannot create a template for a CRM list'  'source_missing' "$(echo "$C" | gj error)"
assert_file_missing '  nothing written'                  "$WIKI_PAGES/Customers/Templates/Eve.md"
C=$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$ACCOUNTS&path=Templates/Cards&folder=Cards&filename_key=e_mail")
assert_contains 'Carl creates one'                       '"success":true' "$C"
NEWT="$WIKI_PAGES/Customers/Templates/Cards.md"
assert_contains '  its source'                           "generator_source: CRM:$ACCOUNTS" "$(cat "$NEWT")"
assert_contains '  pages named after the chosen field'   'generator_filename: "{{e_mail}}"' "$(cat "$NEWT")"
assert_contains '  a starter body listing the fields'    '| **E-mail** | {{e_mail}} |' "$(cat "$NEWT")"
assert_contains '  a lookup in a section of its own, repeated per item' '## Account manager' "$(cat "$NEWT")"
assert_contains '    its items repeat'                   '{{#account_manager}}' "$(cat "$NEWT")"
assert_file_exists '  rendered once the response had gone' "$WIKI_PAGES/Customers/Cards/new@acme.test.md"
assert_eq '  owned by its creator'                       'Carl' \
    "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["generators"]["Customers:"+sys.argv[2]]["owner"]["name"])' "$WIKI_SYS/generators.json" "$(echo "$C" | gj id)")"
assert_eq 'creating over an existing page is refused'    'exists' "$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$ACCOUNTS&path=Templates/Cards&folder=X" | gj error)"

section 'A generator template is not a New Page template'
mkdir -p "$WIKI_PAGES/Customers/templates"
printf -- '# Meeting\n' > "$WIKI_PAGES/Customers/templates/meeting-notes.md"
printf -- "---\ngenerator_source: CRM:%s\ngenerator_folder: FromTemplates\n---\n# {{name}}\n" "$ACCOUNTS" > "$WIKI_PAGES/Customers/templates/Accounts.md"
T=$(get_as "$CARL" 'api.php?action=list_md_templates&space=Customers')
assert_contains 'control: an ordinary template is offered'  '"meeting-notes"' "$T"
assert_not_contains 'a generator template in templates/ is not' '"Accounts"' "$T"

section 'The file tree marks a template'
L=$(get_as "$CARL" 'api.php?action=list&space=Customers')
flag() { python3 -c 'import json,sys
def walk(items):
    for i in items:
        if i.get("path") == sys.argv[1]: return i
        r = walk(i.get("children") or [])
        if r: return r
i = walk(json.load(sys.stdin)["data"]) or {}
print(i.get("generator", False))' "$1"; }
assert_eq 'a template carries generator: true'           'True'  "$(echo "$L" | flag Templates/Account.md)"
assert_eq '  a page it generated does not'               'False' "$(echo "$L" | flag 'Accounts/Acme Ltd.md')"
assert_eq '  nor an ordinary page'                       'False' "$(echo "$L" | flag Linker.md)"

section 'Admin → Content → Generators'
A=$(get_as "$CARL" 'api.php?action=admin_get_generators')
assert_not_contains 'an editor cannot list them'         '"success":true' "$A"
A=$(get_as "$ADMIN" 'api.php?action=admin_get_generators')
assert_contains 'an administrator can'                   '"success":true' "$A"
row() { python3 -c 'import json,sys
d = json.load(sys.stdin)["data"]
r = next((g for g in d if g["template_path"] == sys.argv[1]), None)
if r is None: print("missing"); sys.exit()
for k in sys.argv[2].split("."): r = r.get(k) if isinstance(r, dict) else None
print(r)' "$1" "$2"; }
assert_eq '  the Account template is listed'         'Customers' "$(echo "$A" | row Templates/Account.md space)"
assert_eq '  with its source list'                   'Accounts.list' "$(echo "$A" | row Templates/Account.md source.path)"
assert_eq '  its folder'                             'Accounts' "$(echo "$A" | row Templates/Account.md folder)"
assert_eq '  its owner'                              'Carl' "$(echo "$A" | row Templates/Account.md owner)"
assert_eq '  and its status'                         'ok' "$(echo "$A" | row Templates/Account.md status)"
assert_eq "  a template that stopped says why"       'template_error' "$(echo "$A" | row Bad/broken.md error)"
# A template that arrived without a save is listed at once, not after the hourly scan.
mkdir -p "$WIKI_PAGES/Customers/Dropped"
printf -- "---\ngenerator_source: CRM:%s\ngenerator_folder: Dropped/Out\n---\n# {{name}}\n" "$ACCOUNTS" > "$WIKI_PAGES/Customers/Dropped/T.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
touch "$WIKI_SYS/generators.discover"       # the hourly scan has just run
A=$(get_as "$ADMIN" 'api.php?action=admin_get_generators')
assert_eq 'a dropped-in template is listed at once'  'None' "$(echo "$A" | row Dropped/T.md owner)"
assert_file_missing '  and listing it rendered nothing'  "$WIKI_PAGES/Customers/Dropped/Out"

section 'A list with groups of fields'
cat > "$WIKI_PAGES/CRM/Members.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"},
            {"id":"col_3","name":"Address","type":"object","summary":["sf_s","sf_c"],
             "fields":[{"id":"sf_s","name":"Street","type":"text_single"},{"id":"sf_c","name":"City","type":"text_single"}]},
            {"id":"col_4","name":"Debit cards","type":"object","multiple":true,"summary":["sf_n"],
             "fields":[{"id":"sf_n","name":"Number","type":"text_single"},{"id":"sf_e","name":"Expires","type":"date"},
                       {"id":"sf_i","name":"Id","type":"text_single"}]}],
 "items":[{"id":1,"col_2":"Ann","col_3":{"sf_s":"Main St 1","sf_c":"Oslo"},
           "col_4":[{"id":7,"sf_n":"1111","sf_e":"2027-01-31","sf_i":"A"},{"id":9,"sf_n":"<b>2222</b>","sf_e":"2028-05-31"}]},
          {"id":2,"col_2":"Bo","col_3":null,"col_4":[]}],
 "nextItemId":3,"nextEntryId":10}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
MEMBERS=$(id_of CRM Members.list)
mkdir -p "$WIKI_PAGES/Customers/Templates"
cat > "$WIKI_PAGES/Customers/Templates/Member.md" <<EOF
---
generator_source: CRM:$MEMBERS
generator_folder: Members
---
# {{name}}

Lives at {{address}}; city: {{address.city}}.
{{^address}}
No address on file.
{{/address}}
Cards: {{debit_cards}}
{{#debit_cards}}
- {{_index}}. {{number}} (entry {{_id}}, expires {{expires}}, card id {{id_2}}){{#_first}} FIRST{{/_first}}{{#_last}} LAST{{/_last}}
{{/debit_cards}}
{{^debit_cards}}
No cards.
{{/debit_cards}}
EOF
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
MTPL=$(id_of Customers Templates/Member.md)
OUT=$(render "$CARL" "$MTPL")
assert_eq 'the template renders'                           'ok' "$(echo "$OUT" | field status)"
assert_eq '  with no unknown fields'                        '[]' "$(echo "$OUT" | field warnings)"
ANN=$(cat "$WIKI_PAGES/Customers/Members/Ann.md"); BO=$(cat "$WIKI_PAGES/Customers/Members/Bo.md")
assert_contains '{{address}} is the summary'                'Lives at Main St 1, Oslo;' "$ANN"
assert_contains '{{address.city}} reaches inside'           'city: Oslo.' "$ANN"
assert_not_contains '{{^address}} is false when set'        'No address on file' "$ANN"
assert_contains '  and true when not'                       'No address on file' "$BO"
assert_contains '{{debit_cards}} joins the summaries'       'Cards: 1111; &lt;b&gt;2222&lt;/b&gt;' "$ANN"
assert_contains 'a loop over the entries, with _index and _id' '- 1. 1111 (entry 7, expires 2027-01-31, card id A) FIRST' "$ANN"
assert_contains '  _last on the last one, values escaped'  '- 2. &lt;b&gt;2222&lt;/b&gt; (entry 9, expires 2028-05-31, card id ) LAST' "$ANN"
assert_not_contains '  _first only on the first'            '2222</b> (entry 9, expires 2028-05-31, card id ) FIRST' "$ANN"
assert_contains 'no entries: the inverted section'          'No cards.' "$BO"
assert_not_contains '  and no loop line'                    '- 1.' "$BO"
P=$(get_as "$CARL" "api.php?action=generator_preview&space=Customers&id=$MTPL&row=1")
assert_contains 'the preview renders the same'              'Lives at Main St 1, Oslo' "$(echo "$P" | field body)"
S=$(get_as "$CARL" 'api.php?action=generator_sources')
assert_contains 'the sources name a group by its key'       '"key":"debit_cards","type":"object"' "$S"

section 'An HTML template, previewed'
cat > "$WIKI_PAGES/CRM/Links.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"},{"id":"col_3","name":"Site","type":"text_single"}],
 "items":[{"id":1,"col_2":"<b>Ann</b> @alice","col_3":"javascript:alert(1)"},{"id":2,"col_2":"Bo","col_3":"https://bo.example"}],"nextItemId":3}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
LINKS=$(id_of CRM Links.list)
printf -- '---\nformat: html\ngenerator_source: CRM:%s\ngenerator_folder: Links\n---\n<p title="{{name}}">{{name}}</p><a href="{{site}}">site</a>\n' "$LINKS" > "$WIKI_PAGES/Customers/Templates/Link.md"
printf -- '---\nformat: html\ngenerator_source: CRM:%s\ngenerator_folder: Bad\n---\n<p onclick="{{name}}">x</p>\n' "$LINKS" > "$WIKI_PAGES/Customers/Templates/BadHtml.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
P=$(get_as "$CARL" "api.php?action=generator_preview&space=Customers&id=$(id_of Customers Templates/Link.md)&row=1")
assert_eq 'the preview says it is HTML'                     'html' "$(echo "$P" | field format)"
assert_contains '  text escaped, mentions neutralised'      '<p title="&lt;b&gt;Ann&lt;/b&gt; &#64;alice">&lt;b&gt;Ann&lt;/b&gt; &#64;alice</p>' "$(echo "$P" | field body)"
assert_contains '  a javascript: link blocked'              '<a href="#blocked">' "$(echo "$P" | field body)"
assert_contains '  and reported'                            'url_blocked' "$(echo "$P" | field warnings)"
P=$(get_as "$CARL" "api.php?action=generator_preview&space=Customers&id=$(id_of Customers Templates/Link.md)&row=2")
assert_contains 'control: an https link passes'             '<a href="https://bo.example">' "$(echo "$P" | field body)"
P=$(get_as "$CARL" "api.php?action=generator_preview&space=Customers&id=$(id_of Customers Templates/BadHtml.md)")
assert_eq 'a value in an event handler refuses the template' 'template_error' "$(echo "$P" | field error)"
assert_contains '  naming why'                              'html_context: event handler' "$(echo "$P" | field detail)"

section 'An HTML template writes HTML pages'
LTPL=$(id_of Customers Templates/Link.md)
OUT=$(render "$CARL" "$LTPL")
assert_eq 'it renders'                                      'ok' "$(echo "$OUT" | field status)"
assert_contains 'a blocked link is reported once, with its rows' '"warning":"url_blocked","detail":"site (1)"' "$OUT"
ANNP=$(ls "$WIKI_PAGES/Customers/Links/" | grep -v Bo); ANN="$WIKI_PAGES/Customers/Links/$ANNP"
assert_eq 'its pages are format: html'                      'html' "$(fm "Links/$ANNP" format)"
assert_contains '  their values escaped for where they land' '<p title="&lt;b&gt;Ann&lt;/b&gt; &#64;alice">' "$(cat "$ANN")"
assert_contains '  the link blocked'                        '<a href="#blocked">' "$(cat "$ANN")"
assert_contains 'control: the other row keeps its link'     '<a href="https://bo.example">' "$(cat "$WIKI_PAGES/Customers/Links/Bo.md")"
# Back to Markdown: the key comes off the pages.
printf -- '---\ngenerator_source: CRM:%s\ngenerator_folder: Links\n---\n# {{name}}\n' "$LINKS" > "$WIKI_PAGES/Customers/Templates/Link.md"
render "$CARL" "$LTPL" > /dev/null
assert_eq 'a template back to Markdown takes format off its pages' '' "$(fm Links/Bo.md format)"
assert_contains '  and writes Markdown'                     '# Bo' "$(cat "$WIKI_PAGES/Customers/Links/Bo.md")"
printf -- '---\nformat: html\ngenerator_source: CRM:%s\ngenerator_folder: Links\n---\n<h2>{{name}}</h2>\n' "$LINKS" > "$WIKI_PAGES/Customers/Templates/Link.md"
render "$CARL" "$LTPL" > /dev/null
post_as "$CARL" 'api.php?action=generator_detach&space=Customers' 'path=Links/Bo.md' > /dev/null
assert_eq 'a detached page stays HTML'                      'html' "$(fm Links/Bo.md format)"
assert_eq '  and is detached'                               "$LTPL" "$(fm Links/Bo.md detached_from)"

section 'New → Generated Pages, as HTML'
# A hostile field name, to show labels are text in the starter too.
python3 - "$WIKI_PAGES/CRM/Members.list" <<'PY'
import json, sys; p = sys.argv[1]; d = json.load(open(p))
d["columns"].append({"id": "col_9", "name": "Note <i>{{x}}</i>", "type": "text_single"})
d["items"][0]["col_9"] = "fine"
json.dump(d, open(p, "w"))
PY
C=$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$MEMBERS&path=templates/Members&folder=MembersHtml&format=html")
assert_contains 'an HTML template is created'               '"success":true' "$C"
T="$WIKI_PAGES/Customers/templates/Members.md"
assert_contains '  marked format: html'                     'format: html' "$(cat "$T")"
assert_contains '  starting with its own copy of the styles' '<style>' "$(head -12 "$T")"
assert_contains '  the wk-* rules, written plainly'         '.wk-card   { border' "$(cat "$T")"
assert_contains '  a card of fields'                        '<div class="wk-card">' "$(cat "$T")"
assert_contains '  a single group as a nested block'        '{{#address}}' "$(cat "$T")"
assert_contains '  several entries as a grid of cards'      '<div class="wk-grid">' "$(cat "$T")"
assert_contains '  a field name escaped, braces included'  '<dt>Note &lt;i&gt;&#123;&#123;x&#125;&#125;&lt;/i&gt;</dt>' "$(cat "$T")"
ANNH=$(cat "$WIKI_PAGES/Customers/MembersHtml/Ann.md"); BOH=$(cat "$WIKI_PAGES/Customers/MembersHtml/Bo.md")
assert_contains 'its pages render: the title, with its edit button' '<h1>Ann <button type="button" class="wk-edit"' "$ANNH"
assert_contains '  a group field inside its block'          '<dt>City</dt><dd>Oslo</dd>' "$ANNH"
assert_contains '  one card per entry'                      '<dt>Number</dt><dd>1111</dd>' "$ANNH"
assert_contains '  values escaped'                          '<dd>&lt;b&gt;2222&lt;/b&gt;</dd>' "$ANNH"
assert_contains '  and the empty case'                      '<p class="wk-muted">—</p>' "$BOH"
assert_eq '  and they are HTML pages'                       'html' "$(fm MembersHtml/Ann.md format)"
assert_contains '  each carrying the styles'                '.wk-card   { border' "$ANNH"
C=$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$MEMBERS&path=templates/MembersMd&folder=MembersMd")
assert_not_contains 'without a format, a Markdown template, as before' 'format: html' "$(cat "$WIKI_PAGES/Customers/templates/MembersMd.md")"

section 'Search reads an HTML page as text'
# Ann's HTML page holds <dt>City</dt><dd>Oslo</dd> and "Note: fine" under a <dt>.
printf -- '# Plain md\n\n<div class="wk-card">markdown with inline html, findable as before</div>\n' > "$WIKI_PAGES/Customers/PlainMd.md"
for engine in basic sqlite; do
    sed -i "s#^define('SEARCH_ENGINE'.*#define('SEARCH_ENGINE', '$engine');#" "$WIKI_APP/config.php"
    rm -f "$WIKI_SYS/search.sqlite"
    get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
    q() { get_as "$CARL" "api.php?action=search&space=Customers&query=$1"; }
    assert_not_contains "[$engine] markup is not text: wk-card finds no HTML page" 'MembersHtml' "$(q wk-card)"
    assert_contains     "[$engine] control: a Markdown page's inline html is as before" 'PlainMd.md' "$(q wk-card)"
    hit=$(q Oslo)
    assert_contains     "[$engine] a value in an HTML page is found"   'MembersHtml\/Ann.md' "$hit"
    assert_not_contains "[$engine]   its snippet has no tags"          '&lt;dd' "$hit"
    assert_contains     "[$engine]   and its title is its heading"     '"header":"# Ann"' "$hit"
    assert_contains     "[$engine] an entity is searched as the character" 'MembersHtml\/Ann.md' "$(q '%3Cb%3E2222')"
    assert_not_contains "[$engine] a page's CSS is not text"   'MembersHtml' "$(q border-radius)"
done
sed -i "s#^define('SEARCH_ENGINE'.*#define('SEARCH_ENGINE', 'basic');#" "$WIKI_APP/config.php"

section 'State'
assert_eq 'the owner is recorded'                             'Carl' \
    "$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["generators"]["Customers:"+sys.argv[2]]["owner"]["name"])' "$WIKI_SYS/generators.json" "$TPL")"
assert_eq 'the source revision is recorded (for drift checks)' '40' \
    "$(python3 -c 'import json,sys;print(len(json.load(open(sys.argv[1]))["generators"]["Customers:"+sys.argv[2]]["source_rev"]))' "$WIKI_SYS/generators.json" "$TPL")"
# A lost state file is rebuilt from the pages, not duplicated.
rm -f "$WIKI_SYS/generators.json"
OUT=$(render "$CARL")
assert_eq 'without state: nothing created again'              '0' "$(echo "$OUT" | field counts.created)"
assert_file_missing '  no "(1)" duplicate'                    "$WIKI_PAGES/Customers/Accounts/Acme Ltd (1).md"

section 'Settings: where the pages go, what they are called'
CARDS_T="$WIKI_PAGES/Customers/Templates/Cards.md"
settings() { post_as "$1" 'api.php?action=generator_settings&space=Customers' "path=Templates/Cards.md&$2"; }
listing() { (cd "$WIKI_PAGES/Customers/$1" 2>/dev/null && ls *.md 2>/dev/null | sort | tr '\n' '|'); }
BEFORE=$(listing Cards)
FIRST=$(cd "$WIKI_PAGES/Customers/Cards" && ls *.md | sort | head -1)
FIRST_ID=$(id_of Customers "Cards/$FIRST")
BODY_BEFORE=$(python3 -c 'import sys; print(open(sys.argv[1]).read().split("---\n", 2)[2], end="")' "$CARDS_T")
assert_contains 'the template has pages to move (control)'  '@' "$BEFORE"

R=$(settings "$RITA" 'folder=Elsewhere')
assert_not_contains 'a reader may not change them'          '"success":true' "$R"
R=$(settings "$CARL" 'folder=templates/Inside')
assert_eq 'a reserved folder is refused'                   'bad_folder' "$(echo "$R" | gj error)"
R=$(settings "$CARL" 'folder=../Out')
assert_eq 'a folder outside the Space is refused'          'bad_folder' "$(echo "$R" | gj error)"
R=$(settings "$CARL" 'filename={{#oops}}')
assert_eq 'a page name that is not a template is refused'  'filename_error' "$(echo "$R" | gj error)"
assert_contains '  and the template is as it was'          'generator_folder: Cards' "$(cat "$CARDS_T")"
R=$(post_as "$CARL" 'api.php?action=generator_settings&space=Customers' 'path=Main.md&folder=X')
assert_eq 'a page that is not a template is refused'       'not_template' "$(echo "$R" | gj error)"

R=$(settings "$CARL" 'folder=Team/Cards')
assert_contains 'Carl moves the pages to another folder'    '"success":true' "$R"
sleep 1
assert_contains '  the template says so'                   'generator_folder: Team/Cards' "$(cat "$CARDS_T")"
assert_eq '  its body untouched'                           "$BODY_BEFORE" "$(python3 -c 'import sys; print(open(sys.argv[1]).read().split("---\n", 2)[2], end="")' "$CARDS_T")"
assert_eq '  every page moved'                             "$BEFORE" "$(listing Team/Cards)"
assert_eq '  none left behind'                             '' "$(listing Cards)"
assert_eq '  each keeps its page id'                       "$FIRST_ID" "$(id_of Customers "Team/Cards/$FIRST")"

R=$(settings "$CARL" 'filename=')
assert_contains 'naming after the Title again'             '"success":true' "$R"
sleep 1
assert_not_contains '  drops the key'                      'generator_filename' "$(cat "$CARDS_T")"
assert_contains '  and the pages take their titles'        'Acme' "$(listing Team/Cards)"
assert_not_contains '  not the e-mails'                    '@' "$(listing Team/Cards)"
R=$(settings "$CARL" 'filename={{e_mail}}&folder=Team/Cards')
assert_contains 'a field as the name'                      'generator_filename: "{{e_mail}}"' "$(cat "$CARDS_T")"
R=$(settings "$CARL" 'filename={{e_mail}}&folder=Team/Cards')
assert_eq 'saving the same again changes nothing'          'True' "$(echo "$R" | gj unchanged)"

section 'Lookups as items, like a group'
# A lookup reaches the items it points at, each with its own list's fields — single and several,
# a deleted item, and {{x}} on its own still the text the list shows.
cat > "$WIKI_PAGES/CRM/People.list" <<'JSON'
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Name","type":"text_single","role":"title"},
            {"id":"col_3","name":"City","type":"text_single"},
            {"id":"col_4","name":"Age","type":"number","decimals":0}],
 "items":[{"id":1,"col_1":1,"col_2":"Ann","col_3":"Oslo","col_4":41},
          {"id":2,"col_1":2,"col_2":"Bo","col_3":"Bergen","col_4":33}],"nextItemId":3}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
PEOPLE=$(id_of CRM People.list)
cat > "$WIKI_PAGES/CRM/Deals.list" <<JSON
{"columns":[{"id":"col_1","name":"ItemId","type":"autoincrement"},
            {"id":"col_2","name":"Deal","type":"text_single","role":"title"},
            {"id":"col_3","name":"Contacts","type":"lookup","lookup":{"list":$PEOPLE,"multiple":true}},
            {"id":"col_4","name":"Owner","type":"lookup","lookup":{"list":$PEOPLE,"field":"col_3","multiple":false}},
            {"id":"col_5","name":"Contacts: City","type":"lookup_field","source":"col_3","field":"col_3"}],
 "items":[{"id":1,"col_2":"Big","col_3":[1,2,9],"col_4":[2]},
          {"id":2,"col_2":"Small","col_3":[],"col_4":[]}],"nextItemId":3}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=CRM' > /dev/null
DEALS=$(id_of CRM Deals.list)
cat > "$WIKI_PAGES/Customers/Templates/Deal.md" <<TPL
---
generator_source: CRM:$DEALS
generator_folder: Deals
---
TEXT={{contacts}}|
CARDS={{#contacts}}[{{_index}}:{{_title}}/{{city}}/{{age}}/{{_text}}{{#_missing}}!{{/_missing}}]{{/contacts}}{{^contacts}}none{{/contacts}}|
OWNER={{owner}}/{{owner.name}}/{{#owner}}{{_id}}{{/owner}}{{^owner}}nobody{{/owner}}|
LINKS={{#contacts}}<{{_edit}}>{{/contacts}}|
TPL
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
OUT=$(render "$CARL" "$(id_of Customers Templates/Deal.md)")
assert_eq 'a template with lookups renders'                 'ok' "$(echo "$OUT" | field status)"
BIG=$(cat "$WIKI_PAGES/Customers/Deals/Big.md")
assert_contains '{{contacts}} alone is the text the list shows' 'TEXT=Ann; Bo; (deleted &#35;9)|' "$BIG"
assert_contains '  a section repeats per item, with its own fields' 'CARDS=[1:Ann/Oslo/41/Ann][2:Bo/Bergen/33/Bo]' "$BIG"
assert_contains '  a deleted item: its text, marked, nothing of the row'   '[3:///(deleted &#35;9)!]|' "$BIG"
assert_contains 'a single lookup is the item: its display field, a dotted field, its id' 'OWNER=Bergen/Bo/2|' "$BIG"
assert_contains "{{_edit}} in a lookup's item edits the linked item, in its own list" \
    "<<button type=\"button\" class=\"wk-edit wk-linked\" data-space=\"CRM\" data-list=\"$PEOPLE\" data-item=\"1\"></button>>" "$BIG"
assert_contains "  a deleted item has none — not the row's own button either" \
    'data-item="2"></button>><>|' "$BIG"
SMALL=$(cat "$WIKI_PAGES/Customers/Deals/Small.md")
assert_contains 'no items: the inverted section shows'         'CARDS=none|' "$SMALL"
assert_contains '  and for a single lookup'                     'OWNER=//nobody|' "$SMALL"

S=$(get_as "$CARL" 'api.php?action=generator_sources')
assert_contains "the New dialog knows a lookup's item fields" '"key":"contacts","type":"lookup","title":false,"multiple":true,"subs":[{"name":"ItemId","key":"itemid","title":false},{"name":"Name","key":"name","title":true},{"name":"City","key":"city","title":false}' "$S"
C=$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$DEALS&path=Templates/DealCards&folder=DealCards&format=html")
assert_contains 'an HTML starter with a lookup'              '"success":true' "$C"
DC=$(cat "$WIKI_PAGES/Customers/Templates/DealCards.md")
assert_contains '  shows its items as cards'                 '{{#contacts}}' "$DC"
assert_contains '  each headed by what the lookup shows, and its link' '<h3>{{_text}} {{_edit}}</h3>' "$DC"
assert_not_contains "  its \"also show\" fields not repeated in the main table" 'Contacts: City' "$DC"
assert_contains "  with the item's fields"                   '<dt>City</dt><dd>{{city}}</dd>' "$DC"
assert_not_contains '  its Title not repeated'               '<dt>Name</dt>' "$DC"
assert_not_contains '  and no + (an item is picked, not added)' '{{_add.contacts}}' "$DC"
C=$(post_as "$CARL" 'api.php?action=generator_create&space=Customers' "source=CRM:$DEALS&path=Templates/DealMd&folder=DealMd")
assert_contains 'a Markdown starter with a lookup'           '"success":true' "$C"
DM=$(cat "$WIKI_PAGES/Customers/Templates/DealMd.md")
assert_contains '  repeats a section per item'               $'{{#contacts}}\n### {{_text}} {{_edit}}' "$DM"
assert_contains "  the item's fields as a list"              '- **City:** {{city}}' "$DM"
assert_contains '  and says when there are none'             $'{{^contacts}}\n_None._\n{{/contacts}}' "$DM"
assert_not_contains '  its "also show" field not in the table' 'Contacts: City' "$DM"
sleep 2
DMP=$(cat "$WIKI_PAGES/Customers/DealMd/Big.md")
assert_contains '  rendered: one heading per item, with its link' '### Ann <button type="button" class="wk-edit wk-linked"' "$DMP"
assert_contains '  …each with its fields'                    '- **City:** Bergen' "$DMP"
assert_contains '  a deleted item still has its heading'     '### (deleted &#35;9)' "$DMP"
assert_contains '  no items: the none line'                  '_None._' "$(cat "$WIKI_PAGES/Customers/DealMd/Small.md")"

section 'What an AI in a generated page’s chat is told'
# wiki_page_context_prompt (ai_core.php) — the page's chat names the page and its path; a
# generated one must say it cannot be written, and where its content comes from instead.
ctx_prompt() {
    (cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php"; require_once "ai_core.php";
$dir = PAGES_DIR . $argv[1];
echo wiki_page_context_prompt($dir . "/" . $argv[2], $dir, new PageIndexer($dir));
' "$1" "$2" 2>&1)
}
P=$(ctx_prompt Customers Deals/Big.chat)
assert_contains 'a generated page is said to be generated'   'This page is GENERATED' "$P"
assert_contains '  from which item, through which template'   'item id 1 of a list in the Space "CRM"' "$P"
assert_contains '  the template by path'                     'the template "Templates/Deal.md"' "$P"
assert_contains "  another Space's list: no path, and out of reach" 'which your tools here cannot reach' "$P"
assert_not_contains '  its path is not named'                 'Deals.list' "$P"
assert_not_contains '  never told to update it with wiki_write_page' 'use this exact value when calling wiki_write_page' "$P"
assert_contains '  told what to do instead'                  'the edit button beside the page' "$P"
cat > "$WIKI_PAGES/Customers/Local.list" <<'JSON'
{"columns":[{"id":"col_2","name":"Name","type":"text_single","role":"title"}],"items":[{"id":5,"col_2":"Lo"}],"nextItemId":6}
JSON
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
LOCAL=$(id_of Customers Local.list)
printf -- '---\ngenerator_source: Customers:%s\ngenerator_folder: Locals\n---\n# {{name}}\n' "$LOCAL" > "$WIKI_PAGES/Customers/Templates/Local.md"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Customers' > /dev/null
render "$CARL" "$(id_of Customers Templates/Local.md)" > /dev/null
P=$(ctx_prompt Customers Locals/Lo.chat)
assert_contains "a list in the page's own Space is named, and readable" \
    "item id 5 of the list \"Local.list\" (page id $LOCAL)" "$P"
assert_contains '  with wiki_read_page'                      'with wiki_read_page' "$P"
assert_contains '  and pointed at the tool that changes the item' 'change the item with wiki_update_list_item' "$P"
printf '# Plain\n' > "$WIKI_PAGES/Customers/Plain.md"
P=$(ctx_prompt Customers Plain.chat)
assert_contains 'control: an ordinary page is still the one to write'  'use this exact value when calling wiki_write_page' "$P"
assert_not_contains '  and not called generated'          'GENERATED' "$P"

exit $(( ASSERT_FAIL > 0 ))
