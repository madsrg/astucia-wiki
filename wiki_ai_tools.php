<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// WIKI AI TOOLS — shared by api.php (chat @mentions) and mcp.php (MCP tools/call)
// Defines the wiki_* tool set and executes it against a PageIndexer/space_dir.
// =================================================================

require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/llm_trace.php';
require_once __DIR__ . '/wikilinks.php';
require_once __DIR__ . '/space_settings.php';
require_once __DIR__ . '/service_auth.php';   // actor_spaces_filter(), wiki_path_space()
require_once __DIR__ . '/search_index.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/llm_providers.php';
require_once __DIR__ . '/realtime.php';      // wiki_chat_write(), wiki_realtime_publish()

// Pages under a space's top-level templates/ folder are page templates, not
// content — excluded from every search result (REST search, SQLite FTS, and the
// wiki_search_pages tool used by AI/MCP/advanced search).
function wiki_is_template_path(?string $path): bool {
    return $path !== null && str_starts_with(ltrim($path, '/'), 'templates/');
}

// Every user record, or an empty list when there is no user file (auth disabled).
function wiki_all_users(): array {
    if (!defined('WIKI_SYSTEM_DATA')) return [];
    $f = rtrim(WIKI_SYSTEM_DATA, '/') . '/users.json';
    if (!is_file($f)) return [];
    return json_decode((string)file_get_contents($f), true)['users'] ?? [];
}

function wiki_tool_definitions(): array {
    return [
        [
            'name'        => 'wiki_list_pages',
            'description' => 'List all pages in the current wiki space. Returns a JSON array of objects with "id", "path", "space", and "tags" (array, only present when non-empty) fields. Use this to find pages by tag or to discover what content exists before reading.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'wiki_search_pages',
            'description' => 'Search Markdown and .json data pages in the current wiki space by topic, recency, and/or tags. Returns a JSON array of matching pages with "id", "path", "space", "updated" (ISO 8601 last-modified timestamp), and — for text queries — "header" (first heading) and "preview" (a snippet). Provide "query" to search page text, "updated_within_days" to restrict to recently-updated pages (e.g. answer "pages updated in the last 7 days" with updated_within_days=7 and no query), and/or "tags" to require exact tags. At least one of the three is required.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'query'               => ['type' => 'string',  'description' => 'Search string, e.g. "onboarding checklist". Omit to list purely by recency and/or tags.'],
                    'updated_within_days' => ['type' => 'integer', 'description' => 'Only return pages updated within this many days. E.g. 7 for the last week. Omit for no date restriction.'],
                    'tags'                => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only return pages carrying ALL of these exact tags (case-insensitive). Omit for no tag restriction.'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'wiki_read_page',
            'description' => 'Read the full content of a wiki page by its relative path. Not only Markdown: ".md" returns the markdown source, ".json" returns the raw JSON document — use this to read stored data such as sales figures before summarising or charting it — ".list" returns the structured list file, and ".chat" returns the thread as JSON. Anything else is refused.',
            'params'      => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path to the page, e.g. Notes/Meeting.md']],
                'required'   => ['path'],
            ],
        ],
        [
            'name'        => 'wiki_write_page',
            'description' => 'Create a new wiki page or overwrite an existing one with markdown content. Path must end in .md. Both "path" and "content" are required — you MUST supply the complete markdown text in "content"; omitting it or passing an empty string is an error. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'    => ['type' => 'string', 'description' => 'Relative path ending in .md, e.g. Notes/Summary.md. For a NEW page, put it in the current folder named in the system prompt unless the request asks for a different location — do not invent a folder.'],
                    'content' => ['type' => 'string', 'description' => 'REQUIRED: the complete markdown content of the page. Must not be omitted or empty.'],
                ],
                'required'   => ['path', 'content'],
            ],
        ],
        [
            'name'        => 'wiki_write_json',
            'description' => 'Create a new .json data page or overwrite an existing one — use this to persist raw structured data such as statistics, reports, or query results that do not fit the schema-based .list type. Path must end in .json. Both "path" and "content" are required: "content" MUST be a string containing well-formed JSON (it is parsed and rejected if invalid). The saved page is shown read-only as a formatted, collapsible JSON tree. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'    => ['type' => 'string', 'description' => 'Relative path ending in .json, e.g. Reports/Q3-stats.json. For a NEW page, put it in the current folder named in the system prompt unless the request asks for a different location — do not invent a folder.'],
                    'content' => ['type' => 'string', 'description' => 'REQUIRED: the complete JSON document as a string. Must be valid JSON (object or array) and must not be empty.'],
                ],
                'required'   => ['path', 'content'],
            ],
        ],
        [
            'name'        => 'wiki_related_pages',
            'description' => 'Find pages related to a given page by traversing the wiki knowledge graph, which combines three relationship types: explicit pageid links between pages, folder hierarchy (parent/child/sibling pages sharing a directory), and shared tags. Returns a JSON array of related pages with "id", "path", "label", "tags", "distance" (hops from the start page, nearest first) and "via" (the relationship type that connected it: "reference", "containment", or "tag"). Use this to discover context around a page, find sibling/related content, or ground answers in the wiki\'s structure instead of raw full-text search.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path' => ['type' => 'string',  'description' => 'Relative path to the starting page, e.g. Notes/Meeting.md'],
                    'hops' => ['type' => 'integer', 'description' => 'How many relationship hops to traverse (1-4, default 1). 1 = direct neighbours only.'],
                ],
                'required'   => ['path'],
            ],
        ],
        [
            'name'        => 'wiki_add_tags',
            'description' => 'Add one or more tags to an existing wiki page without removing its current tags. Use this when you want to tag a page without affecting tags already on it. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path to the page, e.g. Notes/Meeting.md'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Array of tag strings to add to the page. Existing tags are kept.'],
                ],
                'required'   => ['path', 'tags'],
            ],
        ],
        [
            'name'        => 'wiki_set_tags',
            'description' => 'Replace ALL tags on an existing wiki page with the provided list. Use wiki_add_tags instead if you only want to add tags without removing existing ones. Pass an empty array to clear all tags. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path to the page, e.g. Notes/Meeting.md'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Array of tag strings. Completely replaces existing tags.'],
                ],
                'required'   => ['path', 'tags'],
            ],
        ],
        [
            'name'        => 'wiki_list_people',
            'description' => 'List the people who can be mentioned in this wiki (human users — AI users and API accounts are excluded). Returns a JSON array of objects with "name" and "role". Call this before wiki_mention_users so the names are spelled exactly right; a mention that misspells a name reaches nobody.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'wiki_mention_users',
            'description' => 'Notify one or more people by posting a message that mentions them, so it appears in their "My Mentions" list. Use this to tell someone a job is finished or that a page needs their attention. "target" is the chat thread (.chat) or page (.md) the message is appended to — a chat thread is usually the right place. Names must match wiki_list_people exactly. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'users'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of the people to mention, exactly as wiki_list_people returns them.'],
                    'message' => ['type' => 'string', 'description' => 'What to tell them, e.g. "the quarterly report is ready for review".'],
                    'target'  => ['type' => 'string', 'description' => 'Relative path of the .chat thread or .md page to post into, e.g. Team.chat or Reports/Q3.md'],
                ],
                'required'   => ['users', 'message', 'target'],
            ],
        ],
        [
            'name'        => 'wiki_rename_page',
            'description' => 'Rename or move a page within the current space, keeping its identity: the page id, tags, authorship, attachments and history all follow it, so existing ?pageid= links and {include:ID} tags keep working. The file extension cannot change. Give "new_path" the same folder to rename in place, or a different folder to move it. Wikilinks elsewhere that name the old title stop resolving — the result says how many, and you can pass retarget_links=true to rewrite them. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'     => ['type' => 'string', 'description' => 'Relative path of the existing page, e.g. Notes/Old Name.md'],
                    'new_path' => ['type' => 'string', 'description' => 'New relative path, same extension, e.g. Notes/New Name.md'],
                    'retarget_links' => ['type' => 'boolean', 'description' => 'Rewrite [[wikilinks]] in other pages that named the old title. Defaults to false — it edits other pages, so prefer reporting the count and letting the person decide.'],
                ],
                'required'   => ['path', 'new_path'],
            ],
        ],
    ];
}

