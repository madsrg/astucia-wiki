#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The realtime push channel. Two halves, and the split is deliberate:
#
#   - **What the wiki publishes** is ours, so it is asserted here against a stub standing in
#     for the hub, which records every POST. That covers the topics, the payload and — most
#     importantly — `private=on`.
#   - **Whether the hub honours a token's selectors** is upstream's contract, verified by
#     hand against a real Mercure 0.24 hub rather than by downloading 34 MB in CI.
#
# The `private=on` assertion is the one to keep. Removing that one form field makes the hub
# broadcast every update to every subscriber regardless of their token, which was confirmed
# by doing it: a user restricted to Space Main received Space Bravo's change stream.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap 'fixture_stop; for _p in "${HUB_PID:-}" "${TLS_PID:-}"; do [ -n "$_p" ] && kill "$_p" 2>/dev/null; done; true' EXIT

fixture_space Main
fixture_space Bravo
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc","spaces":["Main"]},
  {"uid":3,"sub":"s3","name":"Reader","role":"reader","auth":"oidc"}]}'
ADMIN=$WIKI_ROOT/jar-a; ED=$WIKI_ROOT/jar-e; READER=$WIKI_ROOT/jar-r
fixture_login "$ADMIN"  "uid=1&sub=s1&name=Admin&role=admin"
fixture_login "$ED"     "uid=2&sub=s2&name=Ed&role=editor&spaces=Main"
fixture_login "$READER" "uid=3&sub=s3&name=Reader&role=reader"

# ── a stub hub that records what PHP sends it ────────────────────────────────
HUB_DIR=$WIKI_ROOT/hub; mkdir -p "$HUB_DIR"
cat > "$HUB_DIR/router.php" <<'PHP'
<?php
// Records one line per publish: the raw form body.
file_put_contents(__DIR__ . '/posts', file_get_contents('php://input') . "\n", FILE_APPEND);
http_response_code(200); echo 'id';
return true;
PHP
HUB_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$HUB_DIR" && exec php -S "127.0.0.1:$HUB_PORT" router.php ) > "$HUB_DIR/log" 2>&1 &
HUB_PID=$!
until curl -sf --max-time 2 -X POST "http://127.0.0.1:$HUB_PORT/.well-known/mercure" -d x=1 >/dev/null 2>&1; do sleep 0.1; done
: > "$HUB_DIR/posts"

