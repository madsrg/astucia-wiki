#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Applying one Space's settings elsewhere (Space settings → Advanced).
#
# Two features: copying the Content and/or AI settings of one Space onto others, and
# defaults that new Spaces start with. Both copy values — nothing inherits — and neither
# ever touches the read-only flag. The assertions that matter most are what must NOT
# change: the read-only flag, a group that was not chosen, a Space that was not chosen,
# anything at all on a dry run, and skills on an editor's new Space.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Src; fixture_space Alpha; fixture_space Beta; fixture_space Gamma
fixture_page "Src/page.md"   '# Src'
fixture_page "Alpha/page.md" '# Alpha'
fixture_page "Beta/page.md"  '# Beta'
fixture_page "Gamma/page.md" '# Gamma'

fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Ed","role":"editor","auth":"oidc"}
]}'
# Audit logging is a runtime setting, off by default — on, so the copy's entry can be read.
printf '%s\n' '{"audit_log":true}' > "$WIKI_SYS/settings.json"

ADMIN=$WIKI_ROOT/jar-admin; ED=$WIKI_ROOT/jar-ed
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=sub-2&name=Ed&role=editor"

# The record a Space has in spaces.json, as compact JSON ({} when it has none).
rec() {
    python3 -c 'import json,sys
try: d=json.load(open(sys.argv[1]))
except Exception: d={}
print(json.dumps(d.get(sys.argv[2],{}),sort_keys=True,separators=(",",":")))' "$WIKI_SYS/spaces.json" "$1"
}

# Src: every Content and AI setting on. Alpha: frozen, and memory on (to prove a copy
# of only Content leaves AI alone). Beta and Gamma: nothing.
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_edit'       'space_name=Src&mode=manual' >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_autostamp'  'space_name=Src&mode=on'     >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_tags_sync'  'space_name=Src&mode=on'     >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_memory'        'space_name=Src&memory=1'    >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_skills'        'space_name=Src&skills=1'    >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly'      'space_name=Src&readonly=1'  >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly'      'space_name=Alpha&readonly=1' >/dev/null
post_as "$ADMIN" 'api.php?action=admin_set_space_memory'        'space_name=Alpha&memory=1'  >/dev/null

# ── who may ──────────────────────────────────────────────────────────────────
section 'only an administrator'
r=$(post_as "$ED" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Beta&groups=content')
assert_not_contains "an editor cannot copy settings"        '"success":true' "$r"
assert_eq           "  and Beta is unchanged"               '{}' "$(rec Beta)"
r=$(post_as "$ED" 'api.php?action=admin_space_defaults' 'from_space=Src')
assert_not_contains "an editor cannot set the defaults"     '"success":true' "$r"

# ── malformed requests ───────────────────────────────────────────────────────
section 'what is refused'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Beta&groups=readonly')
assert_not_contains "an unknown group (readonly) is refused" '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Nope&groups=content')
assert_not_contains "an unknown target Space is refused"     '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=.git&groups=content')
assert_not_contains "a dot-named target is refused"          '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Src&groups=content')
assert_not_contains "copying only onto itself is refused"    '"success":true' "$r"
assert_eq           "  and Beta is still unchanged"          '{}' "$(rec Beta)"

# ── the preview ──────────────────────────────────────────────────────────────
section 'dry run'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Alpha,Beta&groups=content,ai&dry_run=1')
assert_contains     "it succeeds"                            '"success":true' "$r"
assert_contains     "  and says it did not apply"            '"applied":false' "$r"
assert_contains     "  and lists Beta's changes"             '"fm_edit":["off","manual"]' "$r"
assert_contains     "  memory too"                           '"memory":[false,true]' "$r"
assert_eq           "but Beta is unchanged"                  '{}' "$(rec Beta)"

