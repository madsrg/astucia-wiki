# Running Astucia Wiki in Docker

Configuring, running and maintaining the containerised wiki. Everything here was
executed against Docker 29.7 before being written down.

- [What the image is](#what-the-image-is)
- [Build](#build)
- [Run](#run)
- [Configuration](#configuration)
- [Where your data lives](#where-your-data-lives)
- [Backup and restore](#backup-and-restore)
- [Day-to-day management](#day-to-day-management)
- [Cron: scheduled AI jobs and the daily digest](#cron-scheduled-ai-jobs-and-the-daily-digest)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)
- [Next steps](#next-steps)

---

## What the image is

One container running three processes under supervisord:

| Process | Why |
|---------|-----|
| **nginx** | Serves static files, proxies PHP over FastCGI |
| **PHP-FPM** | The application. FPM specifically, because AI replies call `fastcgi_finish_request()` to answer the browser and keep working in the background |
| **crond** | Scheduled AI agent jobs and the daily digest |

Base image `php:8.3-fpm-alpine`, about **226 MB**. A wiki is an appliance: one image
that runs beats three services to wire together. Split them if your platform prefers
it — see [Next steps](#next-steps).

## Build

```bash
git clone <your-repo> AstuciaWiki && cd AstuciaWiki
docker build -t astucia-wiki:local .
```

The build installs the Composer dependencies in a separate stage and **fails** if a
required PHP extension is missing (`pdo_sqlite`, `curl`, `mbstring`, `fileinfo`,
`json`, `session`), so a broken image is caught here rather than at runtime.

### Stamping the image

The image carries OCI metadata — title, description, source, documentation, licence —
and two values worth supplying so a built image reports what it actually is:

```bash
docker build \
    --build-arg VERSION=$(cat VERSION) \
    --build-arg REVISION=$(git rev-parse --short HEAD) \
    -t astucia-wiki:local .
```

Without them the labels read `version=dev` and `revision=unknown`, which is honest but
unhelpful for anything you distribute. Inspect them with:

```bash
docker image inspect astucia-wiki:local \
    --format '{{range $k,$v := .Config.Labels}}{{$k}} = {{$v}}{{"\n"}}{{end}}'
```

`/var/www/html/VERSION` inside the image is always the authoritative version, whether or
not the build arg was passed.

For a multi-architecture image:

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t astucia-wiki:local .
```

## Run

### With a script (recommended for a single host)

`docker/create_container.sh` is ready to edit — set `DATA_DIR`, `ENV_FILE` and `PORT`
at the top and run it once. It is the same shape as the command below.

```bash
cp docker/wiki.env.example /srv/astucia-wiki/wiki.env
$EDITOR /srv/astucia-wiki/wiki.env
./docker/create_container.sh
```

### By hand

```bash
docker run -d \
    --name astucia-wiki \
    --restart=always \
    -p 8080:80 \
    -v /srv/astucia-wiki/data:/data \
    --env-file /srv/astucia-wiki/wiki.env \
    -m 1g \
    astucia-wiki:local
```

- **`--restart=always`** brings the wiki back after a reboot or a crash. Verified: killing
  the container's main process restarts it automatically and the wiki answers again.
- **`-v …:/data`** puts every piece of state on the host — pages, users, search index,
  logs. Delete or replace the container as often as you like; nothing is lost, and a
  backup is a `tar` of one directory.
- **`-m 1g`** caps memory. PHP-FPM children are allowed 512 MB each, so do not go much
  below 1 GB without lowering `memory_limit` too.
- **No `-it`.** The container is a daemon and needs no TTY; `-it` only matters for
  `docker exec`.

### With Compose

`docker-compose.yml` in the repository uses a named volume and an inline environment
block:

```bash
docker compose up -d --build
```

Point it at a host directory and the shared env file instead, if you prefer:

```yaml
services:
  wiki:
    volumes:
      - /srv/astucia-wiki/data:/data
    env_file: /srv/astucia-wiki/wiki.env
    restart: always
```

## Configuration

All settings are environment variables; the container writes its own `config.php` from
them on first start. `docker/wiki.env.example` documents all **36** of them — copy it,
edit it, pass it with `--env-file` or Compose's `env_file:`.

The ones worth setting on any real deployment:

| Variable | Default | Notes |
|----------|---------|-------|
| `APP_TITLE` | `Astucia Wiki` | Shown in the header |
| `TZ` | `UTC` | **Set this.** Drives the clock, PHP *and* cron — leave it and scheduled jobs fire at the wrong hour |
| `AUTHENTICATION` | `off` | `off` means anyone reaching the port is an admin. Use `otp` or `oidc` for anything exposed |
| `ANONYMOUS_ACCESS_ENABLED` | `false` | Read-only browsing when auth is on |
| `APP_BASE_URL` | — | Public URL; needed behind a reverse proxy so redirects and share links are right |
| `SEARCH_ENGINE` | `sqlite` | FTS5 full-text index |
| `INDEX_SYNC_INTERVAL_SECONDS` | `30` | How often to notice content changed outside the wiki |
| `AGENT_JOB_RUNNER_INTERVAL_MINUTES` | `15` | Cron interval for AI jobs; also the ETA users are shown |
| `DAILY_DIGEST_HOUR` | `7` | Hour for the digest email |
| `ENABLE_CRON` | `true` | `false` to run the scripts from the host instead |

**An env file is not a shell script.** Docker's parser is literal — all verified:

| You write | Value becomes |
|-----------|---------------|
| `APP_TITLE="My Wiki"` | `"My Wiki"` — **with the quotes** |
| `APP_TITLE=My Wiki` | `My Wiki` — spaces need no quoting |
| `SESSION_TIMEOUT=3600 # an hour` | `3600 # an hour` |
| `FROM=$APP_TITLE` | the literal `$APP_TITLE` |

Comments on their own line, starting with `#`, are fine.

Changing any value takes effect on recreate:

```bash
docker rm -f astucia-wiki && ./docker/create_container.sh
# or, with Compose:
docker compose up -d
```

**AI provider API keys are not set here.** They are entered per AI user in
**Admin → AI** and stored in `/data/system/users.json`.

**Already have a `config.php`?** Mount it and the container leaves it alone:
`-v /srv/astucia-wiki/config.php:/var/www/html/config.php:ro`.

## Where your data lives

```
/data/pages    your content            (PAGES_DIR)
/data/system   users.json, AI job queue, search.sqlite  (WIKI_SYSTEM_DATA)
/data/logs     nginx, PHP and cron logs (LOG_DIR)
```

`/data/system` is outside the web root deliberately — none of it should ever be
reachable over HTTP.

**File ownership just works.** The container adopts the owner of the mounted directory,
so files the wiki creates stay editable by you and your host user keeps write access.
Only set `PUID`/`PGID` to override that.

**Content belongs in a Space.** A fresh install creates a Space called `Main` and selects
it. Pages placed directly in `/data/pages` are not reachable from the UI once any Space
exists, and are not returned by full-text search — put them in a Space subdirectory. If
you have such an orphan, move it:

```bash
mv /srv/astucia-wiki/data/pages/Orphan.md /srv/astucia-wiki/data/pages/Main/
```

The wiki notices on its own within `INDEX_SYNC_INTERVAL_SECONDS` — no reindex step, and
an open page reloads itself.

## Backup and restore

Because `/data` is a plain directory, backup is a `tar`. Verified round-trip: archive,
delete the container *and* the data, restore, start — pages and their page IDs intact.

```bash
# Backup (safe while running; stop the container first if you want a strictly
# consistent SQLite search index — it is rebuilt automatically anyway)
tar czf wiki-$(date +%F).tar.gz -C /srv/astucia-wiki/data .

# Restore
docker rm -f astucia-wiki
rm -rf /srv/astucia-wiki/data && mkdir -p /srv/astucia-wiki/data
tar xzf wiki-2026-08-18.tar.gz -C /srv/astucia-wiki/data
./docker/create_container.sh
```

Using a named volume instead of a host directory? Same idea through a throwaway
container:

```bash
docker run --rm -v astuciawiki_wiki-data:/data -v "$PWD":/backup alpine \
    tar czf /backup/wiki-backup.tar.gz -C /data .
```

Worth backing up separately: your `wiki.env`, which holds mail credentials.

## Day-to-day management

```bash
docker ps                                   # is it up, is it healthy
docker inspect -f '{{.State.Health.Status}}' astucia-wiki
docker logs -f astucia-wiki                 # nginx, PHP and supervisord output
docker restart astucia-wiki
docker stats --no-stream astucia-wiki       # memory against the -m ceiling
```

The healthcheck calls the API through nginx and PHP, so a broken FPM socket or a fatal
error in `config.php` shows as `unhealthy` rather than as a container that is merely
"up".

A shell — the image is Alpine, so there is **no `/bin/bash`**:

```bash
docker exec -it astucia-wiki /bin/sh
docker exec -it -u www-data astucia-wiki /bin/sh    # as the web user
```

Logs are also in `/data/logs/` on the host: `nginx-access.log`, `nginx-error.log`,
`php-error.log`, `cron-agent-jobs.log`, `cron-digest.log`. The admin **Diagnostics**
pane reads the nginx ones.

## Cron: scheduled AI jobs and the daily digest

Two scripts run on a schedule: `run_ai_agent_jobs.php` (scheduled agent jobs plus the
one-off `/aiJob` queue) and `run_daily_digest.php` (the opt-in digest email).

**You do not manage this by editing a crontab inside the container.** The crontab is
generated by the entrypoint on every start, from the environment:

```
ENABLE_CRON=true
AGENT_JOB_RUNNER_INTERVAL_MINUTES=15    # -> */15 * * * *
DAILY_DIGEST_HOUR=7                     # -> 0 7 * * *
TZ=Europe/Copenhagen                    # both evaluated in this zone
```

A hand-edited `/etc/crontabs/www-data` survives until the next restart and is then
regenerated — verified. Change a schedule by changing the variable and recreating the
container. The interval deliberately comes from the same variable the application reads,
so the crontab and the "your job starts in about N minutes" estimate cannot disagree.

### Inspecting

```bash
docker exec astucia-wiki cat /etc/crontabs/www-data          # what is scheduled
docker exec astucia-wiki tail -20 /data/logs/cron-agent-jobs.log
docker exec astucia-wiki cat /data/system/agent_jobs_heartbeat.json

# run the agent-job runner right now, as the web user
docker exec -u www-data astucia-wiki php /var/www/html/run_ai_agent_jobs.php
```

The heartbeat is what the wiki reads before promising an `/aiJob` ETA; if it is stale the
UI says the runner does not appear to be running instead of inventing a time.

### Two traps

- **A crontab file must be owned by `root`.** busybox `crond` silently ignores one owned
  by anyone else — no error, no log line, the jobs simply never run. The *filename*
  decides which user they run as, so a root-owned `/etc/crontabs/www-data` still runs
  them as `www-data`.
- **`crond` logs to syslog**, which does not exist in the container, so its own messages
  go nowhere. To see what it is actually loading, run it in the foreground:

  ```bash
  docker exec astucia-wiki crond -f -d 0 -c /etc/crontabs
  ```

  Every crontab it loads is printed. A user missing from that output is being ignored.

### Running cron on the host instead

```bash
# ENABLE_CRON=false in wiki.env, then on the host:
*/15 * * * * docker exec -u www-data astucia-wiki php /var/www/html/run_ai_agent_jobs.php
0 7 * * *    docker exec -u www-data astucia-wiki php /var/www/html/run_daily_digest.php
```

With `ENABLE_CRON=false` and nothing in its place, scheduled jobs never fire and one-off
`/aiJob` requests are accepted but never start.

## Upgrading

```bash
cd /path/to/AstuciaWiki
git pull
docker build --build-arg VERSION=$(cat VERSION) \
             --build-arg REVISION=$(git rev-parse --short HEAD) \
             -t astucia-wiki:local .
docker rm -f astucia-wiki
./docker/create_container.sh
```

Or with Compose: `git pull && docker compose up -d --build`.

No migrations, and no page is touched — code is in the image, data is in the volume.
Page IDs are preserved, so links and bookmarks keep working. Take a backup first anyway.

## Troubleshooting

| Symptom | Cause and fix |
|---------|---------------|
| Container `unhealthy` | `docker logs astucia-wiki` — usually a bad value in `wiki.env`. The entrypoint refuses to start on invalid PHP in `config.php` |
| Blank page, browser console shows module errors | A proxy in front is not passing `.js` through with `Content-Type: application/javascript`. The container itself sets it correctly |
| Saving a page fails | Ownership of the mounted directory. Check `docker logs` for the `adopting the owner` line, or set `PUID`/`PGID` explicitly |
| Scheduled jobs never run | Is `ENABLE_CRON=true`? Is the crontab root-owned (see above)? Check `/data/logs/cron-agent-jobs.log` |
| Jobs run at the wrong time | `TZ` is unset, so everything is UTC |
| AI replies time out after ~60s | A reverse proxy's read timeout. Raise it to 900s — an AI tool-calling loop or an `/aiJob` legitimately takes minutes |
| Search finds nothing | `SEARCH_ENGINE=sqlite`, and the page must be inside a Space, not at the root of `/data/pages` |
| Uploads rejected | Raise `client_max_body_size` in the reverse proxy to match the container's 64 MB |

---

# Next steps

## nginx as a reverse proxy with Let's Encrypt

Put the container on localhost and let nginx on the host terminate TLS. The config below
is syntax-checked against nginx 1.28.

**1. Bind the container to localhost only**, so it cannot be reached except through the
proxy — change `-p 8080:80` to:

```
-p 127.0.0.1:8080:80
```

**2. Get a certificate.** Start with an HTTP-only server block so Certbot can answer the
challenge:

```nginx
# /etc/nginx/sites-available/wiki
server {
    listen 80;
    server_name wiki.example.com;
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}
```

```bash
sudo apt install nginx certbot python3-certbot-nginx
sudo ln -s /etc/nginx/sites-available/wiki /etc/nginx/sites-enabled/
sudo mkdir -p /var/www/certbot
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d wiki.example.com
```

Certbot installs a renewal timer; check it with
`systemctl list-timers | grep certbot` and rehearse with `sudo certbot renew --dry-run`.

**3. The proxy itself:**

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name wiki.example.com;

    ssl_certificate     /etc/letsencrypt/live/wiki.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wiki.example.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # At least as large as the container's own 64m, or attachment uploads fail here.
    client_max_body_size 64m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;

        # An AI reply runs a tool-calling loop inside the request, and an
        # extended-reasoning /aiJob can take many minutes. The 60s default returns
        # a 504 mid-answer.
        proxy_read_timeout    900s;
        proxy_send_timeout    900s;
        proxy_connect_timeout 30s;
        proxy_buffering       off;
    }
}
```

**4. Tell the wiki its public URL**, so login redirects and share links are right:

```
APP_BASE_URL=https://wiki.example.com
OIDC_REDIRECT_URI=https://wiki.example.com/auth.php     # only for OIDC
```

Recreate the container after changing these.

### Caddy, if you prefer two lines to forty

```
wiki.example.com {
    reverse_proxy 127.0.0.1:8080
    request_body { max_size 64MB }
}
```

Caddy obtains and renews certificates automatically. Set the read timeout for long AI
requests with `transport http { read_timeout 900s }` inside the `reverse_proxy` block.

## Turn authentication on

`AUTHENTICATION=off` means every visitor is an admin. Before exposing the wiki:

- `AUTHENTICATION=otp` — one-time codes by email; needs the mail settings
- `AUTHENTICATION=oidc` — your identity provider; needs the `OIDC_*` settings and
  `APP_BASE_URL`
- `AUTHENTICATION=both` — users choose
- `ANONYMOUS_ACCESS_ENABLED=true` — public read-only, editing requires login

The first user to log in becomes admin; see the main README on bootstrapping.

## Git version history for your content

Make the content directory a git repository and the wiki commits page changes to it:

```bash
cd /srv/astucia-wiki/data/pages
git init && git add . && git commit -m "Initial content"
```

The container adds a `safe.directory` entry and a fallback identity automatically. Set
`GIT_AUTHOR_NAME` / `GIT_AUTHOR_EMAIL` to change the fallback. Push that repository
somewhere and you have off-host backup with history, in addition to the tar.

## Hardening

- **Do not publish port 80/443 from the container.** Bind it to `127.0.0.1` and proxy.
- **Read-only root filesystem** works if you give it writable tmp space:
  `--read-only --tmpfs /run --tmpfs /tmp --tmpfs /var/lib/nginx`. `/data` stays writable
  through the mount. Test before adopting: PHP sessions and nginx temp files need those
  tmpfs mounts.
- **Drop capabilities:** `--cap-drop=ALL --cap-add=CHOWN --cap-add=SETUID --cap-add=SETGID`
  (the entrypoint needs those to adopt the mount's ownership and to run jobs as
  `www-data`).
- Keep `wiki.env` at mode `600` — it can hold mail credentials.

## Monitoring and logs

- The container exposes a healthcheck; most orchestrators use it as a readiness probe.
- Ship `/data/logs/*.log` with whatever you already run, or point your log driver at the
  container's stdout: `--log-driver=journald`, `--log-opt max-size=10m` for the default
  json-file driver.
- `Admin → Diagnostics` reads the nginx logs from `NGINX_ACCESS_LOG` / `NGINX_ERROR_LOG`,
  which default to `/data/logs/`.

## Splitting the container

If your platform insists on one process per container, run the image three ways against
the same `/data`: `command: php-fpm` for the app, your own nginx pointed at it, and a
scheduled task (Kubernetes CronJob, systemd timer) invoking the two PHP scripts with
`ENABLE_CRON=false`. The nginx config in `docker/nginx.conf` is a working starting point
for the web tier.
