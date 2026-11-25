<?php
// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
// =================================================================
// WIKI AI TOOLS — shared by api.php (chat @mentions) and mcp.php (MCP tools/call)
// Defines the wiki_* tool set and executes it against a PageIndexer/space_dir.
// =================================================================

require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/frontmatter.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/skills.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/llm_trace.php';
require_once __DIR__ . '/wikilinks.php';
require_once __DIR__ . '/space_settings.php';
require_once __DIR__ . '/service_auth.php';   // actor_spaces_filter(), wiki_path_space()
require_once __DIR__ . '/search_index.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/content_health.php';
require_once __DIR__ . '/llm_providers.php';
require_once __DIR__ . '/realtime.php';      // wiki_chat_write(), wiki_realtime_publish()
require_once __DIR__ . '/page_chat.php';    // a page's .chat thread moves with it

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

/**
 * @param bool $with_memory Include the three memory tools. False when this space does not
 *        keep memories or this AI user does not learn — an offered tool that can only
 *        ever answer "Error: memory is off" costs tokens on every call and invites the
 *        model to keep trying it.
 */
function wiki_tool_definitions(bool $with_memory = true, bool $with_skills = false): array {
    $defs = [
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
                    'tags'                => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only return pages carrying ALL of these tags (case-insensitive; a taxonomy path also matches the items below it). Omit for no tag restriction.'],
                    'fields'              => ['type' => 'object', 'description' => 'Only return pages whose front matter has these values, e.g. {"department": "Finance"}. Only fields bound to the taxonomy (see wiki_get_taxonomy); a value also matches the items below it.'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'wiki_read_page',
            'description' => 'Read the full content of a wiki page by its relative path. Not only Markdown: ".md" returns the markdown source, ".json" returns the raw JSON document — use this to read stored data such as sales figures before summarising or charting it — ".list" returns the structured list file (its "columns" are the fields of the list; a field of type "lookup" stores an array of item ids from the list whose page id is in its "lookup.list" — read that list to see what they are — and a "lookup_field" column is a read-only copy of another field of those items; a "number" holds a JSON number, a "boolean" (Yes/No) true or false; a "choice" with "taxonomy" takes the names of the items in that taxonomy folder (see wiki_get_taxonomy), and with "multiple" an array of them; "_comments" on an item is the comment log of that item — {id, by (user id), name, at (UTC time), text} entries, oldest first — not a field; wiki_update_list_item changes one item\'s fields or adds a comment to it, wiki_add_list_item adds a new item, and wiki_create_list creates a new list), and ".chat" returns the thread as JSON. Anything else is refused.',
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
            'name'        => 'wiki_update_list_item',
            'description' => 'Change one existing item of a .list in the current space: set some of its fields, and/or add a comment to it. Read the list with wiki_read_page first to see its fields and items. Name fields as the list does ("Status", "Account manager") or by column id. Values by field type: text, date ("YYYY-MM-DD") and choice (one of the field\'s options — for a choice with "taxonomy", the name of an item in that taxonomy folder; with "multiple", an array of them) are strings; a number is a number; Yes/No is true or false; a lookup is an item id, or an array of ids, from the list it looks up. null or "" clears a field (not the Title). ItemId, "also show" fields (lookup_field) and groups of fields cannot be set here. A comment is saved with your name and the time. Any pages generated from this list are re-rendered with the change. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'    => ['type' => 'string', 'description' => 'Relative path of the list, ending in .list'],
                    'item_id' => ['type' => 'integer', 'description' => 'The item\'s "id" in the list file'],
                    'fields'  => ['type' => 'object', 'description' => 'Field name (or column id) => new value. Fields left out are not changed.'],
                    'comment' => ['type' => 'string', 'description' => 'A comment to add to the item.'],
                ],
                'required'   => ['path', 'item_id'],
            ],
        ],
        [
            'name'        => 'wiki_create_list',
            'description' => 'Create a new, empty structured list (.list) in the current space, with the fields you name. Refused if anything already exists at the path — it never replaces a list. Each field has a "name" and a "type": "text" (one line), "multiline", "date", "choice" (give "options", or "taxonomy" with a taxonomy folder path to take its values from there; "multiple": true allows several), "number" (optional "decimals" 0-6 and "unit"), "yesno", or "lookup" (give "list": the path or page id of another .list in this space; "multiple": true allows several items). One field is the list\'s Title — what an item is called, and what a lookup in another list shows. It must be a "text" field: name it in "title", or the first "text" field is used. An ItemId field is added and numbered by the list. Add items afterwards with wiki_add_list_item. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'   => ['type' => 'string', 'description' => 'Relative path ending in .list, e.g. Registers/Risks.list. Folders that do not exist are created.'],
                    'fields' => ['type' => 'array', 'description' => 'The fields, in the order they are shown.',
                                 'items' => ['type' => 'object', 'properties' => [
                                     'name'        => ['type' => 'string'],
                                     'type'        => ['type' => 'string', 'enum' => ['text', 'multiline', 'date', 'choice', 'number', 'yesno', 'lookup']],
                                     'description' => ['type' => 'string'],
                                     'options'     => ['type' => 'array', 'items' => ['type' => 'string']],
                                     'taxonomy'    => ['type' => 'string'],
                                     'multiple'    => ['type' => 'boolean'],
                                     'decimals'    => ['type' => 'integer'],
                                     'unit'        => ['type' => 'string'],
                                     'list'        => ['type' => 'string'],
                                 ], 'required' => ['name', 'type']]],
                    'title'  => ['type' => 'string', 'description' => 'Name of the field that is the Title. Optional: the first "text" field otherwise.'],
                ],
                'required'   => ['path', 'fields'],
            ],
        ],
        [
            'name'        => 'wiki_add_list_item',
            'description' => 'Add a new item to an existing .list in the current space. Read the list with wiki_read_page first to see its fields. Give values by field name ("Status", "Account manager") or column id, with the same value rules as wiki_update_list_item: text, date ("YYYY-MM-DD") and choice (one of its options; for a choice with "taxonomy", an item of that taxonomy folder; with "multiple", an array) are strings; a number is a number; Yes/No is true or false; a lookup is an item id, or an array of ids, from the list it looks up. Only the list\'s Title field is required; fields left out stay empty. ItemId is numbered by the list, and "also show" fields (lookup_field) and groups of fields cannot be set. Returns the new item\'s id — use it with wiki_update_list_item to change or comment on the item later, or as a lookup value in another list. Any pages generated from this list are re-rendered. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'path'    => ['type' => 'string', 'description' => 'Relative path of the list, ending in .list'],
                    'fields'  => ['type' => 'object', 'description' => 'Field name (or column id) => value. Must include the Title field.'],
                    'comment' => ['type' => 'string', 'description' => 'Optional first comment on the new item.'],
                ],
                'required'   => ['path', 'fields'],
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
            'name'        => 'wiki_content_health',
            'description' => 'Report what needs attention in the current Space: broken links (pageid links to pages that no longer exist, [[wikilinks]] no page answers to, embedded attachments that are missing), stale pages (front matter review_by date passed, or not updated for stale_days), and empty or stub pages. Read-only. Returns plain text with page paths and ids, each list capped at 50 rows with the true totals stated. Use it for a periodic content review, or before tidying a Space.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'stale_days' => ['type' => 'integer', 'description' => 'A page not updated for this many days counts as stale (default 180). Pages with a future review_by date are never stale.'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'wiki_get_taxonomy',
            'description' => 'Get the wiki\'s taxonomy: the one wiki-wide tree of terms that tags are chosen from. A tag is a term\'s full path, e.g. "Department/Finance". Returns the tagging mode and every term, one per line, indented by level, with its description. Folders group terms and can never be used as tags. Call this before tagging so you use existing terms rather than inventing near-duplicates; in "controlled" mode a tag that is not a term is refused.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'wiki_add_tags',
            'description' => 'Add one or more tags to an existing wiki page without removing its current tags. Use this when you want to tag a page without affecting tags already on it. Tags are paths in the wiki taxonomy ("Department/Finance") — see wiki_get_taxonomy. Only available when the AI user has editor role.',
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
            'name'        => 'wiki_post_chat',
            'description' => 'Post a message in a chat thread, as yourself. "target" is either a .chat thread, or a Markdown page (.md) — then the message goes into that page\'s own chat, where its readers discuss it, and the chat is created if nobody has started it yet. Use it to leave feedback, a review or a note on a page without changing the page itself. It notifies nobody: to reach particular people, use wiki_mention_users or write @Name in the message. Only available when the AI user has editor role.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'target'  => ['type' => 'string', 'description' => 'Relative path of a .md page (posts in its page chat) or a .chat thread, e.g. Reports/Q3.md or Team.chat'],
                    'message' => ['type' => 'string', 'description' => 'The message, in Markdown.'],
                ],
                'required'   => ['target', 'message'],
            ],
        ],
        [
            'name'        => 'wiki_remember',
            'description' => 'Store one fact in your persistent memory for this space, as an ordinary wiki page in memory/. Use it when the user tells you something worth keeping beyond this conversation — a decision, a preference, a convention, how they work. One fact per memory. The title is what you see in your memory listing later, so write it as a short sentence rather than a keyword. Calling it again with the same title replaces that memory, which is how you correct one. Do not store secrets, and do not copy what is already on a wiki page — remember where it is instead. Only available when the space keeps memories and this AI user has learning enabled.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'A short sentence naming the fact, e.g. "Deploys go out on Thursday afternoons"'],
                    'fact'  => ['type' => 'string', 'description' => 'The thing to remember, in a sentence or two. Include why, if it matters.'],
                    'tags'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional tags for grouping, e.g. ["process", "deploys"]. Tags are how memories are grouped — there are no folders.'],
                ],
                'required'   => ['title', 'fact'],
            ],
        ],
        [
            'name'        => 'wiki_recall',
            'description' => 'Read what you have remembered in this space. With a query, returns the memories whose title, tags or text match it; with no query, returns the most recent ones. Your system prompt already lists the titles you know — use this to read the full text of one before relying on it.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'What to look for. Omit to list the most recent memories.'],
                    'limit' => ['type' => 'integer', 'description' => 'How many to return (default 10).'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'wiki_forget',
            'description' => 'Delete one memory by its exact title, when it is wrong or out of date and there is nothing to replace it with. To correct a memory instead, call wiki_remember with the same title.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'The exact title of the memory, as shown in your memory listing.'],
                ],
                'required'   => ['title'],
            ],
        ],
        [
            'name'        => 'wiki_rename_page',
            'description' => 'Rename or move a page within the current space, keeping its identity: the page id, tags, authorship, attachments, its page chat thread and history all follow it, so existing ?pageid= links and {include:ID} tags keep working. The file extension cannot change. Give "new_path" the same folder to rename in place, or a different folder to move it. Wikilinks elsewhere that name the old title stop resolving — the result says how many, and you can pass retarget_links=true to rewrite them. Only available when the AI user has editor role.',
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
    // Same reasoning as memory: a tool that can only answer "skills are off" is not offered.
    if ($with_skills) {
        $defs[] = [
            'name'        => 'wiki_read_skill',
            'description' => 'Read one of the skills listed in your instructions: procedures an administrator wrote for a kind of task. Call it with the skill\'s name before starting a task the skill describes, and follow what it says. Without "file" it returns the skill\'s instructions and the names of its reference files; with "file" it returns that reference file.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'The skill\'s name exactly as listed, e.g. weekly-status-report'],
                    'file' => ['type' => 'string', 'description' => 'Optional: a reference file the skill names, e.g. reference.md. Omit to read the skill itself.'],
                ],
                'required'   => ['name'],
            ],
        ];
    }
    if ($with_memory) return $defs;
    return array_values(array_filter($defs,
        fn($t) => !in_array($t['name'], ['wiki_remember', 'wiki_recall', 'wiki_forget'], true)));
}

