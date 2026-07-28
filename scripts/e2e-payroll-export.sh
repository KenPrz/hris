#!/bin/bash
#
# M7b 'payroll export' — the milestone's end-to-end proof, runnable against a freshly seeded
# stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors scripts/e2e-cutoffs.sh's
# structure (login helper, jq envelope parsing, per-assertion PASS/FAIL, `req` status-capture,
# exit 1) — that script closes a period; this one closes it, then exports and reconciles.
#
#   CompanySeeder gives Miguel Santos (employee.manila@hris.test, MNL-0002) a full, computed,
#   NON-incomplete run of days across the first-half semi-monthly window (2026-07-01..15) in the
#   Manila office. Carmen Lim (hr.manila@hris.test, Manila HR — the `cutoff.manage` verb + an
#   hr_admin_offices row for Manila) drives the cutoff + export surface.
#
#   The export is the point:
#
#     - The clean first-half window closes (POST /office/cutoffs/close) so it is exportable —
#       an export is only defined for a finalized period (decision: closed-only).
#
#     - GET /office/cutoffs/{period}/export returns 200 with a per-employee earnings breakdown
#       in integer minutes + basis points: per (kind, applied_bp, rule_version_id) lines and
#       worked/late/undertime/unpaid_overtime totals, plus base_rate_cents (reference only —
#       the export is hours+bp, never pesos) and has_incomplete_days.
#
#     - THE RECONCILIATION (the core): for Miguel, the export's per-(kind, applied_bp,
#       rule_version_id) line minutes EQUAL the same triples derived from his own calendar
#       (GET /me/attendance/summary?month=2026-07) summed over the in-period dates — the
#       line-for-line guarantee. A summary line carries no rule_version_id of its own; it is
#       paired with its parent DAY's rule_version_id, exactly as the aggregator groups. The
#       four totals match the summed day scalars too. Exact integer equality, via jq.
#
#     - REPRODUCIBILITY: hitting the export a second time returns a byte-identical `data`
#       payload — the period is locked, so the numbers are frozen and stable.
#
#     - CLOSED-ONLY: reopening the period (POST .../reopen) makes the export refuse 422
#       `period_not_exportable`, details.state=open. And a nonexistent period is 404-not-403
#       (route-binding + OfficeScope both land in the same NotFoundHttpException).
#
# The script cleans its own July cutoff state at the DB before it starts (drops any Manila July
# cutoff_periods, resets any left-locked July summaries to computed), so it is safe to rerun
# without a reseed. It leaves the first-half period OPEN at the end (its last act is the reopen),
# so a rerun's opening close always succeeds.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, Manila HR, closes/reopens/exports)
# and employee.manila@hris.test (Miguel Santos, MNL-0002, reads his own calendar). Password
# `password`. API host defaults to the dev port (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The first-half semi-monthly window this script closes and exports — the seeded clean run.
FIRST_START="2026-07-01"
FIRST_END="2026-07-15"
MONTH="2026-07"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

psql_() {   # $1 sql -> prints the -tAc result, against the compose db
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db psql -U hris -d hris -tAc "$1"
}

# A request that CAPTURES both the HTTP status and the body — the error paths assert on BOTH the
# status code AND the envelope's `error.code`, so plain `curl -sf` (which discards the body on a
# 4xx) will not do. Sets REQ_CODE and REQ_BODY.
req() {   # $1 METHOD, $2 URL, then extra curl args (headers/data)
  local resp
  resp=$(curl -s -w $'\n%{http_code}' -X "$1" "$2" "${@:3}")
  REQ_CODE="${resp##*$'\n'}"
  REQ_BODY="${resp%$'\n'*}"
}

# 1. Log in as Carmen (HR, closes/reopens/exports) and Miguel (reads his own calendar).
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"

MANILA_ID=$(curl -sf "$API/me" -H "$H" | jq -r '.data.hr_offices[0]')
echo "1. hr.manila and employee.manila logged in; Manila office=$MANILA_ID"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

MIGUEL_ME=$(curl -sf "$API/me" -H "$E")
MIGUEL_ID=$(echo "$MIGUEL_ME" | jq -r .data.employee.id)
echo "   employee.manila's employee id (Miguel Santos): $MIGUEL_ID"
[ "$(echo "$MIGUEL_ME" | jq -r .data.employee.employee_no)" = "MNL-0002" ] \
  || { echo "FAIL: employee.manila is not seeded as MNL-0002 — did the seeder run?"; exit 1; }

