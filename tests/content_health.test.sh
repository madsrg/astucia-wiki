#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Content health (content_health.php): the three lists, and what they must not say.
#
#   - broken: a ?pageid= to a missing page, one into another Space, an unresolved
#     [[wikilink]], a missing ![[attachment]] — and none of the working ones, nor a link
#     shown as an example inside code;
#   - no orphans list: every page is reachable from the tree, so it was removed;
#   - stale: a passed review_by, an old update, and a future review_by overriding age;
#   - empty: a page that is only a heading;
#   - isolation: a link into a Space the actor may not read is neither broken nor fine —
#     it is not mentioned at all;
#   - the graph: a wikilink to a page created *after* the linking page is an edge at once
#     (it used to wait for the linking page to change), and a cross-Space link is not an
#     edge in this Space's graph.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main; fixture_space Other; fixture_space Secret
fixture_users '{"users":[
  {"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
  {"uid":2,"name":"Ria","role":"editor","auth":"otp","email":"ria@example.com","spaces":["Main","Other"]},
  {"uid":4,"name":"Rex","role":"reader","auth":"otp","email":"rex@example.com"},
  {"uid":3,"name":"Bot","role":"editor","is_ai":true,"service_token":"wk_ai_health","spaces":["Main","Other"],
   "ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'
ADMIN=$WIKI_ROOT/jar-admin; RIA=$WIKI_ROOT/jar-ria
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin"
fixture_login "$RIA"   "uid=2&name=Ria&role=editor&spaces=Main,Other"
REX=$WIKI_ROOT/jar-rex
fixture_login "$REX"   "uid=4&name=Rex&role=reader"

# Targets first, so their ids exist before anything links to them.
fixture_page 'Main/Main.md'        '# Welcome

This is the start page of the wiki, and nothing links to it.'
fixture_page 'Main/Target.md'      '# Target

A page that the hub links to by its id, so its link is a working one.'
fixture_page 'Main/Named.md'       '# Named

A page that the hub links to with a wikilink, by its name alone.'
fixture_page 'Main/Included.md'    '# Included

A fragment that the hub transcludes with an include tag, which counts as a link.'
fixture_page 'Main/Lonely.md'      '# Lonely

Nobody links here. It has enough text to not be a stub.'
fixture_page 'Main/Stub.md'        '# Stub'
fixture_page 'Main/Old.md'         '# Old

Written long ago and never touched since, with no review date to vouch for it.'
fixture_page 'Main/Overdue.md'     '---
owner: Ria
review_by: 2020-01-01
---
# Overdue

The review date in the front matter of this page passed years ago.'
fixture_page 'Main/Cared.md'       '---
review_by: 2999-01-01
---
# Cared for

Old, but someone has promised to review it, so it is not stale.'
fixture_page 'Main/Hub.md.uploads/here.png' 'PNG'
fixture_page 'Other/There.md'      '# There'
fixture_page 'Secret/Hidden.md'    '# Hidden'
for s in Main Other Secret; do get_as "$ADMIN" "api.php?action=indexfiles&space=$s" > /dev/null; done

id_of() { python3 -c 'import json,sys
d=json.load(open(sys.argv[1]))
print(next(k for k,v in d.items() if v.get("path")==sys.argv[2]))' "$WIKI_PAGES/$1/index.json" "$2"; }
T=$(id_of Main Target.md); I=$(id_of Main Included.md); X=$(id_of Other There.md); H=$(id_of Secret Hidden.md)

fixture_page 'Main/Hub.md' "# Hub

[ok](?pageid=$T) and [gone](index.php?pageid=999999) and [[Named]] and [[Nowhere]].
{include:$I}
Cross-space: [ok](?pageid=$X&space=Other), [gone](?space=Other&pageid=888888),
[secret ok](?pageid=$H&space=Secret), [secret gone](?pageid=777777&space=Secret).
Embeds: ![[here.png]] and ![[missing.png]]. [[Minutes 2026.01.05]] is a page name.

\`[[InlineExample]]\` and \`?pageid=555555\` are documentation.

\`\`\`
[[FencedExample]] index.php?pageid=444444
\`\`\`"
get_as "$ADMIN" "api.php?action=indexfiles&space=Main" > /dev/null

# Age two pages: both the file and the index, or index_sync puts the file's mtime back.
touch -d '400 days ago' "$WIKI_PAGES/Main/Old.md" "$WIKI_PAGES/Main/Cared.md"
python3 - "$WIKI_PAGES/Main/index.json" <<'PY'
import json, sys, time
p = sys.argv[1]; d = json.load(open(p)); old = int(time.time()) - 400 * 86400
for v in d.values():
    if v.get('path') in ('Old.md', 'Cared.md'): v['updated'] = old
json.dump(d, open(p, 'w'))
PY

# field <json> <python expression over r> — one value out of a report
field() { printf '%s' "$1" | python3 -c 'import json,sys; r=json.load(sys.stdin); print(eval(sys.argv[1]))' "$2"; }
R=$(get_as "$RIA" 'api.php?action=content_health&space=Main')
# The broken links on Hub, as "kind:target[@space]" — one string to assert against.
HUB=$(field "$R" '" ".join(sorted(l["kind"]+":"+l["target"]+("@"+l["space"] if "space" in l else "") for b in r["broken"] if b["path"]=="Hub.md" for l in b["links"]))')

section 'broken links'
assert_eq 'the request succeeds'                         True "$(field "$R" 'r["success"]')"
assert_contains 'a pageid to a missing page'             'page:999999'           "$HUB"
assert_contains 'a wikilink no page answers to'          'wikilink:Nowhere'      "$HUB"
assert_contains 'a dotted page name is a page link'      'wikilink:Minutes 2026.01.05' "$HUB"
assert_contains 'a missing attachment embed'             'attachment:missing.png' "$HUB"
assert_contains 'a missing page in another Space'        'space:888888@Other'    "$HUB"
assert_not_contains 'a working pageid is not broken'     "page:$T"               "$HUB"
assert_not_contains 'a working wikilink is not broken'   'Named'                 "$HUB"
assert_not_contains 'a present attachment is not broken' 'here.png'              "$HUB"
assert_not_contains 'a working cross-Space link is not'  "$X"                    "$HUB"
assert_not_contains 'an inline-code wikilink is ignored' 'InlineExample'         "$HUB"
assert_not_contains 'an inline-code pageid is ignored'   '555555'                "$HUB"
assert_not_contains 'a fenced wikilink is ignored'       'FencedExample'         "$HUB"
assert_not_contains 'a fenced pageid is ignored'         '444444'                "$HUB"
assert_eq 'the link count matches the list'              5 "$(field "$R" 'r["counts"]["links"]')"

section 'isolation: a Space the actor cannot read is not mentioned'
assert_not_contains 'not its broken link'                'Secret'                "$HUB"
assert_not_contains 'not its working one either'         "$H"                    "$(field "$R" 'json.dumps(r["broken"])')"
A=$(get_as "$ADMIN" 'api.php?action=content_health&space=Main')
AHUB=$(field "$A" '" ".join(sorted(l["kind"]+":"+l["target"]+("@"+l["space"] if "space" in l else "") for b in r["broken"] if b["path"]=="Hub.md" for l in b["links"]))')
assert_contains 'positive control: the admin sees it'    'space:777777@Secret'   "$AHUB"
assert_not_contains '…and not the working one'           "$H"                    "$AHUB"
assert_contains 'Ria cannot open Secret itself'          'false' \
  "$(get_as "$RIA" 'api.php?action=content_health&space=Secret' | tr -d ' ')"

section 'no orphans list'
assert_eq 'the report has no orphans list'                False "$(field "$R" '"orphans" in r')"
assert_eq '…and no orphans count'                          False "$(field "$R" '"orphans" in r["counts"]')"

section 'stale'
STALE=$(field "$R" '" ".join(s["path"]+"="+s["reason"] for s in r["stale"])')
assert_contains 'a passed review_by is overdue'           'Overdue.md=review_overdue' "$STALE"
assert_contains 'an old page is not updated'              'Old.md=not_updated'        "$STALE"
assert_not_contains 'a future review_by vouches for it'   'Cared.md'                  "$STALE"
assert_not_contains 'a fresh page is not stale'           'Lonely.md'                 "$STALE"
assert_eq 'overdue reviews come first'                    'Overdue.md' "$(field "$R" 'r["stale"][0]["path"]')"
assert_eq 'the owner is reported'                         'Ria'        "$(field "$R" '[s for s in r["stale"] if s["path"]=="Overdue.md"][0]["owner"]')"
R2=$(get_as "$RIA" 'api.php?action=content_health&space=Main&stale_days=500')
assert_not_contains 'stale_days moves the threshold'      'Old.md'     "$(field "$R2" '" ".join(s["path"] for s in r["stale"])')"
assert_eq '…and the report says which it used'            500          "$(field "$R2" 'r["stale_days"]')"

section 'empty'
EMPTY=$(field "$R" '" ".join(e["path"] for e in r["empty"])')
assert_contains 'a heading alone is a stub'               'Stub.md'    "$EMPTY"
assert_not_contains 'a page with text is not'             'Lonely.md'  "$EMPTY"

section 'the graph reads the same scan'
fixture_page 'Main/Late.md' '# Late

Created after the page that names it.'
fixture_page 'Main/Namer.md' '# Namer

[[Late Comer]] does not exist yet.'
get_as "$ADMIN" "api.php?action=indexfiles&space=Main" > /dev/null
get_as "$ADMIN" 'api.php?action=get_graph&space=Main' > /dev/null      # caches Namer's scan
mv "$WIKI_PAGES/Main/Late.md" "$WIKI_PAGES/Main/Late Comer.md"
get_as "$ADMIN" "api.php?action=indexfiles&space=Main" > /dev/null
G=$(get_as "$ADMIN" 'api.php?action=get_graph&space=Main')
NAMER=$(id_of Main Namer.md); LATE=$(id_of Main 'Late Comer.md')
assert_eq 'a wikilink resolves to a page created later' 1 \
  "$(field "$G" 'sum(1 for e in r["edges"] if e["type"]=="reference" and e["source"]=="'"$NAMER"'" and e["target"]=="'"$LATE"'")')"
assert_eq 'a cross-Space link is not an edge here' 0 \
  "$(field "$G" 'sum(1 for e in r["edges"] if e["type"]=="reference" and e["target"]=="'"$X"'")')"

section 'who gets the button'
# Content Health is the health tabs of the Wiki Pulse dialog. A signed-in reader still has
# the Pulse icon — for their mentions — but no health tabs, since they cannot fix anything.
assert_contains 'an editor has the health tabs'          'data-health="1"' "$(get_as "$RIA" 'index.php' | grep -o 'id="pulse-btn"[^>]*')"
assert_contains 'a reader has the Pulse icon'            'id="pulse-btn"'  "$(get_as "$REX" 'index.php')"
assert_contains '…without the health tabs'               'data-health="0"' "$(get_as "$REX" 'index.php' | grep -o 'id="pulse-btn"[^>]*')"

section 'AI tool over MCP'
M=$(curl -s -X POST -H 'Authorization: Bearer wk_ai_health' -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_content_health","arguments":{}}}' \
  "$WIKI_URL/mcp.php?space=Main")
assert_contains 'the tool answers'                        'Content health for Space' "$M"
assert_contains 'with the broken links'                   'Nowhere'                  "$M"
assert_contains 'with the stale pages'                    'Overdue.md'               "$M"
assert_not_contains 'and no orphans section'              'Orphan'                   "$M"
assert_not_contains 'nor a Space the AI cannot read'      'Secret'                   "$M"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
