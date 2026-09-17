#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Manual front-matter editing: the `set_frontmatter` action and the per-Space switch.
#
# The writer is the interesting part, and it is built the way it is because the obvious
# implementation is wrong: parsing the block into an array and emitting it again would
# reformat somebody's YAML, drop their comments and flatten their nested values — which is
# exactly the file this feature exists to interoperate with. So it edits *lines*, and most
# of what follows checks that the bytes nobody asked about are still there afterwards.
#
# The other half is that this is a content write on its own — no page save to ride along
# with — so it carries a guard, a precondition and a commit of its own rather than
# inheriting the save action's.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Vault
fixture_space Plain
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"a@example.com"},
                         {"uid":2,"name":"Ed","role":"editor","auth":"otp","email":"e@example.com"},
                         {"uid":3,"name":"Reader","role":"reader","auth":"otp","email":"r@example.com"}]}'

# A page carrying everything the writer must not disturb: a comment, a block list, a
# nested mapping, and a value whose quoting matters.
write_page() {
    printf '%s' '---
title: Quarterly Report
# a note the author left
tags:
  - finance
  - q1
nested:
  a: 1
  b: 2
status: draft
quoted: "has: a colon"
---
# Quarterly Report

Revenue was up.
' > "$WIKI_PAGES/$1"
}
write_page 'Vault/Report.md'
fixture_page 'Vault/Bare.md' '# Bare

No block here.'

JAR=$WIKI_ROOT/jar;   fixture_login "$JAR"  "uid=1&auth=otp&name=Admin&role=admin"
JARE=$WIKI_ROOT/jare; fixture_login "$JARE" "uid=2&auth=otp&name=Ed&role=editor"
JARR=$WIKI_ROOT/jarr; fixture_login "$JARR" "uid=3&auth=otp&name=Reader&role=reader"
get_as "$JAR" 'api.php?action=indexfiles&space=Vault' > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Plain' > /dev/null

setfm() { post_as "$1" 'api.php?action=set_frontmatter&space=Vault' "$2"; }


section 'off by default, and a new Space writes no record at all'
res=$(get_as "$JAR" 'api.php?action=admin_space_settings')
assert_contains "a fresh Space reports off"   '"fm_edit":"off"' "$res"
new=$(post_as "$JAR" 'api.php?action=create_space' 'name=Fresh')
assert_contains "a new Space is created"      '"success":true'  "$new"
assert_contains "  with the setting off"      '"fm_edit":"off"' "$new"
assert_file_missing "and nothing is stored for it" "$WIKI_SYS/spaces.json"
# Chosen in the create dialog, it is honoured.
opt=$(post_as "$JAR" 'api.php?action=create_space' 'name=Opted&fm_edit=manual')
assert_contains "a Space can opt in at creation" '"fm_edit":"manual"' "$opt"

section 'with the Space off, the server refuses — whatever the client thinks'
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22published%22%7D')
assert_contains     "refused"            '"success":false' "$res"
assert_contains     "  and says why"     'turned off'      "$res"
assert_contains     "the file is untouched" 'status: draft' "$(cat "$WIKI_PAGES/Vault/Report.md")"

section 'turning it on is per Space'
on=$(post_as "$JAR" 'api.php?action=admin_set_space_fm_edit' 'space_name=Vault&mode=manual')
assert_contains "Vault is on"  '"mode":"manual"' "$on"
ls=$(get_as "$JAR" 'api.php?action=list_spaces')
assert_contains "list_spaces reports it"      '"fm_edit":["Opted","Vault"]' "$ls"
fixture_page 'Plain/Note.md' '# Note'
other=$(post_as "$JAR" 'api.php?action=set_frontmatter&space=Plain' 'file=Note.md&updates=%7B%22a%22%3A%22b%22%7D')
assert_contains "another Space is still off"  'turned off' "$other"
bad=$(post_as "$JAR" 'api.php?action=admin_set_space_fm_edit' 'space_name=Vault&mode=wat')
assert_contains "an unknown mode is refused"  '"success":false' "$bad"

