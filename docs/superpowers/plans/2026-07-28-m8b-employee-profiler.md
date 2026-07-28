# M8b — Employee profiler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Every employee gets a name; a system admin onboards/edits employees through a multi-step `<Wizard>` over the existing onboarding actions, plus a list + detail/edit surface.

**Architecture:** Add name columns to `employees`; thread them through `CreateEmployee`; add `UpdateEmployee` + list/show endpoints. All `is_system_admin`-gated (the M8a discipline; reuse `InvalidReference` 422 for bad FKs). A generic `<Wizard>` frontend component drives the create flow (identity → employment → optional login) calling the existing `POST /admin/employees` then `POST /admin/employees/{id}/user`.

**Tech Stack:** Laravel 13 / PHP 8.5 / PG 18 (Pest, real Postgres); Next 16 / React 19 / TS / Carbon (Vitest).

## Global Constraints
- `declare(strict_types=1);` everywhere; actions final/own-transaction/Input-DTO/no-HTTP; controllers final+invokable.
- **`is_system_admin` gate = plain 403** (FormRequest `authorize()` = `(bool)$this->user()?->is_system_admin`), NOT 404. Bad FK id (office/dept/reports_to) → **422 `invalid_reference`** (reuse `app/Exceptions/Domain/InvalidReference.php` from M8a).
- `employee_no` is IMMUTABLE (never editable). Name IS editable.
- Employee model stays UNGUARDED (no `$fillable`); writes via Actions with explicit arrays. `RecordEmploymentChange` is the SOLE writer of `current_*` (arch-enforced) — do NOT write those elsewhere.
- Envelope; integer centavos; `YYYY-MM-DD` dates; `timestamptz`. Real Postgres tests. `var(--*)` tokens only on frontend; no `as any`/`@ts-ignore`.
- **Commit messages: body only, NO attribution trailers.** (PR body too.)

---

### Task 1: Name columns + `full_name` + `LogsActivity` on Employee
**Files:** migration `2026_08_12_000001_add_name_to_employees.php`; `app/Models/Employee.php`; `database/factories/EmployeeFactory.php`; test `tests/Feature/Schema/EmployeeNameSchemaTest.php`.
- [ ] **Migration:** add to `employees`: `first_name text NOT NULL DEFAULT ''`, `middle_name text NULL`, `last_name text NOT NULL DEFAULT ''`, `name_suffix text NULL`. (`DEFAULT ''` satisfies any existing row; the FormRequest requires non-empty on create.) `down()` drops all four.
- [ ] **Employee model:** add a `fullName(): Attribute` accessor (Eloquent `Attribute::make(get: fn () => trim(preg_replace('/\s+/',' ', "{$this->first_name} {$this->middle_name} {$this->last_name} {$this->name_suffix}")))`). Add `LogsActivity` (Employee has none) — `getActivitylogOptions()` logging `['employee_no','first_name','middle_name','last_name','name_suffix','organization_id','hired_at','separated_at']`, `useLogName('employee')`, `logOnlyDirty()`. Match the `Office` idiom from M8a. Do NOT add `$fillable`.
- [ ] **Factory:** add `'first_name' => $this->faker->firstName()`, `'last_name' => $this->faker->lastName()`, `'middle_name' => null`, `'name_suffix' => null` to `definition()`.
- [ ] **Test:** columns exist; `full_name` accessor composes correctly (with and without middle/suffix); a name change writes an `activity_log` row (log_name `employee`).
- [ ] Run `--filter=EmployeeNameSchema`; commit `M8b: name columns + full_name accessor + LogsActivity on Employee`.

