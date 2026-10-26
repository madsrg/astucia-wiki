<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// AI SKILLS — procedures an administrator writes once, which an AI pulls in when needed
//
// The format is Anthropic's open Agent Skills standard rather than a wiki dialect: a
// folder per skill holding a SKILL.md whose YAML front matter carries `name` and
// `description`, plus any reference files it wants. A skill written here runs in Claude
// Code unchanged, and a skill from a public repository drops in unchanged — a second
// syntax would have bought nothing and cost both.
//
// Loaded in stages, the way AI memory is:
//
//   1. **The listing is in the prompt** — one line per skill, name and description. The
//      description is the trigger: it is the only thing the model sees when deciding,
//      which is why the template teaches "what it does, and when to use it".
//   2. **The body is fetched by a tool** (`wiki_read_skill`), and so are the reference
//      files the body points at. Nothing is paid for a skill that does not apply.
//
// Two stores, one format:
//
//   - **Per Space**, in `skills/<name>/SKILL.md` inside the space — ordinary pages, in
//     the tree and in git, which is the same reason memories are pages.
//   - **Wiki-wide**, in `WIKI_SYSTEM_DATA/skills/<name>/SKILL.md`, edited only in Admin →
//     Skills. Not a channel between Spaces the way a shared memory store would be: only
//     administrators write either store, so nothing one Space writes reaches another.
//   A Space skill shadows a wiki-wide one of the same name — the more specific wins, and
//   the admin list says when that is happening.
//
// **Only administrators author skills, and no AI ever does.** A skill is an instruction
// injected into every prompt in the Space, read by AIs that hold write tools — whoever
// edits one steers all of them. The guard is on the *path* (`wiki_skill_guarded_abs()`),
// applied in `sanitize_path()` for every api.php write and in `execute_ai_tool()` for
// every AI write, so a new action cannot forget it.
//
// **No code runs.** Skills elsewhere may bundle scripts for a sandbox; this wiki has none
// and is not getting one through this door, so imports keep text and images only and
// say what they dropped.
// =================================================================

require_once __DIR__ . '/frontmatter.php';
require_once __DIR__ . '/space_settings.php';
// Required outright — the wiki-wide default has readers in mcp.php and the cron runner,
// and a function_exists() guard is how frontmatter_expose_ai once read "off" there.
require_once __DIR__ . '/settings.php';

/** Fixed, not configurable: the path guard and every filter would have to know the setting. */
const WIKI_SKILLS_DIR = 'skills';
const WIKI_SKILL_FILE = 'SKILL.md';

/** How many skills the injected listing names before it stops and says so. */
const WIKI_SKILLS_INDEX_CAP = 100;

/** The spec's limits. */
const WIKI_SKILL_NAME_MAX = 64;
const WIKI_SKILL_DESC_MAX = 1024;

/**
 * A SKILL.md larger than this is refused. Generous on purpose: the spec sets no size,
 * and published skills run past 100 KB (anthropics/skills' claude-api is 105 KB). It is
 * a ceiling on what one tool call can hand a model, not a style rule.
 */
const WIKI_SKILL_MAX_BYTES = 262144;
/** What one `wiki_read_skill` call returns of a reference file, at most. */
const WIKI_SKILL_READ_MAX_BYTES = 262144;

/** Kept on import, readable by the tool. */
const WIKI_SKILL_TEXT_EXTS  = ['md', 'txt', 'json', 'csv', 'yaml', 'yml'];
/** Kept on import so a skill's own pages render; never handed to a model. No SVG: it is script. */
const WIKI_SKILL_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

/** Import ceilings — a zip bomb or a monorepo is refused rather than unpacked. */
const WIKI_SKILL_IMPORT_MAX_BYTES     = 20971520;   // 20 MB unpacked, all files
const WIKI_SKILL_IMPORT_MAX_FILES     = 2000;
const WIKI_SKILL_IMPORT_MAX_FILE_SIZE = 2097152;    // 2 MB per file
/** Seconds a staged import waits for the admin to choose before it is swept. */
const WIKI_SKILL_IMPORT_TTL = 3600;
const WIKI_SKILL_GIT_TIMEOUT = 90;

// ── Switches ──────────────────────────────────────────────────────────────────

/**
 * Does this AI User use skills?
 *
 * The memory tri-state: '' follows the wiki-wide `ai_skills_default`, an explicit 'off'
 * wins over it. The default is **on**, unlike memory's, because the decision that
 * matters has already been made twice by an administrator — writing the skill, and
 * switching skills on for the Space — whereas a memory is written by the AI itself.
 */
function wiki_ai_skills_enabled(array $ai_config): bool {
    $own = strtolower(trim((string)($ai_config['skills'] ?? '')));
    if ($own === 'on')  return true;
    if ($own === 'off') return false;
    return (bool)wiki_setting('ai_skills_default', true);
}

