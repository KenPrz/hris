#!/bin/bash
#
# M7a 'cutoffs & locking' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-recompute.sh's structure and scripts/e2e-leave-and-ot.sh's seeded logins. It
# walks the whole close/lock/reopen flow against the real API:
#
#   CompanySeeder gives Miguel Santos (employee.manila@hris.test, MNL-0002) a full, varied
#   CURRENT-month attendance history: an unbroken run of computed, complete days across the
#   first-half semi-monthly window (the 1st–15th), and — deliberately, near the end of the
#   month — ONE incomplete day (a clock-in with no clock-out) that lands in the second-half
#   window (the 16th–end-of-month). Both halves are ordinary Manila office days computed by
#   the M5 engine the moment the seed's punches were recorded.
#
#   Carmen Lim (hr.manila@hris.test, Manila HR — the `cutoff.manage` verb + an
#   hr_admin_offices row for Manila, the OfficeScope the cutoff endpoints gate on) drives
#   the cutoff surface:
#
#     - The strict exception gate, proven LIVE against the seed's own incomplete day: an
#       attempt to close the SECOND-half window (period_start 2026-07-16) is refused 422
#       `cutoff_has_unresolved_exceptions`, and its `details.incomplete_dates` names the
#       exact seeded incomplete date — the period never closes (the transaction rolls back,
#       leaving no row behind).
#
#     - The clean FIRST-half window (period_start 2026-07-01) closes: 200, the period reads
#       `closed`, and every one of Miguel's in-period summaries flips `computed` -> `locked`
#       (read back as that employee via GET /me/attendance/summary), while his second-half
#       days stay `computed` — the freeze is bounded to the window that closed.
#
#     - A closed period is immutable and idempotent to re-close: the same close again is
#       refused 409 `cutoff_already_closed`, and a close with a non-boundary period_start
#       (the 5th) is refused 422 `invalid_cutoff_start` — the window rule is the 1st or the
#       16th, nothing else.
#
#     - The lock actually bites an approval: Miguel files a 1-hour overtime
#       pre-authorization for an in-period, now-LOCKED date (2026-07-10) — filing has no
#       cutoff guard, only approval does — and his manager's approval is refused 422
#       `cutoff_locked`, `details.date` the locked day. The overtime path is used precisely
#       because its approval writes NOTHING to attendance_logs (unlike an `add` adjustment,
#       whose approval would append a punch) — so the append-only ledger stays byte-identical
#       across the whole run.
#
#     - Reopening restores it: POST /office/cutoffs/{period}/reopen with a reason returns
#       200 and `open`, every in-period summary flips `locked` -> `computed`, and the SAME
#       overtime approval that was just refused now SUCCEEDS (the manager's single, final
#       hop) — proving reopen is a true inverse, not a one-way escape hatch.
#
#   Finally, Miguel's raw attendance_logs are asserted BYTE-IDENTICAL — same ids in the same
#   order — before the first close and after the final reopen+approval: closing, locking,
#   reopening, and approving an overtime pre-authorization all touch only DERIVED state
#   (cutoff_periods, daily_attendance_summaries.status), never the append-only ledger a DOLE
#   inspector is shown. The two-real-connection row-lock proofs (a close racing an approval,
#   a close racing a recompute — both serialized by the per-employee Employee row lock) live
#   in the Pest suite (tests/Feature/Cutoff/*), not here: this script is a single client
#   walking one path at a time, not a concurrency test.
#
# One thing this script deliberately does NOT assert via HTTP: reading cutoff_periods or
# attendance_logs directly — the period is read through the endpoints' own envelopes, but
# the append-only ledger has no diff-friendly read surface, so its immutability is checked
# via psql, the same "go straight at the database" idiom scripts/e2e-recompute.sh takes.
#
# The script cleans its own July cutoff state at the DB before it starts (drops any Manila
# July cutoff_periods, resets any left-locked July summaries to computed, deletes any
# overtime request it filed for 2026-07-10), so it is safe to rerun without a reseed.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, Manila HR, closes/reopens),
# employee.manila@hris.test (Miguel Santos, MNL-0002, files the overtime and reads his
# locked summaries), manager.manila@hris.test (Rosa Bautista, Miguel's manager, who
# approves the overtime). All password `password`.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The semi-monthly windows this script drives, all in the seeded current month (July 2026).
FIRST_START="2026-07-01"        # the clean first-half window that closes
FIRST_END="2026-07-15"
SECOND_START="2026-07-16"       # the second-half window, which holds the seeded incomplete day
INCOMPLETE_DATE="2026-07-24"    # CompanySeeder's one incomplete day, in the second half
IN_PERIOD_DATE="2026-07-10"     # a first-half date the overtime request targets (gets locked)
OUT_PERIOD_DATE="2026-07-20"    # a second-half date, to prove the freeze is window-bounded
INVALID_START="2026-07-05"      # neither the 1st nor the 16th
MONTH="2026-07"

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

