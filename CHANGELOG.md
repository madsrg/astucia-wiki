# Changelog

All notable changes to Astucia Wiki are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions follow [CalVer](https://calver.org/) — `YYYY.M.MICRO`.

## [Unreleased]

## [2026.9.4] — 2026-09-08

The wiki gets a test suite. Alongside it: chat threads can be told how long to keep their
messages, an AI user's system prompt can be started from a curated gallery, and a person's
login address and their notification address stop being the same field.

### Added
- **A black-box test suite** (`tests/`, run with `tests/run.sh`). It builds a throwaway wiki
  from the working tree — uncommitted changes included — with its own port, its own content
  directory and a generated `config.php`, then drives it over HTTP and tears it down. No
  framework and no new dependencies, because a wiki you can install by copying a directory
  should be testable the same way. CI runs it on PHP 8.3, 8.4 and 8.5: 8.3 is what the image
  shipped, 8.5 is where development happens, so a fix verified locally can no longer be
  broken in the published image without anyone noticing.
  - `security_spaces.test.sh` is the regression suite for the v2026.9.3 Space-isolation fix.
    It was validated by re-introducing the vulnerability in a copy and confirming that 13
    attack assertions go red while all 15 legitimate-access assertions stay green — a
    security test that passes against fixed code proves nothing on its own.
- **Chat auto-purge.** "Edit Topic" becomes **Chat Settings**, and a thread can now keep only
  its newest 100/200/300 messages, or only the last 1/3/6 months. An administrator sets a
  wiki-wide default in **Admin → Content → Chat Retention**, and a thread either follows it
  ("Use the wiki default", the option an unconfigured thread starts on) or overrides it —
  including overriding it with *off*, so raising a house policy can never quietly start
  trimming a thread somebody deliberately exempted.
  - The dialog previews exactly what a policy would remove before anything is saved, and
    enabling one applies it immediately rather than at the next message.
  - Pinned messages and the placeholders a queued background job writes its answer into are
    never purged, and a pinned message does not consume the count budget. A message whose
    timestamp cannot be read is kept: age cannot be established, and deleting on a guess is
    the wrong way to be wrong.
  - Trimming happens when a thread is written to, not on a timer — so a thread nobody posts
    to is never trimmed, which the dialog says plainly.
  - Bulk deletions are recorded in the audit log, covering the manual `/purge` too.
    Individual messages stay out of it, as before.
- **A system prompt gallery.** Admin → AI Users → **Choose from Gallery** offers a curated
  list of role prompts (Product Owner, Developer, QA, Scrum Master, …) to start from.
  Choosing one **copies its text** into the prompt box — a snapshot you then edit, never a
  live link, so an upstream change cannot alter how your AI user behaves without you
  touching it.
  - This is the only place the wiki contacts the vendor, so: the **server** fetches it, never
    the browser, so no reader's IP is disclosed and it works when the browser sits on an
    isolated network; it is fetched **only when the dialog is opened**, never on page load;
    a copy **ships with the wiki**, so an air-gapped install still has a gallery; and setting
    `SYSTEM_PROMPT_GALLERY_URL` to `''` makes **no outbound request at all**, which the test
    suite asserts by counting requests.
  - `tools/build_system_prompts.php` builds the published JSON from ordinary Markdown pages,
    so the gallery is authored and reviewed like any other content.
- **AI users have an android avatar** in chat threads and in Page Chat, instead of the first
  letter of their name.

### Changed
- **The Docker image is now built on `php:8.5-fpm-alpine`** (was 8.3). About 263 MB, up from
  226 MB.
- **A user's login address and their notification address are two fields.** `email` is the
  identity — what an OIDC provider asserts, and what an OTP login code is matched on.
  `notifyEmail` is a preference: where that person would rather be written to. Mail addressed
  to a *person* (daily digest, agent-job notifications, share-a-page) uses the preference and
  falls back to the identity; mail addressed to an *address* (an OTP login code, an
  access-request alert) does not.
  - A one-shot migration copies `email` → `notifyEmail` for OIDC records, whose identity
    field is about to be overwritten by the provider claim, and writes
    `users.json.pre-split.bak` once before its first change.
  - OTP login addresses are now **unique** across accounts. A duplicate made the lookup
    return whichever record came first, so one person could land in another's account with
    that account's role and Spaces.

### Fixed
- **Saving a notification preference no longer overwrites the login identity.** The two
  shared one field, so setting a preferred address in *My Preferences* erased which provider
  account an OIDC record belonged to — and on an OTP account, a preference could overwrite a
  credential. Existing OIDC records repair themselves on the next login, from the provider's
  own claim; nothing has to be edited by hand.

## [2026.9.3] — 2026-09-06

**Security release.** A reader restricted to one Space could read another Space's content.
Everyone should upgrade. Alongside it, the collapsed sidebar's folder view becomes a place
you can actually work — search, upload, create, and act on the folder — and a background
job now tells you when it has finished.

### Security
- **Space isolation is enforced on the resolved path, not on the `?space=` parameter.** The
  allowlist was checked when a request named a Space, and the check was skipped when it did
  not — but the parameter is optional, so a *path* of `Other/secret.md` reached another
  Space without ever naming it. Four entry points were affected: the attachment gateway
  (`getfile.php`, which never consulted the allowlist at all — reported by a user), the REST
  API, the `.list` export, and the tool set shared by AI users, MCP and the job runner,
  where it allowed writes as well as reads. Each now resolves the path first and asks which
  Space it lands in. Content that sits outside any Space, on a wiki predating the feature,
  stays readable as before.
- **`getfile.php` no longer serves `?space=.git`**, which exposed the content repository's
  internals, including remote URLs with credentials in them. The REST API has always refused
  dot-prefixed Space names; the gateway did not.
- **Containment checks compare against the directory, not its name as a prefix.** A base of
  `…/Alpha` also matched `…/Alpha2`, a different Space one level up.

### Added
- **A working toolbar in the collapsed folder view** — a search box, an Upload button for
  Markdown pages, `New …` with the same options as the sidebar, and the folder's own `…`
  actions (Rename, Move, Delete). The folder's name is in the breadcrumb, so the title row
  that repeated it is gone.
- **Markdown files can be dropped straight onto the folder listing**, not just onto the file
  tree. They land in the folder being browsed.
- **A finished background job raises a notification** with its status and a link back to the
  thread it was started from. Queueing a job means going elsewhere, so the answer used to
  land in a thread nobody was looking at, possibly an hour later.
- **Sticky toasts** — notifications that stay until dismissed, stacked clear of the ordinary
  three-second ones, for things you are meant to act on rather than just notice.
- **`/aiJob` works in Page Chat.** It was in the team-chat composer only; in Page Chat the
  line was posted as an ordinary message, which then drew an inline reply instead of queueing
  a job.

### Changed
- **`/jobs` opens a log inside the list** with a Back button, instead of closing the list to
  borrow the dialog — reading a second log took three clicks and a re-open.
- **List export works again.** `export.php` resolved paths against a relative `pages/`, which
  is the content directory only on an install that keeps it inside the web root; everywhere
  else every export returned 404. It uses `PAGES_DIR` now, and the client sends the Space.
- Sidebar icons and the collapse toggle were drawn in a grey meant for light backgrounds,
  under the contrast floor for a control against the dark sidebar. Both now share one value.

### Fixed
- **Search results left the folder listing on screen underneath them.** The same omission
  affected opening a Files Library from a chat, data or saved-search page.
- Opening a page from the folder listing did not mark it in the sidebar, so expanding the
  sidebar showed the previous selection.

## [2026.9.2] — 2026-09-03

The collapsed sidebar stops being a dead end. A breadcrumb folder now opens a real folder
listing in the main area, and the 48px rail keeps the controls you actually need — space
switcher, the footer icons, log out. Page tabs gained an overflow menu instead of a
scrollbar.

### Added
- **Browsing folders with the sidebar collapsed.** Clicking a folder in the breadcrumb used
  to point the sidebar's browse pane at it, which does nothing when that pane is off-screen.
  Collapsed, it now lists the folder in the main area: directories first, then pages, each
  sorted case-insensitively. It reuses the Files Library pane, so the same three view modes
  (simple, detailed, icons) and the same saved preference apply. `Root` is a crumb like any
  other there — it lists the space root rather than opening the start page, or nothing in
  the root would be reachable.
- **The sidebar toggle swaps between the two presentations** of a selected folder: the
  tree's empty viewer while the tree is on screen, the listing while it isn't.
- **The collapsed sidebar keeps its footer icons**, stacked and bottom-aligned in the rail —
  they are the only way into Preferences, My Mentions, the graph and Admin. The **space
  switcher** is the first of them, with its dropdown opening beside the rail, and **log out**
  is the last, with room below it.
- **An overflow menu for page tabs.** As many tabs as fit are shown at their natural width
  and the rest move into a `⌄ N` dropdown at the end of the row. The active tab is always on
  the strip, whatever its position — only the display moves, so dragging still means what it
  did.
- **`list` returns each page's `updated` stamp**, read off the index entry already in memory
  rather than stat-ing every file. It is what the folder listing's Modified column shows.

### Changed
- **Sidebar icons are legible.** They used `--accent-gray`, a mid grey meant for light
  surfaces, which lands at 2.9:1 against the dark sidebar — under the 3:1 floor for a UI
  control. The collapse/expand toggle was dimmer still, at `opacity: 0.6` over a third
  colour. Both now use one token at 8.1:1.
- **The README's Docker section points at Docker Hub** instead of carrying a `docker run`
  line pinned to a stale tag and an incomplete build-it-yourself recipe.

### Fixed
- **The favourites star no longer appears on My Mentions, My Comments or search results.**
  It carried over from whatever page was last open, still lit for that page, and clicking it
  did nothing.
- **Opening a files library from a chat, `.json` or search page left that page on screen**
  underneath it. The listing pane now hides every other content container, not just two of
  them; selecting a folder in the tree re-shows the viewer for the same reason.
- **Opening a page from the folder listing marks it in the sidebar**, even though the sidebar
  is off-screen at the time — it is what you see the instant you expand it.

## [2026.9.1] — 2026-09-02

Everything an AI user does here now leaves a record you can read. An optional audit log says
who changed which page; `/debug` writes the whole conversation with the model to a page;
`/jobs` shows you your own background work and the log when it fails. Alongside that, AI
users can rename pages and be told to always run in the background, and Markdown files can
be dropped straight onto the file tree.

### Added
- **Audit log**, off by default, turned on in Admin → Audit Log. JSON Lines in
  `LOG_DIR/audit/`, one file per day, with CIM Change field names so Splunk indexes it with
  `INDEXED_EXTRACTIONS=json` and no regex. One hook in the router covers every content
  action from a browser or a service token alike; denials are logged too, since "a reader
  tried to edit a frozen Space" is usually the line someone is looking for. AI writes are
  attributed to the AI user rather than to whoever's request they happened to run inside.
  Chat messages are out of scope — a busy thread is hundreds of entries a day and is already
  its own record. The viewer filters by user and date, and a row opens the full entry.
- **`/debug` writes the full LLM transcript** to `LLM debug.md` in a `debug/` folder beside
  the chat: every request and response, every tool call, every MCP round trip. Credentials
  are redacted at capture, not at render — the transcript becomes a page every editor can
  read.
- **`/jobs`** lists your own background jobs with their state and opens the log of any of
  them. Job failure used to be one line in a thread with an admin-only log behind it.
  Ownership is checked against the queue entry, so a job id is not a key to someone else's
  log.
- **A queued job nothing will ever start is now abandoned** with a visible message. It is
  the one failure the job runner cannot report, because it is not running to report it.
- **`wiki_rename_page`** — AI users can rename a page without breaking what points at it.
  The id is kept, attachments and the cached `.drawio.svg` follow, the FTS row moves, and
  wikilinks pointing at the old name are counted rather than rewritten unless asked.
- **"Always run in the background"** for an AI user: chat mentions are queued as one-off
  jobs instead of answered inline, for reasoning models that take longer than a chat
  request should. The job carries a transcript of the recent thread, and a page chat's page
  travels with it, so a background AI is not answering blind.
- **Drag and drop Markdown pages onto the file tree** or the browse pane. `.md` only, judged
  on the final name; nothing is ever overwritten, a collision becomes `name (1).md`.

### Changed
- **Type-ahead highlights the first match** as soon as the list appears, and takes it
  automatically once only one candidate is left. Enter and Tab already picked the first item;
  nothing showed you that.
- **A bare `/newTopic` straight after another one is ignored** — it resets a context that is
  already empty.
- **Code blocks wrap instead of scrolling.** One long line — a JSON string, a URL, a stack
  trace — used to give the whole block a page-wide horizontal scrollbar.
- **Opening the editor puts the caret at the first character** rather than the end of the
  page.

### Fixed
- **A long page name no longer pushes the header apart**, shoving the action icons off the
  right edge and giving the page a horizontal scrollbar. The title ellipsizes instead.
- **The header's two sides line up.** With a breadcrumb shown, the page title sat ~10px
  below the icons beside it.

## [2026.8.4] — 2026-08-31

Mentions grow up. `@Name` now addresses a person and `#Name` an AI user, My Mentions
notices things it used to miss, an unread badge tells you when someone named you, and AI
users can name you back. Along the way the three AI entry points stopped disagreeing about
what tools exist.

### Added
- **`@` for people, `#` for AI.** The two sigils were interchangeable — `[@#]Name` already
  triggered an AI and every composer inserted `#` for everyone — so a name in a thread told
  you nothing about who it would reach. Each type-ahead now offers one kind, which also
  retires the special case that stopped `/aiJob` offering humans for a slot only an AI can
  fill. Reading stays lenient: `@AiName` still triggers, since the REST API documented it,
  and `#Alice` still counts as a mention of Alice, since every chat and comment written
  before today used it.
- **My Mentions covers every space you can read, and chat threads.** It scanned `.md` files
  in the current space only, so a mention in a chat — where an agent job reports that it
  finished — was invisible, and switching space changed what you were told.
- **An unread badge on My Mentions**, counting what arrived since you last opened the panel
  (your last login the first time). It polls, because what produces a mention is often not a
  person typing, and toasts when the count rises, naming where it happened. A chat is judged
  per message, so a busy thread whose only mention of you is a year old is not new.
- **AI users can notify people** — `wiki_list_people` and `wiki_mention_users`. An AI could
  always type `@Alice`, but nothing told it who exists, so it could not spell the name; and a
  model does not reach for a capability it has not been told about. Both prompts now explain
  the sigils and the tools, and **"me" resolves**: a chat prompt states that messages carry
  their author's name, and a job prompt names its requester. `/aiJob … mention me when done`
  previously had no referent at all.
- **The daily digest reports mentions**, from its own 24-hour window rather than the panel's
  marker, and will send on mentions alone.
- **Admin can see what the wiki tells an AI user** — the chat context, the job context and
  the tool list, assembled by the same functions the live requests call rather than a copy
  that drifts.

### Changed
- **Agent jobs run the shared tool set.** `run_agent_job()` carried its own three-tool copy,
  so a job could not search, tag, write JSON, read the graph or notify anyone, and its
  `wiki_list_pages` returned bare paths where chat and MCP returned objects. All three entry
  points now offer the same ten tools. Two consequences for existing scheduled jobs:
  `wiki_list_pages` returns objects, and an editor-role job can now tag pages, write `.json`
  and post mentions — so a broad prompt has more reach than it did.
- **`find_git_root()` and `git_auto_commit()` take an optional explicit space.** They read
  `$space_dir` from global scope, which is correct in a web request and wrong under cron,
  where it holds whichever scheduled job ran last and nothing at all during a one-off. A file
  written by a job could be committed into another space's repository. Web callers are
  unchanged; `ai_core`'s three byte-identical private copies are gone with 132 lines.
- **The AI user instructions box says who it is for.** It is a REST guide for an *external*
  agent holding that user's token, but sat among the fields for the internal LLM saying "copy
  into the system prompt of your AI agent" — which reads as a description of what the wiki
  sends. Retitled *API Agent Instructions*, with the built-in view beside it.
- Move and copy no longer offer a read-only Space as a destination.

### Fixed
- **Saving a page reported itself as an external change.** Within ten seconds of a save,
  "Page changed on disk — reloaded" appeared and the page reloaded under the author: the
  watcher baselines from the load and `savePage` never updated it, so it read the author's own
  write as somebody else's. Present since the watcher shipped in 2026.7.39. The save response
  now reports what it wrote — after the `.json` re-encode, and after a `clearstatcache()`,
  since PHP would otherwise hand back the mtime from before the write.
- **A mermaid syntax error no longer takes over the bottom of the page**, and the inline
  message no longer quotes the whole diagram back at you when the source is right below it.
- A rename now carries its Space's settings and its external-change stamp; the stamp used to
  be left behind on every rename.


## [2026.8.3] — 2026-08-29

Space-level administration. A Space could be created and renamed, but not frozen and not
dissolved — so an archived project stayed exactly as editable as a live one, and a Space made
by mistake stayed forever. Both now sit behind a gear beside the Space selector, administrators
only. Japanese joins the interface languages, and the English string literals still scattered
through the UI were routed through the translation layer.

### Added
- **Space settings** — a gear beside the Space selector, for administrators only, holding the
  three operations that shape a wiki rather than edit it: rename, read-only, and merge into
  another Space. The per-row rename pencil in the switcher stays where it was, so editors keep
  the rename they already had.
- **Read-only Spaces.** A frozen Space cannot be changed by anyone — administrators, AI Users,
  API Accounts and MCP clients included. A mode that exempted the people most likely to edit by
  reflex would not be a freeze; turning it off in the dialog is the way back. Chat threads stay
  readable with the message box hidden, and a move or copy *into* a frozen Space is refused as
  the write it is. Scheduled and one-off agent jobs targeting one are skipped rather than run
  and discarded. The flag lives in `WIKI_SYSTEM_DATA/spaces.json` — configuration, not content,
  so it is never committed to a Space's git repository and never travels with an rsync of
  `PAGES_DIR`.
- **Merge a Space into another, then delete it.** The dialog shows the real plan before anything
  moves: how many pages travel, how many are renamed, how many identical duplicates are dropped.
  Same-named folders merge and only colliding *files* become `name (1).ext` — renaming the
  folder instead would split related content across two trees because one file inside it
  clashed. A colliding file identical to its twin is dropped rather than duplicated, which
  matters more than it sounds: every Space is scaffolded with the same `templates/` and start
  page, and the first real merge dropped twelve of them. Attachments and a cached `.drawio.svg`
  follow their page through a rename. Page ids are carried across when free in the target index,
  along with tags and authorship, so `?pageid=` links, `{include:ID}` transclusions and
  wikilinks pointing into the merged Space keep working.
- **A Space that is its own git repository refuses to be merged away**, because deleting it
  would take its history with it — remove the repository first. A repository at `PAGES_DIR` is
  fine: both Spaces already share that history, and the merge lands as a single commit.
- **Japanese (日本語)** — the ninth interface language, selectable from the sidebar globe and My
  Preferences.

### Changed
- **The interface is translated wherever it was still English.** Labels, placeholders, titles,
  confirm prompts and error fallbacks moved to `t()` across the admin panel, the login and auth
  pages, the editor toolbar and its insert menu, the list views and their modals, chat, the JSON
  viewer, the file-tree panes and the advanced-search builder. All nine locales are at key
  parity, so the fallback to English is a safety net rather than the plan.
- **A Space rename now carries the Space with it** — its settings entry and its
  external-change stamp, the latter of which was previously left behind on every rename.
- **Move and copy no longer offer a read-only Space as a destination**, rather than letting the
  write be refused after the fact.

### Fixed
- **A mermaid syntax error no longer takes over the bottom of the page.** Mermaid rendered its
  own "Syntax error in text" graphic into a container it appends to `<body>` and left it there,
  so a page with one bad diagram grew a detached bomb SVG and a leaked `<style>` block far from
  the block that caused it. The inline message beside the source — which is fixable in place —
  is unchanged.
- **A mermaid error no longer quotes the diagram back at you.** The message carried either the
  whole block after "…for text:" or a snippet with a caret ruler, both sitting directly above
  the same source in the page. The diagnosis is kept; the echo is not.


## [2026.8.2] — 2026-08-21

Obsidian compatibility. A vault could already be dropped into `PAGES_DIR` and be indexed, but
its pages rendered wrong; the syntax below is now understood, so moving notes in — or back out
— no longer means rewriting them. Nothing changes on disk: all of it is resolved while the page
renders, so a page keeps the exact text Obsidian wrote.

### Added
- **Callouts.** A blockquote beginning `> [!note] Title` renders as a coloured admonition box —
  14 types across 6 tones, all of Obsidian's aliases (`caution`, `tldr`, `check`, …) and
  GitHub's uppercase forms. `-` after the type starts it collapsed, `+` makes it foldable but
  open, and the unsaved-work dot and the fold chevron share the same click target. The syntax is
  identical in Obsidian, GitHub and GitLab, so a page carrying one displays correctly in all
  three. Five entries in the editor's **Insert** menu, and a plain blockquote or an unknown type
  is left exactly as it was rather than rendering a broken box.
- **Wikilinks and embeds** — `[[Page]]`, `[[Page|alias]]`, `[[Page#Heading]]`, `[[#Heading]]`,
  `![[Page]]`, `![[Page#Heading]]` and `![[image.png|300]]`. Names resolve by exact relative
  path, then by unique filename, then by shortest path, ignoring case and an optional extension;
  a name that matches nothing renders as a marked *unresolved* link instead of a dead one.
  Resolution uses the file tree the sidebar has already loaded, so a whole page of links costs
  no extra requests.
- **Embeds are framed like Obsidian's** — the embedded page's name as a clickable heading, with
  a rule down the left margin showing how far the transcluded content reaches. A hand-written
  `{include:ID}` deliberately keeps no chrome: that tag exists to quote a fragment as though it
  were part of the host page.
- **`![[Page#Heading]]` embeds one section**, from that heading to the next of the same or a
  higher level, reusing the existing transclusion machinery and its circular-reference guard.
- **Backlinks and the knowledge graph see wikilinks.** Both previously scanned page bodies for
  `pageid=`, so an imported vault would have rendered its links correctly and still shown no
  backlinks and nothing in the graph. A wikilink names its target instead of carrying its id, so
  the server resolves them the same way the renderer does.
- **A rename offers to update the wikilinks that named the page** — counted first, so the
  question says how many links in how many pages, and applied only if you agree. Aliases,
  headings and embed markers are preserved, a path-shaped target stays a path, and a link shown
  as an example inside a code fence is never touched. This is the one place wikilink source text
  is modified.

### Changed
- **Wikilinks are within-space only, and read-only.** `[[Space:Page]]` would look like Obsidian
  compatibility while being a dialect Obsidian shows as broken, which throws away the
  portability that is the whole point; cross-space links keep the `?pageid=ID&space=Name` form,
  which reaches another Space and survives a rename. For the same reason the editor keeps
  inserting that form: a wikilink is rendered faithfully but never propagated.
- **Page tabs: the "+" tab is gone** and long labels are abbreviated to 20 characters, keeping
  the end — the filename — and marking the cut with a leading ellipsis. The full relative path
  is on the tab's tooltip. Removing the button also retired the blank-tab handling it was the
  only entry point for, so the module is smaller than it was before the feature existed.
- **AI users are told about callouts and wikilinks** as well as the diagram, transclusion and
  placeholder syntax, and are told to keep all of it verbatim when editing a page that uses it —
  otherwise an AI asked to tidy a page has no reason to treat a mermaid block or a `[[link]]` as
  meaningful. The built-in instructions grew by about 70 tokens per request after trimming;
  `/debug` and the agent-job run logs price them under *Wiki instructions*.
- **The transcluded content of a page now renders its own wikilinks**, resolving image embeds
  against the *included* page's attachments rather than the page being viewed.

### Fixed
- **Insert put block constructs on the wrong line.** With the caret at the end of a line,
  `Insert → Callout` produced `Some text> [!note] Note`, which renders as literal text; the same
  applied to the mermaid skeletons, the `{toc}` tag, and — beyond the Insert menu — the code
  fence (`Alt+C`), table (`Alt+T`) and link-reference (`Alt+K`) shortcuts. Block insertions now
  add only the newlines that are actually missing, on both sides, so nothing is stacked up when
  the caret is already on an empty line and nothing is indented at the top of an empty page. The
  caret still lands inside the new block, ready to type.
- **The Blockquote button now prefixes every selected line**, like the list buttons beside it,
  instead of inserting a single `> ` wherever the caret happened to be.
- **A page link with a heading anchor could fail to switch Space.** `?pageid=5&space=Docs#intro`
  was parsed as a whole query string, so the Space came out as `Docs#intro` and did not match.


## [2026.8.1] — 2026-08-19

> **Version numbering:** the middle number is the month and the last resets with it, as it did
> from `2026.6.3` to `2026.7.1`. That rollover was missed on 1 August, so `2026.7.35`–`2026.7.45`
> were released during August under a July number. Those tags are left as published; numbering
> resumes tracking the month here.

### Added
- **Page tabs.** Open pages sit in a strip above the page header: click to switch, `×` or
  middle-click to close, drag to reorder, `+` for an empty tab, and right-click for close
  others / close to the right / close all. A plain click from the file tree reuses one italic
  *preview* tab so browsing does not leave fifteen tabs behind; editing it, double-clicking it,
  or opening with Ctrl/Cmd+click makes it permanent. Switching tabs never asks about unsaved
  work — the edit is held and put back, with the caret where you left it — and closing a tab is
  the only action that prompts. Tabs are per Space and kept in memory, so leaving a Space and
  returning restores that workspace; the open list is saved so a browser reload reopens where
  you were rather than the start page. Renaming or moving a page follows its tab, deleting one
  closes it. `Alt`+`1`–`9` jumps, `Ctrl`+`Alt`+`←`/`→` steps. Hidden in the mobile layout.

  *Inspired by the tabs in [Kai-Syuan Tseng's plugin bundle](https://github.com/kaisyuan-tseng/astucia-wiki-plugins),
  which is where the behaviour was worked out. Written independently rather than adopted: that
  version caches each tab's rendered DOM, which needs hand-maintained lists of every container,
  button and state field plus a bespoke rebuild path per content type. A tab here holds only
  identity plus a small resume record and re-renders through the normal page load, so new
  content types need no per-type handling and nothing has to be kept in step by hand.*

- **AI users are told which Markdown extensions this wiki has.** The built-in instructions now
  state that a ` ```mermaid ` block renders as a diagram, that `{include:ID}` embeds another
  page, that `{toc}` builds a table of contents, and that `{filename}` / `{lastUpdated}` are
  substituted on render — for chat replies, agent jobs and API accounts alike. Previously an AI
  asked for a sequence diagram produced ASCII art, because nothing told it the feature existed;
  worse, an AI asked to tidy a page containing a mermaid block or a transclusion had no reason
  to think either was meaningful and could remove it. The instructions now say to preserve them
  verbatim. Adds about 108 tokens to the built-in prompt, visible in `/debug` and in agent-job
  run logs under *Wiki instructions*.

### Fixed
- **Navigation no longer flickers.** Every page load hid the outgoing pane and revealed the
  incoming one *before* fetching the content, and that wait is a point at which the browser
  paints — so each navigation drew twice: an empty pane (Markdown → list, Markdown → data
  page), or the pane's previously rendered content (list → Markdown briefly showed the last
  Markdown page you had open), before the real page arrived. The content is now read first and
  the swap happens once. For the same reason `{include:ID}` transclusions are resolved before
  the swap, and a page that cannot be read leaves the current one on screen with a toast
  instead of half-switching to a page that is not there. This affected every way of opening a
  page, not only tabs; tabs made it obvious by making switching a single click.


## [2026.7.45] — 2026-08-18

### Added
- **The published image now runs on ARM as well as Intel** — `madsrotwitt/astucia-wiki` is a multi-architecture manifest for `linux/amd64` and `linux/arm64`, so `docker run` works unchanged on an Apple Silicon Mac, a Raspberry Pi or an ARM cloud instance instead of failing with "no matching manifest". Docker selects the right variant automatically; the tags are unchanged.

### Fixed
- **`.dockerignore` was shipped inside the image** — harmless (a list of filenames, no secrets) but it is build tooling that has no business in a runtime image. It, the `Dockerfile` and `docker-compose.yml` are now excluded from the build context outright rather than deleted afterwards. `docker/` stays in the context because the Dockerfile reads the container config template from it, and is still removed from the image after the copy.


## [2026.7.44] — 2026-08-18

### Added
- **The project's licence is now declared where tools and people look for it** — a `LICENSE` file with the verbatim GNU GPL v3 text, the copyright notice (`Copyright (C) 2026 Mads Rotwitt`) with the FSF's recommended wording in the README, a `license` field in `composer.json`, and a short notice at the top of all 94 source files: the copyright line plus a pointer to the full text, which is what the GPL's own "How to Apply These Terms" appendix asks for. Previously the licence was stated only on the website, so the repository showed none and a distributed copy carried no statement at all.
- **`docker/build.sh` — one command that builds and tags the image correctly.** It reads the version from `VERSION` and the revision from git, so a built image cannot claim to be something it is not, and it fails if a tag it was asked to create does not exist afterwards.
- **Multi-architecture images** — `PLATFORMS=linux/amd64,linux/arm64 IMAGE_NAME=you/astucia-wiki ./docker/build.sh` builds for Intel and ARM and pushes a manifest list, since a multi-platform image cannot exist in the local image store. The prerequisites (a container-driver buildx builder, QEMU registered for the non-native architecture) are checked before the build starts, with the one-time commands printed, rather than failing after several minutes of emulated compilation.

### Changed
- **Deployments pin an immutable image tag instead of `:latest`.** Each build now produces three tags with deliberately different mutability: `:sha-<commit>` never changes meaning and is the one to deploy; `:<version>` moves if a release is rebuilt; `:latest` moves on every build and exists for discovery. This was not theoretical — during development a single version tag came to name four different images, and a container started from `:latest` reported that tag while it had since moved to a different image, so nothing on the container said what was actually running. `build.sh` records the tag it produced and `create_container.sh` and `docker-compose.yml` deploy that, so a container names the exact build it runs. A build from a working tree with uncommitted changes gets no immutable tag at all, because those edits have no identity to name.


## [2026.7.43] — 2026-08-18

### Added
- **Image metadata for the Docker image** — the standard OCI labels (title, description, project URL, source repository, documentation, licence, version, revision), so a published or distributed image identifies itself and links back to where it came from. Version and revision are build arguments: `docker build --build-arg VERSION=$(cat VERSION) --build-arg REVISION=$(git rev-parse --short HEAD) .`. Left unset they read `dev` and `unknown` rather than a hardcoded number that would silently go stale; `/var/www/html/VERSION` inside the image remains authoritative either way. `DOCKER.md` documents the build and upgrade commands with the arguments included.


## [2026.7.42] — 2026-08-18

### Added
- **Docker support** — a `Dockerfile` and `docker-compose.yml` run the wiki as one container: nginx, PHP-FPM and cron under supervisord, on `php:8.3-fpm-alpine`, about 226 MB. PHP-FPM specifically, because AI replies call `fastcgi_finish_request()` to answer the browser and keep working in the background. Every piece of state — pages, users, the search index, logs — lives under a single `/data` mount, so a backup is an archive of one directory and replacing the container risks nothing. The build installs Composer dependencies in a separate stage and fails if a required PHP extension is missing, so a broken image is caught at build time rather than at runtime.
- **Configuration entirely from the environment** — the container writes its own `config.php` on first start from environment variables, or leaves a mounted `config.php` alone. `docker/wiki.env.example` documents all 36 settings for use with `--env-file` or Compose's `env_file:`. Every constant the application reads without a `defined()` guard is always declared, so a missing value cannot produce a half-rendered page. The container also adopts the ownership of a bind-mounted content directory, so editing pages from the host with your own tools keeps working instead of the directory being taken over by the image's web user; sets the timezone for the system, PHP *and* cron from one `TZ` variable, since a mismatch would fire scheduled jobs at the wrong hour; and generates the crontab from `AGENT_JOB_RUNNER_INTERVAL_MINUTES`, the same value the application reads for the "your job starts in about N minutes" estimate, so the two cannot disagree.
- **`DOCKER.md`** — configuring and managing the container: settings, data layout, backup and restore, day-to-day commands, cron management, upgrades, troubleshooting, and next steps covering nginx with Let's Encrypt, authentication, git history for content, hardening, monitoring and splitting the container. `docker/create_container.sh` is a ready-to-edit run script.
- **A fresh install starts in a Space** — a wiki with no Spaces now creates one called `Main` and selects it, instead of leaving the interface pointed at the content root. This matters because once any Space exists the root is no longer selectable, so a page written there would be unreachable. The check runs before the start-page check, so the start page is created inside the new Space. A wiki whose pages already sit at the root is deliberately left alone rather than having them hidden behind a directory the interface cannot open.


## [2026.7.41] — 2026-08-17

### Added
- **Diagrams written as text in Markdown pages** — a fenced ```` ```mermaid ```` block renders as a diagram when the page is read, and stays plain text in the editor: sequence diagrams, flowcharts, state charts, ER diagrams and gantt charts. Deliberately implemented as part of Markdown rather than as a separate content type, so a diagram lives in the page it documents — it is covered by that page's version history, its labels are searchable through the normal index, and editing it is just editing the page. A page containing only a diagram can be embedded anywhere with `{include:ID}`, which is how one diagram is reused in several places. Draw.io files remain the better choice for anything drawn by hand.
- **Starter blocks in the editor's Insert menu** — *Sequence Diagram* and *Flowchart* insert a skeleton that already renders, so the result is visible immediately and can be edited down instead of written from memory. With text selected, the selected lines become the body of the block.
- **Diagrams in exported static sites** — the export renders Markdown server-side, which leaves the block untouched, so exported pages now carry their own renderer and show the same diagrams as the live wiki.

### Changed
- **A diagram with a syntax error shows the error and keeps its source visible** rather than dropping the block, so the mistake can be found and fixed in place. The renderer itself (~1 MB) is loaded from CDN only when a page actually contains a diagram, so pages without one are unaffected, and it runs with strict sanitisation because page content is user-authored.


## [2026.7.40] — 2026-08-16

### Fixed
- **The daily digest reported pages as changed that had not been touched in months** — the digest reads the `updated` timestamp from the page index rather than the file itself, and the external-change reconcile added in 2026.7.37 was stamping that field with the current time instead of the file's own modification time. Any index entry that predated timestamping (no `updated` field at all) therefore compared as "modified" the first time its Space was reconciled, and a page last edited in June was recorded as having changed today. Both the reconcile and `?action=indexfiles` now take the timestamp from the file, so a page's recorded `created` and `updated` reflect when it actually changed. This also makes the check self-stabilising: with `updated` equal to the file's mtime, the same page cannot be re-detected as modified on the next pass. Timestamps already written incorrectly are left as they are — they fall outside the digest's 24-hour window and no longer affect it.


## [2026.7.39] — 2026-08-16

### Added
- **The page you are viewing reloads itself when its file changes on disk** — following on from external change detection in 2026.7.37, which kept the index and the file tree current but left the open page showing stale content. A Markdown page, list or data page now re-renders in place within about ten seconds of being changed underneath by a `git pull`, an `rsync`, a script, another person or an AI, with a brief notice. The reload is in-place rather than a full navigation, so scroll position, the table of contents and the open panels are preserved. `.chat` threads already poll themselves; `.drawio` and `.search` pages are deliberately left alone, since re-initialising the draw.io embed mid-view is disruptive and a saved search's results are computed rather than read from the file.
- **A warning instead of a reload while you are editing** — unsaved work is never touched. If the file changes while the editor is open (or a list or data page has unsaved changes), the page is left exactly as it is and a notice explains that the version on disk has changed and that saving will overwrite it. Until now nothing detected this at all: saving performs no concurrent-change check, so an external edit could be discarded silently. The reload happens as soon as the edit is saved or cancelled. Localized across all eight languages.

### Changed
- **`get` and `file_mtime` also report file size** — `filemtime` has one-second resolution, so a write landing in the same second as a page load cannot be distinguished by timestamp alone, which is exactly what happens when an AI finishes writing a page as it is being opened. Both actions now return the byte size as well, and the viewer baselines its change detection against the response that rendered the page rather than a separate stat call, which would otherwise race with a write arriving immediately after the load and hide that change indefinitely. Existing callers that read only `mtime` are unaffected.


## [2026.7.38] — 2026-08-13

### Fixed
- **"AI is working" no longer spins forever when `/debug` is on** — with context debugging enabled, an AI reply left the progress dialog running indefinitely and the reply text never appeared, even though the debug report was posted to the thread. The chat poll fetches only messages with ids newer than the last one it holds, but a reply is delivered by rewriting its own pending placeholder in place — same id — so only a full refetch can see it. That refetch is triggered by the file's mtime, and because the reply and the debug report are written together in one operation, the poll saw the report's new id, took the incremental append path, advanced its mtime marker and returned: the placeholder stayed pending with no later write left to correct it, and the dialog closes only once nothing is pending. While a placeholder is outstanding the poll now always takes the full refetch, in both team chat and page chat. Introduced in 2026.7.37.


## [2026.7.37] — 2026-08-13

### Added
- **Content changed outside the wiki is picked up automatically** — pages added, removed or edited directly on disk (an `rsync`, a `git pull` on the content repository, a script, a desktop editor over a network share) are now reconciled with the page index, the knowledge-graph cache and the SQLite search index without anyone pressing reindex, and the file tree refreshes on its own. There is no cron entry and no filesystem watcher to install: the check runs as part of ordinary requests, is skipped unless `INDEX_SYNC_INTERVAL_SECONDS` (default 30) has elapsed since the last look, and is serialised between simultaneous visitors so two of them can't corrupt the index between them. It also runs on the MCP endpoint, so an AI client reconciles before it reads instead of answering from a stale index. Existing pages keep their IDs, so `?pageid=` links and bookmarks survive; bulk drift (hundreds of files at once) is applied in a single index write.
- **Chats, saved searches and data pages get stable page IDs** — the index has only ever tracked `.md`, `.drawio` and `.list`, so a `.chat`, `.search` or `.json` page created outside the wiki never received an ID and could not be linked by `?pageid=`. All six content types are now indexed, by both the automatic reconcile and `?action=indexfiles`, from one shared definition so the two can never disagree about what counts as content.
- **Copy a chat message** — a Copy button in the message hover toolbar puts the full message text on the clipboard in one click, alongside Reply and the save-to-page actions.
- **`/debug` — see what a chat reply actually costs** — toggling it per thread appends a compact report after each AI reply: estimated tokens for every block of the request (built-in wiki instructions, the attached page injected by page chat, the AI User's own system prompt, MCP guidance, tool schemas, chat history), the AI User's Max Tokens ceiling, and the token counts the provider actually reported for every call in the agentic loop. The per-call figures make the real cost driver visible — each pass re-sends the whole payload plus every tool result so far — and a reply that lands exactly on the Max Tokens ceiling is flagged as probably truncated. Debug reports are excluded from the AI's own context window and don't consume a "last N messages" slot; turning debug off removes them from the thread.
- **Agent-job run logs report their context and token usage** — the same accounting is written to every scheduled and one-off `/aiJob` run log, unconditionally (a run log is read precisely when something looks wrong), including on failed runs, where the breakdown next to a context-length error is the useful part. Extended-reasoning runs report the ceiling actually sent and note that it was raised above the configured value.

### Fixed
- **AI Users no longer save new pages in unpredictable places** — the system prompt named the Space but never the folder, so "write this up as a page" produced a plausible-looking guess such as `Notes/…`. Chat replies now state the current folder (derived from the chat's own location) and one-off `/aiJob` runs derive it from the thread they were queued in; scheduled jobs fall back to the Space root. The `wiki_write_page` and `wiki_write_json` tool descriptions carry the same instruction, since that is the text in front of the model at the moment it picks a path — the old example path was itself inviting the invented `Notes/` folder.
- **Deleting a Space's start page no longer recreates it** — `Main.md` was silently rewritten on the next load, so deleting it appeared to do nothing. A Space without a start page now shows an empty page with a short hint instead.
- **Action buttons in Settings could disappear** — leaving the AI Users, API Accounts, Agent Jobs or MCP Servers tab with a form open (by switching tabs or closing Settings, rather than Cancel or Save) left "+ New …" hidden, with only a full page reload bringing it back. The button's visibility now belongs to the list view itself, so every path back to the list restores it.
- **Agent-job run logs wrap instead of scrolling sideways** — the log viewer forced a horizontal scrollbar through prompts and model output; long unbroken tokens such as URLs and JSON tool arguments now break too.


## [2026.7.36] — 2026-08-12

### Added
- **One-off AI Agent Jobs from the chat prompt** — `/aiJob #AiUser your request` queues a single long-running, reasoning-heavy job instead of waiting for an inline chat reply. Submitting only confirms acceptance ("job accepted — {name} starts it in about N minutes"); the request and a queued placeholder appear in the thread immediately, and the answer replaces the placeholder as a normal chat message when the job finishes. Use it for work that is too slow or too involved for a chat turn — the AI User is asked to reason at length, so a job can take many minutes. The AI User is mandatory and explicit (no fall back to the current chat focus, so an expensive background job never runs on a guess), and the `#` autocomplete narrows to AI Users on an `/aiJob` line. Editors and admins only; three queued jobs per person. Executed by the existing `run_ai_agent_jobs.php` cron runner — no second crontab entry — at most three jobs per tick so a burst can't overrun the cron window, with per-job logs under `LOG_DIR/agent-jobs/_oneoff/` and failures emailed to `ADMIN_EMAIL`. If the runner hasn't checked in, the estimate is replaced by an honest "the job runner does not appear to be running" rather than a time that will never come. Localized across all eight languages.
- **Email me when my AI jobs finish** — an opt-in in My Preferences (shown when email is configured) that mails the result of a `/aiJob` request. The result is always posted back into the chat thread as well; if that thread was renamed or deleted while the job was queued, the email is sent regardless of the opt-in so a completed job is never silently lost.
- **One-off jobs in Admin → AI** — a read-only table of recent `/aiJob` jobs (queued time, requester, AI User, request, state, elapsed) with an in-panel log viewer, a badge for a result that could only be delivered by email, and a job-runner health line that says plainly when the runner has never checked in or has gone stale.
- **Per-model LLM request rules** — `llm_providers.json` gains a `model_rules` section describing, per model rather than per provider, which tuning parameters it accepts: sampling (`temperature`/`top_p`/`top_k`), how to ask for extended thinking (adaptive vs. a fixed token budget), and effort/reasoning-effort support. One provider endpoint can serve models that disagree about all of these, so the decision belongs to the model. Matching ignores gateway prefixes and version tags, so `claude-opus-5`, `anthropic/claude-opus-5`, `us.anthropic.claude-opus-5` and `claude-opus-5@20260101` all resolve to the same rules. Adjusting or adding a model is a JSON edit, no code change; an unlisted model still works because every request also retries once without a parameter the API rejects.

### Fixed
- **Newer Anthropic and OpenAI reasoning models no longer fail outright** — Claude Opus/Sonnet 4.7 and later reject `temperature`, `top_p` and `top_k` with a 400, and OpenAI's o-series/GPT-5 accept only the default temperature, but every request built by the wiki sent a temperature unconditionally, and the "retry without it" recovery was explicitly skipped for the Anthropic family. Any AI User pointed at a current Claude model therefore failed on every chat reply, agent job, and "Explain selection". All three payload builders now share one model-aware helper, and the retry applies to every provider family. The error matcher also learned the phrasings these APIs actually use ("Extra inputs are not permitted", "does not match any of the expected tags") which the old one missed.
- **Fresh installs from `config.php.txt` crashed on the Admin → Users pane** — the template never defined `AUTHENTICATION`, which `index.php` and `auth.php` read unconditionally, so a new install following the documented setup hit `Undefined constant "AUTHENTICATION"` and a half-rendered page. The template now carries it with all four values documented (`off` / `oidc` / `otp` / `both`, defaulting to `off` to preserve the previous open-access behaviour) and derives the legacy `AUTHENTICATION_ENABLED` shim from it, so the two can no longer disagree.
- **Chat "Load older messages" failure showed a raw translation key** — `chat.load-older-failed` was referenced but never defined in any locale, so a failed load displayed the key itself instead of a message. Added in all eight languages.

### Changed
- **README documents agent jobs and their crontab requirement** — both scheduled and one-off jobs, the cron entry they depend on (without it, scheduled jobs never fire and one-off jobs are accepted but never start), and the fact that `AGENT_JOB_RUNNER_INTERVAL_MINUTES` must match the cron interval because the "starts in about N minutes" estimate is derived from it. The Authentication section now describes the four `AUTHENTICATION` modes instead of the derived shim, and the language list covers all eight interface languages.

## [2026.7.35] — 2026-08-03

### Fixed
- **MCP tool calls to stateful (Streamable HTTP) servers now work** — invoking a tool on an MCP server that requires a session (e.g. a Brave Search server) failed with `Bad Request: Server not initialized — code -32000`, even though listing its tools worked. The outbound MCP client was stateless: it never sent `initialize`, never captured the `Mcp-Session-Id` response header, and never carried it on the actual call. The client now performs the full Streamable HTTP handshake (initialize → capture session → `notifications/initialized` → the real call with the session header) for both tool listing and tool calls, and the admin "Test Connection" path uses the same logic. Stateless servers are unaffected — they return no session id, so no session header is added and the call proceeds as before; a server that doesn't support `initialize` falls back to a direct call. Fixes chat `#mention` tool calls, the MCP Tool Explorer, and advanced-search MCP sources.

## [2026.7.34] — 2026-07-30

### Added
- **Two new interface languages: Simplified Chinese (简体中文) and Hindi (हिन्दी)** — selectable from the sidebar language dropdown and My Preferences. Each covers the same ~440 UI strings as the other translated languages, with the remaining admin/help strings falling back to English, matching existing locale behaviour.

## [2026.7.33] — 2026-07-29

### Fixed
- **AI chat focus no longer silently stops working** — reopening a chat where an AI User was pre-selected (the "Chatting with {name}" focus chip is shown) could silently post plain messages that never reached the AI, so nothing happened. The chip is drawn from persisted focus state, while the send path re-resolved the name against the live user list and dropped the routing on a miss with no feedback. Two fixes: the user list is no longer cached as empty after a transient load failure (which had permanently broken AI-user lookups until an admin action refreshed it), and an unresolvable focus now surfaces a toast instead of quietly posting an un-routed message. Applies to both team chat and page chat.

## [2026.7.32] — 2026-07-26

### Added
- **AI User system prompt from a Markdown page** — an AI User's system prompt can optionally be sourced from an existing Markdown page instead of the inline textarea, so Editors (not just admins) can view and edit the AI's instructions as an ordinary, Git-versioned wiki page. Chosen via a Space → Folder → page picker lightbox (move/copy style); falls back to the inline text when unset, missing, or empty. Applies to agent jobs and team/page chat. Localized across all six languages.
- **Sidebar toggle hotkey** — press **`s`** to show/hide the sidebar while reading any page (suppressed in the Markdown editor and when typing in a field). The shortcut is shown in the toggle button's tooltip.

### Fixed
- **Chat hover-action toolbar no longer overlaps the first line of the message** — raised it 10px so it clears the text.

## [2026.7.31] — 2026-07-25

### Added
- **View Agent Job run logs in the admin panel** — the job edit form now has a "Run logs" section: a dropdown of recent runs (timestamp + size) and a log viewer, so per-run output is readable in the browser instead of only on disk. Backed by a new `admin_get_agent_job_logs` action that lists/reads files under `LOG_DIR/agent-jobs/<job>/` (path-constrained to the job's own directory; large logs tailed to the last 200 KB).

## [2026.7.30] — 2026-07-24

### Changed
- **Agent Job "Run now" runs detached instead of blocking the browser** — the run used to execute synchronously inside the admin request, so any job longer than the reverse proxy's timeout (typically 60s) returned a 504 in the UI and held the session lock. It now answers immediately, runs server-side (`fastcgi_finish_request` + `ignore_user_abort` + no time limit, session lock released), and the admin panel polls a new `admin_agent_job_status` endpoint for the result. The job survives closing the admin panel and no longer blocks editing or chatting; the poll caps at 15 minutes and a crash still records an error status.

### Added
- **Copy button for AI User service tokens** — a "Copy" button next to "Regenerate" copies the service token to the clipboard. Localized across all six languages.

## [2026.7.29] — 2026-07-22

### Added
- **Text-selection actions on read-mode Markdown pages** — selecting text in a rendered page now shows a floating toolbar with six actions: **Quote in chat** (insert the selection as a Markdown blockquote into the page chat, creating the chat if needed), **Ask AI** (prefill the composer with the quote + an AI @mention, ready for your question), **Copy**, **Search wiki** (run a full-text search for the selection), **New page** (create a page titled from the selection), and **Explain** (an ephemeral AI tooltip that explains/defines the selection in 2–4 sentences). Backed by a new synchronous `ai_explain` endpoint. Localized across all six languages.

### Fixed
- **Text-emitted tool calls no longer leak into chat** — when a model (e.g. Qwen via vLLM) returns a tool call as plain text instead of the structured `tool_calls` field, the agentic loop now detects a tool-call-shaped JSON (bare, ` ```json `-fenced, or Hermes `<tool_call>`-wrapped) whose name matches an advertised tool, executes it, and continues — instead of posting the raw JSON as the reply. Guarded so normal replies are never intercepted.
- **Chat message hover no longer shifts the layout** — the per-message action buttons (reply, save, pin, react) moved out of the in-flow reaction bar into a floated overlay toolbar, so revealing them on hover adds no jump and no reserved space.

### Changed
- **The admin panel closes only via its "×" button** — a stray click on the backdrop no longer dismisses it, protecting unsaved edits in its forms.

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
