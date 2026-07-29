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

# ---- vendor: composer layer cached off the lockfile alone -------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer \
    composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

# ---- dev: full deps, code arrives via bind mount ----------------------------
FROM base AS dev
ENV APP_ENV=local
COPY docker/Caddyfile.dev /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
USER hris
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---- prod -------------------------------------------------------------------
FROM base AS prod
ENV APP_ENV=production
COPY --chown=hris:hris . .
COPY --from=vendor --chown=hris:hris /app/vendor ./vendor
# --no-scripts: composer's post-autoload-dump runs `artisan package:discover`, which
# boots the framework — and AppServiceProvider::assertConfigured() refuses to boot
# without HRIS_CURRENCY/HRIS_ORGANIZATION_NAME/APP_KEY, none of which exist at build
# time (config is cached at BOOT, never baked into the image — see entrypoint.sh).
# Discovery runs lazily on the first boot instead, once real env is present. Same
# reasoning, same flag, as .github/workflows/ci.yml.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && chown -R hris:hris storage bootstrap/cache
COPY docker/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps=0\nopcache.memory_consumption=192\n' \
       > "$PHP_INI_DIR/conf.d/zz-opcache.ini"
USER hris
# The internal :8000 listener in Caddyfile.prod exists for exactly this — the public
# vhost is TLS on a hostname the container itself can't resolve.
HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
  CMD curl -fsS http://127.0.0.1:8000/api/v1/health || exit 1
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
