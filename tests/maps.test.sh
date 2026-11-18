#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Maps on the server (maps.php): the address lookup Insert → Map uses, the admin settings,
# what the page is told, and the static export.
#
# The lookup service is a stub inside this fixture that logs what it was sent, so nothing
# here reaches OpenStreetMap. What matters most: the request identifies the software and
# nothing else (no user, no wiki), a repeat is served from the cache, two lookups are at
# least a second apart (Nominatim's policy), readers cannot use it, and switching maps off
# switches the lookup off too.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

# The wiki calls the stub lookup service on its own server, and PHP's built-in server is
# single-threaded unless told otherwise — one worker would wait on itself until the timeout.
export PHP_CLI_SERVER_WORKERS=4
fixture_start otp || exit 1
trap fixture_stop EXIT
fixture_space Main
fixture_users '{"users":[
  {"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"},
  {"uid":2,"sub":"s2","name":"Edna Editor","role":"editor","auth":"oidc","email":"edna@example.org"},
  {"uid":3,"sub":"s3","name":"Rita","role":"reader","auth":"oidc"}
]}'
ADMIN=$WIKI_ROOT/jar-a; ED=$WIKI_ROOT/jar-e; RD=$WIKI_ROOT/jar-r
fixture_login "$ADMIN" "uid=1&sub=s1&name=Admin&role=admin"
fixture_login "$ED"    "uid=2&sub=s2&name=Edna%20Editor&role=editor"
fixture_login "$RD"    "uid=3&sub=s3&name=Rita&role=reader"

