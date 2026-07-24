#!/bin/bash
#
# M4a 'holiday calendars' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-adjustments.sh's structure and seeded logins. It walks the whole
# create/list/scope/clone path against the real API:
#
#   a Manila HR admin (Carmen Lim) adds a Manila holiday and sees it on Manila's
#   `/office/holidays` list; a Cebu-only HR admin (Grace Tan) touching that same holiday's
#   id — by GET (list, scoped to office=<manila id>), PATCH, and DELETE — is refused `404
#   not_found`, byte-for-byte identical to touching a fabricated uuid, never a `403` that
#   would confirm the holiday exists; the holiday survives all three refused attempts;
#   Carmen then clones a 2025 calendar into 2026, proving both that a genuinely new date
#   copies across and that a date already occupied in the target year is skipped rather
#   than duplicated or overwritten; and, finally, the activity log names her as the causer
#   of the original create, with the holiday itself as the uuid-morph subject.
#
# One thing this script deliberately does NOT assert via HTTP: reading the activity log.
# There is no `GET` endpoint for it yet (M4a doesn't build an audit-log viewer — that's
# M7), so the causer/subject proof reads the `activity_log` table directly, the same way
# scripts/e2e-adjustments.sh reads `attendance_annulments` directly for its DB-only proof.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, HR Admin scoped to Manila HQ
# only), hr.cebu@hris.test (Grace Tan, HR Admin scoped to Cebu Branch only). All password
# `password`. CompanySeeder also seeds Manila two 2026 holidays (Ninoy Aquino Day Aug 21,
# Rizal Day Dec 30) so the screen isn't empty on `make dev`; this script deliberately picks
# different dates for everything it creates so it never collides with those seeded rows.
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

FABRICATED_UUID="00000000-0000-7000-8000-000000000000"

# 1. Log in as Manila's HR admin and Cebu's HR admin.
MANILA_TOKEN=$(login hr.manila@hris.test password)
M="Authorization: Bearer $MANILA_TOKEN"

CEBU_TOKEN=$(login hr.cebu@hris.test password)
C="Authorization: Bearer $CEBU_TOKEN"

MANILA_ME=$(curl -sf "$API/me" -H "$M")
MANILA_ID=$(echo "$MANILA_ME" | jq -r '.data.hr_offices[0]')
MANILA_USER_ID=$(echo "$MANILA_ME" | jq -r '.data.user.id')
echo "1. hr.manila logged in; hr_offices[0]=$MANILA_ID (Manila HQ), user_id=$MANILA_USER_ID"
[ -n "$MANILA_ID" ] && [ "$MANILA_ID" != "null" ] || { echo "FAIL: hr.manila has no hr_offices — did the seeder run?"; exit 1; }

CEBU_ID=$(curl -sf "$API/me" -H "$C" | jq -r '.data.hr_offices[0]')
echo "   hr.cebu logged in; hr_offices[0]=$CEBU_ID (Cebu Branch)"
[ -n "$CEBU_ID" ] && [ "$CEBU_ID" != "null" ] || { echo "FAIL: hr.cebu has no hr_offices — did the seeder run?"; exit 1; }
[ "$MANILA_ID" != "$CEBU_ID" ] || { echo "FAIL: Manila and Cebu resolved to the same office id"; exit 1; }

# 2. Carmen (Manila HR) creates a holiday for Manila — a date CompanySeeder does not seed,
#    so this never collides with the Aug 21 / Dec 30 rows already there.
CREATE=$(curl -sf -X POST "$API/office/holidays" -H "$M" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"date\":\"2026-06-12\",\"day_type\":\"regular_holiday\",\"name\":\"Independence Day\"}")
HOLIDAY_ID=$(echo "$CREATE" | jq -r .data.id)
echo "2. hr.manila created a holiday: id=$HOLIDAY_ID date=$(echo "$CREATE" | jq -r .data.date) \
day_type=$(echo "$CREATE" | jq -r .data.day_type) (expect 2026-06-12 / regular_holiday)"
[ -n "$HOLIDAY_ID" ] && [ "$HOLIDAY_ID" != "null" ] || { echo "FAIL: create did not return an id"; exit 1; }
[ "$(echo "$CREATE" | jq -r .data.office_id)" = "$MANILA_ID" ] || { echo "FAIL: created holiday has the wrong office_id"; exit 1; }
[ "$(echo "$CREATE" | jq -r .data.name)" = "Independence Day" ] || { echo "FAIL: created holiday has the wrong name"; exit 1; }

# 3. It shows up on Manila's own list for the year, alongside the two seeded holidays.
LIST_MANILA=$(curl -sf "$API/office/holidays?office=$MANILA_ID&year=2026" -H "$M")
echo "3. GET /office/holidays?office=<manila>&year=2026 as hr.manila: $(echo "$LIST_MANILA" | jq -c '.data | map(.date)')"
[ "$(echo "$LIST_MANILA" | jq --arg id "$HOLIDAY_ID" '[.data[] | select(.id == $id)] | length')" = "1" ] \
  || { echo "FAIL: the newly created holiday is missing from Manila's own list"; exit 1; }
