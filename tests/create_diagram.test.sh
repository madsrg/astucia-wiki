#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# New → Diagram and its templates.
#
# Two things used to go wrong. "Blank diagram" sent no template, which the server read as
# "default" — so on a Space whose default.drawio had been customised, blank meant the house
# style. And a Space with no default.drawio refused every new diagram, which the client then
# swallowed without a message.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start off || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_space Bare
curl -s "$WIKI_URL/api.php?action=list_spaces" > /dev/null
# Main's default is customised: one shape that a blank diagram must not have.
mkdir -p "$WIKI_PAGES/Main/templates"
printf '%s' '<mxfile><diagram name="House"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/><mxCell id="house-style" value="Logo" vertex="1" parent="1"/></root></mxGraphModel></diagram></mxfile>' \
    > "$WIKI_PAGES/Main/templates/default.drawio"
rm -f "$WIKI_PAGES/Bare/templates/default.drawio"

mk() { curl -s -X POST "$WIKI_URL/api.php?action=create_diagram&space=$1" --data "path=$2${3+&template=$3}"; }

section 'templates'
assert_contains "no template: the Space's default (control)" '"success":true' "$(mk Main a.drawio)"
assert_contains "  …which is the customised one"             'house-style' "$(cat "$WIKI_PAGES/Main/a.drawio")"
assert_contains "Blank diagram (template='') succeeds"       '"success":true' "$(mk Main b.drawio '')"
assert_not_contains "  …and really is blank"                 'house-style' "$(cat "$WIKI_PAGES/Main/b.drawio")"
assert_contains "  …but a valid diagram"                     '<mxGraphModel' "$(cat "$WIKI_PAGES/Main/b.drawio")"

section 'a Space without default.drawio'
assert_file_missing "no default template there (control)"   "$WIKI_PAGES/Bare/templates/default.drawio"
assert_contains "a new diagram is still created"            '"success":true' "$(mk Bare c.drawio)"
assert_contains "  …as an empty one"                        '<mxGraphModel' "$(cat "$WIKI_PAGES/Bare/c.drawio")"
assert_contains "  …and indexed"                            'c.drawio' "$(cat "$WIKI_PAGES/Bare/index.json")"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