function get_wiki_tools($provider) {
    $tools_def = wiki_tool_definitions();
    $family = llm_family($provider);
    if ($family === 'anthropic') {
        return array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $t['params'],
        ], $tools_def);
    }
    if ($family === 'openai-responses') {
        // Responses API uses a flat tool shape (no nested "function" object).
        return array_map(fn($t) => [
            'type'        => 'function',
            'name'        => $t['name'],
            'description' => $t['description'],
            'parameters'  => $t['params'],
        ], $tools_def);
    }
    // OpenAI Chat Completions
    return array_map(fn($t) => [
        'type'     => 'function',
        'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['params']],
    ], $tools_def);
}

// Single-space search by topic and/or recency.
//   - Text search mirrors the REST `search` action: SQLite FTS5 when configured,
//     otherwise a plain per-file stripos scan.
//   - Date filtering always uses index.json's `updated` timestamp (authoritative —
//     set on real edits and preserved across reindex, unlike SQLite's `updated`
//     which is reset to now on every rebuild), so it works with or without SQLite.
// $updated_within_days > 0 restricts to pages updated within that many days.
// $tags (exact, case-insensitive, AND) restricts to pages carrying all of them.
// An empty $query returns a listing filtered by date/tags (requires at least one filter).
function wiki_search_pages(string $query, $indexer, $space_dir, int $updated_within_days = 0, array $tags = []): array {
    $space_name = basename($space_dir);
    $cutoff     = $updated_within_days > 0 ? time() - $updated_within_days * 86400 : 0;
    $all        = $indexer->getAllPages();
    $updated_of = fn($id) => isset($all[$id]['updated']) ? (int)$all[$id]['updated'] : 0;
    $iso        = fn($ts) => $ts ? date('c', $ts) : null;

    // Exact tag match (case-insensitive); page must carry every requested tag.
    $want_tags = array_map('strtolower', array_filter(array_map('trim', $tags)));
    $has_all_tags = function($page_tags) use ($want_tags) {
        if (!$want_tags) return true;
        $have = array_map('strtolower', is_array($page_tags) ? $page_tags : []);
        foreach ($want_tags as $wt) if (!in_array($wt, $have, true)) return false;
        return true;
    };

    // Pure listing — no text query, so no file reads needed.
    if ($query === '') {
        $rows = [];
        foreach ($all as $id => $data) {
            if (!isset($data['path']) || !in_array(pathinfo($data['path'], PATHINFO_EXTENSION), WIKI_AI_SEARCH_EXTS, true)) continue;
            if (wiki_is_template_path($data['path'])) continue;
            $upd = (int)($data['updated'] ?? 0);
            if ($cutoff && $upd < $cutoff) continue;
            if (!$has_all_tags($data['tags'] ?? [])) continue;
            $rows[] = [
                'id'        => (string)$id,
                'path'      => $data['path'],
                'space'     => $space_name,
                'updated'   => $iso($upd),
                'updatedBy' => $data['updatedBy']['name'] ?? null,
                'tags'      => $data['tags'] ?? [],
            ];
        }
        usort($rows, fn($a, $b) => strcmp($b['updated'] ?? '', $a['updated'] ?? ''));
        return array_slice($rows, 0, 100);
    }

    $results = [];
    if (defined('SEARCH_ENGINE') && SEARCH_ENGINE === 'sqlite') {
        try {
            $search_idx = new SearchIndex();
            foreach ($search_idx->search($query, [$space_name], false) as $row) {
                if (wiki_is_template_path($row['path'])) continue;
                $page_id = $indexer->getId($row['path']);
                if ($page_id === null) continue;
                if ($cutoff && $updated_of($page_id) < $cutoff) continue;
                if (!$has_all_tags($all[$page_id]['tags'] ?? [])) continue;
                $results[] = [
                    'id'      => (string)$page_id,
                    'path'    => $row['path'],
                    'space'   => $space_name,
                    'updated' => $iso($updated_of($page_id)),
                    'header'  => $row['title'] ?? '',
                    'preview' => ($row['snippet'] ?? '…') !== '…' ? $row['snippet'] : ($row['preview'] ?? ''),
                ];
            }
            return $results;
        } catch (\Throwable $e) {
            // Fall through to the basic scan below.
        }
    }

    foreach ($all as $id => $data) {
        if (!isset($data['path']) || !in_array(pathinfo($data['path'], PATHINFO_EXTENSION), WIKI_AI_SEARCH_EXTS, true)) continue;
        if (wiki_is_template_path($data['path'])) continue;
        if ($cutoff && (int)($data['updated'] ?? 0) < $cutoff) continue;
        if (!$has_all_tags($data['tags'] ?? [])) continue;
        $abs = rtrim($space_dir, '/') . '/' . $data['path'];
        if (!file_exists($abs)) continue;
        $content = file_get_contents($abs);
        $pos = stripos($content, $query);
        if ($pos === false && stripos($data['path'], $query) === false) continue;

        $header = '';
        foreach (explode("\n", $content) as $line) {
            if (substr(trim($line), 0, 1) === '#') { $header = trim($line); break; }
        }
        $preview = $pos !== false
            ? '...' . trim(preg_replace('/\s+/', ' ', substr($content, max(0, $pos - 50), strlen($query) + 100))) . '...'
            : $header;

        $results[] = ['id' => (string)$id, 'path' => $data['path'], 'space' => $space_name, 'updated' => $iso((int)($data['updated'] ?? 0)), 'header' => $header, 'preview' => $preview];
    }
    return $results;
}

