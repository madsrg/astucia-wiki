#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# AI memory: what an AI User has learned, kept as ordinary pages in the space's memory/.
#
# The feature's whole claim is that a memory is a page — visible, editable, deletable,
# in git — rather than a hidden store. So most of what is worth asserting is *where the
# bytes land* and *who can reach them*, not that a tool returned a cheerful string.
#
# Four things this is really guarding:
#
#   - **Two switches, both required.** The space keeps memories; the AI User learns. Each
#     one alone does nothing, and an explicit per-AI "off" beats the wiki-wide default —
#     a memory outlives the run that wrote it, so an AI somebody exempted must not start
#     keeping them when the house default is switched on.
#   - **Per-space isolation.** Memories live inside the space they were learned in. A
#     shared memory space would be a channel between Spaces, which is the v2026.9.3
#     isolation bug rebuilt as a feature.
#   - **They stay out of everyone else's way.** Memory pages are ordinary indexed pages,
#     which is the point — and also means that unfiltered they pollute search and turn an
#     `@name` inside a remembered fact into a notification.
#   - **The tools are not even offered when memory is off**, so a model cannot spend a
#     call discovering that.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
. lib/fixture.sh

fixture_start otp || exit 1
trap fixture_stop EXIT

fixture_space Main
fixture_space Bravo
fixture_users '{"users":[{"uid":1,"name":"Admin","role":"admin","auth":"otp","email":"admin@example.com"},
                         {"uid":7,"name":"Alice","role":"editor","auth":"otp","email":"alice@example.com"}]}'
fixture_page 'Main/Notes.md' '# Notes about revenue'

JAR=$WIKI_ROOT/jar
fixture_login "$JAR" "uid=1&auth=otp&name=Admin&role=admin"
get_as "$JAR" 'api.php?action=list_spaces' > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null

MEM=$WIKI_PAGES/Main/memory