# A request that CAPTURES both the HTTP status and the body — the error paths here assert on
# BOTH the status code AND the envelope's `error.code`/`error.details`, so plain `curl -sf`
# (which discards the body on a 4xx) will not do. Sets REQ_CODE and REQ_BODY.
req() {   # $1 METHOD, $2 URL, then extra curl args (headers/data)
  local resp
  resp=$(curl -s -w $'\n%{http_code}' -X "$1" "$2" "${@:3}")
  REQ_CODE="${resp##*$'\n'}"
  REQ_BODY="${resp%$'\n'*}"
}

# 1. Log in as Carmen (HR, closes/reopens), Miguel (files overtime, reads his summaries),
#    and Rosa (Miguel's manager, approves the overtime).
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"

MANAGER_TOKEN=$(login manager.manila@hris.test password)
M="Authorization: Bearer $MANAGER_TOKEN"

MANILA_ID=$(curl -sf "$API/me" -H "$H" | jq -r '.data.hr_offices[0]')
echo "1. hr.manila, employee.manila, manager.manila logged in; Manila office=$MANILA_ID"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

MIGUEL_ME=$(curl -sf "$API/me" -H "$E")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "   employee.manila's employee id (Miguel Santos): $MIGUEL_ID"
[ "$(echo "$MIGUEL_ME" | jq -r .data.employee.employee_no)" = "MNL-0002" ] \
  || { echo "FAIL: employee.manila is not seeded as MNL-0002 — did the seeder run?"; exit 1; }

# Rerun-safety: drop any Manila July cutoff_periods, reset any left-locked July summaries to
# computed, and delete any overtime request this script filed for the in-period date. A prior
# run leaves the first-half period closed and its summaries locked; without this a rerun would
# hit `cutoff_already_closed` on the very first close and never reach the rest.
psql_ "delete from cutoff_periods where office_id = '$MANILA_ID' and start_date between '$FIRST_START' and '2026-07-31'" >/dev/null
psql_ "update daily_attendance_summaries set status = 'computed' where office_id = '$MANILA_ID' and status = 'locked' and date between '$FIRST_START' and '2026-07-31'" >/dev/null
psql_ "delete from requests where type = 'overtime' and employee_id = '$MIGUEL_ID' and id in (select request_id from overtime_details where date between '$FIRST_START' and '2026-07-31')" >/dev/null
echo "   cleared prior July cutoff state for Manila (rerun-safe)"

# Snapshot Miguel's raw attendance_logs now — the append-only ledger the whole close/lock/
# reopen/approve flow below must never touch, only the derived summary/period state.
LOGS_BEFORE=$(psql_ "select id from attendance_logs where employee_id = '$MIGUEL_ID' order by punched_at")
LOGS_BEFORE_COUNT=$(echo "$LOGS_BEFORE" | grep -c . || true)
echo "   Miguel's attendance_logs before: $LOGS_BEFORE_COUNT row(s)"
[ "$LOGS_BEFORE_COUNT" -ge 1 ] || { echo "FAIL: Miguel has no attendance_logs rows at all — did the seeder run?"; exit 1; }

# 2. GET /office/cutoffs?office=Manila as HR: a list, with the current still-running window
#    synthesized (unpersisted, id null) so "now" is never a gap. On a fresh run no period is
#    stored yet, so exactly the synthesized current window comes back.
LIST=$(curl -sf "$API/office/cutoffs?office=$MANILA_ID" -H "$H")
LIST_LEN=$(echo "$LIST" | jq '.data | length')
echo "2. GET /office/cutoffs?office=$MANILA_ID: $LIST_LEN period(s); $(echo "$LIST" | jq -c '.data')"
[ "$LIST_LEN" -ge 1 ] || { echo "FAIL: /office/cutoffs returned no periods — the current window should always be synthesized"; exit 1; }
[ "$(echo "$LIST" | jq '[.data[] | select(.state != "open" and .state != "closed")] | length')" = "0" ] \
  || { echo "FAIL: a cutoff period has a state that is neither open nor closed"; exit 1; }

