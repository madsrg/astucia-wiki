#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# YAML front matter: a page that carries a `---` block of metadata.
#
# The wiki does not author that block — metadata lives in index.json — so everything here
# is about a file that already has one behaving correctly. Two invariants carry the whole
# feature, and both are cheap to break:
#
#   1. `block . body === the original bytes`. Nothing re-serialises the block, so a save
#      can never reformat somebody's YAML, reorder their keys or churn git.
#   2. Every reader of a page body reads it through wiki_fm_body(). There are eight of
#      them (search's two engines, the tag previews, backlinks, the graph, mentions, the
#      static export, the AI tools), and each one that forgets puts YAML in front of a
#      reader — or, in the AI case, hands a model instructions somebody wrote into a file.
#
# The asymmetric risk is the save path. The editor is handed the body *without* the block,
# so a save posts back only the body: miss the re-attach and editing any imported page
# silently deletes its metadata, with no error and nothing in the diff to notice.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
                         {"uid":2,"name":"Bob","role":"editor","auth":"otp","email":"bob@example.com"}]}'

# The page the HTTP assertions work on. The block holds a token that appears nowhere in
# the body (Zarquonium) and a mention of a real person, so "did the block leak into this
# surface" is one grep either way.
mkdir -p "$WIKI_PAGES/Main/Docs"
printf '%s' '---
title: Quarterly Report
author: Zarquonium
created: 2026-01-05
status: draft
tags: [finance, q1]
related: "[[Plain]]"
# internal: do not publish yet
---
# Quarterly Report

Revenue was up. Ask @Admin about the numbers.
' > "$WIKI_PAGES/Main/Docs/Report.md"

fixture_page 'Main/Docs/Plain.md'  '# Plain

No metadata here. Revenue is mentioned too.'

# Not front matter, and the distinction the parser exists to make: a thematic break with a
# line of prose under it, and a Setext heading.
fixture_page 'Main/Docs/Rule.md'   '---
A line of prose between two rules.
---

Body.'
fixture_page 'Main/Docs/Setext.md' 'Document Title
---

Body under a Setext heading.'

JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&auth=otp&name=Admin&role=admin"
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null