function get_wiki_tools($provider, bool $with_memory = true, bool $with_skills = false) {
    $tools_def = wiki_tool_definitions($with_memory, $with_skills);
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
function wiki_search_pages(string $query, $indexer, $space_dir, int $updated_within_days = 0, array $tags = [], array $fields = []): array {
    $space_name = basename($space_dir);
    $cutoff     = $updated_within_days > 0 ? time() - $updated_within_days * 86400 : 0;
    $all        = $indexer->getAllPages();
    $updated_of = fn($id) => isset($all[$id]['updated']) ? (int)$all[$id]['updated'] : 0;
    $iso        = fn($ts) => $ts ? date('c', $ts) : null;

    // Tag match (case-insensitive); page must carry every requested tag — or, the tag
    // being a taxonomy path, something below it: tag:Finance finds Finance/Budget.
    $want_tags = array_filter(array_map('trim', $tags));
    $has_all_tags = function($page_tags) use ($want_tags) {
        if (!$want_tags) return true;
        $have = is_array($page_tags) ? $page_tags : [];
        foreach ($want_tags as $wt) {
            $hit = false;
            foreach ($have as $h) if (wiki_tax_under((string)$h, $wt)) { $hit = true; break; }
            if (!$hit) return false;
        }
        return true;
    };
    // Fields bound to the taxonomy: field => value, matched against the page's own block,
    // a value including the items below it. Read from the file, so only when asked for.
    $field_filters = [];
    foreach ($fields as $fk => $fv) {
        if (trim((string)$fv) !== '') $field_filters[strtolower((string)$fk)] = wiki_tax_field_expand((string)$fk, (string)$fv);
    }
    $fields_ok = function (string $rel) use ($field_filters, $space_dir): bool {
        if (!$field_filters) return true;
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'md') return false;
        $raw = (string)@file_get_contents(rtrim($space_dir, '/') . '/' . $rel, false, null, 0, 65536);
        return wiki_tax_meta_matches(wiki_fm_split($raw)['meta'], $field_filters);
    };

    // Pure listing — no text query, so no file reads needed.
    if ($query === '') {
        $rows = [];
        foreach ($all as $id => $data) {
            if (!isset($data['path']) || !in_array(pathinfo($data['path'], PATHINFO_EXTENSION), WIKI_AI_SEARCH_EXTS, true)) continue;
            if (wiki_is_template_path($data['path']) || wiki_is_memory_path($data['path']) || wiki_is_skill_path($data['path'])) continue;
            $upd = (int)($data['updated'] ?? 0);
            if ($cutoff && $upd < $cutoff) continue;
            if (!$has_all_tags($data['tags'] ?? [])) continue;
            if (!$fields_ok($data['path'])) continue;
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
                if (wiki_is_template_path($row['path']) || wiki_is_memory_path($row['path']) || wiki_is_skill_path($row['path'])) continue;
                $page_id = $indexer->getId($row['path']);
                if ($page_id === null) continue;
                if ($cutoff && $updated_of($page_id) < $cutoff) continue;
                if (!$has_all_tags($all[$page_id]['tags'] ?? [])) continue;
                if (!$fields_ok($row['path'])) continue;
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
        if (wiki_is_template_path($data['path']) || wiki_is_memory_path($data['path']) || wiki_is_skill_path($data['path'])) continue;
        if ($cutoff && (int)($data['updated'] ?? 0) < $cutoff) continue;
        if (!$has_all_tags($data['tags'] ?? [])) continue;
        if (!$fields_ok($data['path'])) continue;
        $abs = rtrim($space_dir, '/') . '/' . $data['path'];
        if (!file_exists($abs)) continue;
        $content = file_get_contents($abs);
        // The FTS branch above indexes the body only (see wiki_ai_fts_upsert), so this
        // fallback has to match it, or the same query answers differently depending on
        // whether the install has SQLite.
        if (pathinfo($data['path'], PATHINFO_EXTENSION) === 'md') $content = wiki_fm_body($content);
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

/**
 * Check the bound front-matter fields an AI write changed (taxonomy.php). Returns
 * [content, error]: the content with valid values respelled as the tree spells them, or an
 * error naming the field and its values. Only a value that differs from what the file
 * already held is checked — an odd value from an import is not the AI's to answer for.
 */
function wiki_ai_check_bound_fields(string $prev_raw, string $new_raw): array {
    $fields = wiki_tax_fields();
    if (!$fields) return [$new_raw, ''];
    $old = wiki_fm_split($prev_raw)['meta'];
    $new = wiki_fm_split($new_raw)['meta'];
    $norm = function ($v, bool $multi) {
        $items = is_array($v) ? $v : ($multi ? explode(',', (string)$v) : [(string)$v]);
        return array_values(array_filter(array_map(fn($x) => trim((string)$x), $items), fn($x) => $x !== ''));
    };
    $updates = [];
    foreach ($new as $k => $v) {
        $f = $fields[strtolower((string)$k)] ?? null;
        if (!$f) continue;
        $was = null;
        foreach ($old as $ok => $ov) if (strcasecmp((string)$ok, (string)$k) === 0) { $was = $ov; break; }
        $items = $norm($v, $f['multiple']);
        if ($was !== null && array_map('wiki_tax_lc', $norm($was, $f['multiple'])) === array_map('wiki_tax_lc', $items)) continue;
        try {
            $ok_v = wiki_tax_field_check((string)$k, $f['multiple'] ? $items : ($items[0] ?? ''));
        } catch (WikiTaxonomyException $e) {
            return [$new_raw, $e->getMessage()];
        }
        $canon = is_array($ok_v) ? $ok_v : [$ok_v];
        if ($canon !== $items) $updates[(string)$k] = is_array($v) || $f['multiple'] ? $canon : $ok_v;
    }
    return [$updates ? wiki_fm_set($new_raw, $updates) : $new_raw, ''];
}

// Parses the compact Advanced Search query language into structured parts:
//   src:<slug>     route to a registered MCP source (0 or 1; first wins)
//   tag:<value>    exact tag filter (0+, AND); tag:"multi word" is honored
//   updated:<Nd>   pages updated within N days (also accepts updated:N or updated:<Nd)
//   <bare words>   free-text search
// Returns ['src'=>?string, 'tags'=>string[], 'days'=>int, 'text'=>string,
//          'filters'=>bool (any tag/date filter present)].
function parse_search_query(string $raw): array {
    $src = null; $tags = []; $days = 0; $words = []; $fields = [];
    $bound = function_exists('wiki_tax_fields') ? wiki_tax_fields() : [];
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
        } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_.\-]*):(.+)$/', $tok, $mm) && isset($bound[strtolower($mm[1])])) {
            // A front-matter field bound to the taxonomy: department:Finance.
            $v = trim($mm[2], '"');
            if ($v !== '') $fields[$bound[strtolower($mm[1])]['field']] = $v;
        } else {
            $words[] = $tok;
        }
    }
    return [
        'src'     => $src,
        'tags'    => $tags,
        'days'    => $days,
        'fields'  => $fields,
        'text'    => implode(' ', $words),
        'filters' => (!empty($tags) || $days > 0 || !empty($fields)),
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

const WIKI_AI_WRITE_TOOLS = ['wiki_write_page', 'wiki_write_json', 'wiki_update_list_item', 'wiki_add_list_item', 'wiki_create_list', 'wiki_add_tags', 'wiki_set_tags', 'wiki_mention_users', 'wiki_post_chat', 'wiki_rename_page', 'wiki_remember', 'wiki_forget'];

// Longer than a person's 2000-character chat limit: a review of a page is the point of
// wiki_post_chat, and that is often longer than a chat line. Still a ceiling, since every
// message is re-read into each later prompt's transcript.
const WIKI_AI_CHAT_MAX_CHARS = 20000;

/**
 * What an AI tool is about to touch, for the audit log. Same shape as the REST map in
 * audit.php, but keyed by tool name and resolved from the tool's own arguments.
 */
const WIKI_AI_AUDIT_TOOLS = [
    'wiki_write_page'    => ['page',       'path'],
    'wiki_write_json'    => ['page',       'path'],
    'wiki_update_list_item' => ['page',    'path'],
    'wiki_add_list_item' => ['page',       'path'],
    'wiki_create_list'   => ['page',       'path'],
    'wiki_add_tags'      => ['tags',       'path'],
    'wiki_set_tags'      => ['tags',       'path'],
    'wiki_rename_page'   => ['page',       'path'],
    // A memory is a page write like any other, and an AI writing into somebody's wiki
    // unattended is exactly what the log is for. The param is a title rather than a
    // path, so _wiki_ai_audit() resolves it to memory/<slug>.md below.
    'wiki_remember'      => ['page',       'title'],
    'wiki_forget'        => ['page',       'title'],
    // Only when it appends to a .md page: a mention posted into a .chat is a chat
    // message, and those are out of scope wherever they come from — see the note on
    // WIKI_AUDIT_ACTIONS in audit.php. Gated on the target's type below rather than
    // dropped here, so the page case stays in scope like any other page write.
    'wiki_mention_users' => ['page',       'target'],
    // Only when it creates a page's chat, which is the create_chat action's entry in
    // audit.php made by an AI. Posting into a thread is a chat message, out of scope.
    'wiki_post_chat'     => ['page',       'target'],
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

/**
 * Why a memory tool is unavailable, or '' when it is fine.
 *
 * Both switches are named separately in the message because "memory is off" with two
 * places to look is a support question; this way the model can tell the user which one.
 */
function _wiki_memory_guard(string $tool, array $ai_user, string $space_dir): string {
    if (!wiki_space_dir_memory($space_dir)) {
        return 'Error: the space "' . basename(rtrim($space_dir, '/'))
             . '" does not keep AI memories. An administrator can turn that on in Space settings.';
    }
    if (!wiki_ai_memory_enabled($ai_user['ai_config'] ?? [])) {
        return 'Error: learning is switched off for this AI user. An administrator can turn it on '
             . 'in Admin → AI Users.';
    }
    if ($tool !== 'wiki_recall' && ($ai_user['role'] ?? 'reader') === 'reader') {
        return 'Error: this AI user has read-only (reader) role and cannot write memories.';
    }
    return '';
}

/** A memory lands in git like any other page write. */
function _wiki_memory_commit(string $space_dir, string $rel, array $ai_user, string $verb): void {
    git_auto_commit(rtrim($space_dir, '/') . '/' . $rel,
                    $ai_user['name'] ?? 'AI',
                    !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost',
                    $verb . ' ' . basename($rel), $space_dir);
}

function execute_ai_tool($tool_name, $tool_input, $ai_user, $indexer, $space_dir) {
    // Whatever this tool writes, the AI user wrote it — for the workflows' "not AI edits"
    // filter. Set here because this is where api.php (inline chat replies, which would
    // otherwise be credited to the person whose message started them), mcp.php and the
    // cron runner converge. Not restored: every later write in the process is the AI's,
    // and the workflow runner takes the actor back after each action.
    if (function_exists('wiki_workflow_set_actor')) {
        wiki_workflow_set_actor(['uid' => $ai_user['uid'] ?? null, 'name' => $ai_user['name'] ?? null, 'is_ai' => true]);
    }
    // Space isolation, for the same reason the read-only guard is here: this is the one
    // point api.php, mcp.php and run_ai_agent_jobs.php share.
    //
    // Each caller gates the ?space= *parameter* against the actor's allowlist, which is
    // only half of it. Every tool builds its target as $space_dir . '/' . path with just
    // '..' stripped, so with no ?space= the base is PAGES_DIR and a path of
    // "Bravo/secret.md" reaches Bravo without the parameter ever being set. Ask which
    // Space the resolved path lands in instead.
    // The taxonomy file is not a page, whoever asks — see taxonomy.php.
    foreach (['path', 'new_path', 'target'] as $arg) {
        $rel = ltrim(str_replace('..', '', (string)($tool_input[$arg] ?? '')), '/');
        if ($rel !== '' && wiki_tax_is_reserved(rtrim($space_dir, '/') . '/' . $rel)) {
            return 'Error: "' . WIKI_TAX_FILE . '" is the wiki\'s taxonomy, not a page. Use wiki_get_taxonomy to read it.';
        }
    }
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
    // Skills are written by administrators, never by an AI — whatever its role, and on
    // every route at once, since this is where chat, MCP and the cron runner converge.
    // On the resolved path, like the Space check above: with no ?space= the base is
    // PAGES_DIR and "Alpha/skills/…" reaches Alpha's skills.
    if (in_array($tool_name, WIKI_AI_WRITE_TOOLS, true)) {
        foreach (['path', 'new_path', 'target'] as $arg) {
            $rel = ltrim(str_replace('..', '', (string)($tool_input[$arg] ?? '')), '/');
            if ($rel !== '' && wiki_skill_guarded_abs(rtrim($space_dir, '/') . '/' . $rel)) {
                $denied = 'Error: "' . WIKI_SKILLS_DIR . '/" holds this space\'s skills, which only an administrator can change. Nothing was written.';
                _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $denied, true);
                return $denied;
            }
        }
    }
    // A read-only Space is read-only for agents too. api.php's guard already covers
    // chat @mentions, but mcp.php and run_ai_agent_jobs.php call this function
    // directly — this is the one point all three routes share.
    if (in_array($tool_name, WIKI_AI_WRITE_TOOLS, true) && wiki_space_dir_is_readonly($space_dir)) {
        $denied = 'Error: the space "' . basename(rtrim($space_dir, '/')) . '" is read-only; nothing in it can be changed.';
        _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $denied,
                       $tool_name === 'wiki_post_chat' ? _wiki_post_chat_existed($tool_input, $space_dir)
                           : is_file(rtrim($space_dir, '/') . '/' . ltrim(str_replace('..', '', (string)($tool_input['path'] ?? '')), '/')));
        return $denied;
    }
    // A generated page's body belongs to its template (page_generators.php) — the same
    // refusal api.php's bootstrap guard gives a person, here for the tools that rewrite,
    // append to or rename one page. Said in full, so the model can tell the user what to
    // change instead.
    $gen_key = ['wiki_write_page' => 'path', 'wiki_rename_page' => 'path', 'wiki_mention_users' => 'target'][$tool_name] ?? null;
    if ($gen_key !== null && function_exists('wiki_gen_write_refused')) {
        $gen_rel = ltrim(str_replace('..', '', (string)($tool_input[$gen_key] ?? '')), '/');
        $gen_hit = $gen_rel !== '' ? wiki_gen_write_refused(rtrim($space_dir, '/') . '/' . $gen_rel) : null;
        if ($gen_hit !== null) {
            $denied = 'Error: ' . wiki_gen_locked_message($gen_hit) . ' Nothing was written.';
            _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $denied, true);
            return $denied;
        }
    }
    // A files library holds uploaded files, not wiki content — the same rule api.php
    // applies to its create/save/move actions, here for the tools that write a page.
    $lib_key = ['wiki_write_page' => 'path', 'wiki_write_json' => 'path', 'wiki_rename_page' => 'new_path'][$tool_name] ?? null;
    if ($lib_key !== null) {
        $lib_rel = ltrim(str_replace('..', '', (string)($tool_input[$lib_key] ?? '')), '/');
        if ($lib_rel !== '' && wiki_inside_files_library(rtrim($space_dir, '/') . '/' . $lib_rel)) {
            $denied = 'Error: "' . $lib_rel . '" is inside a files library, which holds uploaded files only — wiki pages cannot be written there.';
            _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $denied, false);
            return $denied;
        }
    }
    // Whether the page already existed has to be sampled BEFORE the write, or a create
    // is indistinguishable from an update by the time the result comes back.
    $existed = null;
    if (in_array($tool_name, ['wiki_write_page', 'wiki_write_json'], true)) {
        $existed = is_file(rtrim($space_dir, '/') . '/'
                 . ltrim(str_replace('..', '', (string)($tool_input['path'] ?? '')), '/'));
    }
    if ($tool_name === 'wiki_post_chat') $existed = _wiki_post_chat_existed($tool_input, $space_dir);
    $result = _execute_ai_tool_dispatch($tool_name, $tool_input, $ai_user, $indexer, $space_dir);
    wiki_trace_add(['type' => 'tool', 'name' => $tool_name, 'input' => $tool_input, 'output' => $result]);
    _wiki_ai_audit($tool_name, $tool_input, $ai_user, $indexer, $space_dir, $result, $existed);
    return $result;
}

