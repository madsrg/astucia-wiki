// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Fence-aware text mapping for the pre-`marked.parse` transforms.
 *
 * The wiki rewrites Markdown source before rendering it — `{include:ID}`, `{toc}`,
 * `[[wikilinks]]` — and those passes historically ran over the whole string, so a tag written
 * inside a fenced code block as an *example* was substituted rather than shown. That was
 * survivable while every tag looked like `{tag:…}`, but wikilinks use `[[…]]`, which appears in
 * ordinary prose and code far more often, and a page documenting the syntax would rewrite
 * itself.
 *
 * `mapOutsideCode` applies a function to everything except fenced blocks and inline code spans,
 * so those are passed through untouched.
 */

// A code span: a run of N backticks closed by the next run of exactly N. Anything before the
// opener, and after the closer, is ordinary text and gets mapped.
const mapLine = (line, fn) => {
    if (!line.includes('`')) return fn(line);
    let out = '', i = 0;
    while (i < line.length) {
        const tick = line.indexOf('`', i);
        if (tick === -1) { out += fn(line.slice(i)); break; }
        out += fn(line.slice(i, tick));

        let n = 0;
        while (line[tick + n] === '`') n++;
        const run = '`'.repeat(n);

        let j = tick + n, close = -1;
        while (j < line.length) {
            const next = line.indexOf(run, j);
            if (next === -1) break;
            let m = 0;
            while (line[next + m] === '`') m++;
            if (m === n) { close = next; break; }   // a longer run is not a match
            j = next + m;
        }
        if (close === -1) {
            // Unterminated opener: not a code span, so the rest of the line is ordinary text.
            out += fn(line.slice(tick));
            break;
        }
        out += line.slice(tick, close + n);          // the span, verbatim
        i = close + n;
    }
    return out;
};

/**
 * @param {string} src   Markdown source.
 * @param {(text: string) => string} fn  applied to each run of non-code text.
 * @returns {string}
 *
 * Limitation: a code span is matched within one line. Multi-line spans are legal Markdown but
 * vanishingly rare, and treating them per-line fails safe — the backticks are still emitted,
 * only a tag between them on a later line would be substituted.
 */
export const mapOutsideCode = (src, fn) => {
    if (typeof src !== 'string' || src === '') return src;
    if (!src.includes('`') && !src.includes('~~~')) return fn(src);

    const lines = src.split('\n');
    let fence = null;                                // { char, len } while inside a fence
    for (let i = 0; i < lines.length; i++) {
        const m = /^[ \t]{0,3}(`{3,}|~{3,})/.exec(lines[i]);
        if (fence) {
            // A fence closes on a run of the same character at least as long as the opener.
            if (m && m[1][0] === fence.char && m[1].length >= fence.len) fence = null;
            continue;
        }
        if (m) { fence = { char: m[1][0], len: m[1].length }; continue; }
        lines[i] = mapLine(lines[i], fn);
    }
    return lines.join('\n');
};
