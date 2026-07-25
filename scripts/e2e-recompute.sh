#!/bin/bash
#
# M5b 'RecomputeRange' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-holidays.sh's structure and scripts/e2e-compute.sh's seeded logins. It walks
# the holiday-flip path all the way through the queue, against the real API:
#
#   Miguel Santos (employee.manila@hris.test, MNL-0002) has a seeded, computed 2026-01-15
#   summary — a plain ordinary punched day, one regular_day line at 10000bp (100%, the
#   statutory floor; see scripts/e2e-compute.sh step 2). CompanySeeder already seeds Aug 21
#   as a Manila holiday (Ninoy Aquino Day), so this script deliberately picks a DIFFERENT,
#   currently-ordinary date to prove the flip rather than reusing an already-flipped one.
#
#   Carmen Lim (hr.manila@hris.test) then marks that same 2026-01-15 a Manila
#   special_non_working holiday. The write commits and, per CreateHoliday's afterCommit
#   hook, enqueues a `recompute_runs` row plus a Bus::batch of RecomputeDay — but the batch
#   runs on the queue (QUEUE_CONNECTION=database), so nothing has actually recomputed yet
#   until something drains it.
#
#   This script drains it by running `php artisan queue:work --stop-when-empty` inside the
#   api container — the other option (setting QUEUE_CONNECTION=sync for the API call
#   context) would require the running FrankenPHP process itself to have been booted with
#   a different queue driver, which isn't true of a stack already up via `make dev`; a
#   worker pass against the container's actual `database` connection is the approach that
#   works against the dev stack as it runs today, and is what a real deploy would do too.
#
#   Once the batch has run, Miguel's SAME GET as before shows 2026-01-15 flipped to
#   special_non_working at 13000bp (130%) — the exact 100% -> 130% flip M4's original
#   "Done when" line promised and M5 is the first thing able to prove. A `recompute_runs`
#   row exists for it (trigger_type holiday, status completed, pair_count 1 — only Miguel
#   had an existing summary for that date). And Miguel's raw `attendance_logs` rows are
#   BYTE-IDENTICAL, same ids in the same order, before and after — the append-only ledger
#   was never touched; only the derived summary was recomputed.
#
# One thing this script deliberately does NOT assert via HTTP: reading recompute_runs or
# attendance_logs directly — there is no GET endpoint for either (recompute_runs has no
# read surface at all yet; attendance_logs' own read endpoint returns the raw ledger, not
# an easy way to diff a specific employee's row set). Both are read via `psql`, the same
# "go straight at the database for what has no HTTP surface yet" exception
# scripts/e2e-holidays.sh takes for the activity log and scripts/e2e-adjustments.sh takes
# for attendance_annulments.
#
# Seeded logins used here: employee.manila@hris.test (Miguel Santos, MNL-0002) and
# hr.manila@hris.test (Carmen Lim, HR Admin scoped to Manila HQ only). Both password
# `password`.
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

# The date this script flips. Deliberately NOT Aug 21 — CompanySeeder already seeds that
# as a Manila holiday (Ninoy Aquino Day), so reusing it would prove nothing about a flip.
# 2026-01-15 is Miguel's other seeded, computed day: plain ordinary, 100%, still untouched.
DATE="2026-01-15"

# 1. Log in as Miguel (the employee whose day gets recomputed) and Carmen (Manila HR, who
#    will flip the day type).
MIGUEL_TOKEN=$(login employee.manila@hris.test password)
MIGUEL="Authorization: Bearer $MIGUEL_TOKEN"

HR_TOKEN=$(login hr.manila@hris.test password)
HR="Authorization: Bearer $HR_TOKEN"

MIGUEL_ME=$(curl -sf "$API/me" -H "$MIGUEL")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "1. employee.manila (Miguel, $MIGUEL_ID) and hr.manila logged in"
[ -n "$MIGUEL_ID" ] && [ "$MIGUEL_ID" != "null" ] || { echo "FAIL: could not resolve Miguel's employee id"; exit 1; }

