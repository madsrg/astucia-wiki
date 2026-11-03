#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Edit conflicts: a save over a change the writer never saw is refused.
#
# `get` hands out a `rev`; an editor sends it back on `save` as `base`. If the page moved
# on in between, the save answers 409 with what is on disk and writes nothing. Without
# this, two people (or a person and an AI user) editing one page meant the second save
# silently deleted the first.
#
# Three things are as important as the refusal itself, and each is asserted:
#   - a save with no `base` behaves exactly as before (AI tools, MCP, saved searches);
#   - a Markdown rev covers the body only, so a front-matter change made while somebody
#     edits the body — which save preserves anyway — is not a conflict;
#   - the check and the write are atomic: two saves from one base, one wins.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

# Several workers, or the built-in server answers one request at a time and the race at
# the end would pass with no lock at all.
export PHP_CLI_SERVER_WORKERS=4
fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
                         {"uid":2,"name":"Bob","role":"editor","auth":"otp","email":"bob@example.com"}]}'

# Each scenario has its own page, so a write that wrongly succeeds in one cannot change
# what a later assertion reads.
fixture_page 'Main/Stale.md'  'original stale'
fixture_page 'Main/Force.md'  'original force'
fixture_page 'Main/NoBase.md' 'original nobase'
fixture_page 'Main/Chain.md'  'original chain'
fixture_page 'Main/Race.md'   'original race'
printf '%s' '---
status: draft
---
# Body

original body
' > "$WIKI_PAGES/Main/Meta.md"
printf '%s' '{"columns":[],"items":[]}' > "$WIKI_PAGES/Main/Data.list"
printf '%s' '{"a": 1}' > "$WIKI_PAGES/Main/Data.json"

ADMIN=$WIKI_ROOT/admin.jar
BOB=$WIKI_ROOT/bob.jar
fixture_login "$ADMIN" "uid=1&auth=otp&name=Admin&role=admin"
fixture_login "$BOB"   "uid=2&auth=otp&name=Bob&role=editor"
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

# jf <json> <key> — one top-level field, as a string ('' when absent).
jf() { printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d[$argv[1]]??""; echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):$v);' "$2"; }
rev_of() { jf "$(get_as "$1" "api.php?action=get&space=Main&file=$2")" rev; }
# save <jar> <file> <body> [query-extra] — prints "<http status> <json>"
save() {
    curl -s -b "$1" -c "$1" --max-time 15 -w '\n%{http_code}' -X POST \
         -H 'Content-Type: text/plain' --data-binary "$3" \
         "$WIKI_URL/api.php?action=save&space=Main&file=$2${4:-}"
}
status_of() { printf '%s' "$1" | tail -n1; }
body_of()   { printf '%s' "$1" | sed '$d'; }


section 'get and save both report a rev'
r=$(rev_of "$ADMIN" Stale.md)
assert_eq 'get returns a 16-char rev' 16 "${#r}"
res=$(save "$ADMIN" Chain.md 'chain v2' "&base=$(rev_of "$ADMIN" Chain.md)")
assert_eq 'positive control: saving from the current rev succeeds' 200 "$(status_of "$res")"
r2=$(jf "$(body_of "$res")" rev)
assert_eq 'the save returns the rev get would now return' "$(rev_of "$ADMIN" Chain.md)" "$r2"
res=$(save "$ADMIN" Chain.md 'chain v3' "&base=$r2")
assert_eq 'the returned rev is a valid base for the next save' 200 "$(status_of "$res")"
assert_eq '…and that save landed' 'chain v3' "$(cat "$WIKI_PAGES/Main/Chain.md")"


section 'a stale base is refused, and nothing is written'
base=$(rev_of "$ADMIN" Stale.md)
save "$BOB" Stale.md 'bob was here' "&base=$base" > /dev/null          # Bob saves first
res=$(save "$ADMIN" Stale.md 'admin overwrites' "&base=$base")          # Admin, from the old rev
assert_eq 'the second save is a 409' 409 "$(status_of "$res")"
b=$(body_of "$res")
assert_eq 'the response says conflict'                 true          "$(jf "$b" conflict)"
assert_eq 'it carries what is on disk now'             'bob was here' "$(jf "$b" data)"
assert_eq 'it names who changed it'                    Bob           "$(jf "$b" modifiedBy)"
assert_eq 'its rev is the current one'                 "$(rev_of "$ADMIN" Stale.md)" "$(jf "$b" rev)"
assert_eq "Bob's text is still on disk"                'bob was here' "$(cat "$WIKI_PAGES/Main/Stale.md")"


