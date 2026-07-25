#!/bin/bash
#
# M5a 'compute engine' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-holidays.sh's structure and seeded logins. It walks the read side of the
# compute pipeline against the real API:
#
#   Miguel Santos (employee.manila@hris.test), a rank-and-file Manila employee with an
#   ordinary punched day (2026-01-15), sees `GET /me/attendance/summary?month=2026-01`
#   price it as a `regular_day` line at exactly the statutory floor (10000bp = 100%),
#   stamped with the effective `pay_rules` version's id; the SAME employee working
#   2026-08-21 — a scheduled Friday that is ALSO the seeded special-non-working holiday —
#   prices instead at 13000bp (130%), proving day-type resolution actually reaches the
#   holiday table, not just the weekday; Rosa Bautista (manager.manila@hris.test), the
#   seeded Art. 82-exempt manager, working that SAME holiday still prices at 10000bp —
#   the exemption short-circuits even a holiday premium, per `PayMultiplier`'s art82 gate.
#
#   Recomputing Miguel's holiday day a second time — direct, via `ComputeDailySummary`,
#   since M5a exposes no recompute endpoint yet (that's M5b's `RecomputeRange`) — is
#   byte-identical to the first: same lines, same `rule_version_id`, no duplicate. And
#   deleting the very `pay_rules` version every summary above is stamped with is refused
#   outright (`RESTRICT` on `daily_attendance_summaries.rule_version_id`) — the M4c seam
#   proven end to end, not just at the schema level.
#
# One thing this script deliberately does NOT assert via HTTP: the recompute. There is no
# `POST` endpoint that re-runs `ComputeDailySummary` yet — M5a's only writer of it is the
# synchronous on-punch trigger, and re-punching an already-paired day would change the
# INPUT (a third punch, not a recompute of the same two) rather than prove idempotency of
# the same one. So this reads — and re-invokes — the action directly inside the API
# container via `artisan tinker`, the same "no HTTP surface yet, so go straight at it"
# exception scripts/e2e-holidays.sh and scripts/e2e-pay-rules.sh already take for the
# activity log.
#
# Seeded logins used here: employee.manila@hris.test (Miguel Santos, MNL-0002, rank and
# file, NOT Art. 82-exempt) and manager.manila@hris.test (Rosa Bautista, MNL-0001, Art.
# 82-exempt manager). Both password `password`. CompanySeeder seeds both employees a
# punch on 2026-08-21 (Ninoy Aquino Day, special_non_working) and Miguel a second, earlier
# punch on 2026-01-15 (plain ordinary day) — see CompanySeeder's M5a comment block.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

# 1. Log in as Miguel (rank-and-file, not exempt) and Rosa (Art. 82-exempt manager).
MIGUEL_TOKEN=$(login employee.manila@hris.test password)
MIGUEL="Authorization: Bearer $MIGUEL_TOKEN"

ROSA_TOKEN=$(login manager.manila@hris.test password)
ROSA="Authorization: Bearer $ROSA_TOKEN"

echo "1. employee.manila (Miguel) and manager.manila (Rosa) logged in"

# 2. Miguel's ordinary punched day (2026-01-15): one regular_day line at the statutory
#    floor, stamped with a real rule_version_id.
JAN=$(curl -sf "$API/me/attendance/summary?month=2026-01" -H "$MIGUEL")
JAN_DAY=$(echo "$JAN" | jq -c '.data[] | select(.date == "2026-01-15")')
echo "2. GET /me/attendance/summary?month=2026-01 as Miguel: 2026-01-15 = $JAN_DAY"

[ -n "$JAN_DAY" ] || { echo "FAIL: no summary for 2026-01-15 — did the seeder run?"; exit 1; }
[ "$(echo "$JAN_DAY" | jq -r '.status')" = "computed" ] || { echo "FAIL: 2026-01-15 is not status=computed"; exit 1; }
[ "$(echo "$JAN_DAY" | jq -r '.is_incomplete')" = "false" ] || { echo "FAIL: 2026-01-15 is marked incomplete"; exit 1; }
RULE_VERSION_ID=$(echo "$JAN_DAY" | jq -r '.rule_version_id')
[ -n "$RULE_VERSION_ID" ] && [ "$RULE_VERSION_ID" != "null" ] || { echo "FAIL: 2026-01-15 has no rule_version_id"; exit 1; }
[ "$(echo "$JAN_DAY" | jq '[.lines[] | select(.kind == "regular_day" and .applied_bp == 10000)] | length')" = "1" ] \
  || { echo "FAIL: 2026-01-15 has no regular_day line at 10000bp (100%, the ordinary floor)"; exit 1; }

# 3. The Aug 21 special-non-working holiday, worked: prices at 130%, not 100% — proof
#    day-type resolution actually reads the holiday table for this date.
AUG_MIGUEL=$(curl -sf "$API/me/attendance/summary?month=2026-08" -H "$MIGUEL")
AUG_MIGUEL_DAY=$(echo "$AUG_MIGUEL" | jq -c '.data[] | select(.date == "2026-08-21")')
echo "3. GET /me/attendance/summary?month=2026-08 as Miguel: 2026-08-21 = $AUG_MIGUEL_DAY"

