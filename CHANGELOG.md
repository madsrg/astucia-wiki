# Changelog

All notable changes to Astucia Wiki are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions follow [CalVer](https://calver.org/) — `YYYY.M.MICRO`.

## [Unreleased]

## [2026.7.28] — 2026-07-17

### Added
- **"New Topic" checkbox in the chat composer** — a checkbox stacked above the emoji button (in both team chat and the page-chat panel) that starts a new topic for the next message, so you don't have to remember to type `/newTopic`. It's consumed (unchecked) on send, and `Alt+C` toggles it while the chat input is focused. Localized across all six languages.
- **Raw AI error diagnostics** — a new `AI_DEBUG_RAW_ERRORS` config flag (default off) that appends the HTTP status and a truncated raw response body to technical errors from the model endpoint, making it possible to see what a reverse proxy actually returned (e.g. an nginx 502/504/413 HTML page) instead of an opaque "unreadable response".

### Changed
- **Starting a new topic keeps the current AI focus** — `/newTopic` (and the new checkbox) still reset the AI's message context, but no longer drop you out of the focused conversation, so plain follow-up messages keep going to the same AI user.
- **AI model requests now advertise gzip/deflate** (`CURLOPT_ENCODING`) and always surface the HTTP status in connection errors, matching the MCP client and improving diagnosability of self-hosted endpoints behind a proxy.

## [2026.7.27] — 2026-07-17

### Fixed
- **AI users no longer fail with "Stopped after too many tool calls without producing a response"** — when a model kept calling tools right up to the agentic-loop cap without ever writing a final reply, the request errored out. The final iteration now forbids tools (`tool_choice: none`) across all provider families, so the model is forced to produce a text answer instead of erroring. The per-request iteration caps were also raised (agent jobs 10→12, team/page chat 8→10) to give complex tasks more room.

## [2026.7.26] — 2026-07-17

### Added
- **Custom request headers for AI Users and MCP Servers** — both admin forms now have an "Extra request headers" editor (an editable list of name/value pairs) sent on every outbound request, on top of the provider/server auth. This covers gateways in front of a self-hosted model or MCP server, e.g. a Cloudflare Access tunnel needing `CF-Access-Client-Id` and `CF-Access-Client-Secret`. Headers are merged into every request site (agent jobs, team/page chat, saved searches, and the Test Connection buttons) across all provider families; CR/LF are stripped on save to prevent header injection.

## [2026.7.25] — 2026-07-15

### Changed
- **`/aiUsers` overview is now a table** — the chat command lists each AI user alongside the model it uses and the MCP servers enabled for it, instead of a plain comma-separated list of names. Backed by a new `get_ai_users_overview` action that returns non-secret config only (no endpoints, API keys, or tokens); the dialog is widened to fit the table.

## [2026.7.24] — 2026-07-14

### Fixed
- **System sidecars hidden from the file tree and search** — the `.json` content type added in 2026.7.23 had surfaced the space-root `index.json` (page index) and `graph.json` (knowledge-graph cache) sidecars in the file tree and full-text search. Both are now excluded at the space root, scoped so a real user file named `index.json` inside a subfolder is still shown and searchable.

## [2026.7.23] — 2026-07-14

### Added
- **JSON data pages (`.json`)** — a new content type for raw structured data (statistics, reports, query results) that doesn't fit the schema-based `.list` type. Rendered with an editable tree/table/text viewer (vanilla-jsoneditor); Save is role-gated (readers get a read-only view), with a full-screen toggle and a read-only fallback if the editor can't load. New `wiki_write_json` AI tool (validates JSON, editor-role gated); `.json` files are validated and pretty-printed on save, git-committed, full-text indexed, and shown in the file tree with their own icon. Toolbar strings localized across all six languages.

### Fixed
- **Non-text AI/MCP content no longer silently dropped** — image/binary/resource blocks returned by an LLM or MCP tool were discarded without a trace. All provider parsers (Anthropic, OpenAI Responses, OpenAI Chat) and the MCP client now append a visible note listing omitted blocks, and OpenAI chat replies with array content no longer risk a `trim()`-on-array error.

## [2026.7.22] — 2026-07-12

### Fixed
- **HTML entities mangled in AI chat replies** — the #mention highlighter matched digits inside numeric HTML entities (e.g. `&#39;`), so apostrophes rendered as a broken, highlighted `#39;`. The highlighter now skips entities via an alternation, leaving them intact (in both team chat and page chat).

## [2026.7.21] — 2026-07-12

### Added
- **OpenAI Responses API provider** — a new `openai-responses` provider (Admin → AI → Provider) that speaks the Responses shape (flat tools, instructions + input, `max_output_tokens`), working across chat/page-chat replies, agent jobs, and Test Connection.
- **Config-driven LLM provider registry** — `llm_providers.json` maps a provider id to its response family (openai-chat / openai-responses / anthropic), label, and default endpoint; adding a provider that speaks a known family is now a JSON edit. The Admin provider dropdown and endpoint auto-fill are built from the registry.

### Fixed
- **Reasoning-model requests (o-series / gpt-5)** — send `max_completion_tokens` for api.openai.com (else `max_tokens`) with a swap-and-retry, and drop an unsupported custom temperature and retry, across all call paths.

## [2026.7.20] — 2026-07-12

### Changed
- **Clearer MCP connection errors** — outbound MCP calls now surface the server's real error (message/detail, code, data/meta) instead of a generic "JSON-RPC error", and advertise gzip/deflate. A plain REST API misconfigured as an MCP URL now reports its actual validation message.
- **Neutral MCP auth-header example** — replaced the misleading "X-Subscription-Token for Brave" example in the Admin MCP form with a neutral `X-API-Key` one.

## [2026.7.19] — 2026-07-12

### Added
- **Custom outbound MCP auth header** — external MCP servers can use a custom header + scheme instead of the hardcoded `Authorization: Bearer <token>`; Admin → AI → MCP Servers gains Auth header / Auth scheme fields. Existing configs need no migration.
- **`/aiUsers` chat command** — lists the AI users you can #mention (team chat and page chat).

### Fixed
- **API accounts excluded from #mention autocomplete** — headless inbound tokens (`is_system`) no longer appear in chat, page chat, or comment mentions.

## [2026.7.18] — 2026-07-11

### Changed
- **Docs** — daily-digest setup now shows installing the cron entry as the web-server user (`sudo -u www-data crontab -e`), and notes the crontab lives in the cron spool.

## [2026.7.17] — 2026-07-11

### Added
- **Daily updates digest email** — opt-in per user: once a day, email a summary of pages created or updated in the last 24 hours across the spaces they can access (own edits excluded, capped at the 20 most-recent, grouped by space). Driven by a `run_daily_digest.php` CLI/cron runner; toggle in My Preferences (shown when email is configured).

## [2026.7.16] — 2026-07-11

### Added
- **Knowledge-graph zoom controls** — a −/Reset/+ button group in the graph toolbar; Reset re-fits with the clamped fit used on open.

## [2026.7.15] — 2026-07-11

### Fixed
- **Knowledge-graph initial zoom** — sparse graphs (notably the per-page focus view) no longer blow nodes up to fill the viewport; initial zoom is clamped to a readable level while manual zoom still works.

## [2026.7.14] — 2026-07-11

### Added
- **Knowledge graph view** — an interactive per-Space graph layering three relationship types: explicit `?pageid=` links (reference), folder hierarchy (containment), and shared tags (affinity). Whole-space map and per-page focus view via a scope toggle, with edge-type filters, colour-by-folder and size-by-degree (cytoscape, lazy-loaded from CDN). Backed by `graph.php`/`WikiGraph` with an mtime-cached outbound-link map.
- **`wiki_related_pages` AI tool** — lets AI/MCP clients traverse page relationships and ground answers in wiki structure.

### Changed
- **Language selector moved to My Preferences** — with a sidebar fallback for anonymous / no-auth visitors who have no preferences page.

## [2026.7.13] — 2026-07-10

### Fixed
- **ES module cache-busting** — edited JS modules could be served stale because only `script.js`/`styles.css` carried a `?v=<mtime>` query. An import map in `<head>` now maps every `modules/*.js` to a `?v=<mtime>` URL, covering both static and dynamic imports without changing any import statements.

## [2026.7.12] — 2026-07-10

### Fixed
- **Mobile editing keeps the header and toolbar fixed** — when editing a Markdown page on mobile, the page header and formatting toolbar now stay in place and only the textarea contents scroll (bounded the editor's flex height chain and pinned the header).

## [2026.7.11] — 2026-07-10

### Added
- **Simple Markdown editing on mobile** — Markdown pages can now be edited on a phone. A compact toolbar (H1/H2/H3, bold, italic, bulleted &amp; numbered lists, and delete-current-line) sits above the editor, reusing the classic textarea and the standard save flow. Mobile editing always uses the classic editor (the inline block editor stays desktop-only), and the line-number gutter is dropped for space.

## [2026.7.10] — 2026-07-10

### Changed
- **Mobile header is a single aligned row** — on a phone the page icon, title, and favorite button now line up vertically with the menu button; the folder breadcrumb is hidden on mobile (navigation is available from the drawer).

## [2026.7.9] — 2026-07-10

### Changed
- **Mobile drawer stays open when tapping a folder** — the navigation drawer now closes only when an actual page is opened; folders (and the folder-browse "up" row) keep it open so you can browse. Also fixed the folder-browse pane not closing the drawer on page selection.
- **More desktop-only chrome hidden in mobile view** — the Classic/Inline editor-mode toggle, Version History button, the `ID: <pageid>` badge, the page attachments + labels row, and the Administration and MCP Tool Explorer buttons are now hidden on mobile.

## [2026.7.8] — 2026-07-10

### Added
- **General Chat in the mobile view** — opening a `.chat` page on mobile now gives a fully usable chat: the topic control is available and per-message actions (reply, reaction, pin, save-as-page, append-to-page) are always shown instead of hidden behind hover, so they're reachable on touch.

### Changed
- **Mobile dialogs fit the screen** — lightboxes (save/append a message, copy, share, help, search results, etc.) are now near-full-width and content-height with internal scrolling on phones; full-screen dialogs go edge-to-edge.

## [2026.7.7] — 2026-07-10

### Added
- **Mobile view (view-focused)** — a responsive layout for phones: the sidebar becomes an off-canvas drawer opened from a header menu button, content goes full-width with larger type and horizontally-scrollable tables/code, and authoring controls (edit, new item, copy/move, chat, etc.) are hidden. A display-mode toggle in the sidebar footer cycles **Automatic → Desktop → Mobile** and is remembered, so a phone user can force the full desktop UI and a desktop user can preview mobile. Detection is viewport-based (no user-agent sniffing).
- **Chat focus mode** — after you `@mention` an AI User (or click its avatar/name), the chat "focuses" on it: a chip above the input shows who you're talking to and every following message is routed to that AI without re-mentioning. Exit with the chip's ✕, Esc on an empty input, `/newTopic`, or by mentioning someone else. Per-chat and remembered across reloads. Works in both General Chat and Page Chat.
- **Save a chat message as a page** — each chat message gains two actions: **Save as markdown page** (create a new page, choosing Space, folder, and filename) and **Append to markdown page** (pick an existing page from a tree and append the message to it). Both work across spaces and keep the page index, search index, and git history in sync.

### Fixed
- **Page scroll now resets to the top when switching pages** — scrolling down a long page and opening another no longer leaves you partway down the new one (Markdown, list, and saved-search views).
- **Space switcher — clicks near a row's top/bottom edge were ignored** — the whole space row is now clickable, not just the label text.

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
