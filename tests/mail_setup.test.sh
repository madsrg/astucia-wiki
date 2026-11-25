#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Email set up from the wiki (mailer.php, the setup checklist's email wizard), against a
# stand-in for the SendGrid and Mailgun APIs (MAIL_API_BASE_URL):
#
#   - a test sends with settings not yet saved, and reports the provider's own refusal
#   - saving writes mail_settings.json 0600, and the key never comes back to the browser
#   - a blank key keeps the stored one — for the same provider only
#   - the wiki then sends through them (send_email, Admin → Diagnostics' test)
#   - config.php, when it configures a provider completely, wins and the wizard cannot save;
#     when it leaves something out it does not block the wizard's settings
#   - administrators only

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap 'fixture_stop; [ -n "${MAIL_PID:-}" ] && kill "$MAIL_PID" 2>/dev/null; true' EXIT
fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc","email":"admin@example.com"},
  {"uid":3,"sub":"s3","name":"Ed","role":"editor","auth":"oidc"}]}'
AD=$WIKI_ROOT/jar-a; ED=$WIKI_ROOT/jar-e
fixture_login "$AD" "uid=1&sub=s1&name=Admin&role=admin&email=admin@example.com"
fixture_login "$ED" "uid=3&sub=s3&name=Ed&role=editor"

# ── the stand-in: records each call; a key containing "bad" is refused ───────
MAIL_DIR=$WIKI_ROOT/mailapi; mkdir -p "$MAIL_DIR"
cat > "$MAIL_DIR/router.php" <<'PHP'
<?php
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_POSTMARK_SERVER_TOKEN'] ?? $_SERVER['HTTP_API_KEY']
     ?? ('Basic ' . base64_encode(($_SERVER['PHP_AUTH_USER'] ?? '') . ':' . ($_SERVER['PHP_AUTH_PW'] ?? '')));
