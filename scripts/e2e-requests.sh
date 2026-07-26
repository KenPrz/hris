#!/bin/bash
#
# M6a 'the approval spine' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-recompute.sh's structure and scripts/e2e-adjustments.sh's seeded logins and
# JSON-submit idiom (no attachment this time — that path is already proven live by
# e2e-adjustments.sh; this script's job is the NEW M6a surface: the two scoped queues and
# the generalized /requests/* routes). It walks the whole file-then-decide path against the
# real API:
#
#   employee.manila (Miguel Santos, MNL-0002) has CompanySeeder's one seeded "incomplete"
#   day this month — a clock-in with no matching clock-out, computed as zero worked minutes
#   and `is_incomplete: true` (see CompanySeeder::seedCurrentMonthAttendance's docblock).
#   Miguel files an `add` adjustment for the missing clock-out; the request lands on BOTH
#   his manager's `/team/approvals` and his office HR's `/office/approvals` — the two
#   scope-based queues M6a replaced the old single combined pending list with — proving
#   neither queue is type-specific and both see the same pending row. manager.manila (Rosa
#   Bautista, Miguel's direct reports_to) then approves it through the generalized
#   `/requests/{id}/approve` route (not the old `/attendance/adjustments/{id}/approve` M3.6
#   used — see docs/03-api.md's M6a note). Approval re-prices the day synchronously (M5a's
#   on-write trigger, not a queued recompute — there is nothing to drain here, unlike
#   e2e-recompute.sh's holiday flip): Miguel's SAME `is_incomplete` day now pairs into a real
#   `regular_day` line. And the pre-existing `attendance_logs` rows — the ones that existed
#   before this script ever ran — are BYTE-IDENTICAL, same ids, same content, in the same
#   relative order; the approved add is proven to be a genuinely NEW row, appended, never a
#   mutation of anything already there.
#
# The target day is found DYNAMICALLY (`GET /me/attendance/summary`, `is_incomplete: true`),
# not hardcoded — CompanySeeder anchors its "one incomplete day" to whatever "today" was when
# the seeder ran (`seedCurrentMonthAttendance`'s docblock: "Anchored to the office's own local
# 'today', never a fixed calendar date"), so a fixed date here would silently stop matching
# the seed on any day but the one it was written against.
#
# Seeded logins used here: employee.manila@hris.test (Miguel Santos, MNL-0002),
# manager.manila@hris.test (Rosa Bautista, Miguel's direct reports_to), hr.manila@hris.test
# (Carmen Lim, HR Admin scoped to Manila HQ only). All password `password`.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'
MONTH="$(date +%Y-%m)"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

psql_() {   # $1 sql -> prints the -tAc result, against the compose db
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db psql -U hris -d hris -tAc "$1"
}

# 1. Log in as Miguel (the requester) and his two authorized approvers.
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
EMP_ID=$(curl -sf "$API/me" -H "$E" | jq -r .data.employee.id)
echo "1. employee.manila logged in; employee_id=$EMP_ID"
[ -n "$EMP_ID" ] && [ "$EMP_ID" != "null" ] || { echo "FAIL: could not resolve Miguel's employee id"; exit 1; }

MANAGER_TOKEN=$(login manager.manila@hris.test password)
M="Authorization: Bearer $MANAGER_TOKEN"

HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

# 2. Find CompanySeeder's one seeded "incomplete" day for THIS month, dynamically — a
#    clock-in with no clock-out, zero worked minutes, is_incomplete: true.
SUMMARY=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")
INCOMPLETE_DAY=$(echo "$SUMMARY" | jq -c '[.data[] | select(.is_incomplete == true)] | .[0] // empty')
echo "2. GET /me/attendance/summary?month=$MONTH as Miguel: the seeded incomplete day = $INCOMPLETE_DAY"
[ -n "$INCOMPLETE_DAY" ] || { echo "FAIL: no is_incomplete day found for $MONTH — did the seeder run this month?"; exit 1; }

DATE=$(echo "$INCOMPLETE_DAY" | jq -r .date)
[ "$(echo "$INCOMPLETE_DAY" | jq -r .worked_minutes)" = "0" ] \
  || { echo "FAIL: $DATE has nonzero worked_minutes — not the incomplete day this script expects"; exit 1; }

