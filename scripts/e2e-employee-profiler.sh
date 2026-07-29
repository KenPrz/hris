#!/bin/bash
#
# M8b 'employee profiler' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-admin-org.sh's structure VERBATIM: the same login/envelope-parsing helpers,
# base URL, per-assertion PASS/FAIL, `exit 1` on any mismatch, and `psql` for the checks
# the API has no read surface for yet. It walks the whole system-admin employee-profiler
# flow against the real API:
#
#   Sofia Reyes (sysadmin@hris.test, the seeded System Admin — the is_system_admin flag,
#   the only actor the /admin/employees surface admits) onboards a brand-new employee by
#   name, then lists, inspects, gives a login to, and renames them:
#
#     - ATTACH POINT: a fresh organization -> office -> department subtree is built via the
#       /admin org-tree surface (the same one e2e-admin-org.sh exercises) so the new
#       employee attaches to real, known-good ids rather than guessing at a seed row.
#
#     - CREATE (Task 3): POST /admin/employees with a full name (first/middle/last/suffix)
#       + an `employment` block -> 201; the response `full_name` composes correctly
#       ("Juan Santos Cruz Jr." — the Employee::fullName() accessor, single-spaced).
#
#     - LIST (Task 4): the new employee appears in GET /admin/employees with that full_name
#       and has_user:false (no login provisioned yet).
#
#     - SHOW (Task 5): GET /admin/employees/{id} shows the name, the resolved current
#       employment (office/department/base_rate), and has_user:false.
#
#     - PROVISION (Task 6): POST /admin/employees/{id}/user (email+password) provisions a
#       login (201); re-fetching the detail flips has_user to true.
#
#     - RENAME (Task 7): PATCH /admin/employees/{id} edits the name (new last_name) -> 200;
#       GET /admin/employees/{id} then shows the updated full_name (employee_no unchanged —
#       it is immutable and has no field on the PATCH surface).
#
#   Finally the 403 gate, LIVE (Task 8): Miguel Santos (employee.manila@hris.test, a plain
#   rank-and-file employee with no is_system_admin flag) is refused 403 on POST
#   /admin/employees. Like the org tree, the profiler is global config with no subject in
#   the URL to scope by, so CreateEmployeeRequest::authorize() returns the default 403
#   forbidden — the deliberate global-admin exception to the 404-not-403 discipline.
#
# Rerun-safety: this script cleans its OWN prior E2E rows at the DB before it starts —
# employment_records first (they FK the employee), then the employees, then the provisioned
# users, then the org subtree child-first (departments, offices, organizations) — all
# matched by the 'E2E-M8B' marker so the seeded tree and staff are never touched. Every run
# uses a fresh epoch-suffixed employee_no / office code / provisioned email so nothing can
# collide with a leftover from a prior run.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# A per-run suffix so the (globally unique) office code, the employee_no, and the
# provisioned email never collide with a row a prior run left behind.
RUN="$(date +%s)"
ORG_NAME="E2E-M8B Org $RUN"
OFFICE_NAME="E2E-M8B Office $RUN"
OFFICE_CODE="E2EB$RUN"
DEPT_NAME="E2E-M8B Department $RUN"
DEPT_CODE="E2EBD$RUN"
EMP_NO="E2E-M8B-$RUN"
EMP_EMAIL="e2e.m8b.$RUN@hris.test"
# The server's own UTC "today" (APP_TIMEZONE=UTC) — hired_at / effective_from must be on or
# before it, or EmploymentResolver (latest effective_from <= today) resolves current
# employment to null. Read from the wall clock, never a literal, so the script holds
# whatever day it runs.
TODAY="$(date -u +%F)"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

psql_() {   # $1 sql -> prints the -tAc result, against the compose db
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db psql -U hris -d hris -tAc "$1"
}

# A request that CAPTURES both the HTTP status and the body — the paths here assert on
# BOTH the status code AND the envelope (`.data` on success, `.error.code` on failure),
# so plain `curl -sf` (which discards the body on a 4xx) will not do. Sets REQ_CODE and
# REQ_BODY.
req() {   # $1 METHOD, $2 URL, then extra curl args (headers/data)
  local resp
  resp=$(curl -s -w $'\n%{http_code}' -X "$1" "$2" "${@:3}")
  REQ_CODE="${resp##*$'\n'}"
  REQ_BODY="${resp%$'\n'*}"
}

