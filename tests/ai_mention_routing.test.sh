#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Who a [@#]Name mention actually addresses.
#
# The boundary used to be `\b`, which sees one between "0" and "-": "#gpt120-think" also
# matched an AI user named "gpt120", and the detection loop took the first match in
# users.json order. So the wrong AI answered, inline, ignoring the intended one's "always
# run in the background" — which showed up in the thread as a placeholder with no job_id
# reading "Working…". The client never had the bug (it extracts the whole token), so the
# two disagreed about who was addressed while each looked right on its own.
#
# Both orderings of users.json are exercised, because order is exactly what the old code
# was sensitive to.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
JAR=$WIKI_ROOT/jar

ADMIN='{"uid":1,"sub":"s1","name":"Alice","role":"admin","auth":"oidc"}'
PLAIN='{"uid":-8,"name":"gpt120","role":"editor","is_ai":true,
        "ai_config":{"provider":"openai","model":"m","api_key":"k"}}'
BG='{"uid":-9,"name":"gpt120-think","role":"editor","is_ai":true,
     "ai_config":{"provider":"openai","model":"m","api_key":"k","always_background":true}}'

# Who answered, and whether the placeholder is job-backed. Fresh thread each time.
ask() {  # ask <thread> <text>
    printf '{"messages":[],"nextMessageId":1,"topic":"T"}' > "$WIKI_PAGES/Main/$1.chat"
    post_as "$JAR" 'api.php?action=post_chat_message&space=Main' \
        "file=$1.chat&text=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$2")" \
    | python3 -c "
import json,sys
d=json.load(sys.stdin)
p=[m for m in (d.get('data') or {}).get('messages',[]) if m.get('pending')]
print('name=' + (p[0]['name'] if p else '(none)'), 'job=' + ('yes' if p and p[0].get('job_id') else 'no'))
"
}

for order in 'plain-first' 'background-first'; do
    section "users.json order: $order"
    if [ "$order" = plain-first ]; then
        fixture_users "{\"users\":[$ADMIN,$PLAIN,$BG]}"
    else
        fixture_users "{\"users\":[$ADMIN,$BG,$PLAIN]}"
    fi
    fixture_login "$JAR" "uid=1&sub=s1&name=Alice&role=admin"
    # Queued jobs survive between passes and the per-user cap is 3, at which point the
    # placeholder resolves to the cap message instead of staying pending.
    printf '%s' '{"jobs":[]}' > "$WIKI_SYS/agent_jobs_queue.json"

    r=$(ask t1 '#gpt120-think summarise')
    assert_contains "the longer name wins"        'name=gpt120-think' "$r"
    assert_contains "  …and it is queued"         'job=yes'           "$r"

    r=$(ask t2 '#gpt120 summarise')
    assert_contains "the exact name still works"  'name=gpt120'       "$r"
    assert_contains "  …and runs inline"          'job=no'            "$r"

    # A name that is neither: the mention must not fall back to a prefix match.
    r=$(ask t3 '#gpt120x summarise')
    assert_contains "an unknown name reaches no AI" 'name=(none)'     "$r"

    r=$(ask t4 'morning #gpt120-think, please summarise')
    assert_contains "mid-sentence, comma after"   'name=gpt120-think' "$r"
    r=$(ask t5 'ask @gpt120-think about it')
    assert_contains "@ still triggers an AI"      'name=gpt120-think' "$r"
done

# The same boundary decides who a *person* mention names, in mentions.php.
section 'person mentions: the boundary must not break ordinary punctuation'
fixture_users "{\"users\":[$ADMIN,$PLAIN,$BG]}"
fixture_login "$JAR" "uid=1&sub=s1&name=Alice&role=admin"
fixture_page 'Main/Sentence.md'  'Thanks for the help @Alice.'
fixture_page 'Main/Comma.md'     'cc @Alice, please review'
fixture_page 'Main/Hyphen.md'    'assigned to @Alice-Smith'
fixture_page 'Main/Suffix.md'    'ping @Alice2 about it'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
# name and uid are query parameters: get_mentions scans for whoever it is told to.
hits=$(get_as "$JAR" 'api.php?action=get_mentions&name=Alice&uid=1' | python3 -c "
import json,sys
d=json.load(sys.stdin)
rows=d.get('mentions') or d.get('data') or []
print(' '.join(sorted(r.get('path','?') for r in rows)))
")
assert_contains     "ends a sentence: @Alice."   'Sentence.md'   "$hits"
assert_contains     "followed by a comma"        'Comma.md'      "$hits"
assert_not_contains "@Alice-Smith is not Alice"  'Hyphen.md'     "$hits"
assert_not_contains "@Alice2 is not Alice"       'Suffix.md'     "$hits"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
