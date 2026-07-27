#!/bin/bash
#
# M6c 'overtime pre-authorization' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack (`make dev` then `php artisan migrate:fresh --seed`). It proves the
# NEW M6c surface no earlier script exercises — that the compute engine pays exactly
# min(actual_overtime, approved_overtime) and books the rest as unpaid excess — and, by
# invoking scripts/e2e-leave.sh unchanged at the end, that the M6b leave machine still
# works alongside it: the two request paths coexist on one stack.
#
# The overtime model M6c enforces is STRICT: overtime is only ever paid when it was
# pre-authorized. An employee who simply stays late earns nothing for the excess — it is
# recorded as `daily_attendance_summaries.unpaid_overtime_minutes`, never a paid line — and
# a pre-authorization caps the paid overtime at the approved minutes, not the worked ones.
# Compute reads that cap as min(actual, approved); this script drives both edges of it.
#
#   Miguel Santos (employee.manila@hris.test, MNL-0002) works two long days, each 08:00 to
#   20:00 Manila — twelve gross hours, an hour of which is his meal break, so eleven net
#   against a nine-scheduled-hour Mon-Fri shift: real, worked overtime on each day. The
#   days are recorded through hr.manila's manual-punch backfill (POST /admin/attendance/punch)
#   rather than self-service, because that is the one punch route that takes an explicit
#   `punched_at`, so the script can place a controlled long day on a far-future weekday
#   without waiting real hours — and RecordPunch computes each day synchronously on the
#   out-punch, so the summary exists to read the moment the punch returns.
#
#     - Day 1 (the CAP): read BEFORE any request — overtime is unauthorized, so the compute
#       engine pays ZERO of it and the whole overtime span reads as unpaid excess. That read
#       IS how the script learns the day's actual overtime (ACTUAL_OT). Miguel then files
#       POST /overtime/requests for ONE hour (60 min) — deliberately FEWER minutes than he
#       actually worked over — which, because overtime is single-hop, appears at once on
#       BOTH his manager's /team/approvals AND office HR's /office/approvals (a single-hop
#       pending request is actionable by either; see ApprovalQueues::hrOfficesOf). His
#       manager approves it — the single approval IS the final hop, so it lands `approved`
#       and OvertimeEffect enqueues a recompute of that one day. Once the queue drains, the
#       day re-prices: exactly 60 paid overtime minutes (min(ACTUAL_OT, 60) = 60, the cap
#       biting), and unpaid_overtime_minutes drops to ACTUAL_OT - 60. The approval WROTE
#       nothing to any ledger — the approved request plus its overtime_details.minutes IS
#       the authorization; the number moved purely because compute now reads a non-zero cap.
#
#     - Day 2 (UNAUTHORIZED): the same long day with NO request ever filed. Its overtime
#       line minutes are ZERO and unpaid_overtime_minutes is its FULL overtime — the strict
#       model with nothing to soften it, the exact state Day 1 was in before its approval.
#
#   Then scripts/e2e-leave.sh runs unmodified and must pass: the grant -> file -> manager ->
#   HR -> ledger-debit -> leave_with_pay chain, proving overtime pre-authorization did not
#   disturb the leave path — the two coexist on one live stack.
#
# The overtime dates live ~100 days out, clear of CompanySeeder's seeded attendance (the
# current month, 2026-01-15, 2026-08-21) and of e2e-leave.sh's own +60-day leave window, so
# the two halves never touch the same employee-day. The script also clears its own two
# overtime dates (summaries, the overtime request, and the punches) at the DB before it
# starts, so it is safe to rerun without a reseed — a second manual in/out pair on the same
# day would otherwise double the worked total and change the arithmetic under it.
#
# Seeded logins used here: employee.manila@hris.test (Miguel Santos, MNL-0002, the worker
# and requester), manager.manila@hris.test (Rosa Bautista, Miguel's manager, who approves
# the overtime), hr.manila@hris.test (Carmen Lim, Manila HR, who backfills the punches).
# All password `password`.
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

drain_queue() {   # runs the queued RecomputeDay batch an overtime approval enqueues, to completion
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T --user hris api \
    php artisan queue:work --stop-when-empty
}

# hr.manila backfills one punch for Miguel at a specific Manila-local time (the one punch
# route that takes an explicit punched_at). $1 employee_id, $2 in|out, $3 date, $4 HH:MM.
manual_punch() {
  curl -sf -X POST "$API/admin/attendance/punch" -H "$H" -H "$J" -d "{
      \"employee_id\": \"$1\",
      \"direction\": \"$2\",
      \"punched_at\": \"${3}T${4}:00+08:00\"
    }"
}