# 0. Rerun-safety: drop this script's OWN prior E2E-M8B rows. employment_records first (they
#    FK the employee), then the employees (which clears their user_id reference), then the
#    provisioned users, then the org subtree child-first. Matched by the 'E2E-M8B' marker so
#    the seeded staff and tree are never touched.
psql_ "delete from employment_records where employee_id in (select id from employees where employee_no like 'E2E-M8B%')" >/dev/null
psql_ "delete from employees where employee_no like 'E2E-M8B%'" >/dev/null
psql_ "delete from users where email like 'e2e.m8b.%@hris.test'" >/dev/null
psql_ "delete from departments where name like 'E2E-M8B %'" >/dev/null
psql_ "delete from offices where name like 'E2E-M8B %'" >/dev/null
psql_ "delete from organizations where name like 'E2E-M8B %'" >/dev/null
echo "0. cleared this script's prior E2E-M8B employee + org-tree rows (rerun-safe)"

# 1. Log in as the seeded System Admin (Sofia Reyes) — the is_system_admin flag, the only
#    actor the /admin/employees surface admits.
ADMIN_TOKEN=$(login sysadmin@hris.test password)
A="Authorization: Bearer $ADMIN_TOKEN"
echo "1. sysadmin@hris.test logged in"
[ -n "$ADMIN_TOKEN" ] && [ "$ADMIN_TOKEN" != "null" ] || { echo "FAIL: sysadmin login returned no token — did the seeder run?"; exit 1; }

# 2. Build a fresh organization -> office -> department subtree to attach the new employee
#    to, via the /admin org-tree surface (real, known-good ids rather than a seed guess).
req POST "$API/admin/organizations" -H "$A" -H "$J" \
  -d "{\"name\":\"$ORG_NAME\",\"legal_name\":\"$ORG_NAME, Inc.\",\"timezone\":\"Asia/Manila\"}"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating the attach-point organization did not return 201"; exit 1; }
ORG_ID=$(echo "$REQ_BODY" | jq -r .data.id)

req POST "$API/admin/offices" -H "$A" -H "$J" \
  -d "{\"organization_id\":\"$ORG_ID\",\"name\":\"$OFFICE_NAME\",\"code\":\"$OFFICE_CODE\",\"timezone\":\"Asia/Manila\"}"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating the attach-point office did not return 201"; exit 1; }
OFFICE_ID=$(echo "$REQ_BODY" | jq -r .data.id)

req POST "$API/admin/departments" -H "$A" -H "$J" \
  -d "{\"office_id\":\"$OFFICE_ID\",\"name\":\"$DEPT_NAME\",\"code\":\"$DEPT_CODE\"}"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating the attach-point department did not return 201"; exit 1; }
DEPT_ID=$(echo "$REQ_BODY" | jq -r .data.id)
echo "2. attach point built: org $ORG_ID -> office $OFFICE_ID -> department $DEPT_ID"
[ -n "$ORG_ID" ] && [ "$ORG_ID" != "null" ] || { echo "FAIL: no org id"; exit 1; }
[ -n "$OFFICE_ID" ] && [ "$OFFICE_ID" != "null" ] || { echo "FAIL: no office id"; exit 1; }
[ -n "$DEPT_ID" ] && [ "$DEPT_ID" != "null" ] || { echo "FAIL: no department id"; exit 1; }

