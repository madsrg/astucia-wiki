#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The workflow "Web request" action (workflow_runner.php, outbound_http.php).
#
#   - saving: http(s) only, the server part of the address fixed (no {{…}} in it), no
#     credentials in it, headers as "Name: value";
#   - addresses inside the server's network are refused by default — loopback, private,
#     link-local, CGNAT, IPv4-mapped — and allowed by WEB_REQUEST_ALLOW_PRIVATE;
#   - a real request against a local receiver: the method, values URL-encoded in the
#     address and JSON-escaped in the body (a page name with & and quotes stays data),
#     the headers; a line break in a value cannot add a header;
#   - redirects are not followed; a non-2xx answer is a failure;
#   - neither the query string nor a header value reaches the run's detail or its log;
#   - the Test preview renders the request and warns, without sending it.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start off || exit 1
trap 'fixture_stop; [ -n "${RCV_PID:-}" ] && kill "$RCV_PID" 2>/dev/null' EXIT
fixture_space Main
fixture_page 'Main/Main.md' '# Welcome'
fixture_page 'Main/Q3 & Q4 "draft".md' '# Plan'
curl -s "$WIKI_URL/api.php?action=list_spaces" > /dev/null
curl -s "$WIKI_URL/api.php?action=indexfiles&space=Main" > /dev/null
ADMIN=$WIKI_ROOT/jar-admin
fixture_login "$ADMIN" "uid=1&name=Admin&role=admin"