/**
 * For wiki_post_chat's audit entry: whether the thread it posts into already existed.
 * Only a page chat it would *create* is logged, so a .chat target counts as existing.
 */
function _wiki_post_chat_existed(array $input, string $space_dir): bool {
    $side = wiki_page_chat_sidecar(ltrim(str_replace('..', '', (string)($input['target'] ?? '')), '/'));
    return $side === null || is_file(rtrim($space_dir, '/') . '/' . $side);
}

// Tools report failure by returning a string that starts with "Error:" — the existing
// convention, and the only outcome signal there is.
function _wiki_ai_audit(string $tool, array $input, $ai_user, $indexer, string $space_dir,
                        $result, ?bool $existed = null): void {
    if (!wiki_audit_enabled() || !isset(WIKI_AI_AUDIT_TOOLS[$tool])) return;
    [$category, $param] = WIKI_AI_AUDIT_TOOLS[$tool];
    $rel = ltrim(str_replace('..', '', (string)($input[$param] ?? '')), '/');
    // The memory tools take a title, not a path; the page they write is derived from it.
    if ($tool === 'wiki_remember' || $tool === 'wiki_forget') {
        $rel = WIKI_MEMORY_DIR . '/' . wiki_memory_slug($rel) . '.md';
    }
    if ($tool === 'wiki_mention_users' && strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'chat') return;
    if ($tool === 'wiki_post_chat') {
        if ($existed !== false) return;
        $rel = (string)wiki_page_chat_sidecar($rel);
    }

    $verb = 'update';
    if ($tool === 'wiki_rename_page')                       $verb = 'rename';
    elseif ($tool === 'wiki_add_tags' || $tool === 'wiki_set_tags') $verb = 'tag';
    elseif (in_array($tool, ['wiki_write_page', 'wiki_write_json'], true)) {
        $verb = $existed ? 'update' : 'create';
    }
    elseif ($tool === 'wiki_create_list') $verb = 'create';
    elseif ($tool === 'wiki_post_chat') $verb = 'create';

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
            $sp_fields = $tool_input['fields'] ?? [];
            if (!is_array($sp_fields)) $sp_fields = [];
            if ($query === '' && $days <= 0 && !$tags && !$sp_fields) return 'Error: provide "query", "updated_within_days", "tags", "fields", or a combination.';
            return json_encode(wiki_search_pages($query, $indexer, $space_dir, $days, $tags, $sp_fields));

        case 'wiki_read_page':
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $ext = pathinfo($rel, PATHINFO_EXTENSION);
            if (!in_array($ext, ['md', 'list', 'chat', 'json'], true)) return 'Error: only .md, .list, .chat and .json files can be read.';
            $abs = rtrim($space_dir, '/') . '/' . $rel;
            if (!file_exists($abs) || !is_file($abs)) return 'Error: page not found.';
            $raw_read = (string)file_get_contents($abs);
            // A page's front matter is withheld unless an admin has enabled it. It is
            // author-controlled text that the model would read as guidance — which is the
            // point when it carries "keep this table sorted by date", and prompt injection
            // when it does not. Off by default, so turning it on is a deliberate act.
            if ($ext === 'md' && !wiki_fm_expose_to_ai()) $raw_read = wiki_fm_body($raw_read);
            return $raw_read;

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
            $is_new  = !file_exists($abs);
            // The model is shown the body without front matter (unless exposed), so it
            // returns one without it. Re-attach, or an AI edit deletes a page's metadata.
            $fm_prev = $is_new ? '' : (string)@file_get_contents($abs);
            $content = wiki_fm_preserve($abs, $content);
            // Fields bound to the taxonomy, where the AI changed them: checked, and
            // respelled as the tree spells them. A value the page already had is left
            // alone — it may have come from an import, which is somebody else's.
            [$content, $fv_err] = wiki_ai_check_bound_fields($fm_prev, $content);
            if ($fv_err !== '') return 'Error: ' . $fv_err . ' Nothing was written.';
            // Only now: a refused write must not leave an empty folder behind.
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fm_changed = $is_new || wiki_fm_body($fm_prev) !== wiki_fm_body($content);
            if (file_put_contents($abs, $content) === false) return 'Error: could not write file.';
            // Index the body only, matching the save path.
            wiki_ai_fts_upsert($space_dir, $rel, wiki_fm_search_text($content));
            $ai_git_name  = $ai_user['name'] ?? 'AI';
            $ai_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            if ($is_new) $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            else         $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            // An AI user is a writer like any other, and `updatedBy: <ai name>` is useful
            // provenance rather than noise — it answers "who changed this page" on the one
            // kind of edit nobody watched happen. Stamped before the commit, so the stamp
            // is part of the same revision as the edit.
            if (wiki_space_dir_fm_autostamp($space_dir) === 'on') {
                $fm_entry = $indexer->getAllPages()[$indexer->getId($rel)] ?? null;
                wiki_fm_autostamp_file($abs, $fm_entry, $ai_user['name'] ?? null, $fm_changed);
            }
            git_auto_commit($abs, $ai_git_name, $ai_git_email,
                            ($is_new ? 'Create ' : 'Update ') . basename($rel), $space_dir);
            if ($is_new) return "Page created: {$rel}";
            return "Page updated: {$rel}";

        case 'wiki_update_list_item':
            return wiki_ai_update_list_item($tool_input, $ai_user, $indexer, $space_dir);

        case 'wiki_add_list_item':
            return wiki_ai_add_list_item($tool_input, $ai_user, $indexer, $space_dir);

        case 'wiki_create_list':
            return wiki_ai_create_list($tool_input, $ai_user, $indexer, $space_dir);

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

        case 'wiki_post_chat': {
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot post in chats.';
            $pc_text = trim((string)($tool_input['message'] ?? ''));
            if ($pc_text === '') return 'Error: "message" is required.';
            if (mb_strlen($pc_text) > WIKI_AI_CHAT_MAX_CHARS) {
                return 'Error: the message is longer than ' . WIKI_AI_CHAT_MAX_CHARS . ' characters. Shorten it, or write a page and link to it.';
            }
            $pc_rel = ltrim(str_replace('..', '', (string)($tool_input['target'] ?? '')), '/');
            if ($pc_rel === '') return 'Error: "target" is required — a .md page or a .chat thread.';
            $pc_ext = strtolower(pathinfo($pc_rel, PATHINFO_EXTENSION));
            if (!in_array($pc_ext, ['md', 'chat'], true)) return 'Error: target must be a .md page (for its page chat) or a .chat thread.';
            // A memory is the AI's own note, not a page people read; a conversation beside
            // it would be one nobody finds.
            if (wiki_is_memory_path($pc_rel)) return 'Error: memory pages have no chat.';
            try {
                if ($pc_ext === 'md') {
                    $pc_thread = wiki_page_chat_ensure($space_dir, $pc_rel, $indexer,
                                                       ['uid' => $ai_user['uid'] ?? null, 'name' => $ai_user['name'] ?? 'AI']);
                } else {
                    $pc_abs = rtrim($space_dir, '/') . '/' . $pc_rel;
                    if (!is_file($pc_abs)) {
                        return 'Error: chat thread not found: ' . $pc_rel
                             . '. To post in a page\'s chat, pass the page itself (the .md path) and the chat is created for you.';
                    }
                    $pc_thread = ['rel' => $pc_rel, 'abs' => $pc_abs, 'created' => false];
                }
                wiki_chat_append($pc_thread['abs'], $pc_thread['rel'], $pc_text,
                                 (int)($ai_user['uid'] ?? 0), (string)($ai_user['name'] ?? 'AI'));
            } catch (\Throwable $e) {
                return 'Error: ' . $e->getMessage();
            }
            return 'Posted in ' . $pc_thread['rel'] . ($pc_thread['created'] ? ' (the page had no chat yet, so it was created).' : '.');
        }

        case 'wiki_read_skill': {
            // Both switches again: an MCP client or a retried call can name a tool that
            // was never offered.
            if (!wiki_space_dir_skills($space_dir)) {
                return 'Error: the space "' . basename(rtrim($space_dir, '/'))
                     . '" does not offer AI skills. An administrator can turn that on in Space settings.';
            }
            if (!wiki_ai_skills_enabled($ai_user['ai_config'] ?? [])) {
                return 'Error: skills are switched off for this AI user. An administrator can turn them on in Admin → AI Users.';
            }
            $sk = wiki_skill_read($space_dir, (string)($tool_input['name'] ?? ''), (string)($tool_input['file'] ?? ''));
            return $sk['ok'] ? $sk['text'] : 'Error: ' . $sk['error'] . '.';
        }

        case 'wiki_remember':
        case 'wiki_recall':
        case 'wiki_forget': {
            // Both switches, checked here rather than only at prompt-assembly time: the
            // tools are not offered when memory is off, but MCP clients and a retried
            // tool call can name a tool that was never advertised.
            $mem_err = _wiki_memory_guard($tool_name, $ai_user, $space_dir);
            if ($mem_err !== '') return $mem_err;

            if ($tool_name === 'wiki_recall') {
                $hits = wiki_memory_search($space_dir, $indexer,
                                           (string)($tool_input['query'] ?? ''),
                                           (int)($tool_input['limit'] ?? 10));
                if (!$hits) return 'No memories match that. Your memory listing is in the system prompt.';
                $out = [];
                foreach ($hits as $h) {
                    $out[] = ['title' => $h['title'], 'tags' => $h['tags'], 'memory' => $h['text']];
                }
                return json_encode($out, JSON_UNESCAPED_SLASHES);
            }

            $mem_title = trim((string)($tool_input['title'] ?? ''));
            if ($mem_title === '') return 'Error: "title" is required.';

            if ($tool_name === 'wiki_forget') {
                $res = wiki_memory_forget($space_dir, $indexer, $mem_title);
                if (!$res['ok']) return 'Error: ' . $res['error'] . '.';
                _wiki_memory_commit($space_dir, $res['path'], $ai_user, 'Forget');
                return 'Forgotten: ' . $mem_title;
            }

            $res = wiki_memory_write($space_dir, $indexer, $mem_title,
                                     (string)($tool_input['fact'] ?? ''),
                                     (array)($tool_input['tags'] ?? []),
                                     ['uid' => $ai_user['uid'] ?? null, 'name' => $ai_user['name'] ?? 'AI']);
            if (!$res['ok']) return 'Error: ' . $res['error'] . '.';
            wiki_ai_fts_upsert($space_dir, $res['path'], (string)($tool_input['fact'] ?? ''));
            _wiki_memory_commit($space_dir, $res['path'], $ai_user, $res['created'] ? 'Remember' : 'Update memory');
            return ($res['created'] ? 'Remembered: ' : 'Updated memory: ') . $mem_title;
        }

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
            // The page's chat thread comes too, so its destination must be free as well —
            // refused before anything moves rather than leaving the conversation orphaned
            // under the old name.
            if (wiki_page_chat_blocks_move($rn_abs_old, $rn_abs_new)) {
                return 'Error: a chat thread already exists at ' . wiki_page_chat_sidecar($rn_new) . '.';
            }
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
            // And the page's chat thread, which is an indexed page of its own rather than
            // a plain sidecar — id, FTS row and any queued job addressing it. page_chat.php
            // is shared with api.php's move so the two renames cannot drift.
            $rn_chat = wiki_page_chat_move($rn_abs_old, $rn_abs_new, $rn_old, $rn_new,
                                           $indexer, basename(rtrim($space_dir, '/')));

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
                $rn_raw = (string)file_get_contents($rn_file);
                // A generated page's links come from its template; see retarget_wikilinks.
                if (function_exists('wiki_gen_page_generated') && wiki_gen_page_generated($rn_file) !== null) continue;
                // Retarget the body and put the block back untouched: the wiki never
                // rewrites somebody's front matter, not even to fix a link inside it.
                $rn_fm  = wiki_fm_split($rn_raw);
                [$rn_out, $rn_n] = wikilink_retarget($rn_fm['body'], $rn_old, $rn_new);
                if ($rn_n === 0) continue;
                $rn_out = wiki_fm_join($rn_fm['block'], $rn_out);
                $rn_links += $rn_n;
                if (!empty($tool_input['retarget_links']) && $rn_out !== $rn_raw
                    && file_put_contents($rn_file, $rn_out) !== false) {
                    $rn_fixed += $rn_n;
                    $indexer->updateModified($rn_rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                }
            }

            $rn_git_name  = $ai_user['name'] ?? 'AI';
            $rn_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            git_move_commit($rn_abs_old, $rn_abs_new, $rn_git_name, $rn_git_email, $space_dir,
                            $rn_chat ? [[wiki_page_chat_sidecar($rn_abs_old), wiki_page_chat_sidecar($rn_abs_new)]] : []);

            $rn_msg = 'Renamed ' . $rn_old . ' to ' . $rn_new . '. The page keeps its id, tags and attachments.';
            // Said out loud because the model may have been told to look at the thread next,
            // and its path is no longer the one it was given.
            if ($rn_chat) $rn_msg .= ' Its chat thread moved with it, to ' . wiki_page_chat_sidecar($rn_new) . '.';
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

        case 'wiki_content_health':
            // The space gate above has already run on $space_dir; links into other Spaces are
            // checked against the same allowlist, so the report says nothing about a Space this
            // actor cannot read.
            $ch = wiki_content_health($space_dir, $indexer,
                actor_spaces_filter($ai_user['role'] ?? 'reader', $ai_user),
                ['stale_days' => (int)($tool_input['stale_days'] ?? WIKI_HEALTH_STALE_DAYS)]);
            return wiki_content_health_text($ch);

        case 'wiki_get_taxonomy':
            $tx_mode = wiki_tax_mode();
            $tx_lines = ['Mode: ' . $tx_mode . ' — ' . [
                'free'       => 'any tag may be used; a new one is added to the taxonomy.',
                'suggest'    => 'prefer existing terms; a new tag is added to the taxonomy.',
                'controlled' => 'only the terms below may be used as tags.',
            ][$tx_mode]];
            $tx_walk = function (array $nodes, string $parent, int $depth) use (&$tx_walk, &$tx_lines) {
                foreach ($nodes as $n) {
                    if (count($tx_lines) > 3000) return;
                    $path = $parent === '' ? $n['name'] : $parent . '/' . $n['name'];
                    $tx_lines[] = str_repeat('  ', $depth) . $path
                        . (!empty($n['heading']) ? ' (folder, not a tag)' : '')
                        . (!empty($n['description']) ? ' — ' . $n['description'] : '');
                    if (!empty($n['children'])) $tx_walk($n['children'], $path, $depth + 1);
                }
            };
            $tx_walk(wiki_tax_nodes(), '', 0);
            if (count($tx_lines) === 1) $tx_lines[] = '(The taxonomy is empty.)';
            // Front-matter fields bound to a branch: what wiki_write_page's block and
            // wiki_search_pages' "fields" may use.
            $tx_fields = wiki_tax_fields();
            if ($tx_fields) {
                $tx_lines[] = '';
                $tx_lines[] = 'Front-matter fields (write the plain value, not the path):';
                foreach ($tx_fields as $f) {
                    $tx_lines[] = '  ' . $f['field'] . ($f['multiple'] ? ' (one or more of)' : ' (one of)') . ': '
                        . (implode(', ', array_column($f['values'], 'value')) ?: '(no values yet)');
                }
            }
            return implode("\n", $tx_lines);

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
            try { $indexer->updateTags($page_id, $merged_tags); }
            catch (WikiTaxonomyException $e) { return 'Error: ' . $e->getMessage() . ' Call wiki_get_taxonomy for the terms.'; }
            return "Tags on {$rel}: " . implode(', ', $indexer->getTags($page_id));

        case 'wiki_set_tags':
            if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot set tags.';
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $tags_input = $tool_input['tags'] ?? [];
            if (!is_array($tags_input)) return 'Error: tags must be an array.';
            $page_id = $indexer->getId($rel);
            if ($page_id === null) return 'Error: page not found in index — make sure the path matches exactly what wiki_list_pages returns.';
            try { $indexer->updateTags($page_id, $tags_input); }
            catch (WikiTaxonomyException $e) { return 'Error: ' . $e->getMessage() . ' Call wiki_get_taxonomy for the terms.'; }
            $now_tags = $indexer->getTags($page_id);
            return $now_tags ? "Tags set on {$rel}: " . implode(', ', $now_tags) : "Tags cleared on {$rel}.";

        default:
            return 'Error: unknown tool.';
    }
}