section 'updating one field touches one line'
before=$(cat "$WIKI_PAGES/Vault/Report.md")
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22published%22%7D')
after=$(cat "$WIKI_PAGES/Vault/Report.md")
assert_contains "it succeeds"                  '"success":true'    "$res"
assert_contains "the value changed"            'status: published' "$after"
assert_contains "the comment survived"         '# a note the author left' "$after"
assert_contains "the block list survived"      '  - finance'       "$after"
assert_contains "the nested mapping survived"  '  a: 1'            "$after"
assert_contains "the quoting survived"         'quoted: "has: a colon"' "$after"
assert_contains "the body survived"            'Revenue was up.'   "$after"
# Precisely one line differs, which is the whole claim of the surgical writer.
diffs=$(diff <(printf '%s\n' "$before") <(printf '%s\n' "$after") | grep -c '^[<>]')
assert_eq "exactly one line differs" '2' "$diffs"
assert_contains "the response carries the new metadata" '"status":"published"' "$res"
assert_contains "  and a fresh size for the watcher"    '"size":'              "$res"
# Key order is untouched: title first, status still in its original position.
assert_eq "key order is unchanged" 'title status' \
    "$(printf '%s' "$after" | sed -n '2p;10p' | cut -d: -f1 | tr '\n' ' ' | sed 's/ $//')"

section 'adding and removing'
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22version%22%3A%223%22%7D')
after=$(cat "$WIKI_PAGES/Vault/Report.md")
assert_contains "a new field is added"          'version: 3'  "$after"
assert_eq "  just before the closing delimiter" 'version: 3' \
    "$(printf '%s' "$after" | grep -B1 '^---$' | tail -2 | head -1)"
res=$(setfm "$JAR" 'file=Report.md&removals=%5B%22version%22%5D')
assert_not_contains "and it can be removed"     'version:'    "$(cat "$WIKI_PAGES/Vault/Report.md")"
# A structured key cannot be *written*, but it can be removed — including its lines.
res=$(setfm "$JAR" 'file=Report.md&removals=%5B%22tags%22%5D')
after=$(cat "$WIKI_PAGES/Vault/Report.md")
assert_not_contains "a list key can be removed" 'tags:'       "$after"
assert_not_contains "  with its items"          '- finance'   "$after"
assert_contains     "  and nothing else"        '  a: 1'      "$after"

section 'a page with no block gets one'
res=$(setfm "$JAR" 'file=Bare.md&updates=%7B%22status%22%3A%22draft%22%7D')
bare=$(cat "$WIKI_PAGES/Vault/Bare.md")
assert_contains "it succeeds"          '"success":true' "$res"
assert_eq "the block opens the file"   '---'            "$(printf '%s' "$bare" | head -1)"
assert_contains "with the field"       'status: draft'  "$bare"
assert_contains "and the body is kept" 'No block here.' "$bare"

