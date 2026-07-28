# M8a — Organization tree CRUD (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M8a — the first slice of M8 (Admin portal & audit). Slicing:
**M8a org tree CRUD → M8b employee profiler (`<Wizard>`) → M8c roles + `hr_admin_offices` +
activity-log viewer.**
**Depends on:** M2 (the `organizations`/`offices`/`departments` tables, `is_system_admin`, the
`/admin/*` route group + the `is_system_admin` FormRequest gate, the pay-rules admin screen as
the frontend mirror).

## Goal

A system admin builds the organization tree — organizations, offices, departments — entirely
through the UI: create, edit, and archive (never delete). This is the foundation slice of the
admin portal; the employee profiler (M8b) and role/scope management (M8c) attach to the offices
and departments this creates. It delivers the org-tree portion of M8's done-when ("a company
can be configured from an empty database entirely through the UI").

## Decisions (from brainstorm)

1. **Slicing:** M8a (org tree CRUD) → M8b (employee profiler) → M8c (roles + `hr_admin_offices`
   + audit viewer).
2. **Archive, never delete:** a nullable `archived_at timestamptz` on `offices` and
   `departments`. Archived rows drop out of active lists (a "show archived" toggle reveals them)
   but are fully retained — no cascade, no data loss; un-archive nulls it. Organizations are
   **create + edit only** (a company's org isn't "closed" the way an office is; org-archive is a
   trivial later add). **No `DELETE` route is added anywhere in M8a.**
3. **System-admin only:** the org tree is global structural config. Every M8a endpoint's
   FormRequest `authorize()` returns `is_system_admin` → **403** for a non-admin (not the
   404-not-403 discipline the office-scoped HR endpoints use — there is no office to scope by and
   nothing to enumerate at the global admin level; identical to the existing `/admin/employees`
   and `/admin/pay-rules` gates).

## Data model

House rules: string columns + backed enums + CHECK where an enum applies (none new here),
`timestamptz`, uuid v7 PKs (already on all three tables from M2).

### `offices` (modify), `departments` (modify)
- Add `archived_at timestamptz NULL` to each. Null = active; a timestamp = archived (and when).
  Active lists filter `whereNull('archived_at')` by default.

### `organizations` — no schema change
Create + edit only (name, legal_name, tin, timezone already exist). No `archived_at`.

### `LogsActivity`
Add the `LogsActivity` trait (with a `getActivitylogOptions()` logging the meaningful columns,
matching the `LeaveType`/`CutoffPeriod`/`Holiday` idiom) to `Organization`, `Office`, and
`Department` — none have it today. Every create/edit/archive/un-archive then lands in
`activity_log`, which M8c's viewer reads. (This is also why M8a is the right place to add it:
the audit trail must exist before the tree is mutated through the UI.)

## Backend

Action-class architecture (each action `final`, owns its transaction, takes an Input DTO, returns
the domain model, knows nothing about HTTP). All gated by `is_system_admin` via each FormRequest's
`authorize()` (mirror `CreateEmployeeRequest`: `return (bool) $this->user()?->is_system_admin;`).

### Actions
- **Organization:** `CreateOrganization(name, legal_name?, tin?, timezone)`,
  `UpdateOrganization(organization, …)`.
- **Office:** `CreateOffice(organization_id, name, code, timezone, geofence_lat?, geofence_lng?,
  geofence_radius_m?, ip_allowlist?, default_shift_template_id?)`, `UpdateOffice(office, …)`,
  `ArchiveOffice(office)` (sets `archived_at = now()`), `UnarchiveOffice(office)` (nulls it).
- **Department:** `CreateDepartment(office_id, name, code)`, `UpdateDepartment(department, …)`,
  `ArchiveDepartment(department)`, `UnarchiveDepartment(department)`.

Guards: office `code` is unique (DB unique + a domain check surfacing a clean 422/409, not a raw
500); department `code` is unique within its office (confirm the existing constraint's scope and
match it). Archiving an already-archived office/department is a no-op-or-refuse — pick refuse with
a domain error (`AlreadyArchived`, 409) for a clear signal; un-archiving an active one likewise
(`NotArchived`, 409). A `CreateOffice` referencing a nonexistent/foreign organization, or a
`CreateDepartment` referencing a nonexistent office, validates shape-only in the FormRequest (no
`exists:`) and the action/controller surfaces a clean error — but since this is a system-admin
global surface (not office-scoped), a bad id is a 422 domain/validation error, **not** the
404-not-403 existence-hiding used on HR endpoints (a system admin is allowed to know what exists).

Archiving is **soft and non-cascading**: an archived office keeps its departments, employees,
schedules, summaries, and cutoff history untouched — it is a "this office is closed" marker, not a
teardown. (An archived office's departments/employees simply inherit the closed context; managing
their lifecycle is M8b/later, not M8a.)

