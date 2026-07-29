#!/bin/bash
#
# M8c 'roles, scope & audit' — the milestone's (and M8's) end-to-end proof, runnable
# against a freshly seeded stack (`make dev` then `php artisan migrate:fresh --seed`).
# Mirrors scripts/e2e-admin-org.sh's structure: the same login/envelope-parsing helpers,
# base URL, per-assertion PASS/FAIL, `exit 1` on any mismatch, and `psql` for the one
# check worth confirming straight at the DB. It walks the whole system-admin HR-Admin
# grant flow plus the read-only audit viewer against the real API:
#
#   Sofia Reyes (sysadmin@hris.test, the seeded System Admin — the is_system_admin flag,
#   the only actor the /admin surface admits) grants and revokes office-admin access to a
#   real employee-with-login, and browses the filterable activity log:
#
#     - GRANT: POST /admin/employees/{id}/hr-offices {office_ids:[<office>]} returns 200,
#       and GET /admin/employees/{id} then shows hr_admin_office_ids containing that office
#       AND roles containing 'HR Admin'. The pivot and the role are coupled — set together
#       by the SetHrAdminOffices action in one write, never one without the other.
#
#     - AUDIT (the grant): that write logged a `hr_admin_offices_set` row, and the viewer
#       surfaces it — GET /admin/activity (default filter) returns a row whose description
#       is 'hr_admin_offices_set'. Cross-checked straight at the DB via psql on activity_log
#       (the manual activity()->log() lands in log_name 'default').
#
#     - AUDIT (the trail everything writes): the seed leaves activity_log empty (seeding
#       does not log), so this script first creates a throwaway office — a real M8a action
#       that writes a log_name 'office' row — and proves GET /admin/activity?log_name=office
#       returns >=1. That is the point of the viewer: it is a window over the one Spatie
#       activity_log that every LogsActivity model (offices, departments, organizations,
#       employees) already writes to.
#
#     - REVOKE: POST .../hr-offices {office_ids:[]} returns 200, and the detail then shows
#       an EMPTY hr_admin_office_ids AND roles no longer containing 'HR Admin' — office_ids=[]
#       revokes HR-Admin entirely rather than leaving a dangling role or pivot. This also
#       restores the subject to its original state, keeping the script rerun-safe.
#
#     - The login-less guard, LIVE: an employee with no user_id (a punch-only worker) is
#       refused 422 employee_has_no_login — HR-Admin access is granted to a login, not an
#       employee record, so there is no User row to attach the pivot/role to.
#
#   Finally the 403 gate, LIVE: Miguel Santos (employee.manila@hris.test, a plain
#   rank-and-file employee with no is_system_admin flag) is refused 403 on GET
#   /admin/activity — the audit viewer is global config with no subject to scope by, the
#   deliberate global-admin exception to the codebase's 404-not-403 discipline.
#
# Rerun-safety: the grant subject is chosen dynamically as the first employee-with-login
# whose roles do NOT already include 'HR Admin', and the run ends by revoking, so the
# subject is left exactly as it was found. The one throwaway office it creates is
# archive-never-delete through the API, so this script cleans its OWN prior 'E2E-M8C'
# offices (and their activity_log rows) at the DB before it starts, and uses a fresh
# epoch-suffixed office `code` so the global office-code unique can never collide with a
# leftover. It touches only rows it created; the seeded tree is otherwise read, not written.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

RUN="$(date +%s)"
OFFICE_NAME="E2E-M8C Office $RUN"
OFFICE_CODE="E2EC$RUN"

login() {   # $1 email, $2 password -> prints the bearer token
  curl -sf -X POST "$API/login" -H "$J" -d "{\"email\":\"$1\",\"password\":\"$2\"}" \
    | jq -r .data.token
}

psql_() {   # $1 sql -> prints the -tAc result, against the compose db
  docker compose -f "$REPO_ROOT/compose.dev.yml" exec -T db psql -U hris -d hris -tAc "$1"
}

# A request that CAPTURES both the HTTP status and the body — assertions here check BOTH
# the status code AND the envelope (`.data` on success, `.error.code` on failure), so
# plain `curl -sf` (which discards the body on a 4xx) will not do. Sets REQ_CODE/REQ_BODY.
req() {   # $1 METHOD, $2 URL, then extra curl args (headers/data)
  local resp
  resp=$(curl -s -w $'\n%{http_code}' -X "$1" "$2" "${@:3}")
  REQ_CODE="${resp##*$'\n'}"
  REQ_BODY="${resp%$'\n'*}"
}

pass() { echo "   PASS: $1"; }
fail() { echo "   FAIL: $1"; exit 1; }

# 0. Rerun-safety: drop this script's OWN prior E2E-M8C offices and their activity_log rows.
#    Matched by the 'E2E-M8C' name marker so the seeded tree is never touched.
psql_ "delete from activity_log where subject_type = 'App\\Models\\Office' and subject_id in (select id from offices where name like 'E2E-M8C %')" >/dev/null
psql_ "delete from offices where name like 'E2E-M8C %'" >/dev/null
echo "0. cleared this script's prior E2E-M8C offices + their activity_log rows (rerun-safe)"

