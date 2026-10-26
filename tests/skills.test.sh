#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# AI skills: SKILL.md procedures in a Space's skills/ folder and in a wiki-wide store,
# listed in the prompt and read on demand with wiki_read_skill.
#
# What is really being guarded:
#
#   - **Only administrators write skills, and no AI ever does.** A skill is injected into
#     every prompt in the Space, read by AIs holding write tools. The rule is on the
#     *path*, so every write action is covered — each denial below is paired with an
#     admin positive control, or a malformed request would pass it without reaching the
#     guard.
#   - **Two switches**, Space and AI User, with an explicit per-AI Off beating the default.
#   - **Shadowing**: a Space skill replaces a wiki-wide one of the same name.
#   - **Import hardening**: zip-slip, symlinks, executables and oversize files never land.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start oidc || exit 1
trap fixture_stop EXIT

fixture_space Alpha
fixture_space Bravo
fixture_page 'Alpha/Home.md' '# Home'
fixture_page 'Bravo/Home.md' '# Home'
fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Eddie","role":"editor","auth":"oidc"},
  {"uid":3,"name":"Bot","role":"admin","is_ai":true,"service_token":"wk_ai_skills",
   "ai_config":{"provider":"openai","model":"gpt-4o"}},
  {"uid":4,"name":"Quiet","role":"editor","is_ai":true,"service_token":"wk_ai_quiet",
   "ai_config":{"provider":"openai","model":"gpt-4o","skills":"off"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin
EDITOR=$WIKI_ROOT/jar-editor
fixture_login "$ADMIN"  "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$EDITOR" "uid=2&sub=sub-2&name=Eddie&role=editor"
get_as "$ADMIN" 'api.php?action=list_spaces' > /dev/null
for s in Alpha Bravo; do get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null; done

SK=$WIKI_PAGES/Alpha/skills
GSK=$WIKI_SYS/skills

enc() { python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
skill_md() {   # skill_md <name> <description> [body]
    printf -- '---\nname: %s\ndescription: %s\n---\n\n%s\n' "$1" "$2" "${3:-# Steps}"
}
save_skill() { # save_skill <jar> <scope> <original> <content>
    curl -s -b "$1" -c "$1" --max-time 15 -X POST "$WIKI_URL/api.php?action=admin_skill_save" \
        --data-urlencode "scope=$2" --data-urlencode "original=$3" --data-urlencode "content=$4"
}
mcp() {        # mcp <token> <space> <json>
    curl -s -X POST -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        -d "$3" "$WIKI_URL/mcp.php?space=$2"
}
call() {       # call <token> <space> <tool> <args-json>
    mcp "$1" "$2" "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$3\",\"arguments\":$4}}"
}
tools() { mcp "$1" "$2" '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
prompt() {     # prompt <space> [skills-tri-state]
    get_as "$ADMIN" "api.php?action=admin_ai_builtin_instructions&sample_space=$1&skills=${2:-}" \
        | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["chat"]); print("TOOLS=" + ",".join(t["name"] for t in d["tools"]))'
}

# ── authoring through the admin actions ──────────────────────────────────────
section 'an administrator creates a Space skill and a wiki-wide skill'
r=$(save_skill "$ADMIN" Alpha '' "$(skill_md weekly-report 'Writes the weekly report. Use when asked for a status update.' '# Weekly report

Read [the guide](reference.md).')")
assert_contains "the Space skill saves" '"success":true' "$r"
assert_file_exists "as skills/<name>/SKILL.md" "$SK/weekly-report/SKILL.md"
r=$(get_as "$ADMIN" 'api.php?action=list&space=Alpha')
assert_contains "and it is a page in the tree's index" 'SKILL.md' "$(cat "$WIKI_PAGES/Alpha/index.json")"
r=$(save_skill "$ADMIN" '' '' "$(skill_md house-style 'Applies the house writing style. Use for any customer-facing text.')")
assert_contains "the wiki-wide skill saves" '"success":true' "$r"
assert_file_exists "into WIKI_SYSTEM_DATA" "$GSK/house-style/SKILL.md"
printf 'Long reference material.\n' > "$SK/weekly-report/reference.md"
printf 'not text' > "$SK/weekly-report/logo.png"

section 'validation collects every problem and writes nothing'
r=$(save_skill "$ADMIN" Alpha '' "$(printf -- '---\nname: Bad Name\n---\n# x')")
assert_contains "a bad name is refused"        'name-invalid'        "$r"
assert_contains "and so is the missing description, in the same answer" 'description-missing' "$r"
assert_file_missing "nothing was written" "$SK/Bad Name"
r=$(save_skill "$ADMIN" Alpha '' '# no front matter at all')
assert_contains "no front matter is refused" 'no-frontmatter' "$r"
r=$(save_skill "$ADMIN" Alpha '' "$(skill_md weekly-report 'dup')")
assert_contains "a second skill of an existing name is refused" 'already exists' "$r"

section 'renaming follows the name into the folder and the index'
save_skill "$ADMIN" Alpha '' "$(skill_md draft-thing 'A draft. Use never.')" > /dev/null
r=$(save_skill "$ADMIN" Alpha draft-thing "$(skill_md renamed-thing 'A draft. Use never.')")
assert_contains "the rename saves" '"name":"renamed-thing"' "$r"
assert_file_exists  "the folder moved"      "$SK/renamed-thing/SKILL.md"
assert_file_missing "the old one is gone"   "$SK/draft-thing"
assert_not_contains "and the index forgot the old path" 'draft-thing' "$(cat "$WIKI_PAGES/Alpha/index.json")"
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_delete" -d 'scope=Alpha&name=renamed-thing')
assert_contains "delete succeeds" '"success":true' "$r"
assert_file_missing "and removes the folder" "$SK/renamed-thing"
assert_not_contains "and its index entries" 'renamed-thing' "$(cat "$WIKI_PAGES/Alpha/index.json")"

# ── only administrators ──────────────────────────────────────────────────────
section 'an editor cannot write into skills/ by any page action'
P="skills/weekly-report/SKILL.md"
r=$(post_as "$EDITOR" "api.php?action=save&space=Alpha&file=$(enc "$P")" '# hijacked')
assert_contains "save is refused"   'Only an administrator can change skills' "$r"
assert_not_contains "and the file is untouched" 'hijacked' "$(cat "$SK/weekly-report/SKILL.md")"
r=$(post_as "$EDITOR" "api.php?action=save&space=Alpha&file=Home.md" '# Home edited')
assert_contains "while the same editor can save an ordinary page" '"success":true' "$r"
r=$(post_as "$EDITOR" 'api.php?action=create_file&space=Alpha' "path=$(enc skills/evil/SKILL.md)")
assert_contains "create is refused" 'Only an administrator' "$r"
assert_file_missing "nothing was created" "$SK/evil"
r=$(post_as "$EDITOR" 'api.php?action=create_folder&space=Alpha' "path=$(enc skills/evil2)")
assert_contains "a folder inside it is refused" 'Only an administrator' "$r"
r=$(post_as "$EDITOR" 'api.php?action=create_folder&space=Alpha' "path=$(enc Notes)")
assert_contains "while an ordinary folder is created" '"success":true' "$r"
fixture_page 'Alpha/Smuggle.md' '# Smuggled skill'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Alpha' > /dev/null
r=$(post_as "$EDITOR" 'api.php?action=move&space=Alpha' "old_path=Smuggle.md&new_path=$(enc skills/weekly-report/SKILL.md.bak.md)")
assert_contains "moving a page into skills/ is refused" 'Only an administrator' "$r"
r=$(post_as "$EDITOR" 'api.php?action=move&space=Alpha' "old_path=Notes&new_path=skills")
assert_contains "renaming a folder *to* skills is refused" 'Only an administrator' "$r"
r=$(post_as "$EDITOR" 'api.php?action=move&space=Bravo' "old_path=Home.md&new_path=$(enc skills/x/SKILL.md)&target_space=Alpha")
assert_contains "a cross-space move into skills/ is refused" 'Only an administrator' "$r"
r=$(post_as "$EDITOR" 'api.php?action=delete&space=Alpha' "path=$(enc skills/weekly-report)")
assert_contains "deleting a skill folder is refused" 'Only an administrator' "$r"
assert_file_exists "and it survives" "$SK/weekly-report/SKILL.md"
r=$(post_as "$EDITOR" "api.php?action=save&file=$(enc "Alpha/$P")" '# hijacked')
assert_contains "with no ?space= the path still lands in Alpha's skills and is refused" 'Only an administrator' "$r"
r=$(post_as "$EDITOR" "api.php?action=save&space=Alpha&file=$(enc Skills/weekly-report/SKILL.md)" '# x')
assert_contains "a capital S does not walk past it" 'Only an administrator' "$r"
r=$(get_as "$EDITOR" "api.php?action=get&space=Alpha&file=$(enc "$P")")
assert_contains "reading a skill is still allowed" 'Weekly report' "$r"
r=$(get_as "$EDITOR" 'api.php?action=admin_skills_list&scope=')
assert_contains "and the admin actions are admin-only" 'Admin access required' "$r"

section 'an administrator can edit a skill page directly, and the save is validated'
r=$(post_as "$ADMIN" "api.php?action=save&space=Alpha&file=$(enc "$P")" '# Weekly report

Read [the guide](reference.md). Edited by an admin.')
assert_contains "the admin's body save succeeds" '"success":true' "$r"
assert_contains "and the front matter was preserved" 'name: weekly-report' "$(cat "$SK/weekly-report/SKILL.md")"
r=$(post_as "$ADMIN" "api.php?action=save&space=Alpha&file=$(enc "$P")" "$(printf -- '---\nname: other-name\ndescription: x\n---\nbody')")
assert_contains "a save that breaks the skill is refused" 'This skill was not saved' "$r"
assert_contains "and says why" 'does not match its folder' "$r"
assert_contains "leaving the file as it was" 'name: weekly-report' "$(cat "$SK/weekly-report/SKILL.md")"

# ── switches and the prompt ──────────────────────────────────────────────────
section 'with the Space switch off, skills are invisible to an AI'
out=$(prompt Alpha)
assert_not_contains "the prompt says nothing" 'SKILLS —' "$out"
assert_not_contains "and the tool is not offered" 'wiki_read_skill' "$(printf '%s' "$out" | grep '^TOOLS=')"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"weekly-report"}')
assert_contains "a call anyway is refused and names the switch" 'does not offer AI skills' "$r"

section 'switched on, the listing carries both stores'
r=$(post_as "$ADMIN" 'api.php?action=admin_set_space_skills' 'space_name=Alpha&skills=1')
assert_contains "the setting saves" '"skills":true' "$r"
out=$(prompt Alpha)
assert_contains "the prompt lists the Space skill"     'weekly-report: Writes the weekly report' "$out"
assert_contains "and the wiki-wide one"                'house-style: Applies the house writing style' "$out"
assert_contains "and says how to use them"             'call wiki_read_skill' "$out"
assert_contains "and the tool is offered"              'wiki_read_skill' "$(printf '%s' "$out" | grep '^TOOLS=')"
out=$(prompt Bravo)
assert_not_contains "a Space with the switch off still sees none" 'SKILLS —' "$out"

section 'the per-AI tri-state'
out=$(prompt Alpha off)
assert_not_contains "an AI set to Off is told nothing" 'SKILLS —' "$out"
r=$(call wk_ai_quiet Alpha wiki_read_skill '{"name":"weekly-report"}')
assert_contains "and its tool call is refused" 'switched off for this AI user' "$r"
post_as "$ADMIN" 'api.php?action=admin_ai_skills_settings' 'enabled=0' > /dev/null
out=$(prompt Alpha)
assert_not_contains "with the house default off, an undecided AI sees none" 'SKILLS —' "$out"
out=$(prompt Alpha on)
assert_contains "while an AI set to On still does" 'SKILLS —' "$out"
r=$(post_as "$ADMIN" 'api.php?action=admin_ai_skills_settings' 'enabled=1')
assert_contains "the default goes back on" '"enabled":true' "$r"

section 'reading a skill and its files'
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"weekly-report"}')
assert_contains "the body comes back"                 'Edited by an admin'   "$r"
assert_not_contains "without its front matter"        'description:'         "$r"
assert_contains "with its reference files listed"     'reference.md'         "$r"
assert_not_contains "but not its images"              'logo.png'             "$r"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"weekly-report","file":"reference.md"}')
assert_contains "a reference file reads"              'Long reference material' "$r"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"weekly-report","file":"../../Home.md"}')
assert_contains "climbing out of the skill is refused" 'not allowed' "$r"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"weekly-report","file":"logo.png"}')
assert_contains "an image is not handed to a model"    'only text files' "$r"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"house-style"}')
assert_contains "a wiki-wide skill reads too"          'Skill: house-style' "$r"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"nope"}')
assert_contains "an unknown name says so"              'no skill called' "$r"