# One PHP driver per scenario: the tools are called exactly as chat, MCP and the cron
# runner call them, through execute_ai_tool().
probe() {
    (cd "$WIKI_APP" && MEMCFG="$1" php -r '
require "config.php"; require "indexer.php"; require "space_settings.php";
require "search_index.php"; require "llm_providers.php"; require "wiki_ai_tools.php";
require "ai_core.php";
$space = getenv("MEMSPACE") ?: "Main";
$space_dir = rtrim(PAGES_DIR, "/") . "/" . $space;
$ix  = new PageIndexer($space_dir);
$ai  = ["uid" => -9, "name" => "Bot", "role" => getenv("MEMROLE") ?: "editor", "is_ai" => true,
        "ai_config" => ["memory" => getenv("MEMCFG")]];
$call = fn($t, $in = []) => execute_ai_tool($t, $in, $ai, $ix, $space_dir);
foreach (explode("|", getenv("MEMSTEPS")) as $step) {
    if ($step === "") continue;
    [$tool, $json] = array_pad(explode(" ", $step, 2), 2, "{}");
    echo strtoupper($tool), "=", str_replace("\n", " ", $call($tool, json_decode($json, true) ?: [])), "\n";
}
echo "TOOLS=", implode(",", array_column(wiki_tool_definitions(
        wiki_ai_memory_enabled($ai["ai_config"]) && wiki_space_dir_memory($space_dir)), "name")), "\n";
echo "PROMPT=", str_replace("\n", "\\n", wiki_memory_prompt($space_dir, $ix, $ai["ai_config"])), "\n";
' 2>&1)
}

# ── off by default ───────────────────────────────────────────────────────────
section 'with the space setting off, nothing can be remembered'
r=$(MEMSTEPS='wiki_remember {"title":"Deploys are on Thursday","fact":"The team ships on Thursday afternoons."}' probe 'on')
assert_contains "the tool refuses"        'does not keep AI memories' "$r"
assert_contains "and names where to fix it" 'Space settings'          "$r"
[ -d "$MEM" ] && _fail "no folder is created" "memory/ exists" || _pass "no folder is created"
assert_not_contains "the memory tools are not even offered" 'wiki_remember' "$(printf '%s' "$r" | grep '^TOOLS=')"
assert_contains "while the ordinary ones still are"          'wiki_write_page' "$(printf '%s' "$r" | grep '^TOOLS=')"
assert_eq "and the prompt says nothing about memory" "PROMPT=" "$(printf '%s' "$r" | grep '^PROMPT=')"

section 'turning the space on is not enough on its own'
r=$(post_as "$JAR" 'api.php?action=admin_set_space_memory' 'space_name=Main&memory=1')
assert_contains "the setting saved" '"success":true' "$r"
r=$(MEMSTEPS='wiki_remember {"title":"X","fact":"y"}' probe 'off')
assert_contains "an AI with learning off is refused" 'learning is switched off' "$r"
assert_contains "and told where that lives"          'AI Users'                 "$r"

# ── the happy path ───────────────────────────────────────────────────────────
section 'with both switches on, a fact becomes a page'
r=$(MEMSTEPS='wiki_remember {"title":"Deploys go out on Thursday afternoons","fact":"The team ships on Thursday afternoons, never on Fridays.","tags":["process","deploys"]}' probe 'on')
assert_contains "the tool confirms"  'Remembered: Deploys go out on Thursday afternoons' "$r"
assert_file_exists "the memory is a page" "$MEM/Deploys go out on Thursday afternoons.md"
body=$(cat "$MEM/Deploys go out on Thursday afternoons.md")
assert_contains "carrying the fact"        'never on Fridays' "$body"
assert_contains "and who formed it"        'createdBy: Bot'   "$body"
assert_contains "and when"                 'created: 20'      "$body"

section 'it is indexed, tagged and listed back in the prompt'
r=$(MEMSTEPS='' probe 'on')
assert_contains "the prompt lists what is known" 'Deploys go out on Thursday afternoons' "$r"
assert_contains "with its tags"                  '[process, deploys]'                    "$r"
assert_contains "and the protocol to use it"     'wiki_recall'                           "$r"

section 'recall reads it back'
r=$(MEMSTEPS='wiki_recall {"query":"thursday"}' probe 'on')
assert_contains "the fact comes back"   'never on Fridays' "$r"
r=$(MEMSTEPS='wiki_recall {"query":"something else entirely"}' probe 'on')
assert_contains "a miss says so plainly" 'No memories match' "$r"

section 'remembering the same title again corrects rather than duplicates'
r=$(MEMSTEPS='wiki_remember {"title":"Deploys go out on Thursday afternoons","fact":"Actually the team ships on Wednesdays now."}' probe 'on')
assert_contains "it reports an update"  'Updated memory' "$r"
assert_eq "and there is still one page" "1" "$(ls "$MEM" | wc -l)"
body=$(cat "$MEM/Deploys go out on Thursday afternoons.md")
assert_contains "with the new fact"      'ships on Wednesdays' "$body"
assert_not_contains "and not the old one" 'never on Fridays'    "$body"
# The page is being corrected, not formed again, so its origin is kept.
assert_contains "the original created date survives" 'created: 20' "$body"

section 'and it can be forgotten'
r=$(MEMSTEPS='wiki_forget {"title":"Deploys go out on Thursday afternoons"}' probe 'on')
assert_contains "the tool confirms" 'Forgotten' "$r"
[ -e "$MEM/Deploys go out on Thursday afternoons.md" ] \
    && _fail "the page is gone" "still there" || _pass "the page is gone"
r=$(MEMSTEPS='wiki_forget {"title":"Never existed"}' probe 'on')
assert_contains "forgetting nothing says so" 'no memory with that title' "$r"

# ── the tri-state ────────────────────────────────────────────────────────────
section 'the wiki-wide default applies, and an explicit Off still beats it'
# This is the whole reason it is a tri-state rather than a checkbox: switching the house
# default on must not start keeping memories for an AI somebody deliberately exempted,
# and a memory outlives the run that wrote it.
r=$(post_as "$JAR" 'api.php?action=admin_ai_memory_settings' 'enabled=1')
assert_contains "the default is on"  '"enabled":true' "$r"
r=$(MEMSTEPS='wiki_recall {}' probe '')
assert_not_contains "an AI with no opinion now learns" 'learning is switched off' "$r"
r=$(MEMSTEPS='wiki_recall {}' probe 'off')
assert_contains "an AI set to Off stays off"           'learning is switched off' "$r"
r=$(post_as "$JAR" 'api.php?action=admin_ai_memory_settings' 'enabled=0')
assert_contains "the default goes back off" '"enabled":false' "$r"
r=$(MEMSTEPS='wiki_recall {}' probe '')
assert_contains "and the undecided AI follows it down" 'learning is switched off' "$r"

# ── a reader may recall but not write ────────────────────────────────────────
section 'a reader-role AI can read memories but not write them'
MEMSTEPS='wiki_remember {"title":"A","fact":"b"}' MEMROLE=reader r=$(MEMROLE=reader MEMSTEPS='wiki_remember {"title":"A","fact":"b"}' probe 'on')
assert_contains "writing is refused"  'cannot write memories' "$r"
r=$(MEMROLE=reader MEMSTEPS='wiki_recall {}' probe 'on')
assert_not_contains "but recall is not" 'read-only' "$r"

# ── isolation ────────────────────────────────────────────────────────────────
section 'memories never leave the space they were learned in'
MEMSTEPS='wiki_remember {"title":"Main only fact","fact":"This belongs to Main."}' probe 'on' > /dev/null
post_as "$JAR" 'api.php?action=admin_set_space_memory' 'space_name=Bravo&memory=1' > /dev/null
get_as "$JAR" 'api.php?action=indexfiles&space=Bravo' > /dev/null
r=$(MEMSPACE=Bravo MEMSTEPS='wiki_recall {}' probe 'on')
assert_not_contains "Bravo cannot recall Main's memory" 'Main only fact' "$r"
r=$(MEMSPACE=Bravo MEMSTEPS='' probe 'on')
assert_not_contains "nor is it in Bravo's prompt"       'Main only fact' "$r"
assert_contains     "which says Bravo has learned nothing" 'not remembered anything' "$r"

# ── a frozen space ───────────────────────────────────────────────────────────
section 'freezing the space stops memories being written, without a second switch'
# A memory is content, so the read-only guard that stops every other write stops this one
# too — wiki_remember and wiki_forget are in WIKI_AI_WRITE_TOOLS, checked at the one point
# chat, MCP and the cron runner converge. Worth asserting rather than assuming: the space
# setting stays *on* while frozen, so nothing about the configuration says it is inert.
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=1' > /dev/null
r=$(MEMSTEPS='wiki_remember {"title":"Learned while frozen","fact":"should not be kept"}' probe 'on')
assert_contains "remembering is refused"  'read-only' "$r"
[ -e "$MEM/Learned while frozen.md" ] && _fail "and nothing was written" "the page exists" \
    || _pass "and nothing was written"
r=$(MEMSTEPS='wiki_forget {"title":"Main only fact"}' probe 'on')
assert_contains "forgetting is refused too" 'read-only' "$r"
assert_file_exists "so an existing memory survives" "$MEM/Main only fact.md"
# Reading is not a write: a frozen space is still readable, and so is what it remembers.
r=$(MEMSTEPS='wiki_recall {}' probe 'on')
assert_contains "but recall still works" 'Main only fact' "$r"
post_as "$JAR" 'api.php?action=admin_set_space_readonly' 'space_name=Main&readonly=0' > /dev/null
r=$(MEMSTEPS='wiki_remember {"title":"Learned after thawing","fact":"kept"}' probe 'on')
assert_contains "and unfreezing restores it" 'Remembered' "$r"

# ── staying out of the way ───────────────────────────────────────────────────
section 'a memory does not pollute ordinary search'
# Paired with a positive control on the *same* word: without it this passes whenever the
# search happens to find nothing at all, which is exactly what it did the first time.
MEMSTEPS='wiki_remember {"title":"The sync runs at zanzibar oclock","fact":"Nightly sync is scheduled for zanzibar oclock."}' probe 'on' > /dev/null
fixture_page 'Main/Schedule.md' '# Schedule

The nightly sync runs at zanzibar oclock.'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
r=$(get_as "$JAR" 'api.php?action=search&space=Main&query=zanzibar')
assert_contains     "an ordinary page with the word is found" 'Schedule.md' "$r"
# Not 'memory/': json_encode escapes the slash, so the literal string never appears and
# the assertion passes whatever the search returns. Match the memory's own title instead.
assert_not_contains "but the memory holding it is not" 'zanzibar oclock.md' "$r"
r=$(MEMSTEPS='wiki_recall {"query":"zanzibar"}' probe 'on')
assert_contains "while the AI can still recall it" 'zanzibar oclock' "$r"

section 'nor does it notify anyone it happens to name'
# An AI that remembers "Alice prefers short summaries" must not notify Alice — again on
# every edit of that memory, and again in her digest. Positive control on the same name,
# or a mention scanner that had stopped finding anything would pass this on its own.
MEMSTEPS='wiki_remember {"title":"Alice prefers short summaries","fact":"@Alice asked for one-paragraph answers."}' probe 'on' > /dev/null
fixture_page 'Main/Standup.md' '# Standup

Ask @Alice about the release notes.'
get_as "$JAR" 'api.php?action=indexfiles&space=Main' > /dev/null
ALICE=$WIKI_ROOT/jar-alice
fixture_login "$ALICE" "uid=7&auth=otp&name=Alice&role=editor"
# The scanner takes the name and uid as parameters — called without them it answers an
# empty list for everybody, which is how the first version of this passed while proving
# nothing at all.
r=$(get_as "$ALICE" 'api.php?action=get_mentions&name=Alice&uid=7')
assert_contains     "an ordinary page naming her is a mention" 'Standup.md' "$r"
assert_not_contains "the memory naming her is not"             'Alice prefers short summaries' "$r"

printf '\n'
exit $(( ASSERT_FAIL > 0 ))