[ "$(echo "$LIST_MANILA" | jq '.data | length')" -ge 3 ] \
  || { echo "FAIL: expected at least 3 Manila holidays (2 seeded + 1 just created)"; exit 1; }

# 4. A Cebu-only HR admin touching that same Manila holiday's id gets 404 — byte-identical
#    to touching a fabricated uuid, never a 403 that would confirm it exists. Proven three
#    ways: list (scoped by office=<manila id>), PATCH, and DELETE.

# 4a. List, scoped to Manila's office id — 404 before the query ever runs.
LIST_REAL=$(curl -s -w '\n%{http_code}' "$API/office/holidays?office=$MANILA_ID&year=2026" -H "$C")
LIST_REAL_BODY=$(echo "$LIST_REAL" | head -n -1)
LIST_REAL_STATUS=$(echo "$LIST_REAL" | tail -n 1)

LIST_FAKE=$(curl -s -w '\n%{http_code}' "$API/office/holidays?office=$FABRICATED_UUID&year=2026" -H "$C")
LIST_FAKE_BODY=$(echo "$LIST_FAKE" | head -n -1)
LIST_FAKE_STATUS=$(echo "$LIST_FAKE" | tail -n 1)

echo "4a. hr.cebu lists Manila's holidays: HTTP $LIST_REAL_STATUS (expect 404); \
fabricated office: HTTP $LIST_FAKE_STATUS (expect 404, byte-identical body)"
[ "$LIST_REAL_STATUS" = "404" ] || { echo "FAIL: listing an out-of-scope office was not refused with 404"; exit 1; }
[ "$LIST_REAL_STATUS" = "$LIST_FAKE_STATUS" ] && [ "$LIST_REAL_BODY" = "$LIST_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (list)"; exit 1; }
[ "$(echo "$LIST_REAL_BODY" | jq -r .error.code)" = "not_found" ] || { echo "FAIL: wrong error code on list 404"; exit 1; }

# 4b. PATCH the real holiday id vs. a fabricated one.
PATCH_BODY_JSON='{"day_type":"special_non_working","name":"Independence Day (tampered)"}'
PATCH_REAL=$(curl -s -w '\n%{http_code}' -X PATCH "$API/office/holidays/$HOLIDAY_ID" -H "$C" -H "$J" -d "$PATCH_BODY_JSON")
PATCH_REAL_BODY=$(echo "$PATCH_REAL" | head -n -1)
PATCH_REAL_STATUS=$(echo "$PATCH_REAL" | tail -n 1)

PATCH_FAKE=$(curl -s -w '\n%{http_code}' -X PATCH "$API/office/holidays/$FABRICATED_UUID" -H "$C" -H "$J" -d "$PATCH_BODY_JSON")
PATCH_FAKE_BODY=$(echo "$PATCH_FAKE" | head -n -1)
PATCH_FAKE_STATUS=$(echo "$PATCH_FAKE" | tail -n 1)

echo "4b. hr.cebu PATCHes the Manila holiday: HTTP $PATCH_REAL_STATUS (expect 404); \
fabricated id: HTTP $PATCH_FAKE_STATUS (expect 404, byte-identical body)"
[ "$PATCH_REAL_STATUS" = "404" ] || { echo "FAIL: PATCHing an out-of-scope holiday was not refused with 404"; exit 1; }
[ "$PATCH_REAL_STATUS" = "$PATCH_FAKE_STATUS" ] && [ "$PATCH_REAL_BODY" = "$PATCH_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (PATCH)"; exit 1; }

# 4c. DELETE the real holiday id vs. a fabricated one — must not actually delete anything;
#     the scope check throws 404 before DeleteHoliday ever runs.
DELETE_REAL=$(curl -s -w '\n%{http_code}' -X DELETE "$API/office/holidays/$HOLIDAY_ID" -H "$C")
DELETE_REAL_BODY=$(echo "$DELETE_REAL" | head -n -1)
DELETE_REAL_STATUS=$(echo "$DELETE_REAL" | tail -n 1)

DELETE_FAKE=$(curl -s -w '\n%{http_code}' -X DELETE "$API/office/holidays/$FABRICATED_UUID" -H "$C")
DELETE_FAKE_BODY=$(echo "$DELETE_FAKE" | head -n -1)
DELETE_FAKE_STATUS=$(echo "$DELETE_FAKE" | tail -n 1)

echo "4c. hr.cebu DELETEs the Manila holiday: HTTP $DELETE_REAL_STATUS (expect 404); \
fabricated id: HTTP $DELETE_FAKE_STATUS (expect 404, byte-identical body)"
[ "$DELETE_REAL_STATUS" = "404" ] || { echo "FAIL: DELETEing an out-of-scope holiday was not refused with 404"; exit 1; }
[ "$DELETE_REAL_STATUS" = "$DELETE_FAKE_STATUS" ] && [ "$DELETE_REAL_BODY" = "$DELETE_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (DELETE)"; exit 1; }

