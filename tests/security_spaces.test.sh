#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Space isolation — regression suite for the vulnerability fixed in v2026.9.3.
#
# A user restricted to one Space could read another Space's content. The allowlist was
# checked when a request *named* a Space and skipped when it did not — but `?space=` is
# optional, so a path of "Bravo/secret.md" resolved against PAGES_DIR and reached Bravo
# without ever naming it. Four entry points were affected: getfile.php, api.php,
# export.php, and the tool set shared by AI users, MCP and the job runner.
#
# Every attack below was a working read or write before that release. The legitimate-access
# half matters just as much: a fix that isolates Spaces by breaking them is not a fix.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

# ── the wiki under attack ────────────────────────────────────────────────────
# Alice is restricted to Alpha. Bravo holds the secrets. Alpha2 exists only because
# "…/Alpha" is a string prefix of "…/Alpha2".
fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Alpha; fixture_space Bravo; fixture_space Alpha2
fixture_page "Alpha/ok.md"                        '# Alpha
harmless'
fixture_page "Alpha/readonly.md"                  '# Alpha
harmless'
fixture_page "Alpha/ok.md.uploads/pic.txt"        'ALPHA-ATTACHMENT-OK'
fixture_page "Alpha/mine.list"                    '{"columns":[{"id":"c1","name":"Col"}],"items":[{"c1":"ALPHA-LIST-OK"}]}'
fixture_page "Bravo/secret.md"                    '# Bravo
TOP-SECRET-PAGE'
fixture_page "Bravo/notes.md.uploads/plan.txt"    'TOP-SECRET-ATTACHMENT'
fixture_page "Bravo/data.list"                    '{"columns":[{"id":"c1","name":"Col"}],"items":[{"c1":"BRAVO-LIST-SECRET"}]}'
# Separate victims for the destructive attacks. Sharing one file with the read assertions
# made the suite order-dependent: against vulnerable code the delete succeeded, so later
# reads found nothing and *looked* safe — the suite went quiet exactly when it should shout.
fixture_page "Bravo/deleteme.md"                  'TOP-SECRET-DELETE-TARGET'
fixture_page "Bravo/overwrite.md"                 'TOP-SECRET-OVERWRITE-TARGET'
fixture_page "Alpha2/leak.txt"                    'PREFIX-SIBLING-SECRET'
fixture_page "rootnote.md"                        'ROOTLEVEL-OK'
mkdir -p "$WIKI_PAGES/.git"; printf 'GIT-CONFIG-SECRET\n' > "$WIKI_PAGES/.git/config"

fixture_users '{"users":[
  {"uid":1,"sub":"sub-1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"sub-2","name":"Alice","role":"editor","auth":"oidc","spaces":["Alpha"]},
  {"uid":3,"sub":"sub-3","name":"Open","role":"editor","auth":"oidc"},
  {"uid":4,"name":"BotA","role":"editor","is_ai":true,"spaces":["Alpha"],
   "service_token":"wk_ai_testsuite","ai_config":{"provider":"openai","model":"gpt-4o"}}
]}'

ALICE=$WIKI_ROOT/jar-alice; ADMIN=$WIKI_ROOT/jar-admin; OPEN=$WIKI_ROOT/jar-open
fixture_login "$ALICE" "uid=2&sub=sub-2&name=Alice&role=editor&spaces=Alpha"
fixture_login "$ADMIN" "uid=1&sub=sub-1&name=Admin&role=admin"
fixture_login "$OPEN"  "uid=3&sub=sub-3&name=Open&role=editor"

TOKEN=wk_ai_testsuite
SECRET='SECRET\|GIT-CONFIG'   # any of the planted markers

# denied <label> <response> — a leak is any planted marker reaching the caller
denied() { if printf '%s' "$2" | grep -q "$SECRET"; then _fail "$1" "LEAKED: $(printf '%s' "$2" | tr -d '\n' | cut -c1-120)"; else _pass "$1"; fi; }

# ── attacks: getfile.php ─────────────────────────────────────────────────────
section 'getfile.php — the reported entry point'
denied "?space= names another Space" \
  "$(get_as "$ALICE" 'getfile.php?space=Bravo&path=notes.md.uploads/plan.txt')"
denied "no ?space= at all, path reaches in" \
  "$(get_as "$ALICE" 'getfile.php?path=Bravo/notes.md.uploads/plan.txt')"
denied "../ escapes the allowed Space" \
  "$(get_as "$ALICE" 'getfile.php?space=Alpha&path=../Bravo/secret.md')"
denied "prefix sibling Alpha -> Alpha2" \
  "$(get_as "$ALICE" 'getfile.php?space=Alpha&path=../Alpha2/leak.txt')"
denied "?space=.git serves the content repo" \
  "$(get_as "$ALICE" 'getfile.php?space=.git&path=config')"

# ── attacks: api.php ─────────────────────────────────────────────────────────
section 'api.php — reads'
denied "get with ?space=" \
  "$(get_as "$ALICE" 'api.php?action=get&space=Bravo&file=secret.md')"
denied "get with no ?space=, nested path" \
  "$(get_as "$ALICE" 'api.php?action=get&file=Bravo/secret.md')"

