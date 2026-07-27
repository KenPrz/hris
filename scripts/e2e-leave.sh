#!/bin/bash
#
# M6b-b 'the two-hop leave machine' — the milestone's end-to-end proof, runnable against
# a freshly seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-requests.sh's generalized-route idiom and
# scripts/e2e-leave-foundation.sh's grant/balance idiom, and proves the NEW M6b-b surface
# neither of those exercises: an employee filing their OWN leave request, the two-hop
# (manager -> HR) decision chain a two-hop RequestType actually takes, and the ledger
# debit that only ever fires on the FINAL hop.
#
#   Carmen Lim (hr.manila@hris.test, Manila HR) grants Miguel Santos
#   (employee.manila@hris.test, MNL-0002) 10 days of Manila's seeded Vacation Leave (`vl`,
#   deducts_balance=true) via POST /leave/grants — the same append-only credit
#   scripts/e2e-leave-foundation.sh already proved, just enough balance for what follows.
#
#   Miguel then files a full-day, 3-scheduled-working-day Vacation Leave request (a
#   Monday-Wednesday range on his Mon-Fri shift, chosen well past the seeded current
#   month so it can never collide with CompanySeeder's own seeded attendance history) via
#   POST /leave/requests. Because Leave is a two-hop RequestType
#   (RequestType::Leave->requiresHrStep() === true), it starts `pending` (Miguel has a
#   manager on file) and needs TWO decisions, not one:
#
#     - Rosa Bautista (manager.manila@hris.test, Miguel's manager) decides hop 1. The
#       request advances to `manager_approved` — NOT `approved` — and Miguel's VL balance
#       is completely UNCHANGED: LeaveEffect only debits on the FINAL (HR) hop, never the
#       manager's. The request also moves queues: off manager.manila's /team/approvals
#       (that hop is done) and onto hr.manila's /office/approvals (a two-hop request only
#       ever appears there once manager_approved — see ApprovalQueues::hrOfficesOf).
#
#     - Carmen (hr.manila) decides hop 2, the final hop. THIS is what fires LeaveEffect:
#       the request reaches `approved`, Miguel's balance drops by exactly
#       3 days * 480 min/day = 1440 minutes, and — once the queued recompute this
#       approval enqueues is drained — Miguel's own GET /me/attendance/summary shows a
#       `leave_with_pay` line on each of the three days (M6b-b's compute integration,
#       Task 9: a flat 100%, not priced from any pay_rules version).
#
#   A second, independent leave request (a different date range, so it can never overlap
#   the first) proves the other half of "the effect fires ONLY on final approval": Rosa
#   approves hop 1 as before, but this time Carmen REJECTS at hop 2. The request lands
#   `rejected` and Miguel's VL balance is untouched — a rejected final hop must debit
#   nothing, the same as a rejected hop 1 would.
#
# Raw `leave_ledger` debit-row counts are read via psql alongside every balance check —
# the same "go straight at the database for what a derived-balance read alone wouldn't
# prove conclusively" idiom scripts/e2e-leave-foundation.sh already uses for credits: a
# balance that happens to read the same before and after is consistent with "no debit
# happened," but it is also consistent with "a debit and an equal-and-opposite credit both
# happened," which the balance alone cannot rule out. Counting `leave_ledger` rows with
# `entry_type = 'debit'` directly closes that gap.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, Manila HR),
# manager.manila@hris.test (Rosa Bautista, Miguel's manager), employee.manila@hris.test
# (Miguel Santos, MNL-0002). All password `password`.
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

drain_queue() {   # runs the queued RecomputeDay batch a leave approval enqueues, to completion
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T --user hris api \
    php artisan queue:work --stop-when-empty
}

debit_count() {   # $1 employee_id, $2 leave_type_id -> count of leave_ledger debit rows
  psql_ "select count(*) from leave_ledger where employee_id = '$1' and leave_type_id = '$2' and entry_type = 'debit'" \
    | tr -d '[:space:]'
}

