#!/bin/bash
#
# M8a 'org-tree CRUD' — the milestone's end-to-end proof, runnable against a freshly
# seeded stack (`make dev` then `php artisan migrate:fresh --seed`). Mirrors
# scripts/e2e-cutoffs.sh's structure: the same login/envelope-parsing helpers, base URL,
# per-assertion PASS/FAIL, `exit 1` on any mismatch, and `psql` for the checks the API
# has no read surface for yet. It walks the whole system-admin org-tree flow against the
# real API:
#
#   Sofia Reyes (sysadmin@hris.test, the seeded System Admin — the is_system_admin flag,
#   the only actor the /admin/organizations|offices|departments surface admits) builds a
#   fresh three-tier subtree — organization -> office -> department — and proves each
#   tier:
#
#     - CREATE + LIST: each POST returns 201 and the created row then appears in its own
#       GET list (offices filtered by ?organization, departments by ?office).
#
#     - The office `code` global-unique guard: a second office with the SAME code is
#       refused 422 duplicate_office_code (a clean envelope, never a raw unique-violation
#       500).
#
#     - AUDIT: creating the office and the department each wrote an activity_log row
#       (LogsActivity on the org tree, M8a Task 1). There is no activity-log viewer
#       endpoint yet, so this is asserted straight at the DB via psql — log_name
#       'office'/'department', subject_id the created row — the same "go at the database"
#       idiom scripts/e2e-cutoffs.sh uses for the append-only ledger.
#
#     - ARCHIVE-NEVER-DELETE: archiving the department (200) drops it from the default
#       GET /admin/departments but it is still present with ?include_archived=1 (the row
#       is never removed, only stamped archived_at); re-archiving the same row is refused
#       409 already_archived; unarchiving (200) brings it back into the default list. No
#       DELETE route exists on the org tree at all.
#
#   Finally the 403 gate, LIVE: Miguel Santos (employee.manila@hris.test, a plain
#   rank-and-file employee with no is_system_admin flag) is refused 403 on POST
#   /admin/offices. This is the deliberate global-admin exception to the codebase's
#   404-not-403 discipline: the office-scoped HR surface 404s an out-of-scope subject to
#   avoid confirming it exists, but the org tree is global config with no subject to
#   scope by, so its FormRequest authorize() returns the default 403 forbidden.
#
# Rerun-safety: the org tree is archive-never-delete through the API, so this script
# cannot un-create what it made via HTTP. Instead it cleans its OWN prior E2E rows at the
# DB before it starts (departments, then offices, then the organization — child-first for
# the FKs — matched by the 'E2E-M8A' name marker), and every run uses a fresh epoch-suffixed
# office/department `code` so the global office-code unique can never collide with a
# leftover from a prior run. It touches only rows it created; the seeded Manila/Cebu tree
# is never read or written.
#
# API host defaults to the dev port from .env (HRIS_DEV_API_PORT=8001); override with API.
set -euo pipefail

API="${API:-http://127.0.0.1:8001/api/v1}"
J='Content-Type: application/json'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# A per-run suffix so the (globally unique) office code and the department code never
# collide with a row a prior run left behind (archive-never-delete keeps them around).
RUN="$(date +%s)"
ORG_NAME="E2E-M8A Org $RUN"
OFFICE_NAME="E2E-M8A Office $RUN"
OFFICE_CODE="E2EO$RUN"
DEPT_NAME="E2E-M8A Department $RUN"
DEPT_CODE="E2ED$RUN"

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

# 0. Rerun-safety: drop this script's OWN prior E2E rows, child-first for the FKs. Matched
#    by the 'E2E-M8A' name marker so the seeded tree is never touched. A prior run leaves an
#    archived-then-unarchived subtree behind (the API can't delete it); without this the
#    table would slowly accumulate E2E rows on every rerun.
psql_ "delete from departments where name like 'E2E-M8A %'" >/dev/null
psql_ "delete from offices where name like 'E2E-M8A %'" >/dev/null
psql_ "delete from organizations where name like 'E2E-M8A %'" >/dev/null
echo "0. cleared this script's prior E2E-M8A org-tree rows (rerun-safe)"

