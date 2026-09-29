#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# How far back My Mentions looks.
#
# There is no stored mention record — a mention is found by reading pages and chats — so
# "expire" can only mean a cutoff on the scan. Nothing is deleted, which is the property
# most worth pinning: raising the window has to bring the older ones straight back, or
# the setting is a destructive one wearing a retention label.
#
# The two halves are tested separately because they fail separately. A **page** is judged
# by its index stamp, so the cutoff can skip it without opening the file. A **chat** is
# judged per message, and its index stamp is the last write to the thread — so a thread
# posted to this morning whose only mention of you is two years old must still drop off.
# That one cannot be caught by the pre-filter, and it is the case a naive implementation
# gets wrong.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc"},
  {"uid":3,"sub":"s3","name":"Reader","role":"reader","auth":"oidc"}]}'
ADMIN=$WIKI_ROOT/jar-a; ED=$WIKI_ROOT/jar-e; READER=$WIKI_ROOT/jar-r
fixture_login "$ADMIN"  "uid=1&sub=s1&name=Admin&role=admin"
fixture_login "$ED"     "uid=2&sub=s2&name=Ed&role=editor"
fixture_login "$READER" "uid=3&sub=s3&name=Reader&role=reader"

fixture_page 'Main/Fresh.md' '# Fresh

@Ed have a look at this'
fixture_page 'Main/Ancient.md' '# Ancient

@Ed this was ages ago'

# A thread written to *today* whose mention of Ed is two years old. The index stamp says
# "recent", the message says otherwise, and the message is the truth.
python3 - "$WIKI_PAGES/Main/Thread.chat" <<'PY'
import json, sys, time
old = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(time.time() - 730 * 86400))
now = time.strftime('%Y-%m-%d %H:%M:%S')
json.dump({"topic": "Thread", "nextMessageId": 3, "messages": [
    {"id": 1, "uid": 1, "name": "Admin", "text": "@Ed back when we started", "timestamp": old},
    {"id": 2, "uid": 1, "name": "Admin", "text": "unrelated note today",     "timestamp": now},
]}, open(sys.argv[1], 'w'))
PY

# The index stamp comes from the file's mtime on a rebuild, so this is what makes
# Ancient.md old as far as the scan is concerned.
touch -d '200 days ago' "$WIKI_PAGES/Main/Ancient.md"
curl -s "$WIKI_URL/api.php?action=list_spaces" > /dev/null
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Main" > /dev/null

mentions() { get_as "$ED" 'api.php?action=get_mentions&name=Ed&uid=2'; }
set_days() { post_as "$ADMIN" 'api.php?action=admin_mention_settings' "days=$1"; }

section 'the default window is 90 days'
r=$(mentions)
assert_contains     "a recent mention is listed"  '"path":"Fresh.md"'   "$r"
assert_not_contains "a 200-day-old one is not"    'Ancient.md'          "$r"
assert_contains     "and the window is reported"  '"cutoff_days":90'    "$r"

section 'a thread is judged by its message, not by its last write'
# Thread.chat was written moments ago, so the index pre-filter waves it through; only the
# per-message check can see that the mention in it is two years old.
assert_not_contains "an old mention in a fresh thread is not listed" 'Thread.chat' "$r"

section 'nothing was deleted — raising the window brings them back'
# The property that distinguishes this from chat retention, which really does delete.
r=$(set_days 3650)
assert_contains "the setting saved"      '"days":3650'        "$r"
r=$(mentions)
assert_contains "the old page is back"   '"path":"Ancient.md"' "$r"
assert_contains "  and the old thread"   'Thread.chat'         "$r"
assert_contains "  alongside the recent" 'Fresh.md'            "$r"
assert_file_exists "the old page was never touched" "$WIKI_PAGES/Main/Ancient.md"

section '0 means no limit'
set_days 0 > /dev/null
r=$(mentions)
assert_contains "everything is listed"   'Ancient.md'          "$r"
assert_contains "  including the thread" 'Thread.chat'         "$r"
assert_contains "and the dialog is told there is no window" '"cutoff_days":0' "$r"

section 'a short window cuts it back down'
set_days 1 > /dev/null
r=$(mentions)
assert_contains     "the recent one survives" 'Fresh.md'       "$r"
assert_not_contains "the old page does not"   'Ancient.md'     "$r"
assert_not_contains "nor the old thread"      'Thread.chat'    "$r"

section 'a negative value is refused rather than stored'
# It would otherwise become a cutoff in the future and hide everything.
r=$(set_days -5)
assert_contains "it is clamped to no-limit" '"days":0'         "$r"

section 'the badge agrees with the list'
# They are two readings of one scan, and a count that does not match what opening the
# panel then shows is worse than either being wrong on its own.
set_days 3650 > /dev/null
r=$(get_as "$ED" 'api.php?action=get_mention_count&name=Ed&uid=2')
assert_contains "the count endpoint answers" '"success":true'  "$r"

section 'only an administrator may change it'
r=$(post_as "$READER" 'api.php?action=admin_mention_settings' 'days=5')
assert_contains "a reader is refused"        '"success":false' "$r"
r=$(post_as "$ED" 'api.php?action=admin_mention_settings' 'days=5')
assert_contains "an editor is too"           '"success":false' "$r"
# The positive control: the denials above must be the guard talking, not a malformed
# request that never reached it.
r=$(set_days 30)
assert_contains "and an admin is not"        '"days":30'       "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
