# M8c — Roles, scope & audit viewer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Checkbox steps.

**Goal:** A filterable activity-log viewer + HR-admin access management (grant/revoke a user the `HR Admin` role + which offices they administer), all `is_system_admin`-gated. Completes M8.

**Architecture:** Read-only `GET /admin/activity` over the Spatie `activity_log`. `SetHrAdminOffices` couples the pivot sync + role assign/remove; surfaced on the M8b employee detail. No schema changes.

**Tech Stack:** Laravel 13 / PHP 8.5 / PG 18 (Pest); Next 16 / React 19 / TS / Carbon (Vitest).

## Global Constraints
- `declare(strict_types=1);`; actions final/own-transaction/Input-DTO/no-HTTP; controllers final+invokable.
- **`is_system_admin` gate = 403** (FormRequest `authorize()`), NOT 404. Bad office id → **422 `invalid_reference`** (reuse `app/Exceptions/Domain/InvalidReference.php`).
- Spatie `teams` is **false** → role assignment is plain `$user->assignRole('HR Admin')`/`removeRole('HR Admin')` (no team-key). `hr_admin_offices` is `$user->hrAdminOffices()` (BelongsToMany Office).
- Envelope; `var(--*)` tokens only; no `as any`/`@ts-ignore`. Real Postgres tests.
- **Commit messages: body only, NO attribution trailers.** (PR body too.)

---

