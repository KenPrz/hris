# backend/Dockerfile
# syntax=docker/dockerfile:1

# ---- base: FrankenPHP + the extensions this app needs -----------------------
FROM dunglas/frankenphp:php8.5 AS base

# exif is spatie/image's (a laravel-medialibrary dependency) hard platform requirement —
# missing it doesn't fail the build, it fails composer install at container boot with
# "requires ext-exif -> it is missing", which left the api_vendor volume stuck half
# up-to-date (older packages present, medialibrary never actually installed) the first
# time this image tried to pick up M3.6's Media Library dependency. Found running Task 9.
RUN install-php-extensions pdo_pgsql pgsql bcmath intl opcache exif

# The base image has no composer binary; take the official static one.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer's dist downloader needs zip (no unzip/7z binary in this image either).
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Non-root: FrankenPHP needs cap_net_bind_service to bind 80/443 as non-root.
RUN useradd -u 1000 -m hris \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R hris:hris /data/caddy /config/caddy

WORKDIR /app

# ---- dev: full deps, code arrives via bind mount ----------------------------
FROM base AS dev
ENV APP_ENV=local
COPY docker/Caddyfile.dev /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
USER hris
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