MANILA_ID=$(curl -sf "$API/me" -H "$HR" | jq -r '.data.hr_offices[0]')
echo "   hr.manila's hr_offices[0]=$MANILA_ID (Manila HQ)"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

# 2. Before anything: $DATE is a seeded, computed, plain-ordinary day for Miguel — one
#    regular_day line at 10000bp (100%, the statutory floor). See scripts/e2e-compute.sh
#    step 2, which proves the same seeded row from the read side.
BEFORE=$(curl -sf "$API/me/attendance/summary?month=${DATE%-*}" -H "$MIGUEL")
BEFORE_DAY=$(echo "$BEFORE" | jq -c --arg d "$DATE" '.data[] | select(.date == $d)')
echo "2. GET /me/attendance/summary?month=${DATE%-*} as Miguel: $DATE = $BEFORE_DAY"

[ -n "$BEFORE_DAY" ] || { echo "FAIL: no seeded summary for $DATE — did the seeder run?"; exit 1; }
[ "$(echo "$BEFORE_DAY" | jq -r '.day_type')" = "ordinary" ] \
  || { echo "FAIL: $DATE is not seeded as ordinary — pick a different date"; exit 1; }
[ "$(echo "$BEFORE_DAY" | jq '[.lines[] | select(.kind == "regular_day" and .applied_bp == 10000)] | length')" = "1" ] \
  || { echo "FAIL: $DATE has no regular_day line at 10000bp (100%) before the flip"; exit 1; }

# Snapshot Miguel's raw attendance_logs rows now, before the holiday write — this is the
# append-only ledger RecomputeDay must never touch, only the derived summary.
LOGS_BEFORE=$(psql_ "select id from attendance_logs where employee_id = '$MIGUEL_ID' order by punched_at")
LOGS_BEFORE_COUNT=$(echo "$LOGS_BEFORE" | grep -c . || true)
echo "   Miguel's attendance_logs before: $LOGS_BEFORE_COUNT row(s)"
[ "$LOGS_BEFORE_COUNT" -ge 1 ] || { echo "FAIL: Miguel has no attendance_logs rows at all"; exit 1; }

RUNS_BEFORE=$(psql_ "select count(*) from recompute_runs" | tr -d '[:space:]')
echo "   recompute_runs before: $RUNS_BEFORE row(s)"

# 3. Carmen (Manila HR) marks $DATE a special_non_working holiday for Manila. CreateHoliday
#    commits the write, then (DB::afterCommit) enqueues an audited RecomputeRange — a
#    recompute_runs row plus a Bus::batch of RecomputeDay — for every EXISTING summary on
#    this office+date. It has not run yet: it's on the queue.
CREATE=$(curl -sf -X POST "$API/office/holidays" -H "$HR" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"date\":\"$DATE\",\"day_type\":\"special_non_working\",\"name\":\"E2E Recompute Flip Day\"}")
HOLIDAY_ID=$(echo "$CREATE" | jq -r .data.id)
echo "3. hr.manila created a Manila special_non_working holiday on $DATE: id=$HOLIDAY_ID"
[ -n "$HOLIDAY_ID" ] && [ "$HOLIDAY_ID" != "null" ] || { echo "FAIL: holiday create did not return an id"; exit 1; }

# Immediately after the create (before the queue has been touched), the summary must NOT
# have flipped yet — proof the recompute is genuinely queued, not synchronous.
STILL_OLD=$(curl -sf "$API/me/attendance/summary?month=${DATE%-*}" -H "$MIGUEL" \
  | jq -c --arg d "$DATE" '.data[] | select(.date == $d) | .day_type')
echo "   immediately after create, before draining the queue: day_type=$STILL_OLD (expect \"ordinary\" — still queued)"
[ "$STILL_OLD" = '"ordinary"' ] \
  || { echo "FAIL: the summary flipped before the queue was ever drained — recompute is not actually queued"; exit 1; }

# 4. Drain the queue: process the Bus::batch of RecomputeDay jobs it dispatched. This is
#    the live-stack equivalent of what a real deploy's queue worker does continuously; here
#    it is run once, to completion, for a script that then exits.
echo "4. draining the queue (php artisan queue:work --stop-when-empty)..."
docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T --user hris api \
  php artisan queue:work --stop-when-empty

