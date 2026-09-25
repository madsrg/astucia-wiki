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
#   - **Whether the hub honours a token's matchers** is upstream's contract, verified by
#     hand against a real Mercure 1.0.2 hub rather than by downloading 34 MB in CI. What
#     that check covers, and what nothing here can: a Main-only ticket minted by this
#     wiki's own code, against the Caddyfile tools/install-mercure.sh generates, receives
#     Main, root-level and its own user topics and is refused Bravo, Main2 and another
#     user's. The assertions below can see a token's *shape*; only that can see whether a
#     hub accepts it.
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
# The ticket cookie, raw. Mercure 1.0 reads one configured cookie name and the wiki's is
# prefix-less on purpose — a __Secure- cookie is refused by browsers over plain HTTP, which
# is an ordinary install here. See wiki_realtime_cookie_name().
ticket() {
    curl -si -b "$1" -c "$1" --max-time 15 "$WIKI_URL/api.php?action=realtime_ticket" \
      | grep -i '^set-cookie: mercure_access_token' \
      | sed 's/.*mercure_access_token=\([^;]*\).*/\1/' | tr -d '\r'
}
# One part of the token as JSON: `jwt_part 0` is the header, `jwt_part 1` the claims.
jwt_part() {
    python3 -c "
import sys, base64, json
t = sys.stdin.read().strip()
if not t: print('NO-COOKIE'); raise SystemExit
b = t.split('.')[$1]; b += '=' * (-len(b) % 4)
print(json.dumps(json.loads(base64.urlsafe_b64decode(b)), sort_keys=True))"
}
claim() {   # the subscribe matchers out of a ticket, in the order the wiki wrote them
    ticket "$1" | python3 -c "
import sys, base64, json
t = sys.stdin.read().strip()
if not t: print('NO-COOKIE'); raise SystemExit
b = t.split('.')[1]; b += '=' * (-len(b) % 4)
c = json.loads(base64.urlsafe_b64decode(b))
out = []
for d in c.get('authorization_details', []):
    if 'subscribe' in d.get('actions', []):
        out += d.get('topics', [])
print(json.dumps({'subscribe': out}, sort_keys=True))"
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

# ── topic encoding ───────────────────────────────────────────────────────────
# A Mercure topic is a URI and a selector is a URI template, so a raw space makes the topic
# invalid: the hub accepts the publish with 200 and matches it against nobody's selectors,
# delivering it to no one. Nothing reports an error — publishing is fire-and-forget, the
# stream stays open and every check in the monitor passes — so every page and chat whose
# name contained a space silently never pushed. Reported from production as "the page is not
# reloaded when the AI updates it", with all three monitor checks green.
#
# The browser must produce byte-identical topics, because dispatch is string equality on the
# topic. rawurlencode() and the adjusted encodeURIComponent() in modules/realtime must agree.
section 'a name with a space still produces a deliverable topic'
clear_pubs
r=$(post_as "$ADMIN" 'api.php?action=create_file&space=Main' "$(printf 'path=%s&content=x' 'Q3%20report.md')")
assert_contains "the write succeeded"      '"success":true' "$r"
assert_contains "the space is encoded"     'topic=wiki%2FMain%2Fpage%2FQ3%2520report.md' "$(posts)"
# The raw name does appear in the JSON payload, which is correct — it is the topic that has
# to be a valid URI, so the assertion has to look at the topic field alone.
topics=$(posts | tr '&' '\n' | sed -n 's/^topic=//p')
assert_not_contains "no raw space in any topic" '%20report' "$(printf '%s' "$topics" | tr -d '%25')"
assert_contains     "the page topic is there"   'Q3%2520report.md' "$topics"

section 'the encoding matches between PHP and the browser, character for character'
# Not "both look reasonable": identical. A mismatch means events arrive and are discarded,
# which is silent. The cases live in a file so neither side is retyped through three layers
# of shell quoting.
python3 - "$WIKI_ROOT/enc-cases.json" <<'CASES'
import json, sys
json.dump(["Q3 report.md", "Notes/Q3 report.md", "A&B (draft).md", "it's here!.md",
           "50%+more.md", "\u00c6blegr\u00f8d.md", "a~b_c-d.md", "Plain.md"],
          open(sys.argv[1], 'w'))
CASES
php_side=$(cd "$WIKI_APP" && php -r '
require "realtime.php";
$c = json_decode(file_get_contents($argv[1]), true);
// JSON_UNESCAPED_SLASHES, or PHP writes Notes\/… and node writes Notes/… — identical
// values that differ only in serialisation, which is not what is under test here.
echo json_encode(array_map("wiki_rt_path", $c), JSON_UNESCAPED_SLASHES);' "$WIKI_ROOT/enc-cases.json")
cat > "$WIKI_ROOT/enc.js" <<'ENCJS'
const fs = require('fs');
const seg = (s) => encodeURIComponent(String(s ?? ''))
    .replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
const path = (p) => String(p ?? '').split('/').map(seg).join('/');
const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
console.log(JSON.stringify(cases.map(path)));
ENCJS
js_side=$(node "$WIKI_ROOT/enc.js" "$WIKI_ROOT/enc-cases.json" 2>/dev/null || true)
if [ -z "$js_side" ]; then
    echo "    (node unavailable — PHP/JS parity not compared)"
else
    assert_eq "PHP and JS encode identically" "$php_side" "$js_side"
fi
# Compared as decoded values: PHP's json_encode escapes "/" as "\/", so matching the raw
# JSON text would fail on a difference that does not exist.
decoded=$(printf '%s' "$php_side" | python3 -c 'import json,sys; print("\n".join(json.load(sys.stdin)))')
assert_contains "a space becomes %20"        'Q3%20report.md'       "$decoded"
assert_contains "and a slash is preserved"   'Notes/Q3%20report.md' "$decoded"
assert_contains "utf-8 is encoded too"       '%C3%86blegr%C3%B8d'   "$decoded"
# And the module really uses it, rather than interpolating the raw path.
assert_contains "rtTopic encodes its path"   'rtPath(path)' "$(cat "$WIKI_APP/modules/realtime/index.js")"

section 'a Space whose name has a space is encoded in the matcher too'
# Otherwise the token carries an invalid pattern and the user receives nothing at all.
fixture_space 'Two Words'
r=$(claim "$ADMIN")
assert_contains "unrestricted is unaffected" 'wiki/*' "$r"
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc","spaces":["Main"]},
  {"uid":3,"sub":"s3","name":"Reader","role":"reader","auth":"oidc"},
  {"uid":4,"sub":"s4","name":"Two","role":"editor","auth":"oidc","spaces":["Two Words"]}]}'