section 'a Space skill shadows the wiki-wide one of the same name'
save_skill "$ADMIN" Alpha '' "$(skill_md house-style 'Alpha house style. Use for Alpha text.' 'ALPHA VERSION')" > /dev/null
out=$(prompt Alpha)
assert_contains     "the Space's description is listed" 'house-style: Alpha house style' "$out"
assert_not_contains "and the wiki-wide one is not"      'Applies the house writing style' "$out"
r=$(call wk_ai_skills Alpha wiki_read_skill '{"name":"house-style"}')
assert_contains "and the tool reads the Space's"        'ALPHA VERSION' "$r"
r=$(get_as "$ADMIN" 'api.php?action=admin_skills_list&scope=')
assert_contains "the admin list says where it is shadowed" '"shadowed_in":["Alpha"]' "$r"

section 'an invalid skill on disk drops out of the listing but is reported'
mkdir -p "$SK/broken"; printf -- '---\nname: broken\n---\n' > "$SK/broken/SKILL.md"
out=$(prompt Alpha)
assert_not_contains "it is not offered to the AI" '- broken' "$out"
r=$(get_as "$ADMIN" 'api.php?action=admin_skills_list&scope=Alpha')
assert_contains "the admin list shows it with its reason" 'description-missing' "$r"
rm -rf "$SK/broken"

