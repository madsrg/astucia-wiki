# Changelog

All notable changes to Astucia Wiki are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions follow [CalVer](https://calver.org/) — `YYYY.M.MICRO`.

## [Unreleased]

## [2026.7.6] — 2026-07-05

### Added
- **Open remote pages from a saved search** — clicking a result from a wiki-native MCP source now fetches that page and shows it in a lightbox with rendered Markdown and a **Save local copy** button (which writes it to a folder you choose in this wiki). Previously only this-wiki results were clickable.

### Changed
- **All wiki searches now ignore the `templates/` folder** — page templates no longer appear in the search pane, AI User / Page Chat searches, MCP `wiki_search_pages` results, or saved searches.

## [2026.7.5] — 2026-07-05

### Changed
- **Saved searches (`.search`) no longer run automatically on open** — opening a saved search restores its query and source but waits for you to run it (Search button or Enter), instead of firing the query every time the page is viewed.
- **Saved searches remember their last result** — running a search now stores the result and a timestamp in the `.search` file. Reopening the page shows that result under a **"Last run: ‹date/time›"** label (local page links still clickable); running again replaces the stored result.

## [2026.7.4] — 2026-07-05

### Added
- **MCP Tool Explorer — richer result view** — each invocation result now carries a toolbar: a metadata line (latency · size · line count), a **Raw ⇄ JSON** toggle (shown only when the payload parses as JSON, defaulting to formatted), and **Copy** / **Download** actions. A **Clear** button empties the results pane, and a tool-filter box appears once a server exposes more than 10 tools.
- **MCP Tool Explorer — save result as a page** — save any result as a Markdown page, choosing the destination folder (from the space's folder tree) and page name. The page is written with a metadata header (server, tool, arguments, timestamp) followed by the payload in a fenced block.
- **MCP Tool Explorer — re-run and recall** — past invocations are clickable to restore their server, tool, and arguments for tweaking; the last-used server and tool are remembered across opens; Enter in an argument field invokes and Esc closes the explorer.
- **Configurable search tool per MCP server** — saved searches (`.search`) against a generic (non-Astucia-Wiki) MCP source now resolve the tool to call using a hybrid strategy: the server's optionally-configured **Search tool** / **Query argument** (set in Admin → AI → MCP Servers) win, otherwise a name heuristic picks an exact `search` tool, then any tool named like `search`/`find`/`query`/`lookup`/`retrieve`, and routes the text to the configured argument, else `query`, else the tool's first parameter. Still fully deterministic — no LLM.

### Fixed
- The MCP server admin form had no "This server is an Astucia Wiki" toggle, so saving/editing a server silently reset its `wiki_native` flag to false — making `tag:`/`updated:` filters and native page results in saved searches unreachable. The toggle is now present and persisted.

## [2026.7.3] — 2026-07-05

### Added
- **Saved searches (`.search` content type)** — a new content file that stores a query and runs it deterministically (no LLM). Create one from the New menu; opening it auto-runs the saved query in a chat-like results view, and running persists the query back to the file. Supports a compact token language: free text for full-text search plus `tag:<name>` (repeatable, exact-match, quote for spaces), `updated:<N>d` for recency, and `src:<slug>` to route the search at a registered MCP source instead of this wiki.
- **`wiki_search_pages` tool** — full-text search across all Markdown pages in the current Space, available to chat/Page Chat AI Users and MCP clients alike (uses SQLite FTS5 when configured, with a plain-text fallback)
- **Date filtering in `wiki_search_pages`** — an `updated_within_days` parameter (with `query` now optional) lets AI Users answer prose like "pages updated in the last 7 days"; filtering uses the authoritative `index.json` timestamps, so it works with or without SQLite
- **Tag filtering in `wiki_search_pages`** — a `tags` parameter (all must match, exact) lets AI Users and MCP clients narrow searches to specific tags, combinable with `query` and `updated_within_days`.
- **MCP Tool Explorer** — an admin/editor lightbox (sidebar toolbar) to browse and invoke tools on any registered MCP server directly. Pick a server and tool, fill in typed argument fields derived from the tool's input schema, and invoke it (deterministic `tools/list` + `tools/call`, no LLM) — useful for testing and exploring an MCP server's capabilities.
- **MCP tool attribution** — the `MCP tools used: …` footer and the live AI status modal now prefix external MCP tool calls with their server name (e.g. `Microsoft Learn:search_docs`) instead of just the bare tool name, in chat, Page Chat, and Agent Jobs alike
- **Explicit MCP source invocation (`src:`)** — type `src:` in a chat or Page Chat message for a type-ahead of registered MCP servers (e.g. `src:astucia_projects`). When the addressed AI User has that server enabled, its reply is restricted to *only* that server's tools — no built-ins, no other MCP servers — as a deterministic alternative to the free-text per-server instructions. Also honored in Agent Job prompts.
- **Per-Space ACL for AI Users and API Accounts** — service tokens (`wk_ai_…`/`wk_sys_…`) can now be restricted to specific Spaces from their admin form, matching the existing restriction available to human users. Enforced everywhere a Space is resolved, including `mcp.php`.
- **Test Connection for AI Users** — a button on the AI User admin form sends a minimal completion request to verify the provider/URL/model/key actually work together before saving; shows the model's reply or the exact API error inline

### Fixed
- `get_path_from_id`'s cross-space fallback search had no Space ACL check at all (for any actor, not just service tokens) — it now respects the caller's Space restrictions
- MCP tools with a name identical to a built-in `wiki_*` tool (e.g. connecting one AstuciaWiki's MCP server to another's) were silently dropped in chat/Page Chat, and would have silently hijacked the built-in tool's calls in Agent Jobs — every external MCP tool is now namespaced under its server (e.g. `astucia_projects__wiki_list_pages`) so it can never collide
- MCP tools with no parameters (empty `inputSchema.properties`) caused the LLM API to reject the whole request with `input_schema.properties: Input should be an object` — `json_decode`'s empty-array-vs-object ambiguity is now corrected before the schema is sent

## [2026.7.2] — 2026-07-03

### Added
- **MCP server (`mcp.php`)** — the wiki now exposes its own MCP endpoint (JSON-RPC 2.0 / Streamable HTTP) so any MCP client (Claude Desktop, Claude Code, custom agents) can connect directly and call `wiki_list_pages`, `wiki_read_page`, `wiki_write_page`, `wiki_add_tags`, and `wiki_set_tags` using existing AI User / API Account bearer tokens

### Fixed
- Clicking a Recent or Favorites item did not switch the sidebar to the Tree tab
- Editor toolbar `?` help popup could overflow off-screen; now right-aligned
- Admin AI User form scroll area missing right padding

## [2026.7.1] — 2026-07-01

### Added
- **MCP server integration** — Admin → AI → MCP Servers tab to register HTTP/SSE MCP servers; AI Users can enable per-server tool access for both agent jobs and page chat
- **MCP tool guidance** — per-MCP-server instruction field on AI User form to steer the LLM on when and how to use each server's tools, injected into the system prompt at runtime
- **MCP tool visibility** — AI replies append a `MCP tools used: …` footer whenever an MCP tool is called, in both agent jobs and chat

### Fixed
- MCP tool calls returning a boolean instead of a string to the LLM (PHP `&&` operator does not return the right-hand value unlike JavaScript)
- MCP servers using JSON-RPC 2.0 / Streamable HTTP transport (e.g. Microsoft Learn) returned HTTP 400 — now POSTs JSON-RPC envelopes to the base URL instead of REST-style `/tools/list` paths
- Toasts displayed behind modal lightboxes — now always on top via `z-index: 9999`
- AI User could be saved without an API URL

## [2026.6.3] — 2026-06-30

### Fixed
- Release workflow updated to `actions/checkout@v5` for Node.js 24 compatibility

## [2026.6.2] — 2026-06-29

### Added
- **Tag type-ahead** — typing in the tag input suggests existing tags from all accessible Spaces, with substring matching, keyboard navigation (↑ ↓ Enter Tab Esc), and a per-session cache
- **Cross-Space tag cloud** — the Search pane tag cloud now shows tags from all accessible Spaces with total page counts; clicking a tag returns results across all Spaces with Space badges

### Fixed
- Stale space name in Recent/Favorites no longer triggers a broken space switch; the navigation guard validates against the live spaces list and self-heals the bad entry
- Space badge hidden in Recent/Favorites when the stored space name no longer exists (renamed or deleted)
- Tag type-ahead dropdown was transparent (CSS variable `--bg-primary` undefined); now solid white
- Tag type-ahead dropdown appeared below the input and outside the viewport; now opens upward
- Tag type-ahead cache could get permanently stuck as empty on any transient error; now only cached on a clean successful response
- Search pane layout: "All spaces" checkbox moved closer to the search input; more space added above the tag cloud
- Login page OIDC column label changed from "Personal account" to "Single Sign-On"

## [2026.6.1] — 2026-06-28

### Added
- **Admin → Index Pages** — rebuild the page index for a Space or all Spaces from the admin panel, with a per-Space progress modal showing file counts in real time
- **OTP email authentication** — one-time password login alongside existing OIDC, configurable via `config.php`
- **SQLite FTS5 full-text search** — fast keyword search across large Spaces with optional cross-Space search
- **Page Chat** — per-page AI assistant panel with threaded conversation and auto-refresh
- **Deleted Pages recovery** — Admin → Content → Deleted Pages lists git-tracked deletions with one-click restore
- **Diagram templates** — starter templates available when creating a new diagram
- **Share by email** — send a page link directly from the share lightbox
- **Deep-link redirect** — after login, users land on the page they originally requested
- **Search pagination** — results paginate at 50 per page
- **AI slash commands** — `/me`, `/topic`, `/purge`, `/summarize` in chat inputs with type-ahead picker
- **Live AI status panel** — shows AI thinking progress with real-time status updates during responses
- **Clone AI User** — duplicate an AI User configuration with a new name
- **wiki_add_tags AI tool** — AI Users can merge tags onto a page without replacing existing ones
- **wiki_set_tags AI tool** — AI Users can set the full tag list on a page
- **API Accounts** — headless service accounts for scripting and CI/CD integrations
- **Agent Jobs** — scheduled AI tasks that run on a cron-like schedule
- **Admin grouped tabs** — Admin panel reorganised into Users / AI / Monitoring / Content groups
- **Checkbox list toolbar button** — insert a GFM task list from the editor toolbar

### Fixed
- AI Page Chat writing to Space root instead of the page's subfolder
- AI thinking modal closing immediately when AI runs asynchronously
- Concurrent AI run collision when multiple placeholders exist simultaneously
- `confirmModal` swallowing the keystroke that triggered it
- Stale poll errors after a file is moved or renamed during an AI chat session
- Search input overflow and duplicate entries in recent pages list
- i18n `t()` helper now correctly calls function-valued translation keys

## [2026.6.0] — 2026-06-20

Initial public release.