### Task 2: Thread name through CreateEmployee + resource
**Files:** `CreateEmployeeInput.php`, `CreateEmployee.php`, `CreateEmployeeRequest.php`, `CreateEmployeeController.php`, `EmployeeResource.php`; test `tests/Feature/Admin/EmployeeCreateNameTest.php` (or extend `EmployeeAdminTest`).
- [ ] Add `firstName`/`middleName`/`lastName`/`nameSuffix` to `CreateEmployeeInput`; `CreateEmployee` writes them in the `Employee::create([...])` array. `CreateEmployeeRequest.rules()`: `first_name` required string, `middle_name` nullable string, `last_name` required string, `name_suffix` nullable string (keep the existing employee_no/organization_id/hired_at/employment rules). Controller reads the four + passes them.
- [ ] `EmployeeResource`: add `first_name`, `middle_name`, `last_name`, `name_suffix`, `full_name`.
- [ ] Test: create an employee with a name → persisted + in the resource + `full_name` correct; missing first/last → 400 validation.
- [ ] Run; commit `M8b: CreateEmployee carries the employee name`.

### Task 3: UpdateEmployee (name edit) + PATCH route
**Files:** `app/Actions/Employees/UpdateEmployee.php` + `UpdateEmployeeInput.php`; `app/Http/Requests/UpdateEmployeeRequest.php`; `app/Http/Controllers/Admin/Employees/UpdateEmployeeController.php`; route; test.
- [ ] `UpdateEmployee::execute(UpdateEmployeeInput{employeeId, firstName, middleName, lastName, nameSuffix, actorId})` — load, `->fill([name fields])->save()` in a transaction. Does NOT touch employee_no/current_*. `UpdateEmployeeRequest` (authorize=is_system_admin): name fields (first/last required, middle/suffix nullable); NO employee_no field.
- [ ] Controller binds `{employee}`; route `PATCH /admin/employees/{employee}` in the admin group.
- [ ] Test: name edit persists + audited; employee_no unchanged; non-admin → 403.
- [ ] Run; commit `M8b: UpdateEmployee (name edit), employee_no immutable`.

### Task 4: GET /admin/employees list + show
**Files:** `app/Http/Controllers/Admin/Employees/{ListController,ShowController}.php`; `app/Http/Requests/ListEmployeesRequest.php`; an `EmployeeDetailResource` (or extend `EmployeeResource`); routes; test.
- [ ] `GET /admin/employees` (ListController, authorize=is_system_admin): `Employee::query()->when($office, fn($q)=>$q->where('current_office_id',$office))->orderBy('employee_no')->get()` → a list resource with `{id, employee_no, first_name, middle_name, last_name, name_suffix, full_name, current_office_id, current_department_id, has_user}` (`has_user` = `user_id !== null`). Optional `?office=`.
- [ ] `GET /admin/employees/{employee}` (ShowController): the employee + name + current employment (resolve the effective `EmploymentRecord` — office/dept/employment_type/is_art82_exempt/base_rate_cents/reports_to) + `has_user`. Reuse `EmploymentResolver::on($employee, today)` for the current record, or the `current_*` cache.
- [ ] Tests: list (+ `?office=` filter, is_system_admin 403), show (name + current employment + has_user).
- [ ] Run; commit `M8b: GET /admin/employees list + show`.