# Confirm the raw ledger backs that up: exactly one "in" punch on $DATE, with no "out" that
# pairs with it. (This bucket can ALSO hold the tail of the PREVIOUS day's night shift — see
# CompanySeeder's docblock: a night shift's out-punch lands on the NEXT calendar date, and
# the incomplete day is deliberately seeded right after the night-shift day — so this checks
# the "in" count specifically, not the bucket's total raw punch count.)
DAY_LOGS=$(curl -sf "$API/me/attendance?month=$MONTH" -H "$E" | jq -c --arg d "$DATE" '.data[$d] // []')
DAY_LOGS_BEFORE_COUNT=$(echo "$DAY_LOGS" | jq 'length')
echo "   raw /me/attendance on $DATE ($DAY_LOGS_BEFORE_COUNT punch(es)): $DAY_LOGS"
[ "$(echo "$DAY_LOGS" | jq '[.[] | select(.direction == "in")] | length')" = "1" ] \
  || { echo "FAIL: $DATE does not have exactly one \"in\" punch"; exit 1; }

# Snapshot Miguel's raw attendance_logs rows now — full row content, not just ids — the
# append-only ledger the approval below must extend with exactly one new row and never
# mutate. Ordered so a later diff is deterministic.
LOGS_BEFORE=$(psql_ "select id || '|' || punched_at || '|' || direction || '|' || source \
  from attendance_logs where employee_id = '$EMP_ID' order by punched_at, id")
LOGS_BEFORE_COUNT=$(echo "$LOGS_BEFORE" | grep -c . || true)
echo "   Miguel's attendance_logs before: $LOGS_BEFORE_COUNT row(s)"
[ "$LOGS_BEFORE_COUNT" -ge 1 ] || { echo "FAIL: Miguel has no attendance_logs rows at all"; exit 1; }

# 3. Miguel files an `add` adjustment for the missing clock-out — plain JSON, no attachment
#    (that path is already proven live by scripts/e2e-adjustments.sh). 18:00 mirrors
#    CompanySeeder's own ordinary-day out-punch time, so this stays an unremarkable,
#    non-overtime day once it pairs.
ADD=$(curl -sf -X POST "$API/attendance/adjustments" -H "$E" -H "$J" \
  -d "{\"operation\":\"add\",\"note\":\"Forgot to clock out, security guard can confirm.\",\"direction\":\"out\",\"punched_at\":\"${DATE}T18:00:00+08:00\"}")
ADD_ID=$(echo "$ADD" | jq -r .data.id)
echo "3. filed add adjustment for the missing $DATE clock-out: id=$ADD_ID state=$(echo "$ADD" | jq -r .data.state) (expect pending)"
[ -n "$ADD_ID" ] && [ "$ADD_ID" != "null" ] || { echo "FAIL: submit did not return a request id"; exit 1; }
[ "$(echo "$ADD" | jq -r .data.state)" = "pending" ] || { echo "FAIL: add adjustment not pending"; exit 1; }

# 4. The request appears on BOTH scoped queues — Rosa (Miguel's manager) via /team/approvals
#    and Carmen (Manila HR) via /office/approvals. Neither queue is type-specific: this is
#    still the one attendance_adjustment type M6a shipped with, but reached through the new,
#    generalized queue views (ApprovalQueues::directReportsOf / hrOfficesOf), not the old M3.6
#    combined /attendance/adjustments/pending list.
IN_TEAM=$(curl -sf "$API/team/approvals" -H "$M" | jq --arg id "$ADD_ID" '[.data[] | select(.id == $id)] | length')
echo "4. GET /team/approvals as manager.manila: contains $ADD_ID -> count=$IN_TEAM (expect 1)"
[ "$IN_TEAM" = "1" ] || { echo "FAIL: the pending request is not on manager.manila's /team/approvals queue"; exit 1; }

