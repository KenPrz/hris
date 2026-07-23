#!/bin/bash
#
# M3 'timekeeping ingestion' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). It walks the whole
# append-only ingestion path against the real API:
#
#   a seeded Manila employee punches IN and OUT (both FLAGGED, because Manila carries an
#   ip_allowlist and this script runs off that /24 — flagged, never refused); the punch-in
#   is retried under the same Idempotency-Key and replays the stored row instead of writing
#   a second; HR then backfills a missed punch as `manual` (VERIFIED — a manual entry
#   carries no request IP); and finally `GET /me/attendance?month=` returns them all grouped
#   by office-local calendar date, showing both `verified` and `flagged`, `web` and `manual`.
#
# Nothing here mutates a punch: the ledger is append-only, so a "correction" is the new
# manual row, never an edit. Seeded logins are printed by the seeder; the two used here are
# employee.manila@hris.test and hr.manila@hris.test, both password `password`.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'
MONTH="$(date +%Y-%m)"
BACKFILL_AT="${MONTH}-01T08:00:00+08:00"   # a backdated missed punch on the 1st of the month

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

# 1. The employee logs in and learns their own employee id (for HR's backfill later).
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
EMP_ID=$(curl -sf "$API/me" -H "$E" | jq -r .data.employee.id)
echo "1. employee.manila logged in; employee_id=$EMP_ID"

# 2. Clock IN with an idempotency key. Manila enforces an allowlist and we are off it, so
#    the punch lands FLAGGED — recorded (201), never refused.
IN=$(curl -sf -X POST "$API/attendance/punch" -H "$E" -H "$J" \
  -H "Idempotency-Key: in-$MONTH" -d '{"direction":"in"}')
IN_ID=$(echo "$IN" | jq -r .data.id)
echo "2. clock-in: id=$IN_ID direction=$(echo "$IN" | jq -r .data.direction) \
source=$(echo "$IN" | jq -r .data.source) verification=$(echo "$IN" | jq -r .data.verification) \
(expect web / flagged) flag=$(echo "$IN" | jq -r .data.flag_reason)"
[ "$(echo "$IN" | jq -r .data.verification)" = "flagged" ] || { echo "FAIL: off-net punch not flagged"; exit 1; }

# 3. Retry the SAME key. The middleware replays the stored response — same row id, no second
#    write. Two identical ids proves idempotency held.
RETRY=$(curl -sf -X POST "$API/attendance/punch" -H "$E" -H "$J" \
  -H "Idempotency-Key: in-$MONTH" -d '{"direction":"in"}')
RETRY_ID=$(echo "$RETRY" | jq -r .data.id)
echo "3. retried same key: id=$RETRY_ID (expect == $IN_ID)"
[ "$RETRY_ID" = "$IN_ID" ] || { echo "FAIL: idempotent retry wrote a new row"; exit 1; }

# 4. Clock OUT under a distinct key — a genuinely new punch, also flagged (still off-net).
OUT=$(curl -sf -X POST "$API/attendance/punch" -H "$E" -H "$J" \
  -H "Idempotency-Key: out-$MONTH" -d '{"direction":"out"}')
echo "4. clock-out: id=$(echo "$OUT" | jq -r .data.id) \
direction=$(echo "$OUT" | jq -r .data.direction) verification=$(echo "$OUT" | jq -r .data.verification)"

# 5. HR backfills a missed punch for that employee at a supplied (backdated) time. Manual
#    entry is HR-only-never-self, scoped to the offices HR administers; it carries no request
#    IP, so it lands VERIFIED with source=manual and recorded_by = the HR user.
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"
MANUAL=$(curl -sf -X POST "$API/admin/attendance/punch" -H "$H" -H "$J" \
  -d "{\"employee_id\":\"$EMP_ID\",\"direction\":\"in\",\"punched_at\":\"$BACKFILL_AT\"}")
echo "5. HR manual backfill: id=$(echo "$MANUAL" | jq -r .data.id) \
source=$(echo "$MANUAL" | jq -r .data.source) verification=$(echo "$MANUAL" | jq -r .data.verification) \
punched_at=$(echo "$MANUAL" | jq -r .data.punched_at) (expect manual / verified)"
[ "$(echo "$MANUAL" | jq -r .data.source)" = "manual" ] || { echo "FAIL: backfill not source=manual"; exit 1; }

# 6. The employee reads their month back, grouped by office-local calendar date. The manual
#    backfill sits on the 1st; the two live punches sit on today's local date.
echo "6. GET /me/attendance?month=$MONTH — punches grouped by office-local date:"
curl -sf "$API/me/attendance?month=$MONTH" -H "$E" \
  | jq '.data | to_entries[] | {date: .key, punches: [.value[] | {direction, source, verification, flag_reason}]}'

echo "OK: punch in/out (flagged, idempotent under retry), HR manual backfill (verified),"
echo "    grouped read — all against an append-only ledger."