section 'what a typed value must never do'
write_page 'Vault/Report.md'
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22a%5Cnb%22%7D')
assert_contains     "a line break is refused"    'line break'  "$res"
assert_contains     "  and nothing is written"   'status: draft' "$(cat "$WIKI_PAGES/Vault/Report.md")"
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22bad%20key%3A%22%3A%22x%22%7D')
assert_contains     "an invalid field name is refused" '"success":false' "$res"
# A nested mapping parses to a *string*, so an is_array() guard would wave this through and
# the writer would drop its children. Detected from the block text instead.
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22nested%22%3A%22clobbered%22%7D')
assert_contains     "overwriting a nested value is refused" 'nested value' "$res"
assert_contains     "  so its children are intact"     '  a: 1'      "$(cat "$WIKI_PAGES/Vault/Report.md")"
# A *list*, by contrast, is editable — see the list section below. Sending a scalar for one
# converts it to a scalar, which is a deliberate edit rather than something to refuse.
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22tags%22%3A%22just%20one%22%7D')
assert_contains     "a list can be turned into a scalar" '"success":true' "$res"
assert_contains     "  and it is"                      'tags: just one' "$(cat "$WIKI_PAGES/Vault/Report.md")"
assert_not_contains "  with its items gone"            '- finance'      "$(cat "$WIKI_PAGES/Vault/Report.md")"
# And the page read tells the panel which fields it must not offer at all.
write_page 'Vault/Report.md'
got=$(get_as "$JAR" 'api.php?action=get&space=Vault&file=Report.md')
assert_contains "the read reports the nested one" '"frontmatter_nested":["nested"]' "$got"
assert_not_contains "  and not the list"          '"frontmatter_nested":["tags"' "$got"
# A value that would otherwise break the block comes back quoted, and reads back intact.
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22note%22%3A%22key%3A%20value%20%23%20hash%22%7D')
assert_contains "a dangerous value is quoted" 'note: "key: value # hash"' "$(cat "$WIKI_PAGES/Vault/Report.md")"
assert_contains "  and reads back as typed"   '"note":"key: value # hash"' "$res"

section 'a list is editable, in whatever style the file already used'
write_page 'Vault/Report.md'
printf '%s' '---
tags:
  - one
  - two
langs: [da, en]
nested:
  a: 1
---
# Lists
' > "$WIKI_PAGES/Vault/Lists.md"
get_as "$JAR" 'api.php?action=indexfiles&space=Vault' > /dev/null
lsfm() { post_as "$JAR" 'api.php?action=set_frontmatter&space=Vault' "$1"; }
# A block list stays a block list: rewriting it as `[a, b]` would be a reformat of
# somebody's file, which is the one thing this module does not do.
res=$(lsfm 'file=Lists.md&updates=%7B%22tags%22%3A%5B%22alpha%22%2C%22beta%22%5D%7D')
after=$(cat "$WIKI_PAGES/Vault/Lists.md")
assert_contains "a block list is rewritten"  '  - alpha'   "$after"
assert_contains "  keeping its style"        '  - beta'    "$after"
assert_not_contains "  not flattened to flow" 'tags: ['    "$after"
assert_contains "  and read back as a list"  '"tags":["alpha","beta"]' "$res"
# A flow list stays flow.
res=$(lsfm 'file=Lists.md&updates=%7B%22langs%22%3A%5B%22sv%22%2C%22no%22%5D%7D')
assert_contains "a flow list stays flow"     'langs: [sv, no]' "$(cat "$WIKI_PAGES/Vault/Lists.md")"
# A brand-new list key is flow, which is compact and guesses at no indentation.
res=$(lsfm 'file=Lists.md&updates=%7B%22authors%22%3A%5B%22Ada%22%2C%22Grace%22%5D%7D')
assert_contains "a new list key is flow"     'authors: [Ada, Grace]' "$(cat "$WIKI_PAGES/Vault/Lists.md")"
# An item that would break the block comes back quoted.
res=$(lsfm 'file=Lists.md&updates=%7B%22langs%22%3A%5B%22has%3A%20colon%22%2C%22ok%22%5D%7D')
assert_contains "a dangerous item is quoted" 'langs: ["has: colon", ok]' "$(cat "$WIKI_PAGES/Vault/Lists.md")"
assert_contains "  and reads back intact"    '"langs":["has: colon","ok"]' "$res"
# A nested mapping is still refused, either way round.
res=$(lsfm 'file=Lists.md&updates=%7B%22nested%22%3A%5B%22x%22%5D%7D')
assert_contains "a nested value refuses a list"   'nested value' "$res"
res=$(lsfm 'file=Lists.md&updates=%7B%22nested%22%3A%22x%22%7D')
assert_contains "and refuses a scalar"            'nested value' "$res"
assert_contains "  so its children are intact"    '  a: 1'       "$(cat "$WIKI_PAGES/Vault/Lists.md")"
# An item with a line break cannot slip in through a list either.
res=$(lsfm 'file=Lists.md&updates=%7B%22langs%22%3A%5B%22a%5Cnb%22%5D%7D')
assert_contains "a line break in an item is refused" 'line break' "$res"
# Emptying a list, and the page read telling the panel what is nested.
res=$(lsfm 'file=Lists.md&updates=%7B%22authors%22%3A%5B%5D%7D')
assert_contains "a list can be emptied"      'authors: []'  "$(cat "$WIKI_PAGES/Vault/Lists.md")"
got=$(get_as "$JAR" 'api.php?action=get&space=Vault&file=Lists.md')
assert_contains "only nested keys are reported" '"frontmatter_nested":["nested"]' "$got"

