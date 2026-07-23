#!/bin/bash
#
# M3.6 'attendance adjustments' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-timekeeping.sh's structure and seeded logins. It walks the whole
# request/approval path against the real API, with a real RustFS round-trip for the
# attachment:
#
#   a seeded Manila employee files an `add` adjustment WITH a file attachment (a genuine
#   missed clock-in); their manager approves it; the corrected punch shows up in
#   `GET /me/attendance` with `source: adjustment`; the attachment downloads for the
#   manager (200) and refuses an unrelated employee (404, never the file); the same
#   employee then files a `void` against one of CompanySeeder's two pre-seeded punches;
#   HR approves it; a fresh third request proves self-approval is refused (404, never a
#   different status that would confirm the request exists); and, finally, the two-process
#   row-lock proof lives only in the Pest suite
#   (tests/Feature/Attendance/ApproveRequestConcurrencyTest.php) — this script is a single
#   client walking one path at a time, not a concurrency test.
#
# One thing this script deliberately does NOT assert via HTTP: that the voided punch
# "disappears" from GET /me/attendance. It does not, on purpose — `/me/attendance` is the
# raw, append-only ledger (docs/02-data-model.md), and an annulled punch still happened and
# is still shown there; only the M5 compute engine (not yet built) reads the *effective*
# ledger (attendance_logs minus attendance_annulments). This script proves the void the way
# the milestone actually exposes it today: the approval succeeds, and the annulment row is
# there in the database. See docs/02-data-model.md's "Requests, adjustments, and the
# annulment ledger" section before assuming a filtered read exists — it doesn't yet.
#
# Seeded logins used here: employee.manila@hris.test (Miguel Santos, the requester),
# manager.manila@hris.test (Rosa Bautista, approves the add), hr.manila@hris.test (Carmen
# Lim, approves the void), andrea.manila@hris.test (a Manila peer with no authority over
# Miguel's requests — the "unrelated employee" for the 404 checks). All password `password`.
# CompanySeeder also seeds Miguel a manual in/out pair on 2026-01-15, so the void has a real
# target_log_id without this script having to punch and discover one first.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'
MONTH="$(date +%Y-%m)"
SEEDED_MONTH="2026-01"
SEEDED_DATE="2026-01-15"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

ATTACHMENT="$(mktemp /tmp/hris-e2e-proof.XXXXXX)"
mv "$ATTACHMENT" "${ATTACHMENT}.pdf"
ATTACHMENT="${ATTACHMENT}.pdf"
cleanup() { rm -f "$ATTACHMENT"; }
trap cleanup EXIT

# A minimal but genuinely PDF-shaped file — Media Library determines mime type from the
# real bytes (finfo), not the filename, so a plain-text file named *.pdf would still be
# rejected by the mimes:pdf rule. Same content shape SubmitAdjustmentTest uses.
for _ in $(seq 1 20); do printf '%%PDF-1.4\n'; done > "$ATTACHMENT"

# 1. Log in as the requester and their two approvers, plus an unrelated peer.
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
EMP_ID=$(curl -sf "$API/me" -H "$E" | jq -r .data.employee.id)
echo "1. employee.manila logged in; employee_id=$EMP_ID"

MANAGER_TOKEN=$(login manager.manila@hris.test password)
M="Authorization: Bearer $MANAGER_TOKEN"

HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

OTHER_TOKEN=$(login andrea.manila@hris.test password)
O="Authorization: Bearer $OTHER_TOKEN"

# 2. File an `add` adjustment for a missed clock-in, WITH the attachment — multipart, so
#    no Content-Type header (curl -F sets it, boundary and all).
ADD=$(curl -sf -X POST "$API/attendance/adjustments" -H "$E" \
  -F "operation=add" \
  -F "note=Forgot to clock in this morning, security guard can confirm." \
  -F "direction=in" \
  -F "punched_at=${MONTH}-05T08:00:00+08:00" \
  -F "attachment=@${ATTACHMENT};type=application/pdf;filename=proof.pdf")