# 3. POST /admin/employees with a full name + an employment block -> 201; the response
#    full_name composes as "Juan Santos Cruz Jr." (the Employee::fullName() accessor).
BASE_RATE_CENTS=10000000   # ₱100,000.00 in centavos
EXPECTED_FULL_NAME="Juan Santos Cruz Jr."
req POST "$API/admin/employees" -H "$A" -H "$J" -d "{
  \"employee_no\":\"$EMP_NO\",
  \"organization_id\":\"$ORG_ID\",
  \"hired_at\":\"2026-07-28\",
  \"first_name\":\"Juan\",
  \"middle_name\":\"Santos\",
  \"last_name\":\"Cruz\",
  \"name_suffix\":\"Jr.\",
  \"employment\":{
    \"effective_from\":\"2026-07-28\",
    \"office_id\":\"$OFFICE_ID\",
    \"department_id\":\"$DEPT_ID\",
    \"employment_type\":\"regular\",
    \"is_art82_exempt\":false,
    \"base_rate_cents\":$BASE_RATE_CENTS
  }
}"
echo "3. POST /admin/employees ($EMP_NO): HTTP $REQ_CODE full_name=$(echo "$REQ_BODY" | jq -r .data.full_name)"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating an employee did not return 201 — body: $REQ_BODY"; exit 1; }
EMP_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$EMP_ID" ] && [ "$EMP_ID" != "null" ] || { echo "FAIL: create employee did not return an id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.full_name)" = "$EXPECTED_FULL_NAME" ] \
  || { echo "FAIL: created employee full_name is not '$EXPECTED_FULL_NAME'"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.employee_no)" = "$EMP_NO" ] \
  || { echo "FAIL: created employee employee_no mismatch"; exit 1; }

# 4. It appears in GET /admin/employees (filtered by ?office) with that full_name and
#    has_user:false — no login provisioned yet.
LIST=$(curl -sf "$API/admin/employees?office=$OFFICE_ID" -H "$A")
LIST_ROW=$(echo "$LIST" | jq --arg id "$EMP_ID" '[.data[] | select(.id == $id)]')
IN_LIST=$(echo "$LIST_ROW" | jq 'length')
[ "$IN_LIST" = "1" ] || { echo "FAIL: the created employee does not appear in GET /admin/employees"; exit 1; }
# `.[0].has_user` (never `// "null"`): jq's alternative operator treats a legitimate
# `false` as empty, so a fallback would misread has_user:false as the string "null".
LIST_NAME=$(echo "$LIST_ROW" | jq -r '.[0].full_name')
LIST_HAS_USER=$(echo "$LIST_ROW" | jq -r '.[0].has_user')
echo "4. GET /admin/employees?office=$OFFICE_ID: present=$IN_LIST (expect 1), full_name=$LIST_NAME, has_user=$LIST_HAS_USER (expect false)"
[ "$LIST_NAME" = "$EXPECTED_FULL_NAME" ] || { echo "FAIL: list full_name is not '$EXPECTED_FULL_NAME'"; exit 1; }
[ "$LIST_HAS_USER" = "false" ] || { echo "FAIL: a freshly created employee already reports has_user:true"; exit 1; }

# 5. GET /admin/employees/{id} shows the name + the resolved current employment
#    (office/department/base_rate) + has_user:false.
req GET "$API/admin/employees/$EMP_ID" -H "$A"
echo "5. GET /admin/employees/$EMP_ID: HTTP $REQ_CODE full_name=$(echo "$REQ_BODY" | jq -r .data.full_name) has_user=$(echo "$REQ_BODY" | jq -r .data.has_user)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: employee detail did not return 200"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.full_name)" = "$EXPECTED_FULL_NAME" ] || { echo "FAIL: detail full_name mismatch"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.has_user)" = "false" ] || { echo "FAIL: detail reports has_user:true before provisioning"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.current_employment.office_id)" = "$OFFICE_ID" ] \
  || { echo "FAIL: detail current_employment.office_id does not match the attach office"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.current_employment.department_id)" = "$DEPT_ID" ] \
  || { echo "FAIL: detail current_employment.department_id does not match the attach department"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.current_employment.base_rate_cents)" = "$BASE_RATE_CENTS" ] \
  || { echo "FAIL: detail current_employment.base_rate_cents is not $BASE_RATE_CENTS"; exit 1; }

# 6. POST /admin/employees/{id}/user (email+password) provisions a login -> 201; re-fetching
#    the detail flips has_user to true.
req POST "$API/admin/employees/$EMP_ID/user" -H "$A" -H "$J" \
  -d "{\"email\":\"$EMP_EMAIL\",\"password\":\"password123\",\"name\":\"$EXPECTED_FULL_NAME\"}"