# Sum of a day's PAID overtime line minutes (overtime_day + overtime_night). $1 = a single
# day object from /me/attendance/summary.
ot_line_minutes() {
  echo "$1" | jq '[.lines[] | select(.kind == "overtime_day" or .kind == "overtime_night") | .minutes] | add // 0'
}

# 1. Log in as the worker/requester (Miguel), his manager (Rosa, who approves the overtime),
#    and Manila HR (Carmen, who backfills the punches).
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"

MANAGER_TOKEN=$(login manager.manila@hris.test password)
M="Authorization: Bearer $MANAGER_TOKEN"

HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

MIGUEL_ME=$(curl -sf "$API/me" -H "$E")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "1. employee.manila (Miguel), manager.manila (Rosa), hr.manila (Carmen) logged in; Miguel=$MIGUEL_ID"
[ "$(echo "$MIGUEL_ME" | jq -r .data.employee.employee_no)" = "MNL-0002" ] \
  || { echo "FAIL: employee.manila is not seeded as MNL-0002 — did the seeder run?"; exit 1; }

# Two far-future weekdays for the long days: snap ~100 days out to the next Monday (Day 1)
# and take the following Tuesday (Day 2). Both are ordinary Mon-Fri working days, clear of
# every seeded holiday/attendance and of e2e-leave.sh's +60-day leave window.
BASE=$(date -d "+100 days" +%Y-%m-%d)
DOW=$(date -d "$BASE" +%u)                 # 1=Monday..7=Sunday
OFFSET=$(( (8 - DOW) % 7 ))                 # days to the next Monday on/after BASE
D1=$(date -d "$BASE +$OFFSET days" +%Y-%m-%d)
D2=$(date -d "$D1 +1 day" +%Y-%m-%d)       # the following Tuesday
MONTH_D1="${D1:0:7}"
MONTH_D2="${D2:0:7}"
echo "   overtime test days: Day1=$D1 (cap), Day2=$D2 (unauthorized)"

# Clean this script's own two dates so it is rerunnable without a reseed: drop any overtime
# request for those dates (cascades overtime_details), the day summaries (cascades their
# lines), and the punches. A leftover in/out pair would otherwise double the worked total.
psql_ "delete from requests where type = 'overtime' and employee_id = '$MIGUEL_ID' and id in (select request_id from overtime_details where date in ('$D1','$D2'))" >/dev/null
psql_ "delete from daily_attendance_summaries where employee_id = '$MIGUEL_ID' and date in ('$D1','$D2')" >/dev/null
psql_ "delete from attendance_logs where employee_id = '$MIGUEL_ID' and (punched_at AT TIME ZONE 'Asia/Manila')::date in ('$D1','$D2')" >/dev/null
echo "   cleared prior state for $D1/$D2 (rerun-safe)"

# 2. hr.manila backfills Day 1 as a long day: 08:00-20:00 Manila (12h gross, 1h meal break,
#    so 11h net) against a 9h scheduled shift. RecordPunch computes the day synchronously on
#    the out-punch, so the summary is ready to read immediately.
manual_punch "$MIGUEL_ID" in  "$D1" "08:00" >/dev/null
manual_punch "$MIGUEL_ID" out "$D1" "20:00" >/dev/null
echo "2. hr.manila backfilled Miguel's Day 1 ($D1): in 08:00, out 20:00 Manila"

# 3. Read Day 1 BEFORE any overtime request. Unauthorized, so compute pays ZERO overtime and
#    the entire overtime span reads as unpaid excess — and that read is how we learn the
#    day's ACTUAL overtime.
SUMMARY_D1_PRE=$(curl -sf "$API/me/attendance/summary?month=$MONTH_D1" -H "$E")
DAY1_PRE=$(echo "$SUMMARY_D1_PRE" | jq -c --arg d "$D1" '.data[] | select(.date == $d)')
echo "3. GET /me/attendance/summary?month=$MONTH_D1 (before any request), $D1: $(echo "$DAY1_PRE" | jq -c '{worked_minutes,scheduled_minutes,unpaid_overtime_minutes,lines}')"
[ -n "$DAY1_PRE" ] || { echo "FAIL: no summary computed for $D1 — did the manual punch/compute run?"; exit 1; }

