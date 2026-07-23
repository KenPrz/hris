# M2 — Schema, Auth, and RBAC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the org hierarchy, the employee record with its effective-dated employment history and denormalized current-state cache, Sanctum auth, and the office-scoped authorization model — proven by a four-actor scope matrix.

**Architecture:** Migrations declare `uuid` PKs with `uuidv7()` defaults (native in PG 18). `employees` holds immutable identity plus three `current_*` cache columns; `employment_records` is the effective-dated source of truth, and one action (`RecordEmploymentChange`) writes both in one transaction. Authority splits into two axes — verbs (spatie, HR role only) and scope (`EmployeeScope`, returning a query constraint). Manager is derived from the org chart, System Admin is a `users.is_system_admin` flag via `Gate::before`. Every endpoint stays controller → action → resource.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · Laravel Sanctum 4 · spatie/laravel-permission 8 (without teams) · spatie/laravel-activitylog · Pest 4

## Global Constraints

- **PHP 8.5**, **Laravel 13**, **PostgreSQL 18**. Pinned.
- **`declare(strict_types=1);` at the top of every PHP file** in `app/`, `database/`, and `tests/`. CI greps `app/` and `tests/`; extend the grep to `database/` in Task 9.
- **Never call `env()` outside `config/`.** **Nothing in `app/Domain/` calls `config()` or a facade** (M1 arch rules; still enforced).
- **All PKs are `uuid` with `default(DB::raw('uuidv7()'))`.** All FKs are `uuid`. Never `bigIncrements`.
- **Money is integer centavos** (`bigint` / PHP `int`). `base_rate_cents` follows this.
- **Actions own their transaction boundary and never touch HTTP.** One system action = one route = one invokable controller = one Action class.
- **Success is `{"data": ...}`, errors are `{"error": {code, message, details}}`.** The M0 envelope is closed; reuse it.
- **Refusals for out-of-scope subjects are `404 not_found`, never `403`.** "This exists but isn't yours" leaks the org chart.
- **spatie runs WITHOUT teams.** `config/permission.php` `'teams' => false`. The only forced migration edit is `model_id` → `uuid` (because `users.id` is `uuidv7`); no `team_foreign_key` column exists.
- **Manager authority is derived, never assigned.** There is no "Manager" spatie role.
- **Only `RecordEmploymentChange` writes `employees.current_office_id`/`current_department_id`/`current_reports_to_id`.** An arch test enforces this.
- **Tests run against real PostgreSQL, never SQLite.** M2 depends on `uuidv7()`, composite PKs, partial indexes.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL.

## File structure

```
backend/database/migrations/
  ..._create_organizations_offices_departments.php   the 3-tier hierarchy
  ..._create_employees_table.php                      identity + current_* cache
  ..._create_employment_records_table.php             effective-dated history
  ..._create_hr_admin_offices_table.php               the scope pivot
  ..._create_permission_tables.php                    spatie, no teams, uuid model_id
backend/app/Models/
  Organization.php Office.php Department.php Employee.php EmploymentRecord.php User.php
backend/app/Domain/Employment/
  EmploymentResolver.php                              "what was true on date D"
backend/app/Domain/Scope/
  EmployeeScope.php                                   the four scopes, as a query constraint
backend/app/Actions/Employees/
  RecordEmploymentChange.php  CreateEmployee.php  ProvisionUser.php
backend/app/Actions/Auth/
  LogIn.php  LogOut.php  BuildSession.php
backend/app/Policies/EmployeePolicy.php
backend/app/Http/Controllers/{Auth,Employees,Admin}/...  one invokable class per route
backend/app/Http/Requests/...                          one per write action
backend/app/Http/Resources/{SessionResource,EmployeeResource}.php
backend/database/factories/  backend/database/seeders/
```

---

### Task 1: The 3-tier hierarchy

Organizations → offices → departments as flat FKs, with models and factories.

**Files:**
- Create: `backend/database/migrations/2026_07_24_000001_create_organizations_offices_departments.php`
- Create: `backend/app/Models/Organization.php`, `Office.php`, `Department.php`
- Create: `backend/database/factories/OrganizationFactory.php`, `OfficeFactory.php`, `DepartmentFactory.php`
- Test: `backend/tests/Feature/Schema/HierarchyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Models\Organization` — `HasUuids`, columns `id, name, legal_name, tin, timezone`, `hasMany(Office)`.
  - `App\Models\Office` — `HasUuids`, `id, organization_id, name, code, timezone, geofence_lat, geofence_lng, geofence_radius_m, ip_allowlist`, `belongsTo(Organization)`, `hasMany(Department)`.
  - `App\Models\Department` — `HasUuids`, `id, office_id, name, code`, `belongsTo(Office)`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Schema/HierarchyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Office;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an organization with offices and departments', function (): void {
    $org = Organization::factory()->create(['name' => 'Delsan Inc.']);
    $office = Office::factory()->for($org)->create(['code' => 'MNL']);
    $dept = Department::factory()->for($office)->create(['code' => 'ENG']);

    expect($org->id)->toBeString()                       // uuidv7, not an int
        ->and($office->organization_id)->toBe($org->id)
        ->and($dept->office_id)->toBe($office->id)
        ->and($org->offices)->toHaveCount(1)
        ->and($office->departments)->toHaveCount(1)
        ->and($office->organization->is($org))->toBeTrue();
});

it('assigns a uuidv7 primary key from the database default', function (): void {
    $org = Organization::factory()->create();

    // uuidv7: version nibble is 7. Proves the DB default fired, not a client-side uuid.
    expect($org->id[14])->toBe('7');
});

it('requires an office code to be unique within the schema', function (): void {
    Office::factory()->create(['code' => 'MNL']);

    expect(fn () => Office::factory()->create(['code' => 'MNL']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/HierarchyTest.php`
Expected: FAIL — `Class "App\Models\Organization" not found`.

- [ ] **Step 3: Write the migration**

Create `backend/database/migrations/2026_07_24_000001_create_organizations_offices_departments.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The 3-tier hierarchy, as flat FKs. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->text('legal_name')->nullable();
            $table->text('tin')->nullable();            // BIR taxpayer id
            $table->text('timezone')->default('Asia/Manila');
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('code');
            $table->text('timezone')->default('Asia/Manila');
            // Geofence + IP allowlist are stored now so the M3 punch endpoint has a home
            // to validate against; they are unused until then.
            $table->decimal('geofence_lat', 10, 7)->nullable();
            $table->decimal('geofence_lng', 10, 7)->nullable();
            $table->integer('geofence_radius_m')->nullable();
            $table->jsonb('ip_allowlist')->nullable();
            $table->timestamps();

            $table->unique('code');
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('code');
            $table->timestamps();

            $table->unique(['office_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('organizations');
    }
};
```

- [ ] **Step 4: Write the models**

Create `backend/app/Models/Organization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** @return HasMany<Office, $this> */
    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }
}
```

Create `backend/app/Models/Office.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Office extends Model
{
    /** @use HasFactory<\Database\Factories\OfficeFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ip_allowlist' => 'array'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
```

Create `backend/app/Models/Department.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Department extends Model
{
    /** @use HasFactory<\Database\Factories\DepartmentFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
```

**Note on `HasUuids`:** Laravel's `HasUuids` generates a UUID client-side on create. Our columns also carry a `uuidv7()` DB default. These do not conflict — the model-generated value wins on insert, and the DB default only fires for raw inserts (seeders using `DB::table`, tests). To make the *model* path also produce uuidv7 (not uuid4), override `newUniqueId()`. Add this to each of the three models:

```php
    public function newUniqueId(): string
    {
        // uuidv7 everywhere, model-path included — time-ordered keys keep the btree happy.
        return (string) Illuminate\Support\Str::uuid7();
    }

    public function uniqueIds(): array
    {
        return ['id'];
    }
```

Add `use Illuminate\Support\Str;` and call `Str::uuid7()`. (Laravel 13's `Str::uuid7()` exists.)

- [ ] **Step 5: Write the factories**

Create `backend/database/factories/OrganizationFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'timezone' => 'Asia/Manila',
        ];
    }
}
```

Create `backend/database/factories/OfficeFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Office;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Office> */
final class OfficeFactory extends Factory
{
    protected $model = Office::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->city(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'timezone' => 'Asia/Manila',
        ];
    }
}
```

Create `backend/database/factories/DepartmentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => $this->faker->word(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
        ];
    }
}
```

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/HierarchyTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/database/migrations backend/app/Models/{Organization,Office,Department}.php backend/database/factories backend/tests/Feature/Schema/HierarchyTest.php
git commit -m "Schema: the 3-tier hierarchy as flat FKs

organizations -> offices -> departments, uuidv7 PKs, office code unique.
Geofence and IP allowlist columns exist now so M3's punch endpoint has a
home to validate against."
```