/** Both switches: this space offers skills, and this AI uses them. */
function wiki_skills_active(string $space_dir, array $ai_config): bool {
    return $space_dir !== '' && wiki_space_dir_skills($space_dir) && wiki_ai_skills_enabled($ai_config);
}

// ── Paths ─────────────────────────────────────────────────────────────────────

function wiki_skills_global_dir(): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/' . WIKI_SKILLS_DIR;
}

function wiki_skills_space_dir(string $space_dir): string {
    return rtrim($space_dir, '/') . '/' . WIKI_SKILLS_DIR;
}

/**
 * Is this space-relative path inside the space's skills folder (or the folder itself)?
 * For the filters — search, graph, mentions — that keep skills out of everyone's way.
 */
function wiki_is_skill_path(?string $rel_path): bool {
    if ($rel_path === null) return false;
    $rel = strtolower(ltrim(str_replace('\\', '/', $rel_path), '/'));
    return $rel === WIKI_SKILLS_DIR || str_starts_with($rel, WIKI_SKILLS_DIR . '/');
}

/**
 * Does this absolute path land in some Space's `skills/` folder?
 *
 * The authoring guard. Asked of the *resolved* path rather than of a parameter for the
 * reason Space isolation is (see wiki_path_space()): with no `?space=` the base is
 * PAGES_DIR, so "Alpha/skills/x/SKILL.md" reaches Alpha's skills without any space
 * parameter set. So: second segment below PAGES_DIR, whatever the first one is.
 *
 * Case-insensitive on purpose — on a case-insensitive volume `Skills/` *is* `skills/`,
 * and a guard that a capital letter walks past is not one. It covers the folder itself,
 * so creating, deleting or renaming *to* `skills` is caught too.
 */
function wiki_skill_guarded_abs(string $abs): bool {
    $norm = preg_replace('#/+#', '/', str_replace('\\', '/', $abs));
    $norm = str_replace('/./', '/', $norm);
    foreach (array_unique(array_filter([rtrim(PAGES_DIR, '/'), rtrim((string)realpath(PAGES_DIR), '/')])) as $root) {
        $root = preg_replace('#/+#', '/', $root);
        if (!str_starts_with($norm, $root . '/')) continue;
        $segs = array_values(array_filter(explode('/', substr($norm, strlen($root) + 1)), fn($s) => $s !== '' && $s !== '.'));
        if (count($segs) >= 2 && strtolower($segs[1]) === WIKI_SKILLS_DIR) return true;
    }
    return false;
}

// ── Parsing and validation ───────────────────────────────────────────────────

/**
 * A YAML block scalar (`description: >` / `|`), which the front-matter reader keeps as
 * the indicator alone. Skills in the wild do write descriptions that way, and reading
 * ">" as the description would mark them all invalid.
 */
function _wiki_skill_block_scalar(string $block, string $key): ?string {
    $lines = preg_split('/\r?\n/', $block);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^' . preg_quote($key, '/') . '[ \t]*:[ \t]*([>|])[+-]?[ \t]*$/', $lines[$i], $m)) continue;
        $out = [];
        for ($j = $i + 1; $j < $n; $j++) {
            if (trim($lines[$j]) !== '' && !preg_match('/^\s+\S/', $lines[$j])) break;
            $out[] = trim($lines[$j]);
        }
        while ($out && end($out) === '') array_pop($out);
        if ($m[1] === '|') return implode("\n", $out);
        // Folded: lines join with a space, a blank line is a paragraph break.
        return trim(preg_replace('/ ?\n ?/', "\n", preg_replace('/(?<!\n)\n(?!\n)/', ' ', implode("\n", $out))));
    }
    return null;
}

/**
 * Parse a SKILL.md.
 * @return array{name:string,description:string,meta:array,body:string,has_block:bool}
 */
function wiki_skill_parse(string $raw): array {
    $sp   = wiki_fm_split($raw);
    $meta = $sp['meta'];
    $get  = function (string $k) use ($meta, $sp): string {
        $v = $meta[$k] ?? '';
        if (is_array($v)) $v = implode(', ', $v);
        $v = (string)$v;
        if (preg_match('/^[>|][+-]?$/', trim($v))) $v = (string)_wiki_skill_block_scalar($sp['block'], $k);
        return trim($v);
    };
    return [
        'name'        => $get('name'),
        'description' => $get('description'),
        'meta'        => $meta,
        'body'        => $sp['body'],
        'has_block'   => $sp['block'] !== '',
    ];
}

/** The spec's name rule: lowercase letters, digits and single hyphens, not at either end. */
function wiki_skill_name_valid(string $name): bool {
    return strlen($name) >= 1 && strlen($name) <= WIKI_SKILL_NAME_MAX
        && (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name);
}

/**
 * Every problem with a SKILL.md, collected rather than stopping at the first, so an
 * administrator fixes it once. Codes double as i18n keys (`skills.err.<code>`); the
 * English text is for the model, the log and API callers.
 *
 * @param string|null $folder the folder it lives in; the spec requires the two to agree,
 *                            and it is what makes a skill addressable by name at all.
 * @return array<int,array{code:string,message:string}>
 */