// Parses the compact Advanced Search query language into structured parts:
//   src:<slug>     route to a registered MCP source (0 or 1; first wins)
//   tag:<value>    exact tag filter (0+, AND); tag:"multi word" is honored
//   updated:<Nd>   pages updated within N days (also accepts updated:N or updated:<Nd)
//   <bare words>   free-text search
// Returns ['src'=>?string, 'tags'=>string[], 'days'=>int, 'text'=>string,
//          'filters'=>bool (any tag/date filter present)].
function parse_search_query(string $raw): array {
    $src = null; $tags = []; $days = 0; $words = [];
    // Tokenize respecting quotes so tag:"multi word" stays one token.
    preg_match_all('/(?:[^\s"]+"[^"]*")|"[^"]*"|\S+/', trim($raw), $m);
    foreach ($m[0] as $tok) {
        if (preg_match('/^src:(.+)$/i', $tok, $mm)) {
            if ($src === null) $src = strtolower(trim($mm[1], '"'));
        } elseif (preg_match('/^tag:(.+)$/i', $tok, $mm)) {
            $v = trim($mm[1], '"');
            if ($v !== '') $tags[] = $v;
        } elseif (preg_match('/^updated:<?(\d+)d?$/i', $tok, $mm)) {
            $days = (int)$mm[1];
        } else {
            $words[] = $tok;
        }
    }
    return [
        'src'     => $src,
        'tags'    => $tags,
        'days'    => $days,
        'text'    => implode(' ', $words),
        'filters' => (!empty($tags) || $days > 0),
    ];
}