# 1. Log in as Carmen (HR, who grants and decides hop 2), Rosa (Miguel's manager, who
#    decides hop 1), and Miguel (the requester).
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

MANAGER_TOKEN=$(login manager.manila@hris.test password)
M="Authorization: Bearer $MANAGER_TOKEN"

EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"

ME_HR=$(curl -sf "$API/me" -H "$H")
MANILA_ID=$(echo "$ME_HR" | jq -r '.data.hr_offices[0]')
echo "1. hr.manila, manager.manila, employee.manila logged in; Manila office=$MANILA_ID"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

MIGUEL_ME=$(curl -sf "$API/me" -H "$E")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "   employee.manila's employee id (Miguel Santos): $MIGUEL_ID"
[ "$(echo "$MIGUEL_ME" | jq -r .data.employee.employee_no)" = "MNL-0002" ] \
  || { echo "FAIL: employee.manila is not seeded as MNL-0002 — did the seeder run?"; exit 1; }

TYPES=$(curl -sf "$API/office/leave-types?office=$MANILA_ID" -H "$H")
VL_ID=$(echo "$TYPES" | jq -r '.data[] | select(.code == "vl") | .id')
echo "   Manila's seeded Vacation Leave (vl, deducts_balance): id=$VL_ID"
[ -n "$VL_ID" ] && [ "$VL_ID" != "null" ] || { echo "FAIL: no seeded vl leave type found for Manila — did the seeder run?"; exit 1; }

# 2. Carmen grants Miguel 10 days of it — the M6b-a grant path, just enough balance for
#    what follows. 10 * 480 = 4800 minutes.
GRANT=$(curl -sf -X POST "$API/leave/grants" -H "$H" -H "$J" -d "{
    \"employee_id\": \"$MIGUEL_ID\",
    \"leave_type_id\": \"$VL_ID\",
    \"amount\": 10,
    \"unit\": \"day\",
    \"reason\": \"E2E: leave-taking balance seed\"
  }")
echo "2. hr.manila granted Miguel 10 days VL: $(echo "$GRANT" | jq -c '.data | {entry_type,minutes,source}')"
[ "$(echo "$GRANT" | jq -r .data.minutes)" = "4800" ] || { echo "FAIL: grant is not 4800 minutes (10 days * 480 min/day)"; exit 1; }

BALANCE_0=$(curl -sf "$API/me/leave" -H "$E" | jq -r --arg id "$VL_ID" '.data[] | select(.leave_type.id == $id) | .balance_minutes')
echo "   Miguel's VL balance after the grant: $BALANCE_0 minutes"
[ "$BALANCE_0" -ge 4800 ] || { echo "FAIL: Miguel's VL balance did not reflect the grant"; exit 1; }

DEBITS_0=$(debit_count "$MIGUEL_ID" "$VL_ID")
echo "   leave_ledger debit rows for Miguel+VL so far: $DEBITS_0 (baseline — this script diffs against it below, so it is safe to rerun even with rows left over from a previous run)"

# 3. Miguel files a full-day, 3-scheduled-working-day VL request: a Monday-Wednesday
#    range (weekdays 0-4 work 08:00-18:00, 5-6 rest — see shift_template_days), computed
#    well past the seeded current month so it can never collide with CompanySeeder's own
#    seeded attendance history for this employee.
BASE=$(date -d "+60 days" +%Y-%m-%d)
DOW=$(date -d "$BASE" +%u)              # 1=Monday..7=Sunday
OFFSET=$(( (8 - DOW) % 7 ))              # days to the next Monday on/after BASE
START=$(date -d "$BASE +$OFFSET days" +%Y-%m-%d)
END=$(date -d "$START +2 days" +%Y-%m-%d)   # Monday + 2 = Wednesday
echo "3. leave range chosen: $START .. $END (Mon-Wed, 3 scheduled working days)"