section 'the parser: what is a block, and is the split lossless'
# Every case is checked for both — what was detected, and that block+body is byte-identical
# to what went in. A parser that is right about detection and loses a byte is still a bug
# that eats somebody's file.
parse=$(cd "$WIKI_APP" && php -r '
require_once "frontmatter.php";
$cases = [
  "obsidian"  => "---\ntitle: A\ntags: [x, y]\n---\n# H\n\nBody\n",
  "crlf"      => "---\r\ntitle: A\r\n---\r\n# H\r\n",
  "bom"       => "\xEF\xBB\xBF# H\n\nNo block\n",
  "bom_fm"    => "\xEF\xBB\xBF---\ntitle: A\n---\n# H\n",
  "dots"      => "---\ntitle: A\n...\n# H\n",
  "empty"     => "---\ntitle: A\n---\n",
  "quoted"    => "---\ntitle: \"A: B \\\"q\\\"\"\nnote: '\''it'\''\n---\nBody\n",
  "block_seq" => "---\ntags:\n  - one\n  - two\nauthor: Z\n---\nBody\n",
  "rule"      => "---\nA line of prose.\n---\n\nBody\n",
  "hr_top"    => "---\n\n# H\n",
  "setext"    => "Title\n---\n\nBody\n",
  "no_fm"     => "# H\n\nBody\n",
  "unclosed"  => "---\ntitle: A\nand then nothing closes it\n",
  "late"      => "# H\n\n---\ntitle: A\n---\n",
];
foreach ($cases as $name => $raw) {
    $s = wiki_fm_split($raw);
    printf("%s has=%s lossless=%s keys=%s\n", $name,
        $s["block"] === "" ? "no" : "yes",
        ($s["block"] . $s["body"]) === $raw ? "ok" : "BROKEN",
        implode(",", array_keys($s["meta"])));
}
$s = wiki_fm_split($cases["quoted"]);
printf("QUOTED_TITLE=%s\n", $s["meta"]["title"] ?? "");
printf("QUOTED_NOTE=%s\n",  $s["meta"]["note"] ?? "");
$s = wiki_fm_split($cases["obsidian"]);
printf("FLOW_TAGS=%s\n",  implode("|", (array)$s["meta"]["tags"]));
$s = wiki_fm_split($cases["block_seq"]);
printf("SEQ_TAGS=%s\n",   implode("|", (array)$s["meta"]["tags"]));
printf("ORDER=%s\n", implode(",", array_keys(wiki_fm_ordered(
    ["cssclass" => "x", "tags" => [], "title" => "T", "author" => "A"]))));
' 2>&1)

assert_contains "an Obsidian block is one"          'obsidian has=yes'  "$parse"
assert_contains "CRLF is one too"                   'crlf has=yes'      "$parse"
assert_contains "a BOM alone is not"                'bom has=no'        "$parse"
assert_contains "a BOM before the block still is"   'bom_fm has=yes'    "$parse"
assert_contains "... closes a document"             'dots has=yes'      "$parse"
assert_contains "a block with an empty body"        'empty has=yes'     "$parse"
assert_contains "a block sequence"                  'block_seq has=yes' "$parse"
assert_contains "prose between rules is content"    'rule has=no'       "$parse"
assert_contains "a rule at the top is content"      'hr_top has=no'     "$parse"
assert_contains "a Setext heading is content"       'setext has=no'     "$parse"
assert_contains "a page with no block"              'no_fm has=no'      "$parse"
assert_contains "an unclosed block is content"      'unclosed has=no'   "$parse"
assert_contains "a block must be first"             'late has=no'       "$parse"
assert_not_contains "every split is lossless"       'BROKEN'            "$parse"
assert_contains "keys are parsed"          'obsidian has=yes lossless=ok keys=title,tags' "$parse"
assert_contains "a quoted value keeps its colon"    'QUOTED_TITLE=A: B "q"' "$parse"
assert_contains "single quotes come off"            "QUOTED_NOTE=it"    "$parse"
assert_contains "a flow list becomes a list"        'FLOW_TAGS=x|y'     "$parse"
assert_contains "a block list too"                  'SEQ_TAGS=one|two'  "$parse"
assert_contains "known keys are shown first"        'ORDER=title,author,tags,cssclass' "$parse"
comment_keys=$(cd "$WIKI_APP" && php -r '
require_once "frontmatter.php";
$s = wiki_fm_split("---\n# a note\ntitle: A\n---\nBody\n");
echo implode(",", array_keys($s["meta"]));')
assert_eq "a YAML comment is not a key" 'title' "$comment_keys"


section 'get hands over the body, the metadata, and the size on disk'
disk=$(wc -c < "$WIKI_PAGES/Main/Docs/Report.md" | tr -d ' ')
res=$(get_as "$JAR" 'api.php?action=get&space=Main&file=Docs/Report.md')
# The block is *deliberately* in the response, under `frontmatter`. What must not carry it
# is `data`, which is what the renderer and both editors are handed.
body=$(printf '%s' "$res" | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"])')
assert_contains     "the body is there"        'Revenue was up'        "$body"
assert_not_contains "the block is not"         'Zarquonium'            "$body"
assert_not_contains "nor its delimiter"        '---'                   "$body"
assert_contains     "metadata is returned"     '"frontmatter"'         "$res"
assert_contains     "  with the title"         'Quarterly Report'      "$res"
assert_contains     "  the author"             '"author":"Zarquonium"' "$res"
assert_contains     "  and the tags as a list" '"tags":["finance","q1"]' "$res"
# Load-bearing: the open-page watcher baselines from this and compares it against
# filesize(). Report the stripped length and it reloads the page on its first poll, for ever.
assert_contains "size is the size on disk" "\"size\":$disk," "$res"
plain=$(get_as "$JAR" 'api.php?action=get&space=Main&file=Docs/Plain.md')
assert_contains "a page without a block says null" '"frontmatter":null' "$plain"
rule=$(get_as "$JAR" 'api.php?action=get&space=Main&file=Docs/Rule.md')
assert_contains "a thematic break is left in the body" 'A line of prose' "$rule"
assert_contains "  and reports no metadata"            '"frontmatter":null' "$rule"


section 'save posts the body back — and must not lose the block'
curl -s -b "$JAR" -c "$JAR" --max-time 15 -X POST \
     --data-binary '# Quarterly Report

Revenue was up a lot. Ask @Admin about the numbers.
' "$WIKI_URL/api.php?action=save&space=Main&file=Docs/Report.md" > "$WIKI_ROOT/save.json"
saved=$(cat "$WIKI_ROOT/save.json")
on_disk=$(cat "$WIKI_PAGES/Main/Docs/Report.md")
assert_contains "the save succeeds"            '"success":true'      "$saved"
assert_contains "the block survived"           'author: Zarquonium'  "$on_disk"
assert_contains "  byte-identical"             'tags: [finance, q1]' "$on_disk"
assert_contains "the edit landed"              'up a lot'            "$on_disk"
assert_eq "the block is still first" '---' "$(head -1 "$WIKI_PAGES/Main/Docs/Report.md")"
assert_eq "and there is exactly one" '2' "$(grep -c '^---$' "$WIKI_PAGES/Main/Docs/Report.md")"
# The watcher re-baselines from this number; it has to be the bytes that landed, not the
# bytes that were posted.
real=$(wc -c < "$WIKI_PAGES/Main/Docs/Report.md" | tr -d ' ')
assert_contains "the reported size is the file" "\"size\":$real" "$saved"

# An upload or a paste that brings its own block replaces the old one rather than stacking.
curl -s -b "$JAR" -c "$JAR" --max-time 15 -X POST --data-binary '---
title: Replaced Wholesale
---
# New
' "$WIKI_URL/api.php?action=save&space=Main&file=Docs/Report.md" > /dev/null
assert_eq "a body with its own block does not double up" '2' \
    "$(grep -c '^---$' "$WIKI_PAGES/Main/Docs/Report.md")"
assert_contains "  and it is the new one" 'Replaced Wholesale' "$(cat "$WIKI_PAGES/Main/Docs/Report.md")"
assert_not_contains "  the old block is gone" 'Zarquonium' "$(cat "$WIKI_PAGES/Main/Docs/Report.md")"

# Put the original back for the reader assertions below.
printf '%s' '---
title: Quarterly Report
author: Zarquonium
created: 2026-01-05
status: draft
tags: [finance, q1]
related: "[[Plain]]"
# internal: do not publish yet
---
# Quarterly Report

Revenue was up. Ask @Admin about the numbers.
' > "$WIKI_PAGES/Main/Docs/Report.md"

# A page with no block at all must not grow one, and must not gain a byte.
before=$(wc -c < "$WIKI_PAGES/Main/Docs/Plain.md" | tr -d ' ')
curl -s -b "$JAR" -c "$JAR" --max-time 15 -X POST --data-binary '# Plain

Still no metadata.
' "$WIKI_URL/api.php?action=save&space=Main&file=Docs/Plain.md" > /dev/null
assert_eq "a page with no block gains none" '0' "$(grep -c '^---$' "$WIKI_PAGES/Main/Docs/Plain.md")"


section 'every reader of a page body reads the body'
# Search, both engines. The basic engine scans files; SQLite matches the FTS index — which
# is written by save, by the external-change reconcile and by the initial scan, so all
# three have to agree about what a page's text is.
for engine in basic sqlite; do
    sed -i "s#^define('SEARCH_ENGINE'.*#define('SEARCH_ENGINE', '$engine');#" "$WIKI_APP/config.php"
    rm -f "$WIKI_SYS/search.sqlite"
    get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
    hit=$(get_as "$JAR" 'api.php?action=search&space=Main&query=Revenue')
    assert_contains     "[$engine] a body word is found"  'Report.md' "$hit"
    assert_not_contains "[$engine] the header is the page's" 'do not publish' "$hit"
    meta=$(get_as "$JAR" 'api.php?action=search&space=Main&query=Zarquonium')
    assert_not_contains "[$engine] a block word is not"   'Report.md' "$meta"
done
sed -i "s#^define('SEARCH_ENGINE'.*#define('SEARCH_ENGINE', 'basic');#" "$WIKI_APP/config.php"

# Tag previews. The block would otherwise become the preview text of every imported page.
report_id=$(python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == "Docs/Report.md"))' "$WIKI_PAGES/Main/index.json")
post_as "$JAR" 'api.php?action=update_tags&space=Main' "id=$report_id&tags=%5B%22finance%22%5D" > /dev/null
bytag=$(get_as "$JAR" 'api.php?action=get_pages_by_tag&space=Main&tag=finance')
assert_contains     "a tagged page is listed"     'Report.md'      "$bytag"
assert_not_contains "its preview is body text"    'Zarquonium'     "$bytag"
# A YAML comment in the block is the trap: it starts with `#`, so a preview built from the
# raw file reports "do not publish yet" as the page's title.
assert_not_contains "the block's comment is not the title" 'do not publish' "$bytag"
assert_contains     "  the real header"           '# Quarterly Report' "$bytag"

# Mentions. `@Admin` inside a block is metadata, not somebody being written to.
ment=$(get_as "$JAR" 'api.php?action=get_mentions&name=Admin&uid=1')
assert_contains     "a mention in the body counts" 'Report.md' "$ment"
fixture_page 'Main/Docs/MetaOnly.md' '---
author: Bob
reviewer: "@Bob"
---
# No mention in this body.'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
JARB=$WIKI_ROOT/jarb
fixture_login "$JARB" "uid=2&auth=otp&name=Bob&role=editor"
mentb=$(get_as "$JARB" 'api.php?action=get_mentions&name=Bob&uid=2')
assert_not_contains "a mention only in a block does not" 'MetaOnly.md' "$mentb"

# The graph, the static export and the AI tools, each called directly — they are libraries
# rather than actions, and going through the UI would test the UI.
# A wikilink declared in the block is metadata, not a link the page makes. The graph and
# the backlink list read the same body for that reason, so that they cannot disagree.
plain_id=$(python3 -c 'import json,sys
ix = json.load(open(sys.argv[1]))
print(next(k for k, v in ix.items() if v.get("path") == "Docs/Plain.md"))' "$WIKI_PAGES/Main/index.json")
back=$(get_as "$JAR" "api.php?action=get_backlinks&space=Main&pageid=$plain_id")
assert_not_contains "a wikilink in a block is not a backlink" 'Report.md' "$back"

lib=$(cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "settings.php";
require_once "wiki_ai_tools.php"; require_once "graph.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ix = new PageIndexer($space_dir);
$ai = ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true];
$call = fn($t, $in) => execute_ai_tool($t, $in, $ai, $ix, $space_dir);

echo "READ_DEFAULT=", $call("wiki_read_page", ["path" => "Docs/Report.md"]), "\n---\n";
echo "SEARCH=", implode(",", array_column(wiki_search_pages("Zarquonium", $ix, $space_dir), "path")), "\n";
$g = (new WikiGraph($space_dir, $ix))->build();
echo "GRAPH_OK=", isset($g["nodes"]) ? "yes" : "no", "\n";
// Which pages does Report link to, as far as the graph is concerned?
$ids = [];
foreach ($g["nodes"] ?? [] as $n) $ids[$n["id"] ?? ""] = $n["label"] ?? ($n["path"] ?? "");
$edges = [];
foreach ($g["links"] ?? $g["edges"] ?? [] as $e) {
    $src = $ids[$e["source"] ?? ""] ?? "";
    $dst = $ids[$e["target"] ?? ""] ?? "";
    // `reference` is the wikilink edge; containment and shared-tag edges say nothing
    // about what the page links to.
    if (($e["type"] ?? "") === "reference" && stripos($src, "Report") !== false) $edges[] = $dst;
}
echo "GRAPH_FROM_REPORT=", implode("|", $edges), "\n";
' 2>&1)
assert_contains     "an AI reads the page"                 'Revenue was up' "$lib"
assert_not_contains "  but not its block, by default"      'Zarquonium'     "$lib"
ai_scan=$(printf '%s' "$lib" | sed -n 's/^SEARCH=//p')
assert_eq           "the AI text scan matches the index"   '' "$ai_scan"
assert_contains     "the graph still builds"               'GRAPH_OK=yes'   "$lib"
graph_edges=$(printf '%s' "$lib" | sed -n 's/^GRAPH_FROM_REPORT=//p')
assert_not_contains "and draws no edge from a block's link" 'Plain' "$graph_edges"

# The static exporter, run for real. It needs Parsedown, which composer installs and which
# an install without OIDC may not have — a missing vendor/ is a skip, not a failure.
if [ -f "$WIKI_APP/vendor/autoload.php" ]; then
    printf '%s' '---
title: Exported
author: Zarquonium
---
# Exported

Body of the exported page.
' > "$WIKI_PAGES/Export Me.md"
    get_as "$JAR" 'api.php?action=indexfiles' > /dev/null
    (cd "$WIKI_APP" && php export_static_site.php "$WIKI_ROOT/site") > "$WIKI_ROOT/export.log" 2>&1
    html=$(cat "$WIKI_ROOT/site/pages/"*.html 2>/dev/null)
    assert_contains     "the export renders the page"   'Body of the exported page' "$html"
    assert_not_contains "  and not its front matter"    'Zarquonium'                "$html"
    assert_not_contains "  nor a rule where it was"     'title: Exported'           "$html"
else
    printf '  %s\n' "$(_dim 'static export skipped — no vendor/autoload.php')"
fi


section 'showing a block to a model is a decision, and it is off'
gate=$(cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "space_settings.php";
require_once "search_index.php"; require_once "llm_providers.php"; require_once "settings.php";
require_once "wiki_ai_tools.php";
$space_dir = rtrim(PAGES_DIR, "/") . "/Main";
$ix = new PageIndexer($space_dir);
$ai = ["uid" => -9, "name" => "Bot", "role" => "editor", "is_ai" => true];
$call = fn($t, $in) => execute_ai_tool($t, $in, $ai, $ix, $space_dir);
echo "OFF_DEFAULT=", wiki_fm_expose_to_ai() ? "yes" : "no", "\n";
echo "OFF=", str_replace("\n", " ", $call("wiki_read_page", ["path" => "Docs/Report.md"])), "\n";
wiki_setting_set("frontmatter_expose_ai", true);
echo "ON_FLAG=", wiki_fm_expose_to_ai() ? "yes" : "no", "\n";
echo "ON=", str_replace("\n", " ", $call("wiki_read_page", ["path" => "Docs/Report.md"])), "\n";
wiki_setting_set("frontmatter_expose_ai", false);
// A tool write is a whole-body replacement, so it needs the same re-attach as a save.
echo "WRITE=", $call("wiki_write_page", ["path" => "Docs/Report.md",
      "content" => "# Quarterly Report\n\nRewritten by a tool.\n"]), "\n";
' 2>&1)
assert_contains "off unless enabled"           'OFF_DEFAULT=no' "$gate"
assert_contains "the flag turns on"            'ON_FLAG=yes'    "$gate"
off_line=$(printf '%s' "$gate" | sed -n 's/^OFF=//p')
on_line=$(printf  '%s' "$gate" | sed -n 's/^ON=//p')
assert_not_contains "with it off, no block"    'Zarquonium' "$off_line"
assert_contains     "with it on, the block"    'Zarquonium' "$on_line"
assert_contains     "  and still the body"     'Revenue'    "$on_line"
assert_contains "a tool write keeps the block" 'author: Zarquonium' "$(cat "$WIKI_PAGES/Main/Docs/Report.md")"
assert_contains "  and applies the edit"       'Rewritten by a tool' "$(cat "$WIKI_PAGES/Main/Docs/Report.md")"


section 'the flag reaches every entry point, not just the web one'
# mcp.php and the cron runner build their own include chains and neither pulls in
# settings.php, so frontmatter.php requires it rather than testing for it. Loaded the way
# those two do it, the setting still has to be readable.
reach=$(cd "$WIKI_APP" && php -r '
require_once "config.php"; require_once "indexer.php"; require_once "service_auth.php";
require_once "git_helpers.php"; require_once "wiki_ai_tools.php";
echo "HAS_SETTING_FN=", function_exists("wiki_setting") ? "yes" : "no", "\n";
wiki_setting_set("frontmatter_expose_ai", true);
echo "READS_FLAG=", wiki_fm_expose_to_ai() ? "yes" : "no", "\n";
wiki_setting_set("frontmatter_expose_ai", false);
echo "READS_OFF=", wiki_fm_expose_to_ai() ? "yes" : "no", "\n";' 2>&1)
assert_contains "settings.php comes along" 'HAS_SETTING_FN=yes' "$reach"
assert_contains "and the flag is read"     'READS_FLAG=yes'     "$reach"
assert_contains "  both ways"              'READS_OFF=no'       "$reach"


section 'the admin setting'
cfg=$(get_as "$JAR" 'api.php?action=admin_frontmatter_settings')
assert_contains "it reports the state"  '"expose_ai":false' "$cfg"
on=$(post_as "$JAR" 'api.php?action=admin_frontmatter_settings' 'expose_ai=1')
assert_contains "it can be turned on"   '"expose_ai":true'  "$on"
again=$(get_as "$JAR" 'api.php?action=admin_frontmatter_settings')
assert_contains "and it persists"       '"expose_ai":true'  "$again"
denied=$(post_as "$JARB" 'api.php?action=admin_frontmatter_settings' 'expose_ai=0')
assert_not_contains "an editor cannot set it" '"success":true' "$denied"
still=$(get_as "$JAR" 'api.php?action=admin_frontmatter_settings')
assert_contains "  and did not"         '"expose_ai":true'  "$still"
post_as "$JAR" 'api.php?action=admin_frontmatter_settings' 'expose_ai=0' > /dev/null

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
