# syntax=docker/dockerfile:1
#
# Astucia Wiki — single-container image (nginx + PHP-FPM + cron).
#
# The wiki is a flat-file app: everything that must survive an upgrade lives in
# volumes, never in the image. See docker-compose.yml and the "Packaging Astucia
# Wiki as a Docker image" page for the reasoning.

# ---------------------------------------------------------------------------
# Stage 1 — Composer dependencies
# Only needed for OIDC login (jumbojett/openid-connect-php) and the static-site
# export (erusev/parsedown), but both are cheap, so they are always installed.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — Runtime
# PHP-FPM rather than the built-in server or mod_php: the AI chat path calls
# fastcgi_finish_request() to answer the browser and keep working in the
# background, which only exists under FPM.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

# nginx: static files + FastCGI. supervisor: three processes in one container.
# crond: the two wiki cron jobs. git: optional page version history.
# shadow provides usermod/groupmod, which the entrypoint uses to match the
# container user to a bind-mounted host directory's owner (PUID/PGID).
# nginx workers run as www-data (see nginx.conf) and need their own temp dirs to
# buffer uploaded attachments, so those are chowned here too.
# pdo_sqlite is compiled against the system sqlite (PHP no longer bundles it), so
# the headers and a toolchain are installed as a virtual package and removed again
# — only sqlite-libs is needed at runtime.
RUN apk add --no-cache nginx supervisor git tzdata shadow sqlite-libs \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS sqlite-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
 && apk del --no-network .build-deps \
 && chown -R www-data:www-data /var/lib/nginx

# Fail the build rather than ship an image that is missing something the app
# needs at runtime: pdo_sqlite for FTS search, curl for the LLM and MCP clients,
# mbstring for message length limits and Parsedown, fileinfo for attachments.
RUN php -r 'foreach (["pdo_sqlite","curl","mbstring","fileinfo","json","session"] as $e) { \
      if (!extension_loaded($e)) { fwrite(STDERR, "FATAL: PHP extension missing: $e\n"); exit(1); } } \
      echo "PHP extensions present\n";'

# --- Image metadata (OCI) -----------------------------------------------------
# VERSION/REVISION are build args so a published image reports what it actually is:
#   docker build --build-arg VERSION=$(cat VERSION) \
#                --build-arg REVISION=$(git rev-parse --short HEAD) .
# Left as "dev" when not supplied, which is honest — better than a hardcoded number
# that silently goes stale. /var/www/html/VERSION inside the image is authoritative.
ARG VERSION=dev
ARG REVISION=unknown
LABEL org.opencontainers.image.title="Astucia Wiki" \
      org.opencontainers.image.description="Flat-file, self-hosted team wiki with AI assistants, MCP server and no database" \
      org.opencontainers.image.url="https://astucia.wiki" \
      org.opencontainers.image.source="https://github.com/madsrg/astucia-wiki" \
      org.opencontainers.image.documentation="https://github.com/madsrg/astucia-wiki/blob/main/DOCKER.md" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.version="$VERSION" \
      org.opencontainers.image.revision="$REVISION"

WORKDIR /var/www/html

# Application code. .dockerignore keeps config.php, content and .git out.
COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor

# Container plumbing
COPY docker/nginx.conf        /etc/nginx/nginx.conf
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-astucia.ini
COPY docker/www.conf          /usr/local/etc/php-fpm.d/zz-astucia.conf
COPY docker/supervisord.conf  /etc/supervisord.conf
COPY docker/entrypoint.sh     /usr/local/bin/entrypoint
# Kept outside the web root: the entrypoint copies it to config.php only when no
# config.php has been mounted, so it must survive the cleanup below.
COPY docker/config.docker.php /usr/local/share/astucia/config.docker.php
RUN chmod +x /usr/local/bin/entrypoint \
 && rm -rf /var/www/html/docker /var/www/html/Dockerfile /var/www/html/docker-compose.yml

# Defaults; override any of these with -e / environment: in compose.
# PUID/PGID are deliberately NOT defaulted here: the entrypoint infers them from a
# mounted content directory, which only works if it can tell "unset" from "82".
ENV PAGES_DIR=/data/pages/ \
    WIKI_SYSTEM_DATA=/data/system/ \
    LOG_DIR=/data/logs/ \
    APP_TITLE="Astucia Wiki" \
    ENVIRONMENT=production \
    AUTHENTICATION=off \
    ANONYMOUS_ACCESS_ENABLED=false \
    SEARCH_ENGINE=sqlite \
    SESSION_TIMEOUT=3600 \
    INDEX_SYNC_INTERVAL_SECONDS=30 \
    AGENT_JOB_RUNNER_INTERVAL_MINUTES=15 \
    ENABLE_CRON=true \
    TZ=UTC

VOLUME ["/data"]
EXPOSE 80

# Hits the app through nginx and PHP, so a broken FPM socket or a fatal error in
# config.php is reported as unhealthy rather than as a running container.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1/api.php?action=list_spaces"); exit($c && str_contains($c, "\"success\":true") ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
