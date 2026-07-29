# M8b — Employee profiler (design)

**Status:** decisions made autonomously (user asleep, authorized "proceed as one task") — REVIEW
WHEN AWAKE; the design choices are called out below so you can adjust.
**Milestone:** M8b — second slice of M8. Slicing: M8a org tree (done) → **M8b employee profiler**
→ M8c roles + `hr_admin_offices` + audit viewer.
**Depends on:** M8a (the `/admin/*` `is_system_admin` gate, org tree to attach employees to),
M2 (`employees`, `employment_records`, `CreateEmployee`/`RecordEmploymentChange`/`ProvisionUser`).

## Goal

Give every employee a **name** and a UI to onboard/edit them. Today the person's name lives
only on the `User` (login) — a punch-only worker has no name, only `employee_no` (the gap M7b
and M8a both fell back to). M8b adds name fields to the employee itself and builds the multi-step
employee profiler (`<Wizard>`) over the existing onboarding actions, plus a list + detail/edit
surface. All `is_system_admin`-gated (the M8a discipline).

## Decisions (made autonomously — flagged for review)

1. **Name structure = Philippine standard:** `first_name` (required), `middle_name` (nullable —
   commonly the mother's maiden surname), `last_name` (required), `name_suffix` (nullable —
   Jr./Sr./III). A computed `full_name` for display. *(If you'd rather a single `full_name`
   field, this is the reversible call I made — separate fields match PH payroll/gov-form needs.)*
2. **Name lives on `employees`** (not a separate profile table) — every employee, punch-only or
   not, has one. The `User.name` (login display) stays; on provisioning a login the wizard
   defaults the user name to the employee's full name.
3. **Scope kept tight for this slice:** name + the onboarding wizard + list + detail/edit. **Gov
   IDs (TIN/SSS/PhilHealth/PagIBIG), demographics (birthdate/sex/civil status), contact/address
   are DEFERRED** (a bigger profile form, easily added later) — noted so the slice stays
   reviewable overnight. Employee **separation** (`separated_at` exists from M2) is also deferred
   to a later slice.
4. **`employee_no` stays immutable** (M2 rule); name IS editable via a new `UpdateEmployee`.

## Data model
- `employees` (modify): add `first_name text NOT NULL`, `middle_name text NULL`,
  `last_name text NOT NULL`, `name_suffix text NULL`. (Existing rows: the migration must
  backfill — but there are no production rows yet, and the seeder creates employees; the
  migration adds the columns and the seeder/factory supply names. For safety the migration adds
  them nullable-then-not-null-with-default won't work for text names, so: add `first_name`/
  `last_name` as `NOT NULL DEFAULT ''` then the seeder/factory populate real names; OR add
  nullable and enforce in the app. **Chosen:** add `NOT NULL DEFAULT ''` for first/last, nullable
  for middle/suffix — the DEFAULT '' satisfies any existing row; the FormRequest requires
  non-empty on create.) `LogsActivity` on Employee already? — add it if absent (name edits should
  audit).
- A `full_name` accessor on `Employee`: `trim("{$first} {$middle} {$last} {$suffix}")` (display
  order first→last; a `formal_name` "Last, First Middle" accessor optional).

## Backend
- **`CreateEmployee`** + `CreateEmployeeInput` + `CreateEmployeeRequest`: add the four name fields
  (first/last required, middle/suffix nullable). `EmployeeResource`: add `first_name`,
  `middle_name`, `last_name`, `name_suffix`, `full_name`.
- **`UpdateEmployee`** (new) + `UpdateEmployeeInput` + `UpdateEmployeeRequest` +
  `PATCH /admin/employees/{employee}`: edit the name fields (NOT employee_no). Audited.
- **`GET /admin/employees`** (new `ListController`): list employees — `id, employee_no,
  full_name, current_office_id, current_department_id, has_user` — `is_system_admin` gated,
  optional `?office=` filter. **`GET /admin/employees/{employee}`** (new `ShowController`):
  the employee + name + current employment (office/dept/type/art82/base_rate) + `has_user`
  (whether a login exists) + employment history summary.
- The existing `POST /admin/employees` (create), `POST /admin/employees/{employee}/user`
  (provision login), `POST /admin/employees/{employee}/employment` (record change) are reused by
  the wizard — unchanged except CreateEmployee now carries the name.
- All gated `is_system_admin` (403), bad FK id → 422 (the M8a discipline; reuse `InvalidReference`).

## Frontend
- **`<Wizard>`** — a generic multi-step component (`src/components/ui/Wizard.tsx` or domain):
  steps with a title, next/back, per-step validation, a final submit. The named M8b deliverable.
- **Create-employee wizard** (`/admin/employees/new` or a dialog): Step 1 **Identity**
  (employee_no, first/middle/last/suffix name, hired_at); Step 2 **Employment** (office picker →
  department picker, employment_type, is_art82_exempt, base_rate, reports_to, effective_from);
  Step 3 **Login (optional)** (email, password — defaults name from the employee). On finish:
  `POST /admin/employees` with the `employment` block, then if a login was entered
  `POST /admin/employees/{id}/user`.
- **`/admin/employees`** list screen (name + employee_no + office + login badge); a detail/edit
  view (edit name via `PATCH`; "Record employment change" → the existing employment endpoint;
  "Provision login" if none).
- SideNav Admin section gains **Employees** (`ROUTES.admin`).

## Error handling
Envelope unchanged. `is_system_admin` → 403; bad FK (office/department/reports_to) → 422
`invalid_reference`; validation → 400. Provisioning a login for an employee who already has one →
the existing ProvisionUser behavior (a domain error — reuse it).

## Testing
- Backend: name columns + `full_name`; `CreateEmployee` with name (+ audited); `UpdateEmployee`
  name edit (audited; employee_no unchanged/rejected); `GET /admin/employees` list + `?office=`
  filter (is_system_admin 403 for non-admin); `GET /admin/employees/{employee}` show (name +
  current employment + has_user); bad FK on create → 422.
- Frontend: the `<Wizard>` (step navigation, per-step validation, final submit); the create
  wizard (3 steps → the two POST calls in order); the list + detail/edit screens.
- `scripts/e2e-employee-profiler.sh`: as sysadmin — create an org/office/dept (or reuse seed) →
  create an employee through the create+employment flow with a name → it appears in
  `GET /admin/employees` with its full_name → provision a login → edit the name via PATCH → a
  non-admin is 403.

## Done when
A system admin onboards an employee (name + first employment + optional login) through the wizard,
sees them in the employee list by name, and edits the name — all through the UI, `is_system_admin`
gated, audited. Backend + frontend suites green; `scripts/e2e-employee-profiler.sh` exit 0.

## Deferred
Extended profile (gov IDs, demographics, contact/address); employee separation; surfacing the
new name in the approval queues / payroll export (a polish pass — those still show `employee_no`
until then) → later slices. Roles + `hr_admin_offices` + audit viewer → **M8c**.