# ── AIs never write skills ───────────────────────────────────────────────────
section 'an AI cannot write into skills/, even with the admin role'
r=$(call wk_ai_skills Alpha wiki_write_page '{"path":"skills/weekly-report/SKILL.md","content":"# taken over"}')
assert_contains "wiki_write_page is refused" 'only an administrator can change' "$r"
assert_not_contains "and the skill is intact" 'taken over' "$(cat "$SK/weekly-report/SKILL.md")"
r=$(call wk_ai_skills Alpha wiki_write_page '{"path":"Notes/ai.md","content":"# fine"}')
assert_contains "while it can write an ordinary page" 'Page created' "$r"
r=$(call wk_ai_skills '' wiki_write_page '{"path":"Alpha/skills/new/SKILL.md","content":"# x"}')
assert_contains "with no ?space= too" 'only an administrator can change' "$r"
r=$(curl -s -H "Authorization: Bearer wk_ai_skills" -X POST \
    "$WIKI_URL/api.php?action=save&space=Alpha&file=$(enc "$P")" -d '# via rest')
assert_contains "and an admin-role AI token is refused over REST" 'Only an administrator' "$r"

section 'skills stay out of search'
r=$(get_as "$ADMIN" 'api.php?action=search&space=Alpha&query=weekly')
assert_not_contains "a skill page is not a search hit" 'SKILL.md' "$r"

