# Astucia Wiki

If you like Astucia Wiki, please consider giving it a star on
[github](https://github.com/madsrg/astucia-wiki)!

**What each version contains** is in the
[release notes](https://github.com/madsrg/astucia-wiki/releases) — one entry per release,
newest first. This page describes the current image.

A flat-file, self-hosted team wiki with AI assistants and an MCP server. **No database** —
every page is a file on disk, so a backup is an archive of one directory.

**Content** — Markdown pages with **Mermaid** and draw.io diagrams · structured lists and
JSON data pages · **YAML front matter** · page and diagram templates · attachments and file
libraries · **paste a screenshot straight into a page** · copy buttons on code
blocks · wikilinks and transclusion · full-text search with saved searches · a knowledge
graph · optional git version history · static site export.

**Tags with a taxonomy** — one tree of tags for the whole wiki: folders group, items are the
tags pages carry (`Department/Finance`). A tree editor to rename, move and merge — every page
using a tag follows — a tag picker beside the tag field, and a tagging mode from *free* to
*controlled* for organisations that need consistent classification. Export and import the
tree as JSON. The tag cloud groups nested tags by branch and stays manageable however many
tags you have.

**Page metadata that travels with the file** — a page may open with a `---` block of YAML,
as Obsidian, Hugo and Jekyll write it. The wiki keeps it out of the editor and out of
search, shows it on request, and puts it back byte-for-byte when the page is saved — so an
imported note keeps its metadata, and a page copied out of the wiki takes its own with it.
Per Space you can also let people **edit** those fields, have the wiki **maintain**
`created`, `createdBy`, `updated` and `updatedBy` itself, or keep a page's **tags** in its
`tags:`. A folder of the taxonomy can define a field's allowed values — `department:` takes
one of the items in Department — chosen from a dropdown and searchable as
`department:Finance`. All off by default: on a Space that mirrors somebody's vault, the
wiki does not touch the block at all.

**AI** — AI users that read and write wiki pages · one-off agent jobs, and scheduled ones
that run from every few minutes to once a year, or on any **cron expression** · team and
per-page chat · **AI memory**, so an AI keeps what it learns as ordinary pages you can read
and correct · **AI skills** — procedures an administrator writes once, in the open Agent
Skills format, for every AI user to follow · **LLM providers** set up once and shared by every AI user, so a key lives
in one place · avatars for AI users and people · an **MCP server** so other tools can use your wiki, and an **MCP client**
so your AI users can use theirs.

**Workflows** — "when this happens to a page, do that", set up by administrators: when a
page is created, updated, renamed, deleted or tagged, or a front-matter field such as
`status` changes, send an email, give an AI user an instruction, post in a chat, or update
tags and fields. One workflow can listen for several of these at once. Test one without
acting, or run it for real on a chosen page first.

**Teams** — Spaces with per-user access control, including read-only Spaces · OIDC login,
email codes with **Keep me signed in**, or both on one account · mentions, page comments and a daily digest email · an optional audit log · a REST API
with service tokens, stored only as a hash · UI in nine languages.

**Live updates** — a **Mercure** hub ships inside the image and is **on by default**, so
chats, pages, the file tree, mentions and job results arrive by push instead of polling.
`ENABLE_REALTIME=false` turns it off and every view falls back to its own timer — slower,
never broken. **Admin → Monitoring → Mercure** tells you whether it is working, and
**Wiki Info** lists every version this install is running.

> **This image bundles Mercure 1.1.5**, the current release, and runs on **PHP 8.5.12**, the
> security release of 21 October 2026. 1.1.5 is built with a Go release that stops trusting
> compromised intermediate certificates; 1.1.4 closed two authorization gaps in the hub; 1.1.0 fixed a request any signed-in user could send to make the hub
> allocate about 1 GiB of memory, on top of the earlier fixes in 1.0.2 (ten
> vulnerabilities, four high) and 1.0.3 (hardening against slow-connection attacks). The hub runs inside the image, so updating the image is the
> fix. The live update stream is never compressed.

## Quick start

```bash
docker run -d \
    --name astucia-wiki \
    --restart=always \
    -p 8080:80 \
    -v /srv/astucia-wiki/data:/data \
    madsrotwitt/astucia-wiki:2026.10.7
```

Open <http://localhost:8080>. A fresh install creates a Space called **Main** with a start
page in it — no setup wizard, no migrations.

> **`AUTHENTICATION` defaults to `off`, which means every visitor has full admin rights.**
> Fine on a private network; set `AUTHENTICATION=otp` or `oidc` before exposing it.

## All settings in one file

Rather than accumulating `-e` flags, download the annotated sample and edit it. It documents
every variable with its default, so it doubles as the configuration reference:

```bash
mkdir -p /srv/astucia-wiki
curl -o /srv/astucia-wiki/wiki.env \
  https://raw.githubusercontent.com/madsrg/astucia-wiki/main/docker/wiki.env.example
$EDITOR /srv/astucia-wiki/wiki.env

docker run -d \
    --name astucia-wiki \
    --restart=always \
    -p 8080:80 \
    -v /srv/astucia-wiki/data:/data \
    --env-file /srv/astucia-wiki/wiki.env \
    madsrotwitt/astucia-wiki:2026.10.7
```

Docker parses that file itself, not a shell: **do not quote values** (`APP_TITLE=My Wiki`, not
`APP_TITLE="My Wiki"`), and there are no inline comments — everything after the first `=` is the
value. Back the file up separately from the data volume; it may hold mail credentials.

## Tags

| Tag | Mutability |
|-----|-----------|
| `sha-<commit>` | **immutable** — one commit, one image. Pin this in production |
| `2026.10.7` | moves only if that release is rebuilt |
| `latest` | moves on every release |

The image carries OCI labels, so a running container can always tell you what it is:

```bash
docker inspect <container> --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
```

## Configuration

Everything is an environment variable; the container writes its own config on first start.

| Variable | Default | Notes |
|----------|---------|-------|
| `APP_TITLE` | `Astucia Wiki` | Shown in the header |
| `TZ` | `UTC` | **Set this.** Drives the clock, PHP *and* cron — otherwise scheduled AI jobs fire at the wrong hour |
| `AUTHENTICATION` | `off` | `off` / `otp` / `oidc` / `both` |
| `ANONYMOUS_ACCESS_ENABLED` | `false` | Read-only browsing when auth is on |
| `OTP_REMEMBER_DAYS` | `30` | How long "Keep me signed in" lasts for email-code logins; `0` turns it off |
| `APP_BASE_URL` | — | Public URL, so login redirects and share links are right behind a proxy |
| `SEARCH_ENGINE` | `sqlite` | FTS5 full-text index |
| `INDEX_SYNC_INTERVAL_SECONDS` | `30` | How quickly content changed on the host is noticed |
| `AGENT_JOB_RUNNER_INTERVAL_MINUTES` | `2` | Cron interval for AI agent jobs; also the ETA users are shown |
| `AGENT_JOB_RUNNER_SLOTS` | `2` | How many job runs may overlap, so one slow job does not block the queue |
| `DAILY_DIGEST_HOUR` | `7` | When the digest email is sent |
| `ENABLE_CRON` | `true` | `false` to run the two PHP cron scripts from the host |
| `ENABLE_REALTIME` | `true` | The built-in Mercure hub. `false` falls back to polling — slower, never broken |
| `REALTIME_TICKET_TTL` | `3600` | Seconds a subscriber ticket lives; revoking a Space takes effect within one TTL |

AI provider API keys are **not** set here — they are entered once in
**Admin → LLM Providers**, shared by every AI user, and stored in the data volume.

## Data

```
/data/pages    your content
/data/system   users, AI job queue, search index — never web-reachable
/data/logs     nginx, PHP and cron logs
```

Back it up with `tar czf wiki.tar.gz -C /srv/astucia-wiki/data .`, and restore by unpacking
it and starting a container. Page IDs survive, so links and bookmarks keep working.

**Editing content from the host is supported.** Bind-mount `/data/pages`, and the container
adopts that directory's ownership so your files stay yours. Add or change files with any
tool — `rsync`, `git pull`, your editor — and the wiki reconciles its index, search and file
tree on its own; the page you are reading reloads itself.

## Inside the image

One container: **nginx**, **PHP-FPM**, **cron** and the **Mercure hub** under supervisord, on
`php:8.5-fpm-alpine`. PHP-FPM specifically, because AI replies answer the browser and then
keep working in the background.

The hub is the [Mercure](https://mercure.rocks) reference server, shipped unmodified and
**AGPL-3.0** — its licence and copyright notice are in `/usr/share/licenses/mercure/`. It
listens on loopback only and is reached through nginx at `/.well-known/mercure`, so there is
no extra port to open and no CORS. Its signing key is generated into the data volume on first
start, never baked into a layer, so no two installations share one. Set
`ENABLE_REALTIME=false` and it is never started.

- **Docs:** [DOCKER.md](https://github.com/madsrg/astucia-wiki/blob/main/DOCKER.md) —
  configuration, cron, backup/restore, upgrades, nginx with Let's Encrypt, hardening
- **Source:** <https://github.com/madsrg/astucia-wiki>
- **Website:** <https://astucia.wiki>
- **Licence:** GPL-3.0-or-later. Copyright (C) 2026 Mads Rotwitt

Published for **linux/amd64** and **linux/arm64** — the same command works on an Intel
server, an Apple Silicon Mac or a Raspberry Pi.