function wiki_skill_validate(string $raw, ?string $folder = null): array {
    $errs = [];
    $add  = function (string $code, string $msg) use (&$errs) { $errs[] = ['code' => $code, 'message' => $msg]; };
    if (strlen($raw) > WIKI_SKILL_MAX_BYTES) {
        $add('too-large', 'SKILL.md is larger than ' . (WIKI_SKILL_MAX_BYTES / 1024) . ' KB.');
    }
    $p = wiki_skill_parse($raw);
    if (!$p['has_block']) {
        $add('no-frontmatter', 'SKILL.md must start with a --- front matter block holding name and description.');
        return $errs;
    }
    if ($p['name'] === '') {
        $add('name-missing', 'The front matter has no "name".');
    } elseif (!wiki_skill_name_valid($p['name'])) {
        $add('name-invalid', 'The name "' . $p['name'] . '" must be lowercase letters, digits and hyphens, at most ' . WIKI_SKILL_NAME_MAX . ' characters.');
    } elseif ($folder !== null && $folder !== $p['name']) {
        $add('name-folder', 'The name "' . $p['name'] . '" does not match its folder "' . $folder . '".');
    }
    if ($p['description'] === '') {
        $add('description-missing', 'The front matter has no "description".');
    } elseif (mb_strlen($p['description']) > WIKI_SKILL_DESC_MAX) {
        $add('description-long', 'The description is longer than ' . WIKI_SKILL_DESC_MAX . ' characters.');
    }
    return $errs;
}

/** The starting point for a new skill, in both editors. Page content, so not translated. */
function wiki_skill_template(string $name): string {
    return "---\n"
        . "name: {$name}\n"
        . "description: What this skill does, and when to use it. Say both — the description is the only part an AI sees when it decides whether this skill applies. For example: Writes the weekly status report from pages changed in the last 7 days. Use when asked for a status update, a weekly summary or a progress report.\n"
        . "---\n\n"
        . "# " . ucfirst(str_replace('-', ' ', $name)) . "\n\n"
        . "## Steps\n\n"
        . "1. First, …\n"
        . "2. Then, …\n\n"
        . "## Rules\n\n"
        . "- …\n\n"
        . "## Reference files\n\n"
        . "Put longer material in files beside this one (for example `reference.md`) and name them here.\n"
        . "The AI reads them with wiki_read_skill only when it needs them.\n";
}

// ── Listing ───────────────────────────────────────────────────────────────────

/**
 * Every skill folder under one store, valid or not.
 * @return array<int,array{name:string,folder:string,description:string,scope:string,dir:string,valid:bool,errors:array,updated:int}>
 */