section 'api.php — writes must be refused too'
post_as "$ALICE" 'api.php?action=create_file' 'path=Bravo/evil.md' > /dev/null
assert_file_missing "create_file into another Space" "$WIKI_PAGES/Bravo/evil.md"

# `save` takes its path in the query string and its body as form data. Getting that wrong
# makes the request fail for an unrelated reason and the assertion passes without ever
# reaching the guard — so the positive control below is what proves the call is well formed.
post_as "$ALICE" 'api.php?action=save&file=Bravo/overwrite.md' 'content=PWNED' > /dev/null
assert_contains "save over another Space's page" "TOP-SECRET-OVERWRITE-TARGET" "$(cat "$WIKI_PAGES/Bravo/overwrite.md")"

post_as "$ALICE" 'api.php?action=delete' 'path=Bravo/deleteme.md' > /dev/null
assert_file_exists "delete in another Space" "$WIKI_PAGES/Bravo/deleteme.md"

# A path that does not exist yet cannot be realpath()'d, so the guard resolves the deepest
# existing ancestor instead. Exercise that branch from the attack side too.
post_as "$ALICE" 'api.php?action=create_folder' 'path=Bravo/newdir' > /dev/null
assert_file_missing "create_folder into another Space" "$WIKI_PAGES/Bravo/newdir"

# ── attacks: export.php ──────────────────────────────────────────────────────
section 'export.php'
denied "?space= names another Space" \
  "$(get_as "$ALICE" 'export.php?path=data.list&space=Bravo&format=csv')"
denied "no ?space=, nested path" \
  "$(get_as "$ALICE" 'export.php?path=Bravo/data.list&format=csv')"

# ── attacks: the AI/MCP tool set ─────────────────────────────────────────────
section 'AI tools — one guard covers chat, MCP and the cron runner'
mcp() { curl -s --max-time 15 -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
        -X POST "$WIKI_URL/mcp.php${2:-}" -d "$1"; }
denied "wiki_read_page into another Space" \
  "$(mcp '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"wiki_read_page","arguments":{"path":"Bravo/secret.md"}}}')"
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wiki_write_page","arguments":{"path":"Bravo/bot.md","content":"x"}}}' > /dev/null
assert_file_missing "wiki_write_page into another Space" "$WIKI_PAGES/Bravo/bot.md"

# ── the other half: legitimate access must still work ────────────────────────
section 'restricted user, inside their own Space'
assert_contains "attachment"        "ALPHA-ATTACHMENT-OK" "$(get_as "$ALICE" 'getfile.php?space=Alpha&path=ok.md.uploads/pic.txt')"
assert_contains "page"              "harmless"            "$(get_as "$ALICE" 'api.php?action=get&space=Alpha&file=readonly.md')"
assert_contains "list export"       "ALPHA-LIST-OK"       "$(get_as "$ALICE" 'export.php?path=mine.list&space=Alpha&format=csv')"
assert_contains "AI tool read"      "harmless"            "$(mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wiki_read_page","arguments":{"path":"readonly.md"}}}' '?space=Alpha')"

post_as "$ALICE" 'api.php?action=create_file&space=Alpha' 'path=new-page.md' > /dev/null
assert_file_exists "create a page"  "$WIKI_PAGES/Alpha/new-page.md"
# A folder first: create_file writes with file_put_contents and does not mkdir -p, which is
# why the UI creates the folder as its own step. Both calls resolve a path that does not
# exist yet, which is the branch of sanitize_path's guard that walks up to the deepest
# existing ancestor to decide the Space.
post_as "$ALICE" 'api.php?action=save&space=Alpha&file=ok.md' 'content=# Alpha
edited by the owner' > /dev/null
assert_contains "save a page (control for the denial above)" "edited by the owner" "$(cat "$WIKI_PAGES/Alpha/ok.md")"
post_as "$ALICE" 'api.php?action=create_folder&space=Alpha' 'path=Sub' > /dev/null
assert_file_exists "create a folder"           "$WIKI_PAGES/Alpha/Sub"
post_as "$ALICE" 'api.php?action=create_file&space=Alpha' 'path=Sub/deep.md' > /dev/null
assert_file_exists "create a page inside it"   "$WIKI_PAGES/Alpha/Sub/deep.md"

section 'content outside any Space stays readable'
assert_contains "root-level page"   "ROOTLEVEL-OK"        "$(get_as "$ALICE" 'api.php?action=get&file=rootnote.md')"

section 'unrestricted users are not affected'
assert_contains "editor, no allowlist: page"  "TOP-SECRET-PAGE"       "$(get_as "$OPEN"  'api.php?action=get&space=Bravo&file=secret.md')"
assert_contains "admin: page"                 "TOP-SECRET-PAGE"       "$(get_as "$ADMIN" 'api.php?action=get&space=Bravo&file=secret.md')"
assert_contains "admin: attachment"           "TOP-SECRET-ATTACHMENT" "$(get_as "$ADMIN" 'getfile.php?space=Bravo&path=notes.md.uploads/plan.txt')"
assert_contains "admin: list export"          "BRAVO-LIST-SECRET"     "$(get_as "$ADMIN" 'export.php?path=data.list&space=Bravo&format=csv')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