// Which header carried it, so a test can tell Postmark's from Brevo's from a bearer token.
$hdr = isset($_SERVER['HTTP_X_POSTMARK_SERVER_TOKEN']) ? 'X-Postmark-Server-Token' : (isset($_SERVER['HTTP_API_KEY']) ? 'api-key' : 'Authorization');
$auth = $hdr . ':' . $auth;
$body = file_get_contents('php://input') ?: json_encode($_POST);
file_put_contents(__DIR__ . '/calls', $_SERVER['REQUEST_URI'] . "\t" . $auth . "\t" . $body . "\n", FILE_APPEND);
$bad = strpos($auth, 'bad') !== false || strpos(base64_decode(substr($auth, strrpos($auth, ' ') + 1)) ?: '', 'bad') !== false;
$uri = $_SERVER['REQUEST_URI'];
if ($uri === '/email') {                       // Postmark
    if ($bad) { http_response_code(422); echo json_encode(['ErrorCode' => 10, 'Message' => 'The Server Token you provided in the X-Postmark-Server-Token request header was invalid.']); }
    else      { echo json_encode(['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'x']); }
    return true;
}
if ($uri === '/v3/smtp/email') {               // Brevo
    if ($bad) { http_response_code(401); echo json_encode(['code' => 'unauthorized', 'message' => 'Key not found']); }
    else      { http_response_code(201); echo json_encode(['messageId' => '<x@brevo>']); }
    return true;
}
if ($uri === '/emails') {                      // Resend
    if ($bad) { http_response_code(401); echo json_encode(['statusCode' => 401, 'name' => 'validation_error', 'message' => 'API key is invalid']); }
    else      { echo json_encode(['id' => 'x']); }
    return true;
}
if (strpos($uri, '/v3/mail/send') === 0) {
    if ($bad) { http_response_code(401); echo json_encode(['errors' => [['message' => 'The provided authorization grant is invalid, expired, or revoked']]]); }
    else      { http_response_code(202); }
} else {
    if ($bad) { http_response_code(401); echo 'Forbidden'; }
    else      { echo json_encode(['id' => '<x@mg>', 'message' => 'Queued. Thank you.']); }
}
return true;
PHP
MAIL_PORT=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
( cd "$MAIL_DIR" && exec php -S "127.0.0.1:$MAIL_PORT" router.php ) > "$MAIL_DIR/log" 2>&1 &
MAIL_PID=$!
until curl -s --max-time 2 "http://127.0.0.1:$MAIL_PORT/" >/dev/null 2>&1; do sleep 0.1; done
: > "$MAIL_DIR/calls"
printf "\ndefine('MAIL_API_BASE_URL', 'http://127.0.0.1:%s');\n" "$MAIL_PORT" >> "$WIKI_APP/config.php"
recompile

calls() { wc -l < "$MAIL_DIR/calls" | tr -d ' '; }
# config.php changes mid-run: opcache would keep serving the copy it compiled first.
printf '<?php if (function_exists("opcache_reset")) opcache_reset(); echo "ok";' > "$WIKI_APP/__test_opcache.php"
recompile() { curl -s "$WIKI_URL/__test_opcache.php" > /dev/null; }
SG='provider=sendgrid&from_email=wiki@example.com&from_name=Astucia&region=global'
STORE=$WIKI_SYS/mail_settings.json

section "administrators only"
assert_contains "an administrator reads the settings" '"success":true' "$(get_as "$AD" 'api.php?action=admin_mail_settings')"
assert_not_contains "an editor does not"  '"current"' "$(get_as "$ED" 'api.php?action=admin_mail_settings')"
assert_not_contains "nor tests"           '"success":true' "$(post_as "$ED" 'api.php?action=admin_mail_test' "$SG&api_key=SG.good&to=x@example.com")"
assert_not_contains "nor saves"           '"success":true' "$(post_as "$ED" 'api.php?action=admin_mail_save' "$SG&api_key=SG.good")"
assert_file_missing "nothing written by them" "$STORE"

section "nothing set up"
r=$(get_as "$AD" 'api.php?action=admin_mail_settings')
assert_contains "no source"            '"source":""' "$r"
assert_contains "the test goes to the administrator" '"test_to":"admin@example.com"' "$r"

section "a test with settings not yet saved"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=sendgrid&api_key=SG.x&from_email=not-an-address&to=me@example.com")
assert_contains "an invalid sender is named, before any request" '"missing":"from_email"' "$r"
assert_eq "no request made" '0' "$(calls)"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "$SG&api_key=SG.bad&to=me@example.com")
assert_contains "a refused key: the provider's own message" 'authorization grant is invalid' "$r"
assert_contains "with its status"                           'HTTP 401' "$r"
assert_file_missing "and nothing saved" "$STORE"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "$SG&api_key=SG.good&to=me@example.com")
assert_contains "a good key: accepted" '"success":true' "$r"
last=$(tail -1 "$MAIL_DIR/calls")
assert_contains "sent with that key"     'Authorization:Bearer SG.good' "$last"
assert_contains "to the test address"    'me@example.com'      "$last"
assert_contains "from the sender"        'wiki@example.com'    "$last"
assert_file_missing "a test saves nothing" "$STORE"

section "saving"
r=$(post_as "$AD" 'api.php?action=admin_mail_save' "$SG&api_key=SG.good-1234")
assert_contains "saved"                  '"source":"wiki"' "$r"
assert_file_exists "mail_settings.json"  "$STORE"
assert_eq "readable by the server only"  '600' "$(stat -c %a "$STORE")"
r=$(get_as "$AD" 'api.php?action=admin_mail_settings')
assert_not_contains "the key never comes back" 'SG.good-1234' "$r"
# json_encode writes the "…" as \u2026.
assert_contains "only a hint of it"      '"key_hint":"\u20261234"' "$r"
r=$(get_as "$AD" 'api.php?action=admin_setup_status')
assert_contains "the checklist's email item is done, from the wiki" '"id":"email","done":true,"source":"wiki","provider":"SendGrid"' "$r"

section "the wiki sends through them"
n=$(calls)
r=$(post_as "$AD" 'api.php?action=admin_send_test_email' '')
assert_contains "Diagnostics' test email" '"success":true' "$r"
assert_eq "one request" "$((n + 1))" "$(calls)"
assert_contains "with the stored key" 'Authorization:Bearer SG.good-1234' "$(tail -1 "$MAIL_DIR/calls")"

section "a blank key keeps the stored one — for the same provider only"
r=$(post_as "$AD" 'api.php?action=admin_mail_save' "provider=sendgrid&api_key=&from_email=other@example.com&from_name=X&region=eu")
assert_contains "kept for SendGrid" '"success":true' "$r"
assert_eq "the key is unchanged" '"SG.good-1234"' "$(php -r 'echo json_encode(json_decode(file_get_contents($argv[1]), true)["api_key"]);' "$STORE")"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=mailgun&api_key=&domain=mail.example.com&from_email=wiki@mail.example.com&to=me@example.com")
assert_contains "never tried against Mailgun" '"missing":"api_key"' "$r"

section "Mailgun"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=mailgun&api_key=key-bad&domain=mail.example.com&from_email=wiki@mail.example.com&to=me@example.com")
assert_contains "a refused key"      'Forbidden' "$r"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=mailgun&api_key=key-good&domain=mail.example.com&from_email=wiki@mail.example.com&to=me@example.com")
assert_contains "a good one"         '"success":true' "$r"
assert_contains "to the domain's messages" '/v3/mail.example.com/messages' "$(tail -1 "$MAIL_DIR/calls")"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=mailgun&api_key=key-good&domain=not a domain&from_email=wiki@mail.example.com&to=me@example.com")
assert_contains "a domain is checked" '"missing":"domain"' "$r"

section "Postmark, Brevo and Resend"
MT='from_email=wiki@example.com&from_name=Astucia&to=me@example.com'
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=postmark&api_key=pm-bad&$MT")
assert_contains "Postmark refusing: its Message" 'Server Token you provided' "$r"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=postmark&api_key=pm-good&$MT")
assert_contains "Postmark accepting" '"success":true' "$r"
last=$(tail -1 "$MAIL_DIR/calls")
assert_contains "  to /email"                      $'/email\t' "$last"
assert_contains "  with the server token header"   'X-Postmark-Server-Token:pm-good' "$last"
assert_contains "  on the transactional stream"    '"MessageStream":"outbound"' "$last"
assert_contains "  from the sender, named"         '"From":"Astucia <wiki@example.com>"' "$last"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=brevo&api_key=xkeysib-bad&$MT")
assert_contains "Brevo refusing: its message" 'Key not found' "$r"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=brevo&api_key=xkeysib-good&$MT")
assert_contains "Brevo accepting (201)" '"success":true' "$r"
last=$(tail -1 "$MAIL_DIR/calls")
assert_contains "  to /v3/smtp/email"              $'/v3/smtp/email\t' "$last"
assert_contains "  with the api-key header"        'api-key:xkeysib-good' "$last"
assert_contains "  the sender as an object"        '"sender":{"name":"Astucia","email":"wiki@example.com"}' "$last"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=resend&api_key=re_bad&$MT")
assert_contains "Resend refusing: its message" 'API key is invalid' "$r"
r=$(post_as "$AD" 'api.php?action=admin_mail_test' "provider=resend&api_key=re_good&$MT")
assert_contains "Resend accepting" '"success":true' "$r"
last=$(tail -1 "$MAIL_DIR/calls")
assert_contains "  to /emails"                     $'/emails\t' "$last"
assert_contains "  with a bearer token"            'Authorization:Bearer re_good' "$last"
assert_contains "  the recipient as a list"        '"to":["me@example.com"]' "$last"
r=$(post_as "$AD" 'api.php?action=admin_mail_save' "provider=resend&api_key=re_good-9876&from_email=wiki@example.com&from_name=Astucia&region=eu")
assert_contains "saved" '"provider":"resend"' "$r"
assert_contains "no region for Resend, whatever was sent" '"region":""' "$r"
assert_contains "the checklist names it" '"provider":"Resend"' "$(get_as "$AD" 'api.php?action=admin_setup_status')"
n=$(calls)
post_as "$AD" 'api.php?action=admin_send_test_email' '' > /dev/null
assert_contains "and the wiki sends through it" 'Authorization:Bearer re_good-9876' "$(tail -1 "$MAIL_DIR/calls")"

section "a sender name cannot add headers"
post_as "$AD" 'api.php?action=admin_mail_save' "$SG&api_key=SG.good-1234&from_name=Wiki%0D%0ABcc:%20evil@example.com" > /dev/null
name=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["from_name"];' "$STORE")
assert_not_contains "no line break stored" $'\n' "$name"
assert_not_contains "no carriage return"   $'\r' "$name"

section "config.php wins when it configures a provider"
cp "$WIKI_APP/config.php" "$WIKI_ROOT/config.bak"
printf "\ndefine('MAIL_PROVIDER', 'mailgun');\n" >> "$WIKI_APP/config.php"; recompile
r=$(get_as "$AD" 'api.php?action=admin_mail_settings')
assert_contains "naming a provider without the rest: called out" '"config_partial":true' "$r"
assert_contains "and the wiki's own settings still used"        '"source":"wiki"' "$r"
cp "$WIKI_ROOT/config.bak" "$WIKI_APP/config.php"
printf "\ndefine('SENDGRID_API_KEY', 'SG.fromconfig');\ndefine('SENDGRID_FROM_EMAIL', 'cfg@example.com');\n" >> "$WIKI_APP/config.php"; recompile
r=$(get_as "$AD" 'api.php?action=admin_mail_settings')
assert_contains "complete: config.php is in charge" '"source":"config"' "$r"
assert_not_contains "its key is not shown either"   'SG.fromconfig' "$r"
r=$(post_as "$AD" 'api.php?action=admin_mail_save' "$SG&api_key=SG.other")
assert_contains "the wizard cannot save over it" 'config.php' "$r"
assert_not_contains "refused"                    '"success":true' "$r"
n=$(calls)
post_as "$AD" 'api.php?action=admin_send_test_email' '' > /dev/null
assert_contains "and mail goes through config.php's key" 'Bearer SG.fromconfig' "$(tail -1 "$MAIL_DIR/calls")"
cp "$WIKI_ROOT/config.bak" "$WIKI_APP/config.php"; recompile

section "removing"
r=$(post_as "$AD" 'api.php?action=admin_mail_remove' '')
assert_contains "removed"         '"source":""' "$r"
assert_file_missing "the file is gone" "$STORE"
assert_not_contains "Diagnostics' test now says so" '"success":true' "$(post_as "$AD" 'api.php?action=admin_send_test_email' '')"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
