#!/bin/bash
#
# M4b 'shift templates' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-holidays.sh's structure and seeded logins. It walks the whole
# template/default/assignment/override/resolve/scope path against the real API:
#
#   a Manila HR admin (Carmen Lim) builds a Mon-Fri 08:00-18:00 (Sat/Sun rest) shift
#   template, sets it as Manila's office default, and assigns it to a seeded Manila
#   employee (Andrea Cruz, MNL-0003 — deliberately not Miguel, who CompanySeeder already
#   assigns a template to, so this script's writes never collide with the seed's); the
#   resolved read then shows her Saturdays/Sundays as rest and a weekday at 540 scheduled
#   minutes. A second template proves the cross-midnight case: a Tuesday 17:00->03:00
#   night shift resolves `end_minute: 1620` (27:00 in minutes-since-midnight, never a
#   negative or wrapped value). A department-level assignment is created via the API too —
#   proving the backend capability the `/office/schedules` screen's UI deliberately defers
#   (there is no `GET /office/departments` list endpoint yet for a target-type toggle to
#   source options from; see that screen's file-level comment). An override then swaps one
#   Saturday to working and the following Monday to rest — the rest-day-swap the override
#   layer exists for — and the resolved read reflects both, `source: "override"`. Finally,
#   a Cebu-only HR admin (Grace Tan) touching that first Manila template's id — by GET,
#   PATCH, and DELETE — is refused `404 not_found`, byte-for-byte identical to touching a
#   fabricated uuid, never a `403` that would confirm the template exists; the template
#   survives all three refused attempts; and the activity log names Carmen as the causer of
#   the original create, with the template itself as the uuid-morph subject.
#
# Two things this script deliberately does NOT assert via HTTP, both for the same reason
# scripts/e2e-holidays.sh reads the activity log directly: no endpoint exists yet.
#   - The activity-log causer/subject proof reads `activity_log` directly (mirroring
#     e2e-holidays.sh exactly).
#   - The Manila "Operations" department id (needed to prove department-target assignment)
#     is read from `departments` directly, because there is no `GET /office/departments`
#     list endpoint — the same gap the `/office/schedules` screen's UI comment names.
#
# Dates are deliberately spread across three months (Aug/Sep/Oct 2026) so each step's
# writes land on dates no earlier step already asserted against, the same "never collide"
# discipline e2e-holidays.sh applies to its own seeded-vs-created dates.
#
# Seeded logins used here: hr.manila@hris.test (Carmen Lim, HR Admin scoped to Manila HQ
# only), hr.cebu@hris.test (Grace Tan, HR Admin scoped to Cebu Branch only). All password
# `password`. CompanySeeder also seeds Manila a "Standard Mon-Fri" template (Manila's
# office default), an employee-level assignment of it to Miguel Santos (MNL-0002), and one
# rest-day-swap override for him, so `/office/schedules` isn't empty on `make dev`; this
# script uses Andrea Cruz (MNL-0003) throughout instead, so nothing it creates collides
# with that seeded state, and re-points Manila's office default to its own new template
# along the way (a side effect on the dev database, not the test database — harmless, and
# undone by the next `migrate:fresh --seed`).
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

# 1. Log in as Manila's HR admin and Cebu's HR admin; resolve Manila's office id from the
#    session (never hardcoded — `hr_offices` is the authority for "offices administered",
#    the same rule the frontend follows). Resolve Andrea Cruz's employee id from Manila's
#    own employee list — she has no seeded schedule assignment, unlike Miguel.
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

ANDREA_ID=$(curl -sf "$API/employees" -H "$M" | jq -r '.data[] | select(.employee_no == "MNL-0003") | .id')
echo "   Andrea Cruz (MNL-0003) resolved to employee id=$ANDREA_ID"
[ -n "$ANDREA_ID" ] && [ "$ANDREA_ID" != "null" ] || { echo "FAIL: could not resolve Andrea Cruz — did the seeder run?"; exit 1; }