# ── freezing ─────────────────────────────────────────────────────────────────
section 'a frozen Space refuses skill writes, from the admin actions too'
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Alpha&readonly=1' > /dev/null
r=$(save_skill "$ADMIN" Alpha '' "$(skill_md frozen-one 'x. Use never.')")
assert_contains "admin_skill_save is refused" 'read-only' "$r"
assert_file_missing "nothing written" "$SK/frozen-one"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Alpha&readonly=0' > /dev/null

# ── import ───────────────────────────────────────────────────────────────────
ZIP=$WIKI_ROOT/skills.zip
python3 - "$ZIP" <<'PY'
import sys, zipfile, stat
z = zipfile.ZipFile(sys.argv[1], 'w')
def add(name, data, mode=None):
    zi = zipfile.ZipInfo(name)
    if mode is not None:
        zi.create_system = 3
        zi.external_attr = mode << 16
    z.writestr(zi, data)
add('repo-main/skills/pdf-tools/SKILL.md', '---\nname: pdf-tools\ndescription: >\n  Works with PDF files.\n  Use when a PDF is mentioned.\n---\n# PDF\n')
add('repo-main/skills/pdf-tools/forms.md', '# Forms\n')
add('repo-main/skills/pdf-tools/scripts/fill.py', 'print("never")\n')
add('repo-main/skills/pdf-tools/evil.php', '<?php system("id");\n')
add('repo-main/skills/pdf-tools/link.md', '/etc/passwd', mode=stat.S_IFLNK | 0o777)
add('repo-main/skills/pdf-tools/../../../../escape.md', 'slipped\n')
add('repo-main/skills/bad-one/SKILL.md', '---\nname: Bad One\ndescription: x\n---\n')
add('repo-main/skills/weekly-report/SKILL.md', '---\nname: weekly-report\ndescription: Imported version. Use whenever.\n---\nIMPORTED\n')
add('repo-main/.github/workflows/x.md', 'hidden\n')
z.close()
PY
section 'a zip is staged, filtered and reported before anything is written'
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" -F source=zip -F "file=@$ZIP")
assert_contains "staging succeeds"                '"success":true' "$r"
assert_contains "the folded description is read"  'Works with PDF files. Use when a PDF is mentioned.' "$r"
assert_contains "an invalid skill is reported"    'name-invalid' "$r"
assert_contains "the script is reported as dropped"  'fill.py' "$r"
assert_contains "and the PHP file"                'evil.php' "$r"
assert_contains "and the symlink"                 '"reason":"link"' "$r"
IMPORT_ID=$(printf '%s' "$r" | python3 -c 'import json,sys; print(json.load(sys.stdin)["import_id"])')
assert_file_missing "nothing has reached the Space yet" "$SK/pdf-tools"
find "$WIKI_ROOT" -name escape.md | grep -q . && _fail "zip-slip wrote outside the staging folder" "found escape.md" \
    || _pass "zip-slip wrote nothing outside the staging folder"

