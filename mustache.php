<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// PAGE TEMPLATES — a small Mustache, made to write Markdown
//
// The template language of page generators (page_generators.php): a template page is
// rendered once per row of a .list. A subset of Mustache, written here rather than pulled in
// through Composer, which is otherwise needed only for OIDC.
//
//   {{field}}            the value, escaped so it reads as plain text in Markdown
//   {{{field}}} {{&f}}   the value as Markdown — formatting allowed, HTML and mentions not
//   {{a.b}}  {{.}}       a dotted name; the current item. A value with `_text` (a list's
//                        group) prints that text; a list of them, joined with "; "
//   {{#f}}…{{/f}}        once per item of a list, once for any other value that is not empty
//   {{^f}}…{{/f}}        only when the value is empty
//   {{! comment }}       nothing
//
// Not supported, and refused by name rather than silently ignored: partials ({{> x}}),
// changing delimiters ({{=<% %>=}}) and lambdas.
//
// **HTML templates** (a page with `format: html`): wiki_tpl_parse($tpl, ['html' => true]) works
// out *where* in the HTML each {{…}} lands, and rendering escapes the value for that place —
// see "HTML templates" below. {{{x}}} is refused there: inserting HTML from the data is
// exactly what the data must never do.
//
// Double braces on purpose: the wiki's own placeholders are single ({filename},
// {include:ID}), and workflows already use {{page}}.
//
// What escaping has to stop is wider than Markdown syntax, because the page is processed
// by more than `marked` (see wiki_tpl_md_escape()):
//  - `marked` is not sanitised, so a `<` in a value would be live HTML;
//  - the brace tags read from the Markdown source — `{include:ID}`, `{diagram:…}`,
//    `{list:…}`, `{user_comment:…}`, `{toc}` — would act on a value, so `{` becomes an
//    entity, which those passes do not decode. (`marked` does decode it, so `{filename}` and
//    `{lastUpdated}`, replaced in the rendered HTML, still apply to a value: it shows the
//    page's own name or date. Cosmetic, and only ever about the page itself — accepted.);
//  - the mention scanner (mentions.php) matches `@name` / `#name` with no left boundary, so
//    `\@name` is still a mention — `@` and `#` become entities. Data cannot mention anyone,
//    in either form (agreed for v1);
//  - `[[wikilinks]]` are rewritten before Markdown; `\[` breaks the pair.
// =================================================================

const WIKI_TPL_MAX_BYTES = 262144;   // a template, not a document
const WIKI_TPL_MAX_DEPTH = 20;       // nested sections

/**
 * Markup the *wiki* puts into a context — never data from a list. The one value the escaping
 * lets through: a page generator's `{{_edit}}` button. It is written as it is where a value
 * would be text (in HTML mode, the text between tags; in Markdown, anywhere), and as nothing in
 * an attribute, a link or a page name — no place where markup would mean something else.
 */
final class WikiTplMarkup {
    public function __construct(public readonly string $html) {}
}

class WikiTemplateError extends RuntimeException {
    /** A stable code the UI translates: unclosed_tag, unclosed_section, unexpected_close,
     *  mismatched_close, partials_unsupported, delimiters_unsupported, bad_name, too_deep,
     *  too_large, empty_tag. */
    public string $error;
    public int $templateLine;
    public string $detail;

    public function __construct(string $error, int $line, string $detail = '') {
        $this->error = $error;
        $this->templateLine = $line;
        $this->detail = $detail;
        parent::__construct("$error at line $line" . ($detail !== '' ? ": $detail" : ''));
    }
}

/**
 * A column name as a template key: lower case, letters and digits of any script, every other
 * run of characters one underscore. "Account manager" → account_manager, "Kunde nr." → kunde_nr.
 * Two columns that collide are the generator's problem, not this function's.
 */
function wiki_tpl_key(string $name): string {
    $k = mb_strtolower(trim($name), 'UTF-8');
    $k = preg_replace('/[^\p{L}\p{N}]+/u', '_', $k) ?? '';
    return trim($k, '_');
}

// -----------------------------------------------------------------
// Parsing
// -----------------------------------------------------------------

/**
 * Parse a template into a tree. Throws WikiTemplateError with the line it happened on.
 *
 * Nodes: ['t' => 'text', 'v' => string] | ['t' => 'var', 'n' => name, 'raw' => bool]
 *      | ['t' => 'sec' | 'inv', 'n' => name, 'c' => [nodes]]
 *
 * A section, inverted, closing or comment tag alone on its line takes the line with it
 * (Mustache's "standalone" rule). Without that every {{#rows}} would leave a blank line in
 * the output, and a blank line ends a Markdown table.
 */