# 1. Log in as the seeded System Admin (Sofia Reyes) — the is_system_admin flag, the only
#    actor the /admin org-tree surface admits.
ADMIN_TOKEN=$(login sysadmin@hris.test password)
A="Authorization: Bearer $ADMIN_TOKEN"
echo "1. sysadmin@hris.test logged in"
[ -n "$ADMIN_TOKEN" ] && [ "$ADMIN_TOKEN" != "null" ] || { echo "FAIL: sysadmin login returned no token — did the seeder run?"; exit 1; }

# 2. POST /admin/organizations -> 201; it then appears in GET /admin/organizations.
req POST "$API/admin/organizations" -H "$A" -H "$J" \
  -d "{\"name\":\"$ORG_NAME\",\"legal_name\":\"$ORG_NAME, Inc.\",\"timezone\":\"Asia/Manila\"}"
echo "2. POST /admin/organizations (\"$ORG_NAME\"): HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating an organization did not return 201"; exit 1; }
ORG_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$ORG_ID" ] && [ "$ORG_ID" != "null" ] || { echo "FAIL: create organization did not return an id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.name)" = "$ORG_NAME" ] || { echo "FAIL: created organization name mismatch"; exit 1; }

ORG_LIST=$(curl -sf "$API/admin/organizations" -H "$A")
ORG_IN_LIST=$(echo "$ORG_LIST" | jq --arg id "$ORG_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /admin/organizations: contains the new org id? $ORG_IN_LIST (expect 1)"
[ "$ORG_IN_LIST" = "1" ] || { echo "FAIL: the created organization does not appear in GET /admin/organizations"; exit 1; }

# 3. POST /admin/offices under that org -> 201; appears in GET /admin/offices?organization.
req POST "$API/admin/offices" -H "$A" -H "$J" \
  -d "{\"organization_id\":\"$ORG_ID\",\"name\":\"$OFFICE_NAME\",\"code\":\"$OFFICE_CODE\",\"timezone\":\"Asia/Manila\"}"
echo "3. POST /admin/offices (\"$OFFICE_NAME\", code $OFFICE_CODE): HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating an office did not return 201"; exit 1; }
OFFICE_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$OFFICE_ID" ] && [ "$OFFICE_ID" != "null" ] || { echo "FAIL: create office did not return an id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.organization_id)" = "$ORG_ID" ] || { echo "FAIL: created office is not under the new org"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.archived_at)" = "null" ] || { echo "FAIL: a freshly created office is already archived"; exit 1; }

