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
    echo "APP_KEY is empty — run 'make dev-key' and put the value in the root .env as HRIS_DEV_APP_KEY" >&2
    exit 1
fi

if [ "${MIGRATE_ON_BOOT:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
