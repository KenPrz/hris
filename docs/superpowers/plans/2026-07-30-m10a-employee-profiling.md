# M10a — Employee Profiling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every employee a personnel file — contact details, personal details, dependents, and government/financial identification numbers with a scanned copy of each — configurable by HR Admins, readable in full by the employee, and readable in redacted form by their manager.

**Architecture:** A 1:1 `employee_profiles` side table keyed on `employee_id`, plus three catalog-backed child tables (`employee_dependents` → `relationships`, `employee_identifications` → `employee_identification_categories`). Closed sets are PHP backed enums in `app/Domain/Profile/` cast onto plain `text` columns — no Postgres `CHECK`, no lookup tables. Identification scans reuse spatie/medialibrary on the existing RustFS-backed `attachments` disk and are served as app-mediated streams, never object URLs. Authorization activates the dormant `employee.pii.edit` permission against the `hr_admin_offices` pivot.

**Tech Stack:** Laravel 13.21 / PHP 8.5, PostgreSQL 18, Pest, spatie/laravel-medialibrary, spatie/laravel-permission, spatie/laravel-activitylog, Next.js 16 / React 19 / TypeScript, @tanstack/react-query, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-07-30-m10a-employee-profiling-design.md`

## Global Constraints

Copied from the spec and `CLAUDE.md`. Every task's requirements implicitly include this section.

- `declare(strict_types=1);` at the top of **every** PHP file in `app/` and `tests/`. An arch test enforces it.
- **Never call `env()` outside `config/`.** An arch test enforces it.
- Actions are `final`, take an Input DTO, return a domain object, and **never** reference HTTP (`Illuminate\Http`, `request()`, `response()`). An arch test enforces it.
- Controllers are `final` and `__invoke`-only. An arch test enforces it.
- `App\Domain` may not use `config`, `env`, `app`, `resolve`, or facades. Eloquent is allowed.
- **One system action = one route = one controller = one Action class.**
- Success responses are `{"data": ...}`; errors are `{"error": ...}`. Never both.
- Calendar dates on the wire are `YYYY-MM-DD` strings, never `Date` objects.
- Money is integer centavos; worked time is integer minutes. Never a float.
- Tests run against **real PostgreSQL**, never SQLite.
- Frontend tokens: every color/spacing/radius reads a `var(--*)` from `carbon.css`. A raw hex or literal pixel value in a component is a bug.
- Commit messages carry **no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL. Message body only.
- Every new PHP model **that has a surrogate `id`** uses `HasUuids` with `newUniqueId(): string { return (string) Str::uuid7(); }` and `uniqueIds(): array { return ['id']; }`, matching `app/Models/Employee.php`. **Exception:** `EmployeeProfile` (Task 1) has no surrogate id — its primary key IS `employee_id`, supplied by the caller — so it uses neither `HasUuids` nor those two methods. That is correct, not an oversight.
- Migration primary keys are `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'));`, with the same `employee_profiles` exception: `$table->foreignUuid('employee_id')->primary()->constrained()->cascadeOnDelete();`.

## Commands

```bash
# Backend, containerized (preferred)
make test-backend
docker compose -f compose.dev.yml exec --user hris api ./vendor/bin/pest --filter=SomeTest

# Backend, native (needs Postgres on 127.0.0.1:5433, db hris_test)
cd backend && ./vendor/bin/pest --filter=SomeTest

# Migrations
docker compose -f compose.dev.yml exec --user hris api php artisan migrate

# Frontend — ALWAYS use this form. A bare `npm test` runs 84 files at full
# parallelism and times out ~16 of them at 5s on a loaded machine; those failures
# are worker contention, not breakage (verified 2026-07-30 against a clean tree).
docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npx vitest run --maxWorkers=4 --testTimeout=20000'

# Single frontend file
docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npx vitest run --testTimeout=20000 <path>'

# Typecheck / lint / build
docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npm run typecheck && npm run lint && npm run build'
```

**Baseline at branch point (`m10a-employee-profiling`, commit `49dbcc1`): 776 backend tests and 541 frontend tests, all passing.** Any failure beyond those is yours.

## File Structure

**Backend — created**

| File | Responsibility |
| --- | --- |
| `app/Domain/Profile/Gender.php` | Closed set: `male`, `female` |
| `app/Domain/Profile/MaritalStatus.php` | Closed set: `single`, `married`, `widowed`, `separated`, `annulled` |
| `app/Domain/Profile/BloodType.php` | Closed set: the eight ABO/Rh values |
| `app/Domain/Profile/LaborType.php` | Closed set: `direct`, `indirect` |
| `database/migrations/2026_08_13_000001_create_employee_profiles_table.php` | The 1:1 side table |
| `database/migrations/2026_08_13_000002_create_relationships_table.php` | Dependent-relationship catalog |
| `database/migrations/2026_08_13_000003_create_employee_dependents_table.php` | Dependents |
| `database/migrations/2026_08_13_000004_create_employee_identifications_tables.php` | ID catalog + ID rows |
| `database/migrations/2026_08_13_000005_add_profiling_columns_to_employment_and_offices.php` | `designation`, `labor_type`, `region` |
| `app/Models/EmployeeProfile.php` | 1:1 profile, enum casts, `age` accessor, `LogsActivity` |
| `app/Models/Relationship.php` | Catalog |
| `app/Models/EmployeeDependent.php` | Dependent, `LogsActivity` |
| `app/Models/EmployeeIdentificationCategory.php` | Catalog |
| `app/Models/EmployeeIdentification.php` | ID row, `HasMedia('scan')`, `LogsActivity` **without** `number` |
| `database/factories/*Factory.php` (5) | Test fixtures |
| `database/seeders/ProfileCatalogSeeder.php` | The eight ID kinds and five relationships |
| `app/Actions/Profile/UpsertEmployeeProfile{,Input}.php` | Profile write |
| `app/Actions/Profile/ReplaceEmployeeDependents{,Input}.php` | Dependents replace-all |
| `app/Actions/Profile/SaveEmployeeIdentification{,Input}.php` | ID upsert + scan |
| `app/Actions/Profile/DeleteEmployeeIdentification{,Input}.php` | ID delete |
| `app/Http/Resources/EmployeeProfileResource.php` | Full shape |
| `app/Http/Resources/EmployeeProfileSummaryResource.php` | Redacted shape |
| `app/Http/Resources/EmployeeAssignmentPresenter.php` | The nine assignment fields, rendered once for both resources |
| `app/Http/Controllers/Profile/ShowMyProfileController.php` | `GET /me/profile` |
| `app/Http/Controllers/Profile/ShowCatalogController.php` | `GET /profile/catalog` |
| `app/Http/Controllers/Employees/ShowProfileController.php` | `GET /employees/{employee}/profile` |
| `app/Http/Controllers/Admin/Profile/ShowController.php` | `GET /admin/employees/{employee}/profile` |
| `app/Http/Controllers/Admin/Profile/UpsertProfileController.php` | `PUT /admin/employees/{employee}/profile` |
| `app/Http/Controllers/Admin/Profile/ReplaceDependentsController.php` | `PUT /admin/employees/{employee}/dependents` |
| `app/Http/Controllers/Admin/Profile/SaveIdentificationController.php` | `POST /admin/employees/{employee}/identifications` |
| `app/Http/Controllers/Admin/Profile/DeleteIdentificationController.php` | `DELETE …/identifications/{identification}` |
| `app/Http/Controllers/Employees/DownloadScanController.php` | `GET …/identifications/{identification}/scan` |
| `app/Http/Requests/Profile/*.php` (4) | Validation + `authorize()` |
| `tests/Feature/Profile/*.php` | Feature + policy matrix tests |

**Backend — modified**

| File | Change |
| --- | --- |
| `app/Models/Employee.php` | `profile()`, `dependents()`, `identifications()` relations |
| `app/Models/EmploymentRecord.php` | (no change — `$guarded = []` already covers new columns) |
| `app/Actions/Employees/RecordEmploymentChange{,Input}.php` | Carry `designation`, `laborType` |
| `app/Http/Requests/RecordEmploymentRequest.php` | Validate the two new fields |
| `app/Http/Resources/EmployeeDetailResource.php` | Surface them in `current_employment` |
| `app/Console/Commands/BootstrapAdmin.php` | Call `ProfileCatalogSeeder` |
| `routes/api.php` | Nine routes |
| `app/Policies/EmployeePolicy.php` | Add `viewFullProfile` / `viewRedactedProfile` / `updateProfile` — `Gate::policy(Employee::class, …)` is already registered, so no provider change |

**Frontend — created**

| File | Responsibility |
| --- | --- |
| `src/lib/authedBlobUrl.ts` | Bearer-authenticated fetch → object URL (lifted from `RequestCard.tsx`) |
| `src/hooks/useMyProfile.ts` | `GET /me/profile` |
| `src/hooks/useEmployeeProfile.ts` | `GET /admin/employees/{id}/profile` |
| `src/hooks/useSaveProfile.ts` | The three write mutations |
| `src/app/(app)/me/profile/page.tsx` | Read-only personnel file |
| `src/components/domain/ProfileSections.tsx` | The five presentational sections |
| `src/components/domain/IdentificationScan.tsx` | Scan preview |

**Frontend — modified**

| File | Change |
| --- | --- |
| `src/lib/api.ts` | Types + `api.profile.*` |
| `src/lib/keys.ts` | `keys.profile.*` |
| `src/components/domain/RequestCard.tsx` | Use `authedBlobUrl` |
| `src/app/(app)/admin/employees/[id]/page.tsx` | Profile tab |

---

## Task Sequence

| # | Task | Deliverable |
| --- | --- | --- |
| 1 | Enums + `employee_profiles` | Schema + model + age accessor |
| 2 | Relationships + dependents | Schema + models |
| 3 | Identification catalog + rows + media | Schema + `HasMedia` |
| 4 | `designation` / `labor_type` / `region` | Columns wired through `RecordEmploymentChange` |
| 5 | `ProfileCatalogSeeder` + bootstrap | Production catalog data |
| 6 | `EmployeeProfilePolicy` | Authorization, unit-tested |
| 7 | Read endpoints + resources | Three GETs, full and redacted |
| 7b | Catalog read | `GET /profile/catalog` for the dropdowns |
| 8 | Profile write | `PUT …/profile` |
| 9 | Dependents write | `PUT …/dependents` |
| 10 | Identification write + delete + scan | `POST` / `DELETE` / stream |
| 11 | Policy matrix + audit tests | The six-actor matrix |
| 12 | Frontend API + keys | Types and client |
| 13 | `/me/profile` screen | Read-only page |
| 14 | Admin profile tab | Forms + scan preview |
| 15 | Docs | Data model, API, RBAC, roadmap, features |

Tasks 1–4 are schema and may be reviewed together. Task 6 gates 7–11. Task 12 gates 13–14.

---

### Task 1: Profile enums and the `employee_profiles` table

**Files:**
- Create: `backend/app/Domain/Profile/Gender.php`
- Create: `backend/app/Domain/Profile/MaritalStatus.php`
- Create: `backend/app/Domain/Profile/BloodType.php`
- Create: `backend/app/Domain/Profile/LaborType.php`
- Create: `backend/database/migrations/2026_08_13_000001_create_employee_profiles_table.php`
- Create: `backend/app/Models/EmployeeProfile.php`
- Create: `backend/database/factories/EmployeeProfileFactory.php`
- Modify: `backend/app/Models/Employee.php` (add `profile()` relation)
- Test: `backend/tests/Feature/Profile/EmployeeProfileModelTest.php`

**Interfaces:**
- Produces: `App\Domain\Profile\Gender::Male|Female` (backed `string`), `MaritalStatus::Single|Married|Widowed|Separated|Annulled`, `BloodType::APositive|ANegative|BPositive|BNegative|ABPositive|ABNegative|OPositive|ONegative`, `LaborType::Direct|Indirect`. `App\Models\EmployeeProfile` with `$primaryKey = 'employee_id'`, `$incrementing = false`, `$keyType = 'string'`, an `age` accessor returning `?int`, and `employee(): BelongsTo`. `Employee::profile(): HasOne`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/EmployeeProfileModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('stores a profile keyed on employee_id and casts its enums', function (): void {
    $employee = Employee::factory()->create();

    EmployeeProfile::query()->create([
        'employee_id' => $employee->id,
        'salutation' => 'Mr.',
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
        'mobile' => '09166229187',
        'gender' => Gender::Male,
        'birth_date' => '2002-01-23',
        'marital_status' => MaritalStatus::Single,
        'citizenship' => 'Filipino',
        'religion' => 'Roman Catholic',
        'blood_type' => BloodType::OPositive,
    ]);

    $profile = $employee->fresh()->profile;

    expect($profile)->not->toBeNull()
        ->and($profile->getKey())->toBe($employee->id)
        ->and($profile->gender)->toBe(Gender::Male)
        ->and($profile->marital_status)->toBe(MaritalStatus::Single)
        ->and($profile->blood_type)->toBe(BloodType::OPositive)
        ->and($profile->birth_date->toDateString())->toBe('2002-01-23');
});

it('derives age from birth_date in the employee office timezone, not UTC', function (): void {
    // 2002-01-23 born. Freeze the clock at 2026-01-22 16:30 UTC — which is already
    // 2026-01-23 00:30 in Asia/Manila, i.e. the birthday HAS passed locally but has NOT
    // passed in UTC. A naive now() yields 23; the office timezone yields 24.
    Carbon::setTestNow(Carbon::parse('2026-01-22 16:30:00', 'UTC'));

    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $profile = EmployeeProfile::query()->create([
        'employee_id' => $employee->id,
        'birth_date' => '2002-01-23',
    ]);

    expect($profile->fresh()->age)->toBe(24);

    Carbon::setTestNow();
});

it('returns a null age when birth_date is unset', function (): void {
    $employee = Employee::factory()->create();
    $profile = EmployeeProfile::query()->create(['employee_id' => $employee->id]);

    expect($profile->age)->toBeNull();
});

