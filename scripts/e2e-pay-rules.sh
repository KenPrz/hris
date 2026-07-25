#!/bin/bash
#
# M4c 'pay rules' — the milestone's end-to-end proof, runnable against a freshly seeded
# stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-holidays.sh's structure and seeded logins. It walks the whole
# create/list/floor/duplicate/immutability/scope path against the real API:
#
#   Sofia Reyes (sysadmin@hris.test, System Admin) creates a floor-valid 2027 pay-rule
#   version with regular-holiday-worked at 250% (above the 200% statutory floor) and sees
#   it, alongside the seeded 2026 default, on the list; the same shape but
#   regular-holiday-worked at 150% (below the 200% floor) is refused `422
#   pay_rate_below_floor`, naming exactly that cell; re-submitting the 2027 version's
#   `effective_from` a second time is refused `409 pay_rule_exists`; the created version
#   is readable by id but has no PATCH route at all (`405`, not `404` — the route simply
#   doesn't exist for that verb); and Carmen Lim (hr.manila@hris.test, HR Admin) gets
#   `403 forbidden` on both GET and POST, never the 404-not-403 treatment M4a/M4b use,
#   because pay rules are a company singleton with nothing to enumerate. Finally, the
#   activity log names Sofia as the causer of the created version, with the version
#   itself as the uuid-morph subject.
#
# One thing this script deliberately does NOT assert via HTTP: reading the activity log.
# There is no `GET` endpoint for it yet (that's M7's audit-log viewer), so the
# causer/subject proof reads the `activity_log` table directly, the same way
# scripts/e2e-holidays.sh and scripts/e2e-adjustments.sh do.
#
# Seeded logins used here: sysadmin@hris.test (Sofia Reyes, System Admin — the only actor
# who may touch this endpoint at all) and hr.manila@hris.test (Carmen Lim, HR Admin — the
# non-admin used to prove the 403 gate). Both password `password`. CompanySeeder also
# seeds one default pay-rule version effective 2026-01-01 at exactly the statutory floor
# (M4c), so `/admin/pay-rules` isn't empty on `make dev`; this script deliberately uses a
# fresh 2027-01-01 `effective_from` for everything it creates so it never collides with
# that seeded row.
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

# A floor-valid day_rates matrix: every cell at exactly the statutory floor
# (config('hris.pay_floors')) except regular_holiday, whose `worked_bp` is bumped to
# 25000 (250%) — comfortably above the 200% floor — so the created version is visibly
# richer than the statutory minimum, not just a floor copy.
FLOOR_VALID_DAY_RATES='[
  {"day_type":"ordinary","worked_bp":10000,"worked_rest_bp":13000,"unworked_bp":0},
  {"day_type":"special_working","worked_bp":10000,"worked_rest_bp":13000,"unworked_bp":0},
  {"day_type":"special_non_working","worked_bp":13000,"worked_rest_bp":15000,"unworked_bp":0},
  {"day_type":"regular_holiday","worked_bp":25000,"worked_rest_bp":26000,"unworked_bp":10000},
  {"day_type":"double_regular_holiday","worked_bp":30000,"worked_rest_bp":39000,"unworked_bp":20000}
]'

# The same matrix, but regular_holiday's worked_bp dropped to 15000 (150%) — below the
# 20000 (200%) floor — so the create is refused rather than silently accepted.
BELOW_FLOOR_DAY_RATES='[
  {"day_type":"ordinary","worked_bp":10000,"worked_rest_bp":13000,"unworked_bp":0},
  {"day_type":"special_working","worked_bp":10000,"worked_rest_bp":13000,"unworked_bp":0},
  {"day_type":"special_non_working","worked_bp":13000,"worked_rest_bp":15000,"unworked_bp":0},
  {"day_type":"regular_holiday","worked_bp":15000,"worked_rest_bp":26000,"unworked_bp":10000},
  {"day_type":"double_regular_holiday","worked_bp":30000,"worked_rest_bp":39000,"unworked_bp":20000}
]'

# 1. Log in as the System Admin — the only actor who may touch /admin/pay-rules at all.
SYSADMIN_TOKEN=$(login sysadmin@hris.test password)
S="Authorization: Bearer $SYSADMIN_TOKEN"

SYSADMIN_ME=$(curl -sf "$API/me" -H "$S")
SYSADMIN_USER_ID=$(echo "$SYSADMIN_ME" | jq -r '.data.user.id')
echo "1. sysadmin@hris.test logged in; user_id=$SYSADMIN_USER_ID"
[ -n "$SYSADMIN_USER_ID" ] && [ "$SYSADMIN_USER_ID" != "null" ] || { echo "FAIL: sysadmin has no user id — did the seeder run?"; exit 1; }

# 2. Sofia creates a floor-valid 2027 version: regular_holiday worked at 250%, everything
#    else at exactly the statutory floor.
CREATE=$(curl -sf -X POST "$API/admin/pay-rules" -H "$S" -H "$J" -d "{
    \"effective_from\": \"2027-01-01\",
    \"overtime_ordinary_bp\": 12500,
    \"overtime_premium_bp\": 13000,
    \"night_diff_bp\": 11000,
    \"day_rates\": $FLOOR_VALID_DAY_RATES
  }")
