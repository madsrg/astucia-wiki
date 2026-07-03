<?php
// =================================================================
// WIKI AI TOOLS — shared by api.php (chat @mentions) and mcp.php (MCP tools/call)
// Defines the wiki_* tool set and executes it against a PageIndexer/space_dir.
// =================================================================

require_once __DIR__ . '/git_helpers.php';

function wiki_tool_definitions(): array {
    return [
        [
            'name'        => 'wiki_list_pages',
            'description' => 'List all pages in the current wiki space. Returns a JSON array of objects with "id", "path", "space", and "tags" (array, only present when non-empty) fields. Use this to find pages by tag or to discover what content exists before reading.',
            'params'      => ['type' => 'object', 'properties' => (object)[], 'required' => []],
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