section 'force overwrites deliberately'
base=$(rev_of "$ADMIN" Force.md)
save "$BOB" Force.md 'bob force' "&base=$base" > /dev/null
res=$(save "$ADMIN" Force.md 'admin wins' "&base=$base&force=1")
assert_eq 'force=1 with a stale base succeeds' 200 "$(status_of "$res")"
assert_eq '…and wrote'                         'admin wins' "$(cat "$WIKI_PAGES/Main/Force.md")"


section 'no base keeps the old behaviour'
save "$BOB" NoBase.md 'bob nobase' "&base=$(rev_of "$BOB" NoBase.md)" > /dev/null
res=$(save "$ADMIN" NoBase.md 'admin nobase')
assert_eq 'a save without base is not checked' 200 "$(status_of "$res")"
assert_eq '…and wrote'                         'admin nobase' "$(cat "$WIKI_PAGES/Main/NoBase.md")"


section 'Markdown: the rev covers the body, not the front matter'
base=$(rev_of "$ADMIN" Meta.md)
# A change to the block only, made outside the editor (the Metadata panel, stamping or tag
# sync would do the same). save preserves the block as it is on disk at write time.
sed -i 's/^status: draft$/status: published/' "$WIKI_PAGES/Main/Meta.md"
assert_eq 'a block-only change leaves the rev alone' "$base" "$(rev_of "$ADMIN" Meta.md)"
res=$(save "$ADMIN" Meta.md '# Body

edited body
' "&base=$base")
assert_eq 'so a body save from before it succeeds' 200 "$(status_of "$res")"
assert_contains 'the block change survived'  'status: published' "$(cat "$WIKI_PAGES/Main/Meta.md")"
assert_contains 'the body change landed'     'edited body'       "$(cat "$WIKI_PAGES/Main/Meta.md")"
# …while a body change is still a conflict, with the body (not the block) to diff against.
base=$(rev_of "$ADMIN" Meta.md)
save "$BOB" Meta.md '# Body

bob body
' "&base=$base" > /dev/null
res=$(save "$ADMIN" Meta.md '# Body

admin body
' "&base=$base")
assert_eq 'a body change is a conflict'      409 "$(status_of "$res")"
assert_not_contains 'the conflict data is the body, without the block' 'status:' "$(jf "$(body_of "$res")" data)"


section 'list and JSON pages'
base=$(rev_of "$ADMIN" Data.list)
res=$(save "$ADMIN" Data.list '{"columns":[],"items":[{"id":1}]}' "&base=$base")
assert_eq 'list: save from current rev succeeds' 200 "$(status_of "$res")"
res=$(save "$BOB" Data.list '{"columns":[],"items":[]}' "&base=$base")
assert_eq 'list: save from the old rev is refused' 409 "$(status_of "$res")"
assert_contains 'list: the first save is intact' '"id":1' "$(cat "$WIKI_PAGES/Main/Data.list")"

base=$(rev_of "$ADMIN" Data.json)
res=$(save "$ADMIN" Data.json '{"a":2}' "&base=$base")
assert_eq 'json: save from current rev succeeds' 200 "$(status_of "$res")"
# The server re-encodes JSON, so the rev must describe the bytes it wrote, not the ones posted.
r=$(jf "$(body_of "$res")" rev)
res=$(save "$ADMIN" Data.json '{"a":3}' "&base=$r")
assert_eq 'json: the rev after a re-encode is still a valid base' 200 "$(status_of "$res")"


section 'two saves from one base: exactly one wins'
# Ten rounds, because one round with two fast requests may simply not overlap. Separate
# sessions, so PHP's own session lock does not serialise them for us.
ok=0; intact=0
for n in 1 2 3 4 5 6 7 8 9 10; do
    base=$(rev_of "$ADMIN" Race.md)
    save "$ADMIN" Race.md "racer admin $n" "&base=$base" > "$WIKI_ROOT/race1" & p1=$!
    save "$BOB"   Race.md "racer bob $n"   "&base=$base" > "$WIKI_ROOT/race2" & p2=$!
    wait "$p1" "$p2"
    codes=$(printf '%s\n%s\n' "$(tail -n1 "$WIKI_ROOT/race1")" "$(tail -n1 "$WIKI_ROOT/race2")" | sort | tr '\n' ' ')
    [ "$codes" = '200 409 ' ] && ok=$((ok + 1))
    [ "$(tail -n1 "$WIKI_ROOT/race1")" = 200 ] && want="racer admin $n" || want="racer bob $n"
    [ "$(cat "$WIKI_PAGES/Main/Race.md")" = "$want" ] && intact=$((intact + 1))
done
assert_eq 'every round: one 200 and one 409'           10 "$ok"
assert_eq "every round: the winner's text is on disk"  10 "$intact"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