### Routes (all under `Route::prefix('admin')`, `auth:sanctum`, `is_system_admin` via FormRequest)
- `GET /admin/organizations`, `POST /admin/organizations`, `PATCH /admin/organizations/{organization}`.
- `GET /admin/offices` (default active-only; `?include_archived=1` includes archived; optional
  `?organization=` filter), `POST /admin/offices`, `PATCH /admin/offices/{office}`,
  `POST /admin/offices/{office}/archive`, `POST /admin/offices/{office}/unarchive`.
- `GET /admin/departments` (default active-only; `?include_archived=1`; optional `?office=` filter),
  `POST /admin/departments`, `PATCH /admin/departments/{department}`,
  `POST /admin/departments/{department}/archive`, `POST /admin/departments/{department}/unarchive`.
- **No `DELETE` route is added.** (A pre-existing `DELETE /admin/pay-rules/{payRule}` exists from
  M4 for immutable pay-rule versions — that is a separate, deliberate case and is untouched; M8a
  adds none.)

`OrganizationResource` / `OfficeResource` / `DepartmentResource` serialize the models (an
`OfficeResource` already exists? — confirm and extend, else create; include `archived_at` on
office/department resources so the frontend can badge archived rows).

## Frontend

Carbon, React-Query through `keys.ts`, mirroring the pay-rules admin screen
(`/admin/pay-rules`) — the only `/admin/*` screen today.

- **`/admin/organizations`** — list organizations; create/edit form (name, legal_name, tin,
  timezone).
- **`/admin/offices`** — list offices (optionally filtered by organization); create/edit form
  (name, code, timezone, geofence lat/lng/radius, ip_allowlist, default shift template); an
  **archive/un-archive** action per row; a **"show archived"** toggle (archived rows badged).
- **`/admin/departments`** — list departments (optionally filtered by office); create/edit;
  archive/un-archive; show-archived toggle.
- **SideNav gains an "Admin" section**, visible only when the session is a system admin (mirror
  how the "Office" section is gated on HR offices — here the gate is `is_system_admin` on the
  session). Add the org/offices/departments links under it.
- `keys.admin`: `organizations()`, `offices(filter)`, `departments(filter)`; the mutation hooks
  (`useCreateOffice`, `useArchiveOffice`, …) invalidate the relevant list key.
- Session shape: confirm the session (`useSession`) already carries `is_system_admin`; if not,
  surface it (the SideNav gate needs it).

## Error handling
Envelope unchanged. Non-admin → **403** (FormRequest `authorize()` false — not 404). A duplicate
office/department `code` → 422/409 domain error (not a raw unique-violation 500). Archiving an
already-archived / un-archiving an active row → 409 (`already_archived` / `not_archived`). A
malformed create body → 400 `validation_failed` (FormRequest). Bad FK id on a system-admin create
→ 422 (a system admin may know what exists; no 404-hiding here).

## Testing
- **Backend (real Postgres):**
  - Schema: `offices.archived_at` / `departments.archived_at` exist, nullable; `LogsActivity`
    is wired on all three (a create writes an activity_log row).
  - Actions: create/update org, office (unique code enforced → clean error on collision),
    department (code unique within office); archive sets `archived_at`, un-archive nulls it;
    archiving an already-archived row 409s; each mutation is audited (an activity_log entry with
    the actor).
  - The `is_system_admin` gate: a non-admin caller gets 403 on every create/update/archive
    endpoint (mirror how the `/admin/pay-rules` or `/admin/employees` tests assert the 403).
  - List scoping: `GET /admin/offices` excludes archived by default and includes them with
    `?include_archived=1`; the `?organization=` / `?office=` filters work.
- **Frontend:** the three admin screens (create/edit forms, the archive/un-archive action, the
  show-archived toggle); the SideNav Admin section renders only for a system admin.
- **`scripts/e2e-admin-org.sh`:** live, as a system admin — create an organization → create an
  office under it → create a department under the office → each appears in its `GET` list and in
  the activity log → archive the department (drops from the default list, present with
  `?include_archived=1`) → un-archive it (back) → a non-admin caller is `403` on a create.

## Done when
A system admin creates an organization, an office, and a department entirely through the UI;
archives and un-archives an office/department (archived rows leave the active list but are
retained); every step is written to the activity log; and a non-admin is refused with 403.
Backend + frontend suites green; `scripts/e2e-admin-org.sh` runs live, exit 0.

## Explicitly deferred (with the slice/milestone that owns it)
- **The employee profiler `<Wizard>`** and the employee **name/profile fields** (the identifier
  M7b fell back to `employee_no` for) → **M8b**.
- **Role management, `hr_admin_offices` assignment, and the activity-log VIEWER** (M8a only
  *writes* to the log via `LogsActivity`; M8c builds the filterable read UI) → **M8c**.
- **First-`is_system_admin` bootstrap** in a truly empty DB (a seed/console concern — M8a assumes
  a system admin exists and builds the tree from there) → seeding / M9.
- **Organization archive** (org is edit-only in M8a) and any **office-close cascade semantics**
  (archiving stays a soft, non-cascading marker) → later, if needed.