/** A list's column named as the list does ("Status", case-insensitive) or by its id. */
function _wiki_ai_list_column(array $list, string $name): ?array {
    foreach ($list['columns'] as $c) if (($c['id'] ?? null) === $name) return $c;
    foreach ($list['columns'] as $c) if (mb_strtolower(trim((string)($c['name'] ?? ''))) === mb_strtolower(trim($name))) return $c;
    return null;
}

function _wiki_ai_list_no_field(array $list, string $name): string {
    $names = implode(', ', array_map(fn($c) => '"' . ($c['name'] ?? $c['id']) . '"', $list['columns']));
    return "Error: the list has no field \"{$name}\". Its fields are: {$names}.";
}

/**
 * One value an AI gives for a list field, converted the way the item form would store it —
 * shared by wiki_update_list_item and wiki_add_list_item, so the two cannot disagree about
 * what a field accepts. A model sends "12" for a number and "yes" for a boolean.
 *
 * @param array|null $targets the lists this one looks up, read on first need and cached
 * @return array ['value' => mixed] — null means "no value" — or ['error' => message]
 */
function _wiki_ai_list_value(array $col, $value, array $list, string $space_dir, ?array &$targets, bool $is_title): array {
    $cid   = (string)$col['id'];
    $cname = (string)($col['name'] ?? $cid);
    $type  = (string)($col['type'] ?? 'text_single');
    $empty = $value === null || $value === '' || $value === [];
    if (in_array($type, ['autoincrement', 'lookup_field', 'object'], true)) {
        $why = ['autoincrement' => 'is numbered by the list', 'lookup_field' => 'is shown from the item its lookup points at — change that lookup, or that item',
                'object' => 'is a group of fields, which only the item form can change'][$type];
        return ['error' => "\"{$cname}\" {$why}."];
    }
    if ($empty && $is_title) return ['error' => "\"{$cname}\" is the list's Title, which an item must have."];
    if ($empty) return ['value' => null];
    if (in_array($type, ['text_single', 'text_multi'], true)) {
        if (!is_scalar($value)) return ['error' => "\"{$cname}\" takes text."];
        $new = (string)$value;
        if ($type === 'text_single') $new = trim(preg_replace('/\s*\R\s*/u', ' ', $new) ?? $new);
        if ($is_title && $new === '') return ['error' => "\"{$cname}\" is the list's Title, which an item must have."];
        return ['value' => $new];
    }
    if ($type === 'date') {
        $d = is_string($value) ? DateTime::createFromFormat('!Y-m-d', trim($value)) : false;
        if (!$d || $d->format('Y-m-d') !== trim($value)) return ['error' => "\"{$cname}\" takes a date as YYYY-MM-DD."];
        return ['value' => trim($value)];
    }
    if ($type === 'choice') {
        // A choice bound to a taxonomy folder takes the names of the items in it
        // (taxonomy_lists.php); with `multiple`, several of them.
        $bound = is_string($col['taxonomy'] ?? null) && $col['taxonomy'] !== '';
        $opts = $bound ? array_column(wiki_tax_branch_values($col['taxonomy']) ?? [], 'value') : array_map('strval', $col['options'] ?? []);
        $given = is_array($value) ? $value : [$value];
        if (count($given) > 1 && empty($col['multiple'])) return ['error' => "\"{$cname}\" takes one value."];
        $picked = [];
        foreach ($given as $g) {
            $hit = null;
            foreach ($opts as $o) if (is_scalar($g) && mb_strtolower($o) === mb_strtolower(trim((string)$g))) { $hit = $o; break; }
            if ($hit === null) {
                return ['error' => "\"{$cname}\" " . ($bound ? "takes items of the taxonomy folder \"{$col['taxonomy']}\": " : 'is one of: ')
                    . implode(', ', array_map(fn($o) => "\"{$o}\"", $opts)) . '.'];
            }
            if (!in_array($hit, $picked, true)) $picked[] = $hit;
        }
        return ['value' => !empty($col['multiple']) ? $picked : $picked[0]];
    }
    if ($type === 'number') {
        if (is_string($value)) $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (!is_numeric($value)) return ['error' => "\"{$cname}\" takes a number."];
        $new = round((float)$value, max(0, min(6, (int)($col['decimals'] ?? 0))));
        if ((int)($col['decimals'] ?? 0) === 0) $new = (int)$new;
        return ['value' => $new];
    }
    if ($type === 'boolean') {
        $b = is_bool($value) ? $value : (is_string($value) || is_int($value)
            ? (in_array(mb_strtolower(trim((string)$value)), ['1', 'true', 'yes', 'ja', 'y'], true) ? true
              : (in_array(mb_strtolower(trim((string)$value)), ['0', 'false', 'no', 'nej', 'n'], true) ? false : null)) : null);
        if ($b === null) return ['error' => "\"{$cname}\" is Yes/No: give true or false."];
        return ['value' => $b];
    }
    if ($type === 'lookup') {
        $ids = is_array($value) ? $value : [$value];
        $clean = [];
        foreach ($ids as $v) {
            if (!is_numeric($v) || (int)$v != $v) return ['error' => "\"{$cname}\" takes item ids (numbers) from the list it looks up."];
            $clean[] = (int)$v;
        }
        $clean = array_values(array_unique($clean));
        if (empty($col['lookup']['multiple']) && count($clean) > 1) return ['error' => "\"{$cname}\" takes one item."];
        $targets ??= wiki_list_lookup_targets($list, $space_dir);
        $target = $targets[(string)($col['lookup']['list'] ?? '')] ?? null;
        if ($target === null) return ['error' => "the list \"{$cname}\" looks up cannot be read."];
        foreach ($clean as $i) {
            if (!_wiki_list_find_item($target, $i)) return ['error' => "the list \"{$cname}\" looks up has no item {$i}."];
        }
        return ['value' => $clean];
    }
    return ['error' => "\"{$cname}\" cannot be set here."];
}