function wiki_tpl_parse(string $tpl, array $opts = []): array {
    if (strlen($tpl) > WIKI_TPL_MAX_BYTES) throw new WikiTemplateError('too_large', 1);
    $tpl = str_replace(["\r\n", "\r"], "\n", $tpl);

    // Frames of open sections; frame 0 is the root. A section's node is appended to its
    // parent only when it closes.
    $frames = [['node' => null, 'line' => 0, 'c' => []]];
    $len   = strlen($tpl);
    $pos   = 0;
    $text  = '';                       // pending text run

    $line = fn(int $at) => substr_count($tpl, "\n", 0, $at) + 1;
    $flush = function () use (&$text, &$frames) {
        if ($text !== '') $frames[count($frames) - 1]['c'][] = ['t' => 'text', 'v' => $text];
        $text = '';
    };

    while ($pos < $len) {
        $open = strpos($tpl, '{{', $pos);
        if ($open === false) { $text .= substr($tpl, $pos); break; }
        $text .= substr($tpl, $pos, $open - $pos);

        $triple = substr($tpl, $open, 3) === '{{{';
        $close  = strpos($tpl, $triple ? '}}}' : '}}', $open + ($triple ? 3 : 2));
        if ($close === false) throw new WikiTemplateError('unclosed_tag', $line($open));
        $inner = substr($tpl, $open + ($triple ? 3 : 2), $close - $open - ($triple ? 3 : 2));
        $end   = $close + ($triple ? 3 : 2);
        $at    = $line($open);

        $sigil = $triple ? '{' : ($inner !== '' ? $inner[0] : '');
        if (!$triple && in_array($sigil, ['#', '^', '/', '!', '&', '>', '='], true)) {
            $name = trim(substr($inner, 1));
        } else {
            $sigil = $triple ? '{' : '';
            $name  = trim($inner);
        }

        if ($sigil === '>') throw new WikiTemplateError('partials_unsupported', $at, '{{' . $inner . '}}');
        if ($sigil === '=') throw new WikiTemplateError('delimiters_unsupported', $at, '{{' . $inner . '}}');

        // Standalone: only whitespace from the start of the line to the tag, and from the tag
        // to the end of the line. Read from the template itself, so another tag earlier on
        // the line disqualifies it.
        if (in_array($sigil, ['#', '^', '/', '!'], true)) {
            $ls = strrpos(substr($tpl, 0, $open), "\n");
            $ls = $ls === false ? 0 : $ls + 1;
            $nl = strpos($tpl, "\n", $end);
            $le = $nl === false ? $len : $nl;
            $before = substr($tpl, $ls, $open - $ls);
            $after  = substr($tpl, $end, $le - $end);
            if (trim($before, " \t") === '' && trim($after, " \t") === ''
                && strlen($text) >= strlen($before)) {
                $text = substr($text, 0, strlen($text) - strlen($before));
                $end  = $nl === false ? $len : $nl + 1;
            }
        }

        if ($sigil === '!') { $pos = $end; continue; }

        if ($name === '') throw new WikiTemplateError('empty_tag', $at);
        if ($name !== '.' && !preg_match('/^[\p{L}\p{N}_]+(\.[\p{L}\p{N}_]+)*$/u', $name)) {
            throw new WikiTemplateError('bad_name', $at, $name);
        }

        $flush();
        if ($sigil === '#' || $sigil === '^') {
            if (count($frames) > WIKI_TPL_MAX_DEPTH) throw new WikiTemplateError('too_deep', $at);
            $frames[] = ['node' => ['t' => $sigil === '#' ? 'sec' : 'inv', 'n' => $name, 'line' => $at], 'line' => $at, 'c' => []];
        } elseif ($sigil === '/') {
            if (count($frames) === 1) throw new WikiTemplateError('unexpected_close', $at, $name);
            $top = array_pop($frames);
            if ($top['node']['n'] !== $name) {
                throw new WikiTemplateError('mismatched_close', $at, $top['node']['n'] . ' / ' . $name);
            }
            $frames[count($frames) - 1]['c'][] = $top['node'] + ['c' => $top['c']];
        } else {
            $frames[count($frames) - 1]['c'][] = ['t' => 'var', 'n' => $name, 'raw' => $sigil === '{' || $sigil === '&', 'line' => $at];
        }
        $pos = $end;
    }
    $flush();
    if (count($frames) > 1) {
        $top = end($frames);
        throw new WikiTemplateError('unclosed_section', $top['line'], $top['node']['n']);
    }
    $tree = $frames[0]['c'];
    if (!empty($opts['html'])) {
        $st = _wiki_tpl_html_start();
        _wiki_tpl_html_annotate($tree, $st);
    }
    return $tree;
}

