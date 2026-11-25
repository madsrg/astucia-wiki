#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# AI user names are unique among AI users, ignoring case — on create and on rename. A mention
# is matched case-insensitively (wiki_match_ai_mention), so two AI users called "Atlas" and
# "atlas" would answer to the same #Atlas and only one would ever be reached. Saving was
# possible until the check in admin_save_ai_user; every refusal here is paired with a save
# that must still go through.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'
AD=$WIKI_ROOT/jar-a
fixture_login "$AD" "uid=1&sub=s1&name=Admin&role=admin"

CONN=$(post_as "$AD" 'api.php?action=admin_save_llm_connection' 'name=Main&provider=openai&api_url=&api_key=sk-x&extra_headers=[]' \
       | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"] ?? "";')
cfg=$(python3 -c 'import json,sys,urllib.parse;print(urllib.parse.quote(json.dumps({"connection_id":sys.argv[1],"model":"m"})))' "$CONN")
save() { post_as "$AD" 'api.php?action=admin_save_ai_user' "role=editor&spaces=null&ai_config=$cfg&$1"; }
uid_of() { php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d["users"] as $u) if (!empty($u["is_ai"]) && $u["name"] === $argv[2]) { echo $u["uid"]; exit; }' "$WIKI_SYS/users.json" "$1"; }
names() { php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode(",", array_map(fn($u) => $u["name"], array_values(array_filter($d["users"], fn($u) => !empty($u["is_ai"])))));' "$WIKI_SYS/users.json"; }

section "creating"
assert_contains "Atlas is created"  '"success":true' "$(save 'name=Atlas')"
assert_contains "Zeus is created"   '"success":true' "$(save 'name=Zeus')"
r=$(save 'name=Atlas')
assert_contains "a second Atlas is refused" 'already called \"Atlas\"' "$r"
r=$(save 'name=%20atlas%20')
assert_contains "so is atlas, in another case and with spaces" 'already called \"Atlas\"' "$r"
assert_eq "still two AI users" 'Atlas,Zeus' "$(names)"

section "renaming"
ZEUS=$(uid_of Zeus); ATLAS=$(uid_of Atlas)
r=$(save "uid=$ZEUS&name=Atlas")
assert_contains "Zeus cannot become Atlas" 'already called \"Atlas\"' "$r"
r=$(save "uid=$ZEUS&name=ATLAS")
assert_contains "nor ATLAS"                'already called \"Atlas\"' "$r"
assert_eq "nothing renamed" 'Atlas,Zeus' "$(names)"
assert_contains "saving Atlas under its own name is fine" '"success":true' "$(save "uid=$ATLAS&name=Atlas")"
assert_contains "and changing only its case"              '"success":true' "$(save "uid=$ATLAS&name=ATLAS")"
assert_contains "Zeus can take a free name"               '"success":true' "$(save "uid=$ZEUS&name=Hermes")"
assert_eq "renamed" 'ATLAS,Hermes' "$(names)"
assert_contains "and the old name is free again"           '"success":true' "$(save 'name=Zeus')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
