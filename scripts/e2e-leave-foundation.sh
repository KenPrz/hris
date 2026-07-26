#!/bin/bash
#
# M6b-a 'the leave foundation' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-recompute.sh's structure and scripts/e2e-holidays.sh/e2e-pay-rules.sh's
# office-scoped config idiom. It walks the whole configure-then-grant-then-read path
# against the real API — nothing here touches taking leave, approval, or accrual; those
# are M6b-b and later, and this script deliberately proves only what M6b-a shipped:
#
#   Carmen Lim (hr.manila@hris.test, HR Admin scoped to Manila HQ only) creates a NEW
#   "Vacation Leave" leave type for Manila via POST /office/leave-types (deliberately a
#   fresh type rather than reusing CompanySeeder's own seeded `vl`, so this script proves
#   the create path too, not just the grant path against a type that already existed).
#   She confirms Manila's leave day is the default 480 minutes (8h) via PATCH
#   /office/leave-day, then grants Miguel Santos (employee.manila@hris.test, MNL-0002)
#   5 days of it through POST /leave/grants — one append-only leave_ledger credit row of
#   2400 minutes (5 * 480), never a stored balance column. Miguel then reads GET /me/leave
#   and sees the SAME 2400 minutes, decomposed into the readable
#   {days:5, hours:0, minutes:0} shape LeaveUnit::readable() produces from the office's
#   leave day.
#
#   Separately, Carmen tries to grant Manila's seeded Maternity Leave — an EVENT type
#   (deducts_balance: false, an entitlement keyed to an event, not a balance) — and the
#   domain guard (LeaveTypeNotGrantable) refuses it 422 leave_type_not_grantable, never a
#   silent no-op or a ledger row for a type that has no balance to credit.
#
# One thing this script deliberately does NOT assert via HTTP: that exactly one
# leave_ledger row exists for the grant — there is no GET endpoint for the raw ledger yet
# (only the DERIVED balance reads, /me/leave and /employees/{employee}/leave). It is read
# via `psql`, the same "go straight at the database for what has no HTTP surface yet"
# exception scripts/e2e-recompute.sh takes for recompute_runs/attendance_logs and
# scripts/e2e-adjustments.sh takes for attendance_annulments.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, HR Admin scoped to Manila HQ
# only) and employee.manila@hris.test (Miguel Santos, MNL-0002). Both password `password`.
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

psql_() {   # $1 sql -> prints the -tAc result, against the compose db
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db psql -U hris -d hris -tAc "$1"
}

# 1. Log in as Carmen (Manila HR, who configures and grants) and Miguel (the employee
#    whose balance gets read).
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"

ME=$(curl -sf "$API/me" -H "$H")
MANILA_ID=$(echo "$ME" | jq -r '.data.hr_offices[0]')
echo "1. hr.manila and employee.manila logged in; hr.manila's hr_offices[0]=$MANILA_ID (Manila HQ)"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

echo "$ME" | jq -e '.data.permissions | index("leave.manage")' >/dev/null \
  || { echo "FAIL: hr.manila does not hold leave.manage — did RbacSeeder run with M6b-a's catalog?"; exit 1; }
echo "   hr.manila holds leave.manage (catalogued alongside holiday.manage/schedule.manage; enforcement is via OfficeScope, same as those)"

MIGUEL_ME=$(curl -sf "$API/me" -H "$E")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "   employee.manila's employee id (Miguel Santos): $MIGUEL_ID"
[ "$(echo "$MIGUEL_ME" | jq -r .data.employee.employee_no)" = "MNL-0002" ] \
  || { echo "FAIL: employee.manila is not seeded as MNL-0002 — did the seeder run?"; exit 1; }

# 2. Carmen creates a NEW "Vacation Leave" leave type for Manila (deliberately a fresh
#    type, not CompanySeeder's own seeded `vl`, so this script proves the create path
#    too). Paid, no attachment required, banks a balance, not cash-convertible, unlimited
#    carryover (all deferred past M6b-a — see docs/06-roadmap.md).
CREATE=$(curl -sf -X POST "$API/office/leave-types" -H "$H" -H "$J" -d "{
    \"office_id\": \"$MANILA_ID\",
    \"name\": \"E2E Vacation Leave\",
    \"is_paid\": true,
    \"requires_attachment\": false,
    \"deducts_balance\": true,
    \"is_cash_convertible\": false,
    \"max_carryover_minutes\": null
  }")