# 3. The strict exception gate, LIVE: closing the SECOND-half window is refused because the
#    seed's incomplete day (2026-07-24) sits inside it. 422 cutoff_has_unresolved_exceptions,
#    and details.incomplete_dates names that exact date. The period must NOT persist — the
#    close throws inside its transaction, which rolls back the row firstOrCreate opened.
req POST "$API/office/cutoffs/close" -H "$H" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"period_start\":\"$SECOND_START\"}"
echo "3. POST /office/cutoffs/close (second half, $SECOND_START): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
echo "   details=$(echo "$REQ_BODY" | jq -c .error.details)"
[ "$REQ_CODE" = "422" ] || { echo "FAIL: closing a window with an incomplete day was not refused 422"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "cutoff_has_unresolved_exceptions" ] \
  || { echo "FAIL: the refusal code is not cutoff_has_unresolved_exceptions"; exit 1; }
[ "$(echo "$REQ_BODY" | jq --arg d "$INCOMPLETE_DATE" '[.error.details.incomplete_dates[] | select(. == $d)] | length')" = "1" ] \
  || { echo "FAIL: details.incomplete_dates does not name the seeded incomplete day $INCOMPLETE_DATE"; exit 1; }
LEFTOVER=$(psql_ "select count(*) from cutoff_periods where office_id = '$MANILA_ID' and start_date = '$SECOND_START'" | tr -d '[:space:]')
echo "   cutoff_periods rows for $SECOND_START after the refused close: $LEFTOVER (expect 0 — the transaction rolled back)"
[ "$LEFTOVER" = "0" ] || { echo "FAIL: a refused close left a cutoff_periods row behind — the transaction did not roll back"; exit 1; }

# 4. The clean FIRST-half window closes. 200, the period reads closed, carries closed_at.
req POST "$API/office/cutoffs/close" -H "$H" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"period_start\":\"$FIRST_START\"}"
echo "4. POST /office/cutoffs/close (first half, $FIRST_START): HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
{ [ "$REQ_CODE" = "200" ] || [ "$REQ_CODE" = "201" ]; } || { echo "FAIL: closing the clean first-half window did not return 200/201"; exit 1; }
PERIOD_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$PERIOD_ID" ] && [ "$PERIOD_ID" != "null" ] || { echo "FAIL: close did not return a period id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.state)" = "closed" ] || { echo "FAIL: the closed period's state is not 'closed'"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.start_date)" = "$FIRST_START" ] || { echo "FAIL: closed period start_date is not $FIRST_START"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.end_date)" = "$FIRST_END" ] || { echo "FAIL: closed period end_date is not $FIRST_END"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.closed_at)" != "null" ] || { echo "FAIL: closed period has no closed_at"; exit 1; }

# 5. The freeze is visible to the employee: every in-period summary reads `locked`, while the
#    second-half days stay `computed` — the freeze is bounded to the window that closed.
SUMMARY=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")
IN_LOCKED=$(echo "$SUMMARY" | jq -r --arg d "$IN_PERIOD_DATE" '.data[] | select(.date == $d) | .status')
OUT_STATUS=$(echo "$SUMMARY" | jq -r --arg d "$OUT_PERIOD_DATE" '.data[] | select(.date == $d) | .status')
NON_LOCKED_IN=$(echo "$SUMMARY" | jq --arg s "$FIRST_START" --arg e "$FIRST_END" \
  '[.data[] | select(.date >= $s and .date <= $e and .status != "locked")] | length')
LOCKED_IN=$(echo "$SUMMARY" | jq --arg s "$FIRST_START" --arg e "$FIRST_END" \
  '[.data[] | select(.date >= $s and .date <= $e and .status == "locked")] | length')
