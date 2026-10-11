#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The start page every new Space gets: defaults/Main.md, written by wiki_scaffold_space()
# for a fresh install's Main Space and for each Space created afterwards.
#
# The failure this guards against is silent. When defaults/Main.md cannot be read, the
# scaffold falls back to a one-line welcome rather than failing — so a checkout without the
# file (it was untracked for a while, and CI tests a checkout) produces a working wiki with
# a bare start page, and nothing else notices.
#
# And the start page is stamped when the new Space has automatic front-matter stamping on.
# It used not to be: create_space saved the Space's settings *after* scaffolding it, and the
# scaffold wrote the file directly rather than through the stamping code — so the first page
# of a stamped Space was the one page without the four fields.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_users '{"users":[{"uid":1,"name":"Ada Admin","role":"admin","auth":"otp","email":"a@example.com"}]}'
# A title the template cannot contain by accident, so the substitution is what puts it there.
sed -i "s#^define('APP_TITLE'.*#define('APP_TITLE', 'Zebra Notes');#" "$WIKI_APP/config.php"
J=$WIKI_ROOT/jar; fixture_login "$J" "uid=1&auth=otp&name=Ada%20Admin&role=admin"

section 'a fresh install gets the tour as its start page'
assert_file_exists "the template is in the application"   "$WIKI_APP/defaults/Main.md"
get_as "$J" 'api.php?action=list_spaces' > /dev/null
assert_file_exists "the Main Space has a start page"      "$WIKI_PAGES/Main/Main.md"
page=$(cat "$WIKI_PAGES/Main/Main.md")
assert_eq "it is the template, not the fallback" \
    "$(sed 's/%APP_TITLE%/Zebra Notes/g' "$WIKI_APP/defaults/Main.md")" "$page"
assert_contains "titled with this wiki's APP_TITLE"       '# Welcome to Zebra Notes' "$page"
assert_not_contains "no placeholder left behind"          '%APP_TITLE%' "$page"
assert_contains "indexed like any page"                   'Main.md' "$(cat "$WIKI_PAGES/Main/index.json")"

section 'the template carries no page of its own'
tpl=$(cat "$WIKI_APP/defaults/Main.md")
# Front matter copied from a real page would stamp every new install's start page with
# somebody else's name and dates.
assert_eq "it does not start with front matter"           '# Welcome to %APP_TITLE%' "$(head -1 "$WIKI_APP/defaults/Main.md")"
assert_not_contains "no createdBy"                        'createdBy:' "$tpl"

section 'a Space created later gets it too'
r=$(post_as "$J" 'api.php?action=create_space' 'name=Second')
assert_contains "created"                                 '"success":true' "$r"
assert_eq "same start page, and no front matter with stamping off" \
    "$page" "$(cat "$WIKI_PAGES/Second/Main.md")"

section 'a Space created with stamping on gets a stamped start page'
r=$(post_as "$J" 'api.php?action=create_space' 'name=Stamped&fm_autostamp=on')
assert_contains "created with stamping on"                '"fm_autostamp":"on"' "$r"
st=$(cat "$WIKI_PAGES/Stamped/Main.md")
assert_eq "it opens with a front-matter block"            '---' "$(head -1 "$WIKI_PAGES/Stamped/Main.md")"
assert_contains "created"                                 'created: 20' "$st"
assert_contains "createdBy, the person who made the Space" 'createdBy: Ada Admin' "$st"
assert_contains "updated"                                 'updated: 20' "$st"
assert_contains "updatedBy"                               'updatedBy: Ada Admin' "$st"
assert_contains "and then the tour"                       '# Welcome to Zebra Notes' "$st"
r=$(get_as "$J" 'api.php?action=get&space=Stamped&file=Main.md')
assert_contains "the Metadata panel sees the fields"      '"createdBy":"Ada Admin"' "$r"

section 'a bad setting is refused before anything is created'
r=$(post_as "$J" 'api.php?action=create_space' 'name=Broken&fm_autostamp=sometimes')
assert_contains "refused"                                 '"success":false' "$r"
assert_file_missing "no half-made Space left behind"      "$WIKI_PAGES/Broken"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