PAY_RULE_ID=$(echo "$CREATE" | jq -r .data.id)
echo "2. sysadmin created a pay-rule version: id=$PAY_RULE_ID effective_from=$(echo "$CREATE" | jq -r .data.effective_from) \
day_rates=$(echo "$CREATE" | jq '.data.day_rates | length') (expect 2027-01-01 / 5)"
[ -n "$PAY_RULE_ID" ] && [ "$PAY_RULE_ID" != "null" ] || { echo "FAIL: create did not return an id"; exit 1; }
[ "$(echo "$CREATE" | jq -r .data.effective_from)" = "2027-01-01" ] || { echo "FAIL: created version has the wrong effective_from"; exit 1; }
[ "$(echo "$CREATE" | jq '.data.day_rates | length')" = "5" ] || { echo "FAIL: created version does not have exactly 5 day_rates"; exit 1; }
[ "$(echo "$CREATE" | jq -r '.data.day_rates[] | select(.day_type == "regular_holiday") | .worked_bp')" = "25000" ] \
  || { echo "FAIL: regular_holiday worked_bp is not 25000 (250%)"; exit 1; }

# 3. List and confirm it's present, alongside the seeded 2026 default.
LIST=$(curl -sf "$API/admin/pay-rules" -H "$S")
echo "3. GET /admin/pay-rules as sysadmin: $(echo "$LIST" | jq -c '.data | map(.effective_from)')"
[ "$(echo "$LIST" | jq --arg id "$PAY_RULE_ID" '[.data[] | select(.id == $id)] | length')" = "1" ] \
  || { echo "FAIL: the newly created version is missing from the list"; exit 1; }
[ "$(echo "$LIST" | jq '[.data[] | select(.effective_from == "2026-01-01")] | length')" = "1" ] \
  || { echo "FAIL: the seeded 2026-01-01 default version is missing — did the seeder run?"; exit 1; }

# 4. A below-floor write is refused 422 pay_rate_below_floor, naming exactly the
#    regular_holiday/not_rest cell.
BELOW_FLOOR=$(curl -s -w '\n%{http_code}' -X POST "$API/admin/pay-rules" -H "$S" -H "$J" -d "{
    \"effective_from\": \"2027-06-01\",
    \"overtime_ordinary_bp\": 12500,
    \"overtime_premium_bp\": 13000,
    \"night_diff_bp\": 11000,
    \"day_rates\": $BELOW_FLOOR_DAY_RATES
  }")
BELOW_FLOOR_BODY=$(echo "$BELOW_FLOOR" | head -n -1)
BELOW_FLOOR_STATUS=$(echo "$BELOW_FLOOR" | tail -n 1)
echo "4. below-floor create (regular_holiday worked 150%): HTTP $BELOW_FLOOR_STATUS code=$(echo "$BELOW_FLOOR_BODY" | jq -r .error.code) \
violations=$(echo "$BELOW_FLOOR_BODY" | jq -c .error.details.violations) (expect 422 / pay_rate_below_floor)"
[ "$BELOW_FLOOR_STATUS" = "422" ] || { echo "FAIL: below-floor create was not refused with 422"; exit 1; }
[ "$(echo "$BELOW_FLOOR_BODY" | jq -r .error.code)" = "pay_rate_below_floor" ] || { echo "FAIL: wrong error code on below-floor create"; exit 1; }
[ "$(echo "$BELOW_FLOOR_BODY" | jq -r '.error.details.violations[0].multiplier')" = "worked.regular_holiday.not_rest" ] \
  || { echo "FAIL: violations[0] does not name worked.regular_holiday.not_rest"; exit 1; }
[ "$(echo "$BELOW_FLOOR_BODY" | jq -r '.error.details.violations[0].proposed_bp')" = "15000" ] || { echo "FAIL: violations[0].proposed_bp is not 15000"; exit 1; }
[ "$(echo "$BELOW_FLOOR_BODY" | jq -r '.error.details.violations[0].floor_bp')" = "20000" ] || { echo "FAIL: violations[0].floor_bp is not 20000"; exit 1; }

# 5. A duplicate effective_from (reusing step 2's 2027-01-01) is refused 409
#    pay_rule_exists — race-safe via the unique-constraint catch, not a raw 500.
DUPLICATE=$(curl -s -w '\n%{http_code}' -X POST "$API/admin/pay-rules" -H "$S" -H "$J" -d "{
    \"effective_from\": \"2027-01-01\",
    \"overtime_ordinary_bp\": 12500,
    \"overtime_premium_bp\": 13000,
    \"night_diff_bp\": 11000,
    \"day_rates\": $FLOOR_VALID_DAY_RATES
  }")
DUPLICATE_BODY=$(echo "$DUPLICATE" | head -n -1)
DUPLICATE_STATUS=$(echo "$DUPLICATE" | tail -n 1)
echo "5. duplicate effective_from (2027-01-01 again): HTTP $DUPLICATE_STATUS code=$(echo "$DUPLICATE_BODY" | jq -r .error.code) (expect 409 / pay_rule_exists)"
[ "$DUPLICATE_STATUS" = "409" ] || { echo "FAIL: duplicate effective_from was not refused with 409"; exit 1; }
[ "$(echo "$DUPLICATE_BODY" | jq -r .error.code)" = "pay_rule_exists" ] || { echo "FAIL: wrong error code on duplicate effective_from"; exit 1; }

