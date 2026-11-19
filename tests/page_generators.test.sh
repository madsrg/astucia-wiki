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
assert_contains '  a starter body listing the fields'    '| **Account manager** | {{account_manager}} |' "$(cat "$NEWT")"
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

exit $(( ASSERT_FAIL > 0 ))
