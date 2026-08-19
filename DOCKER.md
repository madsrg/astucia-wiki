# Running Astucia Wiki in Docker

Configuring, running and maintaining the containerised wiki. Everything here was
executed against Docker 29.7 before being written down.

- [What the image is](#what-the-image-is)
- [Get the image](#get-the-image)
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

## Get the image

Published on Docker Hub, so running the wiki needs no checkout and no build:

```bash
docker pull madsrotwitt/astucia-wiki:2026.7.44
```

| Tag | Mutability | Use |
|-----|-----------|-----|
| `madsrotwitt/astucia-wiki:sha-<commit>` | **immutable** | pin this in production |
| `madsrotwitt/astucia-wiki:<version>` | moves only if that release is rebuilt | track a release |
| `madsrotwitt/astucia-wiki:latest` | moves on every release | trying it out |

Then run it — nothing else is required:

```bash
docker run -d --name astucia-wiki --restart=always \
    -p 8080:80 -v /srv/astucia-wiki/data:/data \
    madsrotwitt/astucia-wiki:2026.7.44
```

Verify what you pulled, rather than trusting the tag:

```bash
docker image inspect madsrotwitt/astucia-wiki:2026.7.44 \
    --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
```

That revision is a commit in the source repository, so a published image is always traceable
to the exact tree it was built from.

Published for **`linux/amd64` and `linux/arm64`**, so the same command works on an Intel
server, an Apple Silicon Mac or a Raspberry Pi — Docker selects the right variant. **Build
it yourself** if you want to modify the wiki, target another architecture, or would rather
not depend on a registry.

## Build

```bash
git clone <your-repo> AstuciaWiki && cd AstuciaWiki
./docker/build.sh
```

That produces **three tags**, and they are not interchangeable — only one of them
identifies a build:

| Tag | Mutability | Use |
|-----|-----------|-----|
| `astucia-wiki:sha-<commit>` | **immutable** — one commit builds one tree | **Deploy and pin this.** Rollback is picking an older SHA tag |
| `astucia-wiki:<version>` | moves if a release is rebuilt | "the current 2026.7.43" |
| `astucia-wiki:latest` | moves on every build | discovery, casual `docker run` |

Why it matters, from real experience with this project: in one day of development
`:2026.7.43` pointed at **four different images**, and a container started from `:latest`
reported `Config.Image = astucia-wiki:latest` while `:latest` had since moved elsewhere —
the tag could not say what was deployed. The image's OCI labels still could, which is a
mitigation, not a fix.

No `sha-` tag is created from a dirty working tree: uncommitted edits have no identity to
name, so an immutable tag would be a lie. Such a build still gets `:latest` and
`:<version>`, with a warning.

`build.sh` writes the tag it produced to `docker/.image-tag`, and `create_container.sh`
reads it, so a deploy pins the exact build without you copying hashes around:

```bash
./docker/build.sh                                   # -> docker/.image-tag
./docker/create_container.sh                        # runs that exact image
WIKI_IMAGE=astucia-wiki:sha-eb7e64c docker compose up -d    # or pin explicitly
```

The build installs the Composer dependencies in a separate stage and **fails** if a
required PHP extension is missing (`pdo_sqlite`, `curl`, `mbstring`, `fileinfo`,
`json`, `session`), so a broken image is caught here rather than at runtime.

### What the build script does, and why not to do it by hand

The image carries OCI metadata — title, description, source, documentation, licence — plus
a version and revision, which are build arguments. `docker/build.sh` reads them from
`VERSION` and `git`, so they cannot disagree with the code in the image:

```bash
docker build \
    --build-arg VERSION=$(cat VERSION) \
    --build-arg REVISION=$(git rev-parse --short HEAD) \
    -t astucia-wiki:latest -t astucia-wiki:$(cat VERSION) .
```

Building by hand without those args leaves the labels reading `version=dev` /
`revision=unknown`. Worse, passing a version by hand is how an image ends up *claiming* a
release it does not contain. The script also appends `-dirty` to the revision when the
working tree has uncommitted changes, so an image built from unsaved edits says so.

Inspect the result:

```bash
docker image inspect astucia-wiki:latest \
    --format '{{range $k,$v := .Config.Labels}}{{$k}} = {{$v}}{{"\n"}}{{end}}'
```

Before you distribute or `docker save` an image, check that its `revision` label matches
`git rev-parse --short HEAD`. `/var/www/html/VERSION` inside the image is authoritative
either way.

Other environment overrides:

```bash
IMAGE_NAME=madsrg/astucia-wiki ./docker/build.sh    # tags ready to push
./docker/build.sh --no-cache                        # extra args go to docker build
```

### Multi-architecture images

A single-platform image only runs on the architecture it was built for; anyone on Apple
Silicon or an ARM server gets "no matching manifest". To publish for both:

```bash
PLATFORMS=linux/amd64,linux/arm64 IMAGE_NAME=youruser/astucia-wiki ./docker/build.sh
```

This **pushes** rather than loads, because a multi-platform manifest list cannot live in
the classic local image store — there is no such thing as a local multi-arch image to run.
So `IMAGE_NAME` must be a repository you can push to; the script refuses the plain local
name rather than failing at the end of a long build.

Host setup, needed **once**:

```bash
# A builder that can cross-build. network=host matters: without it the builder
# container failed DNS resolution ("dl-cdn.alpinelinux.org: DNS: transient error"),
# so every apk install inside the arm64 build failed.
docker buildx create --use --name astucia \
    --driver docker-container --driver-opt network=host
```

That builder persists — it is a container with `restart-policy=unless-stopped`, and the
`network=host` option is stored with it, so it survives a reboot and needs no attention.

QEMU, which lets a non-native architecture be emulated, is **kernel state** in
`/proc/sys/fs/binfmt_misc` and is therefore lost on reboot. `build.sh` notices and
re-registers it for you, so after a reboot there is still nothing to remember:

```
Registering QEMU for arm64 (kernel state, lost on reboot)…
  done
```

Set `SKIP_QEMU_SETUP=1` if you would rather it never ran a privileged container, in which
case it prints the command instead. To make the registration permanent, install your
distribution's static QEMU package (`apt install qemu-user-static` on Debian/Ubuntu), which
ships `binfmt.d` entries that systemd registers at boot; the `binfmt` container is then
unnecessary.

Expect the non-native leg to be slow — Composer and `pdo_sqlite` run under emulation, so
budget ten minutes or more rather than the usual seconds. The script verifies each tag
landed in the registry and prints the platforms in the published manifest.

Verify a published variant actually runs, rather than trusting the manifest:

```bash
docker run --rm --platform linux/arm64 madsrotwitt/astucia-wiki:2026.7.45 \
    php -r 'echo php_uname("m"), " ", extension_loaded("pdo_sqlite") ? "ok" : "missing", "\n";'
```

### Publishing your own image

```bash
docker login
IMAGE_NAME=youruser/astucia-wiki ./docker/build.sh
docker push youruser/astucia-wiki:sha-<commit>
docker push youruser/astucia-wiki:<version>
docker push youruser/astucia-wiki:latest
```

Push the immutable and version tags as well as `latest`, and tell people to pull one of
those — `latest` will move under them. Before publishing, check the image is what you think:
its `revision` label should equal `git rev-parse --short HEAD`, and it should contain no
`config.php` (the `.dockerignore` keeps secrets and content out, but it is worth verifying
once). `docker/hub-description.md` is the text for the registry's repository description;
Docker Hub does not read a repository's README on its own.

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
    astucia-wiki:latest
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
./docker/build.sh
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