// The tools that write. Named here because the read-only check below and any future
// per-space write rule need the same list, and adding a tool without adding it here
// would silently exempt it.
// What wiki_search_pages looks at. Exactly the set the SQLite FTS index holds full text
// for (see INDEX_SYNC_FTS_EXTS / SearchIndex::scanAndInsert), so the FTS branch and the
// two scan branches return the same kinds of page — otherwise a dataset is findable on
// an install with SEARCH_ENGINE=sqlite and invisible on one without it.
const WIKI_AI_SEARCH_EXTS = ['md', 'json'];

const WIKI_AI_WRITE_TOOLS = ['wiki_write_page', 'wiki_write_json', 'wiki_add_tags', 'wiki_set_tags', 'wiki_mention_users', 'wiki_rename_page'];

/**
 * What an AI tool is about to touch, for the audit log. Same shape as the REST map in
 * audit.php, but keyed by tool name and resolved from the tool's own arguments.
 */
const WIKI_AI_AUDIT_TOOLS = [
    'wiki_write_page'    => ['page',       'path'],
    'wiki_write_json'    => ['page',       'path'],
    'wiki_add_tags'      => ['tags',       'path'],
    'wiki_set_tags'      => ['tags',       'path'],
    'wiki_rename_page'   => ['page',       'path'],
    // Only when it appends to a .md page: a mention posted into a .chat is a chat
    // message, and those are out of scope wherever they come from — see the note on
    // WIKI_AUDIT_ACTIONS in audit.php. Gated on the target's type below rather than
    // dropped here, so the page case stays in scope like any other page write.
    'wiki_mention_users' => ['page',       'target'],
];

/**
 * Every AI write goes through here — chat replies, agent jobs and MCP alike — so the
 * audit hook sits at the same convergence point the read-only guard does.
 *
 * The actor is the AI user, not the session: an inline chat reply runs inside the
 * request of the person who posted, and recording them as the author of the page the AI
 * wrote would be exactly wrong. Whoever asked is kept alongside as requested_by.
 */
/**
 * Keep the FTS row for a page an AI just wrote.
 *
 * PageIndexer is kept in step by every write here, but the search index is separate and
 * was not: wiki_write_page and wiki_write_json created a page that `wiki_search_pages`
 * then could not find, which is worst exactly where it matters — an agent writing a
 * dataset and looking for it again on the next turn. index_sync cannot repair it either,
 * since it reconciles *drift* and a page written through the indexer is not drift.
 * Space key matches wiki_rename_page's movePage() call, so a written page and a renamed
 * one land under the same one.
 */
function wiki_ai_fts_upsert(string $space_dir, string $rel, string $content): void {
    if (!defined('SEARCH_ENGINE') || SEARCH_ENGINE !== 'sqlite') return;
    // Never let indexing break the write it is indexing.
    try { (new SearchIndex())->upsertPage(basename(rtrim($space_dir, '/')), $rel, $content); }
    catch (\Throwable $_e) {}
}

