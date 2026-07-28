# M8a — Organization tree CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A system admin builds the organization tree — organizations, offices, departments — entirely through the UI: create, edit, and archive (never delete), every step audited.

**Architecture:** Action-class CRUD per entity (each `final`, own transaction, Input DTO, audited), gated by `is_system_admin` via each FormRequest's `authorize()` (plain 403 for a non-admin — no 404-scoping, the org tree is global config). Archive is a nullable `archived_at` timestamp on offices/departments (soft, non-cascading); no DELETE routes are added. Admin screens under `/admin/*` mirror the existing pay-rules admin screen. `LogsActivity` on all three models writes the audit trail M8c will later read.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres); Next 16 / React 19 / TS / Tailwind + Carbon (Vitest).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/`/`tests/`. Arch-enforced.
- Never call `env()` outside `config/`. Arch-enforced.
- Actions `final`, own their transaction, take an Input DTO, return the domain model, know nothing about HTTP. Controllers `final` + invokable, read from `$request->validated()`.
- **System-admin gate = plain 403, NOT 404.** Every M8a FormRequest's `authorize()` returns `(bool) $this->user()?->is_system_admin` (mirror `CreateEmployeeRequest`/`CreatePayRuleRequest`). A non-admin gets 403. A bad FK id on a create is a 422 domain/validation error — NOT the 404-existence-hiding used on office-scoped HR endpoints (a system admin may know what exists). This is the deliberate difference from the rest of the API.
- **Archive, never delete.** `archived_at timestamptz NULL` on offices/departments; archive sets `now()`, un-archive nulls it. Active lists filter `whereNull('archived_at')`. **Add NO `DELETE` route.** (The pre-existing `DELETE /admin/pay-rules/{payRule}` from M4 is a separate immutable-version case and is untouched.)
- Archiving is soft and NON-cascading: an archived office keeps its departments/employees/history.
- uuid v7 PKs (already on all three tables). `timestamptz`. Integer/enum/CHECK house rules where they apply.
- Envelope: success `{"data":...}`, error `{"error":...}`. FormRequest validation failure → 400 `validation_failed`; domain exceptions → 422 (409 for already-archived/not-archived).
- Models `Organization`/`Office`/`Department` are unguarded (no `$fillable`) — writes come from vetted Actions; do NOT add `$fillable` (it would flip the mass-assignment default and break other writes). Actions call `->fill([...])->save()` or `Model::query()->create([...])` explicitly.
- Tests run against real PostgreSQL, never SQLite.
- **Commit messages carry no attribution trailers** — message body only. Applies to the PR body too.

---

### Task 1: `archived_at` migration + `LogsActivity` on the org tree

**Files:**
- Create: `backend/database/migrations/2026_08_11_000001_add_archived_at_to_offices_and_departments.php`
- Modify: `backend/app/Models/Organization.php`, `backend/app/Models/Office.php`, `backend/app/Models/Department.php` (add `LogsActivity`)
- Test: `backend/tests/Feature/Schema/OrgTreeArchiveSchemaTest.php`

**Interfaces:**
- Produces: `offices.archived_at` / `departments.archived_at` (nullable timestamptz); `Organization`/`Office`/`Department` write to `activity_log` on change.

- [ ] **Step 1: Write the migration.**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive-never-delete for the org tree: a nullable archived_at marks an office/department
 * closed without removing it (a legal-retention record, not a row to delete). Active lists
 * filter whereNull('archived_at'). Non-cascading — an archived office keeps its children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
        });
        Schema::table('departments', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
```

- [ ] **Step 2: Add `LogsActivity` to the three models.** Read a sibling that uses it (`app/Models/LeaveType.php` or `app/Models/CutoffPeriod.php`) and match the exact idiom. For EACH of `Organization`, `Office`, `Department`, add `use ...LogsActivity;`, the `use LogsActivity;` trait, and a `getActivitylogOptions()` logging the meaningful columns + `->useLogName('organization'|'office'|'department')` + `->logOnlyDirty()`. Example for `Office`:

```php
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['organization_id', 'name', 'code', 'timezone', 'geofence_lat', 'geofence_lng', 'geofence_radius_m', 'ip_allowlist', 'default_shift_template_id', 'archived_at'])
            ->useLogName('office')
            ->logOnlyDirty();
    }