function wiki_skills_scan(?string $base, string $scope): array {
    if ($base === null || !is_dir($base)) return [];
    $out = [];
    foreach (scandir($base) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $dir = $base . '/' . $f;
        if (!is_dir($dir) || is_link($dir)) continue;
        $file = $dir . '/' . WIKI_SKILL_FILE;
        if (!is_file($file)) {
            $out[] = ['name' => $f, 'folder' => $f, 'description' => '', 'scope' => $scope, 'dir' => $dir,
                      'valid' => false, 'errors' => [['code' => 'no-skill-file', 'message' => 'The folder has no SKILL.md.']],
                      'updated' => (int)@filemtime($dir)];
            continue;
        }
        $raw  = (string)@file_get_contents($file, false, null, 0, WIKI_SKILL_MAX_BYTES + 1);
        $p    = wiki_skill_parse($raw);
        $errs = wiki_skill_validate($raw, $f);
        $out[] = [
            'name'        => $errs ? $f : $p['name'],
            'folder'      => $f,
            'description' => $p['description'],
            'scope'       => $scope,
            'dir'         => $dir,
            'valid'       => !$errs,
            'errors'      => $errs,
            'updated'     => (int)@filemtime($file),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/**
 * The skills that apply in a space: its own, then the wiki-wide ones it does not shadow.
 *
 * @param bool $all include invalid and shadowed entries (marked), for the admin views;
 *                  the prompt and the tool see only what is usable.
 */
function wiki_skills_list(string $space_dir, bool $all = false): array {
    $own    = $space_dir !== '' && rtrim($space_dir, '/') !== rtrim(PAGES_DIR, '/')
            ? wiki_skills_scan(wiki_skills_space_dir($space_dir), 'space') : [];
    $global = wiki_skills_scan(wiki_skills_global_dir(), 'wiki');
    $taken  = [];
    foreach ($own as $s) if ($s['valid']) $taken[$s['name']] = true;
    $out = [];
    foreach ($own as $s) {
        if ($s['valid'] || $all) $out[] = $s + ['shadowed' => false];
    }
    foreach ($global as $s) {
        $shadowed = $s['valid'] && isset($taken[$s['name']]);
        if (($s['valid'] && !$shadowed) || $all) $out[] = $s + ['shadowed' => $shadowed];
    }
    return $out;
}

/** One usable skill by name, or null — the Space's first, as the listing orders them. */
function wiki_skill_find(string $space_dir, string $name): ?array {
    foreach (wiki_skills_list($space_dir) as $s) {
        if ($s['name'] === $name) return $s;
    }
    return null;
}

/**
 * The block injected into the system prompt, or '' when skills are off here.
 *
 * Says nothing at all when there is nothing to list: an empty "SKILLS:" header in every
 * prompt is exactly the boilerplate this kind of block must never become.
 */
function wiki_skills_prompt(string $space_dir, array $ai_config): string {
    if (!wiki_skills_active($space_dir, $ai_config)) return '';
    $items = wiki_skills_list($space_dir);
    if (!$items) return '';
    $shown = array_slice($items, 0, WIKI_SKILLS_INDEX_CAP);
    $lines = '';
    foreach ($shown as $s) {
        $lines .= '- ' . $s['name'] . ': ' . preg_replace('/\s+/', ' ', $s['description']) . "\n";
    }
    $more = count($items) - count($shown);
    return "SKILLS — procedures an administrator has written for you. Each line is a skill's name and when to use it.\n"
        . "When a request matches one, call wiki_read_skill with its name BEFORE you start, and follow what it says; "
        . "it may point to reference files, which you read with the same tool. Do not mention skills that do not apply.\n"
        . $lines
        . ($more > 0 ? "…and {$more} more not listed.\n" : '')
        . "\n";
}

/**
 * The files of a skill other than SKILL.md, relative to its folder: what a model may ask
 * for next. Text only — an image is no use to a tool that returns text.
 */
function wiki_skill_files(string $dir): array {
    $out = [];
    $base = rtrim($dir, '/');
    $walk = function (string $d, string $prefix) use (&$walk, &$out) {
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $p = $d . '/' . $f;
            if (is_link($p)) continue;
            if (is_dir($p)) { if (count($out) < 500) $walk($p, $prefix . $f . '/'); continue; }
            $rel = $prefix . $f;
            if ($rel === WIKI_SKILL_FILE) continue;
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), WIKI_SKILL_TEXT_EXTS, true)) $out[] = $rel;
        }
    };
    $walk($base, '');
    sort($out);
    return $out;
}

/**
 * Read a skill, or one of its files, for the tool.
 * @return array{ok:bool,text:string,error:string}
 */
function wiki_skill_read(string $space_dir, string $name, string $file = ''): array {
    $fail = fn(string $m) => ['ok' => false, 'text' => '', 'error' => $m];
    $s = wiki_skill_find($space_dir, trim($name));
    if (!$s) return $fail('there is no skill called "' . $name . '" here. The skills you can use are listed in your instructions');

    $file = trim(str_replace('\\', '/', $file), '/');
    if ($file === '' || $file === WIKI_SKILL_FILE) {
        $raw   = (string)@file_get_contents($s['dir'] . '/' . WIKI_SKILL_FILE);
        $files = wiki_skill_files($s['dir']);
        $text  = "Skill: {$s['name']}\n\n" . trim(wiki_fm_body($raw)) . "\n";
        if ($files) {
            $text .= "\nReference files in this skill (read one with wiki_read_skill, name=\"{$s['name']}\", file=\"…\"):\n- "
                   . implode("\n- ", $files) . "\n";
        }
        return ['ok' => true, 'text' => $text, 'error' => ''];
    }
    // Inside the skill's own folder, and nowhere else: no '..', no symlink out.
    if (str_contains($file, '..') || str_contains($file, "\0")) return $fail('that file name is not allowed');
    $abs  = $s['dir'] . '/' . $file;
    $real = realpath($abs);
    $root = realpath($s['dir']);
    if ($real === false || $root === false || !str_starts_with($real, $root . '/') || !is_file($real)) {
        return $fail('the skill "' . $s['name'] . '" has no file "' . $file . '"');
    }
    if (!in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), WIKI_SKILL_TEXT_EXTS, true)) {
        return $fail('only text files of a skill can be read');
    }
    $text = (string)@file_get_contents($real, false, null, 0, WIKI_SKILL_READ_MAX_BYTES + 1);
    $cut  = strlen($text) > WIKI_SKILL_READ_MAX_BYTES;
    if ($cut) $text = substr($text, 0, WIKI_SKILL_READ_MAX_BYTES);
    return ['ok' => true, 'text' => $text . ($cut ? "\n\n[…truncated]" : ''), 'error' => ''];
}

// ── Writing (administrators only — the callers check) ────────────────────────

/**
 * Where a skill of this scope lives. $scope is '' for wiki-wide, or a Space name.
 * @return array{0:?string,1:string} [base dir or null, error]
 */