OFFICE_LIST=$(curl -sf "$API/admin/offices?organization=$ORG_ID" -H "$A")
OFFICE_IN_LIST=$(echo "$OFFICE_LIST" | jq --arg id "$OFFICE_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /admin/offices?organization=$ORG_ID: contains the new office id? $OFFICE_IN_LIST (expect 1)"
[ "$OFFICE_IN_LIST" = "1" ] || { echo "FAIL: the created office does not appear in GET /admin/offices"; exit 1; }

# 3b. A duplicate office code (offices.code is GLOBAL unique) is refused 422
#     duplicate_office_code — a clean envelope, never a raw unique-violation 500.
req POST "$API/admin/offices" -H "$A" -H "$J" \
  -d "{\"organization_id\":\"$ORG_ID\",\"name\":\"$OFFICE_NAME dup\",\"code\":\"$OFFICE_CODE\",\"timezone\":\"Asia/Manila\"}"
echo "3b. POST /admin/offices (duplicate code $OFFICE_CODE): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
[ "$REQ_CODE" = "422" ] || { echo "FAIL: a duplicate office code was not refused 422"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "duplicate_office_code" ] \
  || { echo "FAIL: the refusal code is not duplicate_office_code"; exit 1; }

# 4. POST /admin/departments under that office -> 201; appears in GET /admin/departments?office.
req POST "$API/admin/departments" -H "$A" -H "$J" \
  -d "{\"office_id\":\"$OFFICE_ID\",\"name\":\"$DEPT_NAME\",\"code\":\"$DEPT_CODE\"}"
echo "4. POST /admin/departments (\"$DEPT_NAME\", code $DEPT_CODE): HTTP $REQ_CODE $(echo "$REQ_BODY" | jq -c .data)"
[ "$REQ_CODE" = "201" ] || { echo "FAIL: creating a department did not return 201"; exit 1; }
DEPT_ID=$(echo "$REQ_BODY" | jq -r .data.id)
[ -n "$DEPT_ID" ] && [ "$DEPT_ID" != "null" ] || { echo "FAIL: create department did not return an id"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.office_id)" = "$OFFICE_ID" ] || { echo "FAIL: created department is not under the new office"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.archived_at)" = "null" ] || { echo "FAIL: a freshly created department is already archived"; exit 1; }

DEPT_LIST=$(curl -sf "$API/admin/departments?office=$OFFICE_ID" -H "$A")
DEPT_IN_LIST=$(echo "$DEPT_LIST" | jq --arg id "$DEPT_ID" '[.data[] | select(.id == $id)] | length')
echo "   GET /admin/departments?office=$OFFICE_ID: contains the new department id? $DEPT_IN_LIST (expect 1)"
[ "$DEPT_IN_LIST" = "1" ] || { echo "FAIL: the created department does not appear in GET /admin/departments"; exit 1; }

# 5. AUDIT: the office and department creates each wrote an activity_log row (LogsActivity on
#    the org tree). No activity-log viewer endpoint yet, so assert straight at the DB via
#    psql — log_name 'office'/'department', subject_id the created row.
OFFICE_LOGS=$(psql_ "select count(*) from activity_log where log_name = 'office' and subject_id = '$OFFICE_ID'" | tr -d '[:space:]')
DEPT_LOGS=$(psql_ "select count(*) from activity_log where log_name = 'department' and subject_id = '$DEPT_ID'" | tr -d '[:space:]')
echo "5. activity_log rows: office($OFFICE_ID)=$OFFICE_LOGS, department($DEPT_ID)=$DEPT_LOGS (each expect >=1)"
[ "$OFFICE_LOGS" -ge 1 ] || { echo "FAIL: creating the office wrote no activity_log row — LogsActivity is not firing"; exit 1; }
[ "$DEPT_LOGS" -ge 1 ] || { echo "FAIL: creating the department wrote no activity_log row — LogsActivity is not firing"; exit 1; }

# 6. ARCHIVE-NEVER-DELETE. Archive the department: 200, archived_at set. It then DROPS from
#    the default GET /admin/departments but is STILL present with ?include_archived=1 (the
#    row is never deleted, only stamped).
req POST "$API/admin/departments/$DEPT_ID/archive" -H "$A"
echo "6. POST /admin/departments/$DEPT_ID/archive: HTTP $REQ_CODE archived_at=$(echo "$REQ_BODY" | jq -r .data.archived_at)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: archiving a department did not return 200"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.archived_at)" != "null" ] || { echo "FAIL: an archived department has no archived_at"; exit 1; }