fixture_login "$WIKI_ROOT/jar-2w" "uid=4&sub=s4&name=Two&role=editor&spaces=Two%20Words"
assert_contains "the Space name is encoded" 'wiki/Two%20Words/*' "$(claim "$WIKI_ROOT/jar-2w")"
assert_not_contains "not raw in the pattern" '"wiki/Two Words/' "$(claim "$WIKI_ROOT/jar-2w")"
# rawurlencode() leaves only A-Za-z0-9-_.~ alone, so every character URL Pattern treats as
# syntax is already a %XX literal by the time the hub compiles the matcher. Verified against
# a real 1.0.2 hub: a Space called 'A (draft)' grants exactly itself, not a capture group.
fixture_space 'A (draft)'
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Ed","role":"editor","auth":"oidc","spaces":["Main"]},
  {"uid":3,"sub":"s3","name":"Reader","role":"reader","auth":"oidc"},
  {"uid":4,"sub":"s4","name":"Two","role":"editor","auth":"oidc","spaces":["Two Words"]},
  {"uid":5,"sub":"s5","name":"Par","role":"editor","auth":"oidc","spaces":["A (draft)"]}]}'
fixture_login "$WIKI_ROOT/jar-par" "uid=5&sub=s5&name=Par&role=editor&spaces=A%20(draft)"
assert_contains "pattern syntax in a Space name is escaped" \
  'wiki/A%20%28draft%29/*' "$(claim "$WIKI_ROOT/jar-par")"

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
# `*` is a URL Pattern wildcard and matches across `/`, which is what `{+rest}` did before
# Mercure 1.0 retired URI Templates. The trailing separator is what carries the isolation:
# confirmed against a real 1.0.2 hub, `wiki/Main/*` matches wiki/Main/page/Note.md and does
# not match wiki/Main2/page/Leak.md — the same containment rule as service_auth.php's paths.
assert_eq 'an unrestricted user gets the whole tree' \
  '{"subscribe": [{"match": "wiki/*", "match_type": "urlpattern"}]}' "$(claim "$ADMIN")"
assert_eq 'a Space-restricted user gets exactly their Spaces' \
  '{"subscribe": [{"match": "wiki/Main/*", "match_type": "urlpattern"}, {"match": "wiki//*", "match_type": "urlpattern"}, {"match": "wiki/user/2/*", "match_type": "urlpattern"}]}' "$(claim "$ED")"

