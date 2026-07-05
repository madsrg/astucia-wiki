<?php
// =================================================================
// WIKI AI TOOLS — shared by api.php (chat @mentions) and mcp.php (MCP tools/call)
// Defines the wiki_* tool set and executes it against a PageIndexer/space_dir.
// =================================================================

require_once __DIR__ . '/git_helpers.php';
require_once __DIR__ . '/search_index.php';

function wiki_tool_definitions(): array {
    return [
        [
            'name'        => 'wiki_list_pages',
            'description' => 'List all pages in the current wiki space. Returns a JSON array of objects with "id", "path", "space", and "tags" (array, only present when non-empty) fields. Use this to find pages by tag or to discover what content exists before reading.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
        ],
        [
            'name'        => 'wiki_search_pages',
            'description' => 'Search Markdown pages in the current wiki space by topic and/or recency. Returns a JSON array of matching pages with "id", "path", "space", "updated" (ISO 8601 last-modified timestamp), and — for text queries — "header" (first heading) and "preview" (a snippet). Provide "query" to search page text, "updated_within_days" to restrict to recently-updated pages (e.g. answer "pages updated in the last 7 days" with updated_within_days=7 and no query), or both together. At least one of the two is required.',
            'params'      => [
                'type'       => 'object',
                'properties' => [
                    'query'               => ['type' => 'string',  'description' => 'Search string, e.g. "onboarding checklist". Omit to list purely by recency.'],
                    'updated_within_days' => ['type' => 'integer', 'description' => 'Only return pages updated within this many days. E.g. 7 for the last week. Omit for no date restriction.'],
                ],
                'required'   => [],
            ],
        ],
        [
            'name'        => 'wiki_read_page',
            'description' => 'Read the full content of a wiki page by its relative path.',
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
                    'path'    => ['type' => 'string', 'description' => 'Relative path ending in .md, e.g. Notes/Summary.md'],
                    'content' => ['type' => 'string', 'description' => 'REQUIRED: the complete markdown content of the page. Must not be omitted or empty.'],
                ],
                'required'   => ['path', 'content'],
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
    ];
}

function get_wiki_tools($provider) {
    $tools_def = wiki_tool_definitions();
    if ($provider === 'anthropic') {
        return array_map(fn($t) => [
            'name'         => $t['name'],
            'description'  => $t['description'],
            'input_schema' => $t['params'],
        ], $tools_def);
    }
    // OpenAI-compatible
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
// An empty $query returns a pure recency listing (requires a date filter).
function wiki_search_pages(string $query, $indexer, $space_dir, int $updated_within_days = 0): array {
    $space_name = basename($space_dir);
    $cutoff     = $updated_within_days > 0 ? time() - $updated_within_days * 86400 : 0;
    $all        = $indexer->getAllPages();
    $updated_of = fn($id) => isset($all[$id]['updated']) ? (int)$all[$id]['updated'] : 0;
    $iso        = fn($ts) => $ts ? date('c', $ts) : null;

    // Pure recency listing — no text query, so no file reads needed.
    if ($query === '') {
        $rows = [];
        foreach ($all as $id => $data) {
            if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') continue;
            $upd = (int)($data['updated'] ?? 0);
            if ($cutoff && $upd < $cutoff) continue;
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
                $page_id = $indexer->getId($row['path']);
                if ($page_id === null) continue;
                if ($cutoff && $updated_of($page_id) < $cutoff) continue;
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
        if (!isset($data['path']) || pathinfo($data['path'], PATHINFO_EXTENSION) !== 'md') continue;
        if ($cutoff && (int)($data['updated'] ?? 0) < $cutoff) continue;
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

function execute_ai_tool($tool_name, $tool_input, $ai_user, $indexer, $space_dir) {
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
            if ($query === '' && $days <= 0) return 'Error: provide "query", "updated_within_days", or both.';
            return json_encode(wiki_search_pages($query, $indexer, $space_dir, $days));

        case 'wiki_read_page':
            $rel = ltrim(str_replace('..', '', $tool_input['path'] ?? ''), '/');
            if (!$rel) return 'Error: path is required.';
            $ext = pathinfo($rel, PATHINFO_EXTENSION);
            if (!in_array($ext, ['md', 'list', 'chat'], true)) return 'Error: only .md, .list and .chat files can be read.';
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
            $ai_git_name  = $ai_user['name'] ?? 'AI';
            $ai_git_email = !empty($ai_user['email']) ? $ai_user['email'] : 'ai@wiki.localhost';
            if ($is_new) {
                $indexer->addPage($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
                git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Create ' . basename($rel));
                return "Page created: {$rel}";
            }
            $indexer->updateModified($rel, $ai_user['uid'] ?? null, $ai_user['name'] ?? null);
            git_auto_commit($abs, $ai_git_name, $ai_git_email, 'Update ' . basename($rel));
            return "Page updated: {$rel}";

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
