#!/bin/sh
# backend/docker/entrypoint.sh
# Config is cached at BOOT, never at build: the image carries no environment.
set -eu

# APP_KEY is never baked into the image, so a blank one only surfaces here, at
# container start. compose.dev.yml deliberately uses a soft default (`:-`) rather
# than a required var — a required var with no default fails interpolation for the
# WHOLE compose file, including `up -d db` on its own. So the loud failure lives
# here, in the one service that actually needs the key.
if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty — dev: run 'make dev-key' and put the value in the root .env as HRIS_DEV_APP_KEY; prod: set HRIS_APP_KEY in the .env beside compose.prod.yml" >&2
    exit 1
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    # Same reasoning as APP_KEY above: compose.prod.yml uses soft `:-` defaults, because
    # a hard `:?` guard fails interpolation for EVERY compose command — including
    # `down -v` and `config`, the ones you need when something is already wrong. So the
    # required-var check lives here, at boot, where it stops only the service that needs
    # the value. HRIS_CURRENCY/HRIS_ORGANIZATION_NAME are guarded by the app itself
    # (AppServiceProvider::assertConfigured()) and the db password by the postgres image;
    # HRIS_DOMAIN has no later guard — an empty vhost just serves nothing — so check it.
    if [ -z "${HRIS_DOMAIN:-}" ]; then
        echo "HRIS_DOMAIN is empty — set it in the .env beside compose.prod.yml (see .env.prod.example)" >&2
        exit 1
    fi

    php artisan config:cache
    php artisan route:cache
fi

if [ "${MIGRATE_ON_BOOT:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