# The stub answers like Nominatim (jsonv2) and logs each request: time, UA, query string.
GEOLOG="$WIKI_ROOT/geo.log"
cat > "$WIKI_APP/__geo.php" <<PHP
<?php
file_put_contents('$GEOLOG', json_encode(['t' => microtime(true), 'ua' => \$_SERVER['HTTP_USER_AGENT'] ?? '',
    'qs' => \$_SERVER['QUERY_STRING'] ?? '', 'q' => \$_GET['q'] ?? '']) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if ((\$_GET['q'] ?? '') === 'fail') { http_response_code(500); echo '{}'; exit; }
echo json_encode([
  ['place_id' => 1, 'osm_id' => 42, 'lat' => '55.6756600', 'lon' => '12.5697600', 'display_name' => 'Rådhuspladsen 1, København', 'type' => 'townhall'],
  ['place_id' => 2, 'lat' => 'not-a-number', 'lon' => '1', 'display_name' => 'Broken'],
  ['place_id' => 3, 'lat' => '56.1', 'lon' => '10.2', 'display_name' => '<b>Aarhus</b>', 'type' => 'city'],
]);
PHP
# Requests to the server's own network are off by default; the stub lives there.
sed -i "s/^define('WEB_REQUEST_ALLOW_PRIVATE'.*/define('WEB_REQUEST_ALLOW_PRIVATE', true);/" "$WIKI_APP/config.php"
# OPcache re-reads a changed file at most every opcache.revalidate_freq (2 s by default), so
# an edit to config.php is not seen until that has passed.
sleep 3
printf '{"maps":{"enabled":true,"geocoder":"%s/__geo.php","tiles":{}}}\n' "$WIKI_URL" > "$WIKI_SYS/settings.json"
hits() { [ -f "$GEOLOG" ] && wc -l < "$GEOLOG" | tr -d ' ' || echo 0; }

section 'who may look an address up'
r=$(get_as "$RD" 'api.php?action=geocode&q=Town+Hall&space=Main')
assert_not_contains "a reader is refused"                 '"success":true' "$r"
assert_eq           "  and the service was not asked"     0 "$(hits)"

section 'a lookup'
r=$(get_as "$ED" 'api.php?action=geocode&q=Town+Hall&lang=da&space=Main')
assert_contains     "an editor gets the places"           '"label":"R\u00e5dhuspladsen 1, K\u00f8benhavn"' "$r"
assert_contains     "  with coordinates as numbers"       '"lat":55.67566,"lon":12.56976' "$r"
assert_not_contains "  a place without numeric coordinates is dropped" 'Broken' "$r"
assert_not_contains "  and the service's own ids are not passed on" 'osm_id' "$r"
assert_contains     "  it was not from the cache"         '"cached":false' "$r"
log=$(cat "$GEOLOG")
assert_contains     "the request names the software"      '"ua":"AstuciaWiki' "$log"
assert_contains     "  and where to read about it"        'astucia.wiki' "$log"
assert_not_contains "  and never the person searching"    'Edna' "$log"
assert_not_contains "  nor their address"                 'edna@example.org' "$log"
assert_contains     "it asks for jsonv2, five at most"    'format=jsonv2&limit=5' "$log"
assert_contains     "  in the reader's language"          'accept-language=da' "$log"

section 'the cache and the one-second rule'
before=$(hits)
r=$(get_as "$ED" 'api.php?action=geocode&q=town+hall&lang=da&space=Main')
assert_contains     "a repeat (any case) comes from the cache" '"cached":true' "$r"
assert_eq           "  without asking the service"        "$before" "$(hits)"
get_as "$ED" 'api.php?action=geocode&q=First&space=Main'  > /dev/null
get_as "$ED" 'api.php?action=geocode&q=Second&space=Main' > /dev/null
gap=$(python3 -c 'import json,sys
rows=[json.loads(l) for l in open(sys.argv[1])]
t={r["q"]: r["t"] for r in rows}
print("ok" if t["Second"] - t["First"] >= 0.99 else "too-close:%.3f" % (t["Second"] - t["First"]))' "$GEOLOG")
assert_eq           "two lookups are at least a second apart" ok "$gap"

section 'failures'
r=$(get_as "$ED" 'api.php?action=geocode&q=fail&space=Main')
assert_contains     "a service error is reported"         'HTTP 500' "$r"
r=$(get_as "$ED" 'api.php?action=geocode&q=&space=Main')
assert_contains     "an empty address is refused"         'Type an address' "$r"

section 'admin settings'
r=$(post_as "$ED" 'api.php?action=admin_map_settings' 'settings={"enabled":false}')
assert_not_contains "an editor cannot change them"        '"success":true' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_map_settings' 'settings={"enabled":true,"tiles":{"streets":{"url":"https://tiles.example/{z}/{x}.png","attribution":"x"}}}')
assert_contains     "a tile address without {y} is refused" 'contain {z}, {x} and {y}' "$r"
r=$(post_as "$ADMIN" 'api.php?action=admin_map_settings' 'settings={"enabled":true,"tiles":{"streets":{"url":"https://tiles.example/{z}/{x}/{y}.png","attribution":""}}}')
assert_contains     "  and one without an attribution"    'attribution' "$r"
assert_not_contains "  (refused)"                          '"success":true' "$r"
geo_url="$WIKI_URL/__geo.php"
r=$(post_as "$ADMIN" 'api.php?action=admin_map_settings' "settings={\"enabled\":true,\"geocoder\":\"$geo_url\",\"tiles\":{\"streets\":{\"url\":\"https://tiles.example/{z}/{x}/{y}.png\",\"attribution\":\"<b>Ours</b>\"}}}")
assert_contains     "a valid custom tile server is saved" '"url":"https:\/\/tiles.example\/{z}\/{x}\/{y}.png"' "$r"
page=$(get_as "$ED" 'index.php?space=Main')
maps=$(printf '%s' "$page" | grep -o 'window.WIKI_MAPS = .*;' | head -1)
assert_contains     "the page is told the custom layer"   'https://tiles.example/{z}/{x}/{y}.png' "$maps"
assert_not_contains "  with the admin's attribution as text, not markup" '<b>' "$maps"
assert_contains     "  and the built-in satellite layer"  'World_Imagery' "$maps"

section 'switching maps off'
post_as "$ADMIN" 'api.php?action=admin_map_settings' "settings={\"enabled\":false,\"geocoder\":\"$geo_url\"}" > /dev/null
before=$(hits)
r=$(get_as "$ED" 'api.php?action=geocode&q=Elsewhere&space=Main')
assert_contains     "the lookup is switched off too"      'switched off' "$r"
assert_eq           "  and the service is not asked"      "$before" "$(hits)"
page=$(get_as "$ED" 'index.php?space=Main')
assert_contains     "the page is told"                    'window.WIKI_MAPS = {"enabled":false' "$page"

section "the server's own network"
post_as "$ADMIN" 'api.php?action=admin_map_settings' "settings={\"enabled\":true,\"geocoder\":\"$geo_url\"}" > /dev/null
sed -i "s/^define('WEB_REQUEST_ALLOW_PRIVATE'.*/define('WEB_REQUEST_ALLOW_PRIVATE', false);/" "$WIKI_APP/config.php"
sleep 3   # OPcache, as above
before=$(hits)
r=$(get_as "$ED" 'api.php?action=geocode&q=Internal&space=Main')
assert_contains     "a lookup service there needs WEB_REQUEST_ALLOW_PRIVATE" 'WEB_REQUEST_ALLOW_PRIVATE' "$r"
assert_eq           "  and is not reached without it"     "$before" "$(hits)"

section 'static export'
out=$(php -r 'require $argv[1]."/maps.php"; echo wiki_map_static_blocks("Before\n\n```map\naddress: Town <Hall>\nlat: 55.67566\nlon: 12.56976\nzoom: 16\n```\n\nAfter\n\n```map\nlat: nope\n```\n");' "$WIKI_APP" 2>&1)
assert_contains     "a map block becomes a link to the place" 'https://www.openstreetmap.org/?mlat=55.675660&amp;mlon=12.569760#map=16/55.675660/12.569760' "$out"
assert_contains     "  labelled with the address, escaped" 'Town &lt;Hall&gt;' "$out"
assert_contains     "  and a block without coordinates is left as it was" 'lat: nope' "$out"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