```

Organization logs `['name','legal_name','tin','timezone']`; Department logs `['office_id','name','code','archived_at']`. Also add an `archived_at` cast (`'datetime'`) to Office and Department (in their `casts()` — add a `casts()` method if absent, matching a sibling).

- [ ] **Step 3: Write the schema/audit test.**

```php
<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Office;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('has a nullable archived_at on offices and departments', function (): void {
    expect(Schema::hasColumn('offices', 'archived_at'))->toBeTrue();
    expect(Schema::hasColumn('departments', 'archived_at'))->toBeTrue();
});

it('writes an activity log entry when an office is created and changed', function (): void {
    $office = Office::factory()->create();
    $office->update(['name' => 'Renamed HQ']);

    expect(Activity::query()->where('log_name', 'office')->where('subject_id', $office->id)->exists())->toBeTrue();
});

it('writes an activity log entry for organization and department changes', function (): void {
    $org = Organization::factory()->create();
    $org->update(['name' => 'Renamed Co']);
    $dept = Department::factory()->create();
    $dept->update(['name' => 'Renamed Dept']);

    expect(Activity::query()->where('log_name', 'organization')->where('subject_id', $org->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'department')->where('subject_id', $dept->id)->exists())->toBeTrue();
});
```

> Confirm the factory names exist (`Organization::factory()`, `Office::factory()`, `Department::factory()`) and that `Office::factory()` supplies an `organization_id` and `Department::factory()` an `office_id` (they should — M2 created these tables; if a factory is missing `HasFactory`, add the trait, mirroring how M7a added it to `DailyAttendanceSummary`). Confirm the Spatie `Activity` model import path against an existing activity-log test if one exists.

- [ ] **Step 4: Run + verify.** `cd backend && ./vendor/bin/pest --filter=OrgTreeArchiveSchema`. PASS.
- [ ] **Step 5: Commit.** `git commit -m "M8a: archived_at on offices/departments + LogsActivity on the org tree"`

---

### Task 2: Organization CRUD (create/update/list)

**Files:**
- Create: `backend/app/Actions/Organizations/CreateOrganization.php` + `CreateOrganizationInput.php`, `UpdateOrganization.php` + `UpdateOrganizationInput.php`
- Create: `backend/app/Http/Resources/OrganizationResource.php`
- Create: `backend/app/Http/Controllers/Admin/Organizations/{ListController,CreateController,UpdateController}.php`
- Create: `backend/app/Http/Requests/{CreateOrganizationRequest,UpdateOrganizationRequest}.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/OrganizationCrudTest.php`

**Interfaces:**
- Produces: `GET/POST /admin/organizations`, `PATCH /admin/organizations/{organization}` → `OrganizationResource`. `is_system_admin`-gated (403).

- [ ] **Step 1: Actions + Input DTOs.**

```php
// CreateOrganizationInput.php
final readonly class CreateOrganizationInput
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public ?string $tin,
        public string $timezone,
        public ?string $actorId,
    ) {}
}
```
```php
// CreateOrganization.php
final class CreateOrganization
{
    public function execute(CreateOrganizationInput $in): Organization
    {
        return DB::transaction(fn (): Organization => Organization::query()->create([
            'name' => $in->name,
            'legal_name' => $in->legalName,
            'tin' => $in->tin,
            'timezone' => $in->timezone,
        ]));
    }
}
```
`UpdateOrganizationInput` carries `organizationId` + the same fields; `UpdateOrganization::execute` loads the org, `->fill([...])->save()`, returns it — inside a transaction. (Audit is automatic via `LogsActivity`; the actor is captured by Spatie's causer resolution if the app sets it — if the existing audited actions pass an actor explicitly, match that; otherwise `LogsActivity` records the authenticated causer.)

> Check how an existing audited action (e.g. any M7a cutoff action or `CreatePayRule`) associates the ACTOR with the activity entry. If they rely on Spatie's default causer (the authenticated user) nothing extra is needed; if they explicitly `activity()->causedBy(...)`, do the same. Match the codebase — don't invent a new audit convention.

- [ ] **Step 2: `OrganizationResource`** — `{ id, name, legal_name, tin, timezone }` (mirror `EmployeeResource` style).

- [ ] **Step 3: FormRequests.** `CreateOrganizationRequest`: `authorize()` returns `(bool) $this->user()?->is_system_admin`; `rules()`: `name` required string, `legal_name`/`tin` nullable string, `timezone` required string (a valid tz — use `'timezone'` rule). `UpdateOrganizationRequest`: same fields, all as required/nullable appropriate for a full update (or `sometimes` for a patch — match how `UpdatePayRule`/`UpdateLeaveType` handle PATCH validation).

- [ ] **Step 4: Controllers** (final, invokable, read `$request->validated()`, pass `actorId: $request->user()->id`): `ListController` returns `OrganizationResource::collection(Organization::query()->orderBy('name')->get())`; `CreateController` → 201; `UpdateController` binds `{organization}`, calls `UpdateOrganization`, returns the resource.

- [ ] **Step 5: Routes** in the `admin` group:
```php
Route::get('/organizations', \App\Http\Controllers\Admin\Organizations\ListController::class);
Route::post('/organizations', \App\Http\Controllers\Admin\Organizations\CreateController::class);
Route::patch('/organizations/{organization}', \App\Http\Controllers\Admin\Organizations\UpdateController::class);
```
(Match the file's `use`-import-at-top convention for admin controllers.)

- [ ] **Step 6: Tests** (`OrganizationCrudTest`): a system admin creates an org (201, persisted, activity logged); updates it (name changes, audited); lists orgs; a NON-admin gets **403** on create/update/list (mirror how `tests/Feature/Admin/*` or the pay-rules admin test asserts the 403 — a plain user via `Sanctum::actingAs`). Assert the envelope shape.

- [ ] **Step 7: Run + verify.** `./vendor/bin/pest --filter=OrganizationCrud`. PASS.
- [ ] **Step 8: Commit.** `git commit -m "M8a: organization CRUD (create/update/list), is_system_admin gated"`

---

### Task 3: Office CRUD (create/update/archive/unarchive/list) — the template

**Files:**
- Create: `backend/app/Actions/Offices/{CreateOffice,UpdateOffice,ArchiveOffice,UnarchiveOffice}.php` + `{CreateOfficeInput,UpdateOfficeInput}.php`
- Create: `backend/app/Exceptions/Domain/{AlreadyArchived,NotArchived,DuplicateOfficeCode}.php`
- Create: `backend/app/Http/Resources/OfficeResource.php`
- Create: `backend/app/Http/Controllers/Admin/Offices/{ListController,CreateController,UpdateController,ArchiveController,UnarchiveController}.php`
- Create: `backend/app/Http/Requests/{CreateOfficeRequest,UpdateOfficeRequest}.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/OfficeCrudTest.php`

**Interfaces:**
- Produces: `GET /admin/offices` (active-only default; `?include_archived=1`; `?organization=`), `POST /admin/offices`, `PATCH /admin/offices/{office}`, `POST /admin/offices/{office}/archive`, `POST /admin/offices/{office}/unarchive` → `OfficeResource`. Generic `AlreadyArchived`/`NotArchived` (409) reused by departments (Task 4).

- [ ] **Step 1: Domain exceptions** (mirror `app/Exceptions/Domain/CutoffAlreadyClosed.php`):
  - `AlreadyArchived(string $subjectType, string $subjectId)` — 409, `errorCode() = 'already_archived'`, `details()` carries both. Generic (reused by department).
  - `NotArchived(string $subjectType, string $subjectId)` — 409, `'not_archived'`.
  - `DuplicateOfficeCode(string $code)` — 422, `'duplicate_office_code'`. (Catch the unique violation cleanly, or pre-check.)

- [ ] **Step 2: Input DTOs + actions.**

```php
// CreateOfficeInput.php
final readonly class CreateOfficeInput
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $code,
        public string $timezone,
        public ?float $geofenceLat,
        public ?float $geofenceLng,
        public ?int $geofenceRadiusM,
        public ?array $ipAllowlist,
        public ?string $defaultShiftTemplateId,
        public ?string $actorId,
    ) {}
}
```
```php
// CreateOffice.php — pre-check the unique code to surface a clean 422, then create.
final class CreateOffice
{
    public function execute(CreateOfficeInput $in): Office
    {
        return DB::transaction(function () use ($in): Office {
            if (Office::query()->where('code', $in->code)->exists()) {
                throw new DuplicateOfficeCode($in->code);
            }
            return Office::query()->create([
                'organization_id' => $in->organizationId,
                'name' => $in->name,
                'code' => $in->code,
                'timezone' => $in->timezone,
                'geofence_lat' => $in->geofenceLat,
                'geofence_lng' => $in->geofenceLng,
                'geofence_radius_m' => $in->geofenceRadiusM,
                'ip_allowlist' => $in->ipAllowlist,
                'default_shift_template_id' => $in->defaultShiftTemplateId,
            ]);
        });
    }
}
```
`UpdateOffice` loads by id, re-checks the unique code if it changed (excluding self), `->fill([...])->save()`. `ArchiveOffice::execute(Office)`: if `archived_at !== null` throw `AlreadyArchived('office', $id)`; else `->update(['archived_at' => now()])`. `UnarchiveOffice`: if `archived_at === null` throw `NotArchived('office', $id)`; else `->update(['archived_at' => null])`. All in transactions.

> Note: the `offices.code` unique is a DB constraint; the pre-check is a clean UX path, but keep a `try/catch` on the unique violation as a backstop (a concurrent create could still race the pre-check) OR rely on the pre-check under the transaction — the reviewer will weigh this. The DB unique remains the ultimate guard.

- [ ] **Step 3: `OfficeResource`** — `{ id, organization_id, name, code, timezone, geofence_lat, geofence_lng, geofence_radius_m, ip_allowlist, default_shift_template_id, archived_at (ISO8601|null) }`.

- [ ] **Step 4: FormRequests.** `authorize()` = `is_system_admin`. `CreateOfficeRequest.rules()`: `organization_id` required uuid (shape-only — but this is a system-admin surface, so a nonexistent org id becomes a 422 from the action/DB FK, NOT a 404; do NOT add the 404-scoping); `name`/`code` required string; `timezone` required timezone; geofence lat/lng nullable numeric, radius nullable integer, `ip_allowlist` nullable array, `default_shift_template_id` nullable uuid. `UpdateOfficeRequest`: same, patch-appropriate.

- [ ] **Step 5: Controllers** (final, invokable). `ListController`: read `include_archived` + `organization` query params; `Office::query()->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))->when($organization, fn ($q) => $q->where('organization_id', $organization))->orderBy('name')->get()` → `OfficeResource::collection`. `CreateController` → 201. `UpdateController` binds `{office}`. `ArchiveController`/`UnarchiveController` bind `{office}`, call the action, return the resource.

- [ ] **Step 6: Routes** in the `admin` group (offices):
```php
Route::get('/offices', ListOfficesController::class);
Route::post('/offices', CreateOfficeController::class);
Route::patch('/offices/{office}', UpdateOfficeController::class);
Route::post('/offices/{office}/archive', ArchiveOfficeController::class);
Route::post('/offices/{office}/unarchive', UnarchiveOfficeController::class);
```
(Import the controllers at the top with aliases matching the file's convention.)

- [ ] **Step 7: Tests** (`OfficeCrudTest`): system admin creates an office (201, persisted, audited); duplicate `code` → 422 `duplicate_office_code`; update; archive sets `archived_at` (and it's audited), re-archiving → 409 `already_archived`; unarchive nulls it, un-archiving an active one → 409 `not_archived`; `GET /admin/offices` excludes archived by default, includes with `?include_archived=1`, filters by `?organization=`; a NON-admin → 403 on every endpoint. Assert envelopes/status codes exactly.

- [ ] **Step 8: Run + verify.** `./vendor/bin/pest --filter=OfficeCrud`. PASS.
- [ ] **Step 9: Commit.** `git commit -m "M8a: office CRUD (create/update/archive/unarchive/list) + archive exceptions"`

---

### Task 4: Department CRUD (mirror Office)

**Files:**
- Create: `backend/app/Actions/Departments/{CreateDepartment,UpdateDepartment,ArchiveDepartment,UnarchiveDepartment}.php` + Input DTOs
- Create: `backend/app/Http/Resources/DepartmentResource.php`
- Create: `backend/app/Http/Controllers/Admin/Departments/{ListController,CreateController,UpdateController,ArchiveController,UnarchiveController}.php`
- Create: `backend/app/Http/Requests/{CreateDepartmentRequest,UpdateDepartmentRequest}.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/DepartmentCrudTest.php`

**Interfaces:**
- Produces: `GET /admin/departments` (active-only default; `?include_archived=1`; `?office=`), `POST /admin/departments`, `PATCH /admin/departments/{department}`, `POST /admin/departments/{department}/archive` + `/unarchive` → `DepartmentResource`.

- [ ] **Step 1: Build the department CRUD by mirroring the Office CRUD files** (`app/Actions/Offices/*`, `app/Http/Controllers/Admin/Offices/*`, `app/Http/Requests/CreateOfficeRequest.php`, `OfficeResource.php`) — they exist on your branch from Task 3. **Read them and copy the structure.** Deltas:
  - Parent is `office_id` (not `organization_id`); fields are `{ office_id, name, code }` only (a department has NO geofence/ip/timezone/default-template).
  - **`code` uniqueness scope:** confirm the `departments.code` constraint in the M2 migration — is `code` globally unique or unique-within-office? Match it. Create `DuplicateDepartmentCode(string $code)` (422, `'duplicate_department_code'`) OR reuse a generic duplicate-code exception if you generalized one in Task 3; check within the correct scope (global or per-office) matching the DB constraint.
  - **REUSE** the generic `AlreadyArchived`/`NotArchived` exceptions from Task 3 (they take a `subjectType` — pass `'department'`).
  - `ListController` filters by `?office=` (not `?organization=`).
  - FormRequest `authorize()` = `is_system_admin`; `office_id` required uuid (system-admin surface — bad id → 422, not 404).

- [ ] **Step 2: Routes** in the `admin` group (departments) — parallel to offices (list/create/patch/archive/unarchive).

- [ ] **Step 3: Tests** (`DepartmentCrudTest`) — parallel to `OfficeCrudTest`: create (201, audited), duplicate code → 422, update, archive/unarchive with the 409 edge cases, list active-only + `?include_archived=1` + `?office=` filter, non-admin → 403.

- [ ] **Step 4: Run + verify.** `./vendor/bin/pest --filter=DepartmentCrud`. PASS. Then run the full admin group: `./vendor/bin/pest --filter="OrganizationCrud|OfficeCrud|DepartmentCrud"`. All green.
- [ ] **Step 5: Commit.** `git commit -m "M8a: department CRUD (mirror of office), archive-never-delete"`

---

### Task 5: Frontend data layer — types, api, keys, hooks, nav

**Files:**
- Modify: `frontend/web/src/lib/api.ts` (Organization/Office/Department wire types + `api.admin.*`)
- Modify: `frontend/web/src/lib/keys.ts` (`keys.admin`)
- Create: `frontend/web/src/hooks/useAdminOrgTree.ts` (query + mutation hooks) + test
- Modify: `frontend/web/src/components/SideNav.tsx` (add the three routes to `ROUTES.admin`)
- Modify: `frontend/web/src/components/SideNav.test.tsx`

**Interfaces:**
- Consumes: the `/admin/organizations|offices|departments` endpoints. Produces: `Organization`/`Office`/`Department` wire types; `api.admin.{organizations,offices,departments}.{list,create,update,archive,unarchive}`; `keys.admin`; query/mutation hooks; the SideNav Admin section links.

- [ ] **Step 1: Wire types** in `api.ts` — match the resources (read `OrganizationResource`/`OfficeResource`/`DepartmentResource` on the branch): `Organization = {id,name,legal_name,tin,timezone}`; `Office = {id,organization_id,name,code,timezone,geofence_lat,geofence_lng,geofence_radius_m,ip_allowlist,default_shift_template_id,archived_at: string|null}`; `Department = {id,office_id,name,code,archived_at: string|null}`. Plus the create/update input types.

- [ ] **Step 2: `api.admin`** block (mirror `api.payRules`): `organizations.{list,create,update}`; `offices.{list(params?),create,update,archive,unarchive}` (list takes optional `{include_archived?, organization?}` → query string); `departments.{list(params?),create,update,archive,unarchive}`. Archive/unarchive are `POST` to `/{id}/archive|unarchive` with no body.

- [ ] **Step 3: `keys.admin`** (mirror `keys.payRules`): `organizations()`, `offices(params?)`, `departments(params?)`.

- [ ] **Step 4: Hooks** `useAdminOrgTree.ts` — `useQuery` list hooks + `useMutation` create/update/archive/unarchive hooks, each invalidating the relevant `keys.admin` list. Mirror `useCutoffs`/`useCloseCutoff`. Write the hook test first (mock `api.admin.*`, assert invalidation).

- [ ] **Step 5: SideNav** — add the three links to `ROUTES.admin` (the Admin group is ALREADY gated on `session?.is_system_admin` in `navEntriesFor` — no gating change needed, just the entries): `{ href: '/admin/organizations', label: 'Organizations' }`, `{ href: '/admin/offices', label: 'Offices' }`, `{ href: '/admin/departments', label: 'Departments' }` (alongside the existing pay-rules entry if present). Update `SideNav.test.tsx`'s expected admin-group items.

- [ ] **Step 6: Run tests + typecheck.** `cd frontend/web && npm test -- useAdminOrgTree SideNav && npm run typecheck`. Green.
- [ ] **Step 7: Commit.** `git commit -m "M8a: frontend data layer + Admin nav for the org tree"`

---

### Task 6: Frontend admin screens — organizations, offices, departments

**Files:**
- Create: `frontend/web/src/app/(app)/admin/organizations/page.tsx` + test
- Create: `frontend/web/src/app/(app)/admin/offices/page.tsx` + test
- Create: `frontend/web/src/app/(app)/admin/departments/page.tsx` + test
- Possibly: shared form components under `src/components/domain/`

**Interfaces:**
- Consumes: the Task 5 hooks + types.

- [ ] **Step 1: Read the mirror** — `src/app/(app)/admin/pay-rules/page.tsx` (the only `/admin/*` screen today) for the admin-screen structure (Carbon, React-Query list, create form, `InlineNotification` errors, `Skeleton` loading). Match it.

- [ ] **Step 2: `/admin/organizations`** — list organizations; a create/edit form (name, legal_name, tin, timezone). Write the screen test first (mock the hook; assert list renders + create calls `api.admin.organizations.create`).

- [ ] **Step 3: `/admin/offices`** — list offices (with an organization filter/picker), each row showing name/code/timezone and an **archive/un-archive** action; a **"show archived"** toggle (archived rows badged via `<Tag>`); a create/edit form (name, code, timezone, geofence lat/lng/radius, ip_allowlist, default shift template). Test: list renders, archived rows hidden until the toggle, archive action calls `api.admin.offices.archive`.

- [ ] **Step 4: `/admin/departments`** — list departments (office filter), archive/un-archive + show-archived toggle, create/edit form (name, code, office). Mirror the offices screen.

- [ ] **Step 5: Tokens/a11y** — only `var(--*)` tokens (no raw hex/px), labeled inputs, visible focus, matching the pay-rules screen. No `as any`/`@ts-ignore`.

- [ ] **Step 6: Run tests + typecheck + build.** `npm test && npm run typecheck && npm run build`. All green.
- [ ] **Step 7: Commit.** `git commit -m "M8a: admin screens for organizations, offices, departments"`

---

### Task 7: e2e-admin-org.sh + docs

**Files:**
- Create: `scripts/e2e-admin-org.sh`
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`

- [ ] **Step 1: Write `scripts/e2e-admin-org.sh`.** Mirror `scripts/e2e-cutoffs.sh` (login, `jq` envelope parsing, base URL, per-assertion PASS/FAIL, `exit 1`). As a SYSTEM ADMIN (the seeded sysadmin account — check the seeder for its credentials, same one other e2e scripts use for admin actions): create an organization → create an office under it → create a department under the office → each appears in its `GET` list → the office/department appear in the activity log (query `GET`… if there's no viewer yet, assert via DB `psql` on `activity_log`) → archive the department (drops from the default `GET /admin/departments`, present with `?include_archived=1`) → un-archive (back) → then, logged in as a NON-admin (a plain employee account from the seed), assert `POST /admin/offices` returns **403**. Per-assertion PASS/FAIL, `exit 1` on mismatch, `chmod +x`.

- [ ] **Step 2: Run it live.** Stack up + migrated + seeded (seed as the sibling e2e does if the DB is empty; the new migration must be applied — run `migrate` in the api container). `bash scripts/e2e-admin-org.sh`. Fix any real defect (a failing e2e is a real finding). Exit 0.

- [ ] **Step 3: Docs.**
  - `02-data-model.md`: `offices.archived_at` / `departments.archived_at` (archive-never-delete, non-cascading); note `LogsActivity` now on the org tree.
  - `03-api.md`: the `/admin/organizations|offices|departments` endpoints (bodies, the `include_archived`/`organization`/`office` list params, archive/unarchive), the `is_system_admin` 403 gate (contrast with the 404-scoping elsewhere), `already_archived`/`not_archived`/`duplicate_*_code` error codes.
  - `05-rbac.md`: org-tree CRUD is `is_system_admin`-gated (403, not 404) — the deliberate global-admin exception to the 404-not-403 rule.
  - `06-roadmap.md`: mark **M8a complete**; describe the archive-never-delete org tree + the audit-log writes; note **M8b (employee profiler) is next**. Update status/counts.
  - `features.md`: a system admin can build the org tree (create/edit orgs, offices, departments; archive/un-archive) through the UI.

- [ ] **Step 4: Commit.** `git commit -m "M8a: e2e-admin-org.sh + docs; M8a complete"`

---

## Self-Review (controller — before dispatch)

**Spec coverage:** Task 1 = `archived_at` + `LogsActivity` (spec §Data model, decision 2). Task 2 = organization CRUD (§Actions org, §Routes). Task 3 = office CRUD + archive + exceptions (§Actions office, decision 2/3). Task 4 = department CRUD (§Actions dept). Task 5 = frontend data layer + nav (§Frontend). Task 6 = admin screens (§Frontend). Task 7 = e2e + docs (§Testing, §Done when). Every spec section maps to a task.

**Placeholder scan:** no TBD/TODO; Tasks 1–3 carry full code; Task 4 mirrors the real (by-then-existing) Office files with explicit deltas; Tasks 5–6 give concrete "read sibling X and mirror" with the sibling named (pay-rules screen, `useCutoffs`, SideNav). The "confirm the departments.code uniqueness scope against the M2 migration" note in Task 4 is a genuine fact the implementer must read, not a placeholder.

**Type/name consistency:** the `is_system_admin` 403 gate (via FormRequest `authorize()`) is stated in Global Constraints and applied identically in Tasks 2/3/4. `archived_at` (nullable timestamptz → wire `string|null`) is consistent from the migration (Task 1) → resource (Task 3/4) → TS type (Task 5) → the show-archived toggle (Task 6). The generic `AlreadyArchived`/`NotArchived` (409) exceptions are defined once in Task 3 and reused in Task 4. `include_archived`/`organization`/`office` list params are named identically across the controllers (Task 3/4), the api layer (Task 5), and the e2e (Task 7).

**One flag for the reviewer:** Task 3's office-`code` uniqueness is enforced by a pre-check inside the transaction plus the DB unique as the ultimate guard; a concurrent create could still race the pre-check and surface the raw unique violation. The plan notes this and leaves the belt-and-suspenders `try/catch`-on-unique to the implementer's judgment — the reviewer should confirm a clean 422 (not a raw 500) is the worst case.
