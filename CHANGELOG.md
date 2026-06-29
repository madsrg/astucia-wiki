# Changelog

All notable changes to Astucia Wiki are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions follow [CalVer](https://calver.org/) — `YYYY.M.MICRO`.

## [Unreleased]

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