---

### Task 2: Employees, employment history, and the scope pivot

The core record: immutable identity plus the three `current_*` cache columns, the effective-dated history table, and `hr_admin_offices`. Schema and models only — the write action is Task 4.

**Files:**
- Create: `backend/database/migrations/2026_07_24_000002_create_employees_table.php`
- Create: `backend/database/migrations/2026_07_24_000003_create_employment_records_table.php`
- Create: `backend/database/migrations/2026_07_24_000004_create_hr_admin_offices_table.php`
- Create: `backend/app/Models/Employee.php`, `EmploymentRecord.php`
- Create: `backend/database/factories/EmployeeFactory.php`, `EmploymentRecordFactory.php`
- Test: `backend/tests/Feature/Schema/EmployeeSchemaTest.php`

**Interfaces:**
- Consumes: `Organization`, `Office`, `Department`, `User` (Task 1 + existing).
- Produces:
  - `App\Models\Employee` — `HasUuids`, `id, employee_no, user_id (nullable), organization_id, hired_at, separated_at, current_office_id, current_department_id, current_reports_to_id`. Relations `user()`, `organization()`, `currentOffice()`, `currentDepartment()`, `manager()` (belongsTo self via `current_reports_to_id`), `reports()` (hasMany self), `employmentRecords()`.
  - `App\Models\EmploymentRecord` — `HasUuids`, `id, employee_id, effective_from (date), office_id, department_id, reports_to_id (nullable), employment_type, is_art82_exempt (bool), base_rate_cents (int), created_by (nullable)`. `belongsTo(Employee)`.
  - Table `hr_admin_offices` — composite PK `(user_id, office_id)`, both `uuid` FKs.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Schema/EmployeeSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates an employee with no login account', function (): void {
    // A punch-only worker: employee record, no user_id.
    $employee = Employee::factory()->create(['user_id' => null]);

    expect($employee->user_id)->toBeNull()
        ->and($employee->user)->toBeNull()
        ->and($employee->employee_no)->toBeString();
});

it('links an employee to an optional user', function (): void {
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create();

    expect($employee->user->is($user))->toBeTrue();
});

it('resolves manager and reports through the current_reports_to_id cache', function (): void {
    $manager = Employee::factory()->create();
    $report = Employee::factory()->create(['current_reports_to_id' => $manager->id]);

    expect($report->manager->is($manager))->toBeTrue()
        ->and($manager->reports->pluck('id')->all())->toContain($report->id);
});

it('records an effective-dated employment row', function (): void {
    $employee = Employee::factory()->create();
    $record = EmploymentRecord::factory()->for($employee)->create([
        'effective_from' => '2026-01-01',
        'is_art82_exempt' => true,
        'base_rate_cents' => 5_000_00,
    ]);

    expect($record->effective_from->toDateString())->toBe('2026-01-01')
        ->and($record->is_art82_exempt)->toBeTrue()
        ->and($record->base_rate_cents)->toBe(500000)
        ->and($employee->employmentRecords)->toHaveCount(1);
});