VL_ID=$(echo "$CREATE" | jq -r .data.id)
echo "2. hr.manila created a Manila leave type: $(echo "$CREATE" | jq -c '.data | {id,name,deducts_balance}')"
[ -n "$VL_ID" ] && [ "$VL_ID" != "null" ] || { echo "FAIL: leave-type create did not return an id"; exit 1; }
[ "$(echo "$CREATE" | jq -r .data.deducts_balance)" = "true" ] || { echo "FAIL: new leave type is not deducts_balance"; exit 1; }

# 3. Confirm Manila's leave day is (still) the default 480 minutes (8h) — the write is
#    idempotent, so this both proves the default and leaves it exactly where it started.
LEAVE_DAY=$(curl -sf -X PATCH "$API/office/leave-day" -H "$H" -H "$J" -d "{
    \"office_id\": \"$MANILA_ID\",
    \"minutes_per_leave_day\": 480
  }")
echo "3. PATCH /office/leave-day (Manila): $(echo "$LEAVE_DAY" | jq -c .data)"
[ "$(echo "$LEAVE_DAY" | jq -r .data.minutes_per_leave_day)" = "480" ] \
  || { echo "FAIL: Manila's minutes_per_leave_day is not 480"; exit 1; }

# Snapshot the ledger row count for this leave type now — 0, since M6b-a's create path
# never writes a ledger row, only the grant below does.
LEDGER_BEFORE=$(psql_ "select count(*) from leave_ledger where leave_type_id = '$VL_ID'" | tr -d '[:space:]')
echo "   leave_ledger rows for this leave type before the grant: $LEDGER_BEFORE"
[ "$LEDGER_BEFORE" = "0" ] || { echo "FAIL: a leave type that was just created already has ledger rows"; exit 1; }

# 4. Carmen grants Miguel 5 days of it. One append-only credit row, 5 * 480 = 2400
#    minutes — GrantLeave never touches a balance column because there isn't one.
GRANT=$(curl -sf -X POST "$API/leave/grants" -H "$H" -H "$J" -d "{
    \"employee_id\": \"$MIGUEL_ID\",
    \"leave_type_id\": \"$VL_ID\",
    \"amount\": 5,
    \"unit\": \"day\",
    \"reason\": \"E2E: manual back-fill of unused VL\"
  }")
LEDGER_ID=$(echo "$GRANT" | jq -r .data.id)
echo "4. POST /leave/grants (5 days -> Miguel): $(echo "$GRANT" | jq -c '.data | {id,entry_type,minutes,source}')"
[ -n "$LEDGER_ID" ] && [ "$LEDGER_ID" != "null" ] || { echo "FAIL: grant did not return a ledger row id"; exit 1; }
[ "$(echo "$GRANT" | jq -r .data.entry_type)" = "credit" ] || { echo "FAIL: grant did not create a credit row"; exit 1; }
[ "$(echo "$GRANT" | jq -r .data.minutes)" = "2400" ] || { echo "FAIL: grant is not 2400 minutes (5 days * 480 min/day)"; exit 1; }
[ "$(echo "$GRANT" | jq -r .data.source)" = "manual_grant" ] || { echo "FAIL: grant's source is not manual_grant"; exit 1; }

# Exactly one ledger row now exists for this employee+leave type — the grant appended one
# row, it did not update anything and did not create a stray second one. There is no GET
# endpoint for the raw ledger, so this is read via psql (see the header note above).
LEDGER_AFTER=$(psql_ "select count(*) from leave_ledger where leave_type_id = '$VL_ID' and employee_id = '$MIGUEL_ID'" | tr -d '[:space:]')
echo "   leave_ledger rows for Miguel + this leave type after the grant: $LEDGER_AFTER (expect 1)"
[ "$LEDGER_AFTER" = "1" ] || { echo "FAIL: expected exactly one leave_ledger row after the grant, got $LEDGER_AFTER"; exit 1; }

ROW=$(psql_ "select entry_type || '|' || minutes || '|' || source from leave_ledger where id = '$LEDGER_ID'")
echo "   that row: $ROW (expect credit|2400|manual_grant)"
[ "$ROW" = "credit|2400|manual_grant" ] || { echo "FAIL: the ledger row's raw content doesn't match the API response"; exit 1; }