it('cascades the profile away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeProfile::query()->create(['employee_id' => $employee->id, 'nickname' => 'KENPE']);

    $employee->delete();

    expect(EmployeeProfile::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test-backend` (or `cd backend && ./vendor/bin/pest --filter=EmployeeProfileModelTest`)
Expected: FAIL — `Class "App\Domain\Profile\Gender" not found`.

- [ ] **Step 3: Write the four enums**

`backend/app/Domain/Profile/Gender.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/**
 * A closed set enforced in PHP, not by a Postgres CHECK — see the M10a spec, decision 4.
 * The column is plain text; this enum is the only definition of what may go in it.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
```

`backend/app/Domain/Profile/MaritalStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Profile;

enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case Annulled = 'annulled';
}
```

`backend/app/Domain/Profile/BloodType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/** Case names cannot contain '+'/'-', so the backed values carry the real notation. */
enum BloodType: string
{
    case APositive = 'A+';
    case ANegative = 'A-';
    case BPositive = 'B+';
    case BNegative = 'B-';
    case ABPositive = 'AB+';
    case ABNegative = 'AB-';
    case OPositive = 'O+';
    case ONegative = 'O-';
}
```

`backend/app/Domain/Profile/LaborType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Profile;

/** Lives on employment_records, not the profile: a transfer can change it. */
enum LaborType: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';
}
```

- [ ] **Step 4: Write the migration**

`backend/database/migrations/2026_08_13_000001_create_employee_profiles_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personnel file's 1:1 side table. Keyed on employee_id as the PRIMARY KEY — the
 * relationship is the identity, so there is no surrogate id to get out of sync.
 *
 * Deliberately NOT columns on `employees`: half of this changes over a career (address,
 * phone, marital status) and `employees` is the row every office-scope query touches.
 * See docs/superpowers/specs/2026-07-30-m10a-employee-profiling-design.md, decision 1.
 *
 * gender/marital_status/blood_type are plain text with PHP backed enums cast on the model
 * (decision 4) — no CHECK constraint, deliberately. Adding a marital status must not be a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->primary()->constrained()->cascadeOnDelete();

            $table->text('salutation')->nullable();
            $table->text('nickname')->nullable();

            $table->text('home_address')->nullable();
            $table->text('personal_email')->nullable();
            $table->text('phone')->nullable();
            $table->text('fax')->nullable();
            $table->text('mobile')->nullable();
            // ponytail: one free-text line ("Juan Perez (father) 0917…"), not name/relation/
            // phone columns. Split it when something needs to dial it programmatically.
            $table->text('emergency_contact')->nullable();

            $table->text('gender')->nullable();            // Domain\Profile\Gender
            $table->date('birth_date')->nullable();
            $table->text('birthplace')->nullable();
            $table->text('marital_status')->nullable();    // Domain\Profile\MaritalStatus
            $table->text('citizenship')->nullable();
            $table->text('religion')->nullable();
            $table->text('blood_type')->nullable();        // Domain\Profile\BloodType

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
```

- [ ] **Step 5: Write the model**

`backend/app/Models/EmployeeProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use Database\Factories\EmployeeProfileFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The 1:1 personnel file. No HasUuids: the primary key IS employee_id, supplied by the
 * caller, so there is no id to generate.
 */
final class EmployeeProfile extends Model
{
    /** @use HasFactory<EmployeeProfileFactory> */
    use HasFactory, LogsActivity;

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'blood_type' => BloodType::class,
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Derived, never stored — a written age is wrong the day after it is written, the same
     * reasoning as Employee::full_name being composed in one place.
     *
     * Computed in the employee's CURRENT OFFICE timezone, not now(). APP_TIMEZONE is UTC by
     * rule (01-architecture.md), so a naive now() rolls an age over up to eight hours early
     * in Manila. Falls back to Asia/Manila when the employee has no office yet — the same
     * default `offices.timezone` carries.
     */
    protected function age(): Attribute
    {
        return Attribute::make(get: function (): ?int {
            if ($this->birth_date === null) {
                return null;
            }

            $timezone = $this->employee?->currentOffice?->timezone ?? 'Asia/Manila';

            return $this->birth_date->diffInYears(Carbon::now($timezone)->startOfDay());
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->useLogName('employee_profile')
            ->logOnlyDirty();
    }
}
```

- [ ] **Step 6: Write the factory**

`backend/database/factories/EmployeeProfileFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeProfile> */
final class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'salutation' => 'Mr.',
            'nickname' => mb_strtoupper($this->faker->lexify('?????')),
            'home_address' => $this->faker->address(),
            'personal_email' => $this->faker->safeEmail(),
            'mobile' => '09'.$this->faker->numerify('#########'),
            'gender' => $this->faker->randomElement(Gender::cases()),
            'birth_date' => $this->faker->date('Y-m-d', '2004-01-01'),
            'birthplace' => $this->faker->city(),
            'marital_status' => $this->faker->randomElement(MaritalStatus::cases()),
            'citizenship' => 'Filipino',
            'religion' => 'Roman Catholic',
            'blood_type' => $this->faker->randomElement(BloodType::cases()),
        ];
    }
}
```

- [ ] **Step 7: Add the relation to `Employee`**

In `backend/app/Models/Employee.php`, add the `HasOne` import (`use Illuminate\Database\Eloquent\Relations\HasOne;`) and this method after `employmentRecords()`:

```php
    /** The 1:1 personnel file (M10a). @return HasOne<EmployeeProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }
```

- [ ] **Step 8: Run the migration and the test**

```bash
docker compose -f compose.dev.yml exec --user hris api php artisan migrate
make test-backend
```
Expected: PASS — all four `EmployeeProfileModelTest` cases green, and no existing test broken.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Domain/Profile backend/app/Models/EmployeeProfile.php \
        backend/app/Models/Employee.php \
        backend/database/migrations/2026_08_13_000001_create_employee_profiles_table.php \
        backend/database/factories/EmployeeProfileFactory.php \
        backend/tests/Feature/Profile/EmployeeProfileModelTest.php
git commit -m "M10a: profile enums and the employee_profiles 1:1 table"
```

---

### Task 2: Relationships catalog and dependents

**Files:**
- Create: `backend/database/migrations/2026_08_13_000002_create_relationships_table.php`
- Create: `backend/database/migrations/2026_08_13_000003_create_employee_dependents_table.php`
- Create: `backend/app/Models/Relationship.php`
- Create: `backend/app/Models/EmployeeDependent.php`
- Create: `backend/database/factories/RelationshipFactory.php`
- Create: `backend/database/factories/EmployeeDependentFactory.php`
- Modify: `backend/app/Models/Employee.php` (add `dependents()` relation)
- Test: `backend/tests/Feature/Profile/EmployeeDependentTest.php`

**Interfaces:**
- Consumes: `App\Models\Employee` (Task 1's `profile()` sits beside the new `dependents()`).
- Produces: `App\Models\Relationship` (`code`, `description`), `App\Models\EmployeeDependent` (`employee_id` nullable, `name`, `relationship_id`, `birth_date`) with `relationship(): BelongsTo` and `employee(): BelongsTo`. `Employee::dependents(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/EmployeeDependentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links a dependent to an employee and a relationship', function (): void {
    $employee = Employee::factory()->create();
    $spouse = Relationship::query()->create(['code' => 'spouse', 'description' => 'Spouse']);

    EmployeeDependent::query()->create([
        'employee_id' => $employee->id,
        'name' => 'Maria Perez',
        'relationship_id' => $spouse->id,
        'birth_date' => '2003-05-11',
    ]);

    $dependent = $employee->fresh()->dependents->first();

    expect($dependent)->not->toBeNull()
        ->and($dependent->name)->toBe('Maria Perez')
        ->and($dependent->relationship->code)->toBe('spouse')
        ->and($dependent->birth_date->toDateString())->toBe('2003-05-11');
});

it('rejects a duplicate relationship code', function (): void {
    Relationship::query()->create(['code' => 'child', 'description' => 'Child']);

    expect(fn () => Relationship::query()->create(['code' => 'child', 'description' => 'Child again']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

// ponytail: employee_id is nullable by explicit user decision (spec, decision 8). This
// test PINS that decision so a later "tighten the schema" pass has to argue with a
// failing test rather than silently changing the contract.
it('allows a dependent with no employee, deliberately', function (): void {
    $parent = Relationship::query()->create(['code' => 'parent', 'description' => 'Parent']);

    $orphan = EmployeeDependent::query()->create([
        'employee_id' => null,
        'name' => 'Unassigned Person',
        'relationship_id' => $parent->id,
    ]);

    expect($orphan->employee_id)->toBeNull()
        ->and(EmployeeDependent::query()->count())->toBe(1);
});

it('cascades dependents away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeDependent::factory()->create(['employee_id' => $employee->id]);

    $employee->delete();

    expect(EmployeeDependent::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=EmployeeDependentTest`
Expected: FAIL — `Class "App\Models\Relationship" not found`.

- [ ] **Step 3: Write the two migrations**

`backend/database/migrations/2026_08_13_000002_create_relationships_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dependent-relationship catalog (spouse/child/parent/…). A table rather than a PHP
 * enum because it is referenced by a foreign key, not merely validated — the one place
 * M10a's "closed sets are enums" rule bends, deliberately. See the spec, decision 4.
 *
 * Seeded by ProfileCatalogSeeder, which production runs through hris:bootstrap-admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
    }
};
```

`backend/database/migrations/2026_08_13_000003_create_employee_dependents_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_dependents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            // ponytail: NULLABLE by explicit decision (spec, decision 8) — an orphan
            // dependent row is unreachable by every query in the system, this was raised,
            // and the answer was to keep it. Intent, not an oversight to tighten.
            $table->foreignUuid('employee_id')->nullable()->constrained()->cascadeOnDelete();

            $table->text('name');
            $table->foreignUuid('relationship_id')->constrained('relationships');
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_dependents');
    }
};
```

- [ ] **Step 4: Write the two models**

`backend/app/Models/Relationship.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RelationshipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Relationship extends Model
{
    /** @use HasFactory<RelationshipFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }
}
```

`backend/app/Models/EmployeeDependent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeDependentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class EmployeeDependent extends Model
{
    /** @use HasFactory<EmployeeDependentFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Relationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'name', 'relationship_id', 'birth_date'])
            ->useLogName('employee_profile')
            ->logOnlyDirty();
    }
}
```

- [ ] **Step 5: Write the two factories**

`backend/database/factories/RelationshipFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Relationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Relationship> */
final class RelationshipFactory extends Factory
{
    protected $model = Relationship::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('rel_?????'),
            'description' => $this->faker->word(),
        ];
    }
}
```

`backend/database/factories/EmployeeDependentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeDependent> */
final class EmployeeDependentFactory extends Factory
{
    protected $model = EmployeeDependent::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'name' => $this->faker->name(),
            'relationship_id' => Relationship::factory(),
            'birth_date' => $this->faker->date(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `Employee`**

In `backend/app/Models/Employee.php`, add after `profile()`:

```php
    /** @return HasMany<EmployeeDependent, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(EmployeeDependent::class);
    }
```

- [ ] **Step 7: Run the migration and the test**

```bash
docker compose -f compose.dev.yml exec --user hris api php artisan migrate
cd backend && ./vendor/bin/pest --filter=EmployeeDependentTest
```
Expected: PASS — all four cases green.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Models/Relationship.php backend/app/Models/EmployeeDependent.php \
        backend/app/Models/Employee.php \
        backend/database/migrations/2026_08_13_00000{2,3}_* \
        backend/database/factories/RelationshipFactory.php \
        backend/database/factories/EmployeeDependentFactory.php \
        backend/tests/Feature/Profile/EmployeeDependentTest.php
git commit -m "M10a: relationships catalog and employee dependents"
```

---

### Task 3: Identification catalog, identification rows, and the scan collection

**Files:**
- Create: `backend/database/migrations/2026_08_13_000004_create_employee_identifications_tables.php`
- Create: `backend/app/Models/EmployeeIdentificationCategory.php`
- Create: `backend/app/Models/EmployeeIdentification.php`
- Create: `backend/database/factories/EmployeeIdentificationCategoryFactory.php`
- Create: `backend/database/factories/EmployeeIdentificationFactory.php`
- Modify: `backend/app/Models/Employee.php` (add `identifications()` relation)
- Test: `backend/tests/Feature/Profile/EmployeeIdentificationTest.php`

**Interfaces:**
- Consumes: `App\Models\Employee`.
- Produces: `App\Models\EmployeeIdentificationCategory` (`code`, `name`, `description`), `App\Models\EmployeeIdentification` (`employee_id`, `category_id`, `number`, `issued_on`, `expires_on`, `notes`) implementing `Spatie\MediaLibrary\HasMedia` with a single-file collection named `scan` on disk `attachments`. `Employee::identifications(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/EmployeeIdentificationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('stores one identification per employee per category', function (): void {
    $employee = Employee::factory()->create();
    $tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);

    $id = EmployeeIdentification::query()->create([
        'employee_id' => $employee->id,
        'category_id' => $tin->id,
        'number' => '653536955000',
        'issued_on' => '2020-03-01',
    ]);

    expect($id->number)->toBe('653536955000')
        ->and($id->category->code)->toBe('TIN')
        ->and($employee->fresh()->identifications)->toHaveCount(1);
});

it('rejects a second identification in the same category for the same employee', function (): void {
    $employee = Employee::factory()->create();
    $tin = EmployeeIdentificationCategory::factory()->create(['code' => 'TIN']);

    EmployeeIdentification::query()->create([
        'employee_id' => $employee->id, 'category_id' => $tin->id, 'number' => '1',
    ]);

    expect(fn () => EmployeeIdentification::query()->create([
        'employee_id' => $employee->id, 'category_id' => $tin->id, 'number' => '2',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('allows the same category across two different employees', function (): void {
    $tin = EmployeeIdentificationCategory::factory()->create(['code' => 'TIN']);

    EmployeeIdentification::factory()->create(['category_id' => $tin->id, 'number' => '1']);
    EmployeeIdentification::factory()->create(['category_id' => $tin->id, 'number' => '2']);

    expect(EmployeeIdentification::query()->count())->toBe(2);
});

it('attaches a scan to the attachments disk as a single-file collection', function (): void {
    Storage::fake('attachments');

    $id = EmployeeIdentification::factory()->create();

    $id->addMedia(UploadedFile::fake()->create('tin.pdf', 12, 'application/pdf'))
        ->toMediaCollection('scan');

    expect($id->fresh()->getMedia('scan'))->toHaveCount(1)
        ->and($id->fresh()->getFirstMedia('scan')->disk)->toBe('attachments');

    // Single-file: adding a second scan REPLACES the first rather than accumulating.
    $id->addMedia(UploadedFile::fake()->create('tin-v2.pdf', 12, 'application/pdf'))
        ->toMediaCollection('scan');

    expect($id->fresh()->getMedia('scan'))->toHaveCount(1)
        ->and($id->fresh()->getFirstMedia('scan')->file_name)->toBe('tin-v2.pdf');
});

// The security-critical one: a TIN must never be copied into activity_log, which has
// different read rules and a longer retention than anyone reasoned about. See spec,
// decision 6.
it('never writes an identification number into the activity log', function (): void {
    $id = EmployeeIdentification::factory()->create(['number' => '653536955000']);
    $id->update(['number' => '999999999999']);

    $properties = Activity::query()->pluck('properties')->map(fn ($p) => (string) $p)->implode(' ');

    expect(Activity::query()->count())->toBeGreaterThan(0)
        ->and($properties)->not->toContain('653536955000')
        ->and($properties)->not->toContain('999999999999');
});

it('cascades identifications away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeIdentification::factory()->create(['employee_id' => $employee->id]);

    $employee->delete();

    expect(EmployeeIdentification::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=EmployeeIdentificationTest`
Expected: FAIL — `Class "App\Models\EmployeeIdentificationCategory" not found`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_13_000004_create_employee_identifications_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Government and financial IDs as ROWS against a catalog, not eight columns on the
 * profile. An identification is not a bare number — it has an issue date, an expiry, and a
 * scanned copy HR is expected to be able to produce, none of which a column can carry, and
 * a ninth ID type must be a row rather than a migration.
 *
 * unique(employee_id, category_id) is what makes the write path a clean upsert: one
 * employee has one TIN. See the M10a spec, decision 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_identification_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();      // 'TIN', 'SSS', 'HDMF', 'PHIC', ...
            $table->text('name');                // 'TIN', 'SSS ID', 'Pag-IBIG MID', ...
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_identifications', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('employee_identification_categories');

            $table->text('number');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_identifications');
        Schema::dropIfExists('employee_identification_categories');
    }
};
```

- [ ] **Step 4: Write the two models**

`backend/app/Models/EmployeeIdentificationCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeIdentificationCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class EmployeeIdentificationCategory extends Model
{
    /** @use HasFactory<EmployeeIdentificationCategoryFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }
}
```

`backend/app/Models/EmployeeIdentification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeIdentificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class EmployeeIdentification extends Model implements HasMedia
{
    /** @use HasFactory<EmployeeIdentificationFactory> */
    use HasFactory, HasUuids, InteractsWithMedia, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<EmployeeIdentificationCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeIdentificationCategory::class, 'category_id');
    }

    public function registerMediaCollections(): void
    {
        // The scanned copy, on the private RustFS-backed disk — same collection shape and
        // limits as Request's 'attachment'. singleFile() so re-uploading replaces rather
        // than accumulating: an identification has one current scan, not a pile.
        $this->addMediaCollection('scan')
            ->singleFile()
            ->useDisk('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /**
     * `number` is DELIBERATELY absent from logOnly(). Logging it would copy every TIN, SSS
     * number, and bank account into activity_log — a table with different read rules and a
     * longer retention than anyone reasoned about. The log records THAT the identification
     * changed, never to what. See the M10a spec, decision 6.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'category_id', 'issued_on', 'expires_on'])
            ->useLogName('employee_profile')
            ->logOnlyDirty();
    }
}
```

- [ ] **Step 5: Write the two factories**

`backend/database/factories/EmployeeIdentificationCategoryFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeeIdentificationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeIdentificationCategory> */
final class EmployeeIdentificationCategoryFactory extends Factory
{
    protected $model = EmployeeIdentificationCategory::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('ID_?????'),
            'name' => $this->faker->word(),
            'description' => $this->faker->sentence(),
        ];
    }
}
```

`backend/database/factories/EmployeeIdentificationFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeIdentification> */
final class EmployeeIdentificationFactory extends Factory
{
    protected $model = EmployeeIdentification::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'category_id' => EmployeeIdentificationCategory::factory(),
            'number' => $this->faker->numerify('############'),
            'issued_on' => $this->faker->date(),
        ];
    }
}
```

- [ ] **Step 6: Add the relation to `Employee`**

In `backend/app/Models/Employee.php`, add after `dependents()`:

```php
    /** @return HasMany<EmployeeIdentification, $this> */
    public function identifications(): HasMany
    {
        return $this->hasMany(EmployeeIdentification::class);
    }
```

- [ ] **Step 7: Run the migration and the test**

```bash
docker compose -f compose.dev.yml exec --user hris api php artisan migrate
cd backend && ./vendor/bin/pest --filter=EmployeeIdentificationTest
```
Expected: PASS — all six cases green, including the "never logs a number" case.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Models/EmployeeIdentification*.php backend/app/Models/Employee.php \
        backend/database/migrations/2026_08_13_000004_* \
        backend/database/factories/EmployeeIdentification*.php \
        backend/tests/Feature/Profile/EmployeeIdentificationTest.php
git commit -m "M10a: employee identifications with a catalog and a RustFS scan collection"
```

---

### Task 4: `designation`, `labor_type`, and `region`

These three requested "Assignment" fields are **not** profile data. Designation and labor type are effective-dated (a promotion changes them on a date), so they belong on `employment_records` behind its single writer. Region is a property of the office, not the person.

**Files:**
- Create: `backend/database/migrations/2026_08_13_000005_add_profiling_columns_to_employment_and_offices.php`
- Modify: `backend/app/Actions/Employees/RecordEmploymentChangeInput.php`
- Modify: `backend/app/Actions/Employees/RecordEmploymentChange.php`
- Modify: `backend/app/Http/Requests/RecordEmploymentRequest.php`
- Modify: `backend/app/Http/Controllers/Admin/Employees/RecordEmploymentController.php`
- Modify: `backend/app/Http/Resources/EmployeeDetailResource.php`
- Modify: `backend/app/Http/Requests/UpdateOfficeRequest.php` and `CreateOfficeRequest.php`
- Test: `backend/tests/Feature/Profile/AssignmentFieldsTest.php`

**Interfaces:**
- Consumes: `App\Domain\Profile\LaborType` (Task 1).
- Produces: `RecordEmploymentChangeInput` gains `public ?string $designation` and `public ?string $laborType` as the **last two** constructor parameters (appended, so existing positional callers are unaffected — but every caller uses named arguments, so verify). `EmployeeDetailResource`'s `current_employment` gains `designation` and `labor_type`. `offices.region` is readable on `Office`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/AssignmentFieldsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Profile\LaborType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records designation and labor type on the employment record', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();

    $record = app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2025-06-16',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: LaborType::Direct->value,
    ));

    expect($record->designation)->toBe('Backend Software Developer')
        ->and($record->labor_type)->toBe('direct');
});

it('keeps designation effective-dated rather than current-only', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();
    $action = app(RecordEmploymentChange::class);

    $base = [
        'employeeId' => $employee->id,
        'officeId' => $office->id,
        'departmentId' => $department->id,
        'reportsToId' => null,
        'employmentType' => 'regular',
        'isArt82Exempt' => false,
        'baseRateCents' => 3000000,
        'actorId' => null,
        'laborType' => 'direct',
    ];

    $action->execute(new RecordEmploymentChangeInput(...[...$base,
        'effectiveFrom' => '2025-06-16', 'designation' => 'Junior Developer']));
    $action->execute(new RecordEmploymentChangeInput(...[...$base,
        'effectiveFrom' => '2026-06-16', 'designation' => 'Backend Software Developer']));

    $designations = $employee->employmentRecords()
        ->orderBy('effective_from')->pluck('designation')->all();

    // Both survive. A promotion does not rewrite what last year's record said.
    expect($designations)->toBe(['Junior Developer', 'Backend Software Developer']);
});

it('exposes designation and labor type through the admin employee detail endpoint', function (): void {
    $office = Office::factory()->create(['region' => 'VII']);
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create();
    $admin = User::factory()->create(['is_system_admin' => true]);

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2025-06-16',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: 'direct',
    ));

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.current_employment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.current_employment.labor_type', 'direct');

    expect($office->fresh()->region)->toBe('VII');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=AssignmentFieldsTest`
Expected: FAIL — `Unknown named parameter $designation`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_13_000005_add_profiling_columns_to_employment_and_offices.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three "Assignment" fields that are not profile data.
 *
 * designation/labor_type go on employment_records because they are effective-dated by
 * nature: a promotion changes the designation on a date, and putting it on the profile
 * would make last March's report show today's job title. region goes on offices because
 * Cebu is in Region VII regardless of who works there.
 *
 * Nothing is cached onto `employees`. The current_* columns exist so office scoping stays
 * a plain WHERE; no scope query filters by designation, so a current_designation would be
 * cache invalidation bought for nothing. See the M10a spec, decision 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_records', function (Blueprint $table): void {
            $table->text('designation')->nullable();
            $table->text('labor_type')->nullable();   // Domain\Profile\LaborType
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->text('region')->nullable();       // 'VII'
        });
    }

    public function down(): void
    {
        Schema::table('employment_records', function (Blueprint $table): void {
            $table->dropColumn(['designation', 'labor_type']);
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn('region');
        });
    }
};
```

- [ ] **Step 4: Extend the Input DTO**

In `backend/app/Actions/Employees/RecordEmploymentChangeInput.php`, append two parameters after `$actorId`:

```php
        public ?string $actorId,
        public ?string $designation = null,
        public ?string $laborType = null,   // Domain\Profile\LaborType value
    ) {}
```

- [ ] **Step 5: Write them in the action**

In `backend/app/Actions/Employees/RecordEmploymentChange.php`, inside the `EmploymentRecord::query()->create([...])` array, add after `'base_rate_cents' => $in->baseRateCents,`:

```php
                'designation' => $in->designation,
                'labor_type' => $in->laborType,
```

Leave the `current_*` cache update block untouched — neither field is cached, deliberately.

- [ ] **Step 6: Validate them**

In `backend/app/Http/Requests/RecordEmploymentRequest.php`, add the import `use App\Domain\Profile\LaborType;` and `use Illuminate\Validation\Rule;`, then add to `rules()`:

```php
            'designation' => ['nullable', 'string', 'max:255'],
            'labor_type' => ['nullable', Rule::enum(LaborType::class)],
```

- [ ] **Step 7: Pass them through the controller**

In `backend/app/Http/Controllers/Admin/Employees/RecordEmploymentController.php`, add to the `new RecordEmploymentChangeInput(...)` call, after `actorId:`:

```php
            designation: $validated['designation'] ?? null,
            laborType: $validated['labor_type'] ?? null,
```

- [ ] **Step 8: Surface them in the resource**

In `backend/app/Http/Resources/EmployeeDetailResource.php`, inside the `current_employment` array, add after `'employment_type' => $current->employment_type,`:

```php
                'designation' => $current->designation,
                'labor_type' => $current->labor_type,
```

- [ ] **Step 9: Allow `region` on office writes**

In both `backend/app/Http/Requests/CreateOfficeRequest.php` and `UpdateOfficeRequest.php`, add to `rules()`:

```php
            'region' => ['nullable', 'string', 'max:32'],
```

Then in the office create/update controllers and their Input DTOs, thread `region` through exactly as `timezone` is already threaded. Read `app/Http/Controllers/Admin/Offices/UpdateController.php` and mirror its handling of an existing nullable text field.

- [ ] **Step 10: Run the migration and the full suite**

```bash
docker compose -f compose.dev.yml exec --user hris api php artisan migrate
make test-backend
```
Expected: PASS — the three new cases green, and every pre-existing employment/office test still green (the new parameters default to `null`, so no existing caller changes behaviour).

- [ ] **Step 11: Commit**

```bash
git add backend/database/migrations/2026_08_13_000005_* \
        backend/app/Actions/Employees/ backend/app/Http/Requests/ \
        backend/app/Http/Controllers/Admin/ backend/app/Http/Resources/EmployeeDetailResource.php \
        backend/tests/Feature/Profile/AssignmentFieldsTest.php
git commit -m "M10a: designation and labor type on employment records, region on offices"
```

---

### Task 5: `ProfileCatalogSeeder` and production bootstrap

The eight ID kinds and five relationships are catalog data **production needs** — the same category as the RBAC permission catalog. They must not go in `DatabaseSeeder`, which is dev-only and pulls in `CompanySeeder`'s Manila/Cebu demo company.

**Files:**
- Create: `backend/database/seeders/ProfileCatalogSeeder.php`
- Modify: `backend/app/Console/Commands/BootstrapAdmin.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/Seed/ProfileCatalogSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\EmployeeIdentificationCategory` (Task 3), `App\Models\Relationship` (Task 2).
- Produces: `Database\Seeders\ProfileCatalogSeeder` — idempotent, safe to re-run.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seed/ProfileCatalogSeederTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Database\Seeders\ProfileCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the eight identification kinds and five relationships', function (): void {
    $this->seed(ProfileCatalogSeeder::class);

    expect(EmployeeIdentificationCategory::query()->pluck('code')->sort()->values()->all())
        ->toBe(['BANK', 'DL', 'HDMF', 'PASSPORT', 'PHIC', 'PRC', 'SSS', 'TIN'])
        ->and(Relationship::query()->pluck('code')->sort()->values()->all())
        ->toBe(['child', 'other', 'parent', 'sibling', 'spouse']);
});

it('is idempotent — a second run creates nothing and duplicates nothing', function (): void {
    $this->seed(ProfileCatalogSeeder::class);
    $this->seed(ProfileCatalogSeeder::class);

    expect(EmployeeIdentificationCategory::query()->count())->toBe(8)
        ->and(Relationship::query()->count())->toBe(5);
});

it('is run by hris:bootstrap-admin so a fresh production database has the catalog', function (): void {
    $this->artisan('hris:bootstrap-admin', ['email' => 'admin@example.com'])
        ->assertSuccessful();

    expect(EmployeeIdentificationCategory::query()->count())->toBe(8)
        ->and(Relationship::query()->count())->toBe(5);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=ProfileCatalogSeederTest`
Expected: FAIL — `Class "Database\Seeders\ProfileCatalogSeeder" not found`.

- [ ] **Step 3: Write the seeder**

`backend/database/seeders/ProfileCatalogSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Illuminate\Database\Seeder;

/**
 * Catalog data PRODUCTION needs, in the same category as RbacSeeder's permission catalog —
 * which is why hris:bootstrap-admin calls this and DatabaseSeeder is not the only caller.
 * DatabaseSeeder is dev-only (it pairs RbacSeeder with CompanySeeder's Manila/Cebu demo
 * company, which must never touch production).
 *
 * Idempotent throughout: updateOrCreate on `code`, so re-running a bootstrap is safe.
 */
final class ProfileCatalogSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, description: string}> */
    private const array IDENTIFICATION_CATEGORIES = [
        ['code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer Identification Number'],
        ['code' => 'SSS', 'name' => 'SSS ID', 'description' => 'Social Security System number'],
        ['code' => 'HDMF', 'name' => 'Pag-IBIG MID', 'description' => 'Home Development Mutual Fund number'],
        ['code' => 'PHIC', 'name' => 'PhilHealth', 'description' => 'PhilHealth Identification Number'],
        ['code' => 'BANK', 'name' => 'Bank Account', 'description' => 'Payroll bank account number'],
        ['code' => 'PASSPORT', 'name' => 'Passport Number', 'description' => 'DFA-issued passport number'],
        ['code' => 'DL', 'name' => "Driver's License", 'description' => 'LTO-issued licence number'],
        ['code' => 'PRC', 'name' => 'PRC License', 'description' => 'Professional Regulation Commission licence'],
    ];

    /** @var array<int, array{code: string, description: string}> */
    private const array RELATIONSHIPS = [
        ['code' => 'spouse', 'description' => 'Spouse'],
        ['code' => 'child', 'description' => 'Child'],
        ['code' => 'parent', 'description' => 'Parent'],
        ['code' => 'sibling', 'description' => 'Sibling'],
        ['code' => 'other', 'description' => 'Other'],
    ];

    public function run(): void
    {
        foreach (self::IDENTIFICATION_CATEGORIES as $row) {
            EmployeeIdentificationCategory::query()->updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => $row['description']],
            );
        }

        foreach (self::RELATIONSHIPS as $row) {
            Relationship::query()->updateOrCreate(
                ['code' => $row['code']],
                ['description' => $row['description']],
            );
        }
    }
}
```

- [ ] **Step 4: Call it from the bootstrap command**

In `backend/app/Console/Commands/BootstrapAdmin.php`, add `use Database\Seeders\ProfileCatalogSeeder;` and, immediately after the existing `RbacSeeder` call:

```php
        // The profile catalog is production configuration too, exactly like the permission
        // catalog above — an HR Admin cannot record a TIN against a category that does not
        // exist, and no UI creates categories. Idempotent, so re-running is safe.
        $this->callSilent('db:seed', ['--class' => ProfileCatalogSeeder::class, '--force' => true]);
```

- [ ] **Step 5: Call it from `DatabaseSeeder` too**

In `backend/database/seeders/DatabaseSeeder.php`, add `ProfileCatalogSeeder::class` to the `$this->call([...])` list **between** `RbacSeeder::class` and `CompanySeeder::class`, so dev databases get the catalog before the demo company is built on top of it.

- [ ] **Step 6: Run the test**

Run: `cd backend && ./vendor/bin/pest --filter=ProfileCatalogSeederTest`
Expected: PASS — all three cases green.

- [ ] **Step 7: Run the full suite**

Run: `make test-backend`
Expected: PASS — in particular the existing bootstrap-admin tests under `tests/Feature/System/`, which must not regress.

- [ ] **Step 8: Commit**

```bash
git add backend/database/seeders/ backend/app/Console/Commands/BootstrapAdmin.php \
        backend/tests/Feature/Seed/ProfileCatalogSeederTest.php
git commit -m "M10a: profile catalog seeder, wired into bootstrap-admin"
```

---

### Task 6: Authorization — three profile abilities on `EmployeePolicy`

The subtlety that makes this its own task: `EmployeeScope` composes self + direct reports + HR offices **additively**, so a manager *is inside the scope of their own report*. An `inScope` test therefore cannot tell a manager apart from an HR Admin — and the manager must get the redacted view. The full-read check must consult the `hr_admin_offices` pivot directly.

`Gate::policy(Employee::class, EmployeePolicy::class)` is already registered in `AppServiceProvider:42`, so these abilities need no new registration. `Gate::before` short-circuits every check for a system admin.

**Files:**
- Modify: `backend/app/Policies/EmployeePolicy.php`
- Test: `backend/tests/Feature/Profile/ProfilePolicyTest.php`

**Interfaces:**
- Produces three abilities on `EmployeePolicy`, all `(User $user, Employee $employee): bool`:
  - `viewFullProfile` — self, or `employee.pii.edit` + the employee's office in the actor's `hr_admin_offices`
  - `viewRedactedProfile` — `EmployeeScope::visibleTo($user)` contains the employee (catches the manager)
  - `updateProfile` — same as `viewFullProfile` **minus** the self branch

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/ProfilePolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** An HR Admin scoped to one office: the role (verbs) plus the pivot (scope). */
function hrAdminFor(Office $office): User
{
    $user = User::factory()->create();
    $user->assignRole('HR Admin');
    $user->hrAdminOffices()->attach($office->id);

    return $user->fresh();
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->cebu = Office::factory()->create(['code' => 'CEB']);
    $this->manila = Office::factory()->create(['code' => 'MNL']);

    $this->subject = Employee::factory()->create(['current_office_id' => $this->cebu->id]);
});

it('lets an employee read their own profile in full but not edit it', function (): void {
    $self = User::factory()->create();
    $this->subject->update(['user_id' => $self->id]);

    expect($self->fresh()->can('viewFullProfile', $this->subject->fresh()))->toBeTrue()
        ->and($self->fresh()->can('updateProfile', $this->subject->fresh()))->toBeFalse();
});

it('lets an in-scope HR Admin read in full and edit', function (): void {
    $hr = hrAdminFor($this->cebu);

    expect($hr->can('viewFullProfile', $this->subject))->toBeTrue()
        ->and($hr->can('updateProfile', $this->subject))->toBeTrue();
});

it('denies an HR Admin of a different office entirely', function (): void {
    $hr = hrAdminFor($this->manila);

    expect($hr->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($hr->can('updateProfile', $this->subject))->toBeFalse()
        ->and($hr->can('viewRedactedProfile', $this->subject))->toBeFalse();
});

it('gives a manager the redacted view only, never the full one', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->cebu->id,
    ]);
    $this->subject->update(['current_reports_to_id' => $manager->id]);

    $managerUser = $managerUser->fresh();

    expect($managerUser->can('viewRedactedProfile', $this->subject->fresh()))->toBeTrue()
        ->and($managerUser->can('viewFullProfile', $this->subject->fresh()))->toBeFalse()
        ->and($managerUser->can('updateProfile', $this->subject->fresh()))->toBeFalse();
});

// The consequence spelled out in the spec: authority follows the office pivot, not the org
// chart. An HR Admin of Cebu who manages someone in Manila gets the REDACTED view of that
// report — being their manager does not widen HR authority across offices.
it('denies full read to an HR Admin managing a report in an office they do not administer', function (): void {
    $hrUser = hrAdminFor($this->cebu);
    $hrEmployee = Employee::factory()->create([
        'user_id' => $hrUser->id,
        'current_office_id' => $this->cebu->id,
    ]);

    $manilaReport = Employee::factory()->create([
        'current_office_id' => $this->manila->id,
        'current_reports_to_id' => $hrEmployee->id,
    ]);

    $hrUser = $hrUser->fresh();

    expect($hrUser->can('viewRedactedProfile', $manilaReport))->toBeTrue()
        ->and($hrUser->can('viewFullProfile', $manilaReport))->toBeFalse()
        ->and($hrUser->can('updateProfile', $manilaReport))->toBeFalse();
});

it('denies a stranger everything', function (): void {
    $stranger = User::factory()->create();

    expect($stranger->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($stranger->can('viewRedactedProfile', $this->subject))->toBeFalse()
        ->and($stranger->can('updateProfile', $this->subject))->toBeFalse();
});

it('grants a system admin everything through Gate::before', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);

    expect($admin->can('viewFullProfile', $this->subject))->toBeTrue()
        ->and($admin->can('viewRedactedProfile', $this->subject))->toBeTrue()
        ->and($admin->can('updateProfile', $this->subject))->toBeTrue();
});