echo "6. POST /admin/employees/$EMP_ID/user ($EMP_EMAIL): HTTP $REQ_CODE"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: provisioning a login did not return 201 — body: $REQ_BODY"; exit 1; }
req GET "$API/admin/employees/$EMP_ID" -H "$A"
echo "   re-fetch GET /admin/employees/$EMP_ID: has_user=$(echo "$REQ_BODY" | jq -r .data.has_user) (expect true)"
[ "$(echo "$REQ_BODY" | jq -r .data.has_user)" = "true" ] \
  || { echo "FAIL: has_user did not flip to true after provisioning a login"; exit 1; }

# 6b. The provisioned account can actually log in — proving the email/password were wired up.
NEW_TOKEN=$(login "$EMP_EMAIL" password123)
echo "6b. login as the newly provisioned $EMP_EMAIL: token present? $([ -n "$NEW_TOKEN" ] && [ "$NEW_TOKEN" != "null" ] && echo yes || echo no)"
[ -n "$NEW_TOKEN" ] && [ "$NEW_TOKEN" != "null" ] || { echo "FAIL: the newly provisioned account could not log in"; exit 1; }

# 7. PATCH /admin/employees/{id} edits the name (new last_name) -> 200; GET then shows the
#    updated full_name. employee_no is immutable and has no field on this surface.
EXPECTED_RENAMED="Juan Santos Delacruz Jr."
req PATCH "$API/admin/employees/$EMP_ID" -H "$A" -H "$J" \
  -d "{\"first_name\":\"Juan\",\"middle_name\":\"Santos\",\"last_name\":\"Delacruz\",\"name_suffix\":\"Jr.\"}"
echo "7. PATCH /admin/employees/$EMP_ID (last_name Cruz->Delacruz): HTTP $REQ_CODE full_name=$(echo "$REQ_BODY" | jq -r .data.full_name)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: editing an employee name did not return 200 — body: $REQ_BODY"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.full_name)" = "$EXPECTED_RENAMED" ] \
  || { echo "FAIL: PATCH response full_name is not '$EXPECTED_RENAMED'"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.employee_no)" = "$EMP_NO" ] \
  || { echo "FAIL: employee_no changed across a name edit — it must be immutable"; exit 1; }
req GET "$API/admin/employees/$EMP_ID" -H "$A"
echo "   re-fetch GET /admin/employees/$EMP_ID: full_name=$(echo "$REQ_BODY" | jq -r .data.full_name) (expect '$EXPECTED_RENAMED')"
[ "$(echo "$REQ_BODY" | jq -r .data.full_name)" = "$EXPECTED_RENAMED" ] \
  || { echo "FAIL: the name edit did not persist to GET /admin/employees/$EMP_ID"; exit 1; }

# 8. The 403 gate, LIVE. A plain rank-and-file employee (no is_system_admin) is refused 403
#    on POST /admin/employees — the deliberate global-admin exception to 404-not-403 (the
#    profiler is global config with no subject in the URL to scope by).
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
[ -n "$EMP_TOKEN" ] && [ "$EMP_TOKEN" != "null" ] || { echo "FAIL: employee.manila login returned no token — did the seeder run?"; exit 1; }
req POST "$API/admin/employees" -H "$E" -H "$J" -d "{
  \"employee_no\":\"E2E-M8B-should-403-$RUN\",
  \"organization_id\":\"$ORG_ID\",
  \"hired_at\":\"2026-07-28\",
  \"first_name\":\"Should\",
  \"last_name\":\"Fail\"
}"
echo "8. POST /admin/employees as employee.manila (non-admin): HTTP $REQ_CODE (expect 403)"
[ "$REQ_CODE" = "403" ] || { echo "FAIL: a non-admin POST /admin/employees was not refused 403"; exit 1; }

echo "OK: the System Admin onboarded an employee by name ('$EXPECTED_FULL_NAME', 201) with a"
echo "    first employment block; the employee appeared in GET /admin/employees with the"
echo "    composed full_name and has_user:false; the detail showed the resolved current"
echo "    employment (office/department/base_rate); provisioning a login flipped has_user to"
echo "    true and the new account logged in; a name edit (Cruz->Delacruz) returned the"
echo "    updated full_name while employee_no stayed immutable; and a plain employee's POST"
echo "    /admin/employees was refused 403 (the global-admin exception to 404-not-403)"
echo "    — all against the live stack."
