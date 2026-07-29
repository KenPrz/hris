#!/bin/bash
#
# M9 'containerization & production' — the milestone's end-to-end proof. Unlike every
# other e2e script here, this one does NOT run against `make dev`: it boots the real
# production stack (compose.prod.yml, the prod images, TLS at the edge) from nothing,
# proves the whole thing serves, backs it up, drills the backup, and tears it all down.
#
# It walks the production done-when:
#
#   1. BUILD + BOOT: compose.prod.yml comes up with the `prod`/`runner` image targets —
#      no bind mounts, no hot reload, APP_DEBUG=false, config and routes cached at boot.
#
#   2. THE EDGE SERVES BOTH HALVES OVER TLS: one FrankenPHP container terminates HTTPS
#      and routes by path — /api/* to PHP, everything else to Next (reverse_proxy
#      web:3000). Proven by fetching the API health endpoint AND an app page from the
#      SAME https:// origin. This is what makes CORS a non-issue in production, by a
#      different mechanism than dev (where Next's own /api rewrite does the forwarding).
#
#   3. THE FIRST LOGIN EXISTS: `hris:bootstrap-admin` on an empty database mints one
#      System Admin and seeds the RBAC catalog. The generated password is printed once,
#      and this script signs in with it through the public edge to prove it is real. A
#      second invocation is refused — the command never mints a second superuser.
#
#   4. THE SESSION IS COHERENT WITH NO EMPLOYEE RECORD: GET /me returns
#      is_system_admin:true and employee:null, which is what lets a bootstrap admin log
#      in before any organization exists (the chicken-and-egg the command sidesteps).
#
#   5. BACKUP + RESTORE DRILL: a pg_dump of the live production database is taken, then
#      `make restore-drill` restores it into a throwaway container and counts rows.
#
# SAFETY: this boots under its OWN compose project name (hris-e2e-prod), never `hris`.
# compose.prod.yml's `name: hris` would otherwise attach to a real production stack's
# pgdata volume — and this script ends with `down -v`. The project override is the only
# thing standing between a smoke test and someone's payroll history, so do not remove it.
# For the same reason it writes its own throwaway env file and never reads or touches the
# repo's .env.
#
# Requires: docker, jq, curl, make, and ports 80/443 free on this host.
# Usage: scripts/e2e-prod-boot.sh

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

PROJECT=hris-e2e-prod
DOMAIN=hris.localhost
ENV_FILE=$(mktemp /tmp/hris-e2e-prod-env.XXXXXX)
COMPOSE="docker compose -f compose.prod.yml --env-file $ENV_FILE -p $PROJECT"
DUMP=""

# --resolve rather than trusting DNS: *.localhost resolves on most systems but not all,
# and this way the proof does not depend on the host's resolver at all. -k because
# HRIS_TLS_ISSUER=internal makes Caddy mint from its own CA — the point of this run is
# that TLS is terminated at the edge, not that a public CA vouched for it.
CURL="curl -sk --resolve $DOMAIN:443:127.0.0.1"
BASE="https://$DOMAIN"
API="$BASE/api/v1"
J='Content-Type: application/json'

cleanup() {
  # Captured FIRST and re-raised at the end: without it the trap's own last command
  # decides the script's exit status, and a run that printed FAIL still exited 0 — an
  # e2e that lies about passing is worse than no e2e. (Observed, not theorised.)
  status=$?
  echo
  echo "--- tearing down ($PROJECT, volumes included) ---"
  $COMPOSE down -v --remove-orphans 2>&1 | tail -3
  rm -f "$ENV_FILE"
  # The e2e dump is deleted deliberately: leaving it in backups/ would make the next
  # plain `make restore-drill` silently drill throwaway test data while looking like it
  # had verified a real backup. The drill's value is the run, not the artifact.
  [ -n "$DUMP" ] && rm -f "$DUMP"
  exit $status
}
trap cleanup EXIT

fail() { echo "FAIL: $1"; exit 1; }

echo "=== M9 production boot e2e ==="
echo

# --- 0. preflight ---------------------------------------------------------------

for bin in docker jq curl make; do
  command -v "$bin" >/dev/null || fail "$bin is not installed"
done