### Task 1: `GET /admin/activity` audit viewer (backend)
**Files:** `app/Http/Requests/ListActivityRequest.php`; `app/Http/Controllers/Admin/ListActivityController.php`; `app/Http/Resources/ActivityResource.php`; route; test `tests/Feature/Admin/ActivityViewerTest.php`.
- [ ] `ListActivityRequest` (authorize=is_system_admin): rules all nullable — `log_name` string, `event` string, `subject_type` string, `causer_id` uuid, `from` date, `to` date, `page` integer.
- [ ] `ListActivityController` (final invokable): `\Spatie\Activitylog\Models\Activity::query()->when($logName, fn($q)=>$q->where('log_name',$logName))->when($event, fn($q)=>$q->where('event',$event))->when($subjectType, fn($q)=>$q->where('subject_type',$subjectType))->when($causerId, fn($q)=>$q->where('causer_id',$causerId))->when($from, fn($q)=>$q->whereDate('created_at','>=',$from))->when($to, fn($q)=>$q->whereDate('created_at','<=',$to))->latest()->paginate(50)` → `ActivityResource::collection($paginator)`. (Returns Laravel's paginated resource collection — the standard `{data, links, meta}`. Confirm the envelope wraps it correctly — if the app's `ApiErrorEnvelope`/success wrapper needs a specific shape, match how any existing paginated endpoint returns; if none exists, Laravel's resource pagination inside `data` is fine.)
- [ ] `ActivityResource`: `{id, log_name, description, event, subject_type, subject_id, causer_id, properties, created_at (ISO8601)}`. (`properties` is the Spatie collection → `->toArray()`.)
- [ ] Route in the `admin` group: `Route::get('/activity', ListActivityController::class);`.
- [ ] Test: seed activity by creating/updating a few models (Office/Employee — they have LogsActivity from M8a/M8b) → `GET /admin/activity` returns them newest-first; filtering by `?log_name=office` / `?event=updated` / `?causer_id=` / `?from=&to=` narrows correctly; a non-admin → 403; pagination meta present.
- [ ] Run `--filter=ActivityViewer`; commit `M8c: GET /admin/activity audit viewer`.

### Task 2: SetHrAdminOffices + hr-offices endpoint (backend)
**Files:** `app/Actions/Access/SetHrAdminOffices.php` + `SetHrAdminOfficesInput.php`; `app/Exceptions/Domain/EmployeeHasNoLogin.php`; `app/Http/Requests/SetHrAdminOfficesRequest.php`; `app/Http/Controllers/Admin/Employees/SetHrAdminOfficesController.php`; route; extend `app/Http/Resources/EmployeeDetailResource.php`; test `tests/Feature/Admin/HrAdminAccessTest.php`.
- [ ] `SetHrAdminOffices::execute(SetHrAdminOfficesInput{userId, officeIds (array), actorId}): User` — in a transaction: `$user = User::findOrFail($userId); $user->hrAdminOffices()->sync($officeIds); if ($officeIds !== []) { $user->assignRole('HR Admin'); } else { $user->removeRole('HR Admin'); }`. Then audit: `activity()->performedOn($user)->causedBy(User::find($actorId))->withProperties(['office_ids' => $officeIds])->log('hr_admin_offices_set')` (mirror how M7a's `ReopenCutoff` logs a manual event + resolves the causer as a User model, NOT a bare id — that was a real M8a/M7a gotcha). Return `$user`.
- [ ] `EmployeeHasNoLogin(string $employeeId)` exception (422, `employee_has_no_login`), mirror `app/Exceptions/Domain/CutoffNotClosed.php`.
- [ ] `SetHrAdminOfficesRequest` (authorize=is_system_admin): `office_ids` required array, `office_ids.*` uuid (shape-only, no `exists:`).
- [ ] `SetHrAdminOfficesController` (final invokable): binds `{employee}`; `$userId = $employee->user_id ?? throw new EmployeeHasNoLogin($employee->id)`; validate each office id exists (bad → `InvalidReference('office', $id)` 422); call the action; return `EmployeeDetailResource::make($employee->fresh())`.
- [ ] Route: `Route::post('/employees/{employee}/hr-offices', SetHrAdminOfficesController::class);` in the admin group.
- [ ] Extend `EmployeeDetailResource`: add `'hr_admin_office_ids' => $this->user_id === null ? [] : $this->user->hrAdminOffices()->pluck('offices.id')->all()` and `'roles' => $this->user_id === null ? [] : $this->user->getRoleNames()->all()`. (Confirm `Employee::user()` belongsTo exists; the User has `hrAdminOffices()` + spatie's `getRoleNames()`.)
- [ ] Test: grant an employee-with-login HR-admin over office A → the pivot has A + the user has the `HR Admin` role + audited (`hr_admin_offices_set` in activity_log); the detail resource shows `hr_admin_office_ids: [A]`, `roles: ['HR Admin']`; setting empty → pivot cleared + role removed; a login-less employee → 422 `employee_has_no_login`; a bad office id → 422 `invalid_reference`; non-admin → 403.
- [ ] Run `--filter=HrAdminAccess`; commit `M8c: SetHrAdminOffices (role + hr_admin_offices) + endpoint`.

### Task 3: Frontend audit viewer
**Files:** `src/lib/api.ts` (activity types + `api.admin.activity.list`), `src/lib/keys.ts`, `src/hooks/useActivityLog.ts` + test, `src/app/(app)/admin/activity/page.tsx` + test, `SideNav.tsx` (+ test).
- [ ] Wire types `ActivityEntry` + a paginated response type matching the backend; `api.admin.activity.list(filters)` (builds a query string from `{log_name?, event?, subject_type?, causer_id?, from?, to?, page?}`); `keys.admin.activity(filters)`; `useActivityLog(filters)` query hook.
- [ ] `/admin/activity` screen: filter controls (log_name `<Select>`, event `<Select>` [created/updated/deleted + the custom events], a date range, optional causer) + a paginated table (created_at, causer, event, log_name, subject, a properties peek); prev/next pagination. Loading/error/empty states. Only `var(--*)` tokens.
- [ ] SideNav: add `{href:'/admin/activity',label:'Activity log'}` to `ROUTES.admin`; update `SideNav.test.tsx`.
- [ ] Test: the viewer renders rows from a mocked hook; changing a filter re-queries with the right key; pagination advances the page. Run `npm test && npm run typecheck`; commit `M8c: activity log viewer screen`.

### Task 4: Frontend HR-admin access panel
**Files:** `src/lib/api.ts` (`api.admin.employees.setHrOffices` + extend `AdminEmployeeDetail` type with `hr_admin_office_ids`/`roles`), `src/hooks/useAdminEmployees.ts` (a `useSetHrOffices` mutation invalidating the detail), the M8b employee-detail page `src/app/(app)/admin/employees/[employee]/page.tsx` (add the panel) + test.
- [ ] Extend `AdminEmployeeDetail` TS type with `hr_admin_office_ids: string[]` + `roles: string[]`. `api.admin.employees.setHrOffices(id, {office_ids})` (POST). `useSetHrOffices` mutation (invalidate `keys.admin.employee(id)`).
- [ ] On the employee detail: an **"Office admin access"** panel shown when `has_user` — a multi-select of offices (from `useAdminOffices`) prefilled from `hr_admin_office_ids`; a Save → `useSetHrOffices`; show the current `roles` (e.g. a badge). When `!has_user`, show a note ("provision a login first"). Only `var(--*)` tokens.
- [ ] Test: with `has_user` + a prefilled office, the panel renders the office selected + the HR Admin role; Save calls `setHrOffices` with the chosen ids; login-less shows the note.
- [ ] Run `npm test && npm run typecheck && npm run build`; commit `M8c: HR-admin office-access panel on the employee detail`.

### Task 5: e2e + docs
**Files:** `scripts/e2e-admin-roles-audit.sh`; docs `03-api.md`, `05-rbac.md`, `06-roadmap.md`, `features.md`.
- [ ] `scripts/e2e-admin-roles-audit.sh` (mirror `e2e-admin-org.sh`): as sysadmin — pick a seeded employee WITH a login (or create one + provision) → `POST /admin/employees/{id}/hr-offices {office_ids:[A]}` → 200; `GET /admin/employees/{id}` shows `hr_admin_office_ids:[A]` + `roles` includes `HR Admin`; `GET /admin/activity?log_name=` shows the `hr_admin_offices_set` event (and a known M8a office/employee action); revoke (`office_ids:[]`) → role removed; a login-less employee → 422 `employee_has_no_login`; a non-admin `GET /admin/activity` → 403. Live, exit 0, chmod +x. If a real defect surfaces, fix the app + re-run + `pest` green.
- [ ] Docs: `03-api` (`GET /admin/activity` + filters/pagination, `POST /admin/employees/{id}/hr-offices`, the error codes); `05-rbac` (HR-admin grant = role + hr_admin_offices, `is_system_admin` manages it; the audit viewer); `06-roadmap` (mark **M8c complete AND M8 complete**; note M9 containerization next; counts); `features` (sysadmin grants office-admin access + browses the audit log).
- [ ] Commit `M8c: e2e-admin-roles-audit.sh + docs; M8c complete, M8 complete`.

## Self-Review
Spec coverage: T1 audit viewer, T2 hr-admin grant backend, T3 audit viewer frontend, T4 hr-admin panel, T5 e2e+docs — all §sections mapped. 403-not-404 + InvalidReference reused. Role assignment = plain assignRole (teams=false). Causer resolved as a User model (the M7a/M8a gotcha). Audit event `hr_admin_offices_set` makes the grant visible in the viewer (closing the loop with the done-when).