section 'committing writes only what was chosen, and respects existing names'
CH='{"repo-main/skills/pdf-tools":"import","repo-main/skills/bad-one":"import","repo-main/skills/weekly-report":"import"}'
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_commit" \
    --data-urlencode "import_id=$IMPORT_ID" --data-urlencode 'scope=Alpha' --data-urlencode "choices=$CH")
assert_contains "pdf-tools is imported"            '"imported":["pdf-tools"]' "$r"
assert_contains "an existing name is skipped unless overwrite is chosen" '"skipped":["weekly-report"]' "$r"
assert_contains "the invalid one is an error"      'Bad One' "$r"
assert_file_exists  "the skill landed under its name"  "$SK/pdf-tools/SKILL.md"
assert_file_exists  "with its reference file"          "$SK/pdf-tools/forms.md"
assert_file_missing "and no script"                    "$SK/pdf-tools/scripts"
assert_file_missing "and no PHP"                       "$SK/pdf-tools/evil.php"
assert_file_missing "and no symlink"                   "$SK/pdf-tools/link.md"
assert_not_contains "the skipped skill was left alone" 'IMPORTED' "$(cat "$SK/weekly-report/SKILL.md")"
# index.json escapes slashes, so match the folder name rather than a path.
assert_contains "the import is indexed as pages"       'pdf-tools' "$(cat "$WIKI_PAGES/Alpha/index.json")"
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_commit" \
    --data-urlencode "import_id=$IMPORT_ID" --data-urlencode 'scope=Alpha' --data-urlencode "choices=$CH")