/** The field types wiki_create_list takes, by the name a model is given => the stored type. */
const WIKI_AI_LIST_TYPES = [
    'text' => 'text_single', 'multiline' => 'text_multi', 'date' => 'date', 'choice' => 'choice',
    'number' => 'number', 'yesno' => 'boolean', 'lookup' => 'lookup',
];

/**
 * wiki_create_list: a new, empty .list made by an AI. The structure the person's
 * *New → List* writes (api.php `create_list`) — `git_commit: false`, the hidden ItemId first,
 * `nextItemId` 1 — with the fields the AI names, cleaned the way List properties cleans them
 * (modules/list/props.js `clean()`), so the list opens in the editor as if made there.
 *
 * - **Never replaces anything**: the file is opened with mode `x`, which fails if the path
 *   exists — an atomic check-and-create, so two creates racing for one path cannot both win.
 * - The Title is a single-line text field (`role: 'title'`), as the form requires.
 * - A lookup names its target by path or page id; it must be a readable .list **in this
 *   Space** (lookups are same-Space, see lists.md), and shows the target's Title.
 * - Groups of fields and "also show" fields are not offered — the List properties dialog
 *   does those. Items are added afterwards with wiki_add_list_item.
 */
function wiki_ai_create_list(array $input, $ai_user, $indexer, string $space_dir): string {
    if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot create lists.';
    $rel = ltrim(str_replace('..', '', (string)($input['path'] ?? '')), '/');
    if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'list' || pathinfo($rel, PATHINFO_FILENAME) === '') {
        return 'Error: path must end in .list, e.g. Registers/Risks.list.';
    }
    if (wiki_is_memory_path($rel)) return 'Error: a list cannot be created in the memory folder.';
    $abs = rtrim($space_dir, '/') . '/' . $rel;
    if (file_exists($abs)) return "Error: \"{$rel}\" already exists. wiki_create_list never replaces a list — use wiki_add_list_item to add to it, or choose another path.";
    $fields = $input['fields'] ?? null;
    if (is_string($fields)) $fields = json_decode($fields, true);
    if (!is_array($fields) || !$fields || !array_is_list($fields)) return 'Error: fields must be a non-empty array of {name, type} objects.';

    $types = implode(', ', array_map(fn($t) => "\"{$t}\"", array_keys(WIKI_AI_LIST_TYPES)));
    $columns = [['id' => 'col_1', 'name' => 'ItemId', 'type' => 'autoincrement', 'showInListView' => true]];
    $seen = ['itemid' => true];
    $n = 1;
    foreach ($fields as $f) {
        if (!is_array($f)) return 'Error: each field must be an object with "name" and "type". Nothing was created.';
        $name = trim(preg_replace('/\s+/u', ' ', (string)($f['name'] ?? '')) ?? '');
        if ($name === '') return 'Error: every field needs a name. Nothing was created.';
        $key = mb_strtolower($name);
        if (isset($seen[$key])) {
            return $key === 'itemid' ? 'Error: "ItemId" is added by the list itself; give your field another name. Nothing was created.'
                                     : "Error: two fields are named \"{$name}\". Nothing was created.";
        }
        $seen[$key] = true;
        $given = mb_strtolower(trim((string)($f['type'] ?? '')));
        $type = WIKI_AI_LIST_TYPES[$given] ?? (in_array($given, WIKI_AI_LIST_TYPES, true) ? $given : null);
        if ($type === null) return "Error: field \"{$name}\" has type \"{$given}\"; the types are {$types}. Nothing was created.";
        $col = ['id' => 'col_' . (++$n), 'name' => $name, 'type' => $type,
                'description' => trim((string)($f['description'] ?? '')), 'showInListView' => true];

        if ($type === 'choice') {
            $tax = trim((string)($f['taxonomy'] ?? ''));
            if ($tax !== '') {
                if (wiki_tax_branch_values($tax) === null) {
                    return "Error: field \"{$name}\": \"{$tax}\" is not a folder of the taxonomy (see wiki_get_taxonomy). Nothing was created.";
                }
                $col['taxonomy'] = wiki_tax_normalize($tax);
            } else {
                $opts = [];
                foreach ((array)($f['options'] ?? []) as $o) {
                    if (!is_scalar($o)) continue;
                    $o = trim((string)$o);
                    if ($o !== '' && !in_array(mb_strtolower($o), array_map('mb_strtolower', $opts), true)) $opts[] = $o;
                }
                if (!$opts) return "Error: field \"{$name}\" is a choice: give its \"options\", or a \"taxonomy\" folder. Nothing was created.";
                $col['options'] = $opts;
            }
            if (!empty($f['multiple'])) $col['multiple'] = true;
        } elseif ($type === 'number') {
            $col['decimals'] = max(0, min(6, (int)($f['decimals'] ?? 0)));
            $unit = mb_substr(trim((string)($f['unit'] ?? '')), 0, 12);
            if ($unit !== '') $col['unit'] = $unit;
        } elseif ($type === 'lookup') {
            $ref = trim((string)($f['list'] ?? ''));
            if ($ref === '') return "Error: field \"{$name}\" is a lookup: give \"list\", the path or page id of a list in this space. Nothing was created.";
            $tpath = ctype_digit($ref) ? $indexer->getPath((int)$ref) : ltrim(str_replace('..', '', $ref), '/');
            $tid   = $tpath ? $indexer->getId($tpath) : null;
            $tlist = ($tpath && $tid !== null && strtolower(pathinfo($tpath, PATHINFO_EXTENSION)) === 'list')
                ? json_decode((string)@file_get_contents(rtrim($space_dir, '/') . '/' . $tpath), true) : null;
            if (!is_array($tlist) || !is_array($tlist['columns'] ?? null)) {
                return "Error: field \"{$name}\" looks up \"{$ref}\", which is not a list in this space. A lookup can only point at a list in the same space. Nothing was created.";
            }
            $col['lookup'] = ['list' => (int)$tid, 'field' => (string)(wiki_list_title_field($tlist) ?? 'col_2'), 'multiple' => !empty($f['multiple'])];
        }
        $columns[] = $col;
    }

    // The Title: named, or the first single-line text field.
    $want = trim((string)($input['title'] ?? ''));
    $at = null;
    foreach ($columns as $i => $c) {
        if ($c['type'] === 'autoincrement') continue;
        if ($want !== '' ? mb_strtolower($c['name']) === mb_strtolower($want) : $c['type'] === 'text_single') { $at = $i; break; }
    }
    if ($at === null) {
        return $want !== '' ? "Error: there is no field \"{$want}\" to be the Title. Nothing was created."
                            : 'Error: the list needs a Title — a "text" field naming each item. Add one. Nothing was created.';
    }
    if ($columns[$at]['type'] !== 'text_single') return "Error: the Title \"{$columns[$at]['name']}\" must be a \"text\" field. Nothing was created.";
    $columns[$at]['role'] = 'title';

    $raw = json_encode(['git_commit' => false, 'columns' => $columns, 'items' => [], 'nextItemId' => 1],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (($bad = wiki_list_check($raw)) !== null) return 'Error: ' . wiki_list_check_message($bad) . ' Nothing was created.';

    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return 'Error: could not create the folder.';
    $fh = @fopen($abs, 'x');   // fails if the path exists — also when another create just won
    if (!$fh) return file_exists($abs) ? "Error: \"{$rel}\" already exists. wiki_create_list never replaces a list." : 'Error: could not create the list file.';
    $ok = fwrite($fh, $raw) === strlen($raw);
    fclose($fh);
    if (!$ok) { @unlink($abs); return 'Error: could not write the list.'; }
    $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
    wiki_ai_fts_upsert($space_dir, $rel, '');
    $id = $indexer->getId($rel);
    $names = implode(', ', array_map(fn($c) => '"' . $c['name'] . '"' . (($c['role'] ?? '') === 'title' ? ' (Title)' : ''), array_slice($columns, 1)));
    return "List created: {$rel}" . ($id !== null ? " (page id {$id})" : '') . ", with the fields {$names}. It has no items yet — add them with wiki_add_list_item.";
}