# Rerun-safety: drop any Manila July cutoff_periods and reset any left-locked July summaries to
# computed. A prior run leaves the first-half period reopened (open) with its summaries computed,
# but a crash mid-run could leave it closed/locked; this normalizes either way.
psql_ "delete from cutoff_periods where office_id = '$MANILA_ID' and start_date between '$FIRST_START' and '2026-07-31'" >/dev/null
psql_ "update daily_attendance_summaries set status = 'computed' where office_id = '$MANILA_ID' and status = 'locked' and date between '$FIRST_START' and '2026-07-31'" >/dev/null
echo "   cleared prior July cutoff state for Manila (rerun-safe)"

# 2. Close the clean first-half window so it is exportable — an export is defined only for a
#    finalized period.
req POST "$API/office/cutoffs/close" -H "$H" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"period_start\":\"$FIRST_START\"}"
echo "2. POST /office/cutoffs/close (first half, $FIRST_START): HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
{ [ "$REQ_CODE" = "200" ] || [ "$REQ_CODE" = "201" ]; } || { echo "FAIL: closing the first-half window did not return 200/201"; exit 1; }
PERIOD_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$PERIOD_ID" ] && [ "$PERIOD_ID" != "null" ] || { echo "FAIL: close did not return a period id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.state)" = "closed" ] || { echo "FAIL: the closed period's state is not 'closed'"; exit 1; }

# 3. GET the export as HR — 200, with the period header + a per-employee earnings breakdown.
req GET "$API/office/cutoffs/$PERIOD_ID/export" -H "$H"
echo "3. GET /office/cutoffs/$PERIOD_ID/export: HTTP $REQ_CODE, $(echo "$REQ_BODY" | jq '.data.employees | length') employee(s)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: exporting a closed period did not return 200"; exit 1; }
EXPORT_1="$REQ_BODY"
[ "$(echo "$EXPORT_1" | jq -r .data.period.state)" = "closed" ] || { echo "FAIL: the export's period header state is not 'closed'"; exit 1; }
[ "$(echo "$EXPORT_1" | jq -r .data.period.start_date)" = "$FIRST_START" ] || { echo "FAIL: export period start_date is not $FIRST_START"; exit 1; }
[ "$(echo "$EXPORT_1" | jq -r .data.period.end_date)" = "$FIRST_END" ] || { echo "FAIL: export period end_date is not $FIRST_END"; exit 1; }

# Miguel must be in the export, with a clean (non-incomplete) first half and at least one line.
MIGUEL_EXPORT=$(echo "$EXPORT_1" | jq --arg id "$MIGUEL_ID" '.data.employees[] | select(.employee.id == $id)')
[ -n "$MIGUEL_EXPORT" ] || { echo "FAIL: Miguel ($MIGUEL_ID) is not in the export"; exit 1; }
echo "   Miguel: employee_no=$(echo "$MIGUEL_EXPORT" | jq -r .employee.employee_no) base_rate_cents=$(echo "$MIGUEL_EXPORT" | jq -r .employee.base_rate_cents) has_incomplete_days=$(echo "$MIGUEL_EXPORT" | jq -r .has_incomplete_days) lines=$(echo "$MIGUEL_EXPORT" | jq '.lines | length')"
[ "$(echo "$MIGUEL_EXPORT" | jq -r .has_incomplete_days)" = "false" ] || { echo "FAIL: Miguel's clean first-half export reports has_incomplete_days=true"; exit 1; }
[ "$(echo "$MIGUEL_EXPORT" | jq '.lines | length')" -ge 1 ] || { echo "FAIL: Miguel's export has no earnings lines"; exit 1; }
[ "$(echo "$MIGUEL_EXPORT" | jq -r .employee.base_rate_cents)" != "null" ] || { echo "FAIL: Miguel's export carries no base_rate_cents (reference)"; exit 1; }

# 4. THE RECONCILIATION. Miguel reads his own calendar for the month; we sum it over the in-period
#    dates and assert the export is a faithful, line-for-line roll-up of it.
SUMMARY=$(curl -sf "$API/me/attendance/summary?month=$MONTH" -H "$E")

# Per-(kind, applied_bp, rule_version_id) minutes. A summary line has no rule_version_id of its
# own — it is paired with its parent DAY's rule_version_id, exactly as the aggregator groups.
EXPORT_LINES=$(echo "$MIGUEL_EXPORT" | jq -cS \
  '.lines | map({key: (.kind + "|" + (.applied_bp|tostring) + "|" + (.rule_version_id // "null")), minutes}) | sort_by(.key)')
