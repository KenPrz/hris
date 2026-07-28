# M8c — Roles, scope & audit viewer (design)

**Status:** decisions made autonomously (user asleep, "proceed as one task") — REVIEW WHEN AWAKE.
**Milestone:** M8c — final slice of M8. Slicing: M8a org tree → M8b profiler → **M8c roles +
`hr_admin_offices` + activity-log viewer**. Completes M8.
**Depends on:** M8a (`/admin/*` `is_system_admin` gate, org tree), M8b (employee detail screen +
`GET /admin/employees/{id}`), M2 (spatie `HR Admin` role, `hr_admin_offices` pivot, `OfficeScope`,
`LogsActivity` on many models — the audit trail M8a/M8b extended to the org tree + employees).

## Goal

Close out the admin portal: (1) a filterable **activity-log viewer** so "the audit log shows
every step" (the M8 done-when); (2) **HR-admin access management** — grant/revoke a user the
`HR Admin` role + which offices they administer (`hr_admin_offices`), so a company's HR structure
is configurable through the UI. All `is_system_admin`-gated.

## Decisions (made autonomously — flagged for review)

1. **HR-admin grant couples role + scope into one action.** `SetHrAdminOffices(user, officeIds[])`
   syncs the user's `hr_admin_offices` to the given set AND ensures the `HR Admin` role is assigned
   when the set is non-empty (removed when empty). Rationale: being an office HR admin *is* the
   role (the verbs) + the pivot (the scope) together — the seeder grants both in lockstep, and
   `OfficeScope` + the role's permissions are both required. Managing them as one "these are the
   offices this user administers" control is simpler and matches the real concept. *(If you'd
   rather manage the role and the office-pivot separately, that's the reversible call I made.)*
   Spatie `teams` is **false**, so role assignment is plain `assignRole`/`removeRole` — no team-key.
2. **Surfaced on the employee detail** (M8b's `/admin/employees/{id}`), not a separate users
   screen: an employee with a login (`has_user`) gets an "Office admin access" panel (a
   multi-select of offices). An employee with no user can't be an HR admin (422). Reuses M8b's
   detail page; no new list-of-users surface needed.
3. **Audit viewer is read-only, paginated, filterable** by `log_name`, `event`, `subject_type`,
   `causer_id`, and a `from`/`to` date range. No mutation. `is_system_admin` only.
4. **Custom-role CRUD is out of scope** — there is one role (`HR Admin`); adding roles is a
   code+permission change (`RbacSeeder`), not a runtime admin task (YAGNI). Deferred.

## Data model
No schema changes. Uses the existing spatie tables (`roles`, `model_has_roles`), the
`hr_admin_offices` pivot, and the Spatie `activity_log` table (standard columns: `id`, `log_name`,
`description`, `subject_type`/`subject_id`, `causer_type`/`causer_id`, `properties` jsonb, `event`,
`created_at`).

## Backend

### Audit viewer
- **`GET /admin/activity`** (`ListActivityController`, final invokable; `ListActivityRequest`
  `authorize()=is_system_admin`, shape-only filter validation). Query params (all optional):
  `log_name`, `event`, `subject_type`, `causer_id` (uuid), `from`/`to` (dates), `page` (paginated,
  e.g. 50/page, newest first). Query the Spatie `Activity` model
  (`Spatie\Activitylog\Models\Activity`) with `->when(...)` filters, `latest()`, `paginate(50)`.
- **`ActivityResource`**: `{ id, log_name, description, event, subject_type, subject_id, causer_id,
  properties, created_at }`. The paginated response carries the standard Laravel pagination meta
  (or a simple `{ data, meta: {current_page, last_page, total} }` — match the codebase's existing
  pagination shape if one exists; else Laravel's default resource-collection pagination).

### HR-admin access
- **`SetHrAdminOffices`** action (final, own transaction): `execute(SetHrAdminOfficesInput{userId,
  officeIds[], actorId}): User` — `$user->hrAdminOffices()->sync($officeIds)`; if `$officeIds` is
  non-empty `$user->assignRole('HR Admin')` else `$user->removeRole('HR Admin')`. Audited (log the
  change — either via an explicit `activity()->performedOn($user)->withProperties(['office_ids' =>
  ...])->log('hr_admin_offices_set')`, matching how M7a's ReopenCutoff logs a manual event, so the
  grant/revoke shows in the audit viewer).
- **`POST /admin/employees/{employee}/hr-offices`** (`SetHrAdminOfficesController`; a FormRequest
  `authorize()=is_system_admin`, body `{ office_ids: string[] }`): resolves the employee's user
  (`$employee->user_id`) — if null, `422 employee_has_no_login` (can't grant HR admin to a
  login-less employee); validates the office ids are real (bad id → 422 `invalid_reference`,
  reusing M8a's exception); calls `SetHrAdminOffices`. Returns the updated employee detail.
- **`EmployeeDetailResource`** (M8b) — extend to include `hr_admin_office_ids: string[]` (the
  user's current `hr_admin_offices`, empty if no user) and `roles: string[]` (the user's role
  names), so the detail screen can show/prefill the access panel.

All `is_system_admin`-gated (403). No new DELETE routes.

## Frontend
- **`/admin/activity`** viewer screen: filter controls (log_name select, event select,
  subject_type, causer, date range) + a paginated table (time, actor, action, subject, a
  properties peek). `api.admin.activity.list(filters)`, `keys.admin.activity(filters)`, a query
  hook. SideNav Admin gains **Activity log**.
- **Employee-detail "Office admin access" panel** (M8b's `/admin/employees/[employee]`): shown when
  `has_user`; a multi-select of offices (from `useAdminOffices`) prefilled from
  `hr_admin_office_ids`; save → `api.admin.employees.setHrOffices(id, {office_ids})`; shows the
  current `roles`. A login-less employee shows a note instead.
- Carbon, `var(--*)` tokens, `is_system_admin`-gated (Admin section already is).

## Error handling
Envelope unchanged. `is_system_admin` → 403; a login-less employee's hr-offices set → 422
`employee_has_no_login`; a bad office id → 422 `invalid_reference`; filter validation → 400.

## Testing
- Backend: `GET /admin/activity` returns rows filtered by log_name/event/subject_type/causer/date,
  paginated, newest-first, `is_system_admin` 403 for non-admin; `SetHrAdminOffices` syncs the pivot
  + assigns the role when non-empty / removes it when empty, audited; `POST .../hr-offices` on a
  login-less employee → 422; bad office id → 422; the employee detail resource carries
  `hr_admin_office_ids` + `roles`.
- Frontend: the activity viewer (filters drive the query; pagination); the employee-detail access
  panel (prefill from hr_admin_office_ids, save calls setHrOffices, login-less shows the note).
- `scripts/e2e-admin-roles-audit.sh`: as sysadmin — grant an employee-with-login HR-admin over an
  office (`POST .../hr-offices`) → the employee detail shows the office + the HR Admin role → the
  grant appears in `GET /admin/activity` → revoke (empty office set) removes the role → filter the
  activity log by log_name and confirm a known M8a/M8b action shows → a non-admin is 403.

## Done when
A system admin grants/revokes an employee HR-admin access over specific offices through the UI, and
browses a filterable activity log showing every configuration step — all `is_system_admin`-gated.
Backend + frontend suites green; `scripts/e2e-admin-roles-audit.sh` exit 0. **With M8c merged, M8
is complete.**

## Deferred
Custom-role CRUD; per-permission editing; manager (`reports_to`) org-chart management; audit-log
export/retention policy → later. The name-in-queues/export polish (M8b deferral) remains open.
