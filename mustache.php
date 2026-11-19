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
function wiki_tpl_parse(string $tpl): array {
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
            $frames[] = ['node' => ['t' => $sigil === '#' ? 'sec' : 'inv', 'n' => $name], 'line' => $at, 'c' => []];
        } elseif ($sigil === '/') {
            if (count($frames) === 1) throw new WikiTemplateError('unexpected_close', $at, $name);
            $top = array_pop($frames);
            if ($top['node']['n'] !== $name) {
                throw new WikiTemplateError('mismatched_close', $at, $top['node']['n'] . ' / ' . $name);
            }
            $frames[count($frames) - 1]['c'][] = $top['node'] + ['c' => $top['c']];
        } else {
            $frames[count($frames) - 1]['c'][] = ['t' => 'var', 'n' => $name, 'raw' => $sigil === '{' || $sigil === '&'];
        }
        $pos = $end;
    }
    $flush();
    if (count($frames) > 1) {
        $top = end($frames);
        throw new WikiTemplateError('unclosed_section', $top['line'], $top['node']['n']);
    }
    return $frames[0]['c'];
}

/**
 * Check a template without rendering it: null when it parses, otherwise
 * ['error' => code, 'line' => n, 'detail' => string] for the UI to translate.
 */
function wiki_tpl_check(string $tpl): ?array {
    try {
        wiki_tpl_parse($tpl);
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
 * $opts['escape']: 'markdown' (default) — as above; 'none' — every value verbatim, for a
 * page name, which is cleaned as a filename afterwards and never rendered as Markdown.
 *
 * A missing name renders as nothing, as in Mustache.
 */
function wiki_tpl_render($tpl, array $ctx, array $opts = []): string {
    $tree = is_array($tpl) ? $tpl : wiki_tpl_parse($tpl);
    $mode = ($opts['escape'] ?? 'markdown') === 'none' ? 'none' : 'markdown';
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
                $s = _wiki_tpl_str(_wiki_tpl_lookup($n['n'], $stack));
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
    if ($v === null || $v === false) return '';
    if ($v === true) return 'true';
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