SCHED_D1=$(echo "$DAY1_PRE" | jq -r '.scheduled_minutes')
WORKED_D1=$(echo "$DAY1_PRE" | jq -r '.worked_minutes')
ACTUAL_OT=$(echo "$DAY1_PRE" | jq -r '.unpaid_overtime_minutes')
OT_LINES_PRE=$(ot_line_minutes "$DAY1_PRE")

echo "   scheduled=$SCHED_D1 worked=$WORKED_D1 -> actual overtime=$ACTUAL_OT; paid overtime lines pre-request=$OT_LINES_PRE"
[ "$WORKED_D1" -gt "$SCHED_D1" ] || { echo "FAIL: Day 1 did not work longer than scheduled — no overtime to authorize"; exit 1; }
[ "$OT_LINES_PRE" = "0" ] \
  || { echo "FAIL: unauthorized overtime was paid a line before ANY request — the strict model must pay zero until approved"; exit 1; }
[ "$ACTUAL_OT" -gt 60 ] \
  || { echo "FAIL: Day 1's actual overtime ($ACTUAL_OT) is not above the 60 min this script authorizes — the cap could not visibly bite"; exit 1; }

# 4. Miguel files a pre-authorization for ONE hour — deliberately fewer minutes than he
#    worked over. 201, pending, detail carries the date and the 60 converted minutes.
APPROVE_MIN=60
SUBMIT=$(curl -sf -X POST "$API/overtime/requests" -H "$E" -H "$J" -d "{
    \"date\": \"$D1\",
    \"hours\": 1,
    \"note\": \"E2E: pre-authorize 1h of the overtime worked on $D1\"
  }")
OT_REQ_ID=$(echo "$SUBMIT" | jq -r .data.id)
echo "4. POST /overtime/requests (1h): id=$OT_REQ_ID state=$(echo "$SUBMIT" | jq -r .data.state) detail=$(echo "$SUBMIT" | jq -c .data.detail)"
[ -n "$OT_REQ_ID" ] && [ "$OT_REQ_ID" != "null" ] || { echo "FAIL: overtime submit did not return an id"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.state)" = "pending" ] || { echo "FAIL: a fresh overtime request should start pending"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.detail.minutes)" = "$APPROVE_MIN" ] || { echo "FAIL: overtime detail minutes is not 60 (1h)"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.detail.date)" = "$D1" ] || { echo "FAIL: overtime detail date is not $D1"; exit 1; }