# 5. Miguel reads his OWN balances. GET /me/leave shows the SAME 2400 minutes — derived
#    fresh from the ledger, never a stored field — decomposed into 5 days / 0 hours /
#    0 minutes (LeaveUnit::readable() against Manila's 480-minute leave day).
MY_LEAVE=$(curl -sf "$API/me/leave" -H "$E")
MY_ROW=$(echo "$MY_LEAVE" | jq -c --arg id "$VL_ID" '.data[] | select(.leave_type.id == $id)')
echo "5. GET /me/leave as Miguel, the new type's row: $MY_ROW"
[ -n "$MY_ROW" ] || { echo "FAIL: the new leave type does not appear on Miguel's /me/leave at all"; exit 1; }
[ "$(echo "$MY_ROW" | jq -r .balance_minutes)" = "2400" ] || { echo "FAIL: Miguel's balance_minutes is not 2400"; exit 1; }
[ "$(echo "$MY_ROW" | jq -r .balance_readable.days)" = "5" ] || { echo "FAIL: Miguel's balance_readable.days is not 5"; exit 1; }
[ "$(echo "$MY_ROW" | jq -r .balance_readable.hours)" = "0" ] || { echo "FAIL: Miguel's balance_readable.hours is not 0"; exit 1; }
[ "$(echo "$MY_ROW" | jq -r .balance_readable.minutes)" = "0" ] || { echo "FAIL: Miguel's balance_readable.minutes is not 0"; exit 1; }

# 6. Carmen tries to grant Manila's seeded Maternity Leave — an EVENT type
#    (deducts_balance: false). The domain guard refuses it: 422
#    leave_type_not_grantable, no ledger row written for a type that has no balance.
TYPES=$(curl -sf "$API/office/leave-types?office=$MANILA_ID" -H "$H")
MATERNITY_ID=$(echo "$TYPES" | jq -r '.data[] | select(.code == "maternity") | .id')
echo "6. Manila's seeded Maternity Leave (event type, deducts_balance=false): id=$MATERNITY_ID"
[ -n "$MATERNITY_ID" ] && [ "$MATERNITY_ID" != "null" ] || { echo "FAIL: no seeded maternity leave type found for Manila — did the seeder run?"; exit 1; }

LEDGER_COUNT_BEFORE=$(psql_ "select count(*) from leave_ledger" | tr -d '[:space:]')

EVENT_GRANT=$(curl -s -w '\n%{http_code}' -X POST "$API/leave/grants" -H "$H" -H "$J" -d "{
    \"employee_id\": \"$MIGUEL_ID\",
    \"leave_type_id\": \"$MATERNITY_ID\",
    \"amount\": 1,
    \"unit\": \"day\",
    \"reason\": \"E2E: should be refused\"
  }")
EVENT_GRANT_BODY=$(echo "$EVENT_GRANT" | head -n -1)
EVENT_GRANT_STATUS=$(echo "$EVENT_GRANT" | tail -n 1)
echo "   POST /leave/grants against Maternity Leave: HTTP $EVENT_GRANT_STATUS code=$(echo "$EVENT_GRANT_BODY" | jq -r .error.code) (expect 422 / leave_type_not_grantable)"
[ "$EVENT_GRANT_STATUS" = "422" ] || { echo "FAIL: granting an event type was not refused with 422"; exit 1; }
[ "$(echo "$EVENT_GRANT_BODY" | jq -r .error.code)" = "leave_type_not_grantable" ] \
  || { echo "FAIL: wrong error code refusing a grant against an event type"; exit 1; }

LEDGER_COUNT_AFTER=$(psql_ "select count(*) from leave_ledger" | tr -d '[:space:]')
echo "   leave_ledger row count: $LEDGER_COUNT_BEFORE -> $LEDGER_COUNT_AFTER (expect unchanged — the refused grant wrote nothing)"
[ "$LEDGER_COUNT_BEFORE" = "$LEDGER_COUNT_AFTER" ] \
  || { echo "FAIL: the refused event-type grant still wrote a leave_ledger row"; exit 1; }

echo "OK: hr.manila (Manila HR) created a new office-scoped leave type, confirmed Manila's"
echo "    480-minute leave day, and granted Miguel Santos 5 days of it through one"
echo "    append-only leave_ledger credit row (2400 minutes, source=manual_grant) — no"
echo "    balance column touched anywhere. Miguel's own GET /me/leave shows the SAME 2400"
echo "    minutes, decomposed into {days:5, hours:0, minutes:0}. A grant against Manila's"
echo "    seeded Maternity Leave (an event type, deducts_balance=false) was refused 422"
echo "    leave_type_not_grantable and wrote no ledger row at all — all against the live"
echo "    stack. Taking leave, its approval, and accrual remain out of scope: M6b-a is"
echo "    config plus manual grants plus derived reads, nothing more."