/**
 * Check a template without rendering it: null when it parses, otherwise
 * ['error' => code, 'line' => n, 'detail' => string] for the UI to translate.
 */
function wiki_tpl_check(string $tpl, array $opts = []): ?array {
    try {
        wiki_tpl_parse($tpl, $opts);
        return null;
    } catch (WikiTemplateError $e) {
        return ['error' => $e->error, 'line' => $e->templateLine, 'detail' => $e->detail];
    }
}

/**
 * The names a template uses at its top level — outside every section — by their first
 * segment. What a generator compares with the list's columns to warn about {{typo}}.
 * Names inside a section may belong to the section's items, so they are not judged.
 */
function wiki_tpl_names(array $tree): array {
    $names = [];
    foreach ($tree as $n) {
        if ($n['t'] === 'text' || $n['n'] === '.') continue;
        $names[explode('.', $n['n'])[0]] = true;
    }
    return array_keys($names);
}

// -----------------------------------------------------------------
// HTML templates — where in the HTML each value lands
// -----------------------------------------------------------------
//
// The template's own HTML is trusted as any page an editor writes is; the *data* is not — it
// can come from a list in another Space, edited by people who cannot edit the page. So each
// {{…}} is placed by a small scanner that walks the template text the way a browser would,
// and the value is escaped for that place:
//
//   between tags                text — HTML-escaped, @ # { } [ ] as entities (the mention
//                               scanner and the wiki's tags read the raw file), newline → <br>
//   a quoted attribute value    the same, quotes included
//   a link attribute            the same, and the link must be http(s), mailto or relative —
//   (href, src, action, …)      anything else (javascript:, data:, …) becomes #blocked
//
// Everywhere else no escaping makes a value safe, so the template is refused with its line:
// inside <script> or <style>, a comment, an on… or style or srcdoc attribute, an unquoted
// attribute value, a tag or attribute name. A section must leave the scanner where it found
// it — otherwise the place a value lands would depend on how often the section repeats (the
// rule Go's html/template has for the same reason).

const WIKI_TPL_URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster', 'cite', 'srcset',
                            'background', 'data', 'xlink:href', 'longdesc', 'manifest', 'icon'];

function _wiki_tpl_html_start(): array {
    return ['s' => 'text', 'tag' => '', 'end' => false, 'attr' => '', 'val' => '', 'raw' => ''];
}