DEFAULT_LIST=$(curl -sf "$API/admin/departments?office=$OFFICE_ID" -H "$A")
IN_DEFAULT=$(echo "$DEFAULT_LIST" | jq --arg id "$DEPT_ID" '[.data[] | select(.id == $id)] | length')
ARCHIVED_LIST=$(curl -sf "$API/admin/departments?office=$OFFICE_ID&include_archived=1" -H "$A")
IN_ARCHIVED=$(echo "$ARCHIVED_LIST" | jq --arg id "$DEPT_ID" '[.data[] | select(.id == $id)] | length')
echo "   after archive: default list=$IN_DEFAULT (expect 0), include_archived=1 list=$IN_ARCHIVED (expect 1)"
[ "$IN_DEFAULT" = "0" ] || { echo "FAIL: an archived department still appears in the default list"; exit 1; }
[ "$IN_ARCHIVED" = "1" ] || { echo "FAIL: an archived department is missing from the include_archived list — was the row deleted?"; exit 1; }

# 6b. Re-archiving an already-archived department is refused 409 already_archived.
req POST "$API/admin/departments/$DEPT_ID/archive" -H "$A"
echo "6b. POST /admin/departments/$DEPT_ID/archive (again): HTTP $REQ_CODE code=$(echo "$REQ_BODY" | jq -r .error.code)"
[ "$REQ_CODE" = "409" ] || { echo "FAIL: re-archiving an already-archived department was not refused 409"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .error.code)" = "already_archived" ] \
  || { echo "FAIL: the refusal code is not already_archived"; exit 1; }

# 6c. Unarchive: 200, archived_at cleared, back in the default list.
req POST "$API/admin/departments/$DEPT_ID/unarchive" -H "$A"
echo "6c. POST /admin/departments/$DEPT_ID/unarchive: HTTP $REQ_CODE archived_at=$(echo "$REQ_BODY" | jq -r .data.archived_at)"
[ "$REQ_CODE" = "200" ] || { echo "FAIL: unarchiving a department did not return 200"; exit 1; }
[ "$(echo "$REQ_BODY" | jq -r .data.archived_at)" = "null" ] || { echo "FAIL: an unarchived department still carries archived_at"; exit 1; }

BACK_LIST=$(curl -sf "$API/admin/departments?office=$OFFICE_ID" -H "$A")
BACK_IN_DEFAULT=$(echo "$BACK_LIST" | jq --arg id "$DEPT_ID" '[.data[] | select(.id == $id)] | length')
echo "   after unarchive: default list=$BACK_IN_DEFAULT (expect 1)"
[ "$BACK_IN_DEFAULT" = "1" ] || { echo "FAIL: an unarchived department did not return to the default list"; exit 1; }

# 7. The 403 gate, LIVE. A plain rank-and-file employee (no is_system_admin) is refused 403
#    on POST /admin/offices — the deliberate global-admin exception to the 404-not-403
#    discipline (the org tree is global config with no subject to scope by).
EMP_TOKEN=$(login employee.manila@hris.test password)
E="Authorization: Bearer $EMP_TOKEN"
[ -n "$EMP_TOKEN" ] && [ "$EMP_TOKEN" != "null" ] || { echo "FAIL: employee.manila login returned no token — did the seeder run?"; exit 1; }
req POST "$API/admin/offices" -H "$E" -H "$J" \
  -d "{\"organization_id\":\"$ORG_ID\",\"name\":\"E2E-M8A Office should-403 $RUN\",\"code\":\"E2EX$RUN\",\"timezone\":\"Asia/Manila\"}"
echo "7. POST /admin/offices as employee.manila (non-admin): HTTP $REQ_CODE (expect 403)"
[ "$REQ_CODE" = "403" ] || { echo "FAIL: a non-admin POST /admin/offices was not refused 403"; exit 1; }

echo "OK: the System Admin created an organization -> office -> department (each 201 and"
echo "    present in its own GET list); a duplicate office code was refused 422"
echo "    (duplicate_office_code); the office and department creates each wrote an"
echo "    activity_log row; archiving the department dropped it from the default list"
echo "    while ?include_archived=1 still showed it, re-archiving was refused 409"
echo "    (already_archived), and unarchiving brought it back; and a plain employee's"
echo "    POST /admin/offices was refused 403 (the global-admin exception to 404-not-403)"
echo "    — all against the live stack."
