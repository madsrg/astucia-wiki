#!/usr/bin/env bash
# Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
# Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
# or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
#
# The template language of page generators (mustache.php): what a template renders to, what
# it refuses, and — the part that matters most — that a value from the data reads as plain
# text however it is written.
#
# A value can come from a list in another Space, edited by people who cannot edit the page
# it lands in. So escaping is not cosmetics: a value must not be able to add HTML, a
# transclusion, a wikilink or a mention to that page. Each of those is pinned here against
# the real pattern that would act on it (the mention scanner's, the include regex), not
# against a guess at what the output should look like.
#
# No server: the library is pure, so this runs it directly from the working tree.

set -uo pipefail
cd "$(dirname "$0")"
. lib/assert.sh
APP=$(cd .. && pwd)

# render <template> <context-json> [none]  — prints the output, or ERR:<code>:<line>
render() {
    TPL="$1" CTX="$2" MODE="${3:-markdown}" php -r '
        require getenv("APP") . "/mustache.php";
        try {
            echo wiki_tpl_render(getenv("TPL"), json_decode(getenv("CTX"), true) ?? [],
                                 ["escape" => getenv("MODE")]);
        } catch (WikiTemplateError $e) { echo "ERR:", $e->error, ":", $e->templateLine; }'
}
export APP

# Run PHP with the library and mentions.php loaded (the latter only for its pattern).
php_lib() { php -r 'require getenv("APP") . "/mustache.php"; '"$1"; }

section 'Variables'
assert_eq 'a name'                    'Hi Ann'            "$(render 'Hi {{name}}' '{"name":"Ann"}')"
assert_eq 'spaces inside the tag'     'Hi Ann'            "$(render 'Hi {{ name }}' '{"name":"Ann"}')"
assert_eq 'a missing name is empty'   'Hi !'              "$(render 'Hi {{nobody}}!' '{}')"
assert_eq 'a dotted name'             'Oslo'              "$(render '{{addr.city}}' '{"addr":{"city":"Oslo"}}')"
assert_eq 'a dotted name, missing'    '[]'                "$(render '[{{addr.zip}}]' '{"addr":{"city":"Oslo"}}')"
assert_eq 'a number'                  '42'                "$(render '{{n}}' '{"n":42}')"
assert_eq 'unicode key'               'Århus'             "$(render '{{by}}' '{"by":"Århus"}')"
assert_eq 'single braces are left alone' '{filename} {include:3}' \
    "$(render '{filename} {include:3}' '{}')"

section 'A value that says how it reads (a list group)'
assert_eq 'a value with _text prints that'       'Main St 1, Oslo' "$(render '{{a}}' '{"a":{"_text":"Main St 1, Oslo","city":"Oslo"}}')"
assert_eq '  and is still reached inside'        'Oslo'            "$(render '{{a.city}}' '{"a":{"_text":"x","city":"Oslo"}}')"
assert_eq 'a list of them, joined with "; "'     '1111; 2222'      "$(render '{{c}}' '{"c":[{"_text":"1111"},{"_text":""},{"_text":"2222"}]}')"
assert_eq '  escaped like any value'             '&lt;b&gt;'       "$(render '{{c}}' '{"c":[{"_text":"<b>"}]}')"
assert_eq 'any other structure prints nothing'   '[]'              "$(render '[{{a}}]' '{"a":{"city":"Oslo"}}')"
assert_eq '  nor does a plain list'              '[]'              "$(render '[{{a}}]' '{"a":["x","y"]}')"