it('enforces employee_no uniqueness', function (): void {
    Employee::factory()->create(['employee_no' => 'EMP-0001']);

    expect(fn () => Employee::factory()->create(['employee_no' => 'EMP-0001']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('makes hr_admin_offices a composite-key grant', function (): void {
    $user = User::factory()->create();
    $office = Office::factory()->create();

    DB::table('hr_admin_offices')->insert(['user_id' => $user->id, 'office_id' => $office->id]);

    // The same grant twice violates the composite primary key.
    expect(fn () => DB::table('hr_admin_offices')->insert(['user_id' => $user->id, 'office_id' => $office->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/EmployeeSchemaTest.php`
Expected: FAIL — `Class "App\Models\Employee" not found`.

- [ ] **Step 3: Write the migrations**

Create `backend/database/migrations/2026_07_24_000002_create_employees_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The core record: immutable identity plus three denormalized current_* columns.
 *
 * The current_* columns are a cache of the employee's active employment_records row,
 * written ONLY by App\Actions\Employees\RecordEmploymentChange, in the same transaction
 * as the history row it derives from. An arch test enforces the single writer. They exist
 * so office scoping stays a plain `WHERE current_office_id = ?` rather than a join to a
 * derived row. See docs/02-data-model.md and docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('employee_no')->unique();
            // Nullable: a punch-only worker has an employee record and no login.
            $table->foreignUuid('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignUuid('organization_id')->constrained();
            $table->date('hired_at');
            $table->date('separated_at')->nullable();

            // The cache. Nullable because an employee exists for the instant between the
            // employees insert and the first RecordEmploymentChange, within one transaction.
            $table->foreignUuid('current_office_id')->nullable()->constrained('offices');
            $table->foreignUuid('current_department_id')->nullable()->constrained('departments');
            $table->foreignUuid('current_reports_to_id')->nullable()->constrained('employees');

            $table->timestamps();

            $table->index('current_office_id');
            $table->index('current_reports_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

Create `backend/database/migrations/2026_07_24_000003_create_employment_records_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The effective-dated source of truth. One row per change; effective_to is derived (the
 * day before the next row's effective_from), never stored. The pay engine (M5) reads the
 * row whose range covers the date it is computing, so a promotion never rewrites history.
 * See docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');

            $table->foreignUuid('office_id')->constrained('offices');
            $table->foreignUuid('department_id')->constrained('departments');
            $table->foreignUuid('reports_to_id')->nullable()->constrained('employees');

            $table->text('employment_type');           // 'regular', 'probationary', 'contractual'
            $table->boolean('is_art82_exempt')->default(false);
            $table->bigInteger('base_rate_cents');      // integer centavos, per the money rule

            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // At most one record per employee per effective date — two changes the same day
            // are one change.
            $table->unique(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_records');
    }
};
```

Create `backend/database/migrations/2026_07_24_000004_create_hr_admin_offices_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR-Admin scope grant: (user, office) pairs. Being in this table for an office is
 * what confers HR-Admin scope over that office's employees. The verb set comes from the
 * spatie 'HR Admin' role; this pivot is the "over whom". See docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_admin_offices', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_admin_offices');
    }
};
```

- [ ] **Step 4: Write the models**

Create `backend/app/Models/Employee.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'separated_at' => 'date',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function currentOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'current_office_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    /** The current manager, via the cache. @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_reports_to_id');
    }

    /** Direct reports, via the cache. @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'current_reports_to_id');
    }

    /** @return HasMany<EmploymentRecord, $this> */
    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class);
    }
}
```

Create `backend/app/Models/EmploymentRecord.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EmploymentRecord extends Model
{
    /** @use HasFactory<\Database\Factories\EmploymentRecordFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'is_art82_exempt' => 'boolean',
            'base_rate_cents' => 'integer',
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
}
```

Also add the `newUniqueId()`/`uniqueIds()` override (from Task 1's note) to `Organization`, `Office`, and `Department` if not already added — every uuid model uses uuid7. If Task 1 already added them, leave as-is.

- [ ] **Step 5: Write the factories**

Create `backend/database/factories/EmployeeFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
final class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_no' => 'EMP-'.$this->faker->unique()->numerify('#####'),
            'user_id' => null,
            'organization_id' => Organization::factory(),
            'hired_at' => $this->faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
        ];
    }
}
```

Create `backend/database/factories/EmploymentRecordFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmploymentRecord> */
final class EmploymentRecordFactory extends Factory
{
    protected $model = EmploymentRecord::class;

    public function definition(): array
    {
        $office = Office::factory()->create();

        return [
            'employee_id' => Employee::factory(),
            'effective_from' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'office_id' => $office->id,
            'department_id' => Department::factory()->for($office)->create()->id,
            'reports_to_id' => null,
            'employment_type' => 'regular',
            'is_art82_exempt' => false,
            'base_rate_cents' => 61000, // ~PHP 610/day, near the NCR minimum
        ];
    }
}
```

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/EmployeeSchemaTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/database/migrations backend/app/Models/{Employee,EmploymentRecord}.php backend/database/factories/{Employee,EmploymentRecord}Factory.php backend/tests/Feature/Schema/EmployeeSchemaTest.php
git commit -m "Schema: employees, effective-dated employment_records, hr_admin_offices

employees holds immutable identity plus three current_* cache columns;
user_id is nullable for punch-only workers. employment_records is the
effective-dated truth; hr_admin_offices is the composite-key scope grant."
```

---

### Task 3: User model wiring, Sanctum, and spatie without teams

Wire `User` for auth and roles, install spatie without teams, publish its migration with the uuid `model_id` edit, and seed the HR permission catalog.

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/config/permission.php` (after publish)
- Create: `backend/database/migrations/2026_07_24_000005_create_permission_tables.php`
- Create: `backend/database/migrations/2026_07_24_000006_add_is_system_admin_to_users.php`
- Create: `backend/database/seeders/RbacSeeder.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (the `Gate::before` super-admin hook)
- Test: `backend/tests/Feature/Rbac/HrRoleTest.php`

**Interfaces:**
- Consumes: `User`, `Office` (Tasks 1–2).
- Produces:
  - `App\Models\User` — adds `HasUuids`, `HasApiTokens`, `HasRoles`, `newUniqueId()` uuid7, `is_system_admin` cast, `hrAdminOffices()` relation (belongsToMany `Office` through `hr_admin_offices`), `employee()` hasOne.
  - `RbacSeeder` — seeds the `HR Admin` role with permissions `employee.manage`, `employee.pii.edit`, `leave.approve`, `schedule.manage`, `holiday.manage`, `cutoff.manage`.
  - `Gate::before` returns `true` when `$user->is_system_admin`.

- [ ] **Step 1: Install spatie and publish**

```bash
cd backend
composer require spatie/laravel-permission:^8.3 spatie/laravel-activitylog:^4.10 --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-config
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-migrations
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag=activitylog-migrations
```

In `backend/config/permission.php` set `'teams' => false` (it is the default; confirm it, do not enable teams).

- [ ] **Step 2: Edit the published permission migration for uuid**

The published migration is named `..._create_permission_tables.php`. Rename it to `2026_07_24_000005_create_permission_tables.php` so it orders after the users/employees tables, and make **one** category of edit: every `model_id` / morph key must be `uuid`, because `users.id` is `uuidv7`. Spatie's default uses `unsignedBigInteger` there. With teams off, there is no `team_foreign_key` column to touch.

In the migration, inside both `model_has_permissions` and `model_has_roles`, replace the morph-key line. Spatie's stub uses `$table->{$columnNames['model_morph_key']}(...)` via a `morphs`-like helper; make it explicit:

```php
// model_has_permissions
$table->uuid($columnNames['model_morph_key']);
$table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

// model_has_roles
$table->uuid($columnNames['model_morph_key']);
$table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');
```

Leave `roles.id` and `permissions.id` as spatie's default `bigIncrements` — nothing in this schema FKs to them by uuid, and the morph key is the only join that touches `users`. Do NOT add a `team_foreign_key` column or index.

Confirm the file has `declare(strict_types=1);` at the top after renaming.

- [ ] **Step 3: Write the `is_system_admin` migration**

Create `backend/database/migrations/2026_07_24_000006_add_is_system_admin_to_users.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global oversight is a flag, not a role: spatie cannot express an assignment that spans
 * every office, and a null-team role assignment is impossible because model_has_roles's
 * key columns are NOT NULL. Granted via Gate::before. See docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_system_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_system_admin');
        });
    }
};
```

The stock `users` table from M0 uses `bigIncrements`. Confirm whether it does; if so, this milestone needs `users.id` to be `uuid` for the morph key and `employees.user_id` FK to line up. **Change the stock users migration** (`0001_01_01_000000_create_users_table.php`) to use `uuid('id')->primary()->default(DB::raw('uuidv7()'))` instead of `id()`. This is a greenfield DB with no data; editing the original migration is correct here. Verify no other migration assumed a bigint user id.

**Sanctum's token table must follow.** `personal_access_tokens.tokenable_id` is a polymorphic key that defaults to `bigint` via `morphs('tokenable')`. With `users.id` now a uuid, `createToken()` (Task 6's login) would fail at insert. Publish Sanctum's migration and change the morph to a uuid morph:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --tag=sanctum-migrations
```

In the published `..._create_personal_access_tokens_table.php`, replace `$table->morphs('tokenable');` with `$table->uuidMorphs('tokenable');`, and add `declare(strict_types=1);` at the top. Order it after the users-table migration. Task 3 Step 9's `migrate:fresh` is what proves users, sanctum, and spatie all agree on the uuid morph key — if `createToken` later fails in Task 6, this edit is the first thing to check.

- [ ] **Step 4: Wire the User model**

Replace `backend/app/Models/User.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_system_admin' => 'boolean',
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

    /** @return HasOne<Employee, $this> */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** The offices this user administers as HR. @return BelongsToMany<Office, $this> */
    public function hrAdminOffices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class, 'hr_admin_offices');
    }
}
```

- [ ] **Step 5: The super-admin gate**

In `backend/app/Providers/AppServiceProvider.php`'s `boot()`, add after `assertConfigured()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
```

```php
        // Global oversight. A System Admin passes every gate; returning null (not false)
        // for everyone else lets the normal policy chain run. Spatie's own recommended
        // super-admin pattern. See docs/05-rbac.md.
        Gate::before(fn (User $user): ?bool => $user->is_system_admin ? true : null);
```

- [ ] **Step 6: Write the failing test**

Create `backend/tests/Feature/Rbac/HrRoleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(Database\Seeders\RbacSeeder::class));

it('seeds the HR Admin role with its verb catalog', function (): void {
    $user = User::factory()->create();
    $user->assignRole('HR Admin');

    expect($user->can('leave.approve'))->toBeTrue()
        ->and($user->can('employee.pii.edit'))->toBeTrue()
        ->and($user->can('schedule.manage'))->toBeTrue()
        ->and($user->can('cutoff.manage'))->toBeTrue();
});

it('grants a system admin every ability via Gate::before', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);

    // A permission that exists but was never assigned to this user.
    expect($admin->can('leave.approve'))->toBeTrue()
        // ...and one that does not exist as a permission at all.
        ->and($admin->can('anything.at.all'))->toBeTrue();
});

it('does not grant a plain user HR abilities', function (): void {
    $user = User::factory()->create();

    expect($user->can('leave.approve'))->toBeFalse();
});
```

- [ ] **Step 7: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Rbac/HrRoleTest.php`
Expected: FAIL — `Class "Database\Seeders\RbacSeeder" not found`.

- [ ] **Step 8: Write the RBAC seeder**

Create `backend/database/seeders/RbacSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The HR-Admin verb catalog, as data. Manager authority is derived from the org chart
 * (no role), and system-admin is a flag (Gate::before) — so this is the one role spatie
 * carries in M2. Future specialized roles (Payroll Officer, Recruiter) are added here.
 * See docs/05-rbac.md.
 */
final class RbacSeeder extends Seeder
{
    private const array HR_PERMISSIONS = [
        'employee.manage',
        'employee.pii.edit',
        'leave.approve',
        'schedule.manage',
        'holiday.manage',
        'cutoff.manage',
    ];

    public function run(): void
    {
        foreach (self::HR_PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }

        $role = Role::findOrCreate('HR Admin');
        $role->syncPermissions(self::HR_PERMISSIONS);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
```

- [ ] **Step 9: Run migrations fresh and the test**

```bash
cd backend
php artisan migrate:fresh
./vendor/bin/pest tests/Feature/Rbac/HrRoleTest.php
```

Expected: migrations run clean (proving the uuid edits and the users-id change are consistent), then 3 tests PASS.

- [ ] **Step 10: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Models/User.php backend/config/permission.php backend/database/migrations backend/database/seeders/RbacSeeder.php backend/app/Providers/AppServiceProvider.php backend/composer.json backend/composer.lock backend/tests/Feature/Rbac/HrRoleTest.php
# includes the edited stock users migration, the published+edited sanctum migration,
# and the spatie + activitylog migrations
git commit -m "Rbac: spatie without teams, HR role catalog, system-admin gate

users.id is now uuidv7 so the morph key lines up; the only spatie edit is
model_id -> uuid. Manager is derived and admin is a flag, so spatie
carries exactly one role: HR Admin and its verbs, as seeded data."
```

---

### Task 4: `RecordEmploymentChange` and the effective-date resolver

The single writer of the cache, and the "what was true on date D" lookup the pay engine will use.

**Files:**
- Create: `backend/app/Domain/Employment/EmploymentResolver.php`
- Create: `backend/app/Actions/Employees/RecordEmploymentChange.php`
- Test: `backend/tests/Feature/Employees/RecordEmploymentChangeTest.php`
- Test: `backend/tests/Unit/Domain/Employment/EmploymentResolverTest.php`
- Modify: `backend/tests/Arch/ConventionsTest.php`

**Interfaces:**
- Consumes: `Employee`, `EmploymentRecord` (Task 2).
- Produces:
  - `App\Domain\Employment\EmploymentResolver` — `final`, static `on(Employee $employee, CarbonInterface $date): ?EmploymentRecord` returning the record whose `effective_from <= $date` and is the latest such, or `null` if none.
  - `App\Actions\Employees\RecordEmploymentChange` — `final`, `execute(RecordEmploymentChangeInput $in): EmploymentRecord`. Inserts a history row and updates `employees.current_*` in one transaction. Updates the cache ONLY when the new row is the latest effective date for that employee (a back-dated correction updates history but not "current").
  - `App\Actions\Employees\RecordEmploymentChangeInput` — readonly DTO: `employeeId, effectiveFrom (string YYYY-MM-DD), officeId, departmentId, reportsToId (?string), employmentType, isArt82Exempt (bool), baseRateCents (int), actorId (?string)`.

- [ ] **Step 1: Write the failing resolver test**

Create `backend/tests/Unit/Domain/Employment/EmploymentResolverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns the record whose range covers the date', function (): void {
    $employee = Employee::factory()->create();
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-01-01', 'is_art82_exempt' => false]);
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-06-01', 'is_art82_exempt' => true]);

    // Before the promotion: the January row (not exempt).
    expect(EmploymentResolver::on($employee, Carbon::parse('2026-03-15'))->is_art82_exempt)->toBeFalse()
        // On/after the promotion: the June row (exempt).
        ->and(EmploymentResolver::on($employee, Carbon::parse('2026-06-01'))->is_art82_exempt)->toBeTrue()
        ->and(EmploymentResolver::on($employee, Carbon::parse('2026-09-01'))->is_art82_exempt)->toBeTrue();
});

it('returns null before the earliest record', function (): void {
    $employee = Employee::factory()->create();
    EmploymentRecord::factory()->for($employee)->create(['effective_from' => '2026-01-01']);

    expect(EmploymentResolver::on($employee, Carbon::parse('2025-12-31')))->toBeNull();
});
```

Note this test hits the DB, so it lives under `tests/Feature`-style `RefreshDatabase` even though it is in `tests/Unit/Domain`. That is acceptable: the resolver is a thin query, and testing it against real rows is the point. (If the Unit suite's no-boot rule rejects `RefreshDatabase`, move this file to `tests/Feature/Employees/EmploymentResolverTest.php` — confirm by running it.)

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Employment/EmploymentResolverTest.php`
Expected: FAIL — resolver class not found. **If it also errors that the Unit suite won't boot `RefreshDatabase`**, move the file to `tests/Feature/Employees/EmploymentResolverTest.php` and rerun; proceed once it fails for the right reason (class not found).

- [ ] **Step 3: Write the resolver**

Create `backend/app/Domain/Employment/EmploymentResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Employment;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use Carbon\CarbonInterface;

/**
 * "What was true for this employee on this date." The pay engine (M5) computes a past
 * period by reading the record whose range covers each day, so a later promotion never
 * changes what a closed period was paid.
 *
 * effective_to is not stored: the covering record is simply the latest one whose
 * effective_from is on or before the date.
 */
final class EmploymentResolver
{
    public static function on(Employee $employee, CarbonInterface $date): ?EmploymentRecord
    {
        return EmploymentRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }
}
```

- [ ] **Step 4: Run the resolver test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Employment/EmploymentResolverTest.php` (or the Feature path if moved)
Expected: PASS, 2 tests.

- [ ] **Step 5: Write the failing action test**

Create `backend/tests/Feature/Employees/RecordEmploymentChangeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function changeInput(Employee $employee, Office $office, Department $dept, array $overrides = []): RecordEmploymentChangeInput
{
    return new RecordEmploymentChangeInput(...array_merge([
        'employeeId' => $employee->id,
        'effectiveFrom' => '2026-01-01',
        'officeId' => $office->id,
        'departmentId' => $dept->id,
        'reportsToId' => null,
        'employmentType' => 'regular',
        'isArt82Exempt' => false,
        'baseRateCents' => 61000,
        'actorId' => null,
    ], $overrides));
}

it('writes a history row and updates the cache in one go', function (): void {
    $office = Office::factory()->create();
    $dept = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create(['current_office_id' => null]);

    app(RecordEmploymentChange::class)->execute(changeInput($employee, $office, $dept));

    $employee->refresh();
    expect($employee->employmentRecords)->toHaveCount(1)
        ->and($employee->current_office_id)->toBe($office->id)
        ->and($employee->current_department_id)->toBe($dept->id);
});

it('moves the cache forward on a promotion to a later effective date', function (): void {
    $office = Office::factory()->create();
    $deptA = Department::factory()->for($office)->create();
    $deptB = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create();

    $action = app(RecordEmploymentChange::class);
    $action->execute(changeInput($employee, $office, $deptA, ['effectiveFrom' => '2026-01-01']));
    $action->execute(changeInput($employee, $office, $deptB, ['effectiveFrom' => '2026-06-01']));

    expect($employee->refresh()->current_department_id)->toBe($deptB->id)
        ->and($employee->employmentRecords)->toHaveCount(2);
});

it('does not move the cache backward on a back-dated correction', function (): void {
    // A correction filed for a PAST date must not overwrite the current state.
    $office = Office::factory()->create();
    $deptCurrent = Department::factory()->for($office)->create();
    $deptOld = Department::factory()->for($office)->create();
    $employee = Employee::factory()->create();

    $action = app(RecordEmploymentChange::class);
    $action->execute(changeInput($employee, $office, $deptCurrent, ['effectiveFrom' => '2026-06-01']));
    $action->execute(changeInput($employee, $office, $deptOld, ['effectiveFrom' => '2026-01-01']));

    // The cache still reflects the June (latest) row, not the back-dated January one.
    expect($employee->refresh()->current_department_id)->toBe($deptCurrent->id);
});
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Employees/RecordEmploymentChangeTest.php`
Expected: FAIL — `RecordEmploymentChange` not found.

- [ ] **Step 7: Write the input DTO and action**

Create `backend/app/Actions/Employees/RecordEmploymentChangeInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class RecordEmploymentChangeInput
{
    public function __construct(
        public string $employeeId,
        public string $effectiveFrom,      // 'YYYY-MM-DD'
        public string $officeId,
        public string $departmentId,
        public ?string $reportsToId,
        public string $employmentType,
        public bool $isArt82Exempt,
        public int $baseRateCents,
        public ?string $actorId,
    ) {}
}
```

Create `backend/app/Actions/Employees/RecordEmploymentChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\EmploymentRecord;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of the current_* cache. Inserts an effective-dated history row and,
 * when that row is the latest for the employee, updates the three cache columns — in one
 * transaction, so the ledger and its cache can never disagree.
 *
 * A back-dated correction updates history but leaves the cache alone: "current" means the
 * latest effective date, not the most recently entered row. See docs/02-data-model.md.
 */
final class RecordEmploymentChange
{
    public function execute(RecordEmploymentChangeInput $in): EmploymentRecord
    {
        return DB::transaction(function () use ($in): EmploymentRecord {
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            $record = EmploymentRecord::query()->create([
                'employee_id' => $in->employeeId,
                'effective_from' => $in->effectiveFrom,
                'office_id' => $in->officeId,
                'department_id' => $in->departmentId,
                'reports_to_id' => $in->reportsToId,
                'employment_type' => $in->employmentType,
                'is_art82_exempt' => $in->isArt82Exempt,
                'base_rate_cents' => $in->baseRateCents,
                'created_by' => $in->actorId,
            ]);

            // Only advance the cache if this is now the latest effective date.
            $latest = EmploymentRecord::query()
                ->where('employee_id', $in->employeeId)
                ->orderByDesc('effective_from')
                ->first();

            if ($latest !== null && $latest->id === $record->id) {
                $employee->update([
                    'current_office_id' => $in->officeId,
                    'current_department_id' => $in->departmentId,
                    'current_reports_to_id' => $in->reportsToId,
                ]);
            }

            return $record;
        });
    }
}
```

- [ ] **Step 8: Add the arch guard for the single writer**

In `backend/tests/Arch/ConventionsTest.php`, add:

```php
arch('only RecordEmploymentChange writes the employment cache columns')
    ->expect(['current_office_id', 'current_department_id', 'current_reports_to_id'])
    ->toOnlyBeUsedIn([
        'App\Actions\Employees\RecordEmploymentChange',
        'App\Models\Employee',                    // the belongsTo/hasMany definitions name them
        'App\Domain\Scope\EmployeeScope',         // reads them (Task 5)
        'App\Http\Resources\SessionResource',     // reads them (Task 6)
        'App\Http\Resources\EmployeeResource',    // reads them (Task 7)
    ]);
```

If `toOnlyBeUsedIn` on string literals is not how this Pest arch version expresses a "these column names appear only here" rule, fall back to a grep-based test: a Pest test that scans `app/` for the three column names as write targets (`->update([... 'current_office_id'`) and asserts the only file is `RecordEmploymentChange.php`. Implement whichever the installed Pest supports; the guarantee — one writer — is what matters, not the mechanism. Record which you used in the report.

- [ ] **Step 9: Run the action test and the arch suite**

```bash
cd backend
./vendor/bin/pest tests/Feature/Employees/RecordEmploymentChangeTest.php
./vendor/bin/pest --testsuite=Arch
```

Expected: 3 action tests PASS; arch suite PASS with the new rule.

- [ ] **Step 10: Prove the arch guard bites**

Add a scratch write of `current_office_id` to some other action or a new scratch file, run the arch suite, confirm it FAILS on the new rule, then remove it and confirm PASS. Record the output.

- [ ] **Step 11: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Employment backend/app/Actions/Employees backend/tests/Feature/Employees backend/tests/Unit/Domain/Employment backend/tests/Arch/ConventionsTest.php
git commit -m "Employees: RecordEmploymentChange owns the cache; effective-date resolver

One transaction writes the history row and, when it is the latest, the
three current_* columns — so ledger and cache can't disagree. An arch
test proves nothing else writes them. A back-dated correction updates
history but not 'current'."
```

---

### Task 5: `EmployeeScope`

The four scopes, as a composable query constraint. One place the boundary is defined.

**Files:**
- Create: `backend/app/Domain/Scope/EmployeeScope.php`
- Test: `backend/tests/Feature/Scope/EmployeeScopeTest.php`

**Interfaces:**
- Consumes: `User`, `Employee` (Tasks 2–3).
- Produces: `App\Domain\Scope\EmployeeScope` — `final`, static `visibleTo(User $user): Builder` returning an `Employee` query already constrained to what `$user` may see. System admins get an unconstrained query; a user with neither reports, HR offices, nor an employee record gets an empty query (self is resolved via their employee).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Scope/EmployeeScopeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Scope\EmployeeScope;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seenBy(User $user): array
{
    return EmployeeScope::visibleTo($user)->pluck('id')->all();
}

it('lets a plain employee see only themselves', function (): void {
    $office = Office::factory()->create();
    $me = Employee::factory()->create(['current_office_id' => $office->id]);
    $me->user()->associate(User::factory()->create())->save();
    $peer = Employee::factory()->create(['current_office_id' => $office->id]);

    expect(seenBy($me->user))->toBe([$me->id])
        ->and(seenBy($me->user))->not->toContain($peer->id);
});

it('lets a manager see exactly their direct reports and themselves', function (): void {
    $office = Office::factory()->create();
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create(['current_office_id' => $office->id]);
    $manager->user()->associate($managerUser)->save();

    $report = Employee::factory()->create(['current_office_id' => $office->id, 'current_reports_to_id' => $manager->id]);
    $peersReport = Employee::factory()->create(['current_office_id' => $office->id]);

    $seen = seenBy($managerUser);
    expect($seen)->toContain($manager->id)
        ->and($seen)->toContain($report->id)
        ->and($seen)->not->toContain($peersReport->id);
});

it('lets an HR admin see only their office', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();

    $hrUser = User::factory()->create();
    $hr = Employee::factory()->create(['current_office_id' => $manila->id]);
    $hr->user()->associate($hrUser)->save();
    $hrUser->hrAdminOffices()->attach($manila->id);

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);

    $seen = seenBy($hrUser);
    expect($seen)->toContain($manilaWorker->id)
        ->and($seen)->not->toContain($cebuWorker->id);
});

it('lets a system admin see everyone', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    Employee::factory()->count(5)->create();

    expect(seenBy($admin))->toHaveCount(5);
});

it('composes additively for an HR admin who also has reports', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();
    $hrUser = User::factory()->create();
    $hr = Employee::factory()->create(['current_office_id' => $cebu->id]);   // works in Cebu
    $hr->user()->associate($hrUser)->save();
    $hrUser->hrAdminOffices()->attach($manila->id);                          // but HR-admins Manila

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $hrDirectReport = Employee::factory()->create(['current_office_id' => $cebu->id, 'current_reports_to_id' => $hr->id]);

    $seen = seenBy($hrUser);
    expect($seen)->toContain($manilaWorker->id)      // via HR office
        ->and($seen)->toContain($hrDirectReport->id); // via direct report in a different office
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Scope/EmployeeScopeTest.php`
Expected: FAIL — `EmployeeScope` not found.

- [ ] **Step 3: Write `EmployeeScope`**

Create `backend/app/Domain/Scope/EmployeeScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which employees may this user see." Returns a query constraint,
 * not a boolean, so it composes into any index query and there is exactly one place the
 * boundary lives. A policy that checks a verb but forgets to apply this is the bug this
 * exists to prevent — an arch test asserts every index action routes through here.
 *
 * The four scopes compose ADDITIVELY: self, direct reports, HR offices, and (for a system
 * admin) everything. See docs/05-rbac.md.
 *
 * This is a Domain service that touches Eloquent, which is allowed for a Scope/query
 * builder — the M1 config-purity rule bars config() and facades from Domain, not the ORM.
 */
final class EmployeeScope
{
    public static function visibleTo(User $user): Builder
    {
        $query = Employee::query();

        // System admin: unconstrained. (Gate::before also short-circuits policy checks,
        // but index queries call this directly, so the scope must grant all here too.)
        if ($user->is_system_admin) {
            return $query;
        }

        $selfEmployeeId = $user->employee?->id;
        $hrOfficeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        return $query->where(function (Builder $q) use ($user, $selfEmployeeId, $hrOfficeIds): void {
            // Self.
            if ($selfEmployeeId !== null) {
                $q->orWhere('id', $selfEmployeeId);
                // Direct reports (manager scope is derived from the org chart).
                $q->orWhere('current_reports_to_id', $selfEmployeeId);
            }

            // HR offices.
            if ($hrOfficeIds !== []) {
                $q->orWhereIn('current_office_id', $hrOfficeIds);
            }

            // A user with no employee, no reports, and no HR offices sees nothing. Force an
            // empty result rather than an unconstrained one.
            if ($selfEmployeeId === null && $hrOfficeIds === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Scope/EmployeeScopeTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Scope backend/tests/Feature/Scope
git commit -m "Scope: EmployeeScope returns a composable query constraint

Self, direct reports, HR offices, and (system admin) everything, composed
additively. One place the boundary is defined, so every index query and
policy resolves it identically."
```

---

### Task 6: Auth — login, logout, `/me`

Sanctum token auth, rate-limited login that never leaks email existence, and the session envelope the frontend reads.

**Files:**
- Create: `backend/app/Actions/Auth/BuildSession.php`
- Create: `backend/app/Http/Controllers/Auth/LoginController.php`, `LogoutController.php`, `MeController.php`
- Create: `backend/app/Http/Requests/LoginRequest.php`
- Create: `backend/app/Http/Resources/SessionResource.php`
- Create: `backend/app/Exceptions/Domain/InvalidCredentials.php`
- Modify: `backend/routes/api.php`, `backend/bootstrap/app.php` (rate limiter)
- Test: `backend/tests/Feature/Auth/LoginTest.php`, `MeTest.php`

**Interfaces:**
- Consumes: `User`, `EmployeeScope` inputs (Tasks 3–5).
- Produces:
  - `App\Actions\Auth\BuildSession` — `final`, `execute(User $user): SessionData` (a readonly DTO carrying user, employee, is_system_admin, has_reports, hr_offices, permissions).
  - `SessionResource` — serializes `SessionData` to the `/me` envelope.
  - `POST /login`, `POST /logout` (auth), `GET /me` (auth).

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Auth/LoginTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues a token for correct credentials', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'secret-pw'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
});

it('rejects a wrong password without revealing the account exists', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('gives the same answer for an unknown email as for a wrong password', function (): void {
    // No account exists. The code and status must be identical to the wrong-password case,
    // so an attacker cannot enumerate accounts.
    $this->postJson('/api/v1/login', ['email' => 'nobody@delsan.test', 'password' => 'whatever'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('rate-limits repeated login attempts', function (): void {
    User::factory()->create(['email' => 'maria@delsan.test', 'password' => Hash::make('secret-pw')]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong']);
    }

    $this->postJson('/api/v1/login', ['email' => 'maria@delsan.test', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests');
});
```

Create `backend/tests/Feature/Auth/MeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the session envelope for a plain employee', function (): void {
    $office = Office::factory()->create();
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.is_system_admin', false)
        ->assertJsonPath('data.has_reports', false)
        ->assertJsonPath('data.hr_offices', [])
        ->assertJsonPath('data.employee.current_office_id', $office->id);
});

it('reports has_reports for a manager and hr_offices for an HR admin', function (): void {
    $office = Office::factory()->create();
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $office->id]);
    Employee::factory()->create(['current_reports_to_id' => $manager->id]);
    $managerUser->hrAdminOffices()->attach($office->id);

    Sanctum::actingAs($managerUser);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.has_reports', true)
        ->assertJsonPath('data.hr_offices', [$office->id]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Auth/`
Expected: FAIL — routes 404 / classes not found.

- [ ] **Step 3: Write the domain exception**

Create `backend/app/Exceptions/Domain/InvalidCredentials.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Wrong email OR wrong password — deliberately indistinguishable, so the API cannot be
 * used to enumerate which accounts exist. See docs/03-api.md.
 */
final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        parent::__construct('The email or password is incorrect.');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
```

- [ ] **Step 4: Write `BuildSession` and its DTO**

Create `backend/app/Actions/Auth/SessionData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;

final readonly class SessionData
{
    /**
     * @param  list<string>  $hrOffices
     * @param  list<string>  $permissions
     */
    public function __construct(
        public User $user,
        public ?Employee $employee,
        public bool $isSystemAdmin,
        public bool $hasReports,
        public array $hrOffices,
        public array $permissions,
    ) {}
}
```

Create `backend/app/Actions/Auth/BuildSession.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;

/**
 * Assembles the /me session — the single source of scope truth the frontend reads. Kept
 * out of the controller because the seeder and tests build sessions too.
 */
final class BuildSession
{
    public function execute(User $user): SessionData
    {
        $employee = $user->employee;

        $hasReports = $employee !== null
            && Employee::query()->where('current_reports_to_id', $employee->id)->exists();

        return new SessionData(
            user: $user,
            employee: $employee,
            isSystemAdmin: $user->is_system_admin,
            hasReports: $hasReports,
            hrOffices: $user->hrAdminOffices()->pluck('offices.id')->all(),
            permissions: $user->getAllPermissions()->pluck('name')->all(),
        );
    }
}
```

- [ ] **Step 5: Write the resource, request, and controllers**

Create `backend/app/Http/Resources/SessionResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\SessionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SessionData */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SessionData $s */
        $s = $this->resource;

        return [
            'user' => [
                'id' => $s->user->id,
                'email' => $s->user->email,
                'name' => $s->user->name,
            ],
            'employee' => $s->employee === null ? null : [
                'id' => $s->employee->id,
                'employee_no' => $s->employee->employee_no,
                'current_office_id' => $s->employee->current_office_id,
                'current_department_id' => $s->employee->current_department_id,
            ],
            'is_system_admin' => $s->isSystemAdmin,
            'has_reports' => $s->hasReports,
            'hr_offices' => $s->hrOffices,
            'permissions' => $s->permissions,
        ];
    }
}
```

Create `backend/app/Http/Requests/LoginRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

Create `backend/app/Http/Controllers/Auth/LoginController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\Domain\InvalidCredentials;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

final class LoginController
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        // One check, one failure. Never branch the response on whether the user was found.
        if ($user === null || ! Hash::check((string) $request->string('password'), (string) $user->password)) {
            throw new InvalidCredentials();
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json(['data' => [
            'token' => $token,
            'user' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
        ]]);
    }
}
```

Create `backend/app/Http/Controllers/Auth/LogoutController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController
{
    public function __invoke(Request $request): Response
    {
        /** @var \Laravel\Sanctum\PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->noContent();
    }
}
```

Create `backend/app/Http/Controllers/Auth/MeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BuildSession;
use App\Http\Resources\SessionResource;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request, BuildSession $action): SessionResource
    {
        return new SessionResource($action->execute($request->user()));
    }
}
```

- [ ] **Step 6: Routes and rate limiter**

In `backend/routes/api.php`, add inside the `v1` group:

```php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
```

```php
    Route::post('/login', LoginController::class)->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', MeController::class);
    });
```

In `backend/bootstrap/app.php`, register the named rate limiter. Laravel 13 defines limiters in a service provider or via `RateLimiter::for`. Add to `AppServiceProvider::boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
```

```php
        // Five login attempts per minute per email+IP. The envelope renders the 429.
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(
            $request->input('email').'|'.$request->ip()
        ));
```

- [ ] **Step 7: Run the auth tests to verify they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Auth/`
Expected: PASS, 7 tests. (If the rate-limit test bleeds across tests, ensure `RefreshDatabase` and the limiter's cache store reset between tests — the array cache store in `phpunit.xml` resets per test.)

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Auth backend/app/Http/Controllers/Auth backend/app/Http/Requests/LoginRequest.php backend/app/Http/Resources/SessionResource.php backend/app/Exceptions/Domain/InvalidCredentials.php backend/routes/api.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/Auth
git commit -m "Auth: Sanctum login, logout, and the /me session envelope

invalid_credentials is identical for a wrong password and an unknown
email, so the API can't enumerate accounts. Login is rate-limited to 5/min
per email+IP. /me is the single source of scope truth the frontend reads."
```

---

### Task 7: `EmployeePolicy` and the four-actor scope matrix

The read path, the two-check policy shape, 404-not-403, and the milestone's proof.

**Files:**
- Create: `backend/app/Policies/EmployeePolicy.php`
- Create: `backend/app/Http/Controllers/Employees/ListEmployeesController.php`, `ShowEmployeeController.php`
- Create: `backend/app/Http/Resources/EmployeeResource.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (register policy), `backend/routes/api.php`
- Modify: `backend/tests/Arch/ConventionsTest.php` (index-routes-through-scope rule)
- Test: `backend/tests/Feature/Employees/ScopeMatrixTest.php`

**Interfaces:**
- Consumes: `EmployeeScope`, `Employee`, `User` (Tasks 2–5).
- Produces:
  - `App\Policies\EmployeePolicy` — `view(User, Employee): bool` (in scope), `update(User, Employee): bool` (in scope AND `can('employee.manage')`).
  - `GET /employees` (index, scoped), `GET /employees/{employee}` (show, 404 out of scope).

- [ ] **Step 1: Write the failing scope-matrix test**

Create `backend/tests/Feature/Employees/ScopeMatrixTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeWorld(): array
{
    $manila = Office::factory()->create(['code' => 'MNL']);
    $cebu = Office::factory()->create(['code' => 'CEB']);

    $adminUser = User::factory()->create(['is_system_admin' => true]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->for($managerUser)->create(['current_office_id' => $manila->id]);
    $report = Employee::factory()->create(['current_office_id' => $manila->id, 'current_reports_to_id' => $manager->id]);

    $hrUser = User::factory()->create();
    $hr = Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);

    return compact('manila', 'cebu', 'adminUser', 'managerUser', 'manager', 'report', 'hrUser', 'cebuWorker');
}

it('404s when an employee views a peer', function (): void {
    ['report' => $report, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($report->user ?? User::factory()->create());

    $reportUser = User::factory()->create();
    $report->user()->associate($reportUser)->save();
    Sanctum::actingAs($reportUser);

    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a manager see a direct report but 404s on a peer', function (): void {
    ['managerUser' => $managerUser, 'report' => $report, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($managerUser);

    $this->getJson("/api/v1/employees/{$report->id}")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a Manila HR admin see a Manila worker but 404s on Cebu', function (): void {
    ['hrUser' => $hrUser, 'report' => $manilaWorker, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($hrUser);

    $this->getJson("/api/v1/employees/{$manilaWorker->id}")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertStatus(404);
});

it('lets a system admin see everyone', function (): void {
    ['adminUser' => $adminUser, 'cebuWorker' => $cebuWorker] = makeWorld();
    Sanctum::actingAs($adminUser);

    $this->getJson("/api/v1/employees/{$cebuWorker->id}")->assertOk();
});

it('scopes the index list to what the actor may see', function (): void {
    $world = makeWorld();
    Sanctum::actingAs($world['managerUser']);

    $ids = $this->getJson('/api/v1/employees')->assertOk()->json('data.*.id');

    // The manager sees themselves and their report, not the Cebu worker.
    expect($ids)->toContain($world['manager']->id)
        ->and($ids)->toContain($world['report']->id)
        ->and($ids)->not->toContain($world['cebuWorker']->id);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Employees/ScopeMatrixTest.php`
Expected: FAIL — routes 404 (not the scoped kind — the routes don't exist yet).

- [ ] **Step 3: Write the policy**

Create `backend/app/Policies/EmployeePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Scope\EmployeeScope;
use App\Models\Employee;
use App\Models\User;

/**
 * Two checks, always: the subject via EmployeeScope, and (for writes) the verb via a
 * permission. System admins never reach here — Gate::before short-circuits first.
 *
 * "Can see" is defined as "is inside the scope query", so there is one definition of the
 * boundary, shared with every index. See docs/05-rbac.md.
 */
final class EmployeePolicy
{
    public function view(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->inScope($user, $employee) && $user->can('employee.manage');
    }

    private function inScope(User $user, Employee $employee): bool
    {
        return EmployeeScope::visibleTo($user)->whereKey($employee->id)->exists();
    }
}
```

Register it in `AppServiceProvider::boot()`:

```php
use App\Models\Employee;
use App\Policies\EmployeePolicy;
use Illuminate\Support\Facades\Gate;
```

```php
        Gate::policy(Employee::class, EmployeePolicy::class);
```

- [ ] **Step 4: Write the resource and controllers**

Create `backend/app/Http/Resources/EmployeeResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
final class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'current_office_id' => $this->current_office_id,
            'current_department_id' => $this->current_department_id,
            'current_reports_to_id' => $this->current_reports_to_id,
            'hired_at' => $this->hired_at?->toDateString(),
        ];
    }
}
```

Create `backend/app/Http/Controllers/Employees/ListEmployeesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Domain\Scope\EmployeeScope;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListEmployeesController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        // The index resolves scope through the same service the policy uses. No employee
        // outside the actor's scope is ever loaded, so there is nothing to 404 on.
        $employees = EmployeeScope::visibleTo($request->user())->orderBy('employee_no')->get();

        return EmployeeResource::collection($employees);
    }
}
```

Create `backend/app/Http/Controllers/Employees/ShowEmployeeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowEmployeeController
{
    public function __invoke(Request $request, Employee $employee): EmployeeResource
    {
        // 404, not 403: "this exists but isn't yours" leaks the org chart, and for salary
        // records the leak is the disclosure. A denied view is indistinguishable from a
        // nonexistent id.
        if ($request->user()->cannot('view', $employee)) {
            throw new NotFoundHttpException();
        }

        return new EmployeeResource($employee);
    }
}
```

- [ ] **Step 5: Routes**

In `backend/routes/api.php`, inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Employees\ListEmployeesController;
use App\Http\Controllers\Employees\ShowEmployeeController;
```

```php
        Route::get('/employees', ListEmployeesController::class);
        Route::get('/employees/{employee}', ShowEmployeeController::class);
```

- [ ] **Step 6: Add the arch rule that index actions route through the scope**

In `backend/tests/Arch/ConventionsTest.php`, add:

```php
arch('reading employees goes through EmployeeScope, never a bare Employee query')
    ->expect('App\Http\Controllers\Employees')
    ->toUse('App\Domain\Scope\EmployeeScope');
```

If the show controller relies on the policy (which uses the scope) rather than calling the scope directly, and this rule is too strict for it, scope the rule to the list controller specifically, or assert the policy uses the scope instead. The guarantee: no employee read path bypasses `EmployeeScope`. Record which form you used.

- [ ] **Step 7: Run the matrix and the arch suite**

```bash
cd backend
./vendor/bin/pest tests/Feature/Employees/ScopeMatrixTest.php
./vendor/bin/pest --testsuite=Arch
```

Expected: 5 matrix tests PASS; arch suite PASS.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Policies backend/app/Http/Controllers/Employees backend/app/Http/Resources/EmployeeResource.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/tests/Arch/ConventionsTest.php backend/tests/Feature/Employees/ScopeMatrixTest.php
git commit -m "Employees: EmployeePolicy, scoped index/show, the four-actor matrix

view is 'is in EmployeeScope'; a denied show is 404 not 403 so the org
chart can't be probed. The matrix is the milestone's proof: employee,
manager, office HR, and system admin each see exactly their scope."
```

---

### Task 8: Admin write endpoints

Creating an employee, provisioning their login, and recording an employment change over HTTP.

**Files:**
- Create: `backend/app/Actions/Employees/CreateEmployee.php` (+ `CreateEmployeeInput`)
- Create: `backend/app/Actions/Employees/ProvisionUser.php` (+ `ProvisionUserInput`)
- Create: controllers under `backend/app/Http/Controllers/Admin/Employees/` and their FormRequests
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/EmployeeAdminTest.php`

**Interfaces:**
- Consumes: `RecordEmploymentChange` (Task 4), `Employee`, `User`.
- Produces:
  - `POST /admin/employees` — creates an `Employee` (+ optional first employment record) [system admin].
  - `POST /admin/employees/{employee}/user` — provisions/sets a login + password [system admin].
  - `POST /admin/employees/{employee}/employment` — wraps `RecordEmploymentChange` [system admin].
  - All three behind `middleware(['auth:sanctum'])` and a `Gate::authorize` that only a system admin passes (there is no self-serve employee creation in M2; System Admin owns onboarding).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/EmployeeAdminTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets a system admin create an employee with a first employment record', function (): void {
    $org = Organization::factory()->create();
    $office = Office::factory()->for($org)->create();
    $dept = Department::factory()->for($office)->create();
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-1001',
        'organization_id' => $org->id,
        'hired_at' => '2026-02-01',
        'employment' => [
            'effective_from' => '2026-02-01',
            'office_id' => $office->id,
            'department_id' => $dept->id,
            'employment_type' => 'probationary',
            'is_art82_exempt' => false,
            'base_rate_cents' => 61000,
        ],
    ])->assertCreated()->assertJsonPath('data.employee_no', 'EMP-1001');

    $employee = Employee::query()->where('employee_no', 'EMP-1001')->firstOrFail();
    // The first employment record populated the cache via RecordEmploymentChange.
    expect($employee->current_office_id)->toBe($office->id);
});

