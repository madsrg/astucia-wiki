<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// Build system_prompts.json for the gallery from a directory of Markdown pages.
//
//   php tools/build_system_prompts.php <dir> [> system_prompts.json]
//   php tools/build_system_prompts.php "/path/to/Pages/Astucia"
//
// The gallery is authored as ordinary wiki pages so it can be written and reviewed like
// anything else; this turns them into the one file the wiki fetches. Regenerate and upload
// after editing a page — the wiki never reads the pages directly, only the published JSON.
//
// A page qualifies when it contains exactly one fenced block: that block is the prompt.
// Everything before the fence is prose *about* the prompt — the first paragraph becomes the
// description shown in the picker. A page with no fence, or more than one, is skipped and
// reported on stderr rather than guessed at, because a gallery entry that silently contains
// the wrong text is worse than one that is missing.
// =================================================================

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tools/build_system_prompts.php <dir-of-markdown-pages>\n");
    exit(1);
}

/** "Product Owner.md" → "product-owner" */
function slugify(string $s): string {
    $s = strtolower(preg_replace('/\.md$/i', '', $s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

$entries = [];
$skipped = [];

foreach (glob(rtrim($dir, '/') . '/*.md') as $file) {
    $name = basename($file);
    $raw  = (string)file_get_contents($file);

    // The prompt is the single fenced block. preg_match_all rather than a lazy first match,
    // so a page with two blocks is reported instead of silently taking the first.
    if (!preg_match_all('/^```[a-z]*\R(.*?)^```/ms', $raw, $m) || count($m[1]) !== 1) {
        $skipped[$name] = count($m[1] ?? []) . ' fenced blocks (need exactly 1)';
        continue;
    }
    $prompt = trim($m[1][0]);
    if ($prompt === '') { $skipped[$name] = 'empty fenced block'; continue; }

    // Title: the first heading, with a leading "System Prompt –" stripped — that prefix is
    // useful on the page and noise in a picker where everything is a system prompt.
    $title = preg_replace('/\.md$/i', '', $name);
    if (preg_match('/^#\s+(.+)$/m', $raw, $h)) {
        $t = trim($h[1]);
        // /u is load-bearing: without it a character class holding an en or em dash is
        // matched byte by byte, so stripping the prefix lops one byte off a three-byte
        // sequence and the title becomes invalid UTF-8 — which json_encode refuses,
        // taking the whole document with it and emitting nothing.
        $t = preg_replace('/^system\s*prompt\s*[\x{2010}-\x{2015}:\-]\s*/iu', '', $t);
        $t = preg_replace('/\s*system\s*prompt$/iu', '', $t);
        if ($t !== '') $title = $t;
    }

    // Description: the first non-empty paragraph after the heading and before the fence,
    // flattened to one line and stripped of Markdown emphasis.
    $before = substr($raw, 0, strpos($raw, '```'));
    $before = preg_replace('/^#.*$/m', '', $before);
    $desc = '';
    foreach (preg_split('/\R{2,}/', $before) as $para) {
        $para = trim(preg_replace('/\s+/', ' ', $para));
        $para = str_replace(['**', '*', '`'], '', $para);
        // preg, not trim(): trim()'s charlist is a set of *bytes*, so trimming an en dash
        // lops one byte off a three-byte sequence and leaves invalid UTF-8 behind — which
        // json_encode then refuses, taking the whole document with it.
        $para = preg_replace('/^[\s\x{2010}-\x{2015}\-]+|[\s\x{2010}-\x{2015}\-]+$/u', '', $para);
        if ($para !== '' && $para !== 'System Prompt' && $para !== 'System Prompt:') { $desc = $para; break; }
    }

    $entries[] = [
        'id'          => slugify($name),
        'title'       => $title,
        'description' => $desc,
        'prompt'      => $prompt,
    ];
}

usort($entries, fn($a, $b) => strcmp($a['title'], $b['title']));

$doc = [
    // Schema version, so a future change can be made without breaking older wikis: a client
    // that does not recognise the number can say so rather than mis-render the gallery.
    'schema'  => 1,
    'updated' => date('Y-m-d'),
    'prompts' => $entries,
];

foreach ($skipped as $f => $why) fwrite(STDERR, "skipped: $f — $why\n");
fwrite(STDERR, sprintf("%d prompt(s), %d skipped\n", count($entries), count($skipped)));

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    // Silent failure here writes an empty file over a good one. Say what went wrong.
    fwrite(STDERR, "ERROR: could not encode the gallery: " . json_last_error_msg() . "\n");
    exit(1);
}
echo $json, "\n";