# ── copying Content only ─────────────────────────────────────────────────────
section 'copying the Content group'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Alpha,Beta&groups=content')
assert_contains     "it succeeds"                            '"success":true' "$r"
assert_contains     "  and names what changed"               '"changed":["Alpha","Beta"]' "$r"
assert_eq           "Beta has the three front-matter settings, and nothing else" \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on"}' "$(rec Beta)"
assert_eq           "Alpha keeps its read-only flag and its memory" \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on","memory":true,"readonly":true}' "$(rec Alpha)"
assert_eq           "Gamma was not chosen and is untouched"  '{}' "$(rec Gamma)"
ls=$(get_as "$ADMIN" 'api.php?action=list_spaces')
assert_not_contains "read-only was never copied"             '"readonly":["Alpha","Beta"' "$ls"
assert_contains     "  (list_spaces sees the copied stamping)" '"fm_autostamp":["Alpha","Beta","Src"]' "$ls"

section 'a second copy that changes nothing'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Beta&groups=content')
assert_contains     "it succeeds"                            '"success":true' "$r"
assert_contains     "  and reports nothing changed"          '"changed":[]' "$r"

# ── copying AI, and copying "off" ────────────────────────────────────────────
section 'copying the AI group'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Src&targets=Gamma&groups=ai')
assert_eq           "Gamma gets memory and skills only"      '{"memory":true,"skills":true}' "$(rec Gamma)"

section 'copying an off setting removes it'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_copy_settings' 'source=Beta&targets=Alpha,Gamma&groups=ai')
assert_contains     "it succeeds"                            '"success":true' "$r"
assert_eq           "Gamma, all off, has no record left"     '{}' "$(rec Gamma)"
assert_eq           "Alpha loses memory but stays frozen"    \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on","readonly":true}' "$(rec Alpha)"

audit=$(cat "$WIKI_ROOT"/logs/audit/*.log 2>/dev/null)
assert_contains     "the copy is audited"                    'space_settings_copy' "$audit"

# ── defaults for new Spaces ──────────────────────────────────────────────────
section 'defaults: unset'
r=$(get_as "$ED" 'api.php?action=list_spaces')
assert_contains     "an editor is told the defaults (to fill in the New Space dialog)" '"space_defaults":{"fm_edit":"off"' "$r"
post_as "$ADMIN" 'api.php?action=create_space' 'name=Plain' >/dev/null
assert_eq           "with no defaults a new Space has no record" '{}' "$(rec Plain)"

section 'defaults: from a Space'
r=$(post_as "$ADMIN" 'api.php?action=admin_space_defaults' 'from_space=Src')
assert_contains     "an admin sets them from Src"            '"success":true' "$r"
assert_contains     "  and they are Src's"                   '"defaults":{"fm_edit":"manual","fm_autostamp":"on","fm_tags_sync":"on","memory":true,"skills":true}' "$r"
assert_not_contains "  read-only is not part of them"        'readonly' "$r"

r=$(post_as "$ADMIN" 'api.php?action=create_space' 'name=Fresh')
assert_contains     "a Space created naming no settings…"   '"success":true' "$r"
assert_eq           "  gets the defaults, and is not frozen" \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on","memory":true,"skills":true}' "$(rec Fresh)"
assert_contains     "  and its start page is stamped (settings saved before the scaffold)" \
                    'updated:' "$(head -8 "$WIKI_PAGES/Fresh/Main.md")"

r=$(post_as "$ADMIN" 'api.php?action=create_space' 'name=Chosen&fm_edit=off&fm_autostamp=off&fm_tags_sync=off&memory=0&skills=0')
assert_eq           "what the dialog sends still wins"       '{}' "$(rec Chosen)"

r=$(post_as "$ED" 'api.php?action=create_space' 'name=EdSpace&skills=0&memory=0')
assert_contains     "an editor may create a Space"           '"success":true' "$r"
assert_eq           "  but cannot turn the default skills off" \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on","skills":true}' "$(rec EdSpace)"
post_as "$ADMIN" 'api.php?action=admin_space_defaults' 'from_space=Beta' >/dev/null
r=$(post_as "$ED" 'api.php?action=create_space' 'name=EdSpace2&skills=1')
assert_eq           "  nor turn skills on"                   \
                    '{"fm_autostamp":"on","fm_edit":"manual","fm_tags_sync":"on"}' "$(rec EdSpace2)"

r=$(post_as "$ADMIN" 'api.php?action=create_space' 'name=Bad&fm_edit=sometimes')
assert_not_contains "an unknown value is refused"            '"success":true' "$r"
assert_file_missing "  before the folder is made"            "$WIKI_PAGES/Bad"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