echo "5. GET /me/attendance/summary?month=$MONTH as Miguel: $IN_PERIOD_DATE status=$IN_LOCKED, $OUT_PERIOD_DATE status=$OUT_STATUS"
echo "   in-period ($FIRST_START..$FIRST_END): $LOCKED_IN locked, $NON_LOCKED_IN not-locked"
[ "$IN_LOCKED" = "locked" ] || { echo "FAIL: an in-period summary did not flip to locked after the close"; exit 1; }
[ "$LOCKED_IN" -ge 1 ] || { echo "FAIL: no in-period summaries are locked"; exit 1; }
[ "$NON_LOCKED_IN" = "0" ] || { echo "FAIL: an in-period summary is still not locked after the close"; exit 1; }
[ "$OUT_STATUS" = "computed" ] || { echo "FAIL: an out-of-period (second-half) summary was frozen — the lock is not window-bounded"; exit 1; }

# 6. A closed period is idempotent to re-close and immutable: the same close again is 409
#    cutoff_already_closed.
req POST "$API/office/cutoffs/close" -H "$H" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"period_start\":\"$FIRST_START\"}"
echo "6. POST /office/cutoffs/close (again, $FIRST_START): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
[ "$REQ_CODE" = "409" ] || { echo "FAIL: re-closing an already-closed period was not refused 409"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "cutoff_already_closed" ] \
  || { echo "FAIL: the refusal code is not cutoff_already_closed"; exit 1; }

# 7. A non-boundary period_start is refused: 422 invalid_cutoff_start. The window rule is the
#    1st or the 16th; the 5th is neither.
req POST "$API/office/cutoffs/close" -H "$H" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"period_start\":\"$INVALID_START\"}"
echo "7. POST /office/cutoffs/close (invalid start $INVALID_START): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
[ "$REQ_CODE" = "422" ] || { echo "FAIL: a non-boundary period_start was not refused 422"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "invalid_cutoff_start" ] \
  || { echo "FAIL: the refusal code is not invalid_cutoff_start"; exit 1; }

# 8. The lock bites an approval. Miguel files a 1-hour overtime pre-authorization for the
#    in-period, now-LOCKED date — filing has no cutoff guard (only approval does), so the
#    submit succeeds and starts pending.
SUBMIT=$(curl -sf -X POST "$API/overtime/requests" -H "$E" -H "$J" -d "{
    \"date\": \"$IN_PERIOD_DATE\",
    \"hours\": 1,
    \"note\": \"E2E: overtime pre-auth on a locked in-period day — the approve must be refused\"
  }")
OT_REQ_ID=$(echo "$SUBMIT" | jq -r .data.id)
echo "8. POST /overtime/requests ($IN_PERIOD_DATE, 1h): id=$OT_REQ_ID state=$(echo "$SUBMIT" | jq -r .data.state)"
[ -n "$OT_REQ_ID" ] && [ "$OT_REQ_ID" != "null" ] || { echo "FAIL: overtime submit did not return an id"; exit 1; }
[ "$(echo "$SUBMIT" | jq -r .data.state)" = "pending" ] || { echo "FAIL: filing overtime for a locked day should still start pending (filing is not guarded)"; exit 1; }

# The manager's approval IS the single, final hop — where ApproveRequest runs CutoffGuard.
# The in-period date is locked, so the approval is refused 422 cutoff_locked, details.date
# the locked day. The transaction rolls back: the request stays pending.
req POST "$API/requests/$OT_REQ_ID/approve" -H "$M"
echo "   POST /requests/$OT_REQ_ID/approve as manager.manila (period closed): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code) details=$(echo "$REQ_BODY" | jq -c .error.details)"
[ "$REQ_CODE" = "422" ] || { echo "FAIL: approving onto a locked day was not refused 422"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "cutoff_locked" ] || { echo "FAIL: the refusal code is not cutoff_locked"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.details.date)" = "$IN_PERIOD_DATE" ] \
  || { echo "FAIL: cutoff_locked details.date is not the locked in-period day $IN_PERIOD_DATE"; exit 1; }
STILL_PENDING=$(curl -sf "$API/requests/$OT_REQ_ID" -H "$E" | jq -r .data.state)
echo "   the refused request's state: $STILL_PENDING (expect pending — the refusal rolled back)"
[ "$STILL_PENDING" = "pending" ] || { echo "FAIL: a request refused by cutoff_locked did not stay pending"; exit 1; }