function execute_ai_tool($tool_name, $tool_input, $ai_user, $indexer, $space_dir) {
    // Space isolation, for the same reason the read-only guard is here: this is the one
    // point api.php, mcp.php and run_ai_agent_jobs.php share.
    //
    // Each caller gates the ?space= *parameter* against the actor's allowlist, which is
    // only half of it. Every tool builds its target as $space_dir . '/' . path with just
    // '..' stripped, so with no ?space= the base is PAGES_DIR and a path of
    // "Bravo/secret.md" reaches Bravo without the parameter ever being set. Ask which
    // Space the resolved path lands in instead.
    if (defined('AUTHENTICATION_ENABLED') && AUTHENTICATION_ENABLED) {
        $acl = actor_spaces_filter($ai_user['role'] ?? 'reader', $ai_user);
        if ($acl !== null) {
            foreach (['path', 'new_path', 'target'] as $arg) {
                $rel = ltrim(str_replace('..', '', (string)($tool_input[$arg] ?? '')), '/');
                if ($rel === '') continue;
                // Resolve the deepest existing ancestor: a page being created does not
                // exist yet, but the directory that decides its Space does.
                $probe = rtrim($space_dir, '/') . '/' . $rel;
                while ($probe !== '' && !file_exists($probe)) {
                    $parent = dirname($probe);
                    if ($parent === $probe) break;
                    $probe = $parent;
                }
                $real = realpath($probe);
                if ($real === false || !wiki_space_allowed($acl, wiki_path_space($real))) {
                    return 'Error: access denied to that space.';
                }
            }
        }
    }
    // A read-only Space is read-only for agents too. api.php's guard already covers
    // chat @mentions, but mcp.php and run_ai_agent_jobs.php call this function
    // directly — this is the one point all three routes share.
    if (in_array($tool_name, WIKI_AI_WRITE_TOOLS, true) && wiki_space_dir_is_readonly($space_dir)) {
        $denied = 'Error: the space "' . basename(rtrim($space_dir, '/')) . '" is read-only; nothing in it can be changed.';
        _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $denied,
                       is_file(rtrim($space_dir, '/') . '/' . ltrim(str_replace('..', '', (string)($tool_input['path'] ?? '')), '/')));
        return $denied;
    }
    // Whether the page already existed has to be sampled BEFORE the write, or a create
    // is indistinguishable from an update by the time the result comes back.
    $existed = null;
    if (in_array($tool_name, ['wiki_write_page', 'wiki_write_json'], true)) {
        $existed = is_file(rtrim($space_dir, '/') . '/'
                 . ltrim(str_replace('..', '', (string)($tool_input['path'] ?? '')), '/'));
    }
    $result = _execute_ai_tool_dispatch($tool_name, $tool_input, $ai_user, $indexer, $space_dir);
    wiki_trace_add(['type' => 'tool', 'name' => $tool_name, 'input' => $tool_input, 'output' => $result]);
    _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $result, $existed);
    return $result;
}

// Tools report failure by returning a string that starts with "Error:" — the existing
// convention, and the only outcome signal there is.
function _wiki_ai_audit(string $tool, array $input, $ai_user, $indexer, string $space_dir,
                        $result, ?bool $existed = null): void {
    if (!wiki_audit_enabled() || !isset(WIKI_AI_AUDIT_TOOLS[$tool])) return;
    [$category, $param] = WIKI_AI_AUDIT_TOOLS[$tool];
    $rel = ltrim(str_replace('..', '', (string)($input[$param] ?? '')), '/');
    if ($tool === 'wiki_mention_users' && strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'chat') return;

    $verb = 'update';
    if ($tool === 'wiki_rename_page')                       $verb = 'rename';
    elseif ($tool === 'wiki_add_tags' || $tool === 'wiki_set_tags') $verb = 'tag';
    elseif (in_array($tool, ['wiki_write_page', 'wiki_write_json'], true)) {
        $verb = $existed ? 'update' : 'create';
    }

    $ctx = $GLOBALS['_wiki_audit_context'] ?? [];
    wiki_audit_set_context(array_merge($ctx, [
        'user'    => $ai_user['name'] ?? 'AI',
        'user_id' => $ai_user['uid'] ?? null,
        'via'     => $ctx['via'] ?? 'ai',
    ]));

    $ok = !(is_string($result) && str_starts_with($result, 'Error:'));
    $fields = ['object_category' => $category, 'api_action' => $tool, 'tool' => $tool];
    if ($rel !== '') $fields['object'] = $rel;
    if ($rel !== '' && $indexer) {
        $id = $indexer->getId($rel);
        if ($id !== null) $fields['object_id'] = (string)$id;
    }
    if ($tool === 'wiki_rename_page' && !empty($input['new_path'])) {
        $fields['object_new'] = ltrim(str_replace('..', '', (string)$input['new_path']), '/');
        // After a rename the id lives at the new path.
        if ($indexer) {
            $nid = $indexer->getId($fields['object_new']);
            if ($nid !== null) $fields['object_id'] = (string)$nid;
        }
    }
    $space = rtrim($space_dir, '/');
    if (defined('PAGES_DIR') && $space !== rtrim(PAGES_DIR, '/')) $fields['space'] = basename($space);
    if (!$ok && is_string($result)) $fields['reason'] = ltrim(substr($result, 6));
    wiki_audit_log($verb, $ok ? 'success' : 'failure', $fields);
}