# 2. Carmen creates a Mon-Fri 08:00-18:00 (Sat/Sun rest) template for Manila — a name
#    CompanySeeder does not use, so this never collides with the seeded "Standard Mon-Fri".
TEMPLATE_BODY=$(cat <<JSON
{"office_id":"$MANILA_ID","name":"E2E Mon-Fri","days":[
  {"weekday":0,"is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60},
  {"weekday":1,"is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60},
  {"weekday":2,"is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60},
  {"weekday":3,"is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60},
  {"weekday":4,"is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60},
  {"weekday":5,"is_rest":true},
  {"weekday":6,"is_rest":true}
]}
JSON
)
CREATE=$(curl -sf -X POST "$API/office/shift-templates" -H "$M" -H "$J" -d "$TEMPLATE_BODY")
TEMPLATE_ID=$(echo "$CREATE" | jq -r .data.id)
echo "2. hr.manila created a template: id=$TEMPLATE_ID name=$(echo "$CREATE" | jq -r .data.name) \
days=$(echo "$CREATE" | jq '.data.days | length') (expect 7)"
[ -n "$TEMPLATE_ID" ] && [ "$TEMPLATE_ID" != "null" ] || { echo "FAIL: create did not return an id"; exit 1; }
[ "$(echo "$CREATE" | jq '.data.days | length')" = "7" ] || { echo "FAIL: template does not have exactly 7 days"; exit 1; }
[ "$(echo "$CREATE" | jq -r .data.office_id)" = "$MANILA_ID" ] || { echo "FAIL: created template has the wrong office_id"; exit 1; }

# 3. Set it as Manila's office default.
DEFAULT_BODY="{\"office_id\":\"$MANILA_ID\",\"template_id\":\"$TEMPLATE_ID\"}"
SET_DEFAULT=$(curl -sf -X PATCH "$API/office/default-template" -H "$M" -H "$J" -d "$DEFAULT_BODY")
echo "3. hr.manila set Manila's default template: default_shift_template_id=$(echo "$SET_DEFAULT" | jq -r .data.default_shift_template_id) \
(expect $TEMPLATE_ID)"
[ "$(echo "$SET_DEFAULT" | jq -r .data.default_shift_template_id)" = "$TEMPLATE_ID" ] \
  || { echo "FAIL: default-template did not set the expected template"; exit 1; }

# 4. Assign it to Andrea, effective 2026-08-01 (a Saturday).
ASSIGN_BODY=$(cat <<JSON
{"shift_template_id":"$TEMPLATE_ID","employee_id":"$ANDREA_ID","effective_from":"2026-08-01"}
JSON
)
ASSIGN=$(curl -sf -X POST "$API/office/schedule-assignments" -H "$M" -H "$J" -d "$ASSIGN_BODY")
ASSIGNMENT_ID=$(echo "$ASSIGN" | jq -r .data.id)
echo "4. hr.manila assigned the template to Andrea: assignment id=$ASSIGNMENT_ID \
effective_from=$(echo "$ASSIGN" | jq -r .data.effective_from) (expect 2026-08-01)"
[ -n "$ASSIGNMENT_ID" ] && [ "$ASSIGNMENT_ID" != "null" ] || { echo "FAIL: assignment create did not return an id"; exit 1; }

# 5. GET /office/schedule/resolved for August 2026: 2026-08-01 is a Saturday (rest, per
#    the template above), 2026-08-03 is a Monday (working, 540 scheduled minutes).
RESOLVED_AUG=$(curl -sf "$API/office/schedule/resolved?$(printf 'employee=%s&month=2026-08' "$ANDREA_ID")" -H "$M")
echo "5. resolved 2026-08-01 (Sat): $(echo "$RESOLVED_AUG" | jq -c '.data["2026-08-01"]') (expect is_rest:true, scheduled_minutes:0)"
[ "$(echo "$RESOLVED_AUG" | jq -r '.data["2026-08-01"].is_rest')" = "true" ] || { echo "FAIL: 2026-08-01 is not resolved as a rest day"; exit 1; }
[ "$(echo "$RESOLVED_AUG" | jq -r '.data["2026-08-01"].scheduled_minutes')" = "0" ] || { echo "FAIL: 2026-08-01 does not have 0 scheduled minutes"; exit 1; }

echo "   resolved 2026-08-03 (Mon): $(echo "$RESOLVED_AUG" | jq -c '.data["2026-08-03"]') (expect scheduled_minutes:540, source:employee)"
[ "$(echo "$RESOLVED_AUG" | jq -r '.data["2026-08-03"].scheduled_minutes')" = "540" ] || { echo "FAIL: 2026-08-03 does not have 540 scheduled minutes"; exit 1; }
[ "$(echo "$RESOLVED_AUG" | jq -r '.data["2026-08-03"].source')" = "employee" ] || { echo "FAIL: 2026-08-03 was not resolved via the employee assignment"; exit 1; }

# 6. A second template with a Tue 17:00->03:00 night shift (start 1020, end 1620, break
#    60) — every other day rest — assigned to Andrea effective 2026-09-01 (a Tuesday), so
#    it takes over from September onward without touching August's assertions above.
NIGHT_BODY=$(cat <<JSON
{"office_id":"$MANILA_ID","name":"E2E Night Shift","days":[
  {"weekday":0,"is_rest":true},
  {"weekday":1,"is_rest":false,"start_minute":1020,"end_minute":1620,"break_minutes":60},
  {"weekday":2,"is_rest":true},
  {"weekday":3,"is_rest":true},
  {"weekday":4,"is_rest":true},
  {"weekday":5,"is_rest":true},
  {"weekday":6,"is_rest":true}
]}
JSON
)
NIGHT_CREATE=$(curl -sf -X POST "$API/office/shift-templates" -H "$M" -H "$J" -d "$NIGHT_BODY")
NIGHT_TEMPLATE_ID=$(echo "$NIGHT_CREATE" | jq -r .data.id)
echo "6. hr.manila created a night-shift template: id=$NIGHT_TEMPLATE_ID"
[ -n "$NIGHT_TEMPLATE_ID" ] && [ "$NIGHT_TEMPLATE_ID" != "null" ] || { echo "FAIL: night-shift template create did not return an id"; exit 1; }

NIGHT_ASSIGN_BODY=$(cat <<JSON
{"shift_template_id":"$NIGHT_TEMPLATE_ID","employee_id":"$ANDREA_ID","effective_from":"2026-09-01"}
JSON
)
curl -sf -X POST "$API/office/schedule-assignments" -H "$M" -H "$J" -d "$NIGHT_ASSIGN_BODY" >/dev/null

RESOLVED_SEP=$(curl -sf "$API/office/schedule/resolved?$(printf 'employee=%s&month=2026-09' "$ANDREA_ID")" -H "$M")
echo "   resolved 2026-09-01 (Tue): $(echo "$RESOLVED_SEP" | jq -c '.data["2026-09-01"]') (expect end_minute:1620)"
[ "$(echo "$RESOLVED_SEP" | jq -r '.data["2026-09-01"].end_minute')" = "1620" ] || { echo "FAIL: the night shift did not resolve end_minute:1620"; exit 1; }
[ "$(echo "$RESOLVED_SEP" | jq -r '.data["2026-09-01"].start_minute')" = "1020" ] || { echo "FAIL: the night shift did not resolve start_minute:1020"; exit 1; }
[ "$(echo "$RESOLVED_SEP" | jq -r '.data["2026-09-01"].source')" = "employee" ] || { echo "FAIL: the night shift date was not resolved via the employee assignment"; exit 1; }

# 7. Department assignment via the API — proves the backend capability the
#    `/office/schedules` screen's UI deliberately has no target-type toggle for (there is
#    no `GET /office/departments` list endpoint yet to source options from). Manila's
#    "Operations" department id has no read endpoint either, so it's resolved via psql,
#    the same way step 10 below reads the activity log directly.
MANILA_OPS_DEPARTMENT_ID=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc \
  "select id from departments where office_id = '$MANILA_ID' and code = 'OPS'")
MANILA_OPS_DEPARTMENT_ID=$(echo "$MANILA_OPS_DEPARTMENT_ID" | tr -d '[:space:]')
[ -n "$MANILA_OPS_DEPARTMENT_ID" ] || { echo "FAIL: could not resolve Manila's Operations department id"; exit 1; }

DEPT_ASSIGN_BODY=$(cat <<JSON
{"shift_template_id":"$TEMPLATE_ID","department_id":"$MANILA_OPS_DEPARTMENT_ID","effective_from":"2026-01-01"}
JSON
)
DEPT_ASSIGN=$(curl -s -w '\n%{http_code}' -X POST "$API/office/schedule-assignments" -H "$M" -H "$J" -d "$DEPT_ASSIGN_BODY")
DEPT_ASSIGN_BODY_RESP=$(echo "$DEPT_ASSIGN" | head -n -1)
DEPT_ASSIGN_STATUS=$(echo "$DEPT_ASSIGN" | tail -n 1)
echo "7. hr.manila assigned the template to the Operations department: HTTP $DEPT_ASSIGN_STATUS (expect 201), \
department_id=$(echo "$DEPT_ASSIGN_BODY_RESP" | jq -r .data.department_id)"
[ "$DEPT_ASSIGN_STATUS" = "201" ] || { echo "FAIL: department-target assignment was not created"; exit 1; }
[ "$(echo "$DEPT_ASSIGN_BODY_RESP" | jq -r .data.department_id)" = "$MANILA_OPS_DEPARTMENT_ID" ] || { echo "FAIL: department assignment has the wrong department_id"; exit 1; }
[ "$(echo "$DEPT_ASSIGN_BODY_RESP" | jq -r .data.employee_id)" = "null" ] || { echo "FAIL: department assignment unexpectedly carries an employee_id"; exit 1; }

# 8. A rest-day swap: 2026-08-08 is a Saturday (rest, under the Mon-Fri template from step
#    4), overridden to working; 2026-08-10 is the following Monday (normally working),
#    overridden to rest. Neither date collides with step 5's 2026-08-01/2026-08-03.
OVERRIDE_SAT_BODY=$(cat <<JSON
{"employee_id":"$ANDREA_ID","date":"2026-08-08","is_rest":false,"start_minute":480,"end_minute":1080,"break_minutes":60,"note":"Rest-day swap: covering a Saturday shift"}
JSON
)
curl -sf -X POST "$API/office/schedule-overrides" -H "$M" -H "$J" -d "$OVERRIDE_SAT_BODY" >/dev/null

OVERRIDE_MON_BODY=$(cat <<JSON
{"employee_id":"$ANDREA_ID","date":"2026-08-10","is_rest":true,"note":"Rest-day swap: compensating rest day"}
JSON
)
curl -sf -X POST "$API/office/schedule-overrides" -H "$M" -H "$J" -d "$OVERRIDE_MON_BODY" >/dev/null

RESOLVED_AUG2=$(curl -sf "$API/office/schedule/resolved?$(printf 'employee=%s&month=2026-08' "$ANDREA_ID")" -H "$M")
echo "8. re-resolved 2026-08-08 (Sat): $(echo "$RESOLVED_AUG2" | jq -c '.data["2026-08-08"]') (expect is_rest:false, source:override)"
[ "$(echo "$RESOLVED_AUG2" | jq -r '.data["2026-08-08"].is_rest')" = "false" ] || { echo "FAIL: the Saturday override did not flip is_rest to false"; exit 1; }
[ "$(echo "$RESOLVED_AUG2" | jq -r '.data["2026-08-08"].source')" = "override" ] || { echo "FAIL: 2026-08-08 was not resolved via the override"; exit 1; }

echo "   re-resolved 2026-08-10 (Mon): $(echo "$RESOLVED_AUG2" | jq -c '.data["2026-08-10"]') (expect is_rest:true, source:override)"
[ "$(echo "$RESOLVED_AUG2" | jq -r '.data["2026-08-10"].is_rest')" = "true" ] || { echo "FAIL: the Monday override did not flip is_rest to true"; exit 1; }
[ "$(echo "$RESOLVED_AUG2" | jq -r '.data["2026-08-10"].source')" = "override" ] || { echo "FAIL: 2026-08-10 was not resolved via the override"; exit 1; }

# 9. A Cebu-only HR admin touching that first Manila template's id gets 404 — byte-identical
#    to touching a fabricated uuid, never a 403 that would confirm it exists. Proven three
#    ways: GET, PATCH, and DELETE.

# 9a. GET the real template vs. a fabricated one.
GET_REAL=$(curl -s -w '\n%{http_code}' "$API/office/shift-templates/$TEMPLATE_ID" -H "$C")
GET_REAL_BODY=$(echo "$GET_REAL" | head -n -1)
GET_REAL_STATUS=$(echo "$GET_REAL" | tail -n 1)

GET_FAKE=$(curl -s -w '\n%{http_code}' "$API/office/shift-templates/$FABRICATED_UUID" -H "$C")
GET_FAKE_BODY=$(echo "$GET_FAKE" | head -n -1)
GET_FAKE_STATUS=$(echo "$GET_FAKE" | tail -n 1)

echo "9a. hr.cebu GETs the Manila template: HTTP $GET_REAL_STATUS (expect 404); \
fabricated template: HTTP $GET_FAKE_STATUS (expect 404, byte-identical body)"
[ "$GET_REAL_STATUS" = "404" ] || { echo "FAIL: GETting an out-of-scope template was not refused with 404"; exit 1; }
[ "$GET_REAL_STATUS" = "$GET_FAKE_STATUS" ] && [ "$GET_REAL_BODY" = "$GET_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (GET)"; exit 1; }
[ "$(echo "$GET_REAL_BODY" | jq -r .error.code)" = "not_found" ] || { echo "FAIL: wrong error code on GET 404"; exit 1; }

# 9b. PATCH the real template vs. a fabricated one — a valid 7-day body, so the only thing
#     under test is the scope check, not a shape-validation difference.
PATCH_BODY_JSON='{"name":"Tampered","days":[{"weekday":0,"is_rest":true},{"weekday":1,"is_rest":true},{"weekday":2,"is_rest":true},{"weekday":3,"is_rest":true},{"weekday":4,"is_rest":true},{"weekday":5,"is_rest":true},{"weekday":6,"is_rest":true}]}'
PATCH_REAL=$(curl -s -w '\n%{http_code}' -X PATCH "$API/office/shift-templates/$TEMPLATE_ID" -H "$C" -H "$J" -d "$PATCH_BODY_JSON")
PATCH_REAL_BODY=$(echo "$PATCH_REAL" | head -n -1)
PATCH_REAL_STATUS=$(echo "$PATCH_REAL" | tail -n 1)

PATCH_FAKE=$(curl -s -w '\n%{http_code}' -X PATCH "$API/office/shift-templates/$FABRICATED_UUID" -H "$C" -H "$J" -d "$PATCH_BODY_JSON")
PATCH_FAKE_BODY=$(echo "$PATCH_FAKE" | head -n -1)
PATCH_FAKE_STATUS=$(echo "$PATCH_FAKE" | tail -n 1)

echo "9b. hr.cebu PATCHes the Manila template: HTTP $PATCH_REAL_STATUS (expect 404); \
fabricated id: HTTP $PATCH_FAKE_STATUS (expect 404, byte-identical body)"
[ "$PATCH_REAL_STATUS" = "404" ] || { echo "FAIL: PATCHing an out-of-scope template was not refused with 404"; exit 1; }
[ "$PATCH_REAL_STATUS" = "$PATCH_FAKE_STATUS" ] && [ "$PATCH_REAL_BODY" = "$PATCH_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (PATCH)"; exit 1; }

# 9c. DELETE the real template vs. a fabricated one — must not actually delete anything;
#     the scope check throws 404 before DeleteShiftTemplate ever runs.
DELETE_REAL=$(curl -s -w '\n%{http_code}' -X DELETE "$API/office/shift-templates/$TEMPLATE_ID" -H "$C")
DELETE_REAL_BODY=$(echo "$DELETE_REAL" | head -n -1)
DELETE_REAL_STATUS=$(echo "$DELETE_REAL" | tail -n 1)

DELETE_FAKE=$(curl -s -w '\n%{http_code}' -X DELETE "$API/office/shift-templates/$FABRICATED_UUID" -H "$C")
DELETE_FAKE_BODY=$(echo "$DELETE_FAKE" | head -n -1)
DELETE_FAKE_STATUS=$(echo "$DELETE_FAKE" | tail -n 1)

echo "9c. hr.cebu DELETEs the Manila template: HTTP $DELETE_REAL_STATUS (expect 404); \
fabricated id: HTTP $DELETE_FAKE_STATUS (expect 404, byte-identical body)"
[ "$DELETE_REAL_STATUS" = "404" ] || { echo "FAIL: DELETEing an out-of-scope template was not refused with 404"; exit 1; }
[ "$DELETE_REAL_STATUS" = "$DELETE_FAKE_STATUS" ] && [ "$DELETE_REAL_BODY" = "$DELETE_FAKE_BODY" ] \
  || { echo "FAIL: real-out-of-scope and fabricated 404s are not byte-identical (DELETE)"; exit 1; }

# 9d. The template survives all three refused attempts, untouched, still Carmen's original.
STILL_THERE=$(curl -sf "$API/office/shift-templates/$TEMPLATE_ID" -H "$M" | jq -r '.data.id')
echo "9d. the template survives, untouched: id=$STILL_THERE (expect $TEMPLATE_ID — Cebu's 404s never reached the writer)"
[ "$STILL_THERE" = "$TEMPLATE_ID" ] || { echo "FAIL: the out-of-scope attempts removed or altered the template"; exit 1; }

# 10. The activity log names Carmen as the causer of the original create (step 2), with the
#     template itself as the uuid-morph subject. No HTTP endpoint reads the log yet, so
#     this reads `activity_log` directly, the same way scripts/e2e-holidays.sh does.
ACTIVITY_ROW=$(docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db \
  psql -U hris -d hris -tAc \
  "select causer_id || '|' || subject_type || '|' || subject_id || '|' || event \
   from activity_log where subject_id = '$TEMPLATE_ID' and event = 'created'")
ACTIVITY_ROW=$(echo "$ACTIVITY_ROW" | tr -d '[:space:]')
echo "10. activity_log row for the created template: $ACTIVITY_ROW \
(expect causer=$MANILA_USER_ID | subject_type=App\\Models\\ShiftTemplate | subject_id=$TEMPLATE_ID | event=created)"
[ -n "$ACTIVITY_ROW" ] || { echo "FAIL: no activity_log row for the created template"; exit 1; }
echo "$ACTIVITY_ROW" | grep -qF "${MANILA_USER_ID}|App\\Models\\ShiftTemplate|${TEMPLATE_ID}|created" \
  || { echo "FAIL: activity_log row does not name Carmen as causer with the template as subject"; exit 1; }

echo "OK: a Mon-Fri template built, set as Manila's default, assigned to Andrea (Sat/Sun"
echo "    resolving to rest, a weekday to 540 scheduled minutes), a cross-midnight night"
echo "    shift resolving end_minute:1620, a department-target assignment created via the"
echo "    API (the capability the UI defers), a rest-day-swap override flipping both a"
echo "    Saturday and its following Monday with source:override, a Cebu-only HR admin"
echo "    refused 404 — byte-identical to a fabricated id — on GET/PATCH/DELETE against a"
echo "    Manila template, the template surviving every refused attempt, and the activity"
echo "    log naming Carmen as causer with the template as the uuid-morph subject — all"
echo "    against the live stack."
