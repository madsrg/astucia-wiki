#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The system prompt gallery. Two things matter here and neither is the happy path:
#
#   1. It is the only place the wiki talks to the vendor, so "makes no request when told
#      not to" is a promise the product makes and a test has to hold it to.
#   2. It must always produce a gallery. A dead endpoint, a hostile response or an
#      air-gapped install all have to degrade to something usable.
#
# The remote is a local stub, so the suite never touches the network.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap 'fixture_stop; [ -n "${STUB_PID:-}" ] && kill "$STUB_PID" 2>/dev/null' EXIT

fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc"}]}'
ADMIN=$WIKI_ROOT/jar-admin; EDITOR=$WIKI_ROOT/jar-ed
fixture_login "$ADMIN"  "uid=1&sub=s1&name=Admin&role=admin"
fixture_login "$EDITOR" "uid=2&sub=s2&name=Ed&role=editor"

# ── a stub "astucia.wiki" that counts requests ───────────────────────────────
STUB_DIR=$WIKI_ROOT/stub; mkdir -p "$STUB_DIR"
cat > "$STUB_DIR/system_prompts.json" <<'JSON'
{"schema":1,"updated":"2026-09-08","prompts":[
 {"id":"remote-one","title":"Remote Role","description":"From the stub","prompt":"REMOTE PROMPT BODY"}]}
JSON
cat > "$STUB_DIR/router.php" <<'PHP'
<?php
$hits = __DIR__ . '/hits';
file_put_contents($hits, (int)@file_get_contents($hits) + 1);
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/system_prompts.json') { header('Content-Type: application/json'); readfile(__DIR__ . '/system_prompts.json'); return true; }
if ($p === '/garbage')  { header('Content-Type: application/json'); echo '{"nope":true}'; return true; }
http_response_code(500); echo 'boom'; return true;
PHP
STUB_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$STUB_DIR" && exec php -S "127.0.0.1:$STUB_PORT" router.php ) > "$STUB_DIR/log" 2>&1 &
STUB_PID=$!
until curl -sf --max-time 2 "http://127.0.0.1:$STUB_PORT/system_prompts.json" >/dev/null 2>&1; do sleep 0.1; done
: > "$STUB_DIR/hits"

set_url() {   # point the wiki's config at a given gallery URL
    python3 - "$WIKI_APP/config.php" "$1" <<'PY'
import re, sys
p, url = sys.argv[1], sys.argv[2]
s = open(p).read()
s = re.sub(r"define\('SYSTEM_PROMPT_GALLERY_URL',[^;]*\);",
           "define('SYSTEM_PROMPT_GALLERY_URL', '%s');" % url, s)
open(p, 'w').write(s)
PY
}
# An empty file is zero hits. `cat || echo 0` does not cover that — cat *succeeds* and
# prints nothing — and this is the assertion that holds the "makes no outbound request"
# promise, so it has to be exact.
hits()      { local n; n=$(cat "$STUB_DIR/hits" 2>/dev/null); echo "${n:-0}"; }
drop_cache() { rm -f "$WIKI_SYS/system_prompts_cache.json"; }

# ── the promise: no URL, no outbound request ─────────────────────────────────
section 'with the URL cleared the wiki contacts nobody'
set_url ''; drop_cache; : > "$STUB_DIR/hits"
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery')
assert_contains "still returns a gallery" '"success":true'   "$r"
assert_contains "from the bundled copy"   '"source":"bundled"' "$r"
assert_contains "which has real prompts"  'product-owner'    "$r"
assert_eq       "made no outbound request" "0" "$(hits)"

# ── the remote ───────────────────────────────────────────────────────────────
section 'a reachable endpoint is used and cached'
set_url "http://127.0.0.1:$STUB_PORT/system_prompts.json"; drop_cache; : > "$STUB_DIR/hits"
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery')
assert_contains "served from the endpoint" '"source":"remote"'  "$r"
assert_contains "with its content"         'REMOTE PROMPT BODY' "$r"
assert_eq       "one request"              "1" "$(hits)"

r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery')
assert_contains "second call is cached"  '"source":"cache"' "$r"
assert_eq       "no second request"      "1" "$(hits)"

r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery&refresh=1')
assert_contains "refresh goes out again" '"source":"remote"' "$r"
assert_eq       "two requests"           "2" "$(hits)"

# ── degradation ──────────────────────────────────────────────────────────────
section 'a dead endpoint falls back to the cache, not to nothing'
set_url "http://127.0.0.1:$STUB_PORT/dead"
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery&refresh=1')
assert_contains "says the cache is stale"  '"source":"cache-stale"' "$r"
assert_contains "still has the content"    'REMOTE PROMPT BODY'     "$r"

section 'with no cache either, the bundled copy'
drop_cache
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery&refresh=1')
assert_contains "falls back to bundled" '"source":"bundled"' "$r"
assert_contains "which is usable"       'product-owner'      "$r"

section 'a malformed response is rejected, not rendered'
set_url "http://127.0.0.1:$STUB_PORT/garbage"; drop_cache
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery&refresh=1')
assert_contains     "does not become the gallery" '"source":"bundled"' "$r"
assert_not_contains "and nothing of it leaks"     'nope'               "$r"

section 'an unreachable host does not hang the dialog'
set_url "http://127.0.0.1:1/system_prompts.json"; drop_cache
t0=$(date +%s)
r=$(get_as "$ADMIN" 'api.php?action=admin_prompt_gallery&refresh=1')
t1=$(date +%s)
assert_contains "still answers"          '"success":true' "$r"
if [ $((t1 - t0)) -le 15 ]; then _pass "answered within the timeout ($((t1-t0))s)"; else _fail "answered within the timeout" "took $((t1-t0))s"; fi

# ── who may ask ──────────────────────────────────────────────────────────────
section 'the gallery is admin-only'
r=$(get_as "$EDITOR" 'api.php?action=admin_prompt_gallery')
assert_contains "an editor is refused" '"success":false' "$r"
assert_not_contains "and sees nothing" 'product-owner'   "$r"

# ── the bundled file itself ──────────────────────────────────────────────────
section 'the file shipped in the repo is valid'
assert_file_exists "system_prompts.json exists" "$WIKI_APP/system_prompts.json"
n=$(python3 -c "
import json; d=json.load(open('$WIKI_APP/system_prompts.json')); print(len(d['prompts']))")
if [ "$n" -ge 5 ]; then _pass "holds $n prompts"; else _fail "holds $n prompts" "expected several"; fi
bad=$(python3 -c "
import json; d=json.load(open('$WIKI_APP/system_prompts.json'))
print(sum(1 for p in d['prompts'] if not p.get('id') or not p.get('title') or not p.get('prompt').strip()))")
assert_eq "every entry has id, title and prompt" "0" "$bad"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