function wiki_skill_scope_base(string $scope): array {
    if ($scope === '') {
        $g = wiki_skills_global_dir();
        return $g === null ? [null, 'WIKI_SYSTEM_DATA is not configured'] : [$g, ''];
    }
    $safe = basename($scope);
    if ($safe === '' || $safe[0] === '.' || !is_dir(rtrim(PAGES_DIR, '/') . '/' . $safe)) return [null, 'no such space'];
    return [rtrim(PAGES_DIR, '/') . '/' . $safe . '/' . WIKI_SKILLS_DIR, ''];
}

/** Remove a directory tree. Only ever handed paths this module built. */
function _wiki_skill_rmtree(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) { if (is_link($dir) || is_file($dir)) @unlink($dir); return; }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        (is_dir($p) && !is_link($p)) ? _wiki_skill_rmtree($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ── Import ────────────────────────────────────────────────────────────────────
//
// One pipeline for both sources: fetch or upload → unpack into a staging folder through
// one filter → find every SKILL.md → validate each → the admin ticks which to keep →
// copy them into the chosen store. The filter is where the hardening lives, so both
// sources get exactly the same rules.

function wiki_skill_import_root(): ?string {
    if (!defined('WIKI_SYSTEM_DATA') || !WIKI_SYSTEM_DATA) return null;
    return rtrim(WIKI_SYSTEM_DATA, '/') . '/skill_imports';
}

/** Drop staged imports nobody finished. Called whenever a new one starts. */
function wiki_skill_import_sweep(): void {
    $root = wiki_skill_import_root();
    if ($root === null || !is_dir($root)) return;
    foreach (scandir($root) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (time() - (int)@filemtime($root . '/' . $f) > WIKI_SKILL_IMPORT_TTL) _wiki_skill_rmtree($root . '/' . $f);
    }
}

/** A fresh staging folder: [id, abs dir]. */
function wiki_skill_import_new(): array {
    $root = wiki_skill_import_root();
    if ($root === null) throw new Exception('WIKI_SYSTEM_DATA is not configured.');
    if (!is_dir($root) && !@mkdir($root, 0700, true)) throw new Exception('Could not create the import folder.');
    wiki_skill_import_sweep();
    $id  = bin2hex(random_bytes(12));
    $dir = $root . '/' . $id;
    if (!@mkdir($dir . '/files', 0700, true)) throw new Exception('Could not create the import folder.');
    return [$id, $dir];
}

function wiki_skill_import_dir(string $id): ?string {
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) return null;
    $root = wiki_skill_import_root();
    $dir  = $root === null ? null : $root . '/' . $id;
    return ($dir !== null && is_dir($dir . '/files')) ? $dir : null;
}

/**
 * Is this archive member name safe to place, and should it be kept?
 * @return array{0:string,1:string} [clean relative path or '', reason it was dropped or '']
 */
function wiki_skill_import_member(string $name): array {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || str_contains($name, "\0")) return ['', 'unsafe'];
    // zip-slip: an absolute path, a drive letter, or any '..' segment is refused outright.
    if ($name[0] === '/' || preg_match('#^[A-Za-z]:#', $name)) return ['', 'unsafe'];
    $segs = array_values(array_filter(explode('/', $name), fn($s) => $s !== '' && $s !== '.'));
    foreach ($segs as $s) {
        if ($s === '..') return ['', 'unsafe'];
        // Hidden files and folders (.git, .github, __MACOSX's ._ files) are not skill content.
        if ($s[0] === '.' || $s === '__MACOSX') return ['', 'hidden'];
    }
    if (!$segs) return ['', 'unsafe'];
    $rel = implode('/', $segs);
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, array_merge(WIKI_SKILL_TEXT_EXTS, WIKI_SKILL_IMAGE_EXTS), true)) return ['', 'type'];
    return [$rel, ''];
}

/**
 * Unpack a zip into a staging folder through the filter.
 * @return array{dropped:array<int,array{path:string,reason:string}>}
 */