/** Walk $text from state $st; returns the state after it. */
function _wiki_tpl_html_step(array $st, string $text): array {
    $len = strlen($text);
    $isName = fn($c) => $c !== '' && !ctype_space($c) && !in_array($c, ['>', '/', '=', '"', "'", '<'], true);
    $close = function (array $st): array {
        $tag = strtolower($st['tag']);
        $st['s'] = (!$st['end'] && in_array($tag, ['script', 'style'], true)) ? 'raw' : 'text';
        $st['raw'] = $st['s'] === 'raw' ? $tag : '';
        // Back in text, which tag it was no longer matters — keeping it would make a section
        // holding a whole <li>…</li> look as if it had moved the scanner.
        $st['tag'] = ''; $st['end'] = false; $st['attr'] = ''; $st['val'] = '';
        return $st;
    };
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        switch ($st['s']) {
            case 'text':
                if ($c !== '<') break;
                if (substr($text, $i, 4) === '<!--') { $st['s'] = 'comment'; $i += 3; break; }
                $n = $text[$i + 1] ?? '';
                if ($n === '!' || $n === '?') { $st['s'] = 'decl'; $i++; break; }
                if ($n === '/') { $st = ['s' => 'tagname', 'tag' => '', 'end' => true] + $st; $i++; break; }
                if ($n === '' || ctype_alpha($n)) { $st = ['s' => 'tagname', 'tag' => '', 'end' => false] + $st; }
                break;
            case 'tagname':
                if ($c === '>') { $st = $close($st); break; }
                if (ctype_space($c) || $c === '/') { $st['s'] = 'intag'; break; }
                $st['tag'] .= $c;
                break;
            case 'intag':
                if ($c === '>') { $st = $close($st); break; }
                if (ctype_space($c) || $c === '/') break;
                $st['s'] = 'attrname'; $st['attr'] = $c;
                break;
            case 'attrname':
                if ($c === '>') { $st = $close($st); break; }
                if ($c === '=') { $st['s'] = 'beforeval'; break; }
                if (ctype_space($c)) { $st['s'] = 'afterattr'; break; }
                if ($c === '/') { $st['s'] = 'intag'; break; }
                $st['attr'] .= $c;
                break;
            case 'afterattr':
                if ($c === '>') { $st = $close($st); break; }
                if ($c === '=') { $st['s'] = 'beforeval'; break; }
                if (ctype_space($c) || $c === '/') break;
                $st['s'] = 'attrname'; $st['attr'] = $c;
                break;
            case 'beforeval':
                if (ctype_space($c)) break;
                if ($c === '>') { $st = $close($st); break; }
                if ($c === '"') { $st['s'] = 'valdq'; $st['val'] = ''; break; }
                if ($c === "'") { $st['s'] = 'valsq'; $st['val'] = ''; break; }
                $st['s'] = 'valunq'; $st['val'] = $c;
                break;
            case 'valdq':
            case 'valsq':
                if ($c === ($st['s'] === 'valdq' ? '"' : "'")) { $st['s'] = 'intag'; $st['val'] = ''; break; }
                $st['val'] .= $c;
                break;
            case 'valunq':
                if (ctype_space($c)) { $st['s'] = 'intag'; $st['val'] = ''; break; }
                if ($c === '>') { $st = $close($st); break; }
                $st['val'] .= $c;
                break;
            case 'comment':
                if (substr($text, $i, 3) === '-->') { $st['s'] = 'text'; $i += 2; }
                break;
            case 'decl':
                if ($c === '>') $st['s'] = 'text';
                break;
            case 'raw':
                // Only the matching end tag ends a <script> or <style>.
                if ($c === '<' && strtolower(substr($text, $i, strlen($st['raw']) + 2)) === '</' . $st['raw']) {
                    $st = ['s' => 'tagname', 'tag' => '', 'end' => true, 'raw' => ''] + $st;
                    $i++;
                }
                break;
        }
    }
    return $st;
}

/** The parts of the state a section must leave as it found them. */
function _wiki_tpl_html_key(array $st): string {
    return $st['s'] . '|' . strtolower($st['tag']) . '|' . strtolower($st['attr']) . '|' . $st['raw'];
}

/** Label each value with the place it lands, or refuse the template. */
function _wiki_tpl_html_annotate(array &$nodes, array &$st): void {
    foreach ($nodes as &$n) {
        if ($n['t'] === 'text') { $st = _wiki_tpl_html_step($st, $n['v']); continue; }
        $line = (int)($n['line'] ?? 1);
        if ($n['t'] === 'var') {
            if ($n['raw']) throw new WikiTemplateError('html_raw', $line, $n['n']);
            [$n['ctx'], $n['prefix']] = _wiki_tpl_html_context($st, $line);
            continue;
        }
        // A section, repeated or not, must leave the scanner where it found it.
        $inner = $st;
        _wiki_tpl_html_annotate($n['c'], $inner);
        if (_wiki_tpl_html_key($inner) !== _wiki_tpl_html_key($st)) {
            throw new WikiTemplateError('html_section_context', $line, $n['n']);
        }
    }
    unset($n);
}

/** [context, url prefix] for a value at state $st; throws where nothing would be safe. */
function _wiki_tpl_html_context(array $st, int $line): array {
    switch ($st['s']) {
        case 'text':
            return ['text', ''];
        case 'valdq':
        case 'valsq':
            $attr = strtolower($st['attr']);
            if (str_starts_with($attr, 'on')) throw new WikiTemplateError('html_context', $line, 'event handler');
            if ($attr === 'style')  throw new WikiTemplateError('html_context', $line, 'style');
            if ($attr === 'srcdoc') throw new WikiTemplateError('html_context', $line, 'srcdoc');
            if (in_array($attr, WIKI_TPL_URL_ATTRS, true)) return ['url', $st['val']];
            return ['attr', ''];
        case 'beforeval':
        case 'valunq':
            throw new WikiTemplateError('html_context', $line, 'unquoted attribute');
        case 'raw':
            throw new WikiTemplateError('html_context', $line, $st['raw']);
        case 'comment':
        case 'decl':
            throw new WikiTemplateError('html_context', $line, 'comment');
        default:
            throw new WikiTemplateError('html_context', $line, 'tag');
    }
}