/**
 * wiki_add_list_item: a new item added to a .list by an AI (US-0002). Built on the same rules
 * as wiki_update_list_item — values through _wiki_ai_list_value(), comments stamped by the
 * server, wiki_list_check(), `updateModified` for generators and workflows — plus the one thing
 * only an add has: a new item id.
 *
 * The id is allocated **under the file's lock**, from the list as it is on disk at that moment,
 * so two adds at once (two AI jobs, or an AI and a person) are serialised: each reads the
 * other's result and neither can get the same id or overwrite the other's item. api.php's
 * `save` takes the same lock and compares the page's `rev`, so a person saving from a copy
 * older than the add gets the conflict dialog instead of silently dropping the AI's item.
 *
 * The id is past `nextItemId` *and* past every id in the file, since a list edited by hand can
 * have a counter that is behind — the same defensive rule as the entry counter. An
 * autoincrement (ItemId) column gets the same number, which is what the item form writes.
 */
function wiki_ai_add_list_item(array $input, $ai_user, $indexer, string $space_dir): string {
    if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot change lists.';
    $rel = ltrim(str_replace('..', '', (string)($input['path'] ?? '')), '/');
    if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'list') return 'Error: path must name a .list file.';
    $abs = rtrim($space_dir, '/') . '/' . $rel;
    if (!is_file($abs)) return "Error: there is no list \"{$rel}\" in this space. wiki_add_list_item adds to an existing list; it does not create one.";
    $fields = $input['fields'] ?? [];
    if (is_string($fields)) $fields = json_decode($fields, true) ?? [];
    if (!is_array($fields) || !$fields) return 'Error: fields must be an object of field name => value, including the Title field.';
    $comment = trim((string)($input['comment'] ?? ''));

    $lock = @fopen($abs, 'r');
    if (!$lock) return 'Error: could not open the list.';
    flock($lock, LOCK_EX);
    $done = function (string $msg) use ($lock) { flock($lock, LOCK_UN); fclose($lock); return $msg; };
    $old_raw = (string)file_get_contents($abs);
    $list = json_decode($old_raw, true);
    if (!is_array($list) || !is_array($list['columns'] ?? null)) return $done('Error: that file is not a readable list.');
    if (!is_array($list['items'] ?? null)) $list['items'] = [];
    $title   = wiki_list_title_field($list);
    $targets = null;
    $item    = [];

    foreach ($fields as $name => $value) {
        $col = _wiki_ai_list_column($list, (string)$name);
        if (!$col) return $done(_wiki_ai_list_no_field($list, (string)$name) . ' Nothing was added.');
        $cid  = (string)$col['id'];
        $conv = _wiki_ai_list_value($col, $value, $list, $space_dir, $targets, $cid === $title);
        if (isset($conv['error'])) return $done('Error: ' . $conv['error'] . ' Nothing was added.');
        if ($conv['value'] !== null) $item[$cid] = $conv['value'];
    }
    if ($title !== null && !array_key_exists($title, $item)) {
        $tcol = _wiki_ai_list_column($list, $title);
        $tname = (string)($tcol['name'] ?? $title);
        return $done("Error: \"{$tname}\" is the list's Title, which an item must have. Nothing was added.");
    }
    if ($comment !== '') {
        if (!wiki_list_comments_on($list)) return $done('Error: this list has comments turned off. Nothing was added.');
        $item[WIKI_LIST_COMMENTS] = [['text' => $comment]];
    }

    $max = 0;
    foreach ($list['items'] as $it) $max = max($max, (int)($it['id'] ?? 0));
    $new_id = max(is_int($list['nextItemId'] ?? null) ? $list['nextItemId'] : 1, $max + 1);
    $head = ['id' => $new_id];
    foreach ($list['columns'] as $c) if (($c['type'] ?? '') === 'autoincrement') $head[(string)$c['id']] = $new_id;
    $list['items'][]    = $head + $item;
    $list['nextItemId'] = $new_id + 1;

    $raw = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $st = wiki_list_comments_stamp($raw, $old_raw, ['uid' => (int)($ai_user['uid'] ?? 0), 'name' => (string)($ai_user['name'] ?? 'AI')], false);
    if (isset($st['error'])) return $done('Error: ' . wiki_list_check_message($st['error']) . ' Nothing was added.');
    $raw = $st['content'];
    if (($bad = wiki_list_check($raw)) !== null) return $done('Error: ' . wiki_list_check_message($bad) . ' Nothing was added.');
    if (file_put_contents($abs, $raw) === false) return $done('Error: could not write the list.');
    clearstatcache(true, $abs);
    flock($lock, LOCK_UN);
    fclose($lock);
    $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
    return "Item {$new_id} added to {$rel}. Its id is {$new_id}. Pages generated from this list are re-rendered with the change.";
}