# 6. The version is readable by id, and there is no PATCH route at all — versions are
#    immutable, a correction is always a new version, never an edit in place.
SHOW=$(curl -sf "$API/admin/pay-rules/$PAY_RULE_ID" -H "$S")
echo "6a. GET /admin/pay-rules/\$id: id=$(echo "$SHOW" | jq -r .data.id) effective_from=$(echo "$SHOW" | jq -r .data.effective_from) (expect the id back)"
[ "$(echo "$SHOW" | jq -r .data.id)" = "$PAY_RULE_ID" ] || { echo "FAIL: GET by id did not return the same version"; exit 1; }

PATCH_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -X PATCH "$API/admin/pay-rules/$PAY_RULE_ID" -H "$S" -H "$J" -d '{"note":"tampered"}')
echo "6b. PATCH /admin/pay-rules/\$id: HTTP $PATCH_STATUS (expect 405 — no such route, immutable by design)"
[ "$PATCH_STATUS" = "405" ] || { echo "FAIL: PATCH on a pay-rule version was not refused with 405"; exit 1; }

# 7. A non-admin (Carmen Lim, HR Admin) gets 403 forbidden on both GET and POST — the
#    actor check a FormRequest::authorize() makes, never the 404-not-403 discipline M4a/
#    M4b use, because pay rules are a company singleton with nothing to enumerate.
HR_TOKEN=$(login hr.manila@hris.test password)
H="Authorization: Bearer $HR_TOKEN"

HR_LIST_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$API/admin/pay-rules" -H "$H")
echo "7a. hr.manila GET /admin/pay-rules: HTTP $HR_LIST_STATUS (expect 403)"
[ "$HR_LIST_STATUS" = "403" ] || { echo "FAIL: non-admin GET was not refused with 403"; exit 1; }

HR_CREATE=$(curl -s -w '\n%{http_code}' -X POST "$API/admin/pay-rules" -H "$H" -H "$J" -d "{
    \"effective_from\": \"2027-07-01\",
    \"overtime_ordinary_bp\": 12500,
    \"overtime_premium_bp\": 13000,
    \"night_diff_bp\": 11000,
    \"day_rates\": $FLOOR_VALID_DAY_RATES
  }")
HR_CREATE_BODY=$(echo "$HR_CREATE" | head -n -1)
HR_CREATE_STATUS=$(echo "$HR_CREATE" | tail -n 1)
echo "7b. hr.manila POST /admin/pay-rules: HTTP $HR_CREATE_STATUS code=$(echo "$HR_CREATE_BODY" | jq -r .error.code) (expect 403 / forbidden)"
[ "$HR_CREATE_STATUS" = "403" ] || { echo "FAIL: non-admin POST was not refused with 403"; exit 1; }
[ "$(echo "$HR_CREATE_BODY" | jq -r .error.code)" = "forbidden" ] || { echo "FAIL: wrong error code on non-admin POST"; exit 1; }

# 8. The activity log names Sofia as the causer of the created version (step 2), with the
#    version itself as the uuid-morph subject. No HTTP endpoint reads the log yet (that's
#    M7's audit-log viewer), so this reads `activity_log` directly, the same way
#    scripts/e2e-holidays.sh and scripts/e2e-adjustments.sh do.
ACTIVITY_ROW=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc \
  "select causer_id || '|' || subject_type || '|' || subject_id || '|' || event \
   from activity_log where subject_id = '$PAY_RULE_ID' and event = 'created'")
ACTIVITY_ROW=$(echo "$ACTIVITY_ROW" | tr -d '[:space:]')
echo "8. activity_log row for the created pay-rule version: $ACTIVITY_ROW \
(expect causer=$SYSADMIN_USER_ID | subject_type=App\\Models\\PayRule | subject_id=$PAY_RULE_ID | event=created)"
[ -n "$ACTIVITY_ROW" ] || { echo "FAIL: no activity_log row for the created pay-rule version"; exit 1; }
echo "$ACTIVITY_ROW" | grep -qF "${SYSADMIN_USER_ID}|App\\Models\\PayRule|${PAY_RULE_ID}|created" \
  || { echo "FAIL: activity_log row does not name Sofia as causer with the pay-rule version as subject"; exit 1; }

echo "OK: a floor-valid 2027 version (regular_holiday worked at 250%) created and listed"
echo "    alongside the seeded 2026 default, a below-floor write refused 422"
echo "    pay_rate_below_floor naming the exact offending cell, a duplicate effective_from"
echo "    refused 409 pay_rule_exists, the version readable by id but immutable (405 on"
echo "    PATCH — no such route), a non-admin refused 403 forbidden on both GET and POST,"
echo "    and the activity log naming Sofia as causer with the version as the uuid-morph"
echo "    subject — all against the live stack."