/** HTML-escape a value for text or a quoted attribute, neutralising what the wiki reads. */
function wiki_tpl_html_escape(string $v, bool $text): string {
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    static $map = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;',
                   '@' => '&#64;', '#' => '&#35;', '{' => '&#123;', '}' => '&#125;', '[' => '&#91;', ']' => '&#93;'];
    $v = strtr($v, $map);
    return $text ? str_replace("\n", '<br>', $v) : str_replace("\n", '&#10;', $v);
}

/**
 * Is a link safe to write into a link attribute? $prefix is the template's own text before
 * the value in that attribute: if it already fixes the scheme ("mailto:", "https://…",
 * "/page", "?id=") the value cannot change it. Otherwise the whole link must be http(s),
 * mailto or relative. Browsers ignore tabs and line breaks inside a scheme, and leading
 * spaces and control characters, so those are taken out before looking.
 */
function wiki_tpl_url_allowed(string $prefix, string $value): bool {
    $p = ltrim($prefix, "\x00..\x20");
    if ($p !== '' && (strpbrk($p[0], '/?#.') !== false || preg_match('/^[^\/?#]*:/', $p))) return true;
    $url = preg_replace('/[\t\n\r]/', '', ltrim($prefix . $value, "\x00..\x20")) ?? '';
    if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $m)) return true;   // relative
    return in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true);
}

/** Warnings from the last render (a blocked link), taken once. */
function wiki_tpl_take_warnings(): array {
    $w = $GLOBALS['_wiki_tpl_warnings'] ?? [];
    $GLOBALS['_wiki_tpl_warnings'] = [];
    return $w;
}

// -----------------------------------------------------------------
// Escaping
// -----------------------------------------------------------------

/**
 * A value as plain text in Markdown — whatever it holds, it reads as the characters it is.
 *
 * Newlines become <br>, so a value is always a single inline run: it cannot start a list,
 * a heading, a code block or a new table row, wherever the template put it. Leading and
 * trailing whitespace is trimmed (in list data it is accidental, and four leading spaces
 * would be code).
 */
function wiki_tpl_md_escape(string $v): string {
    $v = trim(str_replace(["\r\n", "\r"], "\n", $v));
    if ($v === '') return '';

    static $map = [
        '\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '[' => '\\[', ']' => '\\]',
        '|'  => '\\|',  '~' => '\\~',
        '&'  => '&amp;', '<' => '&lt;', '>' => '&gt;',
        '{'  => '&#123;', '}' => '&#125;', '@' => '&#64;', '#' => '&#35;',
    ];
    $v = strtr($v, $map);

    // What only means something at the start of a line: a bullet, a setext underline,
    // an ordered-list number. `#`, `>` and `*` are already entities or escaped.
    $v = preg_replace('/^([-+=])/', '\\\\$1', $v);
    $v = preg_replace('/^(\d+)([.)])/', '$1\\\\$2', $v);

    return preg_replace('/[ \t]*\n[ \t]*/', '<br>', $v);
}

/**
 * A value meant as Markdown ({{{x}}}): formatting, links, lists and tables pass through, as
 * the author of the data wrote them. Two things do not —
 *  - HTML: a `<` is shown, never parsed. The data may live in a Space whose editors cannot
 *    edit the page it lands in, so it must not be able to put script on that page;
 *  - mentions: an `@` or `#` directly followed by a name is an entity, so the mention
 *    scanner does not see it. A heading's `# ` (sigil, then space) is untouched.
 */
function wiki_tpl_md_raw(string $v): string {
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = str_replace('<', '&lt;', $v);
    return preg_replace_callback('/[@#](?=[^\s#])/u',
        fn($m) => $m[0] === '@' ? '&#64;' : '&#35;', $v);
}

// -----------------------------------------------------------------
// Rendering
// -----------------------------------------------------------------

/**
 * Render a template (string, or a tree from wiki_tpl_parse — parse once when rendering
 * many rows) against a context.
 *
 * $opts['escape']: 'markdown' (default) — as above; 'html' — by the place each value lands
 * (the tree must come from wiki_tpl_parse(…, ['html' => true])); 'none' — every value
 * verbatim, for a page name, which is cleaned as a filename afterwards and never rendered.
 *
 * A missing name renders as nothing, as in Mustache.
 */