# 1. Log in as the seeded System Admin (Sofia Reyes) — the only actor the /admin surface admits.
ADMIN_TOKEN=$(login sysadmin@hris.test password)
A="Authorization: Bearer $ADMIN_TOKEN"
echo "1. sysadmin@hris.test logged in"
[ -n "$ADMIN_TOKEN" ] && [ "$ADMIN_TOKEN" != "null" ] || fail "sysadmin login returned no token — did the seeder run?"
pass "system-admin token acquired"

# 1a. A throwaway office (a real M8a action) so the viewer has a known log_name 'office' row
#     to surface later. Its parent is the org the first seeded office belongs to.
ORG_ID=$(curl -sf "$API/admin/offices" -H "$A" | jq -r '.data[0].organization_id')
[ -n "$ORG_ID" ] && [ "$ORG_ID" != "null" ] || fail "could not read a seeded organization_id — is the stack seeded?"
req POST "$API/admin/offices" -H "$A" -H "$J" \
  -d "{\"organization_id\":\"$ORG_ID\",\"name\":\"$OFFICE_NAME\",\"code\":\"$OFFICE_CODE\",\"timezone\":\"Asia/Manila\"}"
echo "1a. POST /admin/offices (\"$OFFICE_NAME\", code $OFFICE_CODE): HTTP $REQ_CODE"
[ "$REQ_CODE" = "201" ] || fail "creating the throwaway office did not return 201 (body: $REQ_BODY)"
OFFICE_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$OFFICE_ID" ] && [ "$OFFICE_ID" != "null" ] || fail "create office did not return an id"
pass "throwaway office created: $OFFICE_ID"

# 1b. Pick the grant subject: the first employee WITH a login whose roles do NOT already
#     include 'HR Admin' (a clean before-state, and revoking at the end restores it).
EMP_ID=""
for id in $(curl -sf "$API/admin/employees" -H "$A" | jq -r '.data[] | select(.has_user == true) | .id'); do
  ROLES=$(curl -sf "$API/admin/employees/$id" -H "$A" | jq -c '.data.roles')
  if ! echo "$ROLES" | jq -e 'index("HR Admin")' >/dev/null; then EMP_ID="$id"; break; fi
done
[ -n "$EMP_ID" ] || fail "no employee-with-login lacking the HR Admin role was found to grant to"
EMP_NAME=$(curl -sf "$API/admin/employees/$EMP_ID" -H "$A" | jq -r '.data.full_name')
echo "1b. grant subject: $EMP_NAME ($EMP_ID) — has a login, is not yet HR Admin"
pass "clean grant subject chosen"

# 2. GRANT: POST .../hr-offices {office_ids:[<office>]} -> 200; the detail then shows the
#    office in hr_admin_office_ids AND 'HR Admin' in roles (pivot + role, coupled).
req POST "$API/admin/employees/$EMP_ID/hr-offices" -H "$A" -H "$J" \
  -d "{\"office_ids\":[\"$OFFICE_ID\"]}"
echo "2. POST /admin/employees/$EMP_ID/hr-offices {office_ids:[$OFFICE_ID]}: HTTP $REQ_CODE"
[ "$REQ_CODE" = "200" ] || fail "granting HR-Admin did not return 200 (body: $REQ_BODY)"
pass "grant returned 200"

DETAIL=$(curl -sf "$API/admin/employees/$EMP_ID" -H "$A")
HAS_OFFICE=$(echo "$DETAIL" | jq --arg o "$OFFICE_ID" '.data.hr_admin_office_ids | index($o) != null')
HAS_ROLE=$(echo "$DETAIL" | jq '.data.roles | index("HR Admin") != null')
echo "   GET detail: hr_admin_office_ids has office? $HAS_OFFICE  roles has 'HR Admin'? $HAS_ROLE (expect true/true)"
[ "$HAS_OFFICE" = "true" ] || fail "granted office is missing from hr_admin_office_ids"
pass "hr_admin_office_ids includes the granted office"
[ "$HAS_ROLE" = "true" ] || fail "'HR Admin' role is missing from roles after the grant"
pass "roles includes 'HR Admin' (pivot and role set together)"

# 3. AUDIT. (a) the grant wrote a 'hr_admin_offices_set' row the viewer surfaces; (b) the
#    same row is visible straight at the DB; (c) the viewer surfaces the wider audit trail
#    every LogsActivity model writes — filter ?log_name=office returns the office we made.
SET_HITS=$(curl -sf "$API/admin/activity" -H "$A" | jq '[.data[] | select(.description == "hr_admin_offices_set")] | length')
echo "3a. GET /admin/activity: rows with description 'hr_admin_offices_set' = $SET_HITS (expect >=1)"
[ "$SET_HITS" -ge 1 ] || fail "the audit viewer does not surface the hr_admin_offices_set event"
pass "viewer surfaces the hr_admin_offices_set event"