assert_contains "a staged import is used once" 'expired' "$r"

section 'overwrite replaces, into the wiki-wide store as well'
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" -F source=zip -F "file=@$ZIP")
IMPORT_ID=$(printf '%s' "$r" | python3 -c 'import json,sys; print(json.load(sys.stdin)["import_id"])')
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_commit" \
    --data-urlencode "import_id=$IMPORT_ID" --data-urlencode 'scope=Alpha' \
    --data-urlencode 'choices={"repo-main/skills/weekly-report":"overwrite","repo-main/skills/pdf-tools":"skip"}')
assert_contains "overwrite imports"     '"imported":["weekly-report"]' "$r"
assert_contains "the content is replaced" 'IMPORTED' "$(cat "$SK/weekly-report/SKILL.md")"
assert_file_missing "and the old reference file went with it" "$SK/weekly-report/reference.md"
assert_not_contains "and its index entry" 'reference.md' "$(cat "$WIKI_PAGES/Alpha/index.json")"
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" -F source=zip -F "file=@$ZIP")
IMPORT_ID=$(printf '%s' "$r" | python3 -c 'import json,sys; print(json.load(sys.stdin)["import_id"])')
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_commit" \
    --data-urlencode "import_id=$IMPORT_ID" --data-urlencode 'scope=' \
    --data-urlencode 'choices={"repo-main/skills/pdf-tools":"import"}')
assert_contains "a wiki-wide import works" '"imported":["pdf-tools"]' "$r"
assert_file_exists "into WIKI_SYSTEM_DATA" "$GSK/pdf-tools/SKILL.md"

section 'a zip bomb and a non-zip are refused'
python3 - "$WIKI_ROOT/bomb.zip" <<'PY'
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED)
for i in range(12):
    z.writestr(f'b/SKILL{i}.md', '0' * (1900 * 1024))
z.close()
PY
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" -F source=zip -F "file=@$WIKI_ROOT/bomb.zip")
assert_contains "more than the unpacked ceiling is refused" 'MB' "$r"
printf 'not a zip' > "$WIKI_ROOT/fake.zip"
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" -F source=zip -F "file=@$WIKI_ROOT/fake.zip")
assert_contains "a file that is not a zip is refused" 'not a readable zip' "$r"
assert_eq "and no staging folders are left behind" "0" "$(ls "$WIKI_SYS/skill_imports" 2>/dev/null | wc -l | tr -d ' ')"

section 'a repository address must be https'
for u in 'file:///etc' 'ssh://git@example.com/x' 'git@github.com:a/b.git' 'https://example.com/a;rm -rf' '--upload-pack=x'; do
    r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=admin_skill_import_stage" \
        --data-urlencode source=repo --data-urlencode "url=$u")
    assert_contains "refused: $u" 'repository address' "$r"