# 5. Miguel's SAME GET now shows $DATE flipped: special_non_working, 13000bp (130%) instead
#    of 10000bp (100%) — the exact 100% -> 130% flip M4's original "Done when" line named,
#    now actually happening because M5 exists to compute it.
AFTER=$(curl -sf "$API/me/attendance/summary?month=${DATE%-*}" -H "$MIGUEL")
AFTER_DAY=$(echo "$AFTER" | jq -c --arg d "$DATE" '.data[] | select(.date == $d)')
echo "5. GET /me/attendance/summary?month=${DATE%-*} as Miguel, after draining the queue: $DATE = $AFTER_DAY"

[ "$(echo "$AFTER_DAY" | jq -r '.day_type')" = "special_non_working" ] \
  || { echo "FAIL: $DATE did not flip to special_non_working after the recompute ran"; exit 1; }
[ "$(echo "$AFTER_DAY" | jq '[.lines[] | select(.kind == "regular_day" and .applied_bp == 13000)] | length')" = "1" ] \
  || { echo "FAIL: $DATE has no regular_day line at 13000bp (130%) after the flip"; exit 1; }
[ "$(echo "$AFTER_DAY" | jq -r '.rule_version_id')" = "$(echo "$BEFORE_DAY" | jq -r '.rule_version_id')" ] \
  || { echo "FAIL: the recompute changed which pay_rules version priced the day"; exit 1; }

# 6. A recompute_runs row exists for this: trigger_type holiday, status completed (the
#    Bus::batch's ->then() callback fired once every job in it finished), pair_count 1 —
#    only Miguel had an existing summary for Manila+$DATE, so exactly one (employee, date)
#    pair was dispatched, never more just because the office has other employees too.
RUNS_AFTER=$(psql_ "select count(*) from recompute_runs" | tr -d '[:space:]')
NEW_RUN=$(psql_ "select trigger_type || '|' || status || '|' || pair_count || '|' || trigger_id \
  from recompute_runs where trigger_id = '$HOLIDAY_ID'")
echo "6. recompute_runs: $RUNS_BEFORE -> $RUNS_AFTER row(s); the new row: $NEW_RUN"
[ "$RUNS_AFTER" -eq "$((RUNS_BEFORE + 1))" ] || { echo "FAIL: expected exactly one new recompute_runs row"; exit 1; }
[ "$NEW_RUN" = "holiday|completed|1|$HOLIDAY_ID" ] \
  || { echo "FAIL: the recompute_runs row is not trigger_type=holiday, status=completed, pair_count=1 for this holiday"; exit 1; }

# 7. Miguel's raw attendance_logs rows are BYTE-IDENTICAL — same ids, same order, before
#    and after. The recompute touched only the derived summary; the append-only ledger a
#    DOLE inspector would be shown was never mutated, never re-created, never reordered.
LOGS_AFTER=$(psql_ "select id from attendance_logs where employee_id = '$MIGUEL_ID' order by punched_at")
echo "7. Miguel's attendance_logs after: $(echo "$LOGS_AFTER" | grep -c . || true) row(s), same ids/order as step 2: \
$([ "$LOGS_BEFORE" = "$LOGS_AFTER" ] && echo yes || echo NO)"
[ "$LOGS_BEFORE" = "$LOGS_AFTER" ] \
  || { echo "FAIL: attendance_logs rows changed — the append-only ledger was mutated by a recompute"; exit 1; }

echo "OK: a Manila HR admin flipped a seeded ordinary day to special_non_working; the"
echo "    recompute stayed queued (not yet applied) until the queue was drained; draining"
echo "    it flipped Miguel's summary 100% -> 130% (regular_day, same rule_version_id);"
echo "    an audited recompute_runs row (trigger_type=holiday, status=completed,"
echo "    pair_count=1) exists for it; and Miguel's raw attendance_logs rows are"
echo "    byte-identical, same ids in the same order, before and after — the append-only"
echo "    ledger was never touched, only the derived summary — all against the live stack."