section 'a write that changes nothing is not a write'
write_page 'Vault/Report.md'
mtime_before=$(stat -c %Y "$WIKI_PAGES/Vault/Report.md")
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22draft%22%7D')
assert_contains "reported as unchanged" '"unchanged":true' "$res"
assert_eq "the file was not touched" "$mtime_before" "$(stat -c %Y "$WIKI_PAGES/Vault/Report.md")"

section 'a blind overwrite of a file that moved on is refused'
size=$(wc -c < "$WIKI_PAGES/Vault/Report.md" | tr -d ' ')
mt=$(stat -c %Y "$WIKI_PAGES/Vault/Report.md")
ok=$(setfm "$JAR" "file=Report.md&updates=%7B%22status%22%3A%22live%22%7D&base_mtime=$mt&base_size=$size")
assert_contains "a matching baseline is accepted" '"success":true' "$ok"
stale=$(setfm "$JAR" "file=Report.md&updates=%7B%22status%22%3A%22other%22%7D&base_mtime=$mt&base_size=$((size + 99))")
assert_contains "a stale baseline is refused"     '"stale":true'   "$stale"
assert_contains "  and nothing is written"        'status: live'   "$(cat "$WIKI_PAGES/Vault/Report.md")"

section 'the ordinary guards apply, because it is an ordinary content write'
res=$(setfm "$JARR" 'file=Report.md&updates=%7B%22status%22%3A%22reader%22%7D')
assert_contains     "a reader cannot"        'Readers cannot' "$res"
assert_not_contains "  and did not"          'status: reader' "$(cat "$WIKI_PAGES/Vault/Report.md")"
res=$(setfm "$JARE" 'file=Report.md&updates=%7B%22status%22%3A%22editor%22%7D')
assert_contains     "an editor can"          '"success":true' "$res"
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Vault&readonly=1' > /dev/null
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22frozen%22%7D')
assert_contains     "a frozen Space refuses" 'read-only'      "$res"
assert_not_contains "  and did not"          'status: frozen' "$(cat "$WIKI_PAGES/Vault/Report.md")"
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Vault&readonly=0' > /dev/null

section 'and it is only for Markdown'
fixture_page 'Vault/Data.json' '{"a":1}'
get_as "$JAR" 'api.php?action=indexfiles&space=Vault' > /dev/null
res=$(setfm "$JAR" 'file=Data.json&updates=%7B%22status%22%3A%22x%22%7D')
assert_contains "a .json page is refused" 'Only Markdown' "$res"

section 'the edit is recorded and indexed like any other page write'
post_as "$JAR" 'api.php?action=admin_set_audit_enabled' 'enabled=1' > /dev/null
setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22audited%22%7D' > /dev/null
log=$(get_as "$JAR" "api.php?action=admin_get_audit_entries&date=$(date +%F)")
assert_contains "the audit log has it"    'set_frontmatter' "$log"
assert_contains "  as a page update"      '"action":"update"' "$log"
assert_contains "  naming the page"       'Report.md'       "$log"
# updateModified ran, so the index agrees the page changed.
assert_contains "the index was touched"   '"updatedBy"'     "$(cat "$WIKI_PAGES/Vault/index.json")"