// Scope without the verb is not enough: an HR-Admin pivot row with no role grants nothing.
it('denies an actor holding the office pivot but not the permission', function (): void {
    $user = User::factory()->create();
    $user->hrAdminOffices()->attach($this->cebu->id);

    expect($user->fresh()->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($user->fresh()->can('updateProfile', $this->subject))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=ProfilePolicyTest`
Expected: FAIL — every `can('viewFullProfile', …)` returns `false`, because Laravel denies an ability the policy does not define. The self, HR-Admin, and system-admin cases go red; the "denies" cases pass vacuously.

- [ ] **Step 3: Add the three abilities**

In `backend/app/Policies/EmployeePolicy.php`, add these three methods after `update()` and the private helper after `inScope()`:

```php
    /**
     * The full personnel file, including national IDs and dependents.
     *
     * Self, or an HR Admin who administers THIS employee's office. Deliberately not
     * `inScope()`: EmployeeScope composes self + direct reports + HR offices additively, so
     * a manager is inside their own report's scope — and a manager must get the redacted
     * view. The HR branch therefore reads the hr_admin_offices pivot directly.
     */
    public function viewFullProfile(User $user, Employee $employee): bool
    {
        if ($user->employee?->id === $employee->id) {
            return true;
        }

        return $this->administersOfficeOf($user, $employee);
    }

    /**
     * Contact and assignment only. Anyone the scope already lets see this employee — which
     * is what admits the manager, and why this one IS `inScope()`.
     */
    public function viewRedactedProfile(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee);
    }

    /**
     * HR Admins configure; employees do not edit their own personal data (spec, decision 7).
     * Same check as viewFullProfile MINUS the self branch — that omission is the whole rule.
     */
    public function updateProfile(User $user, Employee $employee): bool
    {
        return $this->administersOfficeOf($user, $employee);
    }

    /**
     * The two axes together: the verb (`employee.pii.edit`, catalogued in RbacSeeder since
     * M2 and first read here) and the scope (an hr_admin_offices row covering this
     * employee's current office). An employee with no office yet is administered by nobody.
     */
    private function administersOfficeOf(User $user, Employee $employee): bool
    {
        if ($employee->current_office_id === null) {
            return false;
        }

        return $user->can('employee.pii.edit')
            && $user->hrAdminOffices()->whereKey($employee->current_office_id)->exists();
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest --filter=ProfilePolicyTest`
Expected: PASS — all eight cases green.

- [ ] **Step 5: Run the arch tests**

Run: `cd backend && ./vendor/bin/pest tests/Arch`
Expected: PASS — in particular `EmployeePolicy defines "can see" as EmployeeScope membership`, which still holds: `inScope()` is unchanged and still referenced by `viewRedactedProfile`.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Policies/EmployeePolicy.php backend/tests/Feature/Profile/ProfilePolicyTest.php
git commit -m "M10a: profile view/update abilities activating employee.pii.edit"
```

---

### Task 7: Read endpoints — full and redacted resources

Three GETs over one model, two resource shapes. The redacted shape is a **separate class**, not a conditional inside the full one, so that adding a field to the full resource cannot silently widen the manager's view.

**Files:**
- Create: `backend/app/Http/Resources/EmployeeProfileResource.php`
- Create: `backend/app/Http/Resources/EmployeeProfileSummaryResource.php`
- Create: `backend/app/Http/Controllers/Profile/ShowMyProfileController.php`
- Create: `backend/app/Http/Controllers/Admin/Profile/ShowController.php`
- Create: `backend/app/Http/Controllers/Employees/ShowProfileController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Profile/ShowProfileTest.php`

**Interfaces:**
- Consumes: `EmployeePolicy::viewFullProfile` / `viewRedactedProfile` (Task 6); `App\Models\EmployeeProfile`, `EmployeeDependent`, `EmployeeIdentification` (Tasks 1–3); `App\Domain\Employment\EmploymentResolver` (existing).
- Produces the JSON contract every later task and the whole frontend depends on:

```jsonc
// EmployeeProfileResource — GET /me/profile, GET /admin/employees/{id}/profile
{ "data": {
  "employee_id": "uuid", "employee_no": "2506366", "full_name": "Ken Daryl Austero Perez",
  "details":  { "salutation", "first_name", "middle_name", "last_name", "name_suffix", "nickname" },
  "contact":  { "home_address", "personal_email", "phone", "fax", "mobile", "emergency_contact" },
  "personal": { "gender", "birth_date", "age", "birthplace", "marital_status",
                "citizenship", "religion", "blood_type" },
  "assignment": { "designation", "business_unit", "reports_to", "employment_status",
                  "location", "region", "labor_type", "hired_at", "work_shift" },
  "dependents": [ { "id", "name", "relationship", "birth_date" } ],
  "identifications": [ { "id", "category_code", "category_name", "number",
                         "issued_on", "expires_on", "notes", "has_scan" } ]
}}

// EmployeeProfileSummaryResource — GET /employees/{id}/profile
{ "data": {
  "employee_id", "employee_no", "full_name",
  "contact": { "personal_email", "phone", "mobile" },   // no address
  "assignment": { …same nine fields… }
}}                                                       // no personal, dependents, identifications
```

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/ShowProfileTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\EmployeeProfile;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->office = Office::factory()->create(['code' => 'CEB', 'region' => 'VII']);

    $this->selfUser = User::factory()->create();
    $this->employee = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'employee_no' => '2506366',
        'first_name' => 'Ken Daryl',
        'middle_name' => 'Austero',
        'last_name' => 'Perez',
        'current_office_id' => $this->office->id,
    ]);

    EmployeeProfile::factory()->create([
        'employee_id' => $this->employee->id,
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City',
        'mobile' => '09166229187',
        'birth_date' => '2002-01-23',
        'religion' => 'Roman Catholic',
    ]);

    $tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
    EmployeeIdentification::query()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $tin->id,
        'number' => '653536955000',
    ]);
});