ADD_ID=$(echo "$ADD" | jq -r .data.id)
echo "2. filed add adjustment: id=$ADD_ID state=$(echo "$ADD" | jq -r .data.state) \
has_attachment=$(echo "$ADD" | jq -r .data.has_attachment) (expect pending / true)"
[ "$(echo "$ADD" | jq -r .data.state)" = "pending" ] || { echo "FAIL: add adjustment not pending"; exit 1; }
[ "$(echo "$ADD" | jq -r .data.has_attachment)" = "true" ] || { echo "FAIL: attachment did not attach"; exit 1; }

# 3. Miguel's manager approves it. Manager is in EmployeeScope::visibleTo as Miguel's
#    reports_to, and is not Miguel himself, so RequestAuthority::canDecide holds.
APPROVE_ADD=$(curl -sf -X POST "$API/attendance/adjustments/$ADD_ID/approve" -H "$M")
echo "3. manager.manila approved: state=$(echo "$APPROVE_ADD" | jq -r .data.state) \
decided_by-is-manager=$([ "$(echo "$APPROVE_ADD" | jq -r .data.decided_by)" != null ] && echo yes)"
[ "$(echo "$APPROVE_ADD" | jq -r .data.state)" = "approved" ] || { echo "FAIL: add adjustment not approved"; exit 1; }

# 4. The corrected punch is now a real row in the ledger, source=adjustment — read back
#    exactly like any self-service or manual punch would be.
READ_ADD=$(curl -sf "$API/me/attendance?month=$MONTH" -H "$E" \
  | jq --arg d "${MONTH}-05" '.data[$d] // [] | map(select(.source == "adjustment"))')
echo "4. GET /me/attendance?month=$MONTH on ${MONTH}-05: $(echo "$READ_ADD" | jq -c '.')"
[ "$(echo "$READ_ADD" | jq 'length')" -ge 1 ] || { echo "FAIL: adjustment-sourced punch missing from the ledger read"; exit 1; }
[ "$(echo "$READ_ADD" | jq -r '.[0].direction')" = "in" ] || { echo "FAIL: wrong direction on the recorded punch"; exit 1; }

# 5. The attachment downloads for the manager who just approved it (200, real PDF bytes)…
STATUS_MGR=$(curl -s -o /tmp/hris-e2e-download.pdf -w "%{http_code}" \
  "$API/attendance/adjustments/$ADD_ID/attachment" -H "$M")
echo "5. manager.manila downloads the attachment: HTTP $STATUS_MGR (expect 200)"
[ "$STATUS_MGR" = "200" ] || { echo "FAIL: authorized download did not return 200"; exit 1; }
head -c 8 /tmp/hris-e2e-download.pdf | grep -q '%PDF' || { echo "FAIL: downloaded content is not the PDF we uploaded"; exit 1; }
rm -f /tmp/hris-e2e-download.pdf

# 6. …and refuses an unrelated peer with no authority over Miguel's requests — 404, never
#    a 403 that would confirm the request exists.
STATUS_OTHER=$(curl -s -o /dev/null -w "%{http_code}" \
  "$API/attendance/adjustments/$ADD_ID/attachment" -H "$O")
echo "6. andrea.manila (unrelated) downloads the same attachment: HTTP $STATUS_OTHER (expect 404)"
[ "$STATUS_OTHER" = "404" ] || { echo "FAIL: unrelated download was not refused with 404"; exit 1; }

# 7. Find the seeded punch to void: CompanySeeder recorded an in/out pair for Miguel on
#    2026-01-15, source=manual, through RecordPunch. Take the `in` one.
TARGET_LOG_ID=$(curl -sf "$API/me/attendance?month=$SEEDED_MONTH" -H "$E" \
  | jq -r --arg d "$SEEDED_DATE" '.data[$d][] | select(.direction == "in" and .source == "manual") | .id')