/**
 * wiki_update_list_item: one item of a .list changed by an AI — some fields set, a comment
 * added. The same rules a person's save goes through (api.php `save`): under the file's own
 * lock, comments stamped by wiki_list_comments_stamp() (as the AI user, never an admin's
 * rights), the result refused unless wiki_list_check() passes, and `updateModified` — which
 * is what re-renders the pages generated from the list and fires workflows. Values are
 * converted by the field's type here, since a model sends "12" for a number and "yes" for a
 * boolean, and a list stores what its form would. Groups of fields (list-wide entry numbers),
 * ItemId and "also show" columns are refused rather than half-handled: the form does those.
 * Not committed to git, like a person's list save.
 */
function wiki_ai_update_list_item(array $input, $ai_user, $indexer, string $space_dir): string {
    if (($ai_user['role'] ?? 'reader') === 'reader') return 'Error: this AI user has read-only (reader) role and cannot change lists.';
    $rel = ltrim(str_replace('..', '', (string)($input['path'] ?? '')), '/');
    if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'list') return 'Error: path must name a .list file.';
    $abs = rtrim($space_dir, '/') . '/' . $rel;
    if (!is_file($abs)) return "Error: there is no list \"{$rel}\" in this space.";
    $item_id = $input['item_id'] ?? null;
    if (!is_numeric($item_id) || (int)$item_id != $item_id) return 'Error: item_id must be the item\'s numeric "id".';
    $item_id = (int)$item_id;
    $fields  = $input['fields'] ?? [];
    if (is_string($fields)) $fields = json_decode($fields, true) ?? [];
    if (!is_array($fields)) return 'Error: fields must be an object of field name => value.';
    $comment = trim((string)($input['comment'] ?? ''));
    if (!$fields && $comment === '') return 'Error: give fields to change, a comment, or both.';

    $lock = @fopen($abs, 'r');
    if (!$lock) return 'Error: could not open the list.';
    flock($lock, LOCK_EX);
    $done = function (string $msg) use ($lock) { flock($lock, LOCK_UN); fclose($lock); return $msg; };
    $old_raw = (string)file_get_contents($abs);
    $list = json_decode($old_raw, true);
    if (!is_array($list) || !is_array($list['columns'] ?? null)) return $done('Error: that file is not a readable list.');
    $at = null;
    foreach ($list['items'] ?? [] as $n => $it) if ((int)($it['id'] ?? -1) === $item_id) { $at = $n; break; }
    if ($at === null) return $done("Error: the list has no item with id {$item_id}.");
    $item  = $list['items'][$at];
    $title = wiki_list_title_field($list);
    $targets = null;
    $changed = [];

    foreach ($fields as $name => $value) {
        $col = _wiki_ai_list_column($list, (string)$name);
        if (!$col) return $done(_wiki_ai_list_no_field($list, (string)$name) . ' Nothing was changed.');
        $cid = (string)$col['id'];
        $conv = _wiki_ai_list_value($col, $value, $list, $space_dir, $targets, $cid === $title);
        if (isset($conv['error'])) return $done('Error: ' . $conv['error'] . ' Nothing was changed.');
        $new = $conv['value'];
        $cname = (string)($col['name'] ?? $cid);
        if (($item[$cid] ?? null) === $new) continue;
        if ($new === null) unset($item[$cid]); else $item[$cid] = $new;
        $changed[] = $cname;
    }
    if ($comment !== '') {
        if (!wiki_list_comments_on($list)) return $done('Error: this list has comments turned off. Nothing was changed.');
        $item[WIKI_LIST_COMMENTS] = array_merge(is_array($item[WIKI_LIST_COMMENTS] ?? null) ? $item[WIKI_LIST_COMMENTS] : [], [['text' => $comment]]);
    }
    if (!$changed && $comment === '') return $done("Nothing to change: item {$item_id} already has those values.");
    $list['items'][$at] = $item;
    $raw = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Who and when, by the server — the same stamp a person's save gets, never an admin's rights.
    $st = wiki_list_comments_stamp($raw, $old_raw, ['uid' => (int)($ai_user['uid'] ?? 0), 'name' => (string)($ai_user['name'] ?? 'AI')], false);
    if (isset($st['error'])) return $done('Error: ' . wiki_list_check_message($st['error']) . ' Nothing was changed.');
    $raw = $st['content'];
    if (($bad = wiki_list_check($raw)) !== null) return $done('Error: ' . wiki_list_check_message($bad) . ' Nothing was changed.');
    if (file_put_contents($abs, $raw) === false) return $done('Error: could not write the list.');
    clearstatcache(true, $abs);
    flock($lock, LOCK_UN);
    fclose($lock);
    $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
    $what = [];
    if ($changed) $what[] = 'set ' . implode(', ', array_map(fn($n) => "\"{$n}\"", $changed));
    if ($comment !== '') $what[] = 'added a comment';
    return "Item {$item_id} of {$rel} updated: " . implode(' and ', $what) . '. Pages generated from this list are re-rendered with the change.';
}