# ── a receiver that records what it is sent ──────────────────────────────────
RCV_DIR=$WIKI_ROOT/receiver; mkdir -p "$RCV_DIR"
RCV_LOG=$RCV_DIR/requests.log
cat > "$RCV_DIR/r.php" <<'R'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$h = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents(__DIR__ . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'],
    'ctype' => $_SERVER['CONTENT_TYPE'] ?? '', 'headers' => $h, 'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND);
if ($p === '/redirect') { header('Location: /landed', true, 302); exit; }
if ($p === '/fail') { http_response_code(500); echo 'boom'; exit; }
echo 'ok';
R
RCV_PORT=$(_free_port)
( cd "$RCV_DIR" && exec php -S "127.0.0.1:$RCV_PORT" r.php ) > /dev/null 2>&1 &
RCV_PID=$!
for i in $(seq 1 50); do curl -s "http://127.0.0.1:$RCV_PORT/ping" >/dev/null 2>&1 && break; sleep 0.1; done
: > "$RCV_LOG"
RCV="http://127.0.0.1:$RCV_PORT"

new_wf() { post_as "$ADMIN" 'api.php?action=admin_save_workflow' \
    "workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$1")"; }
wf_id() { python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("workflow",{}).get("id","") or "ERR:"+d.get("message",""))'; }
run_on() { post_as "$ADMIN" 'api.php?action=admin_run_workflow' "id=$1&test_space=Main&test_path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$2")"; }
web_wf() {  # name, method, url, headers, body_type, body
    python3 - "$@" <<'PY'
import json, sys
n, m, u, h, bt, b = sys.argv[1:7]
print(json.dumps({"name": n, "enabled": False, "space": "Main", "trigger": {"type": "page_updated"},
                  "actions": [{"type": "webhook", "method": m, "url": u, "headers": h, "body_type": bt, "body": b}]}))
PY
}
last_req() { tail -1 "$RCV_LOG" 2>/dev/null; }
set_private() {
    sed -i "s#^define('WEB_REQUEST_ALLOW_PRIVATE',.*#define('WEB_REQUEST_ALLOW_PRIVATE', $1);#" "$WIKI_APP/config.php"
    printf '<?php if (function_exists("opcache_reset")) opcache_reset(); echo "ok";' > "$WIKI_APP/__op.php"
    curl -s "$WIKI_URL/__op.php" > /dev/null
}
JSONBODY='{"page": "{{page}}", "path": "{{path}}", "event": "{{event}}"}'

section 'saving checks the address'
assert_contains 'only http and https'                 'must start with http' "$(new_wf "$(web_wf A POST 'ftp://example.com/x' '' json '')")"
assert_contains 'the server part is fixed'            'must be fixed'        "$(new_wf "$(web_wf A POST 'https://{{space}}.example.com/x' '' json '')")"
assert_contains 'no credentials in the address'       'credentials'          "$(new_wf "$(web_wf A POST 'https://user:pw@example.com/x' '' json '')")"
assert_contains 'headers are Name: value'             'Name: value'          "$(new_wf "$(web_wf A POST 'https://example.com/x' 'not a header' json '')")"
OK=$(new_wf "$(web_wf Hook POST "$RCV/ok?page={{page}}&token=QSECRET" $'Authorization: Bearer HSECRET\nX-Page: {{page}}' json "$JSONBODY")" | wf_id)
assert_not_contains 'positive control: a good one saves' 'ERR' "$OK"

section 'addresses inside the server are refused by default'
: > "$RCV_LOG"
R=$(run_on "$OK" 'Q3 & Q4 "draft".md')
assert_contains 'loopback is refused'                 'internal address' "$R"
assert_eq 'and nothing reached it'                    '' "$(last_req)"
ip_ok() { (cd "$WIKI_APP" && php -r 'require "outbound_http.php"; foreach (array_slice($argv, 1) as $ip) echo $ip, "=", wiki_http_ip_public($ip) ? "out" : "in", " ";' "$@"); }
assert_eq 'every internal range is recognised' \
  '127.0.0.1=in 10.1.2.3=in 172.16.0.1=in 192.168.1.1=in 169.254.169.254=in 100.64.0.1=in 0.0.0.0=in ::1=in ::ffff:127.0.0.1=in fd00::1=in fe80::1=in ' \
  "$(ip_ok 127.0.0.1 10.1.2.3 172.16.0.1 192.168.1.1 169.254.169.254 100.64.0.1 0.0.0.0 ::1 ::ffff:127.0.0.1 fd00::1 fe80::1)"
assert_eq 'positive control: public addresses are not' '8.8.8.8=out 2606:4700::1111=out ' "$(ip_ok 8.8.8.8 2606:4700::1111)"
P=$(post_as "$ADMIN" 'api.php?action=admin_test_workflow' "test_space=Main&test_path=Main.md&workflow=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$(web_wf T POST "$RCV/ok" '' json "$JSONBODY")")")
assert_contains 'the Test preview warns'              'webhook_blocked' "$P"
assert_eq '…without sending anything'                 '' "$(last_req)"

section 'allowed: the request as sent'
set_private true
: > "$RCV_LOG"
R=$(run_on "$OK" 'Q3 & Q4 "draft".md')
assert_contains 'the run succeeds'                    '"state":"ok"' "$R"
REQ=$(last_req)
field() { printf '%s' "$REQ" | python3 -c 'import json,sys;r=json.load(sys.stdin);print(eval(sys.argv[1]))' "$1"; }
assert_eq 'the method'                                POST "$(field 'r["method"]')"
assert_eq 'values are URL-encoded in the address'     '/ok?page=Q3%20%26%20Q4%20%22draft%22&token=QSECRET' "$(field 'r["uri"]')"
assert_eq 'the body is JSON'                          application/json "$(field 'r["ctype"]')"
assert_eq '…with the value as data, quotes and all'   'Q3 & Q4 "draft"' "$(field 'json.loads(r["body"])["page"]')"
assert_eq 'the headers arrive'                        'Bearer HSECRET' "$(field 'r["headers"].get("Authorization","")')"
assert_eq '…with values filled in'                    'Q3 & Q4 "draft"' "$(field 'r["headers"].get("X-Page","")')"
inj() { (cd "$WIKI_APP" && php -r 'require "config.php"; require "workflow_runner.php";
  [$m,$u,$h,$b] = wiki_wf_webhook_request(["method"=>"POST","url"=>"https://example.com/x","headers"=>"X-Page: {{page}}","body_type"=>"json","body"=>""], ["page"=>"a\r\nX-Evil: 1"]);
  echo json_encode($h);'); }
assert_eq 'a line break in a value cannot add a header' '{"X-Page":"a  X-Evil: 1"}' "$(inj)"

section 'what does not count as success'
RED=$(new_wf "$(web_wf Redirect GET "$RCV/redirect" '' json '')" | wf_id)
: > "$RCV_LOG"
R=$(run_on "$RED" 'Main.md')
assert_contains 'a redirect is a failure'             'redirects are not followed' "$R"
assert_not_contains '…and is not followed'            '/landed' "$(cat "$RCV_LOG")"
FAIL=$(new_wf "$(web_wf Failing POST "$RCV/fail" '' text 'x')" | wf_id)
assert_contains 'HTTP 500 is a failure'               'HTTP 500' "$(run_on "$FAIL" 'Main.md')"
assert_contains 'positive control: GET sends no body' '"body": ""' "$(python3 -c "import json;print(json.dumps(json.loads(open('$RCV_LOG').readlines()[0])))")"

section 'secrets stay out of the record'
STORE="$(cat "$WIKI_SYS/workflow_queue.json") $(cat "$WIKI_ROOT"/logs/workflows/*/* 2>/dev/null)"
assert_contains '(the runs are recorded)'             'HTTP 200' "$STORE"
assert_not_contains 'not the query string'            'QSECRET' "$STORE"
assert_not_contains 'nor a header value'              'HSECRET' "$STORE"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