it('returns the full profile to the employee themself', function (): void {
    $this->actingAs($this->selfUser)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.employee_no', '2506366')
        ->assertJsonPath('data.details.nickname', 'KENPE')
        ->assertJsonPath('data.contact.mobile', '09166229187')
        ->assertJsonPath('data.personal.birth_date', '2002-01-23')
        ->assertJsonPath('data.personal.religion', 'Roman Catholic')
        ->assertJsonPath('data.assignment.region', 'VII')
        ->assertJsonPath('data.identifications.0.category_code', 'TIN')
        ->assertJsonPath('data.identifications.0.number', '653536955000')
        ->assertJsonPath('data.identifications.0.has_scan', false);
});

it('404s /me/profile for a user with no employee record', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/me/profile')
        ->assertNotFound();
});

it('returns an empty-but-valid profile when no profile row exists yet', function (): void {
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id, 'current_office_id' => $this->office->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.details.nickname', null)
        ->assertJsonPath('data.personal.age', null)
        ->assertJsonPath('data.dependents', [])
        ->assertJsonPath('data.identifications', []);
});

it('returns the full profile to an in-scope HR Admin', function (): void {
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($this->office->id);

    $this->actingAs($hr->fresh())
        ->getJson("/api/v1/admin/employees/{$this->employee->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '653536955000');
});

it('404s the admin profile read for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->getJson("/api/v1/admin/employees/{$this->employee->id}/profile")
        ->assertNotFound();
});

// The redaction contract. This is the test that must never be relaxed.
it('gives a manager contact and assignment but no address, personal block, or IDs', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->office->id,
    ]);
    $this->employee->update(['current_reports_to_id' => $manager->id]);

    $response = $this->actingAs($managerUser->fresh())
        ->getJson("/api/v1/employees/{$this->employee->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.contact.mobile', '09166229187')
        ->assertJsonPath('data.assignment.region', 'VII');

    $body = $response->json('data');

    expect($body)->not->toHaveKey('personal')
        ->and($body)->not->toHaveKey('dependents')
        ->and($body)->not->toHaveKey('identifications')
        ->and($body['contact'])->not->toHaveKey('home_address');

    // Belt and braces: the raw payload must not contain the TIN anywhere at all.
    expect($response->getContent())->not->toContain('653536955000')
        ->and($response->getContent())->not->toContain('Tagles Compound');
});

it('404s the redacted read for an unrelated employee', function (): void {
    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id]);

    $this->actingAs($stranger->fresh())
        ->getJson("/api/v1/employees/{$this->employee->id}/profile")
        ->assertNotFound();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=ShowProfileTest`
Expected: FAIL — 404 on every route (none are registered yet).

- [ ] **Step 3: Write the full resource**

`backend/app/Http/Resources/EmployeeProfileResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The whole personnel file — self and in-scope HR Admin only.
 *
 * Its counterpart EmployeeProfileSummaryResource is a SEPARATE class rather than a
 * conditional in here, so that adding a field to this resource can never silently widen
 * what a manager sees. See the M10a spec.
 *
 * @mixin Employee
 */
final class EmployeeProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $current = EmploymentResolver::on($this->resource, Carbon::today());

        return [
            'employee_id' => $this->id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,

            'details' => [
                'salutation' => $profile?->salutation,
                'first_name' => $this->first_name,
                'middle_name' => $this->middle_name,
                'last_name' => $this->last_name,
                'name_suffix' => $this->name_suffix,
                'nickname' => $profile?->nickname,
            ],

            'contact' => [
                'home_address' => $profile?->home_address,
                'personal_email' => $profile?->personal_email,
                'phone' => $profile?->phone,
                'fax' => $profile?->fax,
                'mobile' => $profile?->mobile,
                'emergency_contact' => $profile?->emergency_contact,
            ],

            'personal' => [
                'gender' => $profile?->gender?->value,
                'birth_date' => $profile?->birth_date?->toDateString(),
                'age' => $profile?->age,
                'birthplace' => $profile?->birthplace,
                'marital_status' => $profile?->marital_status?->value,
                'citizenship' => $profile?->citizenship,
                'religion' => $profile?->religion,
                'blood_type' => $profile?->blood_type?->value,
            ],

            'assignment' => EmployeeAssignmentPresenter::forEmployee($this->resource, $current),

            'dependents' => $this->dependents->map(fn ($dependent): array => [
                'id' => $dependent->id,
                'name' => $dependent->name,
                'relationship' => $dependent->relationship?->code,
                'birth_date' => $dependent->birth_date?->toDateString(),
            ])->values()->all(),

            'identifications' => $this->identifications->map(fn ($identification): array => [
                'id' => $identification->id,
                'category_code' => $identification->category?->code,
                'category_name' => $identification->category?->name,
                'number' => $identification->number,
                'issued_on' => $identification->issued_on?->toDateString(),
                'expires_on' => $identification->expires_on?->toDateString(),
                'notes' => $identification->notes,
                // Never a URL: the scan is an app-mediated stream. This flag only tells the
                // client whether the stream route will return something.
                'has_scan' => $identification->hasMedia('scan'),
            ])->values()->all(),
        ];
    }
}
```

- [ ] **Step 4: Write the shared assignment presenter**

Both resources render the identical nine-field assignment block, so it lives in one place. `backend/app/Http/Resources/EmployeeAssignmentPresenter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use Illuminate\Support\Carbon;

/**
 * The nine "Assignment" fields, rendered identically for the full and redacted resources —
 * one definition, so the two can never disagree about what an assignment looks like.
 *
 * Every value here is READ from existing M2/M4 tables; M10a writes none of them except
 * designation/labor_type (on employment_records) and region (on offices).
 */
final class EmployeeAssignmentPresenter
{
    public static function forEmployee(Employee $employee, ?EmploymentRecord $current): array
    {
        return [
            'designation' => $current?->designation,
            'business_unit' => $employee->currentDepartment?->name,
            'reports_to' => $employee->manager?->full_name,
            'employment_status' => $current?->employment_type,
            'location' => $employee->currentOffice?->name,
            'region' => $employee->currentOffice?->region,
            'labor_type' => $current?->labor_type,
            'hired_at' => $employee->hired_at?->toDateString(),
            'work_shift' => self::workShift($employee),
        ];
    }

    /**
     * The active schedule assignment's template name ("8:00 Am To 6:00 Pm - Rest Sat & Sun"),
     * falling back to the office default. Read-only: M10a never writes a schedule.
     */
    private static function workShift(Employee $employee): ?string
    {
        $assignment = $employee->scheduleAssignments()
            ->whereDate('effective_from', '<=', Carbon::today())
            ->orderByDesc('effective_from')
            ->with('shiftTemplate')
            ->first();

        return $assignment?->shiftTemplate?->name
            ?? $employee->currentOffice?->defaultShiftTemplate?->name;
    }
}
```

> **Before writing this file**, read `app/Models/ScheduleAssignment.php` and `app/Models/Office.php` and confirm the relation names `scheduleAssignments`, `shiftTemplate`, and `defaultShiftTemplate` exist with those exact names. If `Employee` has no `scheduleAssignments()` relation, add one (`hasMany(ScheduleAssignment::class)`) as part of this step. If the office's default-template relation is named differently (the column is `default_shift_template_id`), use the real name.

- [ ] **Step 5: Write the redacted resource**

`backend/app/Http/Resources/EmployeeProfileSummaryResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * What a MANAGER sees of a direct report: how to reach them and where they sit.
 *
 * Deliberately NOT a filtered EmployeeProfileResource. There is no `personal`, no
 * `dependents`, no `identifications`, and no `home_address` key — not a null one, no key at
 * all. A separate class means a new field added to the full resource cannot leak here by
 * default; someone has to come and add it on purpose.
 *
 * @mixin Employee
 */
final class EmployeeProfileSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;
        $current = EmploymentResolver::on($this->resource, Carbon::today());

        return [
            'employee_id' => $this->id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,

            'contact' => [
                'personal_email' => $profile?->personal_email,
                'phone' => $profile?->phone,
                'mobile' => $profile?->mobile,
            ],

            'assignment' => EmployeeAssignmentPresenter::forEmployee($this->resource, $current),
        ];
    }
}
```

- [ ] **Step 6: Write the three controllers**

`backend/app/Http/Controllers/Profile/ShowMyProfileController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Resources\EmployeeProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The employee's own personnel file. No policy call needed — "self" is the whole check, and
 * a user with no employee row has no profile to show (404, matching how /me renders a null
 * employee for a login-less sysadmin).
 */
final class ShowMyProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotFoundHttpException();
        }

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

`backend/app/Http/Controllers/Admin/Profile/ShowController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 404, never 403, for an out-of-scope employee — the id is in the URL, and a
 * 403-for-real/404-for-nonexistent split would let any authenticated user enumerate which
 * employee ids exist. Same discipline as ProvisionUserRequest. See docs/05-rbac.md.
 */
final class ShowController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        if ($request->user()->cannot('viewFullProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

`backend/app/Http/Controllers/Employees/ShowProfileController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Resources\EmployeeProfileSummaryResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The manager-facing read: contact plus assignment, nothing else.
 *
 * The gate call below is also what satisfies the arch rule "every Employees controller
 * references an authorization boundary" (tests/Arch/ConventionsTest.php) — a file in this
 * directory with neither an EmployeeScope reference nor a gate call fails CI.
 */
