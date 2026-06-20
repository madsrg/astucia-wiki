# Astucia Wiki

A flat-file, self-hosted wiki for teams. No database required — all content is stored as plain files on disk.

## What it is

Astucia Wiki combines several content types in one place:

- **Markdown pages** — rich editor with inline and classic modes, keyboard shortcuts, `{include:ID}` transclusion, and live preview
- **Diagrams** — embedded draw.io editor with automatic SVG preview generation
- **Structured lists** — spreadsheet-style tables with typed columns, named views, filters, and CSV/XML/JSON export
- **Team chat** — per-file chat threads with real-time polling, emoji reactions, pinned messages, and #mentions
- **AI assistants** — per-space AI users (Claude, GPT, or any OpenAI-compatible model) that respond to #mentions in chat and can read and write wiki pages
- **AI agent jobs** — scheduled or on-demand jobs that run an AI user against a prompt and optionally write the result to a wiki page
- **File attachments** — drag-and-drop upload stored per-page
- **Full-text search** — keyword search and tag cloud across all content
- **Spaces** — isolated workspaces for different teams or projects, with per-user access control
- **Localization** — UI available in English, Danish, Swedish, Spanish, French, and German; users pick their language from the sidebar
- **Git integration** — optional version history per file; pages and diagrams auto-commit on save, chats and lists support manual snapshots

Authentication is optional. When enabled it uses OIDC (tested with Auth0) with three roles: admin, editor, and reader.

## Quick start

```bash
git clone https://github.com/madsrg/AstuciaWiki.git
cd AstuciaWiki
composer install
cp config.php.txt config.php   # edit config.php before starting
php -S localhost:8000
```

Open `http://localhost:8000`. No database, no build step.

### Configuration

`config.php.txt` is a template committed to the repository. Copy it to `config.php` and fill in your values before first use. Because `config.php` is excluded from version control, running `git pull` to update the wiki will never overwrite your live configuration.

The minimum required settings are `PAGES_DIR` (where wiki content is stored) and `APP_TITLE`. Everything else — authentication, email, logging — is optional and disabled by default.

## AI assistants and system users

The admin panel (gear icon, **Users** tab) lets you create two kinds of non-human users.

### AI users

An AI user is a bot that participates in chat threads. When a regular user #mentions it, the wiki sends the conversation to a language model and posts the reply automatically. The bot's role controls page-writing access: an **editor** AI user can create and overwrite pages; a **reader** AI user can reply in chat and read pages but cannot write.

**Configuration per AI user:**

| Field | Description |
|-------|-------------|
| Name | The #mention handle that triggers the bot |
| Provider | `openai` (default) or `anthropic` |
| API URL | Leave blank for the provider default, or set a custom endpoint for self-hosted / compatible models |
| Model | e.g. `gpt-4o`, `claude-opus-4-7`, or any model string accepted by the endpoint |
| API key | Stored server-side only — never sent to the browser |
| System prompt | Optional instructions that set the bot's persona or constraints |
| Context messages | How many recent chat messages to include in each request (default 20) |
| Max tokens | Maximum length of the model's reply (default 4096) |
| Temperature | Sampling temperature, 0–2 (default 0.7) |

AI users also receive a **service token** (`wk_ai_…`) that can be used to call the API on their behalf from external scripts.

AI users can use three tools the wiki exposes:
- **`wiki_list_pages`** — lists all page paths in the current Space
- **`wiki_read_page`** — reads the content of any `.md`, `.list`, or `.chat` file by ID
- **`wiki_write_page`** — creates or overwrites a `.md` page (editor role only)

### System users

A system user is a headless account for external integrations (scripts, CI pipelines, other services). It has no AI config — it authenticates via a **service token** (`wk_sys_…`) sent as `Authorization: Bearer <token>` and can call any API action its role permits.

Create a system user in the admin panel, copy the generated token, and use it in your integration. Tokens can be regenerated at any time.

## Git version history

If the content directory (or `PAGES_DIR` itself) is a git repository, Astucia Wiki will automatically commit changes to it:

- **Pages and diagrams** auto-commit on every save (per-file toggle to disable).
- **Chats and lists** are off by default — enable tracking per file and use the **Commit snapshot** button in the header to save a manual checkpoint whenever a conversation or dataset is worth preserving.

The clock icon in the header opens the full commit history for the current file.

To initialise git tracking for your content, run as the web-server user (typically `www-data`) so that subsequent auto-commits have the right ownership:

```bash
sudo -u www-data git -C /path/to/your/PAGES_DIR init
sudo -u www-data git -C /path/to/your/PAGES_DIR add .
sudo -u www-data git -C /path/to/your/PAGES_DIR commit -m "Initial content"
```

## Localization

The UI is available in **English, Danish, Swedish, Spanish, French, and German**. Each user picks their preferred language from the globe icon in the bottom-left sidebar; the preference is stored in the browser. Adding a new language requires one new file in `modules/i18n/locales/` — copy `en.js`, translate the strings, and add the language code to `SUPPORTED_LANGUAGES` in `modules/i18n/index.js`.

## Documentation

Full documentation, feature guides, and a step-by-step installation guide for Debian with Nginx and PHP are available at:

**[https://astucia.wiki](https://astucia.wiki)**

## Tech stack

- **Backend:** PHP 8.0+, no framework, no database
- **Frontend:** Vanilla ES modules, no bundler
- **Auth:** OIDC via `jumbojett/openid-connect-php` (optional)
- **Email:** SendGrid or Mailgun via HTTP API (optional)
- **Diagrams:** Embedded [draw.io](https://www.diagrams.net)
- **Markdown:** [marked.js](https://marked.js.org) (CDN)
- **Static export:** `export_static_site.php` — generates a self-contained HTML site from all pages
