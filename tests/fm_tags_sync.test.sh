#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Front-matter tag sync (Space settings → Content → Tags in front matter).
#
# The rules worth a test each: it is per Space and off by default; it writes a delta, so a
# tag only the block has survives every sync; it keeps the key's own form (block list,
# flow list, comma string); it keeps the file's mtime; `tags` becomes a managed field;
# a taxonomy rename follows into the blocks — except in a frozen Space.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Vault; fixture_space Plain
fixture_page 'Vault/a.md' '# A'
fixture_page 'Vault/b.md' '---
title: B
tags:
  - imported
  - Extra
---
# B'
fixture_page 'Vault/c.md' '---
tags: one, two
---
# C'
fixture_page 'Vault/d.md' '# D'
fixture_page 'Vault/e.md' '# E'
fixture_page 'Plain/p.md' '# P'
fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Ed","role":"editor","auth":"oidc"}
]}'
ADMIN=$WIKI_ROOT/jar-admin; ED=$WIKI_ROOT/jar-ed
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=sub-2&name=Ed&role=editor"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Vault' > /dev/null
get_as "$ADMIN" 'api.php?action=indexfiles&space=Plain' > /dev/null

id_of() {
    python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == sys.argv[2]))' "$WIKI_PAGES/$1/index.json" "$2"
}
enc() { python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$1"; }
tag() { post_as "$ED" "api.php?action=update_tags&space=$1" "id=$(id_of "$1" "$2")&tags=$(enc "$3")"; }
file() { cat "$WIKI_PAGES/$1"; }
mtime() { stat -c %Y "$WIKI_PAGES/$1"; }
# Back-date every file, so "mtime kept" cannot pass by the write landing in the same second.
touch -d '2020-01-01 00:00:00' "$WIKI_PAGES"/Vault/*.md "$WIKI_PAGES"/Plain/*.md

section 'off by default'
tag Plain p.md '["X"]' > /dev/null
assert_eq "a Space without it leaves the file alone" '# P' "$(file Plain/p.md)"
tag Vault d.md '["Before"]' > /dev/null
assert_eq "  including the Space it is about to be turned on for" '# D' "$(file Vault/d.md)"

section 'the setting'
r=$(post_as "$ED" 'api.php?action=admin_set_space_fm_tags_sync' 'space_name=Vault&mode=on')
assert_not_contains "an editor cannot turn it on"  '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_set_space_fm_tags_sync' 'space_name=Vault&mode=on')
assert_contains "an admin can"                      '"mode":"on"' "$r"
assert_contains "list_spaces reports it"            '"fm_tags_sync":["Vault"]' "$(get_as "$ED" 'api.php?action=list_spaces')"
assert_contains "the settings dialog sees it"       '"name":"Vault"' "$(get_as "$ADMIN" 'api.php?action=admin_space_settings')"
assert_contains "  as on"                           '"fm_tags_sync":"on"' "$(get_as "$ADMIN" 'api.php?action=admin_space_settings')"
assert_eq "turning it on rewrites nothing"          '# D' "$(file Vault/d.md)"

section 'a page with no block'
before=$(mtime Vault/a.md)
r=$(tag Vault a.md '["X","Y Z"]')
assert_contains "the tag write succeeds"            '"success":true' "$r"
assert_contains "  the block is created"            'tags: [X, Y Z]' "$(file Vault/a.md)"
assert_contains "  above the body"                  $'---\n# A' "$(file Vault/a.md)"
assert_eq       "  and the mtime is kept"           "$before" "$(mtime Vault/a.md)"
assert_contains "the response carries the new size" "\"size\":$(wc -c < "$WIKI_PAGES/Vault/a.md")" "$r"
assert_contains "  and the block"                   '"frontmatter":{"tags":["X","Y Z"]}' "$r"
tag Vault a.md '["X"]' > /dev/null
assert_contains "removing a tag removes it from the block" 'tags: [X]' "$(file Vault/a.md)"
tag Vault a.md '[]' > /dev/null
assert_not_contains "removing the last drops the key" 'tags' "$(file Vault/a.md)"

section 'a block that already has tags'
tag Vault b.md '["New"]' > /dev/null
b=$(file Vault/b.md)
assert_contains "the block list keeps its form"     $'  - Extra\n  - New' "$b"
assert_contains "  the tags only the block had stay" '  - imported' "$b"
assert_contains "  and other fields are untouched"  'title: B' "$b"
tag Vault b.md '["Other"]' > /dev/null
b=$(file Vault/b.md)
assert_not_contains "a removed tag goes"            '- New' "$b"
assert_contains "  while the block's own tags stay" '  - imported' "$b"
tag Vault c.md '["three"]' > /dev/null
assert_contains "a comma-separated string stays a string" 'tags: one, two, three' "$(file Vault/c.md)"

section 'tags is a managed field'
post_as "$ADMIN" 'api.php?action=admin_set_space_fm_edit' 'space_name=Vault&mode=manual' > /dev/null
r=$(get_as "$ED" 'api.php?action=get&space=Vault&file=b.md')
assert_contains "the get response says so"         '"frontmatter_managed":["tags"]' "$r"
r=$(post_as "$ED" 'api.php?action=set_frontmatter&space=Vault' "file=b.md&updates=$(enc '{"tags":["hand"]}')&removals=%5B%5D")
assert_contains "set_frontmatter refuses it"        'maintained by the wiki' "$r"
r=$(post_as "$ED" 'api.php?action=set_frontmatter&space=Vault' "file=b.md&updates=$(enc '{"title":"B2"}')&removals=%5B%5D")
assert_contains "  while other fields still edit"  '"success":true' "$r"
r=$(get_as "$ED" 'api.php?action=get&space=Plain&file=p.md')
assert_not_contains "a Space without it manages nothing" '"frontmatter_managed":[' "$r"

section 'writing a page'"'"'s tags into its block on request'
r=$(post_as "$ED" 'api.php?action=sync_fm_tags&space=Vault' 'file=d.md')
assert_contains "the tags from before the setting are written" 'tags: [Before]' "$(file Vault/d.md)"
assert_contains "  and the response carries the block" '"tags":["Before"]' "$r"
r=$(post_as "$ED" 'api.php?action=sync_fm_tags&space=Plain' 'file=p.md')
assert_contains "a Space without it refuses"       'does not keep tags' "$r"
assert_eq       "  and leaves the file"            '# P' "$(file Plain/p.md)"

section 'a taxonomy rename follows into the blocks'
tag Vault e.md '["Dept/Fin"]' > /dev/null
post_as "$ADMIN" 'api.php?action=admin_taxonomy_node' 'op=update&path=Dept&name=Department' > /dev/null
assert_contains "the block is retagged"            'tags: [Department/Fin]' "$(file Vault/e.md)"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Vault&readonly=1' > /dev/null
post_as "$ADMIN" 'api.php?action=admin_taxonomy_node' 'op=update&path=Department&name=Dept2' > /dev/null
assert_contains "in a frozen Space the index follows" '"Dept2\/Fin"' "$(cat "$WIKI_PAGES/Vault/index.json")"
assert_contains "  but the file is left alone"     'tags: [Department/Fin]' "$(file Vault/e.md)"
post_as "$ADMIN" 'api.php?action=admin_set_space_readonly' 'space_name=Vault&readonly=0' > /dev/null

section 'a refused tag writes nothing'
post_as "$ADMIN" 'api.php?action=admin_taxonomy_mode' 'mode=controlled' > /dev/null
cp "$WIKI_PAGES/Vault/a.md" "$WIKI_ROOT/a.before"
r=$(tag Vault a.md '["Not A Term"]')
assert_contains "the tag is refused"               '"refused":true' "$r"
assert_eq       "  and the file is unchanged"      "$(cat "$WIKI_ROOT/a.before")" "$(file Vault/a.md)"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
