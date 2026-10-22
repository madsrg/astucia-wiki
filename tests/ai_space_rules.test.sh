#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# What an AI user is told about this Space before it writes (wiki_space_rules_prompt), and
# the write-time check on taxonomy-bound front-matter fields in wiki_write_page.
#
# The prompt is read through admin_ai_builtin_instructions, which builds it with the same
# functions a live chat reply and agent job use. Each part must appear exactly when its
# setting is on — and a Space with none of them must add nothing, which is the assertion
# that keeps this from becoming boilerplate in every prompt.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Plain; fixture_space Rich; fixture_space Frozen
fixture_page 'Plain/x.md' '# X'
fixture_page 'Plain/imported.md' '---
department: Ghost
---
# Imported'
fixture_page 'Rich/y.md' '# Y'
fixture_page 'Frozen/z.md' '# Z'
fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_rules",
   "ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
for s in Plain Rich Frozen; do get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null; done

enc() { python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
# prompt <space> <chat|job> — the instructions, decoded
prompt() { get_as "$ADMIN" "api.php?action=admin_ai_builtin_instructions&sample_space=$1" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$2"; }
mcp() { curl -s -X POST -H "Authorization: Bearer wk_ai_rules" -H 'Content-Type: application/json' \
    -d "$2" "$WIKI_URL/mcp.php?space=$1"; }
write() {
    mcp "$1" "$(python3 -c 'import json,sys; print(json.dumps({"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_write_page","arguments":{"path":sys.argv[1],"content":sys.argv[2]}}}))' "$2" "$3")"
}

TAXLINE='taxonomy, a shared tree'
SYNCLINE='follows its tags automatically'
STAMPLINE='maintains created, createdBy'
FROZENLINE='This space is read-only'
FIELDLINE='take fixed values from the taxonomy'

section 'a Space with nothing to say adds nothing'
for kind in chat job; do
    p=$(prompt Plain $kind)
    assert_contains "the $kind prompt is there"       'Plain' "$p"
    for m in "$TAXLINE" "$SYNCLINE" "$STAMPLINE" "$FROZENLINE" "$FIELDLINE"; do
        assert_not_contains "  without \"$m\"" "$m" "$p"
    done
done

section 'the taxonomy, once there is one'
post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(enc '{"nodes":[
  {"name":"Department","heading":true,"field":"department","children":[{"name":"Finance"},{"name":"HR"}]},
  {"name":"Audience","heading":true,"field":"audience","multiple":true,"children":[{"name":"Staff"},{"name":"Managers"}]}]}')" > /dev/null
p=$(prompt Plain chat)
assert_contains "tagging is explained"               "$TAXLINE" "$p"
assert_contains "  with the mode"                    'a tag that is not in it yet is added' "$p"
assert_contains "  and where to look"                'Call wiki_get_taxonomy before tagging' "$p"
assert_contains "the bound fields with their values" '"department" (one of: Finance, HR)' "$p"
assert_contains "  and which take several"           '"audience" (one or more of: Staff, Managers)' "$p"
assert_not_contains "but no Space settings that are off" "$SYNCLINE" "$p"
post_as "$ADMIN" 'api.php?action=admin_taxonomy_mode' 'mode=controlled' > /dev/null
assert_contains "controlled mode is said as such"    'only its items can be used' "$(prompt Plain job)"

section 'the Space settings, each when it is on'
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_tags_sync' 'space_name=Rich&mode=on' > /dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_autostamp' 'space_name=Rich&mode=on' > /dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Frozen&readonly=1' > /dev/null
for kind in chat job; do
    p=$(prompt Rich $kind)
    assert_contains "Rich ($kind): tags in front matter" "$SYNCLINE"  "$p"
    assert_contains "  and stamping"                     "$STAMPLINE" "$p"
    assert_not_contains "  but not frozen"               "$FROZENLINE" "$p"
done
p=$(prompt Frozen chat)
assert_contains "a frozen Space says so"             "$FROZENLINE" "$p"
assert_not_contains "  and Plain does not inherit it" "$FROZENLINE" "$(prompt Plain chat)"
assert_not_contains "Plain says nothing about Rich's settings" "$SYNCLINE" "$(prompt Plain chat)"

section 'a long value list is capped'
post_as "$ADMIN" 'api.php?action=admin_taxonomy_mode' 'mode=free' > /dev/null
many=$(python3 -c 'import json; print(json.dumps({"nodes":[{"name":"Code","heading":True,"field":"code","children":[{"name":"C%02d" % i} for i in range(30)]}]}))')
post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(enc "$many")" > /dev/null
p=$(prompt Plain chat)
assert_contains "the first 25 are listed"            'C00, C01' "$p"
assert_not_contains "  not the rest"                 'C29' "$p"
assert_contains "  and the tool is named for them"   'and 5 more — see wiki_get_taxonomy' "$p"

# Back to the department/audience tree for the write checks.
post_as "$ADMIN" 'api.php?action=admin_taxonomy_import' "data=$(enc '{"nodes":[
  {"name":"Department","heading":true,"field":"department","children":[{"name":"Finance"},{"name":"HR"}]},
  {"name":"Audience","heading":true,"field":"audience","multiple":true,"children":[{"name":"Staff"},{"name":"Managers"}]}]}')" > /dev/null

section 'an AI write is held to the bound fields'
r=$(write Plain 'New/Dir/bad.md' $'---\ndepartment: Finanse\n---\n# Bad')
assert_contains "a value outside the folder is refused" 'not a value of' "$r"
assert_contains "  naming the values"                'Finance, HR' "$r"
assert_contains "  and saying nothing was written"   'Nothing was written' "$r"
assert_file_missing "  no page"                      "$WIKI_PAGES/Plain/New/Dir/bad.md"
assert_file_missing "  and no empty folder left behind" "$WIKI_PAGES/Plain/New"
r=$(write Plain 'good.md' $'---\ndepartment: finance\naudience: [staff, Managers]\n---\n# Good')
assert_contains "a valid value is written"           'Page created' "$r"
assert_contains "  in the tree's spelling"           'department: Finance' "$(cat "$WIKI_PAGES/Plain/good.md")"
assert_contains "  lists too"                        'audience: [Staff, Managers]' "$(cat "$WIKI_PAGES/Plain/good.md")"
r=$(write Plain 'imported.md' '# Imported, edited by the AI')
assert_contains "an imported odd value the AI did not touch passes" 'Page updated' "$r"
assert_contains "  and is kept"                      'department: Ghost' "$(cat "$WIKI_PAGES/Plain/imported.md")"
r=$(write Plain 'imported.md' $'---\ndepartment: Ghost\n---\n# Imported again, block restated')
assert_contains "  restating it unchanged passes as well" 'Page updated' "$r"
r=$(write Plain 'imported.md' $'---\ndepartment: Ghost2\n---\n# Changed')
assert_contains "  but changing it to another odd value is refused" 'not a value of' "$r"
r=$(write Plain 'free.md' $'---\nowner: anyone\n---\n# Free')
assert_contains "a field that is not bound is free" 'Page created' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