function wiki_skill_import_unzip(string $zip_path, string $stage): array {
    if (!class_exists('ZipArchive')) throw new Exception('This server cannot read zip files (the PHP zip extension is missing).');
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) throw new Exception('That is not a readable zip file.');
    $dropped = [];
    $total = 0; $kept = 0;
    try {
        if ($zip->numFiles > WIKI_SKILL_IMPORT_MAX_FILES * 4) throw new Exception('The archive holds too many files.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) continue;
            $name = (string)$st['name'];
            if (str_ends_with($name, '/')) continue;                       // a directory entry
            // A symlink is stored as a file whose mode says so. Never followed, never written.
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                $dropped[] = ['path' => $name, 'reason' => 'link'];
                continue;
            }
            [$rel, $why] = wiki_skill_import_member($name);
            if ($rel === '') { if ($why !== 'hidden') $dropped[] = ['path' => $name, 'reason' => $why]; continue; }
            if ((int)$st['size'] > WIKI_SKILL_IMPORT_MAX_FILE_SIZE) { $dropped[] = ['path' => $rel, 'reason' => 'size']; continue; }
            if (++$kept > WIKI_SKILL_IMPORT_MAX_FILES) throw new Exception('The archive holds too many files.');
            // The declared size is the archive's claim; count the bytes actually inflated.
            $in = $zip->getStream($name);
            if ($in === false) { $dropped[] = ['path' => $rel, 'reason' => 'unsafe']; continue; }
            $dest = $stage . '/files/' . $rel;
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0700, true);
            $out = @fopen($dest, 'wb');
            if ($out === false) { fclose($in); throw new Exception('Could not write the import.'); }
            $n = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) break;
                $n += strlen($chunk); $total += strlen($chunk);
                if ($n > WIKI_SKILL_IMPORT_MAX_FILE_SIZE || $total > WIKI_SKILL_IMPORT_MAX_BYTES) {
                    fclose($in); fclose($out); @unlink($dest);
                    if ($total > WIKI_SKILL_IMPORT_MAX_BYTES) throw new Exception('The archive unpacks to more than ' . (WIKI_SKILL_IMPORT_MAX_BYTES >> 20) . ' MB.');
                    $dropped[] = ['path' => $rel, 'reason' => 'size'];
                    continue 2;
                }
                fwrite($out, $chunk);
            }
            fclose($in); fclose($out);
        }
    } finally {
        $zip->close();
    }
    return ['dropped' => $dropped];
}

/**
 * Turn what an admin pasted into a clone URL, branch and sub-folder. A GitHub "tree"
 * link — what you get by browsing to a skill — is understood, since that is what people
 * will paste.
 * @return array{url:string,branch:string,path:string}
 */
function wiki_skill_parse_repo(string $input, string $branch = '', string $path = ''): array {
    $input = trim($input);
    if (!preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/%+-]+$#', $input)) {
        throw new Exception('Give an https:// repository address.');
    }
    if (preg_match('#^https://github\.com/([^/]+)/([^/]+)/(?:tree|blob)/([^/]+)(?:/(.*))?$#', $input, $m)) {
        $input  = 'https://github.com/' . $m[1] . '/' . $m[2];
        $branch = $branch !== '' ? $branch : $m[3];
        $path   = $path   !== '' ? $path   : preg_replace('#/?SKILL\.md$#', '', (string)($m[4] ?? ''));
    }
    $branch = trim($branch);
    if ($branch !== '' && !preg_match('#^[A-Za-z0-9._/-]+$#', $branch) || str_starts_with($branch, '-')) {
        throw new Exception('That branch name is not valid.');
    }
    $path = trim(str_replace('\\', '/', $path), '/');
    if (str_contains($path, '..')) throw new Exception('That folder is not valid.');
    return ['url' => $input, 'branch' => $branch, 'path' => $path];
}

/**
 * Shallow-clone a repository into a scratch folder, then copy it into the staging folder
 * through the same filter a zip goes through.
 *
 * `git clone` rather than a host's archive download, so any host works (GitHub, GitLab,
 * Gitea, a company server); git is already in the image for version history. A clone
 * runs none of the repository's hooks, and the options below close the rest: no
 * file:// or ssh transport (https only, checked above and enforced by
 * protocol.allow), no symlinks checked out, no submodules, no credential prompt.
 * Run without a shell, so nothing in the URL is ever interpreted.
 */
