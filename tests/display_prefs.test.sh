#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# My Preferences' font and two sizes (accounts.php → wiki_display_prefs): the interface size
# (the root font size) and the text size (content, relative to it), both percentages in steps
# of 5. They replaced one size in points, which an account that has not saved since still
# carries — so the migration is read-time, and a save is what retires `fontSize`.
#
# Asserted where it lands: what user_get_preferences answers, what users.json holds, and the
# CSS variables index.php writes on <html>, which is all the browser ever reads.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc","fontFamily":"serif","fontSize":"14pt"}]}'
ED=$WIKI_ROOT/jar-e; ADMIN=$WIKI_ROOT/jar-a
fixture_login "$ED"    "uid=2&sub=s2&name=Ed&role=editor"
fixture_login "$ADMIN" "uid=1&sub=s1&name=Admin&role=admin"

field() { php -r '$u = json_decode(file_get_contents($argv[1]), true)["users"][1]; echo json_encode($u[$argv[2]] ?? null);' "$WIKI_SYS/users.json" "$1"; }
html_tag() { get_as "$1" 'index.php' | tr -d '\r' | grep -m1 '^<html'; }
save() { post_as "$ED" 'api.php?action=user_save_preferences' "email=&dailyDigest=0&notifyAgentJobs=0&$1"; }

section "an account from before the two sizes"
r=$(get_as "$ED" 'api.php?action=user_get_preferences')
assert_contains "14pt reads as a 125% interface" '"uiScale":125'  "$r"
assert_contains "and text at the 90% default"    '"textScale":90' "$r"
assert_contains "the font is kept"               '"fontFamily":"serif"' "$r"
assert_not_contains "points are no longer answered" '"fontSize"' "$r"

section "a session without any of it: the defaults on <html>"
h=$(html_tag "$ADMIN")
assert_contains "interface at 100%" '--ui-scale:1;'   "$h"
assert_contains "text at 90%"       '--text-scale:0.9"' "$h"
assert_contains "the system's sans" 'data-font="sans"' "$h"
assert_not_contains "no point size" 'data-font-size' "$h"

section "saving the two sizes and the bundled font"
r=$(save 'fontFamily=source&uiScale=90&textScale=85')
assert_contains "saved" '"success":true' "$r"
assert_eq "uiScale stored"           '90'       "$(field uiScale)"
assert_eq "textScale stored"         '85'       "$(field textScale)"
assert_eq "fontFamily stored"        '"source"' "$(field fontFamily)"
assert_eq "the old point size is gone" 'null'   "$(field fontSize)"
h=$(html_tag "$ED")
assert_contains "the session follows: interface" '--ui-scale:0.9;'     "$h"
assert_contains "and text"                       '--text-scale:0.85"'  "$h"
assert_contains "and the font"                   'data-font="source"'  "$h"

section "anything off the steps keeps what is stored"
save 'fontFamily=comic&uiScale=93&textScale=500' > /dev/null
assert_eq "93 is not a step"          '90'     "$(field uiScale)"
assert_eq "500 is out of range"       '85'     "$(field textScale)"
assert_eq "an unknown font is sans"   '"sans"' "$(field fontFamily)"
# 'system' named the system font for a while before it became 'sans' again.
python3 -c "import json;p='$WIKI_SYS/users.json';d=json.load(open(p));d['users'][1]['fontFamily']='system';json.dump(d,open(p,'w'))"
assert_contains "a stored 'system' reads as sans" '"fontFamily":"sans"' "$(get_as "$ED" 'api.php?action=user_get_preferences')"
save 'fontFamily=sans&uiScale=1e2&textScale=-70' > /dev/null
assert_eq "1e2 is not a number here"  '90'     "$(field uiScale)"
assert_eq "nor a negative"            '85'     "$(field textScale)"

section "an older client that sends only points"
save 'fontFamily=sans&fontSize=16pt' > /dev/null
assert_eq "the sizes are kept"     '90'   "$(field uiScale)"
assert_eq "and points not stored"  'null' "$(field fontSize)"

section "the range ends are allowed"
save 'fontFamily=sans&uiScale=150&textScale=70' > /dev/null
assert_eq "150% interface" '150' "$(field uiScale)"
assert_eq "70% text"       '70'  "$(field textScale)"

section "the bundled font is served"
assert_eq "upright" '200' "$(status_as "$ED" 'fonts/source-sans-3/SourceSans3-Upright.woff2')"
assert_eq "italic"  '200' "$(status_as "$ED" 'fonts/source-sans-3/SourceSans3-Italic.woff2')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