# ══ Automatic stamping ═══════════════════════════════════════════════════════════
#
# index.json is the source of truth; the block is a projection of it into the file. So
# `updated`/`updatedBy` are always rewritten while `created`/`createdBy` are filled only
# when the file claims neither — an imported note's own `created` is real information, and
# pairing our author with their date would invent a combination that never existed.

section 'stamping is off until the Space asks for it'
write_page 'Vault/Report.md'
post_as "$JAR" 'api.php?action=save&space=Vault&file=Report.md' '' > /dev/null
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Quarterly Report

Edited once.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Report.md" > /dev/null
assert_not_contains "no stamp appears" 'updatedBy' "$(cat "$WIKI_PAGES/Vault/Report.md")"

on=$(post_as "$JAR" 'api.php?action=admin_set_space_fm_autostamp' 'space_name=Vault&mode=on')
assert_contains "it can be switched on"     '"mode":"on"' "$on"
ls=$(get_as "$JAR" 'api.php?action=list_spaces')
assert_contains "list_spaces reports it"    '"fm_autostamp":["Vault"]' "$ls"
ss=$(get_as "$JAR" 'api.php?action=admin_space_settings')
assert_contains "the dialog sees it"        '"fm_autostamp":"on"' "$ss"

section 'a save stamps the four fields'
write_page 'Vault/Report.md'
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Quarterly Report

Edited with stamping on.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Report.md" > "$WIKI_ROOT/save.json"
after=$(cat "$WIKI_PAGES/Vault/Report.md")
assert_contains "updated was written"        'updated: 20'     "$after"
assert_contains "  in full ISO-8601"          "updated: $(date +%Y-%m-%d)T" "$after"
assert_contains "updatedBy names the saver"  'updatedBy: Admin' "$after"
assert_contains "created came from the index" 'created: 20'     "$after"
assert_contains "the author's fields survive" '# a note the author left' "$after"
assert_contains "  and the list"              '  - finance'     "$after"
assert_contains "  and the body"              'Edited with stamping on.' "$after"
# The response's size is what the open-page watcher re-baselines from; report the
# pre-stamp length and it reloads the page under the author on its first poll.
real=$(wc -c < "$WIKI_PAGES/Vault/Report.md" | tr -d ' ')
assert_contains "the reported size is post-stamp" "\"size\":$real" "$(cat "$WIKI_ROOT/save.json")"

section 'a page with no block gets one'
fixture_page 'Vault/Fresh.md' '# Fresh

No block.'
get_as "$JAR" 'api.php?action=indexfiles&space=Vault' > /dev/null
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Fresh

Now edited.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Fresh.md" > /dev/null
fresh=$(cat "$WIKI_PAGES/Vault/Fresh.md")
assert_eq "the block opens the file" '---' "$(printf '%s' "$fresh" | head -1)"
assert_contains "with the stamp"      'updatedBy: Admin' "$fresh"
assert_contains "and the body kept"   'Now edited.'      "$fresh"

section 'a new page is stamped when it is created'
post_as "$JAR" 'api.php?action=create_file&space=Vault' 'path=Created.md' > /dev/null
created=$(cat "$WIKI_PAGES/Vault/Created.md")
assert_contains "created is there"    'created: 20'       "$created"
assert_contains "createdBy is there"  'createdBy: Admin'  "$created"
assert_contains "and the heading"     '# Created'         "$created"

section 'an imported note keeps its own origin'
printf '%s' '---
title: Imported
created: 2019-04-01
---
# Imported

From elsewhere.
' > "$WIKI_PAGES/Vault/Imported.md"
get_as "$JAR" 'api.php?action=indexfiles&space=Vault' > /dev/null
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Imported