# 80/443 must be free — the prod stack publishes them, and a bind failure here is much
# clearer than a container that flaps in the background five steps later.
for port in 80 443; do
  if (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null; then
    exec 3>&- 2>/dev/null
    fail "port $port is already in use — stop whatever holds it (\`make dev-down\`?) and retry"
  fi
done
echo "0. preflight: docker/jq/curl/make present, ports 80 and 443 free — PASS"

# --- 1. a throwaway production env ----------------------------------------------

# Never the repo's .env: this run must not be able to read a real database password, and
# `down -v` at the end must not be able to reach a real volume.
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
cat > "$ENV_FILE" <<EOF
HRIS_DB_PASSWORD=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
HRIS_APP_KEY=$APP_KEY
HRIS_DOMAIN=$DOMAIN
HRIS_ORGANIZATION_NAME=E2E Prod Boot Inc.
HRIS_RUSTFS_KEY=e2eprodkey
HRIS_RUSTFS_SECRET=e2eprodsecret
HRIS_TLS_ISSUER=internal
HRIS_MIGRATE_ON_BOOT=1
EOF
echo "1. throwaway env written ($DOMAIN, TLS issuer 'internal', fresh APP_KEY) — PASS"

# --- 2. build and boot ----------------------------------------------------------

echo
echo "2. building the production images and booting the stack (first run: several minutes)..."
$COMPOSE up -d --build 2>&1 | tail -5
[ "${PIPESTATUS[0]}" = 0 ] || fail "compose up failed"

# The api healthcheck curls the internal :8000 listener; waiting on it means waiting for
# migrate + config:cache + route:cache to have finished, not merely for a port to open.
echo -n "   waiting for the api container to report healthy"
for i in $(seq 1 90); do
  state=$($COMPOSE ps --format json api 2>/dev/null | jq -r 'if type == "array" then .[0].Health else .Health end' 2>/dev/null)
  [ "$state" = "healthy" ] && break
  echo -n "."
  sleep 5
done
echo
[ "$state" = "healthy" ] || fail "api never became healthy — \`$COMPOSE logs api\`"
echo "2. stack up, api healthy (migrations ran, config and routes cached at boot) — PASS"

# --- 3. the edge serves the API over TLS ----------------------------------------

echo
HEALTH=$($CURL "$API/health")
echo "3. GET $API/health -> $(echo "$HEALTH" | jq -c .data 2>/dev/null || echo "$HEALTH")"
[ "$(echo "$HEALTH" | jq -r .data.healthy 2>/dev/null)" = "true" ] \
  || fail "health endpoint did not report healthy through the TLS edge"
# Not just "the container answered" — the API reached Postgres through the compose
# network, which is the half a static page could fake.
[ "$(echo "$HEALTH" | jq -r .data.database.ok 2>/dev/null)" = "true" ] \
  || fail "the API answered but could not reach the database"
echo "3. the API answers over HTTPS at the public domain — PASS"

# --- 4. the same edge serves the frontend ---------------------------------------

WEB_CODE=$($CURL -o /dev/null -w '%{http_code}' "$BASE/login")
echo "4. GET $BASE/login -> HTTP $WEB_CODE (proxied to Next on the same origin)"
[ "$WEB_CODE" = "200" ] || fail "the login page did not render through the edge (HTTP $WEB_CODE)"
echo "4. one origin serves both halves — /api/* to PHP, everything else to Next — PASS"

# --- 5. the first login on an empty database ------------------------------------

echo
BOOTSTRAP=$($COMPOSE exec -T --user hris api php artisan hris:bootstrap-admin admin@e2e.test --name "E2E Admin" 2>&1)
echo "$BOOTSTRAP" | sed 's/^/   | /'
ADMIN_PW=$(echo "$BOOTSTRAP" | grep -oP 'password:\s*\K\S+')
[ -n "$ADMIN_PW" ] || fail "bootstrap-admin printed no password"
echo "5. hris:bootstrap-admin created the first System Admin — PASS"

# --- 6. that login actually works, through the public edge ----------------------

echo
TOKEN=$($CURL -X POST "$API/login" -H "$J" \
  -d "{\"email\":\"admin@e2e.test\",\"password\":\"$ADMIN_PW\"}" | jq -r .data.token)
[ -n "$TOKEN" ] && [ "$TOKEN" != "null" ] \
  || fail "the bootstrapped admin could not sign in — the printed password is not the real one"
echo "6. the printed password signs in over HTTPS — PASS"

ME=$($CURL "$API/me" -H "Authorization: Bearer $TOKEN")
echo "   GET /me -> $(echo "$ME" | jq -c '{is_system_admin: .data.is_system_admin, employee: .data.employee}')"
[ "$(echo "$ME" | jq -r .data.is_system_admin)" = "true" ] || fail "/me did not report is_system_admin"
# The whole point of decision 4 in the spec: an admin with no employee record is a
# coherent session, which is what lets them sign in before any organization exists.
[ "$(echo "$ME" | jq -r .data.employee)" = "null" ] \
  || fail "/me returned an employee for a bootstrap admin that has none"
echo "6. the session is coherent with employee:null — PASS"

# --- 7. it refuses to mint a second superuser -----------------------------------

echo
SECOND=$($COMPOSE exec -T --user hris api php artisan hris:bootstrap-admin other@e2e.test 2>&1)
SECOND_CODE=$?
echo "$SECOND" | sed 's/^/   | /'
[ "$SECOND_CODE" != "0" ] || fail "a second bootstrap-admin run was allowed"
echo "$SECOND" | grep -q "already exists" || fail "the refusal did not explain itself"
echo "7. a second System Admin is refused — PASS"

# --- 8. backup, then drill it ---------------------------------------------------

echo
mkdir -p backups
DUMP="backups/e2e-prod-$(date -u +%Y%m%dT%H%M%SZ).dump"   # removed by cleanup() on exit
# `make backup` is not used here only because it targets the `hris` project by name and
# this stack deliberately runs under its own; the dump itself is byte-identical in kind.
$COMPOSE exec -T db pg_dump -U hris -d hris -Fc > "$DUMP" || fail "pg_dump failed"
echo "8. pg_dump of the live production database -> $DUMP ($(du -h "$DUMP" | cut -f1))"

# This one IS the real target, run exactly as an operator would.
make restore-drill 2>&1 | sed 's/^/   | /'
[ "${PIPESTATUS[0]}" = 0 ] || fail "make restore-drill did not pass"
echo "8. make restore-drill restored that dump into a throwaway db and counted rows — PASS"

echo
echo "=== all M9 production checks PASSED ==="
echo "    built and booted compose.prod.yml, served both halves over TLS from one edge,"
echo "    bootstrapped the first admin on an empty database, signed in through the public"
echo "    domain, refused a second superuser, and drilled a real backup."