KEY=$(php -r 'echo bin2hex(random_bytes(32));')
enable_rt() {
    python3 - "$WIKI_APP/config.php" "$1" "$KEY" "http://127.0.0.1:$HUB_PORT" <<'PY'
import re, sys
p, on, key, url = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
s = open(p).read()
s = re.sub(r"define\('ENABLE_REALTIME',[^;]*\);",     "define('ENABLE_REALTIME', %s);" % on, s)
s = re.sub(r"define\('MERCURE_JWT_KEY',[^;]*\);",     "define('MERCURE_JWT_KEY', '%s');" % key, s)
s = re.sub(r"define\('MERCURE_INTERNAL_URL',[^;]*\);","define('MERCURE_INTERNAL_URL', '%s');" % url, s)
open(p, 'w').write(s)
PY
    # Wait until the change is actually in force. PHP honours opcache.revalidate_freq
    # (2 s by default), so a request made straight after rewriting config.php can still
    # be served the old value — which made the assertion after this silently test the
    # previous state instead of the new one.
    local want='"enabled":false'
    [ "$1" = true ] && want='"enabled":true'
    local i=0
    until get_as "$ADMIN" 'api.php?action=realtime_ticket' | grep -q "$want"; do
        i=$((i + 1))
        [ "$i" -gt 50 ] && { echo "    enable_rt: config change never took effect" >&2; break; }
        sleep 0.2
    done
}
# Point MERCURE_PUBLIC_URL somewhere and wait for it to be in force (opcache; see enable_rt).
set_public_url() {
    python3 - "$WIKI_APP/config.php" "$1" <<'SPU'
import re, sys
p, url = sys.argv[1], sys.argv[2]
s = open(p).read()
s = re.sub(r"define\('MERCURE_PUBLIC_URL',[^;]*\);",
           "define('MERCURE_PUBLIC_URL', '%s');" % url, s)
open(p, 'w').write(s)
SPU
    # json_encode escapes forward slashes, so the response carries https:\/\/… — match
    # the escaped form or this waits out its whole budget and warns for nothing.
    local marker="${1//\//\\/}"
    local i=0
    until get_as "$ADMIN" 'api.php?action=admin_realtime_status' | grep -qF "$marker"; do
        i=$((i + 1))
        [ "$i" -gt 50 ] && { echo "    set_public_url: never took effect" >&2; break; }
        sleep 0.2
    done
}
posts()      { cat "$HUB_DIR/posts" 2>/dev/null; }
npubs()      { local n; n=$(grep -c . "$HUB_DIR/posts" 2>/dev/null); echo "${n:-0}"; }
clear_pubs() { : > "$HUB_DIR/posts"; }
claim() {   # decode the mercure claim out of a ticket cookie
    curl -si -b "$1" -c "$1" --max-time 15 "$WIKI_URL/api.php?action=realtime_ticket" \
      | grep -i '^set-cookie: mercureAuthorization' | sed 's/.*mercureAuthorization=\([^;]*\).*/\1/' \
      | python3 -c "
import sys, base64, json
t = sys.stdin.read().strip()
if not t: print('NO-COOKIE'); raise SystemExit
b = t.split('.')[1]; b += '=' * (-len(b) % 4)
print(json.dumps(json.loads(base64.urlsafe_b64decode(b))['mercure'], sort_keys=True))"
}

# ── off by default ───────────────────────────────────────────────────────────
section 'with realtime off the wiki publishes nothing'
enable_rt false; clear_pubs
r=$(get_as "$ADMIN" 'api.php?action=realtime_ticket')
assert_contains "the ticket endpoint says so" '"enabled":false' "$r"
post_as "$ADMIN" 'api.php?action=create_file&space=Main' 'path=Off.md' > /dev/null
assert_eq "and a write reaches no hub" "0" "$(npubs)"

enable_rt true

# ── the payload ──────────────────────────────────────────────────────────────
section 'a page write publishes a page topic and a tree topic'
clear_pubs
r=$(post_as "$ADMIN" 'api.php?action=create_file&space=Main' 'path=Note.md')
assert_contains "the write succeeded"  '"success":true'      "$r"
assert_contains "page topic"  'topic=wiki%2FMain%2Fpage%2FNote.md' "$(posts)"
assert_contains "tree topic"  'topic=wiki%2FMain%2Ftree'           "$(posts)"

section 'private=on — without it the hub broadcasts to everyone'
# Confirmed by removing it against a real hub: a Main-only subscriber then received
# Bravo's events. Every publish must carry it.
n_priv=$(posts | grep -c 'private=on')
assert_eq "every publish is private" "$(npubs)" "$n_priv"

section 'a chat post publishes a chat topic, not a page topic'
clear_pubs
python3 -c "
import json; json.dump({'topic':'T','messages':[],'nextMessageId':1}, open('$WIKI_PAGES/Main/Team.chat','w'))"
post_as "$ADMIN" 'api.php?action=post_chat_message&space=Main' 'file=Team.chat&text=hi' > /dev/null
assert_contains     "chat topic"     'topic=wiki%2FMain%2Fchat%2FTeam.chat' "$(posts)"
assert_not_contains "no page topic"  'wiki%2FMain%2Fpage%2FTeam.chat'       "$(posts)"

section 'the topic carries the Space the file is really in'
clear_pubs
post_as "$ADMIN" 'api.php?action=create_file&space=Bravo' 'path=Other.md' > /dev/null
assert_contains     "Bravo"      'topic=wiki%2FBravo%2Fpage%2FOther.md' "$(posts)"
assert_not_contains "not Main"   'wiki%2FMain%2Fpage%2FOther.md'        "$(posts)"