function _execute_ai_tool_dispatch($tool_name, $tool_input, $ai_user, $indexer, $space_dir) {
    switch ($tool_name) {
        case 'wiki_list_pages':
            $pages = $indexer->getAllPages();
            $space_name_lp = basename($space_dir);
            $result_lp = [];
            foreach ($pages as $id => $data) {
                if (empty($data['path'])) continue;
                $entry_lp = ['id' => (string)$id, 'path' => $data['path'], 'space' => $space_name_lp];
                if (!empty($data['tags'])) $entry_lp['tags'] = $data['tags'];
                $result_lp[] = $entry_lp;
            }
            usort($result_lp, fn($a, $b) => strcmp($a['path'], $b['path']));
            return json_encode($result_lp);

        case 'wiki_search_pages':
            $query = trim($tool_input['query'] ?? '');
            $days  = (int)($tool_input['updated_within_days'] ?? 0);
            $tags  = $tool_input['tags'] ?? [];
            if (!is_array($tags)) $tags = [];
            if ($query === '' && $days <= 0 && !$tags) return 'Error: provide "query", "updated_within_days", "tags", or a combination.';
            return json_encode(wiki_search_pages($query, $indexer, $space_dir, $days, $tags));

        case 'wiki_read_page':
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $ext = pathinfo($rel, PATHINFO_EXTENSION);
            if (!in_array($ext, ['md', 'list', 'chat', 'json'], true)) return 'Error: only .md, .list, .chat and .json files can be read.';
            $abs = rtrim($space_dir, '/') . '/' . $rel;
            if (!file_exists($abs) || !is_file($abs)) return 'Error: page not found.';
            return file_get_contents($abs);

        case 'wiki_write_page':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot write pages.';
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            if (pathinfo($rel, PATHINFO_EXTENSION) !== 'md') return 'Error: only .md files can be written.';
            if (!isset($tool_input['content']) || $tool_input['content'] === '') {
                return 'Error: content parameter is required and must not be empty. Call wiki_write_page again and include the full markdown content in the "content" field.';
            }
            $content = $tool_input['content'];
            $abs     = rtrim($space_dir, '/') . '/' . $rel;
            $dir     = dirname($abs);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $is_new  = !file_exists($abs);
            if (file_put_contents($abs, $content) === false) return 'Error: could not write file.';
            wiki_ai_fts_upsert($space_dir, $rel, $content);
            $ai_git_name  = $ai_user['name'] ?? 'AI';
            $ai_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            if ($is_new) {
                $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Create ' . basename($rel), $space_dir);
                return "Page created: {$rel}";
            }
            $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Update ' . basename($rel), $space_dir);
            return "Page updated: {$rel}";

        case 'wiki_write_json':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot write pages.';
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            if (pathinfo($rel, PATHINFO_EXTENSION) !== 'json') return 'Error: only .json files can be written with wiki_write_json.';
            if (!isset($tool_input['content']) || $tool_input['content'] === '') {
                return 'Error: content parameter is required and must not be empty. Call wiki_write_json again with the full JSON document as a string in the "content" field.';
            }
            $content = $tool_input['content'];
            if (!is_string($content)) $content = json_encode($content);
            $decoded_json = json_decode($content, true);
            if ($decoded_json === null && strtolower(trim($content)) !== 'null') {
                return 'Error: content is not valid JSON (' . json_last_error_msg() . '). Fix the JSON and call wiki_write_json again.';
            }
            // Store pretty-printed for readable diffs and raw viewing.
            $content = json_encode($decoded_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $abs     = rtrim($space_dir, '/') . '/' . $rel;
            $dir     = dirname($abs);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $is_new  = !file_exists($abs);
            if (file_put_contents($abs, $content) === false) return 'Error: could not write file.';
            wiki_ai_fts_upsert($space_dir, $rel, $content);
            $ai_git_name  = $ai_user['name'] ?? 'AI';
            $ai_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            if ($is_new) {
                $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Create ' . basename($rel), $space_dir);
                return "JSON page created: {$rel}";
            }
            $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Update ' . basename($rel), $space_dir);
            return "JSON page updated: {$rel}";

        case 'wiki_list_people':
            // Names only, plus role. An AI has no business with anyone's email address,
            // and a mention needs nothing more than the name.
            $lp_out = [];
            foreach (wiki_all_users() as $lp_u) {
                if (!empty($lp_u['is_ai']) || !empty($lp_u['is_system'])) continue;
                if (empty($lp_u['name'])) continue;
                $lp_out[] = ['name' => $lp_u['name'], 'role' => $lp_u['role'] ?? 'editor'];
            }
            return json_encode($lp_out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        case 'wiki_mention_users':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot post mentions.';
            $mu_names = $tool_input['users'] ?? [];
            if (is_string($mu_names)) $mu_names = [$mu_names];
            if (!is_array($mu_names) || !$mu_names) return 'Error: "users" is required and must be a non-empty array of names.';
            $mu_message = trim((string)($tool_input['message'] ?? ''));
            if ($mu_message === '') return 'Error: "message" is required.';
            $mu_rel = ltrim(str_replace('..', '', (string)($tool_input['target'] ?? '')), '/');
            if ($mu_rel === '') return 'Error: "target" is required — the .chat or .md path to post into.';
            $mu_ext = strtolower(pathinfo($mu_rel, PATHINFO_EXTENSION));
            if (!in_array($mu_ext, ['chat', 'md'], true)) return 'Error: target must be a .chat thread or a .md page.';

            // Resolve every name before writing anything: a half-delivered notification
            // that silently drops the one person who mattered is worse than an error the
            // model can correct.
            $mu_people = [];
            foreach (wiki_all_users() as $mu_u) {
                if (!empty($mu_u['is_ai']) || !empty($mu_u['is_system']) || empty($mu_u['name'])) continue;
                $mu_people[mb_strtolower($mu_u['name'])] = $mu_u['name'];
            }
            $mu_resolved = [];
            $mu_unknown  = [];
            foreach ($mu_names as $mu_n) {
                $mu_key = mb_strtolower(trim(ltrim((string)$mu_n, '@#')));
                if (isset($mu_people[$mu_key])) $mu_resolved[] = $mu_people[$mu_key];
                else                            $mu_unknown[]  = (string)$mu_n;
            }
            if ($mu_unknown) {
                return 'Error: no such user: ' . implode(', ', $mu_unknown)
                     . '. Call wiki_list_people for the exact names. Nothing was posted.';
            }
            $mu_resolved = array_values(array_unique($mu_resolved));
            // @ is the sigil that addresses a person; # would aim at an AI user.
            $mu_line = implode(' ', array_map(fn($n) => '@' . $n, $mu_resolved)) . ' ' . $mu_message;

            $mu_abs = rtrim($space_dir, '/') . '/' . $mu_rel;
            if (!file_exists($mu_abs)) return 'Error: target not found: ' . $mu_rel;
            $mu_git_name  = $ai_user['name'] ?? 'AI';
            $mu_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';

            if ($mu_ext === 'chat') {
                $mu_data = json_decode((string)file_get_contents($mu_abs), true);
                if (!is_array($mu_data) || !isset($mu_data['messages'])) return 'Error: not a valid chat thread: ' . $mu_rel;
                $mu_data['messages'][] = [
                    'id'        => $mu_data['nextMessageId'] ?? (count($mu_data['messages']) + 1),
                    'uid'       => (int)($ai_user['uid'] ?? 0),
                    'name'      => $ai_user['name'] ?? 'AI',
                    'timestamp' => date('c'),
                    'text'      => $mu_line,
                ];
                $mu_data['nextMessageId'] = ($mu_data['nextMessageId'] ?? count($mu_data['messages'])) + 1;
                if (!wiki_chat_write($mu_abs, $mu_data)) {
                    return 'Error: could not write to ' . $mu_rel;
                }
            } else {
                if (file_put_contents($mu_abs, "\n\n" . $mu_line . "\n", FILE_APPEND) === false) {
                    return 'Error: could not write to ' . $mu_rel;
                }
            }
            $indexer->updateModified($mu_rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            // The one place the wiki knows a mention was created without re-scanning content
            // for it. Everything else that can mention somebody is a human typing into a page
            // or a thread, where finding out costs a scan — so the badge keeps its (demoted)
            // poll for those, and this path gets to be instant.
            if (function_exists('wiki_realtime_publish')) {
                $mu_uids = [];
                foreach (wiki_all_users() as $mu_u) {
                    if (in_array((string)($mu_u['name'] ?? ''), $mu_resolved, true)) {
                        $mu_uids[] = (int)($mu_u['uid'] ?? 0);
                    }
                }
                foreach (array_unique($mu_uids) as $mu_uid) {
                    if ($mu_uid > 0) wiki_realtime_publish(wiki_rt_topic_mention($mu_uid), ['type' => 'mention']);
                }
            }
            git_auto_commit($mu_abs, $mu_git_name, $mu_git_email, 'Mention ' . implode(', ', $mu_resolved) . ' in ' . basename($mu_rel), $space_dir);
            return 'Mentioned ' . implode(', ', $mu_resolved) . ' in ' . $mu_rel
                 . '. They will see it in their My Mentions list.';

        case 'wiki_rename_page':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot rename pages.';
            $rn_old = ltrim(str_replace('..', '', (string)($tool_input['path'] ?? '')), '/');
            $rn_new = ltrim(str_replace('..', '', (string)($tool_input['new_path'] ?? '')), '/');
            if ($rn_old === '' || $rn_new === '') return 'Error: both "path" and "new_path" are required.';
            if ($rn_old === $rn_new) return 'Error: "new_path" is the same as "path".';
            // The extension decides how the wiki renders and indexes a file, so a rename
            // must not change it — and must never be able to turn a page into something else.
            if (strtolower(pathinfo($rn_old, PATHINFO_EXTENSION)) !== strtolower(pathinfo($rn_new, PATHINFO_EXTENSION))) {
                return 'Error: the file extension cannot change in a rename.';
            }
            $rn_abs_old = rtrim($space_dir, '/') . '/' . $rn_old;
            $rn_abs_new = rtrim($space_dir, '/') . '/' . $rn_new;
            if (!is_file($rn_abs_old)) return 'Error: page not found: ' . $rn_old;
            if (file_exists($rn_abs_new)) return 'Error: something already exists at ' . $rn_new;
            $rn_parent = dirname($rn_abs_new);
            if (!is_dir($rn_parent)) return 'Error: target folder does not exist: ' . ltrim(dirname($rn_new), '.');

            if (!@rename($rn_abs_old, $rn_abs_new)) return 'Error: could not rename the file.';

            // updatePath, never removePage+addPage: the id is what ?pageid= links and
            // {include:ID} tags point at, and minting a new one would break every one of them.
            $indexer->updatePath($rn_old, $rn_new);

            // Attachments and the cached diagram export belong to the page, so they follow
            // it — the single-page move in api.php does the same.
            if (is_dir($rn_abs_old . '.uploads')) @rename($rn_abs_old . '.uploads', $rn_abs_new . '.uploads');
            if (is_file($rn_abs_old . '.svg'))     @rename($rn_abs_old . '.svg',     $rn_abs_new . '.svg');

            if (defined('SEARCH_ENGINE') && SEARCH_ENGINE === 'sqlite') {
                try { (new SearchIndex())->movePage(basename(rtrim($space_dir, '/')), $rn_old, $rn_new); }
                catch (\Throwable $_e) {}
            }

            // A wikilink names its target, so [[Old Name]] elsewhere stops resolving. Counted
            // always, rewritten only when asked: this is the one tool that edits pages the
            // request did not name, and the UI asks a human before doing it too.
            $rn_links = 0;
            $rn_fixed = 0;
            foreach ($indexer->getAllPages() as $rn_data) {
                $rn_rel = (string)($rn_data['path'] ?? '');
                if ($rn_rel === '' || $rn_rel === $rn_new) continue;
                if (strtolower(pathinfo($rn_rel, PATHINFO_EXTENSION)) !== 'md') continue;   // only Markdown carries them
                $rn_file = rtrim($space_dir, '/') . '/' . $rn_rel;
                if (!is_file($rn_file)) continue;
                $rn_text = (string)file_get_contents($rn_file);
                [$rn_out, $rn_n] = wikilink_retarget($rn_text, $rn_old, $rn_new);
                if ($rn_n === 0) continue;
                $rn_links += $rn_n;
                if (!empty($tool_input['retarget_links']) && $rn_out !== $rn_text
                    && file_put_contents($rn_file, $rn_out) !== false) {
                    $rn_fixed += $rn_n;
                    $indexer->updateModified($rn_rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                }
            }

            $rn_git_name  = $ai_user['name'] ?? 'AI';
            $rn_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            git_move_commit($rn_abs_old, $rn_abs_new, $rn_git_name, $rn_git_email, $space_dir);

            $rn_msg = 'Renamed ' . $rn_old . ' to ' . $rn_new . '. The page keeps its id, tags and attachments.';
            if ($rn_fixed > 0)      $rn_msg .= ' Also updated ' . $rn_fixed . ' wikilink(s) that named the old title.';
            elseif ($rn_links > 0)  $rn_msg .= ' ' . $rn_links . ' wikilink(s) elsewhere still name the old title and no longer resolve'
                                             . ' — call again with retarget_links=true to rewrite them, or tell the user.';
            return $rn_msg;

        case 'wiki_related_pages':
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $page_id = $indexer->getId($rel);
            if ($page_id === null) return 'Error: page not found in index — make sure the path matches exactly what wiki_list_pages returns.';
            $hops    = max(1, min(4, (int)($tool_input['hops'] ?? 1)));
            $graph   = new WikiGraph($space_dir, $indexer);
            $related = $graph->related((string)$page_id, $hops);
            $space_name_rp = basename($space_dir);
            foreach ($related as &$_r) { $_r['space'] = $space_name_rp; $_r['id'] = (string)$_r['id']; }
            return json_encode($related);

        case 'wiki_add_tags':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot set tags.';
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $tags_input = $tool_input['tags'] ?? [];
            if (!is_array($tags_input)) return 'Error: tags must be an array.';
            $page_id = $indexer->getId($rel);
            if ($page_id === null) return 'Error: page not found in index — make sure the path matches exactly what wiki_list_pages returns.';
            $existing_tags = $indexer->getTags($page_id);
            $merged_tags = array_values(array_unique(array_merge($existing_tags, array_filter(array_map('trim', $tags_input)))));
            $indexer->updateTags($page_id, $merged_tags);
            return "Tags on {$rel}: " . implode(', ', $merged_tags);

        case 'wiki_set_tags':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot set tags.';
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $tags_input = $tool_input['tags'] ?? [];
            if (!is_array($tags_input)) return 'Error: tags must be an array.';
            $page_id = $indexer->getId($rel);
            if ($page_id === null) return 'Error: page not found in index — make sure the path matches exactly what wiki_list_pages returns.';
            $indexer->updateTags($page_id, $tags_input);
            $set_count = count(array_filter(array_map('trim', $tags_input)));
            return $set_count > 0 ? "Tags set on {$rel}: " . implode(', ', array_filter(array_map('trim', $tags_input))) : "Tags cleared on {$rel}.";

        default:
            return 'Error: unknown tool.';
    }
}