Edited here.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Imported.md" > /dev/null
imp=$(cat "$WIKI_PAGES/Vault/Imported.md")
assert_contains     "their created is untouched"   'created: 2019-04-01' "$imp"
assert_not_contains "and no author is invented"    'createdBy'           "$imp"
assert_contains     "but updated is maintained"    'updatedBy: Admin'    "$imp"

section 'a save that changes nothing does not churn the block'
stamp_before=$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")
sleep 1
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Quarterly Report

Edited with stamping on.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Report.md" > /dev/null
assert_eq "updated did not move" "$stamp_before" "$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")"
# …while a real edit does move it.
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Quarterly Report

Edited again, for real.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Report.md" > /dev/null
if [ "$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")" != "$stamp_before" ]; then
    _pass "a real edit does move it"
else
    _fail "a real edit does move it" "the stamp is stuck at $stamp_before"
fi

section 'a hand-edit cannot fight the wiki for those four fields'
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22updatedBy%22%3A%22Someone%20Else%22%7D')
assert_contains "the managed field is refused" 'maintained by the wiki' "$res"
assert_not_contains "  and not written"        'Someone Else' "$(cat "$WIKI_PAGES/Vault/Report.md")"
# An ordinary field still works — and the edit stamps, because editing metadata is
# editing the page.
before_upd=$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")
sleep 1
res=$(setfm "$JAR" 'file=Report.md&updates=%7B%22status%22%3A%22review%22%7D')
assert_contains "an ordinary field still saves" '"success":true' "$res"
assert_contains "  the value landed"            'status: review' "$(cat "$WIKI_PAGES/Vault/Report.md")"
if [ "$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")" != "$before_upd" ]; then
    _pass "  and the edit is stamped"
else
    _fail "  and the edit is stamped" "updated did not move"
fi
assert_contains "the panel is told which fields are the wiki's" '"frontmatter_managed":["created","createdBy","updated","updatedBy"]' "$res"
got=$(get_as "$JAR" 'api.php?action=get&space=Vault&file=Report.md')
assert_contains "and the page read says so too" '"frontmatter_managed":["created","createdBy","updated","updatedBy"]' "$got"

section 'an AI write is stamped too, and named'
cat > "$WIKI_ROOT/ai.php" <<'DRIVER'
<?php
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "settings.php";
require_once "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Vault";
$ix = new PageIndexer($space_dir);
$ai = ["uid" => -9, "name" => "gpt120-think", "role" => "editor", "is_ai" => true];
echo execute_ai_tool("wiki_write_page",
    ["path" => "Report.md", "content" => "# Quarterly Report

Rewritten by a model.
"],
    $ai, $ix, $space_dir), "
";
DRIVER
cp "$WIKI_ROOT/ai.php" "$WIKI_APP/__ai.php"
(cd "$WIKI_APP" && php __ai.php) > /dev/null 2>&1
ai_out=$(cat "$WIKI_PAGES/Vault/Report.md")
assert_contains "the AI's edit landed"       'Rewritten by a model' "$ai_out"
assert_contains "and it is credited"         'updatedBy: gpt120-think' "$ai_out"
assert_contains "the author's fields survive" '# a note the author left' "$ai_out"

section 'switching stamping off stops it'
post_as "$JAR" 'api.php?action=admin_set_space_fm_autostamp' 'space_name=Vault&mode=off' > /dev/null
prev=$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")
sleep 1
curl -s -b "$JAR" -c "$JAR" -X POST --data-binary '# Quarterly Report

Edited with stamping off.
' "$WIKI_URL/api.php?action=save&space=Vault&file=Report.md" > /dev/null
assert_eq "the stamp is frozen where it was" "$prev" "$(grep '^updated:' "$WIKI_PAGES/Vault/Report.md")"
assert_contains "  but the edit still saved" 'Edited with stamping off.' "$(cat "$WIKI_PAGES/Vault/Report.md")"

section 'and the other Space was never involved'
assert_not_contains "no stamp leaked into it" 'updatedBy' "$(cat "$WIKI_PAGES/Plain/Note.md")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
