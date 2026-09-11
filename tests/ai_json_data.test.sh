#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# An AI user working with .json data pages: finding one, reading it, charting it, writing
# one back.
#
# Reading and writing already worked; what did not was *finding*. wiki_search_pages had
# three branches — SQLite FTS, a no-query listing, and a no-FTS text scan — and only the
# first returned .json, because the index carries full text for .md and .json alike while
# the other two hard-filtered to .md. So a stored dataset was searchable on an install
# with SEARCH_ENGINE=sqlite and invisible on one without. Every search assertion here runs
# under both engines for that reason.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
mkdir -p "$WIKI_PAGES/Main/Data"
cat > "$WIKI_PAGES/Main/Data/Sales.json" <<'EOF'
{"currency":"EUR","monthly":[
 {"month":"jan","revenue":5000},{"month":"feb","revenue":6000},{"month":"mar","revenue":7500}]}
EOF
fixture_page 'Main/Notes/Plain.md' '# Plain page about revenue'
fixture_users '{"users":[{"uid":1,"sub":"s1","name":"Admin","role":"admin","auth":"oidc"}]}'

# Index the tree so the pages have ids (the tools work off index.json).
JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&sub=s1&name=Admin&role=admin"
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null

# One PHP driver, run once per search engine. Prints KEY=value lines the shell asserts on.
probe() {
    (cd "$WIKI_APP" && php -r '
require "config.php"; require "indexer.php"; require "space_settings.php";
require "search_index.php"; require "llm_providers.php"; require "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ix = new PageIndexer($space_dir);
$ai = ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true];
$call = fn($t, $in) => execute_ai_tool($t, $in, $ai, $ix, $space_dir);
$paths = fn($rows) => implode(",", array_column($rows, "path"));

echo "ENGINE=", SEARCH_ENGINE, "\n";
// Text query, and a pure listing — the two branches that used to exclude .json.
echo "Q_REVENUE=",  $paths(wiki_search_pages("revenue", $ix, $space_dir)), "\n";
echo "LIST_ALL=",   $paths(wiki_search_pages("", $ix, $space_dir, 3650)), "\n";
// Reading: the tool always could, its description just never said so.
$read = $call("wiki_read_page", ["path" => "Data/Sales.json"]);
echo "READ_OK=",    (strpos($read, "\"revenue\": 7500") !== false || strpos($read, "\"revenue\":7500") !== false) ? "yes" : "no($read)", "\n";
echo "READ_TXT=",   $call("wiki_read_page", ["path" => "Data/nope.txt"]), "\n";
// Writing a chart of what was read.
$chart = "# Sales\n\n```mermaid\nxychart\n    title \"Sales Revenue\"\n"
       . "    x-axis [jan, feb, mar]\n    y-axis \"Revenue (EUR)\" 0 --> 8000\n"
       . "    bar [5000, 6000, 7500]\n```\n";
echo "WRITE_MD=",   $call("wiki_write_page", ["path" => "Reports/Sales chart.md", "content" => $chart]), "\n";
// Writing data back.
echo "WRITE_JSON=", $call("wiki_write_json", ["path" => "Data/Totals.json", "content" => "{\"total\":18500}"]), "\n";
echo "WRITE_BAD=",  $call("wiki_write_json", ["path" => "Data/Bad.json", "content" => "{oops"]), "\n";
// Is what was just written findable? This is the FTS upsert.
echo "FIND_NEW=",   $paths(wiki_search_pages("18500", $ix, $space_dir)), "\n";
echo "FIND_CHART=", $paths(wiki_search_pages("Sales Revenue", $ix, $space_dir)), "\n";
' 2>&1)
}

for engine in basic sqlite; do
    section "search engine: $engine"
    sed -i "s#^define('SEARCH_ENGINE'.*#define('SEARCH_ENGINE', '$engine');#" "$WIKI_APP/config.php"
    rm -rf "$WIKI_PAGES/Main/Reports" "$WIKI_PAGES/Main/Data/Totals.json" "$WIKI_SYS/search.sqlite"
    # A fresh FTS index for the sqlite pass. indexfiles rebuilds it, but only once the
    # config above says sqlite — which is why this runs after the sed and not with the
    # initial index build.
    if [ "$engine" = sqlite ]; then
        idx=$(get_as "$JAR" 'api.php?action=indexfiles&space=Main')
        assert_contains "FTS index built" 'SQLite FTS index rebuilt' "$idx"
    fi
    out=$(probe)

    assert_contains "engine in force"            "ENGINE=$engine"          "$out"
    q=$(printf '%s' "$out" | sed -n 's/^Q_REVENUE=//p')
    l=$(printf '%s' "$out" | sed -n 's/^LIST_ALL=//p')
    assert_contains "text query finds the .json" 'Data/Sales.json'         "$q"
    assert_contains "  …and the .md as before"   'Notes/Plain.md'          "$q"
    assert_contains "listing includes the .json" 'Data/Sales.json'         "$l"
    assert_contains "  …and the .md as before"   'Notes/Plain.md'          "$l"
    assert_not_contains "no .chat/.list leakage" '.chat'                   "$l"

    assert_contains "reads the JSON document"    'READ_OK=yes'             "$out"
    assert_contains "refuses another extension"  'only .md, .list, .chat and .json' "$out"

    assert_contains "writes the chart page"      'Page created: Reports/Sales chart.md' "$out"
    assert_contains "writes a JSON data page"    'JSON page created: Data/Totals.json'  "$out"
    assert_contains "rejects invalid JSON"       'WRITE_BAD=Error: content is not valid JSON' "$out"
    assert_file_missing "  …and writes nothing"  "$WIKI_PAGES/Main/Data/Bad.json"

    # The chart itself, on disk and rendered by the same fence page_view looks for.
    assert_contains "xychart block on disk"      '```mermaid' "$(cat "$WIKI_PAGES/Main/Reports/Sales chart.md")"
    assert_contains "  …with the xychart keyword" 'xychart'   "$(cat "$WIKI_PAGES/Main/Reports/Sales chart.md")"

    # Search index kept in step by the write tools, not left to a manual reindex.
    assert_contains "finds the JSON it wrote"    'Data/Totals.json'        "$(printf '%s' "$out" | sed -n 's/^FIND_NEW=//p')"
    assert_contains "finds the page it wrote"    'Reports/Sales chart.md'  "$(printf '%s' "$out" | sed -n 's/^FIND_CHART=//p')"
done

section 'the tool descriptions say so, which is how the model finds out'
defs=$(cd "$WIKI_APP" && php -r '
require "config.php"; require "llm_providers.php"; require "wiki_ai_tools.php";
foreach (wiki_tool_definitions() as $t) echo $t["name"], "|", $t["description"], "\n";')
read_desc=$(printf '%s' "$defs" | sed -n 's/^wiki_read_page|//p')
srch_desc=$(printf '%s' "$defs" | sed -n 's/^wiki_search_pages|//p')
assert_contains "read_page advertises .json"  '.json'   "$read_desc"
assert_contains "  …and says what it is for"  'data'    "$read_desc"
assert_contains "search advertises .json"     '.json'   "$srch_desc"
assert_contains "wiki_write_json is offered"  'wiki_write_json|' "$defs"

section 'the system prompt names the chart types that exist'
prompt=$(cd "$WIKI_APP" && php -r 'require "config.php"; require "llm_providers.php"; require "ai_core.php";
echo wiki_markdown_features_prompt();' 2>/dev/null)
assert_contains "xychart is named"   'xychart' "$prompt"
assert_contains "pie is named"       'pie'     "$prompt"
assert_contains "points at .json"    '.json'   "$prompt"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