section 'a delete publishes too, so an open tab does not keep a dead page'
clear_pubs
post_as "$ADMIN" 'api.php?action=delete&space=Main' 'path=Note.md' > /dev/null
assert_contains "page topic" 'topic=wiki%2FMain%2Fpage%2FNote.md' "$(posts)"
assert_contains "and tree"   'topic=wiki%2FMain%2Ftree'           "$(posts)"

# ── the ticket ───────────────────────────────────────────────────────────────
section 'the ticket is the ACL, in the token'
assert_eq 'an unrestricted user gets the whole tree' \
  '{"subscribe": ["wiki/{+rest}"]}' "$(claim "$ADMIN")"
assert_eq 'a Space-restricted user gets exactly their Spaces' \
  '{"subscribe": ["wiki/Main/{+rest}", "wiki//{+rest}", "wiki/user/2/{+rest}"]}' "$(claim "$ED")"

section 'a reader may subscribe — they have chats and mentions too'
r=$(get_as "$READER" 'api.php?action=realtime_ticket')
assert_contains "not refused" '"success":true' "$r"
assert_contains "and enabled" '"enabled":true' "$r"

section 'the browser is never handed the credential'
r=$(get_as "$ADMIN" 'api.php?action=realtime_ticket')
assert_contains "the body carries no token" '"token":null' "$r"
h=$(curl -si -b "$ADMIN" -c "$ADMIN" --max-time 15 "$WIKI_URL/api.php?action=realtime_ticket")
assert_contains "the cookie is HttpOnly"           'HttpOnly'                    "$h"
assert_contains "and scoped to the hub path"       'path=/.well-known/mercure'   "$h"

# ── the admin monitor ────────────────────────────────────────────────────────
# Publishing is fire-and-forget by design, so a broken hub is silent and every module
# quietly falls back to its slow poll. Admin -> Monitoring -> Mercure is the only place
# that failure is visible, which makes it worth asserting that it reports the truth.
section 'the monitor reports a working hub'
clear_pubs
r=$(get_as "$ADMIN" 'api.php?action=admin_realtime_status')
assert_contains "enabled"                 '"enabled":true'  "$r"
assert_contains "the key is set"          '"key_set":true'  "$r"
assert_contains "the probe published"     '"ok":true'       "$r"
assert_contains "the diag topic is the caller's own" '"topic":"wiki\/user\/1\/diag"' "$r"
# The absolute URL the subscribe check used — the row shows it, because it is derived
# from scheme + Host and so matches nothing an operator can read out of config.php.
assert_contains "the probed URL is reported" '.well-known\/mercure"' "$r"
assert_contains "  …resolved to an absolute URL" '"url":"http' "$r"
assert_not_contains "and never the key itself" "$KEY"       "$r"
assert_eq       "which took one real publish" "1" "$(npubs)"
assert_contains "carrying private=on"     'private=on'      "$(posts)"

section 'the round trip publishes a nonce the browser can match'
clear_pubs
r=$(post_as "$ADMIN" 'api.php?action=admin_realtime_test' '')
nonce=$(printf '%s' "$r" | python3 -c "import json,sys; print(json.load(sys.stdin)['data']['nonce'])")
assert_contains "the publish succeeded"   '"ok":true'       "$r"
if [ -n "$nonce" ]; then _pass "a nonce was issued"; else _fail "a nonce was issued" "$r"; fi
assert_contains "the nonce went to the hub" "$nonce"        "$(posts)"
assert_contains "on the diag topic"       'topic=wiki%2Fuser%2F1%2Fdiag' "$(posts)"

section 'the monitor is admin-only'
r=$(get_as "$ED" 'api.php?action=admin_realtime_status')
assert_contains "an editor is refused"    '"success":false' "$r"
r=$(post_as "$ED" 'api.php?action=admin_realtime_test' '')
assert_contains "  …for the test too"     '"success":false' "$r"