it('provisions a login for an existing employee', function (): void {
    $employee = Employee::factory()->create(['user_id' => null]);
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));

    $this->postJson("/api/v1/admin/employees/{$employee->id}/user", [
        'email' => 'newhire@delsan.test',
        'password' => 'provisioned-pw',
    ])->assertCreated();

    expect($employee->refresh()->user)->not->toBeNull()
        ->and($employee->user->email)->toBe('newhire@delsan.test');
});

it('forbids a non-admin from creating employees', function (): void {
    $office = Office::factory()->create();
    Sanctum::actingAs(User::factory()->create());   // not a system admin

    $this->postJson('/api/v1/admin/employees', [
        'employee_no' => 'EMP-9999',
        'organization_id' => Organization::factory()->create()->id,
        'hired_at' => '2026-02-01',
    ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
```

Note: admin endpoints legitimately return `403 forbidden` (not 404) — an unauthorized *actor* is a different case from an out-of-scope *subject*. The 404-not-403 rule is specifically about not leaking which employees exist; it does not apply to "you may not create employees at all."

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/EmployeeAdminTest.php`
Expected: FAIL — routes 404.

- [ ] **Step 3: Write the actions**

Create `backend/app/Actions/Employees/CreateEmployeeInput.php` and `CreateEmployee.php`, and `ProvisionUserInput.php` and `ProvisionUser.php`. `CreateEmployee` inserts the `Employee` row, then — if an `employment` block is present — calls `RecordEmploymentChange` in the same transaction so the cache is populated. `ProvisionUser` creates a `User` (hashed password) and associates it to the employee, failing if the employee already has one.

```php
// CreateEmployeeInput.php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class CreateEmployeeInput
{
    public function __construct(
        public string $employeeNo,
        public string $organizationId,
        public string $hiredAt,
        public ?RecordEmploymentChangeInput $firstEmployment,   // null if created bare
        public ?string $actorId,
    ) {}
}
```

```php
// CreateEmployee.php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

final class CreateEmployee
{
    public function __construct(private readonly RecordEmploymentChange $recordChange) {}

    public function execute(CreateEmployeeInput $in): Employee
    {
        return DB::transaction(function () use ($in): Employee {
            $employee = Employee::query()->create([
                'employee_no' => $in->employeeNo,
                'organization_id' => $in->organizationId,
                'hired_at' => $in->hiredAt,
            ]);

            if ($in->firstEmployment !== null) {
                // Re-point the input at the new employee id, then record it — this fills
                // the current_* cache through the one action allowed to write it.
                $this->recordChange->execute(new RecordEmploymentChangeInput(
                    employeeId: $employee->id,
                    effectiveFrom: $in->firstEmployment->effectiveFrom,
                    officeId: $in->firstEmployment->officeId,
                    departmentId: $in->firstEmployment->departmentId,
                    reportsToId: $in->firstEmployment->reportsToId,
                    employmentType: $in->firstEmployment->employmentType,
                    isArt82Exempt: $in->firstEmployment->isArt82Exempt,
                    baseRateCents: $in->firstEmployment->baseRateCents,
                    actorId: $in->actorId,
                ));
            }

            return $employee->refresh();
        });
    }
}
```

```php
// ProvisionUserInput.php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class ProvisionUserInput
{
    public function __construct(
        public string $employeeId,
        public string $email,
        public string $password,
        public string $name,
    ) {}
}
```

```php
// ProvisionUser.php
<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Exceptions\Domain\EmployeeAlreadyHasLogin;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ProvisionUser
{
    public function execute(ProvisionUserInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            if ($employee->user_id !== null) {
                throw new EmployeeAlreadyHasLogin($employee->id);
            }

            $user = User::query()->create([
                'name' => $in->name,
                'email' => $in->email,
                'password' => Hash::make($in->password),
            ]);

            $employee->update(['user_id' => $user->id]);

            return $user;
        });
    }
}
```

Create `backend/app/Exceptions/Domain/EmployeeAlreadyHasLogin.php` (extends `DomainException`, code `employee_already_has_login`, status `422`, details `['employee_id' => ...]`).

- [ ] **Step 4: Write the controllers, requests, and the admin gate**

Create the three controllers under `Http/Controllers/Admin/Employees/` (`CreateEmployeeController`, `ProvisionUserController`, `RecordEmploymentController`), each a thin invokable mapping a FormRequest to the action and returning a resource with the right status (`201` for create/provision). Each FormRequest's `authorize()` returns `$this->user()->is_system_admin` — so a non-admin gets `403 forbidden` through the envelope. `RecordEmploymentController` builds a `RecordEmploymentChangeInput` from the route employee id and the body.

Routes in `backend/routes/api.php`, inside the `auth:sanctum` group:

```php
    Route::prefix('admin')->group(function (): void {
        Route::post('/employees', Admin\Employees\CreateEmployeeController::class);
        Route::post('/employees/{employee}/user', Admin\Employees\ProvisionUserController::class);
        Route::post('/employees/{employee}/employment', Admin\Employees\RecordEmploymentController::class);
    });
```

(Import the controllers, or reference by FQCN. Match the existing import style in the file.)

- [ ] **Step 5: Run the admin tests to verify they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/EmployeeAdminTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Employees backend/app/Http/Controllers/Admin backend/app/Http/Requests backend/app/Exceptions/Domain/EmployeeAlreadyHasLogin.php backend/routes/api.php backend/tests/Feature/Admin/EmployeeAdminTest.php
git commit -m "Admin: create employee, provision login, record employment over HTTP

CreateEmployee populates the current_* cache through RecordEmploymentChange
rather than writing it directly. An unauthorized actor is 403 (a different
case from an out-of-scope subject, which is 404)."
```

---

### Task 9: Seeders, docs, and the final gate

The Manila/Cebu company you can log into as each of the four scopes, the design docs M2 owns, and the whole suite green.

**Files:**
- Create: `backend/database/seeders/CompanySeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Create: `docs/02-data-model.md`, `docs/05-rbac.md`
- Modify: `docs/03-api.md` (create if absent), `docs/README.md`, `docs/06-roadmap.md`
- Modify: `.github/workflows/ci.yml` (extend the strict_types grep to `database/`)
- Test: `backend/tests/Feature/Seed/CompanySeederTest.php`

**Interfaces:**
- Consumes: everything.
- Produces: a seeded company; the docs M3+ build against.

- [ ] **Step 1: Write the seeder test**

Create `backend/tests/Feature/Seed/CompanySeederTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(Database\Seeders\DatabaseSeeder::class);
});

it('seeds two offices with employees in each', function (): void {
    expect(Office::query()->count())->toBe(2)
        ->and(Employee::query()->count())->toBeGreaterThanOrEqual(10);
});

it('seeds a system admin, an HR admin per office, and a punch-only worker', function (): void {
    expect(User::query()->where('is_system_admin', true)->count())->toBe(1)
        ->and(Employee::query()->whereNull('user_id')->count())->toBeGreaterThanOrEqual(1);

    // Each office has an HR admin scoped to exactly that office.
    foreach (Office::query()->pluck('id') as $officeId) {
        $hrCount = User::query()->whereHas('hrAdminOffices', fn ($q) => $q->where('offices.id', $officeId))->count();
        expect($hrCount)->toBeGreaterThanOrEqual(1);
    }
});

it('seeds at least one Art. 82-exempt manager with live current state', function (): void {
    // Some employee's latest record is exempt AND they have reports.
    $exemptManagers = Employee::query()
        ->whereHas('reports')
        ->whereHas('employmentRecords', fn ($q) => $q->where('is_art82_exempt', true))
        ->count();

    expect($exemptManagers)->toBeGreaterThanOrEqual(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/CompanySeederTest.php`
Expected: FAIL — `CompanySeeder` not wired / not enough data.

- [ ] **Step 3: Write `CompanySeeder`**

Create `backend/database/seeders/CompanySeeder.php` building: one organization; Manila + Cebu offices (2 departments each); a System Admin user; one HR Admin per office (each with `hr_admin_offices` for their office and the `HR Admin` role); a manager in each office with 2–3 reports; one Art. 82-exempt manager; one punch-only worker with `user_id = null`. Every employee with a login gets an employment record via `RecordEmploymentChange` (so caches are populated the one legal way). Print a credentials table (email → password, and the scope each represents) via `$this->command->table(...)`, exactly as POS's seeder does. Use fixed dev passwords.

Wire it in `DatabaseSeeder::run()`: call `RbacSeeder` then `CompanySeeder`.

- [ ] **Step 4: Run the seeder test and a fresh seed**

```bash
cd backend
php artisan migrate:fresh --seed
./vendor/bin/pest tests/Feature/Seed/CompanySeederTest.php
```

Expected: seed prints a credentials table; 3 tests PASS.

- [ ] **Step 5: Write `docs/02-data-model.md`**

Full schema with rationale, in POS's `02-data-model.md` voice: the hierarchy; `employees` with the cache columns and *why* they exist (the flat-`WHERE` promise meeting effective dating); `employment_records` as effective-dated truth with derived `effective_to`; `hr_admin_offices` as the composite-key grant; the spatie tables (no teams, uuid morph key); `is_system_admin`. State the single-writer invariant for the cache and point at the arch test. Note `base_rate_cents` follows the integer-centavos rule.

- [ ] **Step 6: Write `docs/05-rbac.md`**

The authority model, in POS's `05-rbac.md` voice, but stating the HRIS divergence explicitly: spatie WITHOUT teams (quote the foundation spec's reason — no device token to make team context unambiguous); the two axes (verbs via spatie, scope via `EmployeeScope`); Manager derived from the org chart (no role); System Admin a flag via `Gate::before` (with POS's proof that a global role assignment is impossible); the four scopes and their additive composition; 404-not-403 for subjects vs 403 for actors; the single seeded `HR Admin` role and why spatie carries only one role in M2.

- [ ] **Step 7: Update `docs/03-api.md`, `docs/README.md`, `docs/06-roadmap.md`**

Add the M2 endpoints and their error codes (`invalid_credentials`, `too_many_requests`, `employee_already_has_login`, `not_found` for scoped subjects) to `03-api.md`; the `/me` envelope shape. Drop the `*(M2)*` markers on `02-data-model.md`, `03-api.md`, `05-rbac.md` in `docs/README.md` and link them. Add a `**Status: complete.**` block to `docs/06-roadmap.md`'s M2 section recording: the current-state cache and its single writer; manager-derived-not-assigned; spatie carrying one role; 404-not-403; the four-actor matrix as the proof; and any wall-hit notes (e.g. the users-id-to-uuid change, the spatie morph-key edit).

- [ ] **Step 8: Extend the strict_types CI gate to `database/`**

In `.github/workflows/ci.yml`, change the strict_types grep step to also scan `backend/database/`. Verify locally: `grep -rL 'declare(strict_types=1)' backend/database --include='*.php'` returns nothing (migrations, factories, seeders all carry it).

- [ ] **Step 9: Run everything**

```bash
cd /home/haru/projects/hris/backend && ./vendor/bin/pest
cd /home/haru/projects/hris/frontend/web && npm run lint && npm test && npm run typecheck && npm run build
cd /home/haru/projects/hris && make test
```

Expected: backend suite green (M0 27 + M1 88 + all M2 feature/unit/arch), frontend unchanged (16), `make test` green in containers. Report the real backend count.

- [ ] **Step 10: Commit**

```bash
cd /home/haru/projects/hris
git add backend/database/seeders docs .github/workflows/ci.yml backend/tests/Feature/Seed
git commit -m "Seed + docs: the Manila/Cebu company, and M2's design docs

A company you can log into as each of the four scopes, including an
Art. 82-exempt manager and a punch-only worker. 02-data-model, 05-rbac,
and 03-api written; the strict_types gate now covers database/."
```

---

## Done When

The four-actor scope matrix is green (an employee 404s on a peer, a Manila HR Admin 404s on a Cebu employee, a manager sees exactly their reports, a System Admin sees all); `RecordEmploymentChange` keeps the cache and history in sync and an arch test proves it is the only writer; a promotion resolves correctly across its effective-date boundary; and `migrate:fresh --seed` produces a company you can log into as each of the four scopes.