section 'the ticket is an RFC 9068 access token, not the retired 0.x claim'
# Mercure 1.0 rejects the bespoke `mercure` claim outright unless the hub is run in
# compatibility mode — which also switches off the exp, audience, at+jwt and issuer checks.
# Each of these is load-bearing on its own; a token missing any one is a 401 on subscribe,
# and the only symptom in the product is that realtime silently never goes live.
t=$(ticket "$ADMIN")
h=$(printf '%s' "$t" | jwt_part 0)
c=$(printf '%s' "$t" | jwt_part 1)
assert_contains "the header types it as an access token" '"typ": "at+jwt"' "$h"
assert_contains "signed HS256"                           '"alg": "HS256"'  "$h"
assert_contains "it names an issuer the hub trusts"      '"iss":'          "$c"
assert_contains "and the hub as its audience"            '.well-known/mercure' "$c"
assert_contains "it expires"                             '"exp":'          "$c"
assert_contains "grants live in authorization_details"   '"authorization_details"' "$c"
assert_not_contains "and not in the 0.x claim"           '"mercure":'      "$c"
# Bare strings are rejected by the hub: a matcher is an object, and the type is spelled out
# rather than left to the `exact` default, which would match no topic this wiki publishes.
assert_contains "each matcher says which kind it is"     '"match_type": "urlpattern"' "$c"

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
# The name the hub's `cookie_name` directive configures. 1.0 defaults to the __Secure-
# prefixed name, which a browser refuses over plain HTTP — that would leave realtime dead
# on a wiki served over HTTP on a LAN hostname, so both sides use the prefix-less name and
# the cookie is marked Secure per request instead.
assert_contains "under the name the hub is configured with" 'mercure_access_token' "$h"
assert_not_contains "not the 0.x name"             'mercureAuthorization'        "$h"

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
# The three values the hub's Caddyfile has to match. A mismatch is a flat 401 on publish
# with nothing else to see, so the monitor is where the two sides get compared.
assert_contains "the issuer is shown"     '"issuer":'      "$r"
assert_contains "the audience too"        '"resource_id":' "$r"
assert_contains "and the cookie name"     '"cookie_name":"mercure_access_token"' "$r"
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