### Task 5: Frontend `<Wizard>` component
**Files:** `frontend/web/src/components/ui/Wizard.tsx` + test.
- [ ] A generic multi-step component: props = an array of steps `{ id, title, render(), validate?() }` + `onComplete()`. Renders the current step, a progress indicator (step N of M), Back/Next (Next disabled when the step's `validate()` fails), Finish on the last step calling `onComplete`. Carbon tokens only. Keyboard-accessible, labeled. Mirror the Carbon-primitive style of existing `src/components/ui/*`.
- [ ] Test: renders step 1; Next advances only when valid; Back returns; Finish calls `onComplete` with accumulated data.
- [ ] Run `npm test -- Wizard && npm run typecheck`; commit `M8b: generic Wizard component`.

### Task 6: Frontend data layer + create-employee wizard
**Files:** `src/lib/api.ts` (`api.admin.employees.*` + wire types), `src/lib/keys.ts`, `src/hooks/useAdminEmployees.ts` + test, `src/app/(app)/admin/employees/new/page.tsx` (or a dialog) + test.
- [ ] Wire types `Employee` (id, employee_no, name fields, full_name, current_office_id, current_department_id, has_user) + create/update inputs. `api.admin.employees.{list(params?),show(id),create(body),update(id,body),provisionUser(id,body),recordEmployment(id,body)}` — mirror the existing `api.adjustments`/`api.admin.offices` style; `create` posts to `/admin/employees` (with an `employment` block), `provisionUser` to `/admin/employees/{id}/user`, `recordEmployment` to `/admin/employees/{id}/employment`. `keys.admin.employees(params?)` + `employee(id)`. Hooks: list/show queries + create/update/provision/recordEmployment mutations invalidating the list/detail.
- [ ] Create-employee wizard using `<Wizard>`: Step 1 Identity (employee_no, first/middle/last/suffix, hired_at); Step 2 Employment (office select → department select filtered by office, employment_type, is_art82_exempt checkbox, base_rate in pesos→centavos, reports_to optional, effective_from); Step 3 Login optional (email, password; name prefilled from the employee). `onComplete`: `create({...identity, employment: {...step2}})` then, if login given, `provisionUser(id, {email, password, name})`. Uses the offices/departments admin hooks (from M8a) for the pickers.
- [ ] Tests: the wizard walks 3 steps and fires `create` then `provisionUser` in order with the right bodies (mock the hooks).
- [ ] Run `npm test && npm run typecheck`; commit `M8b: employee create wizard + admin employees data layer`.

### Task 7: Frontend employees list + detail/edit + nav
**Files:** `src/app/(app)/admin/employees/page.tsx` + test; a detail/edit view (`.../[employee]/page.tsx` or dialog) + test; `SideNav.tsx` (add Employees to `ROUTES.admin`) + test.
- [ ] `/admin/employees` list: full_name + employee_no + current office + a login badge (has_user); a "New employee" button → the wizard; a row click → detail. `is_system_admin` gated (the Admin nav already is).
- [ ] Detail/edit: show name + current employment + login status; edit the name (`PATCH`); "Record employment change" (the recordEmployment mutation with a small form); "Provision login" when `!has_user`.
- [ ] SideNav: add `{href:'/admin/employees',label:'Employees'}` to `ROUTES.admin`; update `SideNav.test.tsx`.
- [ ] Run `npm test && npm run typecheck && npm run build` (all green); commit `M8b: employees list + detail/edit + nav`.

### Task 8: e2e + docs
**Files:** `scripts/e2e-employee-profiler.sh`; docs `02-data-model.md`, `03-api.md`, `06-roadmap.md`, `features.md`.
- [ ] `scripts/e2e-employee-profiler.sh` (mirror `e2e-admin-org.sh`): as sysadmin — reuse/seed an office+dept → `POST /admin/employees` with a name + `employment` block → 201 → appears in `GET /admin/employees` with its `full_name` → `GET /admin/employees/{id}` shows name + current employment → `POST /admin/employees/{id}/user` provisions a login → `PATCH /admin/employees/{id}` edits the name (verify in show) → a non-admin `POST /admin/employees` → 403. Per-assertion PASS/FAIL, exit 1 on mismatch, chmod +x. Run it LIVE (stack up + migrated + seeded), exit 0.
- [ ] Docs: `02` (employee name columns + full_name); `03` (name on create/update, `GET /admin/employees` list+show, PATCH); `06-roadmap` (M8b complete, M8c next); `features` (a sysadmin onboards an employee by name via the wizard). Note the deferred profile/separation/name-in-queues items.
- [ ] Commit `M8b: e2e-employee-profiler.sh + docs; M8b complete`.

## Self-Review
Spec coverage: T1 name schema, T2 create, T3 update, T4 list/show, T5 Wizard, T6 create-wizard+data, T7 list/detail+nav, T8 e2e+docs — all §sections mapped. 403-not-404 + InvalidReference reused from M8a. employee_no immutable stated in T3. Employee unguarded + RecordEmploymentChange-sole-writer stated in Global Constraints.