function wiki_tpl_render($tpl, array $ctx, array $opts = []): string {
    $tree = is_array($tpl) ? $tpl : wiki_tpl_parse($tpl, ['html' => ($opts['escape'] ?? '') === 'html']);
    $mode = in_array($opts['escape'] ?? 'markdown', ['none', 'html'], true) ? $opts['escape'] : 'markdown';
    return _wiki_tpl_nodes($tree, [$ctx], $mode);
}

function _wiki_tpl_nodes(array $nodes, array $stack, string $mode): string {
    $out = '';
    foreach ($nodes as $n) {
        switch ($n['t']) {
            case 'text':
                $out .= $n['v'];
                break;
            case 'var':
                $raw_v = _wiki_tpl_lookup($n['n'], $stack);
                if ($raw_v instanceof WikiTplMarkup) {
                    if ($mode === 'markdown' || ($mode === 'html' && ($n['ctx'] ?? 'text') === 'text')) $out .= $raw_v->html;
                    break;
                }
                $s = _wiki_tpl_str($raw_v);
                if ($mode === 'html') {
                    $ctx = $n['ctx'] ?? 'text';
                    if ($ctx === 'url' && !wiki_tpl_url_allowed((string)($n['prefix'] ?? ''), $s)) {
                        $GLOBALS['_wiki_tpl_warnings'][] = ['warning' => 'url_blocked', 'detail' => $n['n']];
                        $out .= '#blocked';
                    } else {
                        $out .= wiki_tpl_html_escape($s, $ctx === 'text');
                    }
                    break;
                }
                if ($mode === 'none')   $out .= $s;
                elseif ($n['raw'])      $out .= wiki_tpl_md_raw($s);
                else                    $out .= wiki_tpl_md_escape($s);
                break;
            case 'sec':
                $v = _wiki_tpl_lookup($n['n'], $stack);
                if (!_wiki_tpl_truthy($v)) break;
                if (is_array($v) && array_is_list($v)) {
                    foreach ($v as $item) $out .= _wiki_tpl_nodes($n['c'], [...$stack, $item], $mode);
                } else {
                    $out .= _wiki_tpl_nodes($n['c'], [...$stack, $v], $mode);
                }
                break;
            case 'inv':
                if (!_wiki_tpl_truthy(_wiki_tpl_lookup($n['n'], $stack))) {
                    $out .= _wiki_tpl_nodes($n['c'], $stack, $mode);
                }
                break;
        }
    }
    return $out;
}

/** Mustache name resolution: the first segment from the innermost context that has it,
 *  the rest from there down. `.` is the current item. */
function _wiki_tpl_lookup(string $name, array $stack) {
    if ($name === '.') return end($stack);
    $parts = explode('.', $name);
    $first = array_shift($parts);
    $val = null;
    for ($i = count($stack) - 1; $i >= 0; $i--) {
        if (is_array($stack[$i]) && array_key_exists($first, $stack[$i])) { $val = $stack[$i][$first]; break; }
    }
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) return null;
        $val = $val[$p];
    }
    return $val;
}

function _wiki_tpl_truthy($v): bool {
    return !($v === null || $v === false || $v === '' || $v === []);
}

/**
 * A value as text. A structure prints nothing — except one that says how it reads: a value
 * carrying `_text` (a list's Group of fields, see wiki_gen_rows()) prints that, and a list of
 * such values prints them joined with "; ", as the list's table does. So {{address}} is the
 * summary while {{address.city}} and {{#address}}…{{/address}} reach inside it.
 */
function _wiki_tpl_str($v): string {
    // A list's Yes/No field is a boolean, so {{#paid}}…{{/paid}} and {{^paid}} work, and
    // {{paid}} prints Yes or No. (Mustache would print false as nothing; no boolean reached a
    // template before Yes/No fields, so nothing depended on that.)
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_object($v)) return '';
    if (is_array($v)) {
        if (isset($v['_text']) && is_scalar($v['_text'])) return (string)$v['_text'];
        if (array_is_list($v)) {
            $parts = array_filter(array_map(fn($e) => is_array($e) && isset($e['_text']) && is_scalar($e['_text'])
                ? (string)$e['_text'] : '', $v), fn($s) => $s !== '');
            return implode('; ', $parts);
        }
        return '';
    }
    return (string)$v;
}