section 'the hub probe keeps the real hostname and pins it to loopback'
# Probing https://127.0.0.1 can never verify: a public certificate is issued for the
# hostname and no CA will issue one for a loopback address, so TLS fails on a subject-name
# mismatch that says nothing about whether nginx forwards the path. Reported from
# production as "no alternative certificate subject name matches target ipv4 address".
#
# Tested against wiki_realtime_probe_targets() directly rather than through a forged Host
# header: overriding Host breaks the cookie the session rides on, so that route tests the
# fixture's plumbing instead of the code.
targets=$(cd "$WIKI_APP" && php -r '
require "realtime.php";
$out = [];
foreach ([
    "tls"          => ["HTTP_HOST" => "wiki.example.test", "SERVER_PORT" => "443"],
    "plain"        => ["HTTP_HOST" => "wiki.example.test", "SERVER_PORT" => "80"],
    "mapped_port"  => ["HTTP_HOST" => "127.0.0.1:8099",    "SERVER_PORT" => "80"],
    "odd_port"     => ["HTTP_HOST" => "wiki.example.test:8443", "SERVER_PORT" => "8443"],
] as $k => $srv) {
    $t = wiki_realtime_probe_targets($srv, "/.well-known/mercure");
    $out[$k] = ["url" => $t[0][0], "resolve" => $t[0][1], "fallback" => $t[1][1]];
}
$out["absolute"] = wiki_realtime_probe_targets(["SERVER_PORT" => "443"],
    "https://hub.example.net/.well-known/mercure");
echo json_encode($out, JSON_UNESCAPED_SLASHES);')

get() { printf '%s' "$targets" | python3 -c "
import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d = d[int(k)] if isinstance(d, list) else d[k]
print('' if d is None else d)" "$1"; }

assert_eq "TLS keeps the hostname"         "https://wiki.example.test/.well-known/mercure" "$(get tls.url)"
assert_eq "  …pinned to loopback"          "wiki.example.test:443:127.0.0.1"               "$(get tls.resolve)"
assert_eq "  …and a fallback without it"   ""                                              "$(get tls.fallback)"
assert_eq "plain http likewise"            "http://wiki.example.test/.well-known/mercure"  "$(get plain.url)"
# The original reason for probing loopback: the Host header's port is the published one.
assert_eq "a mapped port is not used"      "http://127.0.0.1/.well-known/mercure"          "$(get mapped_port.url)"
assert_eq "a non-default port is kept"     "http://wiki.example.test:8443/.well-known/mercure" "$(get odd_port.url)"
# An operator who set an absolute URL means it — no hostname rewriting, no pin.
assert_eq "an absolute URL is left alone"  "https://hub.example.net/.well-known/mercure"   "$(get absolute.0.0)"
assert_eq "  …and not pinned"              ""                                              "$(get absolute.0.1)"
assert_not_contains "never probes the bare IP over TLS" 'https://127.0.0.1' "$targets"

section 'the monitor is admin-only'
r=$(get_as "$ED" 'api.php?action=admin_realtime_status')
assert_contains "an editor is refused"    '"success":false' "$r"
r=$(post_as "$ED" 'api.php?action=admin_realtime_test' '')
assert_contains "  …for the test too"     '"success":false' "$r"

section 'a page edited outside the wiki announces that page, not just the tree'
# This is the case push could not help with. Nothing on the server watches the filesystem,
# so drift is found by index_sync_maybe() from the api.php bootstrap — and that used to
# announce only the tree. The file tree therefore refreshed while the *open page*, which
# subscribes per path, was never told and sat stale until its own fallback poll.
enable_rt true

# Two separate waits are needed here, for two unrelated reasons, and each one silently
# empties the publish log if it is missing:
#
#   - a scan is debounced for INDEX_SYNC_INTERVAL_SECONDS (30 by default, and a configured
#     0 is clamped up to it). Rather than rewrite config.php — which is opcached, so the
#     new value would not be in force for the very next request — drop the stamp file the
#     debounce reads.
#   - drift is `file mtime > the index's own updated stamp`, both at 1-second resolution
#     (the same resolution trap as the open-page watcher and the mentions marker). A file
#     written in the same second as its index entry is not newer, so it is not drift.
unstamp() { rm -f "$WIKI_SYS/index-sync/Main.json"; }
settle()  { sleep 1.1; }

post_as "$ADMIN" 'api.php?action=create_file&space=Main' 'path=Outside.md' > /dev/null
get_as "$ADMIN" 'api.php?action=indexfiles&space=Main' > /dev/null

settle; clear_pubs; unstamp
printf '# Outside\n\nEdited by a text editor.\n' > "$WIKI_PAGES/Main/Outside.md"
# Any request triggers the reconcile; the open-page watcher's own poll is one of these.
get_as "$ADMIN" 'api.php?action=tree_mtime&space=Main' > /dev/null
pubs=$(posts)
assert_contains "the tree is announced"           'topic=wiki%2FMain%2Ftree' "$pubs"
assert_contains "and so is the changed page"      'page%2FOutside.md'        "$pubs"
assert_contains "  as an update"                  '%22change%22%3A%22update%22'         "$pubs"
assert_contains "  privately, like every publish" 'private=on'               "$pubs"

settle; clear_pubs; unstamp
printf '# Appeared\n' > "$WIKI_PAGES/Main/Appeared.md"
get_as "$ADMIN" 'api.php?action=tree_mtime&space=Main' > /dev/null
assert_contains "a new file is announced" 'page%2FAppeared.md' "$(posts)"
assert_contains "  as a create"           '%22change%22%3A%22create%22'   "$(posts)"

settle; clear_pubs; unstamp
rm -f "$WIKI_PAGES/Main/Appeared.md"
get_as "$ADMIN" 'api.php?action=tree_mtime&space=Main' > /dev/null
assert_contains "a removed file is announced" 'page%2FAppeared.md' "$(posts)"
assert_contains "  as a delete"               '%22change%22%3A%22delete%22'   "$(posts)"

section 'a bulk reconcile does not turn into hundreds of publishes'
# Each publish is a synchronous POST to the hub inside whichever request happened to
# trigger the reconcile, so a `git pull` of a large tree is capped. Past the cap the tree
# event and the clients' fallback polls carry the rest, as they did for every file before.
settle; clear_pubs; unstamp
for i in $(seq 1 60); do printf '# Bulk %s\n' "$i" > "$WIKI_PAGES/Main/Bulk$i.md"; done
get_as "$ADMIN" 'api.php?action=tree_mtime&space=Main' > /dev/null
page_pubs=$(posts | grep -c 'page%2F' || true)
tree_pubs=$(posts | grep -c 'Ftree' || true)
printf '  %s\n' "$(_dim "60 new files → ${page_pubs} page events, ${tree_pubs} tree event(s)")"
assert_eq "the tree is announced once" '1' "$tree_pubs"
if [ "${page_pubs:-0}" -le 50 ] && [ "${page_pubs:-0}" -gt 0 ]; then
    _pass "page events are capped"
else
    _fail "page events are capped" "got ${page_pubs} of them"
fi

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
# Re-establish the session first: this section runs minutes into the suite, after several
# config rewrites and a killed hub, and an assertion that fails because the login has gone
# stale is indistinguishable from the behaviour under test.
fixture_login "$ADMIN" "uid=1&sub=s1&name=Admin&role=admin"
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