IN_OFFICE=$(curl -sf "$API/office/approvals" -H "$H" | jq --arg id "$ADD_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /office/approvals as hr.manila: contains $ADD_ID -> count=$IN_OFFICE (expect 1)"
[ "$IN_OFFICE" = "1" ] || { echo "FAIL: the pending request is not on hr.manila's /office/approvals queue"; exit 1; }

# 5. manager.manila approves it through the generalized /requests/{id}/approve route.
APPROVE=$(curl -sf -X POST "$API/requests/$ADD_ID/approve" -H "$M")
echo "5. POST /requests/$ADD_ID/approve as manager.manila: state=$(echo "$APPROVE" | jq -r .data.state) (expect approved)"
[ "$(echo "$APPROVE" | jq -r .data.state)" = "approved" ] || { echo "FAIL: add adjustment not approved"; exit 1; }

# Now decided, it must have dropped off BOTH pending queues — a decided request has no
# business sitting in either scoped view of the PENDING set.
STILL_IN_TEAM=$(curl -sf "$API/team/approvals" -H "$M" | jq --arg id "$ADD_ID" '[.data[] | select(.id == $id)] | length')
echo "   after approval, /team/approvals still contains it: count=$STILL_IN_TEAM (expect 0)"
[ "$STILL_IN_TEAM" = "0" ] || { echo "FAIL: an approved (no longer pending) request is still on the team queue"; exit 1; }

# 6. Approval recomputed the day SYNCHRONOUSLY — RecordPunch's own on-write trigger
#    (M5a), not a queued recompute like e2e-recompute.sh's holiday flip — so there is
#    nothing to drain here. The SAME GET now shows the day paired: is_incomplete flips to
#    false, and a real regular_day line exists where there was none before.
AFTER_SUMMARY=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")
AFTER_DAY=$(echo "$AFTER_SUMMARY" | jq -c --arg d "$DATE" '.data[] | select(.date == $d)')
echo "6. GET /me/attendance/summary?month=$MONTH as Miguel, after approval: $DATE = $AFTER_DAY"
[ "$(echo "$AFTER_DAY" | jq -r '.is_incomplete')" = "false" ] || { echo "FAIL: $DATE is still marked incomplete after the approved add"; exit 1; }
[ "$(echo "$AFTER_DAY" | jq -r '.worked_minutes')" -gt "0" ] || { echo "FAIL: $DATE still shows zero worked_minutes after the approved add"; exit 1; }
[ "$(echo "$AFTER_DAY" | jq '[.lines[] | select(.kind == "regular_day")] | length')" -ge "1" ] \
  || { echo "FAIL: $DATE has no regular_day line after the approved add paired the punches"; exit 1; }

# The raw ledger read confirms it too: exactly one more punch in $DATE's bucket than before
# (the new one), source=adjustment.
AFTER_LOGS=$(curl -sf "$API/me/attendance?month=$MONTH" -H "$E" | jq -c --arg d "$DATE" '.data[$d] // []')
echo "   raw /me/attendance on $DATE, after approval: $AFTER_LOGS"
[ "$(echo "$AFTER_LOGS" | jq 'length')" -eq "$((DAY_LOGS_BEFORE_COUNT + 1))" ] \
  || { echo "FAIL: $DATE's punch count did not grow by exactly one after the approved add"; exit 1; }
[ "$(echo "$AFTER_LOGS" | jq '[.[] | select(.direction == "out" and .source == "adjustment")] | length')" = "1" ] \
  || { echo "FAIL: no adjustment-sourced \"out\" punch found on $DATE"; exit 1; }

# 7. The pre-existing attendance_logs rows are BYTE-IDENTICAL — same ids, same
#    punched_at/direction/source, in the same relative order. The approved add is exactly
#    one NEW row (count +1); nothing that existed before this script ran was touched.
LOGS_AFTER=$(psql_ "select id || '|' || punched_at || '|' || direction || '|' || source \
  from attendance_logs where employee_id = '$EMP_ID' order by punched_at, id")
LOGS_AFTER_COUNT=$(echo "$LOGS_AFTER" | grep -c . || true)
echo "7. Miguel's attendance_logs: $LOGS_BEFORE_COUNT -> $LOGS_AFTER_COUNT row(s) (expect exactly +1)"
[ "$LOGS_AFTER_COUNT" -eq "$((LOGS_BEFORE_COUNT + 1))" ] \
  || { echo "FAIL: expected exactly one new attendance_logs row, got a count of $LOGS_AFTER_COUNT"; exit 1; }

MISSING=$(comm -23 <(echo "$LOGS_BEFORE" | sort) <(echo "$LOGS_AFTER" | sort) || true)
echo "   pre-existing rows missing or mutated after approval: ${MISSING:-none}"
[ -z "$MISSING" ] \
  || { echo "FAIL: a pre-existing attendance_logs row is missing or changed — the append-only ledger was mutated"; exit 1; }

echo "OK: Miguel's seeded incomplete day ($DATE) got an add adjustment filed against its"
echo "    missing clock-out; the pending request showed up on BOTH manager.manila's"
echo "    /team/approvals and hr.manila's /office/approvals (M6a's two scoped queues, not"
echo "    type-specific); manager.manila approved it through the generalized"
echo "    /requests/{id}/approve route, after which it dropped off /team/approvals; the day"
echo "    recomputed synchronously — is_incomplete flipped false, a real regular_day line"
echo "    appeared, and the raw ledger now shows the paired out-punch (source=adjustment);"
echo "    and Miguel's pre-existing attendance_logs rows are byte-identical, same ids and"
echo "    content in the same order, before and after — the append-only ledger was never"
echo "    mutated, only extended by exactly one new row — all against the live stack."