echo "7. seeded target punch to void: id=$TARGET_LOG_ID (2026-01-15, in, manual)"
[ -n "$TARGET_LOG_ID" ] && [ "$TARGET_LOG_ID" != "null" ] || { echo "FAIL: no seeded punch found to void — did the seeder run?"; exit 1; }

# 8. File the void against it.
VOID=$(curl -sf -X POST "$API/attendance/adjustments" -H "$E" -H "$J" \
  -d "{\"operation\":\"void\",\"note\":\"Duplicate entry, please remove.\",\"target_log_id\":\"$TARGET_LOG_ID\"}")
VOID_ID=$(echo "$VOID" | jq -r .data.id)
echo "8. filed void adjustment: id=$VOID_ID state=$(echo "$VOID" | jq -r .data.state) (expect pending)"
[ "$(echo "$VOID" | jq -r .data.state)" = "pending" ] || { echo "FAIL: void adjustment not pending"; exit 1; }

# 9. HR (not Miguel's manager this time — the OTHER kind of authorized approver) approves it.
APPROVE_VOID=$(curl -sf -X POST "$API/attendance/adjustments/$VOID_ID/approve" -H "$H")
echo "9. hr.manila approved the void: state=$(echo "$APPROVE_VOID" | jq -r .data.state) (expect approved)"
[ "$(echo "$APPROVE_VOID" | jq -r .data.state)" = "approved" ] || { echo "FAIL: void adjustment not approved"; exit 1; }

# 10. The raw ledger still shows the annulled punch — on purpose (docs/02-data-model.md).
#     The database-level fact IS the proof today: exactly one attendance_annulments row now
#     points at it, written by RecordAnnulment under the approval's row lock.
STILL_THERE=$(curl -sf "$API/me/attendance?month=$SEEDED_MONTH" -H "$E" \
  | jq --arg d "$SEEDED_DATE" --arg id "$TARGET_LOG_ID" '.data[$d] // [] | map(select(.id == $id)) | length')
echo "10. raw /me/attendance still lists the annulled punch: count=$STILL_THERE (expect 1 — raw ledger, never filtered)"
[ "$STILL_THERE" = "1" ] || { echo "FAIL: the raw (append-only) ledger lost a row — nothing should ever remove one"; exit 1; }

ANNULMENT_COUNT=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc "select count(*) from attendance_annulments where attendance_log_id = '$TARGET_LOG_ID'")
echo "    attendance_annulments rows for that punch: $(echo "$ANNULMENT_COUNT" | tr -d '[:space:]') \
(expect 1 — this is the effective ledger dropping it, proven at the DB the M5 engine will read)"
[ "$(echo "$ANNULMENT_COUNT" | tr -d '[:space:]')" = "1" ] || { echo "FAIL: no annulment row was written for the voided punch"; exit 1; }

# 11. Self-approval is refused. File one more trivial add, then have Miguel try to approve
#     his own pending request — 404, indistinguishable from a nonexistent id, never a
#     status that would confirm "this is yours, you just can't decide it."
SELF=$(curl -sf -X POST "$API/attendance/adjustments" -H "$E" -H "$J" \
  -d "{\"operation\":\"add\",\"note\":\"Self-approval refusal check.\",\"direction\":\"out\",\"punched_at\":\"${MONTH}-06T18:00:00+08:00\"}")
SELF_ID=$(echo "$SELF" | jq -r .data.id)
STATUS_SELF=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API/attendance/adjustments/$SELF_ID/approve" -H "$E")
echo "11. Miguel attempts to approve his own request $SELF_ID: HTTP $STATUS_SELF (expect 404)"
[ "$STATUS_SELF" = "404" ] || { echo "FAIL: self-approval was not refused with 404"; exit 1; }

echo "OK: add-with-attachment (manager-approved, ledger reflects it, attachment scoped to"
echo "    requester+approver), void-of-a-seeded-punch (HR-approved, annulled at the DB"
echo "    layer, raw ledger untouched), and self-approval refused — all against the live"
echo "    stack, real RustFS."