function wiki_skill_import_git(string $url, string $branch, string $sub, string $stage): array {
    $scratch = $stage . '/clone';
    $cmd = ['git', '-c', 'protocol.allow=never', '-c', 'protocol.https.allow=always',
            '-c', 'core.symlinks=false', '-c', 'core.hooksPath=/dev/null', '-c', 'credential.helper=',
            'clone', '--depth', '1', '--single-branch', '--no-tags', '--quiet'];
    if ($branch !== '') array_push($cmd, '--branch', $branch);
    array_push($cmd, '--', $url, $scratch);

    $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/bin/true', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $stage, 'GIT_CONFIG_NOSYSTEM' => '1'];
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) throw new Exception('Could not run git on this server.');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $err = '';
    $start = time();
    while (true) {
        $err .= (string)stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        $st = proc_get_status($proc);
        if (!$st['running']) { $code = $st['exitcode']; break; }
        if (time() - $start > WIKI_SKILL_GIT_TIMEOUT) {
            proc_terminate($proc, 9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
            _wiki_skill_rmtree($scratch);
            throw new Exception('The repository took too long to download.');
        }
        usleep(100000);
    }
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    if ($code !== 0) {
        _wiki_skill_rmtree($scratch);
        $line = trim(strtok(trim($err), "\n") ?: '');
        throw new Exception('git could not fetch that repository' . ($line !== '' ? ': ' . $line : '.'));
    }

    $from = $scratch . ($sub !== '' ? '/' . $sub : '');
    $real = realpath($from);
    if ($real === false || !is_dir($real) || !str_starts_with($real . '/', realpath($scratch) . '/')) {
        _wiki_skill_rmtree($scratch);
        throw new Exception('The repository has no folder "' . $sub . '".');
    }
    $dropped = [];
    $total = 0; $kept = 0;
    $walk = function (string $d, string $prefix) use (&$walk, &$dropped, &$total, &$kept, $stage) {
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d . '/' . $f;
            $rel = $prefix . $f;
            if (is_link($p)) { $dropped[] = ['path' => $rel, 'reason' => 'link']; continue; }
            if (is_dir($p)) {
                if ($f[0] === '.') continue;
                $walk($p, $rel . '/');
                continue;
            }
            [$clean, $why] = wiki_skill_import_member($rel);
            if ($clean === '') { if ($why !== 'hidden') $dropped[] = ['path' => $rel, 'reason' => $why]; continue; }
            $size = (int)@filesize($p);
            if ($size > WIKI_SKILL_IMPORT_MAX_FILE_SIZE) { $dropped[] = ['path' => $rel, 'reason' => 'size']; continue; }
            $total += $size;
            if (++$kept > WIKI_SKILL_IMPORT_MAX_FILES || $total > WIKI_SKILL_IMPORT_MAX_BYTES) {
                throw new Exception('The repository is too large to import from — point at the folder holding the skills.');
            }
            $dest = $stage . '/files/' . $clean;
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0700, true);
            if (!@copy($p, $dest)) throw new Exception('Could not write the import.');
        }
    };
    try { $walk($real, ''); }
    finally { _wiki_skill_rmtree($scratch); }
    return ['dropped' => $dropped];
}

/**
 * Every skill found in a staging folder: wherever a SKILL.md sits, its folder is a skill.
 * Records the dropped files beside each, so the dialog can say what will not come along.
 */
function wiki_skill_import_scan(string $stage, array $dropped = []): array {
    $files = $stage . '/files';
    $found = [];
    $walk = function (string $d, string $prefix) use (&$walk, &$found) {
        if (is_file($d . '/' . WIKI_SKILL_FILE)) {
            $found[] = rtrim($prefix, '/');
            return;   // a skill's own sub-folders are its resources, not more skills
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !is_dir($d . '/' . $f)) continue;
            if (count($found) < 1000) $walk($d . '/' . $f, $prefix . $f . '/');
        }
    };
    $walk($files, '');
    $out = [];
    foreach ($found as $key) {
        $dir  = $files . ($key !== '' ? '/' . $key : '');
        $raw  = (string)@file_get_contents($dir . '/' . WIKI_SKILL_FILE, false, null, 0, WIKI_SKILL_MAX_BYTES + 1);
        $p    = wiki_skill_parse($raw);
        // Validated against its *name*, not its folder: on import the folder is renamed to
        // the name, which is the only reading of "must match" that lets a zip whose root
        // is "my-skill-main/" import at all.
        $errs = wiki_skill_validate($raw, null);
        $drop = array_values(array_filter($dropped, fn($x) => $key === '' || str_starts_with($x['path'], $key . '/')));
        $out[] = [
            'key'         => $key,
            'name'        => $p['name'],
            'description' => $p['description'],
            'valid'       => !$errs,
            'errors'      => $errs,
            'files'       => count(wiki_skill_files($dir)),
            'dropped'     => array_map(fn($x) => ['path' => $x['path'], 'reason' => $x['reason']], array_slice($drop, 0, 50)),
            'dropped_count' => count($drop),
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['name'] ?: $a['key'], $b['name'] ?: $b['key']));
    return $out;
}

/** Copy a staged tree into place, every file through the filter again. */
function _wiki_skill_copy_tree(string $from, string $to, array &$written, string $prefix = ''): void {
    if (!is_dir($to)) @mkdir($to, 0755, true);
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $from . '/' . $f;
        if (is_link($p)) continue;
        if (is_dir($p)) { _wiki_skill_copy_tree($p, $to . '/' . $f, $written, $prefix . $f . '/'); continue; }
        [$clean] = wiki_skill_import_member($prefix . $f);
        if ($clean === '') continue;
        if (@copy($p, $to . '/' . $f)) $written[] = $prefix . $f;
    }
}

/**
 * Write the chosen skills into a store.
 *
 * @param array<string,string> $choices key → 'skip' | 'import' | 'overwrite'
 * @return array{imported:array,skipped:array,errors:array,written:array<string,array>}
 *         `written` maps each imported skill name to its files, so a Space caller can index them.
 */