SUMMARY_LINES=$(echo "$SUMMARY" | jq -cS --arg s "$FIRST_START" --arg e "$FIRST_END" \
  '[.data[] | select(.date >= $s and .date <= $e) as $d | $d.lines[] | {key: (.kind + "|" + (.applied_bp|tostring) + "|" + ($d.rule_version_id // "null")), minutes}]
   | group_by(.key) | map({key: .[0].key, minutes: (map(.minutes) | add)}) | sort_by(.key)')
echo "4. reconcile Miguel's export lines against his calendar summed over $FIRST_START..$FIRST_END:"
echo "   export : $EXPORT_LINES"
echo "   summary: $SUMMARY_LINES"
[ "$EXPORT_LINES" = "$SUMMARY_LINES" ] \
  || { echo "FAIL: the export's per-(kind, applied_bp, rule_version_id) line minutes do NOT reconcile with the calendar"; exit 1; }

# The four totals match the summed day scalars too.
for f in worked_minutes late_minutes undertime_minutes unpaid_overtime_minutes; do
  EX=$(echo "$MIGUEL_EXPORT" | jq ".totals.$f")
  SU=$(echo "$SUMMARY" | jq --arg s "$FIRST_START" --arg e "$FIRST_END" "[.data[] | select(.date >= \$s and .date <= \$e) | .$f] | add // 0")
  echo "   totals.$f: export=$EX summary=$SU"
  [ "$EX" = "$SU" ] || { echo "FAIL: totals.$f does not reconcile (export=$EX, summary=$SU)"; exit 1; }
done

# 5. REPRODUCIBILITY: a second export of the still-locked period is byte-identical in its `data`
#    payload — the numbers are frozen, so the export is stable (the resource carries no volatile
#    field, so the whole `data` compares; we normalize with jq -cS to rule out incidental ordering).
req GET "$API/office/cutoffs/$PERIOD_ID/export" -H "$H"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: the second export did not return 200"; exit 1; }
DATA_1=$(echo "$EXPORT_1" | jq -cS .data)
DATA_2=$(echo "$REQ_BODY" | jq -cS .data)
echo "5. re-export byte-identical data payload: $([ "$DATA_1" = "$DATA_2" ] && echo yes || echo NO)"
[ "$DATA_1" = "$DATA_2" ] || { echo "FAIL: two exports of the same locked period differ — the numbers are not stable"; exit 1; }

# 6. CLOSED-ONLY. Reopen the period, then the export is refused 422 period_not_exportable — an
#    export is defined only for a finalized period, and reopen is a true inverse of close.
req POST "$API/office/cutoffs/$PERIOD_ID/reopen" -H "$H" -H "$J" \
  -d '{"reason":"E2E: reopening to prove the export is closed-only (period_not_exportable)."}'
[ "$REQ_CODE" = "200" ] || { echo "FAIL: reopen did not return 200"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.state)" = "open" ] || { echo "FAIL: the reopened period's state is not 'open'"; exit 1; }

req GET "$API/office/cutoffs/$PERIOD_ID/export" -H "$H"
echo "6. GET export on the reopened (open) period: HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code) details=$(echo "$REQ_BODY" | jq -c .error.details)"
[ "$REQ_CODE" = "422" ] || { echo "FAIL: exporting an OPEN period was not refused 422"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "period_not_exportable" ] \
  || { echo "FAIL: the refusal code is not period_not_exportable"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.details.state)" = "open" ] \
  || { echo "FAIL: period_not_exportable details.state is not 'open'"; exit 1; }

# 7. SCOPING is 404-not-403: a nonexistent period id is a 404, not a 403 — route-binding and
#    OfficeScope both land in the same NotFoundHttpException, so a foreign period is never
#    distinguishable from one that does not exist.
req GET "$API/office/cutoffs/019fa64b-0000-0000-0000-000000000000/export" -H "$H"
echo "7. GET export for a nonexistent period id: HTTP $REQ_CODE (expect 404)"
[ "$REQ_CODE" = "404" ] || { echo "FAIL: a nonexistent period export was not 404"; exit 1; }

echo "OK: Manila HR closed the clean first-half window ($FIRST_START..$FIRST_END) and exported it;"
echo "    the export's per-(kind, applied_bp, rule_version_id) line minutes and its worked/late/"
echo "    undertime/unpaid_overtime totals reconcile EXACTLY, line-for-line, with Miguel's own"
echo "    calendar summed over the in-period dates; a second export of the still-locked period is"
echo "    byte-identical (the numbers are frozen); reopening the period makes the export refuse"
echo "    422 period_not_exportable (closed-only); and a nonexistent period is 404-not-403 —"
echo "    all against the live stack."