DB_SET=$(psql_ "select count(*) from activity_log where description = 'hr_admin_offices_set'" | tr -d '[:space:]')
echo "3b. activity_log rows with description 'hr_admin_offices_set' (psql) = $DB_SET (expect >=1)"
[ "$DB_SET" -ge 1 ] || fail "no hr_admin_offices_set row in activity_log — the grant did not log"
pass "activity_log carries the hr_admin_offices_set row"

OFFICE_ACTIVITY=$(curl -sf "$API/admin/activity?log_name=office" -H "$A")
OFFICE_TOTAL=$(echo "$OFFICE_ACTIVITY" | jq '.meta.total')
OFFICE_ALL_OFFICE=$(echo "$OFFICE_ACTIVITY" | jq '[.data[] | select(.log_name != "office")] | length')
echo "3c. GET /admin/activity?log_name=office: meta.total = $OFFICE_TOTAL (expect >=1), non-office rows in page = $OFFICE_ALL_OFFICE (expect 0)"
[ "$OFFICE_TOTAL" -ge 1 ] || fail "the viewer surfaces no log_name=office rows — the audit trail is not visible"
pass "viewer surfaces the log_name=office audit trail (M8a action)"
[ "$OFFICE_ALL_OFFICE" = "0" ] || fail "the log_name=office filter leaked a non-office row"
pass "the log_name filter is honoured (page holds office rows only)"

# 4. REVOKE: POST .../hr-offices {office_ids:[]} -> 200; the detail then shows an EMPTY
#    hr_admin_office_ids AND roles WITHOUT 'HR Admin' (revoke clears both, no dangling half).
req POST "$API/admin/employees/$EMP_ID/hr-offices" -H "$A" -H "$J" -d '{"office_ids":[]}'
echo "4. POST /admin/employees/$EMP_ID/hr-offices {office_ids:[]}: HTTP $REQ_CODE"
[ "$REQ_CODE" = "200" ] || fail "revoking HR-Admin did not return 200 (body: $REQ_BODY)"
pass "revoke returned 200"

DETAIL=$(curl -sf "$API/admin/employees/$EMP_ID" -H "$A")
OFFICE_COUNT=$(echo "$DETAIL" | jq '.data.hr_admin_office_ids | length')
STILL_ROLE=$(echo "$DETAIL" | jq '.data.roles | index("HR Admin") != null')
echo "   GET detail: hr_admin_office_ids length = $OFFICE_COUNT (expect 0)  roles still has 'HR Admin'? $STILL_ROLE (expect false)"
[ "$OFFICE_COUNT" = "0" ] || fail "hr_admin_office_ids is not empty after revoke"
pass "hr_admin_office_ids is empty after revoke"
[ "$STILL_ROLE" = "false" ] || fail "'HR Admin' role still present after revoke — the role was left dangling"
pass "roles no longer includes 'HR Admin' (role and pivot revoked together)"

# 5. The login-less guard, LIVE: an employee with no user_id is refused 422 employee_has_no_login.
LL_ID=$(curl -sf "$API/admin/employees" -H "$A" | jq -r '.data[] | select(.has_user == false) | .id' | head -1)
[ -n "$LL_ID" ] || fail "no login-less employee found in the seed to test the guard against"
req POST "$API/admin/employees/$LL_ID/hr-offices" -H "$A" -H "$J" -d "{\"office_ids\":[\"$OFFICE_ID\"]}"
echo "5. POST .../hr-offices for a login-less employee ($LL_ID): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
[ "$REQ_CODE" = "422" ] || fail "granting to a login-less employee was not refused 422"
pass "login-less grant refused 422"
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "employee_has_no_login" ] || fail "the refusal code is not employee_has_no_login"
pass "refusal code is employee_has_no_login"

# 6. The 403 gate, LIVE: a plain rank-and-file employee (no is_system_admin) is refused 403
#    on GET /admin/activity — the global-admin exception to the 404-not-403 discipline.
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
[ -n "$EMP_TOKEN" ] && [ "$EMP_TOKEN" != "null" ] || fail "employee.manila login returned no token — did the seeder run?"
req GET "$API/admin/activity" -H "$E"
echo "6. GET /admin/activity as employee.manila (non-admin): HTTP $REQ_CODE (expect 403)"
[ "$REQ_CODE" = "403" ] || fail "a non-admin GET /admin/activity was not refused 403"
pass "non-admin refused 403 on the audit viewer"

echo
echo "OK: the System Admin granted HR-Admin access to an employee-with-login (200; the"
echo "    detail then showed the office in hr_admin_office_ids AND 'HR Admin' in roles),"
echo "    the audit viewer surfaced both the hr_admin_offices_set event and the wider"
echo "    log_name=office trail (confirmed at the DB), revoking cleared both the pivot and"
echo "    the role together (restoring the subject), a login-less employee was refused 422"
echo "    (employee_has_no_login), and a plain employee's GET /admin/activity was refused"
echo "    403 (the global-admin exception to 404-not-403) — all against the live stack."