# 5. Single-hop, so the pending request is actionable by BOTH the manager (/team) and office
#    HR (/office) at once — unlike a two-hop leave, which reaches /office only after hop 1.
IN_TEAM=$(curl -sf "$API/team/approvals" -H "$M" | jq --arg id "$OT_REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "5. GET /team/approvals as manager.manila: contains $OT_REQ_ID -> count=$IN_TEAM (expect 1)"
[ "$IN_TEAM" = "1" ] || { echo "FAIL: the pending overtime request is not on manager.manila's /team/approvals"; exit 1; }

IN_OFFICE=$(curl -sf "$API/office/approvals" -H "$H" | jq --arg id "$OT_REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /office/approvals as hr.manila: contains $OT_REQ_ID -> count=$IN_OFFICE (expect 1 — single-hop is HR's the moment it's pending)"
[ "$IN_OFFICE" = "1" ] || { echo "FAIL: the pending single-hop overtime request is not on hr.manila's /office/approvals"; exit 1; }

# 6. The manager approves. Single-hop, so this one decision IS the final hop: the request
#    lands `approved` and OvertimeEffect enqueues a recompute of $D1.
OT_APPROVE=$(curl -sf -X POST "$API/requests/$OT_REQ_ID/approve" -H "$M")
echo "6. POST /requests/$OT_REQ_ID/approve as manager.manila: state=$(echo "$OT_APPROVE" | jq -r .data.state) (expect approved — single-hop, final)"
[ "$(echo "$OT_APPROVE" | jq -r .data.state)" = "approved" ] \
  || { echo "FAIL: the single approval of a single-hop overtime request did not reach approved"; exit 1; }

echo "   draining the queue (php artisan queue:work --stop-when-empty)..."
drain_queue

# 7. Re-read Day 1. The cap now bites: exactly 60 paid overtime minutes (min(ACTUAL_OT, 60)),
#    and unpaid_overtime_minutes drops to ACTUAL_OT - 60. Nothing was written to a ledger —
#    the approved request's minutes ARE the authorization compute reads.
SUMMARY_D1_POST=$(curl -sf "$API/me/attendance/summary?month=$MONTH_D1" -H "$E")
DAY1_POST=$(echo "$SUMMARY_D1_POST" | jq -c --arg d "$D1" '.data[] | select(.date == $d)')
OT_LINES_POST=$(ot_line_minutes "$DAY1_POST")
UNPAID_POST=$(echo "$DAY1_POST" | jq -r '.unpaid_overtime_minutes')
EXPECTED_UNPAID=$((ACTUAL_OT - APPROVE_MIN))
echo "7. $D1 after approval: $(echo "$DAY1_POST" | jq -c '{unpaid_overtime_minutes,lines}')"
echo "   paid overtime line minutes=$OT_LINES_POST (expect $APPROVE_MIN = min(actual $ACTUAL_OT, approved 60))"
[ "$OT_LINES_POST" = "$APPROVE_MIN" ] \
  || { echo "FAIL: paid overtime is not the 60-minute cap — compute did not apply min(actual, approved)"; exit 1; }
echo "   unpaid_overtime_minutes=$UNPAID_POST (expect $EXPECTED_UNPAID = actual $ACTUAL_OT - approved 60)"
[ "$UNPAID_POST" = "$EXPECTED_UNPAID" ] \
  || { echo "FAIL: unpaid excess is not (actual overtime - approved) after the cap"; exit 1; }

# 8. Day 2: the same long day, NO request. The strict model with nothing to soften it —
#    zero paid overtime, the full overtime span unpaid.
manual_punch "$MIGUEL_ID" in  "$D2" "08:00" >/dev/null
manual_punch "$MIGUEL_ID" out "$D2" "20:00" >/dev/null
SUMMARY_D2=$(curl -sf "$API/me/attendance/summary?month=$MONTH_D2" -H "$E")
DAY2=$(echo "$SUMMARY_D2" | jq -c --arg d "$D2" '.data[] | select(.date == $d)')
OT_LINES_D2=$(ot_line_minutes "$DAY2")
UNPAID_D2=$(echo "$DAY2" | jq -r '.unpaid_overtime_minutes')
echo "8. Day 2 ($D2), long day with NO overtime request: $(echo "$DAY2" | jq -c '{worked_minutes,scheduled_minutes,unpaid_overtime_minutes,lines}')"
[ -n "$DAY2" ] || { echo "FAIL: no summary computed for $D2"; exit 1; }
[ "$OT_LINES_D2" = "0" ] \
  || { echo "FAIL: Day 2 has PAID overtime with no request on file — unauthorized overtime must pay zero"; exit 1; }
[ "$UNPAID_D2" -gt 0 ] \
  || { echo "FAIL: Day 2 recorded no unpaid overtime despite working past schedule"; exit 1; }
[ "$UNPAID_D2" = "$ACTUAL_OT" ] \
  || { echo "FAIL: Day 2's unpaid overtime ($UNPAID_D2) does not equal the full worked overtime ($ACTUAL_OT) for the identical long day"; exit 1; }

echo "   Day 2: paid overtime=$OT_LINES_D2, unpaid_overtime_minutes=$UNPAID_D2 (= full overtime, unauthorized)"

# 9. Leave still works: run the M6b leave chain unmodified against the same live stack. It
#    grants, files, approves through both hops, debits the ledger, and prices leave_with_pay
#    — proving overtime pre-authorization did not disturb the leave path.
echo "9. running scripts/e2e-leave.sh (the M6b leave chain) against the same stack..."
API="$API" bash "$REPO_ROOT/scripts/e2e-leave.sh"
echo "   e2e-leave.sh passed — leave and overtime coexist."

echo "OK: Miguel worked two identical long days (08:00-20:00 Manila, $ACTUAL_OT min of real"
echo "    overtime each). Day 1, read before any request, paid ZERO overtime and booked all"
echo "    $ACTUAL_OT as unpaid excess; his 1-hour pre-authorization appeared at once on BOTH"
echo "    his manager's /team/approvals and HR's /office/approvals; the manager's single"
echo "    (final) approval re-priced the day to exactly 60 paid overtime minutes — the cap,"
echo "    min(actual, approved) — with unpaid_overtime_minutes dropping to $EXPECTED_UNPAID,"
echo "    no ledger touched. Day 2, an identical long day with NO request, paid zero overtime"
echo "    and booked the full $ACTUAL_OT as unpaid — the strict model. And the M6b leave"
echo "    chain still passed end to end — all against the live stack."