[ -n "$AUG_MIGUEL_DAY" ] || { echo "FAIL: no summary for Miguel on 2026-08-21"; exit 1; }
[ "$(echo "$AUG_MIGUEL_DAY" | jq -r '.day_type')" = "special_non_working" ] \
  || { echo "FAIL: 2026-08-21 did not resolve as special_non_working"; exit 1; }
[ "$(echo "$AUG_MIGUEL_DAY" | jq '[.lines[] | select(.kind == "regular_day" and .applied_bp == 13000)] | length')" = "1" ] \
  || { echo "FAIL: Miguel's 2026-08-21 has no regular_day line at 13000bp (130%, special-non-working worked)"; exit 1; }
AUG_RULE_VERSION_ID=$(echo "$AUG_MIGUEL_DAY" | jq -r '.rule_version_id')
[ "$AUG_RULE_VERSION_ID" = "$RULE_VERSION_ID" ] \
  || { echo "FAIL: Miguel's two summaries were priced by two different pay_rules versions"; exit 1; }

# 4. Rosa (Art. 82-exempt) works the SAME holiday: every line still prices at 10000bp —
#    the exemption short-circuits the holiday premium entirely, per PayMultiplier.
AUG_ROSA=$(curl -sf "$API/me/attendance/summary?month=2026-08" -H "$ROSA")
AUG_ROSA_DAY=$(echo "$AUG_ROSA" | jq -c '.data[] | select(.date == "2026-08-21")')
echo "4. GET /me/attendance/summary?month=2026-08 as Rosa (Art. 82-exempt): 2026-08-21 = $AUG_ROSA_DAY"

[ -n "$AUG_ROSA_DAY" ] || { echo "FAIL: no summary for Rosa on 2026-08-21"; exit 1; }
[ "$(echo "$AUG_ROSA_DAY" | jq -r '.is_art82_exempt')" = "true" ] || { echo "FAIL: Rosa's summary is not is_art82_exempt"; exit 1; }
[ "$(echo "$AUG_ROSA_DAY" | jq '[.lines[] | select(.applied_bp != 10000)] | length')" = "0" ] \
  || { echo "FAIL: Rosa (Art. 82-exempt) has a line priced above 10000bp on a holiday"; exit 1; }
[ "$(echo "$AUG_ROSA_DAY" | jq '.lines | length')" -ge 1 ] || { echo "FAIL: Rosa's holiday summary has no lines at all"; exit 1; }

# 5. Recompute is idempotent. No HTTP endpoint re-runs ComputeDailySummary yet (M5b's
#    RecomputeRange) — re-punching would change the INPUT, not recompute the same one — so
#    this invokes the action directly inside the API container, the same "go straight at
#    it" exception e2e-holidays.sh/e2e-pay-rules.sh take for the activity log.
docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T api php artisan tinker --execute="
\$e = App\Models\Employee::where('employee_no', 'MNL-0002')->firstOrFail();
app(App\Actions\Compute\ComputeDailySummary::class)->execute(\$e, '2026-08-21');
" >/dev/null

AUG_MIGUEL_RECOMPUTED=$(curl -sf "$API/me/attendance/summary?month=2026-08" -H "$MIGUEL")
AUG_MIGUEL_DAY_RECOMPUTED=$(echo "$AUG_MIGUEL_RECOMPUTED" | jq -c '.data[] | select(.date == "2026-08-21")')
echo "5. recomputed Miguel's 2026-08-21 directly; re-read: $AUG_MIGUEL_DAY_RECOMPUTED"

[ "$AUG_MIGUEL_DAY" = "$AUG_MIGUEL_DAY_RECOMPUTED" ] \
  || { echo "FAIL: recomputing the same day changed its summary — not idempotent"; exit 1; }

# 6. The M4c seam, end to end: deleting the pay_rules version every summary above is
#    stamped with is refused by the database itself (RESTRICT on rule_version_id), not
#    merely by application code choosing not to call DELETE.
DELETE_ATTEMPT=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc "delete from pay_rules where id = '$RULE_VERSION_ID'" 2>&1 || true)
echo "6. DELETE FROM pay_rules WHERE id = '$RULE_VERSION_ID': $DELETE_ATTEMPT (expect a foreign-key violation)"
echo "$DELETE_ATTEMPT" | grep -qi "foreign key constraint" \
  || { echo "FAIL: deleting the stamped pay_rules version was not refused by a foreign-key constraint"; exit 1; }

STILL_THERE=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc "select count(*) from pay_rules where id = '$RULE_VERSION_ID'" | tr -d '[:space:]')
[ "$STILL_THERE" = "1" ] || { echo "FAIL: the stamped pay_rules version no longer exists — RESTRICT did not hold"; exit 1; }

echo "OK: Miguel's ordinary day prices at 100% (10000bp), the SAME employee working the"
echo "    seeded Aug 21 special-non-working holiday prices at 130% (13000bp), and Rosa —"
echo "    Art. 82-exempt — working that same holiday still prices at 100% everywhere;"
echo "    both are stamped with the one seeded pay_rules version; recomputing a day is"
echo "    byte-identical to the first compute; and deleting that stamped pay_rules version"
echo "    is refused by the database's own RESTRICT constraint — all against the live stack."