SUBMIT=$(curl -sf -X POST "$API/leave/requests" -H "$E" -H "$J" -d "{
    \"leave_type_id\": \"$VL_ID\",
    \"start_date\": \"$START\",
    \"end_date\": \"$END\",
    \"day_part\": \"full\",
    \"note\": \"E2E: VL request\"
  }")
REQ_ID=$(echo "$SUBMIT" | jq -r .data.id)
echo "   POST /leave/requests: id=$REQ_ID state=$(echo "$SUBMIT" | jq -r .data.state) amount_minutes=$(echo "$SUBMIT" | jq -r .data.detail.amount_minutes)"
[ -n "$REQ_ID" ] && [ "$REQ_ID" != "null" ] || { echo "FAIL: leave request submit did not return an id"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.state)" = "pending" ] \
  || { echo "FAIL: a fresh leave request for an employee WITH a manager on file should start pending"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.detail.amount_minutes)" = "1440" ] \
  || { echo "FAIL: leave_details amount_minutes is not 1440 (3 scheduled days * 480 min/day)"; exit 1; }

# 4. Pending: on the manager's /team/approvals, NOT yet on HR's /office/approvals — a
#    two-hop type is HR's business only once hop 1 clears (ApprovalQueues::hrOfficesOf).
IN_TEAM=$(curl -sf "$API/team/approvals" -H "$M" | jq --arg id "$REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "4. GET /team/approvals as manager.manila: contains $REQ_ID -> count=$IN_TEAM (expect 1)"
[ "$IN_TEAM" = "1" ] || { echo "FAIL: the pending leave request is not on manager.manila's /team/approvals"; exit 1; }

IN_OFFICE=$(curl -sf "$API/office/approvals" -H "$H" | jq --arg id "$REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /office/approvals as hr.manila: contains $REQ_ID -> count=$IN_OFFICE (expect 0 — hop 1 hasn't cleared)"
[ "$IN_OFFICE" = "0" ] || { echo "FAIL: a still-pending two-hop leave request must not appear on /office/approvals yet"; exit 1; }

# 5. manager.manila decides hop 1: pending -> manager_approved. No effect, no debit — the
#    balance and the raw debit-row count must be BYTE-IDENTICAL to before this call.
MGR_APPROVE=$(curl -sf -X POST "$API/requests/$REQ_ID/approve" -H "$M")
echo "5. POST /requests/$REQ_ID/approve as manager.manila (hop 1): state=$(echo "$MGR_APPROVE" | jq -r .data.state) (expect manager_approved)"
[ "$(echo "$MGR_APPROVE" | jq -r .data.state)" = "manager_approved" ] \
  || { echo "FAIL: manager's decision on a two-hop leave request did not land on manager_approved"; exit 1; }

BALANCE_1=$(curl -sf "$API/me/leave" -H "$E" | jq -r --arg id "$VL_ID" '.data[] | select(.leave_type.id == $id) | .balance_minutes')
echo "   Miguel's VL balance after hop 1: $BALANCE_1 (expect unchanged: $BALANCE_0)"
[ "$BALANCE_1" = "$BALANCE_0" ] \
  || { echo "FAIL: Miguel's VL balance changed after the MANAGER's (hop 1) decision — the ledger must stay untouched until HR's final approval"; exit 1; }

DEBITS_1=$(debit_count "$MIGUEL_ID" "$VL_ID")
echo "   leave_ledger debit rows: $DEBITS_1 (expect unchanged: $DEBITS_0)"
[ "$DEBITS_1" = "$DEBITS_0" ] \
  || { echo "FAIL: a leave_ledger debit row was written at hop 1 — LeaveEffect must only fire on the FINAL (HR) hop"; exit 1; }

# Now off /team/approvals (hop 1 is done), and on to /office/approvals (HR's hop 2).
STILL_TEAM=$(curl -sf "$API/team/approvals" -H "$M" | jq --arg id "$REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "   /team/approvals after hop 1: count=$STILL_TEAM (expect 0)"
[ "$STILL_TEAM" = "0" ] || { echo "FAIL: a manager_approved request still shows on /team/approvals"; exit 1; }

NOW_OFFICE=$(curl -sf "$API/office/approvals" -H "$H" | jq --arg id "$REQ_ID" '[.data[] | select(.id == $id)] | length')
echo "   /office/approvals after hop 1: count=$NOW_OFFICE (expect 1)"
[ "$NOW_OFFICE" = "1" ] || { echo "FAIL: a manager_approved leave request did not appear on hr.manila's /office/approvals"; exit 1; }

# 6. hr.manila decides hop 2, the FINAL hop: manager_approved -> approved. THIS is what
#    fires LeaveEffect — the balance drops by exactly 1440 minutes (3 * 480) and one new
#    leave_ledger debit row appears.
HR_APPROVE=$(curl -sf -X POST "$API/requests/$REQ_ID/approve" -H "$H")
echo "6. POST /requests/$REQ_ID/approve as hr.manila (hop 2, final): state=$(echo "$HR_APPROVE" | jq -r .data.state) (expect approved)"
[ "$(echo "$HR_APPROVE" | jq -r .data.state)" = "approved" ] \
  || { echo "FAIL: HR's decision on a manager_approved leave request did not reach approved"; exit 1; }

BALANCE_2=$(curl -sf "$API/me/leave" -H "$E" | jq -r --arg id "$VL_ID" '.data[] | select(.leave_type.id == $id) | .balance_minutes')
EXPECTED_BALANCE_2=$((BALANCE_0 - 1440))
echo "   Miguel's VL balance after hop 2 (final): $BALANCE_1 -> $BALANCE_2 (expect $EXPECTED_BALANCE_2)"
[ "$BALANCE_2" = "$EXPECTED_BALANCE_2" ] \
  || { echo "FAIL: Miguel's VL balance did not drop by exactly 1440 minutes after the FINAL (HR) approval"; exit 1; }

DEBITS_2=$(debit_count "$MIGUEL_ID" "$VL_ID")
echo "   leave_ledger debit rows: $DEBITS_1 -> $DEBITS_2 (expect exactly +1)"
[ "$DEBITS_2" -eq "$((DEBITS_1 + 1))" ] \
  || { echo "FAIL: expected exactly one new leave_ledger debit row after the final approval, got a delta of $((DEBITS_2 - DEBITS_1))"; exit 1; }

# The final approval also enqueued a recompute over the leave span (RecomputeTrigger::Leave)
# — QUEUE_CONNECTION=database, so nothing has actually recomputed yet until the queue is
# drained (same idiom as scripts/e2e-recompute.sh's holiday flip).
echo "   draining the queue (php artisan queue:work --stop-when-empty)..."
drain_queue

MONTH="${START:0:7}"
SUMMARY=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")
DAY2="$(date -d "$START +1 day" +%Y-%m-%d)"
for D in "$START" "$DAY2" "$END"; do
  DAY=$(echo "$SUMMARY" | jq -c --arg d "$D" '.data[] | select(.date == $d)')
  LWP_COUNT=$(echo "$DAY" | jq '[.lines[] | select(.kind == "leave_with_pay" and .applied_bp == 10000)] | length')
  echo "   /me/attendance/summary?month=$MONTH, $D: $(echo "$DAY" | jq -c '{date,rule_version_id,lines}')"
  [ "$LWP_COUNT" = "1" ] \
    || { echo "FAIL: $D has no leave_with_pay line at 10000bp (100%) after the approved leave's recompute drained"; exit 1; }
  [ "$(echo "$DAY" | jq -r '.rule_version_id')" = "null" ] \
    || { echo "FAIL: $D's rule_version_id is not null — a leave-only day must never be attributed to a pay_rules version"; exit 1; }
done

# 7. Second scenario, a different (non-overlapping) date range: hop 1 approves as before,
#    but hop 2 REJECTS. The balance must be untouched — a rejected final hop debits
#    nothing, exactly like a rejected hop 1 would.
START2=$(date -d "$START +7 days" +%Y-%m-%d)
END2=$(date -d "$START2 +2 days" +%Y-%m-%d)
echo "7. second scenario, leave range: $START2 .. $END2 (reject at HR's final hop)"

SUBMIT2=$(curl -sf -X POST "$API/leave/requests" -H "$E" -H "$J" -d "{
    \"leave_type_id\": \"$VL_ID\",
    \"start_date\": \"$START2\",
    \"end_date\": \"$END2\",
    \"day_part\": \"full\",
    \"note\": \"E2E: VL request, to be rejected at HR\"
  }")
REQ2_ID=$(echo "$SUBMIT2" | jq -r .data.id)
echo "   POST /leave/requests: id=$REQ2_ID state=$(echo "$SUBMIT2" | jq -r .data.state)"
[ -n "$REQ2_ID" ] && [ "$REQ2_ID" != "null" ] || { echo "FAIL: second leave request submit did not return an id"; exit 1; }
[ "$(echo "$SUBMIT2" | jq -r .data.state)" = "pending" ] || { echo "FAIL: second leave request is not pending"; exit 1; }

MGR_APPROVE2=$(curl -sf -X POST "$API/requests/$REQ2_ID/approve" -H "$M")
echo "   manager.manila approves hop 1: state=$(echo "$MGR_APPROVE2" | jq -r .data.state) (expect manager_approved)"
[ "$(echo "$MGR_APPROVE2" | jq -r .data.state)" = "manager_approved" ] || { echo "FAIL: hop 1 approval on the second request failed"; exit 1; }

HR_REJECT=$(curl -sf -X POST "$API/requests/$REQ2_ID/reject" -H "$H" -H "$J" \
  -d '{"decision_note":"E2E: rejected at HR on purpose — proving no debit on a rejected final hop."}')
echo "   hr.manila rejects hop 2 (final): state=$(echo "$HR_REJECT" | jq -r .data.state) (expect rejected)"
[ "$(echo "$HR_REJECT" | jq -r .data.state)" = "rejected" ] || { echo "FAIL: HR's rejection at hop 2 did not land on rejected"; exit 1; }

BALANCE_3=$(curl -sf "$API/me/leave" -H "$E" | jq -r --arg id "$VL_ID" '.data[] | select(.leave_type.id == $id) | .balance_minutes')
echo "   Miguel's VL balance after the HR rejection: $BALANCE_3 (expect unchanged: $BALANCE_2)"
[ "$BALANCE_3" = "$BALANCE_2" ] \
  || { echo "FAIL: Miguel's VL balance changed after a request that was REJECTED at the final hop — rejection must never debit"; exit 1; }

DEBITS_3=$(debit_count "$MIGUEL_ID" "$VL_ID")
echo "   leave_ledger debit rows: $DEBITS_2 -> $DEBITS_3 (expect unchanged)"
[ "$DEBITS_3" = "$DEBITS_2" ] \
  || { echo "FAIL: a leave_ledger debit row was written for a request that was rejected at its final hop"; exit 1; }

echo "OK: hr.manila granted Miguel Santos 10 days of Vacation Leave; Miguel filed a"
echo "    3-scheduled-working-day VL request that started pending and needed BOTH hops —"
echo "    manager.manila's hop-1 approval moved it to manager_approved with the balance"
echo "    and the raw leave_ledger debit-row count completely UNCHANGED, and moved the"
echo "    request from /team/approvals to /office/approvals; hr.manila's hop-2 (final)"
echo "    approval is what actually debited the ledger — balance down by exactly 1440"
echo "    minutes (3 * 480), one new debit row — and, once the queued recompute drained,"
echo "    each of the three days showed a leave_with_pay line at 100% with rule_version_id"
echo "    null (M6b-b's compute integration, never attributed to a pay_rules version). A"
echo "    second, non-overlapping leave request proved the mirror case: manager-approved,"
echo "    then REJECTED at HR's final hop, leaving the balance and the debit-row count"
echo "    untouched — all against the live stack."