section 'a publish failure never breaks the write'
kill "$HUB_PID" 2>/dev/null; wait "$HUB_PID" 2>/dev/null; HUB_PID=
r=$(post_as "$ADMIN" 'api.php?action=create_file&space=Main' 'path=HubDown.md')
assert_contains    "the save still succeeds" '"success":true' "$r"
assert_file_exists "and the file is there"   "$WIKI_PAGES/Main/HubDown.md"

section 'and the monitor says so rather than staying silent'
# The whole point of the tab: with the hub gone the wiki carries on, so nothing else in
# the product changes appearance. This must not 500 either — a diagnostic that dies when
# the thing it diagnoses is broken is useless.
r=$(get_as "$ADMIN" 'api.php?action=admin_realtime_status')
assert_contains     "the action still answers"   '"success":true'  "$r"
assert_contains     "publish is reported failed" '"ok":false'      "$r"
assert_not_contains "and not as working"         '"ok":true'       "$r"

# ── TLS the server cannot verify, but the browser can ───────────────────────
# The reported symptom: "Nothing answered at the public URL. SSL certificate ... unable to
# get local issuer certificate", while the same address answers normally in a browser.
# Browsers use their own root store and chase a missing intermediate via the certificate's
# AIA extension; PHP's curl does neither. The probe must not report "nothing answered"
# when something did — it is an unauthenticated GET whose body is discarded, so it retries
# without verification and reports the degraded state instead of a false failure.
#
# openssl s_server rather than a PHP or Python one: php -S cannot do TLS at all, and this
# needs a certificate curl refuses. CN=localhost probed as 127.0.0.1 fails verification
# deterministically (curl errno 60 — the same class as a missing local issuer).
section 'an unverifiable certificate is a warning, not "nothing answered"'
enable_rt true
TLS_DIR=$WIKI_ROOT/tls; mkdir -p "$TLS_DIR"
TLS_PID=
if openssl req -x509 -newkey rsa:2048 -nodes -keyout "$TLS_DIR/k.pem" -out "$TLS_DIR/c.pem" \
     -days 2 -subj "/CN=localhost" >/dev/null 2>&1; then
    TLS_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
    ( cd "$TLS_DIR" && exec openssl s_server -accept "$TLS_PORT" -cert c.pem -key k.pem -www -quiet ) \
        > "$TLS_DIR/log" 2>&1 &
    TLS_PID=$!
    i=0
    until curl -sk -o /dev/null --max-time 2 "https://127.0.0.1:$TLS_PORT/"; do
        i=$((i + 1)); [ "$i" -gt 50 ] && break; sleep 0.2
    done
    set_public_url "https://127.0.0.1:$TLS_PORT/.well-known/mercure"
    r=$(get_as "$ADMIN" 'api.php?action=admin_realtime_status')
    assert_contains     "a hub was found anyway"      '"reason":"hub_unverified"' "$r"
    assert_contains     "the status code is reported" '"code":200'                "$r"
    assert_contains     "the TLS reason is kept"      'certificate'               "$r"
    assert_not_contains "not reported as unreachable" '"reason":"unreachable"'    "$r"
    assert_contains     "and the probed URL is shown" "127.0.0.1:$TLS_PORT"       "$r"
    kill "$TLS_PID" 2>/dev/null; wait "$TLS_PID" 2>/dev/null; TLS_PID=
    set_public_url '/.well-known/mercure'
else
    echo "    (openssl unavailable — TLS section skipped)"
fi

section 'with realtime off it says off, and probes nothing'
enable_rt false
r=$(get_as "$ADMIN" 'api.php?action=admin_realtime_status')
assert_contains "reported disabled"       '"enabled":false' "$r"
assert_contains "the reason is visible"   '"flag":false'    "$r"
assert_contains "and it still answers"    '"success":true'  "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