final class ShowProfileController
{
    public function __invoke(Request $request, Employee $employee): JsonResponse
    {
        if ($request->user()->cannot('viewRedactedProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $employee->load('profile');

        return EmployeeProfileSummaryResource::make($employee)->response();
    }
}
```

- [ ] **Step 7: Register the three routes**

In `backend/routes/api.php`, add the imports and then the routes.

Beside the other `/employees/{employee}/…` routes in the `auth:sanctum` group:

```php
        // The manager-facing redacted personnel file (M10a). Same prefix as the full read
        // under /admin below, deliberately different policy: contact and assignment only.
        Route::get('/employees/{employee}/profile', ShowProfileController::class);
```

Beside the other `/me/…` routes:

```php
        Route::get('/me/profile', ShowMyProfileController::class);
```

Inside the `admin` prefix group, after the existing employee routes:

```php
            // The personnel file (M10a). Unlike every other route in this group, these are
            // NOT is_system_admin-gated: the requirement is that HR ADMINS configure
            // profiles, so authorization runs through EmployeePolicy's viewFullProfile /
            // updateProfile, which pair the `employee.pii.edit` permission with the
            // hr_admin_offices pivot. Gate::before still grants a system admin everything.
            Route::get('/employees/{employee}/profile', ShowProfileAdminController::class);
```

Import it as `use App\Http\Controllers\Admin\Profile\ShowController as ShowProfileAdminController;` to avoid colliding with the other `ShowController` aliases already in the file.

- [ ] **Step 8: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest --filter=ShowProfileTest`
Expected: PASS — all seven cases green, including both redaction assertions.

- [ ] **Step 9: Run the arch tests**

Run: `cd backend && ./vendor/bin/pest tests/Arch`
Expected: PASS — `every Employees controller references an authorization boundary` must still pass, which the new `ShowProfileController`'s `cannot()` call satisfies.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Http/Resources/EmployeeProfile*.php \
        backend/app/Http/Resources/EmployeeAssignmentPresenter.php \
        backend/app/Http/Controllers/Profile backend/app/Http/Controllers/Admin/Profile \
        backend/app/Http/Controllers/Employees/ShowProfileController.php \
        backend/routes/api.php backend/tests/Feature/Profile/ShowProfileTest.php
git commit -m "M10a: profile read endpoints with full and redacted resources"
```

---

### Task 7b: The catalog read — `GET /profile/catalog`

Found during plan self-review: Tasks 9 and 10 take a `relationship_id` and a `category_id`, and **nothing tells the client what those ids are**. The form cannot render a relationship or ID-kind dropdown without this route. Small, but blocking — the write endpoints are unusable from a browser without it.

Not office-scoped and not admin-gated: this is a static, company-wide catalog with nothing sensitive in it, and any authenticated user rendering a profile screen needs it.

**Files:**
- Create: `backend/app/Http/Controllers/Profile/ShowCatalogController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Profile/ShowCatalogTest.php`

**Interfaces:**
- Consumes: `App\Models\Relationship` (Task 2), `App\Models\EmployeeIdentificationCategory` (Task 3).
- Produces: `GET /api/v1/profile/catalog` →

```jsonc
{ "data": {
  "relationships": [ { "id": "uuid", "code": "spouse", "description": "Spouse" } ],
  "identification_categories": [ { "id": "uuid", "code": "TIN", "name": "TIN", "description": "…" } ]
}}
```

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/ShowCatalogTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ProfileCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns both catalogs to any authenticated user', function (): void {
    $this->seed(ProfileCatalogSeeder::class);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/profile/catalog')
        ->assertOk()
        ->assertJsonCount(5, 'data.relationships')
        ->assertJsonCount(8, 'data.identification_categories')
        ->assertJsonPath('data.identification_categories.0.code', 'BANK');   // ordered by code
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/profile/catalog')->assertUnauthorized();
});

it('returns empty lists rather than failing on an unseeded database', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/profile/catalog')
        ->assertOk()
        ->assertJsonPath('data.relationships', [])
        ->assertJsonPath('data.identification_categories', []);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=ShowCatalogTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the controller**

`backend/app/Http/Controllers/Profile/ShowCatalogController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Illuminate\Http\JsonResponse;

/**
 * The two profile catalogs, for populating dropdowns. Deliberately not office-scoped and not
 * admin-gated: it is static company-wide reference data with nothing sensitive in it, and
 * every profile screen needs it to turn a relationship_id into a word.
 *
 * No Action class — this is a read with no domain behaviour, the same shape as the other
 * list-only controllers in this codebase.
 */
final class ShowCatalogController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'relationships' => Relationship::query()
                    ->orderBy('code')
                    ->get(['id', 'code', 'description'])
                    ->all(),
                'identification_categories' => EmployeeIdentificationCategory::query()
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'description'])
                    ->all(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `backend/routes/api.php`, in the plain `auth:sanctum` group beside `/me/profile`:

```php
        // Static reference data for the profile dropdowns — not scoped, not admin-gated.
        Route::get('/profile/catalog', ShowCatalogController::class);
```

- [ ] **Step 5: Run the test**

Run: `cd backend && ./vendor/bin/pest --filter=ShowCatalogTest`
Expected: PASS — all three cases green.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Profile/ShowCatalogController.php \
        backend/routes/api.php backend/tests/Feature/Profile/ShowCatalogTest.php
git commit -m "M10a: profile catalog read for relationship and ID-kind dropdowns"
```

---

### Task 8: Profile write — `PUT /admin/employees/{employee}/profile`

**Files:**
- Create: `backend/app/Actions/Profile/UpsertEmployeeProfileInput.php`
- Create: `backend/app/Actions/Profile/UpsertEmployeeProfile.php`
- Create: `backend/app/Http/Requests/Profile/UpsertProfileRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Profile/UpsertProfileController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Profile/UpsertProfileTest.php`

**Interfaces:**
- Consumes: `EmployeePolicy::updateProfile` (Task 6), `App\Models\EmployeeProfile` (Task 1), the enums (Task 1).
- Produces: `UpsertEmployeeProfile::execute(UpsertEmployeeProfileInput $in): EmployeeProfile`. Input fields, all `?string` except `$employeeId`: `employeeId`, `salutation`, `nickname`, `homeAddress`, `personalEmail`, `phone`, `fax`, `mobile`, `emergencyContact`, `gender`, `birthDate`, `birthplace`, `maritalStatus`, `citizenship`, `religion`, `bloodType`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/UpsertProfileTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->office = Office::factory()->create(['code' => 'CEB']);
    $this->employee = Employee::factory()->create(['current_office_id' => $this->office->id]);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr->hrAdminOffices()->attach($this->office->id);
    $this->hr = $this->hr->fresh();

    $this->payload = [
        'salutation' => 'Mr.',
        'nickname' => 'KENPE',
        'home_address' => 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
        'mobile' => '09166229187',
        'gender' => 'male',
        'birth_date' => '2002-01-23',
        'marital_status' => 'single',
        'citizenship' => 'Filipino',
        'religion' => 'Roman Catholic',
        'blood_type' => 'O+',
    ];
});

it('creates the profile row on first write', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk()
        ->assertJsonPath('data.details.nickname', 'KENPE')
        ->assertJsonPath('data.personal.blood_type', 'O+');

    expect(EmployeeProfile::query()->count())->toBe(1);
});

it('updates in place on a second write rather than creating a second row', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk();

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'nickname' => 'KEN'])
        ->assertOk()
        ->assertJsonPath('data.details.nickname', 'KEN');

    expect(EmployeeProfile::query()->count())->toBe(1);
});

it('rejects a value outside a closed set', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'blood_type' => 'Z+'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile",
            [...$this->payload, 'gender' => 'Male'])   // capitalised: not the backed value
        ->assertStatus(422);
});

it('accepts a fully empty payload — every profile field is optional', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", [])
        ->assertOk()
        ->assertJsonPath('data.details.nickname', null);
});

it('refuses an employee their own profile edit', function (): void {
    $self = User::factory()->create();
    $this->employee->update(['user_id' => $self->id]);

    $this->actingAs($self->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertNotFound();

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('404s for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertNotFound();
});

it('writes an activity log entry under the employee_profile log name', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/profile", $this->payload)
        ->assertOk();

    expect(Activity::query()->where('log_name', 'employee_profile')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=UpsertProfileTest`
Expected: FAIL — 405/404, the route does not exist.

- [ ] **Step 3: Write the Input DTO**

`backend/app/Actions/Profile/UpsertEmployeeProfileInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

/** Every field nullable: a personnel file is filled in over time, not in one sitting. */
final readonly class UpsertEmployeeProfileInput
{
    public function __construct(
        public string $employeeId,
        public ?string $salutation = null,
        public ?string $nickname = null,
        public ?string $homeAddress = null,
        public ?string $personalEmail = null,
        public ?string $phone = null,
        public ?string $fax = null,
        public ?string $mobile = null,
        public ?string $emergencyContact = null,
        public ?string $gender = null,          // Domain\Profile\Gender value
        public ?string $birthDate = null,       // 'YYYY-MM-DD'
        public ?string $birthplace = null,
        public ?string $maritalStatus = null,   // Domain\Profile\MaritalStatus value
        public ?string $citizenship = null,
        public ?string $religion = null,
        public ?string $bloodType = null,       // Domain\Profile\BloodType value
    ) {}
}
```

- [ ] **Step 4: Write the action**

`backend/app/Actions/Profile/UpsertEmployeeProfile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;

/**
 * Upsert rather than update: the 1:1 row does not exist until someone first fills the
 * personnel file in, and CreateEmployee deliberately does not pre-create an empty one.
 *
 * A PUT replaces the whole profile — an omitted field becomes null rather than keeping its
 * old value. That is what PUT means, and it keeps "clear this employee's fax number" from
 * needing its own endpoint.
 */
final class UpsertEmployeeProfile
{
    public function execute(UpsertEmployeeProfileInput $in): EmployeeProfile
    {
        return DB::transaction(fn (): EmployeeProfile => EmployeeProfile::query()->updateOrCreate(
            ['employee_id' => $in->employeeId],
            [
                'salutation' => $in->salutation,
                'nickname' => $in->nickname,
                'home_address' => $in->homeAddress,
                'personal_email' => $in->personalEmail,
                'phone' => $in->phone,
                'fax' => $in->fax,
                'mobile' => $in->mobile,
                'emergency_contact' => $in->emergencyContact,
                'gender' => $in->gender,
                'birth_date' => $in->birthDate,
                'birthplace' => $in->birthplace,
                'marital_status' => $in->maritalStatus,
                'citizenship' => $in->citizenship,
                'religion' => $in->religion,
                'blood_type' => $in->bloodType,
            ],
        ));
    }
}
```

- [ ] **Step 5: Write the FormRequest**

`backend/app/Http/Requests/Profile/UpsertProfileRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Domain\Profile\BloodType;
use App\Domain\Profile\Gender;
use App\Domain\Profile\MaritalStatus;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpsertProfileRequest extends FormRequest
{
    /**
     * NOT is_system_admin like its neighbours under /admin — the requirement is that HR
     * Admins configure profiles. Gate::before still short-circuits a system admin to true.
     */
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    /** 404, not 403 — the employee id is in the URL; see docs/05-rbac.md. */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'salutation' => ['nullable', 'string', 'max:16'],
            'nickname' => ['nullable', 'string', 'max:64'],
            'home_address' => ['nullable', 'string', 'max:512'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'fax' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            // Rule::enum matches the BACKED VALUE exactly — 'male', never 'Male'. That
            // strictness is the point: one spelling reaches the database.
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date_format:Y-m-d'],
            'birthplace' => ['nullable', 'string', 'max:255'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'citizenship' => ['nullable', 'string', 'max:64'],
            'religion' => ['nullable', 'string', 'max:64'],
            'blood_type' => ['nullable', Rule::enum(BloodType::class)],
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

`backend/app/Http/Controllers/Admin/Profile/UpsertProfileController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\UpsertEmployeeProfile;
use App\Actions\Profile\UpsertEmployeeProfileInput;
use App\Http\Requests\Profile\UpsertProfileRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class UpsertProfileController
{
    public function __invoke(UpsertProfileRequest $request, Employee $employee, UpsertEmployeeProfile $action): JsonResponse
    {
        $validated = $request->validated();

        $action->execute(new UpsertEmployeeProfileInput(
            employeeId: $employee->id,
            salutation: $validated['salutation'] ?? null,
            nickname: $validated['nickname'] ?? null,
            homeAddress: $validated['home_address'] ?? null,
            personalEmail: $validated['personal_email'] ?? null,
            phone: $validated['phone'] ?? null,
            fax: $validated['fax'] ?? null,
            mobile: $validated['mobile'] ?? null,
            emergencyContact: $validated['emergency_contact'] ?? null,
            gender: $validated['gender'] ?? null,
            birthDate: $validated['birth_date'] ?? null,
            birthplace: $validated['birthplace'] ?? null,
            maritalStatus: $validated['marital_status'] ?? null,
            citizenship: $validated['citizenship'] ?? null,
            religion: $validated['religion'] ?? null,
            bloodType: $validated['blood_type'] ?? null,
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

- [ ] **Step 7: Register the route**

In `backend/routes/api.php`, inside the `admin` group beside the profile read added in Task 7:

```php
            Route::put('/employees/{employee}/profile', UpsertProfileController::class);
```

- [ ] **Step 8: Run the test and the arch tests**

```bash
cd backend && ./vendor/bin/pest --filter=UpsertProfileTest && ./vendor/bin/pest tests/Arch
```
Expected: PASS — all seven cases green; arch green (the action is `final`, references no HTTP, and the controller is `final` + invokable).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Actions/Profile backend/app/Http/Requests/Profile \
        backend/app/Http/Controllers/Admin/Profile/UpsertProfileController.php \
        backend/routes/api.php backend/tests/Feature/Profile/UpsertProfileTest.php
git commit -m "M10a: HR-admin profile upsert endpoint"
```

---

### Task 9: Dependents write — `PUT /admin/employees/{employee}/dependents`

Replace-all, not per-row CRUD: a zero-to-five row list that nothing else references, so one route beats three plus the client-side id bookkeeping they require.

**Files:**
- Create: `backend/app/Actions/Profile/ReplaceEmployeeDependentsInput.php`
- Create: `backend/app/Actions/Profile/ReplaceEmployeeDependents.php`
- Create: `backend/app/Http/Requests/Profile/ReplaceDependentsRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Profile/ReplaceDependentsController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Profile/ReplaceDependentsTest.php`

**Interfaces:**
- Consumes: `EmployeePolicy::updateProfile`, `App\Models\EmployeeDependent`, `App\Models\Relationship`.
- Produces: `ReplaceEmployeeDependents::execute(ReplaceEmployeeDependentsInput $in): Collection` of `EmployeeDependent`. Input: `public string $employeeId`, `public array $dependents` — each element `['name' => string, 'relationship_id' => string, 'birth_date' => ?string]`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/ReplaceDependentsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeDependent;
use App\Models\Office;
use App\Models\Relationship;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->office = Office::factory()->create(['code' => 'CEB']);
    $this->employee = Employee::factory()->create(['current_office_id' => $this->office->id]);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr->hrAdminOffices()->attach($this->office->id);
    $this->hr = $this->hr->fresh();

    $this->spouse = Relationship::query()->create(['code' => 'spouse', 'description' => 'Spouse']);
    $this->child = Relationship::query()->create(['code' => 'child', 'description' => 'Child']);
});

it('creates the listed dependents', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Maria Perez', 'relationship_id' => $this->spouse->id, 'birth_date' => '2003-05-11'],
                ['name' => 'Juan Perez', 'relationship_id' => $this->child->id, 'birth_date' => '2024-02-02'],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(2);
});

it('replaces the whole list — removed dependents are gone', function (): void {
    EmployeeDependent::factory()->count(3)->create([
        'employee_id' => $this->employee->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Maria Perez', 'relationship_id' => $this->spouse->id],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(1)
        ->and(EmployeeDependent::query()->first()->name)->toBe('Maria Perez');
});

it('clears every dependent when given an empty list', function (): void {
    EmployeeDependent::factory()->count(2)->create([
        'employee_id' => $this->employee->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.dependents');

    expect(EmployeeDependent::query()->count())->toBe(0);
});

// The one that catches a naive `EmployeeDependent::truncate()` or a missing WHERE.
it('never touches another employee dependents', function (): void {
    $other = Employee::factory()->create(['current_office_id' => $this->office->id]);
    EmployeeDependent::factory()->count(2)->create([
        'employee_id' => $other->id,
        'relationship_id' => $this->child->id,
    ]);

    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertOk();

    expect(EmployeeDependent::query()->where('employee_id', $other->id)->count())->toBe(2);
});

it('rejects an unknown relationship id', function (): void {
    $this->actingAs($this->hr)
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", [
            'dependents' => [
                ['name' => 'Ghost', 'relationship_id' => '0199a000-0000-7000-8000-000000000000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

it('404s for an HR Admin of another office', function (): void {
    $other = Office::factory()->create(['code' => 'MNL']);
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr->hrAdminOffices()->attach($other->id);

    $this->actingAs($hr->fresh())
        ->putJson("/api/v1/admin/employees/{$this->employee->id}/dependents", ['dependents' => []])
        ->assertNotFound();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=ReplaceDependentsTest`
Expected: FAIL — route not registered.

- [ ] **Step 3: Write the Input DTO and action**

`backend/app/Actions/Profile/ReplaceEmployeeDependentsInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

final readonly class ReplaceEmployeeDependentsInput
{
    /** @param array<int, array{name: string, relationship_id: string, birth_date?: string|null}> $dependents */
    public function __construct(
        public string $employeeId,
        public array $dependents,
    ) {}
}
```

`backend/app/Actions/Profile/ReplaceEmployeeDependents.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeDependent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replace-all. A dependent list is short, owned entirely by HR, and referenced by nothing
 * else, so one PUT beats POST/PATCH/DELETE plus the id bookkeeping they force on the client.
 *
 * The delete is scoped by employee_id, never a truncate — another employee's dependents are
 * not this request's business.
 */
final class ReplaceEmployeeDependents
{
    /** @return Collection<int, EmployeeDependent> */
    public function execute(ReplaceEmployeeDependentsInput $in): Collection
    {
        return DB::transaction(function () use ($in): Collection {
            EmployeeDependent::query()->where('employee_id', $in->employeeId)->delete();

            return collect($in->dependents)->map(fn (array $row): EmployeeDependent => EmployeeDependent::query()->create([
                'employee_id' => $in->employeeId,
                'name' => $row['name'],
                'relationship_id' => $row['relationship_id'],
                'birth_date' => $row['birth_date'] ?? null,
            ]))->values();
        });
    }
}
```

- [ ] **Step 4: Write the FormRequest**

`backend/app/Http/Requests/Profile/ReplaceDependentsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReplaceDependentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            // `present` not `required`: an empty array is a legitimate instruction
            // ("this employee has no dependents"), and `required` rejects [].
            'dependents' => ['present', 'array', 'max:20'],
            'dependents.*.name' => ['required', 'string', 'max:255'],
            'dependents.*.relationship_id' => ['required', 'uuid', 'exists:relationships,id'],
            'dependents.*.birth_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

`backend/app/Http/Controllers/Admin/Profile/ReplaceDependentsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\ReplaceEmployeeDependents;
use App\Actions\Profile\ReplaceEmployeeDependentsInput;
use App\Http\Requests\Profile\ReplaceDependentsRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class ReplaceDependentsController
{
    public function __invoke(ReplaceDependentsRequest $request, Employee $employee, ReplaceEmployeeDependents $action): JsonResponse
    {
        $action->execute(new ReplaceEmployeeDependentsInput(
            employeeId: $employee->id,
            dependents: $request->validated()['dependents'],
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

- [ ] **Step 6: Register the route**

In `backend/routes/api.php`, inside the `admin` group:

```php
            Route::put('/employees/{employee}/dependents', ReplaceDependentsController::class);
```

- [ ] **Step 7: Run the test and the arch tests**

```bash
cd backend && ./vendor/bin/pest --filter=ReplaceDependentsTest && ./vendor/bin/pest tests/Arch
```
Expected: PASS — all six cases green.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Actions/Profile backend/app/Http/Requests/Profile \
        backend/app/Http/Controllers/Admin/Profile/ReplaceDependentsController.php \
        backend/routes/api.php backend/tests/Feature/Profile/ReplaceDependentsTest.php
git commit -m "M10a: replace-all dependents endpoint"
```

---

### Task 10: Identifications — save, delete, and the scan stream

Three routes. The save is a **`POST`, not a `PUT`**, despite being an upsert: PHP parses a multipart body only on `POST`. A `PUT multipart/form-data` arrives with an empty `$_FILES` and the scan silently vanishes — Laravel's `_method` spoofing exists precisely because of this.

**Files:**
- Create: `backend/app/Actions/Profile/SaveEmployeeIdentificationInput.php`
- Create: `backend/app/Actions/Profile/SaveEmployeeIdentification.php`
- Create: `backend/app/Actions/Profile/DeleteEmployeeIdentificationInput.php`
- Create: `backend/app/Actions/Profile/DeleteEmployeeIdentification.php`
- Create: `backend/app/Http/Requests/Profile/SaveIdentificationRequest.php`
- Create: `backend/app/Http/Requests/Profile/DeleteIdentificationRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Profile/SaveIdentificationController.php`
- Create: `backend/app/Http/Controllers/Admin/Profile/DeleteIdentificationController.php`
- Create: `backend/app/Http/Controllers/Employees/DownloadScanController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Profile/IdentificationEndpointsTest.php`

**Interfaces:**
- Consumes: `EmployeePolicy::updateProfile` / `viewFullProfile` (Task 6), `App\Models\EmployeeIdentification` with its `scan` collection (Task 3).
- Produces: `SaveEmployeeIdentification::execute(SaveEmployeeIdentificationInput $in): EmployeeIdentification` — Input carries `employeeId`, `categoryId`, `number`, `?issuedOn`, `?expiresOn`, `?notes`, `?UploadedFile $scan`. `DeleteEmployeeIdentification::execute(DeleteEmployeeIdentificationInput $in): void` — Input carries `identificationId`.

> **Note on the action-purity arch rule:** actions may not use `Illuminate\Http`. `UploadedFile` lives in `Illuminate\Http\UploadedFile`, so **run `cd backend && ./vendor/bin/pest tests/Arch` immediately after Step 3** and, if the rule rejects it, type the property as `Symfony\Component\HttpFoundation\File\UploadedFile` (Laravel's class extends it) or `?string $scanPath`. Check how `App\Actions\Attendance\SubmitAdjustment` already handles its `attachment` parameter and copy that exact approach — it solved this problem in M3.6.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Profile/IdentificationEndpointsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    Storage::fake('attachments');

    $this->office = Office::factory()->create(['code' => 'CEB']);

    $this->selfUser = User::factory()->create();
    $this->employee = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'current_office_id' => $this->office->id,
    ]);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr->hrAdminOffices()->attach($this->office->id);
    $this->hr = $this->hr->fresh();

    $this->tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
});

it('creates an identification with a scan', function (): void {
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '653536955000',
            'issued_on' => '2020-03-01',
            'scan' => UploadedFile::fake()->create('tin.pdf', 20, 'application/pdf'),
        ])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '653536955000')
        ->assertJsonPath('data.identifications.0.has_scan', true);

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('upserts on category rather than creating a duplicate', function (): void {
    $payload = ['category_id' => $this->tin->id, 'number' => '111111111111'];

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", $payload)
        ->assertOk();

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications",
            [...$payload, 'number' => '222222222222'])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '222222222222');

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('keeps the existing scan when an upsert omits the file', function (): void {
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
            'scan' => UploadedFile::fake()->create('tin.pdf', 20, 'application/pdf'),
        ])->assertOk();

    // Second write, number only — the scan must survive rather than being cleared.
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '222222222222',
        ])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.has_scan', true);
});

it('rejects a scan that is not a pdf or image', function (): void {
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
            'scan' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

it('deletes an identification', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/employees/{$this->employee->id}/identifications/{$identification->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.identifications');

    expect(EmployeeIdentification::query()->count())->toBe(0);
});

it('404s deleting an identification belonging to a different employee', function (): void {
    $other = Employee::factory()->create(['current_office_id' => $this->office->id]);
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $other->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/employees/{$this->employee->id}/identifications/{$identification->id}")
        ->assertNotFound();

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('streams the scan to the employee themself', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);
    $identification->addMedia(UploadedFile::fake()->create('tin.pdf', 20, 'application/pdf'))
        ->toMediaCollection('scan');

    $this->actingAs($this->selfUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertOk();
});

it('404s the scan stream for a manager, who never sees identifications at all', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->office->id,
    ]);
    $this->employee->update(['current_reports_to_id' => $manager->id]);

    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);
    $identification->addMedia(UploadedFile::fake()->create('tin.pdf', 20, 'application/pdf'))
        ->toMediaCollection('scan');

    $this->actingAs($managerUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertNotFound();
});

it('404s the scan stream when the identification carries no scan', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->selfUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertNotFound();
});

it('refuses an employee writing their own identifications', function (): void {
    $this->actingAs($this->selfUser->fresh())
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
        ])
        ->assertNotFound();

    expect(EmployeeIdentification::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && ./vendor/bin/pest --filter=IdentificationEndpointsTest`
Expected: FAIL — routes not registered.

- [ ] **Step 3: Write the two Input DTOs and two actions**

`backend/app/Actions/Profile/SaveEmployeeIdentificationInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `$scan` is typed against the SYMFONY UploadedFile, not Illuminate's, because the arch
 * rule bars App\Actions from using Illuminate\Http. Laravel's UploadedFile extends this
 * one, so a controller passes its own instance straight through.
 */
final readonly class SaveEmployeeIdentificationInput
{
    public function __construct(
        public string $employeeId,
        public string $categoryId,
        public string $number,
        public ?string $issuedOn = null,
        public ?string $expiresOn = null,
        public ?string $notes = null,
        public ?UploadedFile $scan = null,
    ) {}
}
```

`backend/app/Actions/Profile/SaveEmployeeIdentification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeIdentification;
use Illuminate\Support\Facades\DB;

/**
 * Upsert on (employee_id, category_id) — the unique index the M10a schema carries, because
 * one employee has one TIN. A second write for the same category corrects the number rather
 * than adding a row.
 *
 * A null $scan LEAVES the existing scan alone; it does not clear it. Clearing a scan is
 * deleting the identification, which has its own route — "I only came to fix a typo in the
 * number" must never silently destroy the evidence HR is expected to produce.
 */
final class SaveEmployeeIdentification
{
    public function execute(SaveEmployeeIdentificationInput $in): EmployeeIdentification
    {
        return DB::transaction(function () use ($in): EmployeeIdentification {
            $identification = EmployeeIdentification::query()->updateOrCreate(
                ['employee_id' => $in->employeeId, 'category_id' => $in->categoryId],
                [
                    'number' => $in->number,
                    'issued_on' => $in->issuedOn,
                    'expires_on' => $in->expiresOn,
                    'notes' => $in->notes,
                ],
            );

            if ($in->scan !== null) {
                // singleFile() on the collection replaces any previous scan.
                $identification->addMedia($in->scan)->toMediaCollection('scan');
            }

            return $identification->fresh();
        });
    }
}
```

`backend/app/Actions/Profile/DeleteEmployeeIdentificationInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

final readonly class DeleteEmployeeIdentificationInput
{
    public function __construct(
        public string $identificationId,
    ) {}
}
```

`backend/app/Actions/Profile/DeleteEmployeeIdentification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\EmployeeIdentification;
use Illuminate\Support\Facades\DB;

/**
 * Deleting the model deletes its media through medialibrary's own model observer, so the
 * scan does not linger in RustFS as an orphan object nothing can reach.
 */
final class DeleteEmployeeIdentification
{
    public function execute(DeleteEmployeeIdentificationInput $in): void
    {
        DB::transaction(function () use ($in): void {
            EmployeeIdentification::query()->findOrFail($in->identificationId)->delete();
        });
    }
}
```

- [ ] **Step 4: Run the arch tests before going further**

Run: `cd backend && ./vendor/bin/pest tests/Arch`
Expected: PASS. If `App\Actions` is flagged for using `Symfony\Component\HttpFoundation`, read `app/Actions/Attendance/SubmitAdjustment.php` and copy however it types its `attachment` parameter — that file already solved this exact problem in M3.6.

- [ ] **Step 5: Write the two FormRequests**

`backend/app/Http/Requests/Profile/SaveIdentificationRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SaveIdentificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', 'exists:employee_identification_categories,id'],
            'number' => ['required', 'string', 'max:64'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:512'],
            // Same limits as Request's attachment collection (SubmitAdjustmentRequest).
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
```

`backend/app/Http/Requests/Profile/DeleteIdentificationRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteIdentificationRequest extends FormRequest
{
    /**
     * Two checks, not one: the actor may edit THIS employee, AND the identification in the
     * URL actually belongs to that employee. Without the second, an authorized HR Admin
     * could delete any identification in the system by pairing it with an employee they do
     * administer.
     */
    public function authorize(): bool
    {
        $employee = $this->route('employee');
        $identification = $this->route('identification');

        if (! $employee instanceof Employee || ! $identification instanceof EmployeeIdentification) {
            return false;
        }

        return $identification->employee_id === $employee->id
            && $this->user()?->can('updateProfile', $employee) === true;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 6: Write the three controllers**

`backend/app/Http/Controllers/Admin/Profile/SaveIdentificationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\SaveEmployeeIdentification;
use App\Actions\Profile\SaveEmployeeIdentificationInput;
use App\Http\Requests\Profile\SaveIdentificationRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

final class SaveIdentificationController
{
    public function __invoke(SaveIdentificationRequest $request, Employee $employee, SaveEmployeeIdentification $action): JsonResponse
    {
        $validated = $request->validated();

        $action->execute(new SaveEmployeeIdentificationInput(
            employeeId: $employee->id,
            categoryId: (string) $validated['category_id'],
            number: (string) $validated['number'],
            issuedOn: $validated['issued_on'] ?? null,
            expiresOn: $validated['expires_on'] ?? null,
            notes: $validated['notes'] ?? null,
            scan: $request->file('scan'),
        ));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

`backend/app/Http/Controllers/Admin/Profile/DeleteIdentificationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Profile;

use App\Actions\Profile\DeleteEmployeeIdentification;
use App\Actions\Profile\DeleteEmployeeIdentificationInput;
use App\Http\Requests\Profile\DeleteIdentificationRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Http\JsonResponse;

final class DeleteIdentificationController
{
    public function __invoke(
        DeleteIdentificationRequest $request,
        Employee $employee,
        EmployeeIdentification $identification,
        DeleteEmployeeIdentification $action,
    ): JsonResponse {
        $action->execute(new DeleteEmployeeIdentificationInput(identificationId: $identification->id));

        $employee->load(['profile', 'dependents.relationship', 'identifications.category', 'identifications.media']);

        return EmployeeProfileResource::make($employee)->response();
    }
}
```

`backend/app/Http/Controllers/Employees/DownloadScanController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A private, app-mediated stream — never a public or presigned object URL. RustFS publishes
 * no ports in production precisely because every attachment goes out through here.
 *
 * Gated on viewFullProfile, NOT viewRedactedProfile: a manager gets the redacted resource,
 * which never hands them an identification id, so a manager reaching this route at all is
 * either a guess or an attack. Same 404-for-everything shape as DownloadAttachmentController.
 */
final class DownloadScanController
{
    public function __invoke(Request $request, Employee $employee, EmployeeIdentification $identification): StreamedResponse
    {
        if ($identification->employee_id !== $employee->id) {
            throw new NotFoundHttpException();
        }

        if ($request->user()->cannot('viewFullProfile', $employee)) {
            throw new NotFoundHttpException();
        }

        $media = $identification->getFirstMedia('scan');

        if ($media === null) {
            throw new NotFoundHttpException();
        }

        return $media->toResponse($request);
    }
}
```

- [ ] **Step 7: Register the three routes**

In `backend/routes/api.php`, inside the `admin` group:

```php
            // POST, not PUT, despite being an upsert: PHP parses a multipart body only on
            // POST. A PUT multipart/form-data arrives with an empty $_FILES and the scan
            // vanishes silently. See the M10a spec.
            Route::post('/employees/{employee}/identifications', SaveIdentificationController::class);
            Route::delete('/employees/{employee}/identifications/{identification}', DeleteIdentificationController::class);
```

And beside the other `/employees/{employee}/…` routes in the plain `auth:sanctum` group:

```php
            Route::get('/employees/{employee}/identifications/{identification}/scan', DownloadScanController::class);
```

- [ ] **Step 8: Run the test and the arch tests**

```bash
cd backend && ./vendor/bin/pest --filter=IdentificationEndpointsTest && ./vendor/bin/pest tests/Arch
```
Expected: PASS — all ten cases green.

- [ ] **Step 9: Run the whole backend suite**

Run: `make test-backend`
Expected: PASS — every pre-existing test still green.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Actions/Profile backend/app/Http/Requests/Profile \
        backend/app/Http/Controllers/Admin/Profile backend/app/Http/Controllers/Employees \
        backend/routes/api.php backend/tests/Feature/Profile/IdentificationEndpointsTest.php
git commit -m "M10a: identification save, delete, and app-mediated scan stream"
```

---

### Task 11: The consolidated six-actor × eight-route matrix

Tasks 7–10 each tested their own route's authorization. This task adds the single table-driven test that proves the *whole surface* agrees — the file a reviewer opens to answer "who can do what" without reading eight test files, and the one that fails when a new route is added without a policy.

`GET /profile/catalog` (Task 7b) is **deliberately excluded**: it is ungated static reference data, so every actor is allowed and a row of eight `true`s would only add noise. `ShowCatalogTest` covers it.

**Files:**
- Create: `backend/tests/Feature/Profile/ProfileScopeMatrixTest.php`
- Test: itself

**Interfaces:**
- Consumes: every route from Tasks 7–10 and every ability from Task 6.

- [ ] **Step 1: Write the matrix test**

Create `backend/tests/Feature/Profile/ProfileScopeMatrixTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Six actors against all eight M10a routes. Every expectation below is a deliberate policy
 * decision from the spec, not an observation of what the code happens to do — if one of
 * these flips, the authorization contract changed and the spec needs updating too.
 *
 * 404 is the correct denial everywhere: the employee id is in the URL, and a
 * 403-for-real / 404-for-nonexistent split lets any authenticated user enumerate employee
 * ids. See docs/05-rbac.md.
 */
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->cebu = Office::factory()->create(['code' => 'CEB']);
    $this->manila = Office::factory()->create(['code' => 'MNL']);

    // The subject: a Cebu employee with a login, one identification, and a manager.
    $this->selfUser = User::factory()->create();
    $this->subject = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'current_office_id' => $this->cebu->id,
    ]);

    $this->category = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
    $this->identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->subject->id,
        'category_id' => $this->category->id,
        'number' => '653536955000',
    ]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->cebu->id,
    ]);
    $this->subject->update(['current_reports_to_id' => $manager->id]);

    $hrIn = User::factory()->create();
    $hrIn->assignRole('HR Admin');
    $hrIn->hrAdminOffices()->attach($this->cebu->id);

    $hrOut = User::factory()->create();
    $hrOut->assignRole('HR Admin');
    $hrOut->hrAdminOffices()->attach($this->manila->id);

    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id, 'current_office_id' => $this->manila->id]);

    $this->actors = [
        'self' => $this->selfUser->fresh(),
        'manager' => $managerUser->fresh(),
        'hr-in-scope' => $hrIn->fresh(),
        'hr-out-of-scope' => $hrOut->fresh(),
        'stranger' => $stranger->fresh(),
        'system-admin' => User::factory()->create(['is_system_admin' => true]),
    ];
});

/**
 * @return array<string, array{method: string, uri: string, payload: array<string, mixed>}>
 */
function m10aRoutes(string $employeeId, string $identificationId, string $categoryId): array
{
    return [
        'GET /me/profile' => ['method' => 'getJson', 'uri' => '/api/v1/me/profile', 'payload' => []],
        'GET /admin/…/profile' => ['method' => 'getJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/profile", 'payload' => []],
        'GET /employees/…/profile' => ['method' => 'getJson', 'uri' => "/api/v1/employees/{$employeeId}/profile", 'payload' => []],
        'PUT /admin/…/profile' => ['method' => 'putJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/profile", 'payload' => ['nickname' => 'X']],
        'PUT /admin/…/dependents' => ['method' => 'putJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/dependents", 'payload' => ['dependents' => []]],
        'POST /admin/…/identifications' => ['method' => 'postJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/identifications", 'payload' => ['category_id' => $categoryId, 'number' => '1']],
        'DELETE /admin/…/identifications/{id}' => ['method' => 'deleteJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/identifications/{$identificationId}", 'payload' => []],
        'GET /employees/…/scan' => ['method' => 'getJson', 'uri' => "/api/v1/employees/{$employeeId}/identifications/{$identificationId}/scan", 'payload' => []],
    ];
}

it('enforces the documented matrix across every M10a route', function (): void {
    // true = allowed (2xx), false = denied (404). The scan route is 404 for everyone here
    // because the fixture identification carries no media — the AUTHORIZED actors still
    // 404, which is why it is listed false for them too; IdentificationEndpointsTest proves
    // the authorized-with-media case separately.
    $expected = [
        'self' => [
            'GET /me/profile' => true,
            'GET /admin/…/profile' => true,          // self branch of viewFullProfile
            'GET /employees/…/profile' => true,      // self is inside EmployeeScope
            'PUT /admin/…/profile' => false,         // employees do not edit their own PII
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,        // no media on the fixture
        ],
        'manager' => [
            'GET /me/profile' => true,               // their OWN profile, not the subject's
            'GET /admin/…/profile' => false,         // redacted only
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'hr-in-scope' => [
            'GET /me/profile' => false,              // this HR user has no employee row
            'GET /admin/…/profile' => true,
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => true,
            'PUT /admin/…/dependents' => true,
            'POST /admin/…/identifications' => true,
            'DELETE /admin/…/identifications/{id}' => true,
            'GET /employees/…/scan' => false,
        ],
        'hr-out-of-scope' => [
            'GET /me/profile' => false,
            'GET /admin/…/profile' => false,
            'GET /employees/…/profile' => false,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'stranger' => [
            'GET /me/profile' => true,               // their own, empty profile
            'GET /admin/…/profile' => false,
            'GET /employees/…/profile' => false,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'system-admin' => [
            'GET /me/profile' => false,              // no employee row; /me/profile 404s
            'GET /admin/…/profile' => true,
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => true,
            'PUT /admin/…/dependents' => true,
            'POST /admin/…/identifications' => true,
            'DELETE /admin/…/identifications/{id}' => true,
            'GET /employees/…/scan' => false,
        ],
    ];

    $failures = [];

    foreach ($expected as $actorName => $routeExpectations) {
        foreach ($routeExpectations as $routeName => $allowed) {
            // Rebuild the fixtures between destructive calls so DELETE in one row does not
            // change what a later row is testing.
            $this->refreshApplication();
            $this->seed(RbacSeeder::class);

            $routes = m10aRoutes($this->subject->id, $this->identification->id, $this->category->id);
            $route = $routes[$routeName];
            $method = $route['method'];

            $response = $this->actingAs($this->actors[$actorName])
                ->{$method}($route['uri'], $route['payload']);

            $succeeded = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

            if ($succeeded !== $allowed) {
                $failures[] = sprintf(
                    '%s -> %s: expected %s, got HTTP %d',
                    $actorName,
                    $routeName,
                    $allowed ? 'allowed' : 'denied',
                    $response->getStatusCode(),
                );
            }
        }
    }

    expect($failures)->toBe([]);
});
```

> **Note on `refreshApplication()` inside the loop:** if it proves unreliable under `RefreshDatabase` (the database transaction and the app instance are separately managed), replace the loop body's reset with a plain re-seed of the mutable fixtures — recreate `$this->identification` before each iteration — and drop the `refreshApplication()` call. The matrix is the deliverable; the isolation mechanism is not. Run the test both ways and keep whichever is green **and** still fails when you temporarily flip one `true` to `false`.

- [ ] **Step 2: Run the test**

Run: `cd backend && ./vendor/bin/pest --filter=ProfileScopeMatrixTest`
Expected: PASS.

- [ ] **Step 3: Verify the test can actually fail**

Temporarily change `'manager' => ['GET /admin/…/profile' => false, …]` to `true`, re-run, and confirm the test **fails** with a message naming that cell. Then change it back. A matrix that passes no matter what is worse than no matrix.

- [ ] **Step 4: Run the whole suite**

Run: `make test-backend`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Feature/Profile/ProfileScopeMatrixTest.php
git commit -m "M10a: six-actor scope matrix across every profile route"
```

---

### Task 12: Frontend API client and query keys

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Modify: `frontend/web/src/lib/keys.ts`
- Create: `frontend/web/src/lib/authedBlobUrl.ts`
- Modify: `frontend/web/src/components/domain/RequestCard.tsx`
- Test: `frontend/web/src/lib/authedBlobUrl.test.ts`

**Interfaces:**
- Consumes: the JSON contract defined in Task 7.
- Produces:
  - `export type EmployeeProfile` / `EmployeeProfileSummary` / `ProfileDependent` / `ProfileIdentification` in `api.ts`
  - `api.profile.mine()`, `api.profile.forEmployee(id)`, `api.profile.redacted(id)`, `api.profile.save(id, body)`, `api.profile.saveDependents(id, dependents)`, `api.profile.saveIdentification(id, form)`, `api.profile.deleteIdentification(id, identificationId)`
  - `keys.profile.mine()`, `keys.profile.forEmployee(id)`
  - `authedBlobUrl(path: string): Promise<string>`

- [ ] **Step 1: Write the failing test for the extracted helper**

Create `frontend/web/src/lib/authedBlobUrl.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest'

import { authedBlobUrl } from './authedBlobUrl'
import { setToken, clearToken } from './session'

afterEach(() => {
  clearToken()
  vi.unstubAllGlobals()
})

describe('authedBlobUrl', () => {
  it('sends the bearer token and returns an object URL for the blob', async () => {
    setToken('test-token')

    const blob = new Blob(['pdf bytes'], { type: 'application/pdf' })
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, blob: () => Promise.resolve(blob) })
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:fake-url') })

    const url = await authedBlobUrl('/api/v1/requests/abc/attachment')

    expect(url).toBe('blob:fake-url')
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/v1/requests/abc/attachment',
      expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer test-token' }) }),
    )
  })

  it('throws when the response is not ok, so a 404 never becomes a broken <img>', async () => {
    setToken('test-token')
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 404 }))

    await expect(authedBlobUrl('/api/v1/nope')).rejects.toThrow()
  })
})
```

> Read `src/lib/session.ts` first and use its **real** exported names. If it exports `getToken` but not `setToken`/`clearToken`, mock the module with `vi.mock('./session', () => ({ getToken: () => 'test-token' }))` instead of importing setters that do not exist.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend/web && npm test -- authedBlobUrl`
Expected: FAIL — cannot resolve `./authedBlobUrl`.

- [ ] **Step 3: Write the helper**

Create `frontend/web/src/lib/authedBlobUrl.ts`:

```ts
/**
 * Fetches a bearer-authenticated stream and hands back a same-origin blob URL.
 *
 * Every attachment route in this API is a private, app-mediated stream — never a public or
 * presigned object URL — so a plain `<a href>` or `<img src>` navigates WITHOUT the
 * Authorization header and gets a 401. This wraps the one workaround: fetch with the header,
 * then `createObjectURL` the result.
 *
 * Lifted out of RequestCard.tsx (M3.6) when M10a needed the same trick for identification
 * scan previews. Callers own the returned URL and should `URL.revokeObjectURL` it on unmount.
 */

import { getToken } from './session'

export async function authedBlobUrl(path: string): Promise<string> {
  const token = getToken()

  const response = await fetch(path, {
    headers: token === null ? {} : { Authorization: `Bearer ${token}` },
  })

  if (!response.ok) {
    throw new Error(`Failed to fetch ${path}: HTTP ${response.status}`)
  }

  return URL.createObjectURL(await response.blob())
}
```

- [ ] **Step 4: Use it in `RequestCard.tsx`**

Replace the body of the existing `downloadAttachment` function (around `RequestCard.tsx:146`) so it calls `authedBlobUrl` instead of hand-rolling the fetch, keeping its existing download-triggering behaviour and its `URL.revokeObjectURL` cleanup. Do not change its signature or any call site.

- [ ] **Step 5: Add the types and client methods to `api.ts`**

Append these types beside the other exported types:

```ts
export type ProfileDependent = {
  id: string
  name: string
  relationship: string | null
  birth_date: string | null
}

export type ProfileIdentification = {
  id: string
  category_code: string | null
  category_name: string | null
  number: string
  issued_on: string | null
  expires_on: string | null
  notes: string | null
  /** Whether the scan stream will return a file. Never a URL — the scan is app-mediated. */
  has_scan: boolean
}

export type ProfileAssignment = {
  designation: string | null
  business_unit: string | null
  reports_to: string | null
  employment_status: string | null
  location: string | null
  region: string | null
  labor_type: string | null
  hired_at: string | null
  work_shift: string | null
}

export type EmployeeProfile = {
  employee_id: string
  employee_no: string
  full_name: string
  details: {
    salutation: string | null
    first_name: string
    middle_name: string | null
    last_name: string
    name_suffix: string | null
    nickname: string | null
  }
  contact: {
    home_address: string | null
    personal_email: string | null
    phone: string | null
    fax: string | null
    mobile: string | null
    emergency_contact: string | null
  }
  personal: {
    gender: string | null
    birth_date: string | null
    age: number | null
    birthplace: string | null
    marital_status: string | null
    citizenship: string | null
    religion: string | null
    blood_type: string | null
  }
  assignment: ProfileAssignment
  dependents: ProfileDependent[]
  identifications: ProfileIdentification[]
}

/** What a manager sees. Structurally NOT a Partial<EmployeeProfile> — the missing sections are absent keys, not nulls. */
export type EmployeeProfileSummary = {
  employee_id: string
  employee_no: string
  full_name: string
  contact: { personal_email: string | null; phone: string | null; mobile: string | null }
  assignment: ProfileAssignment
}

export type ProfileWriteBody = Partial<{
  salutation: string | null
  nickname: string | null
  home_address: string | null
  personal_email: string | null
  phone: string | null
  fax: string | null
  mobile: string | null
  emergency_contact: string | null
  gender: string | null
  birth_date: string | null
  birthplace: string | null
  marital_status: string | null
  citizenship: string | null
  religion: string | null
  blood_type: string | null
}>

export type DependentWrite = {
  name: string
  relationship_id: string
  birth_date?: string | null
}

/** Static reference data for the profile dropdowns (GET /profile/catalog). */
export type ProfileCatalog = {
  relationships: Array<{ id: string; code: string; description: string }>
  identification_categories: Array<{ id: string; code: string; name: string; description: string | null }>
}
```

Then add to the `api` object, beside `api.admin`:

```ts
  profile: {
    mine: () => request<EmployeeProfile>('/me/profile'),
    forEmployee: (id: string) => request<EmployeeProfile>(`/admin/employees/${id}/profile`),
    redacted: (id: string) => request<EmployeeProfileSummary>(`/employees/${id}/profile`),
    catalog: () => request<ProfileCatalog>('/profile/catalog'),

    save: (id: string, body: ProfileWriteBody) =>
      request<EmployeeProfile>(`/admin/employees/${id}/profile`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),

    saveDependents: (id: string, dependents: DependentWrite[]) =>
      request<EmployeeProfile>(`/admin/employees/${id}/dependents`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ dependents }),
      }),

    // Multipart, mirroring api.adjustments.submit: build FormData and DO NOT set
    // Content-Type — the browser must set the multipart boundary itself. POST rather than
    // PUT because PHP parses a multipart body only on POST.
    saveIdentification: (
      id: string,
      fields: { category_id: string; number: string; issued_on?: string; expires_on?: string; notes?: string; scan?: File },
    ) => {
      const form = new FormData()
      form.append('category_id', fields.category_id)
      form.append('number', fields.number)
      if (fields.issued_on !== undefined) form.append('issued_on', fields.issued_on)
      if (fields.expires_on !== undefined) form.append('expires_on', fields.expires_on)
      if (fields.notes !== undefined) form.append('notes', fields.notes)
      if (fields.scan !== undefined) form.append('scan', fields.scan)

      return request<EmployeeProfile>(`/admin/employees/${id}/identifications`, {
        method: 'POST',
        body: form,
      })
    },

    deleteIdentification: (id: string, identificationId: string) =>
      request<EmployeeProfile>(`/admin/employees/${id}/identifications/${identificationId}`, {
        method: 'DELETE',
      }),
  },
```

- [ ] **Step 6: Add the query keys**

In `frontend/web/src/lib/keys.ts`, add to the `keys` object:

```ts
  // The personnel file (M10a). `mine` and `forEmployee` are separate cache entries on
  // purpose: they are different RESOURCES (self-read vs HR-admin read), and a profile
  // mutation invalidates only the employee whose profile it wrote.
  profile: {
    mine: () => ['profile', 'mine'] as const,
    forEmployee: (id: string) => ['profile', 'employee', id] as const,
    // Static company-wide reference data. Nothing invalidates it — no endpoint writes it,
    // it is seeded by ProfileCatalogSeeder — so it can safely carry a long staleTime.
    catalog: () => ['profile', 'catalog'] as const,
  },
```

- [ ] **Step 7: Run the frontend checks**

```bash
cd frontend/web && npm test && npm run typecheck
```
Expected: PASS — the two new `authedBlobUrl` cases green, every existing test green (including `RequestCard`'s download test, which must not regress).

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/lib/api.ts frontend/web/src/lib/keys.ts \
        frontend/web/src/lib/authedBlobUrl.ts frontend/web/src/lib/authedBlobUrl.test.ts \
        frontend/web/src/components/domain/RequestCard.tsx
git commit -m "M10a: profile API client, query keys, and extracted authedBlobUrl"
```

---

### Task 13: The `/me/profile` screen

Read-only. The five sections from the requirement, composed from existing tier-2 components — no new primitives, no raw hex, no literal pixel values.

**Files:**
- Create: `frontend/web/src/hooks/useMyProfile.ts`
- Create: `frontend/web/src/components/domain/ProfileSections.tsx`
- Create: `frontend/web/src/app/(app)/me/profile/page.tsx`
- Create: `frontend/web/src/app/(app)/me/profile/profile.test.tsx`
- Modify: `frontend/web/src/components/SideNav.tsx`

**Interfaces:**
- Consumes: `api.profile.mine()`, `keys.profile.mine()`, `EmployeeProfile` (Task 12).
- Produces: `useMyProfile()` returning `UseQueryResult<EmployeeProfile>`; `<ProfileSections profile={…} />` and `<DefinitionList items={…} />` for reuse by Task 14's admin tab.

- [ ] **Step 1: Write the failing test**

Create `frontend/web/src/app/(app)/me/profile/profile.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile } from '@/lib/api'

import ProfilePage from './page'

const profile: EmployeeProfile = {
  employee_id: 'emp-1',
  employee_no: '2506366',
  full_name: 'Ken Daryl Austero Perez',
  details: {
    salutation: 'Mr.', first_name: 'Ken Daryl', middle_name: 'Austero',
    last_name: 'Perez', name_suffix: null, nickname: 'KENPE',
  },
  contact: {
    home_address: 'Tagles Compound, Putatan, Muntinlupa City, Metro Manila',
    personal_email: null, phone: null, fax: null,
    mobile: '09166229187', emergency_contact: null,
  },
  personal: {
    gender: 'male', birth_date: '2002-01-23', age: 24, birthplace: null,
    marital_status: 'single', citizenship: 'Filipino',
    religion: 'Roman Catholic', blood_type: null,
  },
  assignment: {
    designation: 'Backend Software Developer',
    business_unit: 'Management Information System',
    reports_to: 'Castillo, Mark Jerome L.',
    employment_status: 'regular', location: 'Cebu', region: 'VII',
    labor_type: 'direct', hired_at: '2025-06-16',
    work_shift: '8:00 Am To 6:00 Pm - Rest Sat & Sun',
  },
  dependents: [],
  identifications: [
    {
      id: 'id-1', category_code: 'TIN', category_name: 'TIN',
      number: '653536955000', issued_on: null, expires_on: null,
      notes: null, has_scan: false,
    },
  ],
}

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { profile: { mine: vi.fn() } },
}))

const { api } = await import('@/lib/api')

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ProfilePage />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(api.profile.mine).mockReset()
})

describe('/me/profile', () => {
  it('renders every section of the personnel file', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText('2506366')).toBeInTheDocument()
    expect(screen.getByText('KENPE')).toBeInTheDocument()
    expect(screen.getByText('09166229187')).toBeInTheDocument()
    expect(screen.getByText('Backend Software Developer')).toBeInTheDocument()
    expect(screen.getByText('8:00 Am To 6:00 Pm - Rest Sat & Sun')).toBeInTheDocument()
    expect(screen.getByText('653536955000')).toBeInTheDocument()
  })

  it('renders age as a readable label, not a bare number', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText('24 Years Old')).toBeInTheDocument()
  })

  it('renders an em dash for an empty field rather than blank space', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    // birthplace, phone, fax, emergency_contact, blood_type, name_suffix are all null.
    expect(await screen.findByText('Birthplace')).toBeInTheDocument()
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('shows an empty state when the employee has no dependents', async () => {
    vi.mocked(api.profile.mine).mockResolvedValue(profile)
    renderPage()

    expect(await screen.findByText(/no dependents/i)).toBeInTheDocument()
  })

  it('shows a skeleton while loading', () => {
    vi.mocked(api.profile.mine).mockReturnValue(new Promise(() => {}))
    const { container } = renderPage()

    expect(container.querySelector('[data-testid="profile-skeleton"]')).not.toBeNull()
  })
})
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend/web && npm test -- profile`
Expected: FAIL — cannot resolve `./page`.

- [ ] **Step 3: Write the hook**

Create `frontend/web/src/hooks/useMyProfile.ts`:

```ts
'use client'

/**
 * The current employee's own personnel file (`GET /me/profile`) — full, including national
 * IDs. Thin on purpose, like useMyLeave: the key comes from keys.ts so an admin write's
 * invalidation can never drift from this hook's fetch.
 */

import { useQuery } from '@tanstack/react-query'

import type { EmployeeProfile } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useMyProfile() {
  return useQuery<EmployeeProfile>({
    queryKey: keys.profile.mine(),
    queryFn: () => api.profile.mine(),
  })
}
```

- [ ] **Step 4: Write the presentational sections**

Create `frontend/web/src/components/domain/ProfileSections.tsx`. It must export `DefinitionList` and `ProfileSections`, render an em dash for null, and label age as `"{n} Years Old"`. Read `src/components/SectionHeader.tsx`, `EmptyState.tsx`, and an existing domain component (e.g. `DayCell.tsx`) first, and mirror their token usage exactly — every color, gap, and radius reads a `var(--*)` from `carbon.css`.

```tsx
'use client'

/**
 * The five sections of a personnel file, presentational only. Shared by /me/profile (read)
 * and the admin employee Profile tab (read + edit), so the two can never disagree about
 * what a personnel file looks like.
 */

import { EmptyState } from '@/components/EmptyState'
import { SectionHeader } from '@/components/SectionHeader'
import type { EmployeeProfile } from '@/lib/api'

/** Null renders as an em dash, never as blank space — "we have no value" must be visible. */
function value(raw: string | number | null | undefined): string {
  return raw === null || raw === undefined || raw === '' ? '—' : String(raw)
}

export function DefinitionList({ items }: { items: Array<[string, string | number | null]> }) {
  return (
    <dl
      className="grid"
      style={{
        gridTemplateColumns: 'minmax(8rem, 14rem) 1fr',
        gap: 'var(--sp-xs) var(--sp-md)',
        margin: 0,
      }}
    >
      {items.map(([label, raw]) => (
        <div key={label} style={{ display: 'contents' }}>
          <dt style={{ font: 'var(--t-body-sm)', letterSpacing: 'var(--ls-body)', color: 'var(--ink-muted)' }}>
            {label}
          </dt>
          <dd style={{ font: 'var(--t-body)', letterSpacing: 'var(--ls-body)', color: 'var(--ink)', margin: 0 }}>
            {value(raw)}
          </dd>
        </div>
      ))}
    </dl>
  )
}

export function ProfileSections({ profile }: { profile: EmployeeProfile }) {
  const { details, contact, personal, assignment, dependents, identifications } = profile

  return (
    <div className="flex flex-col" style={{ gap: 'var(--sp-lg)' }}>
      <section>
        <SectionHeader>Details</SectionHeader>
        <DefinitionList
          items={[
            ['Employee ID', profile.employee_no],
            ['Salutation', details.salutation],
            ['Firstname', details.first_name],
            ['Middle Name', details.middle_name],
            ['Last Name', details.last_name],
            ['Suffix', details.name_suffix],
            ['Nickname', details.nickname],
          ]}
        />
      </section>

      <section>
        <SectionHeader>Contact</SectionHeader>
        <DefinitionList
          items={[
            ['Home', contact.home_address],
            ['Email', contact.personal_email],
            ['Phone', contact.phone],
            ['Fax', contact.fax],
            ['Mobile', contact.mobile],
            ['Emergency', contact.emergency_contact],
          ]}
        />
      </section>

      <section>
        <SectionHeader>Personal</SectionHeader>
        <DefinitionList
          items={[
            ['Gender', personal.gender],
            ['Birthday', personal.birth_date],
            // The backend sends a number; the label is a display concern, so it is composed
            // here rather than shipped as a pre-formatted string.
            ['Age', personal.age === null ? null : `${personal.age} Years Old`],
            ['Birthplace', personal.birthplace],
            ['Marital Status', personal.marital_status],
            ['Citizenship', personal.citizenship],
            ['Religion', personal.religion],
            ['Blood Type', personal.blood_type],
          ]}
        />
        <div style={{ marginTop: 'var(--sp-md)' }}>
          <SectionHeader>Dependents</SectionHeader>
          {dependents.length === 0 ? (
            <EmptyState>No dependents on file.</EmptyState>
          ) : (
            <DefinitionList
              items={dependents.map((d): [string, string] => [
                d.relationship ?? 'Dependent',
                d.birth_date === null ? d.name : `${d.name} · ${d.birth_date}`,
              ])}
            />
          )}
        </div>
      </section>

      <section>
        <SectionHeader>Assignment</SectionHeader>
        <DefinitionList
          items={[
            ['Designation', assignment.designation],
            ['Business Unit', assignment.business_unit],
            ['Reporting To', assignment.reports_to],
            ['Employment Status', assignment.employment_status],
            ['Location', assignment.location],
            ['Region', assignment.region],
            ['Labor Type', assignment.labor_type],
            ['Date Hired', assignment.hired_at],
            ['Work Shift', assignment.work_shift],
          ]}
        />
      </section>

      <section>
        <SectionHeader>National IDs</SectionHeader>
        {identifications.length === 0 ? (
          <EmptyState>No identification numbers on file.</EmptyState>
        ) : (
          <DefinitionList
            items={identifications.map((i): [string, string] => [
              i.category_name ?? i.category_code ?? 'ID',
              i.number,
            ])}
          />
        )}
      </section>
    </div>
  )
}
```

> `SectionHeader` and `EmptyState` may take different props than assumed (e.g. `title=` rather than children). Read both components first and adapt the call sites — do not change those components to fit this file.

- [ ] **Step 5: Write the page**

Create `frontend/web/src/app/(app)/me/profile/page.tsx`:

```tsx
'use client'

import { InlineNotification } from '@/components/ui/InlineNotification'
import { Skeleton } from '@/components/ui/Skeleton'
import { ProfileSections } from '@/components/domain/ProfileSections'
import { useMyProfile } from '@/hooks/useMyProfile'

export default function ProfilePage() {
  const { data, isPending, isError, error } = useMyProfile()

  if (isPending) {
    return <div data-testid="profile-skeleton"><Skeleton /></div>
  }

  if (isError) {
    return <InlineNotification kind="error" title="Could not load your profile" subtitle={error.message} />
  }

  return <ProfileSections profile={data} />
}
```

> Read `src/components/ui/Skeleton.tsx` and `InlineNotification.tsx` for their real props and match an existing page (e.g. `src/app/(app)/me/leave/page.tsx`) for the loading/error shape this codebase already uses.

- [ ] **Step 6: Add the nav entry**

In `frontend/web/src/components/SideNav.tsx`, add a `Profile` link to `/me/profile` beside the existing `/me/*` entries, matching their exact shape.

- [ ] **Step 7: Run the frontend checks**

```bash
cd frontend/web && npm test && npm run typecheck && npm run build
```
Expected: PASS — the five new cases green, existing tests green (including `SideNav`'s, which may assert a link count and need updating to match the new entry).

- [ ] **Step 8: Load it in a real browser**

```bash
make dev
```
Open <http://127.0.0.1:5176/me/profile>, sign in as a seeded employee, and confirm the five sections render with real data and readable spacing. **This step is not optional.** `CLAUDE.md` records that there is still no browser-level e2e harness and that M3.5's screens were never visually confirmed — do not extend that debt.

- [ ] **Step 9: Commit**

```bash
git add frontend/web/src/hooks/useMyProfile.ts \
        frontend/web/src/components/domain/ProfileSections.tsx \
        frontend/web/src/components/SideNav.tsx \
        "frontend/web/src/app/(app)/me/profile"
git commit -m "M10a: the /me/profile personnel file screen"
```

---

### Task 14: The admin Profile tab

**Files:**
- Create: `frontend/web/src/hooks/useEmployeeProfile.ts`
- Create: `frontend/web/src/hooks/useProfileCatalog.ts`
- Create: `frontend/web/src/hooks/useSaveProfile.ts`
- Create: `frontend/web/src/components/domain/ProfileForm.tsx`
- Create: `frontend/web/src/components/domain/IdentificationScan.tsx`
- Create: `frontend/web/src/components/domain/ProfileForm.test.tsx`
- Modify: `frontend/web/src/app/(app)/admin/employees/[id]/page.tsx`

**Interfaces:**
- Consumes: everything from Tasks 12–13.
- Produces: `useEmployeeProfile(id)`; `useSaveProfile(id)` exposing `saveProfile`, `saveDependents`, `saveIdentification`, `deleteIdentification` mutations, each invalidating `keys.profile.forEmployee(id)`; `<IdentificationScan employeeId identificationId />`.

- [ ] **Step 1: Write the failing test**

Create `frontend/web/src/components/domain/ProfileForm.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { EmployeeProfile } from '@/lib/api'

import { ProfileForm } from './ProfileForm'

const profile: EmployeeProfile = {
  employee_id: 'emp-1',
  employee_no: '2506366',
  full_name: 'Ken Daryl Austero Perez',
  details: {
    salutation: 'Mr.', first_name: 'Ken Daryl', middle_name: 'Austero',
    last_name: 'Perez', name_suffix: null, nickname: 'KENPE',
  },
  contact: {
    home_address: 'Tagles Compound, Putatan, Muntinlupa City',
    personal_email: null, phone: null, fax: null,
    mobile: '09166229187', emergency_contact: null,
  },
  personal: {
    gender: 'male', birth_date: '2002-01-23', age: 24, birthplace: null,
    marital_status: 'single', citizenship: 'Filipino',
    religion: 'Roman Catholic', blood_type: null,
  },
  assignment: {
    designation: 'Backend Software Developer', business_unit: 'MIS',
    reports_to: null, employment_status: 'regular', location: 'Cebu',
    region: 'VII', labor_type: 'direct', hired_at: '2025-06-16', work_shift: null,
  },
  dependents: [],
  identifications: [],
}

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: {
    profile: {
      save: vi.fn(),
      saveDependents: vi.fn(),
      saveIdentification: vi.fn(),
      deleteIdentification: vi.fn(),
    },
  },
}))

const { api } = await import('@/lib/api')

const RELATIONSHIPS = [{ id: 'rel-1', code: 'spouse', description: 'Spouse' }]
const CATEGORIES = [{ id: 'cat-1', code: 'TIN', name: 'TIN' }]

function renderForm() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ProfileForm profile={profile} relationships={RELATIONSHIPS} categories={CATEGORIES} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(api.profile.save).mockReset().mockResolvedValue(profile)
  vi.mocked(api.profile.saveDependents).mockReset().mockResolvedValue(profile)
  vi.mocked(api.profile.saveIdentification).mockReset().mockResolvedValue(profile)
})

describe('ProfileForm', () => {
  it('pre-fills every editable field from the profile', () => {
    renderForm()

    expect(screen.getByLabelText('Nickname')).toHaveValue('KENPE')
    expect(screen.getByLabelText('Home')).toHaveValue('Tagles Compound, Putatan, Muntinlupa City')
    expect(screen.getByLabelText('Mobile')).toHaveValue('09166229187')
    expect(screen.getByLabelText('Birthday')).toHaveValue('2002-01-23')
    expect(screen.getByLabelText('Religion')).toHaveValue('Roman Catholic')
  })

  // The field-name test. A snake_case/camelCase slip here is a silent 422 that no type
  // check catches, because the body is serialised as a plain object.
  it('submits the exact snake_case field names the backend validates', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.clear(screen.getByLabelText('Nickname'))
    await user.type(screen.getByLabelText('Nickname'), 'KEN')
    await user.click(screen.getByRole('button', { name: /save profile/i }))

    await waitFor(() => expect(api.profile.save).toHaveBeenCalledTimes(1))

    expect(api.profile.save).toHaveBeenCalledWith('emp-1', expect.objectContaining({
      nickname: 'KEN',
      home_address: 'Tagles Compound, Putatan, Muntinlupa City',
      personal_email: null,
      marital_status: 'single',
      birth_date: '2002-01-23',
      blood_type: null,
    }))
  })

  // Rule::enum matches the BACKED VALUE exactly, so an option valued 'Male' is a 422.
  it('offers only backed enum values in the closed-set selects', () => {
    renderForm()

    const genderValues = Array.from(
      screen.getByLabelText('Gender').querySelectorAll('option'),
    ).map((option) => option.value).filter((value) => value !== '')

    expect(genderValues).toEqual(['male', 'female'])

    const bloodValues = Array.from(
      screen.getByLabelText('Blood Type').querySelectorAll('option'),
    ).map((option) => option.value).filter((value) => value !== '')

    expect(bloodValues).toEqual(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])
  })

  it('sends the resulting array after adding and removing a dependent row', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.click(screen.getByRole('button', { name: /add dependent/i }))
    await user.click(screen.getByRole('button', { name: /add dependent/i }))
    await user.type(screen.getAllByLabelText('Dependent name')[0], 'Maria Perez')
    await user.click(screen.getAllByRole('button', { name: /remove dependent/i })[1])
    await user.click(screen.getByRole('button', { name: /save dependents/i }))

    await waitFor(() => expect(api.profile.saveDependents).toHaveBeenCalledTimes(1))

    expect(api.profile.saveDependents).toHaveBeenCalledWith('emp-1', [
      expect.objectContaining({ name: 'Maria Perez', relationship_id: 'rel-1' }),
    ])
  })

  it('sends an empty array when every dependent row is removed', async () => {
    const user = userEvent.setup()
    renderForm()

    await user.click(screen.getByRole('button', { name: /save dependents/i }))

    await waitFor(() => expect(api.profile.saveDependents).toHaveBeenCalledWith('emp-1', []))
  })

  it('attaches the chosen scan file to the identification save', async () => {
    const user = userEvent.setup()
    renderForm()

    const file = new File(['pdf bytes'], 'tin.pdf', { type: 'application/pdf' })

    await user.type(screen.getByLabelText('ID number'), '653536955000')
    await user.upload(screen.getByLabelText('Scan'), file)
    await user.click(screen.getByRole('button', { name: /save identification/i }))

    await waitFor(() => expect(api.profile.saveIdentification).toHaveBeenCalledTimes(1))

    expect(api.profile.saveIdentification).toHaveBeenCalledWith('emp-1', expect.objectContaining({
      category_id: 'cat-1',
      number: '653536955000',
      scan: file,
    }))
  })
})
```

This test defines `ProfileForm`'s full contract: props `{ profile, relationships, categories }`, the accessible labels every field must carry, three separate submit buttons, and the exact payload shape each one sends.

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend/web && npm test -- ProfileForm`
Expected: FAIL — cannot resolve `./ProfileForm`.

- [ ] **Step 3: Write the three hooks**

`useEmployeeProfile.ts` mirrors `useMyProfile` but calls `api.profile.forEmployee(id)` with `keys.profile.forEmployee(id)` and `enabled: id !== ''`.

`useProfileCatalog.ts` feeds the relationship and ID-kind dropdowns:

```ts
'use client'

import { useQuery } from '@tanstack/react-query'

import type { ProfileCatalog } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

/** Static reference data — nothing writes it, so it is refetched rarely rather than per mount. */
export function useProfileCatalog() {
  return useQuery<ProfileCatalog>({
    queryKey: keys.profile.catalog(),
    queryFn: () => api.profile.catalog(),
    staleTime: 60 * 60 * 1000,
  })
}
```

`useSaveProfile.ts` returns four `useMutation`s, each with:

```ts
    onSuccess: () => {
      // Both keys: an HR admin editing their OWN profile must see /me/profile update too.
      void queryClient.invalidateQueries({ queryKey: keys.profile.forEmployee(employeeId) })
      void queryClient.invalidateQueries({ queryKey: keys.profile.mine() })
    },
```

- [ ] **Step 4: Write `IdentificationScan.tsx`**

```tsx
'use client'

/**
 * Previews an identification scan. The stream is bearer-authenticated, so an <img src> or
 * <object data> pointing straight at the route navigates WITHOUT the token and 401s —
 * authedBlobUrl is the workaround, and the object URL is revoked on unmount so the blob does
 * not leak for the life of the tab.
 */

import { useEffect, useState } from 'react'

import { authedBlobUrl } from '@/lib/authedBlobUrl'

export function IdentificationScan({
  employeeId,
  identificationId,
}: {
  employeeId: string
  identificationId: string
}) {
  const [url, setUrl] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    authedBlobUrl(`/api/v1/employees/${employeeId}/identifications/${identificationId}/scan`)
      .then((blobUrl) => {
        if (cancelled) {
          URL.revokeObjectURL(blobUrl)

          return
        }
        objectUrl = blobUrl
        setUrl(blobUrl)
      })
      .catch(() => setFailed(true))

    return () => {
      cancelled = true
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl)
    }
  }, [employeeId, identificationId])

  if (failed) {
    return <span style={{ font: 'var(--t-body-sm)', color: 'var(--ink-muted)' }}>Scan unavailable</span>
  }

  if (url === null) {
    return <span style={{ font: 'var(--t-body-sm)', color: 'var(--ink-muted)' }}>Loading scan…</span>
  }

  // <object> renders both PDFs and images, so one element covers every accepted mime type.
  return (
    <object
      data={url}
      style={{ width: '100%', maxWidth: '32rem', height: '24rem', borderRadius: 'var(--radius)' }}
      aria-label="Identification scan"
    />
  )
}
```

- [ ] **Step 5: Write `ProfileForm.tsx` and wire the tab**

Build the form from the tier-1 primitives already in `src/components/ui/`. Three independent submits — profile, dependents, identifications — matching the three endpoints, because they are three separate writes and one combined save would need a transaction the API does not offer.

Add a `Profile` tab to `src/app/(app)/admin/employees/[id]/page.tsx` following whatever tab pattern that page already uses.

- [ ] **Step 6: Run the frontend checks**

```bash
cd frontend/web && npm test && npm run typecheck && npm run lint && npm run build
```
Expected: PASS.

- [ ] **Step 7: Load it in a real browser**

With `make dev` running, sign in as a system admin, open an employee, and actually **save a profile, add a dependent, and upload a scan**. Confirm the scan preview renders. A 422 from a field-name mismatch will not show up in any unit test.

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/hooks/useEmployeeProfile.ts frontend/web/src/hooks/useSaveProfile.ts \
        frontend/web/src/components/domain/ProfileForm.tsx \
        frontend/web/src/components/domain/ProfileForm.test.tsx \
        frontend/web/src/components/domain/IdentificationScan.tsx \
        "frontend/web/src/app/(app)/admin/employees"
git commit -m "M10a: admin profile tab with dependents, identifications, and scan preview"
```

---

### Task 15: Documentation

The design is the source of truth in this repo; a feature that is not in the docs is not finished.

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`, `CLAUDE.md`

- [ ] **Step 1: `docs/02-data-model.md`**

Add an "Employee profiling (M10a)" section with the five tables' DDL and the reasoning from spec decisions 1–3: why the profile is a side table, why IDs are catalog rows rather than eight columns, and why designation/labor_type sit on `employment_records` while region sits on `offices`.

- [ ] **Step 2: `docs/03-api.md`**

Document all nine routes with request and response shapes, and record the two non-obvious contracts: identifications are `POST`-not-`PUT` because PHP only parses multipart on `POST`, and dependents are replace-all.

- [ ] **Step 3: `docs/05-rbac.md`**

Add the profile abilities to the permission table, note that `employee.pii.edit` was catalogued in M2 and is **first read in M10a**, and record the manager-vs-HR-Admin distinction — including the consequence that an HR Admin managing a report in an office they do not administer gets the redacted view.

- [ ] **Step 4: `docs/06-roadmap.md`**

Add an M10a status block: what shipped, what was deferred (the table from the spec), and the gotchas found while building. Note that M10b (documents) is the open follow-on.

- [ ] **Step 5: `docs/features.md`**

Add what a user can now actually do: view their own personnel file; HR Admins configure profiles, dependents, and IDs for their offices; managers see a direct report's contact details.

- [ ] **Step 6: `CLAUDE.md`**

Update the **Status** section's test counts to the real numbers from `make test`, and add to "Gotchas that will cost you an afternoon":

```markdown
- **PHP parses a multipart body only on `POST`.** A `PUT multipart/form-data` arrives with
  an empty `$_FILES` and the uploaded file vanishes with no error — which is why Laravel
  ships `_method` spoofing. M10a's identification save is a `POST` despite being an upsert
  for exactly this reason; the profile and dependents writes stay `PUT` because they carry
  JSON and no file.
```

- [ ] **Step 7: Verify the counts are real**

```bash
make test
```
Copy the actual backend and frontend test counts into `CLAUDE.md`. Do not estimate them.

- [ ] **Step 8: Commit**

```bash
git add docs/ CLAUDE.md
git commit -m "M10a: docs — data model, API, RBAC, roadmap, features"
```

---

## Done when

- `make test` is green: every new test plus all 776 backend and 541 frontend tests that existed before M10a.
- `cd backend && ./vendor/bin/pest tests/Arch` is green — 19 arch tests, none relaxed.
- A fresh database bootstrapped with `php artisan hris:bootstrap-admin` has the eight identification categories and five relationships.
- `/me/profile` and the admin Profile tab have both been **loaded in a real browser**, with a scan uploaded and previewed.
- `docs/03-api.md` documents all nine routes.