function wiki_skill_import_commit(string $stage, array $choices, string $base): array {
    $found  = [];
    foreach (wiki_skill_import_scan($stage) as $c) $found[$c['key']] = $c;
    $res = ['imported' => [], 'skipped' => [], 'errors' => [], 'written' => []];
    if (!is_dir($base) && !@mkdir($base, 0755, true)) {
        $res['errors'][] = ['name' => '', 'message' => 'Could not create the skills folder.'];
        return $res;
    }
    foreach ($choices as $key => $how) {
        $key = (string)$key;
        if ($how === 'skip' || !isset($found[$key])) continue;
        $c = $found[$key];
        if (!$c['valid']) { $res['errors'][] = ['name' => $c['name'] ?: $key, 'message' => $c['errors'][0]['message'] ?? 'invalid']; continue; }
        $dest = $base . '/' . $c['name'];
        if (is_dir($dest) && $how !== 'overwrite') { $res['skipped'][] = $c['name']; continue; }
        if (is_dir($dest)) _wiki_skill_rmtree($dest);
        $written = [];
        _wiki_skill_copy_tree($stage . '/files' . ($key !== '' ? '/' . $key : ''), $dest, $written);
        $res['imported'][] = $c['name'];
        $res['written'][$c['name']] = $written;
    }
    return $res;
}

// ── Keeping a Space's index in step ──────────────────────────────────────────

/**
 * Is this absolute path a skill's SKILL.md inside a Space? Returns the folder name, or
 * null. What `save` and `set_frontmatter` ask before validating what they write.
 */
function wiki_skill_file_folder_abs(string $abs): ?string {
    if (!wiki_skill_guarded_abs($abs)) return null;
    $norm = preg_replace('#/+#', '/', str_replace('\\', '/', $abs));
    $parts = explode('/', $norm);
    $n = count($parts);
    if ($n < 3 || $parts[$n - 1] !== WIKI_SKILL_FILE || strtolower($parts[$n - 3]) !== WIKI_SKILLS_DIR) return null;
    return $parts[$n - 2];
}

/**
 * Reconcile the index entries under one space-relative folder with what is on disk:
 * forget what is gone, add what is new. Every admin write to a Space's skills ends here,
 * so the tree shows exactly what the store holds — a renamed or re-imported skill would
 * otherwise leave entries pointing at files that no longer exist.
 */
function wiki_skill_reindex($indexer, string $space_dir, string $rel_dir, array $actor = []): void {
    $rel_dir = trim($rel_dir, '/');
    $prefix  = $rel_dir . '/';
    foreach ($indexer->getAllPages() as $data) {
        $p = (string)($data['path'] ?? '');
        if (($p === $rel_dir || str_starts_with($p, $prefix)) && !is_file(rtrim($space_dir, '/') . '/' . $p)) {
            $indexer->removePage($p);
        }
    }
    $abs = rtrim($space_dir, '/') . '/' . $rel_dir;
    if (!is_dir($abs)) return;
    $walk = function (string $d, string $rel) use (&$walk, $indexer, $actor) {
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $p = $d . '/' . $f;
            if (is_link($p)) continue;
            if (is_dir($p)) { $walk($p, $rel . '/' . $f); continue; }
            if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), PageIndexer::CONTENT_EXTS, true)) continue;
            $r = $rel . '/' . $f;
            if ($indexer->getId($r) === null) $indexer->addPage($r, $actor['uid'] ?? null, $actor['name'] ?? null);
            else                              $indexer->updateModified($r, $actor['uid'] ?? null, $actor['name'] ?? null);
        }
    };
    $walk($abs, $rel_dir);
}

// ── /skill <name>: invoking one explicitly ───────────────────────────────────

/**
 * The skill a message asks for with `/skill <name>`, or null.
 *
 * At the start of the message, after any mentions — chat focus and an explicit `#Bot`
 * both put the AI's name in front, and `/aiJob #Bot /skill x …` arrives here with the
 * mention already stripped or not, depending on the route.
 */
function wiki_skill_invocation(string $text): ?string {
    if (!preg_match('/^\s*(?:[#@][\w.-]+\s+)*\/skill\s+([A-Za-z0-9][A-Za-z0-9-]*)/u', $text, $m)) return null;
    return strtolower($m[1]);
}

/**
 * The block appended to the system prompt when a message invokes a skill: the skill
 * itself, so the model does not have to decide to fetch what the person already chose.
 * When it cannot be had, the model is told so — otherwise it answers as though the
 * skill had been followed.
 */
function wiki_skill_invocation_prompt(string $space_dir, array $ai_config, string $text): string {
    $name = wiki_skill_invocation($text);
    if ($name === null) return '';
    if (!wiki_skills_active($space_dir, $ai_config)) {
        return "\n\nThe user invoked the skill \"{$name}\" with /skill, but skills are not available to you here. "
             . "Say so briefly, then help as best you can without it.";
    }
    $sk = wiki_skill_read($space_dir, $name);
    if (!$sk['ok']) {
        return "\n\nThe user invoked the skill \"{$name}\" with /skill, but there is no such skill here. "
             . "Say so, and name the skills that do exist if one looks like what they meant.";
    }
    return "\n\nThe user invoked this skill explicitly with /skill {$name}. Follow it for this request:\n\n" . $sk['text'];
}