# 4d. The holiday survived all three refused attempts, untouched, still Carmen's original.
STILL_THERE=$(curl -sf "$API/office/holidays?office=$MANILA_ID&year=2026" -H "$M" \
  | jq --arg id "$HOLIDAY_ID" '[.data[] | select(.id == $id and .name == "Independence Day" and .day_type == "regular_holiday")] | length')
echo "4d. the holiday survives, untouched: count=$STILL_THERE (expect 1 — Cebu's 404s never reached the writer)"
[ "$STILL_THERE" = "1" ] || { echo "FAIL: the out-of-scope attempts changed or removed the holiday"; exit 1; }

# 5. Clone 2025 into 2026. Two 2025 source holidays: one whose target month/day is already
#    occupied in 2026 (by the holiday from step 2, so cloning it must be a no-op skip), and
#    one that is genuinely new (so cloning it must actually copy).
curl -sf -X POST "$API/office/holidays" -H "$M" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"date\":\"2025-06-12\",\"day_type\":\"regular_holiday\",\"name\":\"Independence Day 2025\"}" >/dev/null
curl -sf -X POST "$API/office/holidays" -H "$M" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"date\":\"2025-11-30\",\"day_type\":\"regular_holiday\",\"name\":\"Bonifacio Day\"}" >/dev/null
echo "5. seeded two 2025 Manila holidays: Independence Day (target already occupied) and Bonifacio Day (target open)"

CLONE=$(curl -sf -X POST "$API/office/holidays/clone" -H "$M" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"from_year\":2025,\"to_year\":2026}")
echo "   clone 2025->2026: created=$(echo "$CLONE" | jq -c '.data | map(.date)') (expect exactly [\"2026-11-30\"] — \
2026-06-12 was already occupied and must be skipped, not duplicated or overwritten)"
[ "$(echo "$CLONE" | jq '.data | length')" = "1" ] || { echo "FAIL: clone created the wrong number of rows"; exit 1; }
[ "$(echo "$CLONE" | jq -r '.data[0].date')" = "2026-11-30" ] || { echo "FAIL: clone did not create the expected new date"; exit 1; }
[ "$(echo "$CLONE" | jq -r '.data[0].name')" = "Bonifacio Day" ] || { echo "FAIL: clone did not carry the source name across"; exit 1; }

# 6. Re-cloning the identical range is a true no-op — everything is now occupied.
RECLONE=$(curl -sf -X POST "$API/office/holidays/clone" -H "$M" -H "$J" \
  -d "{\"office_id\":\"$MANILA_ID\",\"from_year\":2025,\"to_year\":2026}")
echo "6. re-cloning 2025->2026: created=$(echo "$RECLONE" | jq -c '.data') (expect [] — fully re-runnable, nothing duplicated)"
[ "$(echo "$RECLONE" | jq '.data | length')" = "0" ] || { echo "FAIL: re-cloning an already-cloned range created rows"; exit 1; }

# 7. The activity log names Carmen as the causer of the original create (step 2), with the
#    holiday itself as the uuid-morph subject. No HTTP endpoint reads the log yet (M4a
#    doesn't build an audit-log viewer — that's M7), so this reads `activity_log` directly,
#    the same way scripts/e2e-adjustments.sh reads `attendance_annulments` directly.
ACTIVITY_ROW=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc \
  "select causer_id || '|' || subject_type || '|' || subject_id || '|' || event \
   from activity_log where subject_id = '$HOLIDAY_ID' and event = 'created'")
ACTIVITY_ROW=$(echo "$ACTIVITY_ROW" | tr -d '[:space:]')
echo "7. activity_log row for the created holiday: $ACTIVITY_ROW \
(expect causer=$MANILA_USER_ID | subject_type=App\\Models\\Holiday | subject_id=$HOLIDAY_ID | event=created)"
[ -n "$ACTIVITY_ROW" ] || { echo "FAIL: no activity_log row for the created holiday"; exit 1; }
echo "$ACTIVITY_ROW" | grep -qF "${MANILA_USER_ID}|App\\Models\\Holiday|${HOLIDAY_ID}|created" \
  || { echo "FAIL: activity_log row does not name Carmen as causer with the holiday as subject"; exit 1; }

echo "OK: Manila-scoped create+list (past the two seeded holidays), a Cebu-only HR admin"
echo "    refused 404 — byte-identical to a fabricated id — on GET/PATCH/DELETE against a"
echo "    Manila holiday, the holiday surviving every refused attempt, clone-from-2025"
echo "    both skipping an occupied date and copying a genuinely new one (and re-running"
echo "    as a true no-op), and the activity log naming Carmen as causer with the holiday"
echo "    as the uuid-morph subject — all against the live stack."