section 'Sections'
# `$(…)` drops trailing newlines, so templates whose last line matters end in a sentinel.
assert_eq 'a list, once per item' '- a
- b
END' "$(render '{{#rows}}
- {{name}}
{{/rows}}
END' '{"rows":[{"name":"a"},{"name":"b"}]}')"
assert_eq 'an empty list renders nothing' 'x' "$(render 'x{{#rows}}y{{/rows}}' '{"rows":[]}')"
assert_eq 'a value shows its section once' '(yes)' "$(render '{{#v}}(yes){{/v}}' '{"v":"text"}')"
assert_eq 'an empty string is false'   ''        "$(render '{{#v}}yes{{/v}}' '{"v":""}')"
assert_eq '"0" is a value, not false'  'yes'     "$(render '{{#v}}yes{{/v}}' '{"v":"0"}')"
assert_eq 'inverted on empty'          'none'    "$(render '{{^v}}none{{/v}}' '{"v":""}')"
assert_eq 'inverted on a value'        ''        "$(render '{{^v}}none{{/v}}' '{"v":"x"}')"
assert_eq '{{.}} is the current item'  'a,b,'    "$(render '{{#xs}}{{.}},{{/xs}}' '{"xs":["a","b"]}')"
assert_eq 'an outer name inside a section' 'T:a T:b ' \
    "$(render '{{#xs}}{{t}}:{{.}} {{/xs}}' '{"t":"T","xs":["a","b"]}')"
assert_eq 'an object section pushes it' 'Oslo' "$(render '{{#addr}}{{city}}{{/addr}}' '{"addr":{"city":"Oslo"}}')"
assert_eq 'a comment renders nothing'  'ab'      "$(render 'a{{! a note }}b' '{}')"

section 'Standalone tags take their line (a blank line would end a Markdown table)'
TABLE_TPL='| Name | Mail |
|---|---|
{{#rows}}
| {{name}} | {{mail}} |
{{/rows}}
{{! end of table }}
After'
assert_eq 'a table from a list' '| Name | Mail |
|---|---|
| Ann | a@x |
| Bo | b@x |
After' "$(render "$TABLE_TPL" '{"rows":[{"name":"Ann","mail":"a@x"},{"name":"Bo","mail":"b@x"}]}' none)"
assert_eq 'indented standalone tags too' 'x
y
END' "$(render 'x
  {{#v}}
y
  {{/v}}
END' '{"v":1}')"
assert_eq 'not standalone beside text' 'a b c' "$(render 'a {{#v}}b{{/v}} c' '{"v":1}')"

section 'Escaping: a value is plain text'
assert_eq 'Markdown syntax' '\*bold\* \_it\_ \`code\` \~s\~ \[x\](y)' \
    "$(render '{{v}}' '{"v":"*bold* _it_ `code` ~s~ [x](y)"}')"
assert_eq 'a pipe cannot add a table cell'  'A \| B'  "$(render '{{v}}' '{"v":"A | B"}')"
assert_eq 'a backslash is kept'             'a\\b'    "$(render '{{v}}' '{"v":"a\\b"}')"
assert_eq 'HTML is shown, not parsed' '&lt;script&gt;alert(1)&lt;/script&gt;' \
    "$(render '{{v}}' '{"v":"<script>alert(1)</script>"}')"
assert_eq 'an entity in the data stays text' '&amp;&#35;64;' "$(render '{{v}}' '{"v":"&#64;"}')"
assert_eq 'a heading at the start'    '&#35; Title'  "$(render '{{v}}' '{"v":"# Title"}')"
assert_eq 'a bullet at the start'     '\- item'      "$(render '{{v}}' '{"v":"- item"}')"
assert_eq 'a numbered item'           '1\. item'     "$(render '{{v}}' '{"v":"1. item"}')"
assert_eq 'a blockquote'              '&gt; q'       "$(render '{{v}}' '{"v":"> q"}')"
assert_eq 'newlines become <br>, one inline run' 'a<br>- b<br>c' \
    "$(render '{{v}}' '{"v":"a\n- b\r\nc"}')"
assert_eq 'surrounding whitespace is trimmed' 'x' "$(render '{{v}}' '{"v":"    x  "}')"

section 'Escaping: the wiki tokens the data must not reach'
# The brace tags are expanded from the Markdown source before `marked` runs
# (modules/page_view/index.js: processIncludes, processDiagramTags, processListTags,
# processUserCommentTags, processTocTag), so no `{` may reach the source at all. The entity
# holds there; `marked` decodes it later, which is why {filename} is not pinned here.
OUT=$(render '{{v}}' '{"v":"{include:12} {diagram:3} {list:4} {user_comment:1:x:2} {toc}"}')
assert_not_contains 'no brace tag reaches the pre-Markdown passes' '{' "$OUT"
OUT=$(render '{{v}}' '{"v":"see [[Secret page]] and ![[Other]]"}')
assert_not_contains 'no [[ for the wikilink pass' '[[' "$OUT"

# The mention scanner's own pattern (mentions.php) — `[@#]name` with no left boundary.
for raw in 0 1; do
    if [ $raw = 1 ]; then T='{{{v}}}'; L='raw'; else T='{{v}}'; L='escaped'; fi
    OUT=$(render "$T" '{"v":"ping @alice and #Bot, \\@carol"}')
    HITS=$(OUT="$OUT" php -r 'require getenv("APP") . "/mentions.php";
        $n = 0; foreach (["alice","Bot","carol"] as $u)
            $n += preg_match("/[@#]" . preg_quote($u, "/") . WIKI_MENTION_END . "/i", getenv("OUT"));
        echo $n;' 2>/dev/null)
    assert_eq "$L: the mention scanner finds no one" '0' "$HITS"
done
# Positive control: the same check does find a mention written in the template itself.
HITS=$(OUT="$(render '@alice {{v}}' '{"v":"x"}')" php -r 'require getenv("APP") . "/mentions.php";
    echo preg_match("/[@#]alice" . WIKI_MENTION_END . "/i", getenv("OUT"));' 2>/dev/null)
assert_eq 'control: a mention in the template is found' '1' "$HITS"

section 'Raw {{{v}}}: Markdown yes, HTML and mentions no'
assert_eq 'formatting passes'  '**bold** [link](x)'   "$(render '{{{v}}}' '{"v":"**bold** [link](x)"}')"
assert_eq '{{& v}} is the same' '**b**'               "$(render '{{& v}}' '{"v":"**b**"}')"
assert_eq 'lines and a heading pass' '## H
- a' "$(render '{{{v}}}' '{"v":"## H\n- a"}')"
assert_eq 'HTML does not' '&lt;img src=x onerror=alert(1)>' \
    "$(render '{{{v}}}' '{"v":"<img src=x onerror=alert(1)>"}')"

section 'Filename mode: verbatim'
assert_eq 'no escaping at all' 'Q3 & Q4 *draft* #1' "$(render '{{v}}' '{"v":"Q3 & Q4 *draft* #1"}' none)"

section 'Refusals, with the line they are on'
assert_eq 'unclosed tag'      'ERR:unclosed_tag:2'       "$(render $'a\n{{name' '{}')"
assert_eq 'unclosed section'  'ERR:unclosed_section:1'   "$(render '{{#a}}x' '{}')"
assert_eq 'unexpected close'  'ERR:unexpected_close:1'   "$(render 'x{{/a}}' '{}')"
assert_eq 'mismatched close'  'ERR:mismatched_close:3'   "$(render $'{{#a}}\n{{#b}}\n{{/a}}\n{{/b}}' '{}')"
assert_eq 'partials'          'ERR:partials_unsupported:1'   "$(render '{{> other}}' '{}')"
assert_eq 'delimiters'        'ERR:delimiters_unsupported:1' "$(render '{{=<% %>=}}' '{}')"
assert_eq 'a bad name'        'ERR:bad_name:1'           "$(render '{{a b}}' '{}')"
assert_eq 'an empty tag'      'ERR:empty_tag:1'          "$(render '{{}}' '{}')"
DEEP=$(printf '{{#a}}%.0s' $(seq 21))$(printf '{{/a}}%.0s' $(seq 21))
assert_eq 'too deep'          'ERR:too_deep:1'           "$(render "$DEEP" '{}')"
OK20=$(printf '{{#a}}%.0s' $(seq 20))x$(printf '{{/a}}%.0s' $(seq 20))
assert_eq 'control: 20 levels are fine' 'x' "$(render "$OK20" '{"a":true}')"

section 'Helpers'
assert_eq 'check: fine'       'null' "$(php_lib 'echo json_encode(wiki_tpl_check("{{a}}"));')"
assert_eq 'check: an error'   '{"error":"unclosed_section","line":1,"detail":"a"}' \
    "$(php_lib 'echo json_encode(wiki_tpl_check("{{#a}}"));')"
assert_eq 'top-level names (a section is one), not those inside' '["name","addr","rows"]' \
    "$(php_lib 'echo json_encode(wiki_tpl_names(wiki_tpl_parse("{{name}} {{addr.city}} {{#rows}}{{inner}}{{/rows}} {{.}}")));')"
assert_eq 'key: spaces'        'account_manager' "$(php_lib 'echo wiki_tpl_key("Account manager");')"
assert_eq 'key: punctuation'   'kunde_nr'        "$(php_lib 'echo wiki_tpl_key(" Kunde nr. ");')"
assert_eq 'key: unicode'       'område'          "$(php_lib 'echo wiki_tpl_key("Område");')"

# ── HTML templates ────────────────────────────────────────────────────────────
# html <template> <context-json>  — rendered in HTML mode, or ERR:<code>:<line>:<detail>.
# Warnings (a blocked link) are appended as " WARN:<code>".
html() {
    TPL="$1" CTX="$2" php -r '
        require getenv("APP") . "/mustache.php";
        try {
            $tree = wiki_tpl_parse(getenv("TPL"), ["html" => true]);
            echo wiki_tpl_render($tree, json_decode(getenv("CTX"), true) ?? [], ["escape" => "html"]);
            foreach (wiki_tpl_take_warnings() as $w) echo " WARN:", $w["warning"];
        } catch (WikiTemplateError $e) { echo "ERR:", $e->error, ":", $e->templateLine, ":", $e->detail; }'
}

section 'HTML: text between tags'
assert_eq 'markup, quotes and & are escaped' '<p>&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot; &#39;s</p>' \
    "$(html '<p>{{v}}</p>' '{"v":"<b>x</b> & \"q\" '"'"'s"}')"
assert_eq 'mentions and wiki tags are entities' '<p>&#64;alice &#35;Bot &#123;include:1&#125; &#91;&#91;Page&#93;&#93;</p>' \
    "$(html '<p>{{v}}</p>' '{"v":"@alice #Bot {include:1} [[Page]]"}')"
assert_eq 'a line break is <br>'            '<p>a<br>b</p>' "$(html '<p>{{v}}</p>' '{"v":"a\nb"}')"
assert_eq 'in a textarea, still just text'  '<textarea>&lt;/textarea&gt;</textarea>' "$(html '<textarea>{{v}}</textarea>' '{"v":"</textarea>"}')"

section 'HTML: attribute values'
assert_eq 'a quote cannot end the attribute' '<a title="x&quot; onmouseover=&quot;alert(1)">' \
    "$(html '<a title="{{v}}">' '{"v":"x\" onmouseover=\"alert(1)"}')"
assert_eq '  nor a single quote'             "<a title='x&#39; y'>" "$(html "<a title='{{v}}'>" '{"v":"x'"'"' y"}')"
assert_eq 'a line break stays inside'        '<a title="a&#10;b">' "$(html '<a title="{{v}}">' '{"v":"a\nb"}')"

section 'HTML: links'
assert_eq 'https passes, escaped'            '<a href="https://ex.com/?a=1&amp;b=2">' "$(html '<a href="{{v}}">' '{"v":"https://ex.com/?a=1&b=2"}')"
assert_eq 'a relative link passes'           '<a href="index.php?pageid=3">' "$(html '<a href="{{v}}">' '{"v":"index.php?pageid=3"}')"
assert_eq 'mailto passes'                    '<a href="mailto:a&#64;b.c">' "$(html '<a href="{{v}}">' '{"v":"mailto:a@b.c"}')"
for bad in 'javascript:alert(1)' 'JaVaScRiPt:alert(1)' '  javascript:alert(1)' 'java\tscript:alert(1)' 'data:text/html,<b>x</b>' 'vbscript:x'; do
    assert_eq "blocked: $bad" '<a href="#blocked"> WARN:url_blocked' "$(html '<a href="{{v}}">' "{\"v\":\"$bad\"}")"
done
assert_eq 'img src too'                      '<img src="#blocked"> WARN:url_blocked' "$(html '<img src="{{v}}">' '{"v":"javascript:x"}')"
assert_eq 'an entity in the data is not decoded into a scheme' '<a href="javascript&amp;&#35;58;x">' \
    "$(html '<a href="{{v}}">' '{"v":"javascript&#58;x"}')"
assert_eq "the template's own prefix fixes the scheme" '<a href="mailto:javascript:x">' \
    "$(html '<a href="mailto:{{v}}">' '{"v":"javascript:x"}')"
assert_eq '  so does a relative one'         '<a href="?id=javascript:x">' "$(html '<a href="?id={{v}}">' '{"v":"javascript:x"}')"
assert_eq 'a prefix that fixes nothing is checked with the value' '<a href="java#blocked"> WARN:url_blocked' \
    "$(html '<a href="java{{v}}">' '{"v":"script:x"}')"

section 'HTML: refused, with the line'
assert_eq 'inside <script>'                  'ERR:html_context:2:script'  "$(html $'<p>x</p>\n<script>var a="{{v}}"</script>' '{}')"
assert_eq 'inside <style>'                   'ERR:html_context:1:style'   "$(html '<style>.a{color:{{v}}}</style>' '{}')"
assert_eq 'an on… attribute'                 'ERR:html_context:1:event handler' "$(html '<p onclick="{{v}}">' '{}')"
assert_eq 'a style attribute'                'ERR:html_context:1:style'   "$(html '<p style="color:{{v}}">' '{}')"
assert_eq 'srcdoc'                           'ERR:html_context:1:srcdoc'  "$(html '<iframe srcdoc="{{v}}"></iframe>' '{}')"
assert_eq 'an unquoted attribute'            'ERR:html_context:1:unquoted attribute' "$(html '<p title={{v}}>' '{}')"
assert_eq 'a tag name'                       'ERR:html_context:1:tag'     "$(html '<{{v}}>' '{}')"
assert_eq 'an attribute name'                'ERR:html_context:1:tag'     "$(html '<p {{v}}>' '{}')"
assert_eq 'a comment'                        'ERR:html_context:1:comment' "$(html '<!-- {{v}} -->' '{}')"
assert_eq '{{{x}}}'                          'ERR:html_raw:1:v'           "$(html '<p>{{{v}}}</p>' '{}')"
assert_eq '{{& x}}'                          'ERR:html_raw:1:v'           "$(html '<p>{{& v}}</p>' '{}')"
assert_eq 'a section that moves into an attribute' 'ERR:html_section_context:1:a' "$(html '{{#a}}<p class="{{/a}}x">' '{}')"

section 'HTML: what keeps working'
assert_eq 'a loop of whole elements'         '<ul><li>a</li><li>&lt;b&gt;</li></ul>' "$(html '<ul>{{#xs}}<li>{{.}}</li>{{/xs}}</ul>' '{"xs":["a","<b>"]}')"
assert_eq 'a section inside an attribute value' '<p class="a b">' "$(html '<p class="a {{#x}}b{{/x}}">' '{"x":true}')"
assert_eq 'text again after </script>'       '<script>1</script><p>&lt;i&gt;</p>' "$(html '<script>1</script><p>{{v}}</p>' '{"v":"<i>"}')"
assert_eq 'a loop of cards with links'       '<a href="https://a.b">A</a><a href="#blocked">&lt;B&gt;</a> WARN:url_blocked' \
    "$(html '{{#xs}}<a href="{{u}}">{{n}}</a>{{/xs}}' '{"xs":[{"u":"https://a.b","n":"A"},{"u":"javascript:x","n":"<B>"}]}')"
assert_eq 'Markdown mode is unchanged'       '\*a\*' "$(render '{{v}}' '{"v":"*a*"}')"

exit $(( ASSERT_FAIL > 0 ))
