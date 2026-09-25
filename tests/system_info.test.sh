#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# Admin → Monitoring → Wiki Info: what every component of this install actually is.
#
# The pane exists because for most of these rows the answer is **not in the source
# tree**. Three of the four CDN libraries float — marked and vanilla-jsoneditor are
# unpinned, mermaid floats inside major 11 — so the version an install runs is whatever
# was served to that browser, changing with no release here. The Mercure hub is the same
# problem from the other side: the image pins one version while a bare-metal install has
# whatever was last installed on it, which is exactly the mismatch that makes realtime
# fail silently.
#
# What is asserted here is the server half plus the guards. The browser half is reported
# by `modules/core/versions.js` from the loaders themselves and has no HTTP surface at
# all; what this suite can still do is prove the loaders record it, by reading the code
# for the calls, so a lazy import that quietly stops reporting is caught.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
                         {"uid":2,"name":"Ed","role":"editor","auth":"otp","email":"ed@example.com"}]}'
JAR=$WIKI_ROOT/jar; JARE=$WIKI_ROOT/jar-ed
fixture_login "$JAR"  "uid=1&auth=otp&name=Admin&role=admin"
fixture_login "$JARE" "uid=2&auth=otp&name=Ed&role=editor"

section 'it is admin-only'
# Version and extension inventory is reconnaissance; an editor has no business with it.
r=$(get_as "$JARE" 'api.php?action=admin_system_info')
assert_not_contains "an editor is refused" '"success":true' "$r"

section 'the server reports what it can see'
r=$(get_as "$JAR" 'api.php?action=admin_system_info')
assert_contains "the call succeeds"        '"success":true' "$r"
assert_contains "the wiki version is the VERSION file" \
                "\"wiki\":\"$(tr -d '\n' < "$WIKI_APP/VERSION")\"" "$r"
assert_contains "PHP's own version"        "\"php\":\"$(php -r 'echo PHP_VERSION;')\"" "$r"
assert_contains "the search engine in force" '"search":"' "$r"
assert_contains "and the authentication mode" '"auth":"otp"' "$r"

section 'every composer package, straight from the lock file'
# The lock file is the truth for these; a hand-maintained list would rot on the first
# `composer update`.
# Compared as parsed JSON rather than by grepping the response: PHP escapes the slash
# in a package name, and matching that through two layers of shell quoting is how the
# first version of this assertion came to look for a backslash that was not there.
missing=$(printf '%s' "$r" | python3 -c "
import json, sys
resp = json.load(sys.stdin)
lock = json.load(open('$WIKI_APP/composer.lock'))
want = {p['name'] for p in lock['packages']}
got  = {p['name'] for p in resp.get('composer', [])}
print(','.join(sorted(want - got)))")
assert_eq "no package is missing from the report" "" "$missing"
versions=$(printf '%s' "$r" | python3 -c "
import json, sys
print(','.join(sorted(p['name'] + '@' + p['version'] for p in json.load(sys.stdin).get('composer', []))))")
assert_contains "with its locked version" 'erusev/parsedown@1.8.0' "$versions"
printf '  %s\n' "$(_dim "reported: $versions")"

section 'the PHP extensions the features depend on, and why'
# Named with a reason, so a missing one reads as a cause rather than a curiosity.
assert_contains "curl, for the AI and realtime"  'AI, MCP and realtime publishing' "$r"
assert_contains "pdo_sqlite, for FTS"            'FTS5 search'                     "$r"
assert_contains "and each says whether it is there" '"loaded":true'                "$r"

section 'the hub version is read from a stamp, not guessed from the pin'
# The hub advertises no version over HTTP, so it records itself when installed. Absent a
# stamp the answer is empty — reporting the Dockerfile's pin instead would describe what
# the image intends rather than the hub this wiki talks to, and those differ routinely.
assert_contains "no stamp means no claim" '"mercure":""' "$r"
printf 'Mercure.rocks 1.0.2 Caddy v2.11.4\n' > "$WIKI_SYS/mercure.version"
r=$(get_as "$JAR" 'api.php?action=admin_system_info')
assert_contains "a stamp is reported verbatim" 'Mercure.rocks 1.0.2' "$r"

section 'the browser half records what each lazy loader actually got'
# No HTTP surface — this reads the modules, the same way page_type_vocabulary does.
assert_contains "mermaid reports its version"   "noteLoaded('mermaid'"   "$(cat "$WIKI_APP/modules/mermaid/index.js")"
assert_contains "cytoscape reports its version" "noteLoaded('cytoscape'" "$(cat "$WIKI_APP/modules/graph/index.js")"
assert_contains "the JSON editor too"           "noteLoaded('vanilla-jsoneditor'" "$(cat "$WIKI_APP/modules/json_view/index.js")"
assert_contains "and marked at startup"         "noteLoaded('marked'"    "$(cat "$WIKI_APP/script.js")"
# Every library the registry lists must have a loader that records it, or the pane shows
# "not loaded on this page" for ever and looks like a broken page rather than a stale list.
reg=$(cat "$WIKI_APP/modules/core/versions.js")
for key in marked mermaid cytoscape vanilla-jsoneditor; do
    assert_contains "  $key is in the registry" "key: '$key'" "$reg"
done

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