done
r=$(cd "$WIKI_APP" && php -r 'require "config.php"; require "skills.php";
    echo json_encode(wiki_skill_parse_repo("https://github.com/anthropics/skills/tree/main/skills/pdf"), JSON_UNESCAPED_SLASHES);')
assert_contains "a GitHub tree link becomes repo + branch + folder" \
    '{"url":"https://github.com/anthropics/skills","branch":"main","path":"skills/pdf"}' "$r"

# ── MCP prompts ──────────────────────────────────────────────────────────────
section 'skills are MCP prompts for an outside agent, behind the same switches'
r=$(mcp wk_ai_skills Alpha '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')
assert_contains "the server advertises prompts" '"prompts"' "$r"
r=$(mcp wk_ai_skills Alpha '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}')
assert_contains "the Space's skills are listed"   '"name":"weekly-report"' "$r"
assert_contains "and the wiki-wide ones"          '"name":"pdf-tools"' "$r"
r=$(mcp wk_ai_skills Alpha '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"house-style"}}')
assert_contains "a prompt returns the skill — the Space's, shadowing" 'ALPHA VERSION' "$r"
r=$(mcp wk_ai_skills Bravo '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}')
assert_contains "a Space with skills off lists none" '"prompts":[]' "$r"
r=$(mcp wk_ai_quiet Alpha '{"jsonrpc":"2.0","id":1,"method":"prompts/list"}')
assert_contains "and so does an AI set to Off"     '"prompts":[]' "$r"
r=$(mcp wk_ai_skills Bravo '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"house-style"}}')
assert_contains "getting one there is an error"    'Unknown prompt' "$r"

# ── /skill <name> ────────────────────────────────────────────────────────────
section '/skill puts the chosen skill in front of the model'
r=$(cd "$WIKI_APP" && php -r 'require "config.php"; require "skills.php";
    $d = rtrim(PAGES_DIR, "/") . "/Alpha";
    echo "A=", wiki_skill_invocation("#Bot /skill weekly-report write it"), "\n";
    echo "B=", var_export(wiki_skill_invocation("please /skill weekly-report"), true), "\n";
    echo "C=", str_replace("\n", " ", wiki_skill_invocation_prompt($d, [], "/skill house-style hi")), "\n";
    echo "D=", str_replace("\n", " ", wiki_skill_invocation_prompt($d, [], "/skill nope hi")), "\n";
    echo "E=", str_replace("\n", " ", wiki_skill_invocation_prompt($d, ["skills" => "off"], "/skill house-style hi")), "\n";')
assert_contains "recognised after a mention"         'A=weekly-report' "$r"
assert_contains "but only at the start"              'B=NULL' "$r"
assert_contains "the skill's text is appended"       'C=  The user invoked this skill explicitly with /skill house-style' "$r"
assert_contains "the Space's version, shadowing"     'ALPHA VERSION' "$(printf '%s' "$r" | grep '^C=')"
assert_contains "an unknown skill is said to be unknown" 'there is no such skill here' "$r"
assert_contains "and an AI with skills off is told so" 'not available to you here' "$r"

section 'a queued /aiJob remembers the skill past the thread history'
fixture_page 'Alpha/Ops.chat' '{"messages":[{"id":1,"uid":1,"name":"Admin","timestamp":"2026-01-01T00:00:00+00:00","text":"earlier"}],"nextMessageId":2,"topic":"T"}'
get_as "$ADMIN" 'api.php?action=indexfiles&space=Alpha' > /dev/null
r=$(curl -s -b "$ADMIN" -X POST "$WIKI_URL/api.php?action=queue_agent_job&space=Alpha" \
    --data-urlencode 'file=Ops.chat' --data-urlencode 'ai_user=Quiet' --data-urlencode 'prompt=/skill weekly-report for this week')
assert_contains "the job is queued" '"success":true' "$r"
assert_contains "carrying the skill's name" '"skill": "weekly-report"' \
    "$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1]))["jobs"][-1]))' "$WIKI_SYS/agent_jobs_queue.json")"