# 9. Reopen restores it. POST /office/cutoffs/{period}/reopen with a reason: 200, open.
req POST "$API/office/cutoffs/$PERIOD_ID/reopen" -H "$H" -H "$J" \
  -d '{"reason":"E2E: reopening to prove the freeze is reversible and the refused approval then succeeds."}'
echo "9. POST /office/cutoffs/$PERIOD_ID/reopen: HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: reopen did not return 200"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.state)" = "open" ] || { echo "FAIL: the reopened period's state is not 'open'"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.closed_at)" = "null" ] || { echo "FAIL: reopened period still carries closed_at"; exit 1; }

# Every in-period summary flips locked -> computed.
SUMMARY_REOPENED=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")
STILL_LOCKED_IN=$(echo "$SUMMARY_REOPENED" | jq --arg s "$FIRST_START" --arg e "$FIRST_END" \
  '[.data[] | select(.date >= $s and .date <= $e and .status == "locked")] | length')
IN_AFTER=$(echo "$SUMMARY_REOPENED" | jq -r --arg d "$IN_PERIOD_DATE" '.data[] | select(.date == $d) | .status')
echo "   after reopen: $IN_PERIOD_DATE status=$IN_AFTER; in-period summaries still locked=$STILL_LOCKED_IN (expect 0)"
[ "$IN_AFTER" = "computed" ] || { echo "FAIL: an in-period summary did not flip back to computed after the reopen"; exit 1; }
[ "$STILL_LOCKED_IN" = "0" ] || { echo "FAIL: an in-period summary stayed locked after the reopen"; exit 1; }

# The SAME approval that was just refused now succeeds — reopen is a true inverse.
OT_APPROVE=$(curl -sf -X POST "$API/requests/$OT_REQ_ID/approve" -H "$M")
echo "   POST /requests/$OT_REQ_ID/approve as manager.manila (period reopened): state=$(echo "$OT_APPROVE" | jq -r .data.state)"
[ "$(echo "$OT_APPROVE" | jq -r .data.state)" = "approved" ] \
  || { echo "FAIL: the overtime approval refused while closed did not succeed after the reopen"; exit 1; }

# The approval enqueued a recompute of the in-period day (RecomputeTrigger overtime) — drain
# it, the same way scripts/e2e-recompute.sh does, so nothing is left dangling on the queue.
echo "   draining the queue (php artisan queue:work --stop-when-empty)..."
drain_queue

# 10. The append-only ledger is BYTE-IDENTICAL across the whole run — same ids, same order,
#     before the first close and after the final reopen+approval. Closing, locking, reopening,
#     and approving an overtime pre-authorization touched only derived state; the raw log a
#     DOLE inspector is shown was never mutated, re-created, or reordered.
LOGS_AFTER=$(psql_ "select id from attendance_logs where employee_id = '$MIGUEL_ID' order by punched_at")
echo "10. Miguel's attendance_logs after: $(echo "$LOGS_AFTER" | grep -c . || true) row(s), same ids/order as step 1: \
$([ "$LOGS_BEFORE" = "$LOGS_AFTER" ] && echo yes || echo NO)"
[ "$LOGS_BEFORE" = "$LOGS_AFTER" ] \
  || { echo "FAIL: attendance_logs rows changed — the append-only ledger was mutated by a close/lock/reopen/approve"; exit 1; }

echo "OK: Manila HR's attempt to close the second-half window was refused"
echo "    (cutoff_has_unresolved_exceptions, naming the seed's incomplete $INCOMPLETE_DATE);"
echo "    the clean first-half window ($FIRST_START..$FIRST_END) closed, flipping every"
echo "    in-period summary computed -> locked while the second half stayed computed;"
echo "    re-closing was refused 409 (cutoff_already_closed) and a non-boundary start 422"
echo "    (invalid_cutoff_start); a manager's approval onto a locked in-period day was"
echo "    refused 422 (cutoff_locked) and the request stayed pending; reopening the period"
echo "    flipped every summary locked -> computed and let the SAME approval succeed; and"
echo "    Miguel's raw attendance_logs are byte-identical, same ids in the same order,"
echo "    before and after — the append-only ledger was never touched — all against the"
echo "    live stack."
