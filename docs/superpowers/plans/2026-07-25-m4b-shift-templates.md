# M4b — Shift Templates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the schedule layer — the per-office data and the `ScheduleResolver` that turns any `(employee, date)` into "rest day or not, and how many minutes are scheduled" — the two inputs M5's compute engine will read.

**Architecture:** Four tables (shift_templates + its 7 weekday rows, schedule_assignments, schedule_overrides) plus an `offices.default_shift_template_id` column, resolved by a pure-read `ScheduleResolver` that walks `override → employee assignment → department assignment → office default`. Every endpoint reuses M4a's byte-identical-404 `OfficeScope` discipline verbatim. The `/office/schedules` screen is the third `MonthCalendar` consumer. M4b computes no pay.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 · Next 16 / React 19 / TypeScript / Tailwind v4 · Pest (real Postgres) · Vitest.

**The canonical pattern to mirror is M4a (holidays), merged on `main`.** Wherever this plan says "mirror the holidays X," open that exact file and copy its structure, changing only the stated delta. Key files:
- Scoped controller: `backend/app/Http/Controllers/Office/Holidays/{List,Create,Update,Delete}Controller.php`
- Shape-only FormRequest: `backend/app/Http/Requests/{ListHolidays,CreateHoliday,UpdateHoliday}Request.php`
- Resource: `backend/app/Http/Resources/HolidayResource.php`
- Action + unique-violation-as-domain-error: `backend/app/Actions/Holidays/CreateHoliday.php`, `backend/app/Exceptions/Domain/HolidayExists.php`, `backend/app/Exceptions/Domain/EmploymentRecordExists.php`
- Scope helpers: `backend/app/Domain/Scope/OfficeScope.php` (`administered(User,?string):?Office`, `administers(User,string):bool`)
- Model with activity log: `backend/app/Models/Holiday.php`
- Feature-test scoping harness: `backend/tests/Feature/Office/HolidayReadWriteTest.php`
- Frontend: `frontend/web/src/lib/keys.ts`, `lib/api.ts`, `hooks/useHolidays.ts`, `components/ui/{Dialog,Select,TextInput}.tsx`, `components/domain/MonthCalendar.tsx`, `app/(app)/office/holidays/page.tsx`, `components/SideNav.tsx`.

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/`. Actions final, take an Input DTO, own their transaction, never touch HTTP. Controllers final + invokable. Domain layer is HTTP-agnostic (controllers throw the 404, never the Domain).
- **String columns + PHP backed enums + CHECK constraints; never native PG enums.** `Weekday` is the one int-backed enum (`Monday = 0` … `Sunday = 6`), still a backed enum + `CHECK (weekday BETWEEN 0 AND 6)`.
- **Integer minutes only, never a float, in any layer.** Minutes-from-local-midnight; `end_minute` may exceed 1439 for a cross-midnight shift (the existing `WorkInterval` convention).
- **Calendar dates on the wire are `YYYY-MM-DD` strings**, never `Date` objects.
- uuid v7 PKs (`->default(DB::raw('uuidv7()'))`), uuid FKs; models use `HasUuids` and override `newUniqueId()`→`Str::uuid7()` (mirror `Holiday.php`). activity_log subject morph is uuid (already so in this codebase).
- **404-not-403 enumeration discipline:** FormRequests validate ids as shape-only `uuid`/`date`, **never** `exists:`; controllers resolve scope via `OfficeScope`/target office and `throw new NotFoundHttpException`, so an out-of-scope real id and a fabricated id are byte-identical.
- Success `{data:…}` / error `{error:…}`, closed envelope. Domain refusals are `DomainException` subclasses (never a raw 500 from a constraint).
- Frontend: token-only styling (no raw hex outside `carbon.css`); every `font: var(--t-*)` paired with its `--ls-*` companion (except `--t-card-title`); `'use client'` first line; `import type`; no `enum`; no unused locals; `crypto.randomUUID` only via `lib/uuid`.
- Tests run against **real PostgreSQL, never SQLite.** Two suites: `./vendor/bin/pest` and `./vendor/bin/pest --testsuite=Arch`.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, `Generated with`, or session URL. Message body only.

---

### Task 1: Weekday + ScheduleSource enums, `shift_templates` + `shift_template_days` schema & models

**Files:**
- Create: `backend/app/Domain/Schedule/Weekday.php`, `backend/app/Domain/Schedule/ScheduleSource.php`
- Create: `backend/database/migrations/2026_07_28_000001_create_shift_templates_table.php`
- Create: `backend/app/Models/ShiftTemplate.php`, `backend/app/Models/ShiftTemplateDay.php`
- Test: `backend/tests/Feature/Schema/ShiftTemplateSchemaTest.php`

**Interfaces:**
- Produces: `App\Domain\Schedule\Weekday` (int enum, `Monday=0`…`Sunday=6`), `App\Domain\Schedule\ScheduleSource` (string enum: `Override`, `Employee`, `Department`, `OfficeDefault`), `ShiftTemplate` (hasMany `days`), `ShiftTemplateDay` (casts `weekday`→`Weekday`).

- [ ] **Step 1: Write the failing schema test.** Mirror `ShiftTemplateSchemaTest` on `HolidaySchemaTest.php`'s live-constraint style (`pg_get_constraintdef`, insert-that-should-fail wrapped in `expect(fn () => …)->toThrow(QueryException::class)`).

```php
<?php
declare(strict_types=1);

use App\Domain\Schedule\Weekday;
use App\Models\Office;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a template with seven weekday rows and casts weekday to the enum', function (): void {
    $office = Office::factory()->create();
    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office Mon-Fri']);

    ShiftTemplateDay::create([
        'shift_template_id' => $template->id, 'weekday' => Weekday::Monday,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
    ]);
    ShiftTemplateDay::create([
        'shift_template_id' => $template->id, 'weekday' => Weekday::Saturday, 'is_rest' => true,
        'start_minute' => null, 'end_minute' => null, 'break_minutes' => null,
    ]);

    $mon = $template->days()->where('weekday', Weekday::Monday)->sole();
    expect($mon->weekday)->toBe(Weekday::Monday)
        ->and($mon->weekday->value)->toBe(0)
        ->and($mon->is_rest)->toBeFalse();
});

it('rejects a weekday outside 0..6', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'weekday' => 7, 'is_rest' => true, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a rest row carrying hours, and a working row missing hours (is_rest XOR hours)', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    // rest row with hours
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 1,
        'is_rest' => true, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    // working row missing hours
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 2,
        'is_rest' => false, 'start_minute' => null, 'end_minute' => null, 'break_minutes' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts a cross-midnight working row (end_minute up to start+1440) and rejects beyond', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Night']);
    // 17:00 -> 03:00 == start 1020, end 1620: valid
    ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => Weekday::Tuesday,
        'is_rest' => false, 'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 0]);
    // end == start (zero length) invalid
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 3,
        'is_rest' => false, 'start_minute' => 600, 'end_minute' => 600, 'break_minutes' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    // break >= span invalid
    expect(fn () => DB::table('shift_template_days')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id, 'weekday' => 4,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 540, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
    expect(true)->toBeTrue();
})->group('schema');

it('cascades day deletion when a template is deleted', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'X']);
    ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => Weekday::Monday,
        'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60]);
    $t->delete();
    expect(ShiftTemplateDay::count())->toBe(0);
});
```

- [ ] **Step 2: Run it, expect failure** (`./vendor/bin/pest tests/Feature/Schema/ShiftTemplateSchemaTest.php` — errors on missing tables/classes).

- [ ] **Step 3: The `Weekday` enum.**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/**
 * A day of the week, 0=Monday..6=Sunday — the backing int IS the index, aligned 1:1 with
 * the frontend's weekdayIndex. The one int-backed coded set in the system: a weekday's
 * identity is genuinely an ordinal, unlike DayType where the string is the meaning.
 */
enum Weekday: int
{
    case Monday = 0;
    case Tuesday = 1;
    case Wednesday = 2;
    case Thursday = 3;
    case Friday = 4;
    case Saturday = 5;
    case Sunday = 6;
}
```

- [ ] **Step 4: The `ScheduleSource` enum.**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/** Which layer of the resolution chain produced a ResolvedSchedule — for UI transparency and tests. */
enum ScheduleSource: string
{
    case Override = 'override';
    case Employee = 'employee';
    case Department = 'department';
    case OfficeDefault = 'office_default';
}
```

- [ ] **Step 5: The migration** `2026_07_28_000001_create_shift_templates_table.php`. Both tables in one migration.

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->timestamps();
            $table->index('office_id');
        });

        Schema::create('shift_template_days', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('shift_template_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('weekday');
            $table->boolean('is_rest');
            $table->smallInteger('start_minute')->nullable();
            $table->smallInteger('end_minute')->nullable();
            $table->smallInteger('break_minutes')->nullable();
            $table->timestamps();
            $table->unique(['shift_template_id', 'weekday']);
        });

        DB::statement('ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_weekday_check CHECK (weekday BETWEEN 0 AND 6)');
        // is_rest XOR hours: rest => all three null; working => all three present.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_rest_xor_hours_check CHECK (
              (is_rest = true  AND start_minute IS NULL AND end_minute IS NULL AND break_minutes IS NULL)
              OR
              (is_rest = false AND start_minute IS NOT NULL AND end_minute IS NOT NULL AND break_minutes IS NOT NULL)
            )
        SQL);
        // working-row minute ranges (only checked when hours present; rest rows short-circuit).
        DB::statement(<<<'SQL'
            ALTER TABLE shift_template_days ADD CONSTRAINT shift_template_days_minutes_check CHECK (
              is_rest = true OR (
                start_minute >= 0 AND start_minute < 1440
                AND end_minute > start_minute AND end_minute <= start_minute + 1440
                AND break_minutes >= 0 AND break_minutes < (end_minute - start_minute)
              )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_template_days');
        Schema::dropIfExists('shift_templates');
    }
};
```

- [ ] **Step 6: The models.** Mirror `Holiday.php` for `HasUuids` + `LogsActivity` + `getActivitylogOptions`.

`ShiftTemplate.php`:
```php
<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class ShiftTemplate extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = ['office_id', 'name'];

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return HasMany<ShiftTemplateDay> */
    public function days(): HasMany { return $this->hasMany(ShiftTemplateDay::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['office_id', 'name'])->logOnlyDirty()->useLogName('shift_template');
    }
}
```

`ShiftTemplateDay.php` (cast `weekday`→`Weekday`, `is_rest`→bool; no activity log — it rides its template):
```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Domain\Schedule\Weekday;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ShiftTemplateDay extends Model
{
    use HasUuids;

    protected $fillable = ['shift_template_id', 'weekday', 'is_rest', 'start_minute', 'end_minute', 'break_minutes'];

    protected function casts(): array
    {
        return ['weekday' => Weekday::class, 'is_rest' => 'boolean',
            'start_minute' => 'integer', 'end_minute' => 'integer', 'break_minutes' => 'integer'];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return BelongsTo<ShiftTemplate, ShiftTemplateDay> */
    public function template(): BelongsTo { return $this->belongsTo(ShiftTemplate::class, 'shift_template_id'); }
}
```

Add a `ShiftTemplateFactory` if the test needs it (mirror `HolidayFactory`); the tests above use `::create` directly, so a factory is optional — add one only if a later task needs `ShiftTemplate::factory()`.

- [ ] **Step 7: Run the schema test, expect PASS.** `./vendor/bin/pest tests/Feature/Schema/ShiftTemplateSchemaTest.php`

- [ ] **Step 8: Run Arch suite** (`./vendor/bin/pest --testsuite=Arch`) — confirms strict_types/final on the new files.

- [ ] **Step 9: Commit.**
```bash
git add backend/app/Domain/Schedule backend/app/Models/ShiftTemplate.php backend/app/Models/ShiftTemplateDay.php backend/database/migrations/2026_07_28_000001_create_shift_templates_table.php backend/tests/Feature/Schema/ShiftTemplateSchemaTest.php
git commit -m "Schedules: shift_templates + weekday rows, is_rest XOR hours, cross-midnight"
```

---

### Task 2: `schedule_assignments` schema & model

**Files:**
- Create: `backend/database/migrations/2026_07_28_000002_create_schedule_assignments_table.php`, `backend/app/Models/ScheduleAssignment.php`
- Test: `backend/tests/Feature/Schema/ScheduleAssignmentSchemaTest.php`

**Interfaces:**
- Consumes: `ShiftTemplate` (Task 1).
- Produces: `ScheduleAssignment` (belongsTo template, employee?, department?; effective-dated).

- [ ] **Step 1: Failing schema test** — assert: exactly-one-of employee/department (both null rejected, both set rejected); duplicate `(employee_id, effective_from)` rejected; duplicate `(department_id, effective_from)` rejected; two DIFFERENT employees may share an `effective_from`.

```php
<?php
declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function assignmentTemplate(): ShiftTemplate {
    $office = Office::factory()->create();
    return ShiftTemplate::create(['office_id' => $office->id, 'name' => 'T']);
}

it('rejects an assignment targeting neither employee nor department', function (): void {
    $t = assignmentTemplate();
    expect(fn () => DB::table('schedule_assignments')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'employee_id' => null, 'department_id' => null, 'effective_from' => '2026-08-01',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an assignment targeting both employee and department', function (): void {
    $t = assignmentTemplate();
    $emp = Employee::factory()->create();
    $dept = Department::factory()->create();
    expect(fn () => DB::table('schedule_assignments')->insert([
        'id' => Str::uuid7()->toString(), 'shift_template_id' => $t->id,
        'employee_id' => $emp->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a second assignment for the same employee on the same effective date', function (): void {
    $t = assignmentTemplate();
    $emp = Employee::factory()->create();
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(fn () => ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']))
        ->toThrow(QueryException::class);
});

it('allows two different employees to share an effective date', function (): void {
    $t = assignmentTemplate();
    $a = Employee::factory()->create(); $b = Employee::factory()->create();
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $a->id, 'effective_from' => '2026-08-01']);
    ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $b->id, 'effective_from' => '2026-08-01']);
    expect(ScheduleAssignment::count())->toBe(2);
});
```
(Check `Department::factory()` / `Employee::factory()` exist — they do, per M2 seeding. If a factory is missing, create the target rows via the seeder helpers instead.)

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Migration.**
```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('shift_template_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE schedule_assignments ADD CONSTRAINT schedule_assignments_one_target_check CHECK (
              (employee_id IS NOT NULL AND department_id IS NULL)
              OR (employee_id IS NULL AND department_id IS NOT NULL)
            )
        SQL);
        // Partial uniques: one assignment per target per effective date.
        DB::statement('CREATE UNIQUE INDEX schedule_assignments_employee_effective_unique ON schedule_assignments (employee_id, effective_from) WHERE employee_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX schedule_assignments_department_effective_unique ON schedule_assignments (department_id, effective_from) WHERE department_id IS NOT NULL');
    }

    public function down(): void { Schema::dropIfExists('schedule_assignments'); }
};
```

- [ ] **Step 4: Model** `ScheduleAssignment.php` — `HasUuids` + `LogsActivity` (log `shift_template_id`, `employee_id`, `department_id`, `effective_from`; log name `schedule_assignment`); casts `effective_from`→`date`; `belongsTo` template/employee/department. Mirror `Holiday.php`.

- [ ] **Step 5: Run schema test → PASS. Step 6: Arch suite. Step 7: Commit.**
```bash
git commit -m "Schedules: schedule_assignments, exactly-one-target, effective-dated"
```

---

### Task 3: `schedule_overrides` schema & model + `offices.default_shift_template_id`

**Files:**
- Create: `backend/database/migrations/2026_07_28_000003_create_schedule_overrides_table.php`, `backend/database/migrations/2026_07_28_000004_add_default_shift_template_to_offices.php`, `backend/app/Models/ScheduleOverride.php`
- Modify: `backend/app/Models/Office.php` (add `defaultShiftTemplate` belongsTo + `shiftTemplates` hasMany + fillable)
- Test: `backend/tests/Feature/Schema/ScheduleOverrideSchemaTest.php`

**Interfaces:**
- Produces: `ScheduleOverride` (employee_id, date, is_rest XOR hours, note?); `Office::defaultShiftTemplate()`, `Office::shiftTemplates()`.

- [ ] **Step 1: Failing schema test** — mirror Task 1's is_rest-XOR-hours + minute-range checks for `schedule_overrides`; assert `unique(employee_id, date)`; assert `offices.default_shift_template_id` accepts a template id and nulls on template delete.

```php
<?php
declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects a second override for the same employee and date', function (): void {
    $emp = Employee::factory()->create();
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-22', 'is_rest' => true]);
    expect(fn () => ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-22', 'is_rest' => true]))
        ->toThrow(QueryException::class);
});

it('rejects a rest override carrying hours (is_rest XOR hours)', function (): void {
    $emp = Employee::factory()->create();
    expect(fn () => DB::table('schedule_overrides')->insert([
        'id' => Str::uuid7()->toString(), 'employee_id' => $emp->id, 'date' => '2026-08-22',
        'is_rest' => true, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('sets an office default template and nulls it when the template is deleted', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Default']);
    $office->update(['default_shift_template_id' => $t->id]);
    expect($office->fresh()->default_shift_template_id)->toBe($t->id);
    $t->delete();
    expect($office->fresh()->default_shift_template_id)->toBeNull();
});
```

- [ ] **Step 2: Run, expect failure. Step 3: overrides migration** (same three CHECKs as Task 1's day table, plus `unique(employee_id, date)`, `note text nullable`, `created_by`). **Step 4: offices column migration** (`$table->foreignUuid('default_shift_template_id')->nullable()->after('timezone')->constrained('shift_templates')->nullOnDelete();`). **Step 5: `ScheduleOverride` model** (HasUuids + LogsActivity name `schedule_override`; cast `date`→date, `is_rest`→bool, minutes→int; belongsTo employee). **Step 6: `Office` model** additions:
```php
/** @return HasMany<ShiftTemplate> */
public function shiftTemplates(): HasMany { return $this->hasMany(ShiftTemplate::class); }
/** @return BelongsTo<ShiftTemplate, Office> */
public function defaultShiftTemplate(): BelongsTo { return $this->belongsTo(ShiftTemplate::class, 'default_shift_template_id'); }
```
(add `default_shift_template_id` to `Office::$fillable`).

- [ ] **Step 7: schema test → PASS. Step 8: Arch. Step 9: Commit.**
```bash
git commit -m "Schedules: schedule_overrides + offices.default_shift_template_id"
```

---

### Task 4: `ScheduleResolver` + `ResolvedSchedule` (the crown jewel)

**Files:**
- Create: `backend/app/Domain/Schedule/ResolvedSchedule.php`, `backend/app/Domain/Schedule/ScheduleResolver.php`
- Create: `backend/app/Exceptions/Domain/OfficeHasNoDefaultTemplate.php`, `backend/app/Exceptions/Domain/EmployeeHasNoOffice.php`
- Test: `backend/tests/Feature/Schedule/ScheduleResolverTest.php`

**Interfaces:**
- Consumes: `ShiftTemplate`/`ShiftTemplateDay`/`ScheduleAssignment`/`ScheduleOverride` (Tasks 1–3), `Office::defaultShiftTemplate`, `Employee::$current_office_id`/`$current_department_id`, `Weekday`, `ScheduleSource`.
- Produces: `ScheduleResolver::resolve(Employee $employee, string $date): ResolvedSchedule`. `ResolvedSchedule` public readonly: `bool $isRestDay`, `?int $startMinute`, `?int $endMinute`, `?int $breakMinutes`, `int $scheduledMinutes`, `ScheduleSource $source`.

- [ ] **Step 1: Write the failing table-driven test.** Cover every branch. Build a small fixture helper that makes an office + a Mon–Fri-08:00–18:00-rest-weekends template and returns `[office, template]`.

```php
<?php
declare(strict_types=1);

use App\Domain\Schedule\ScheduleResolver;
use App\Domain\Schedule\ScheduleSource;
use App\Domain\Schedule\Weekday;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Mon-Fri 08:00-18:00 (60m break), Sat/Sun rest. */
function weekdayTemplate(Office $office, string $name = 'Office'): ShiftTemplate {
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => $name]);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080, 'break_minutes' => $rest ? null : 60]);
    }
    return $t;
}

function resolver(): ScheduleResolver { return app(ScheduleResolver::class); }

it('resolves a weekday from the office default when nothing else is assigned', function (): void {
    $office = Office::factory()->create();
    $t = weekdayTemplate($office);
    $office->update(['default_shift_template_id' => $t->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => null]);

    $mon = resolver()->resolve($emp, '2026-08-03'); // Monday
    expect($mon->isRestDay)->toBeFalse()
        ->and($mon->startMinute)->toBe(480)->and($mon->endMinute)->toBe(1080)
        ->and($mon->scheduledMinutes)->toBe(540) // 600 span - 60 break
        ->and($mon->source)->toBe(ScheduleSource::OfficeDefault);

    $sat = resolver()->resolve($emp, '2026-08-08'); // Saturday
    expect($sat->isRestDay)->toBeTrue()->and($sat->scheduledMinutes)->toBe(0);
});

it('prefers an employee assignment over the office default', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $assigned = weekdayTemplate($office, 'Assigned');
    ScheduleAssignment::create(['shift_template_id' => $assigned->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Employee);
});

it('prefers a department assignment over the office default, and an employee assignment over the department', function (): void {
    $office = Office::factory()->create();
    $dept = Department::factory()->create(['office_id' => $office->id]);
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id, 'current_department_id' => $dept->id]);
    ScheduleAssignment::create(['shift_template_id' => weekdayTemplate($office, 'Dept')->id, 'department_id' => $dept->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Department);
    ScheduleAssignment::create(['shift_template_id' => weekdayTemplate($office, 'Emp')->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    expect(resolver()->resolve($emp, '2026-08-03')->source)->toBe(ScheduleSource::Employee);
});

it('uses the greatest effective_from that is <= the date, ignoring future assignments', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office, 'Default'); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $aug = weekdayTemplate($office, 'Aug'); $sep = weekdayTemplate($office, 'Sep');
    ScheduleAssignment::create(['shift_template_id' => $aug->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    ScheduleAssignment::create(['shift_template_id' => $sep->id, 'employee_id' => $emp->id, 'effective_from' => '2026-09-01']);
    expect(resolver()->resolve($emp, '2026-08-15')->startMinute)->not->toBeNull(); // Aug applies
    // resolve for a date before ANY assignment -> falls through to office default
    expect(resolver()->resolve($emp, '2026-07-15')->source)->toBe(ScheduleSource::OfficeDefault);
});

it('lets a per-date override win over everything, for both rest and custom hours', function (): void {
    $office = Office::factory()->create();
    $def = weekdayTemplate($office); $office->update(['default_shift_template_id' => $def->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    // Make a normally-working Monday a rest day.
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-03', 'is_rest' => true]);
    $r = resolver()->resolve($emp, '2026-08-03');
    expect($r->isRestDay)->toBeTrue()->and($r->source)->toBe(ScheduleSource::Override);
    // Make a normally-rest Saturday a working day.
    ScheduleOverride::create(['employee_id' => $emp->id, 'date' => '2026-08-08', 'is_rest' => false,
        'start_minute' => 540, 'end_minute' => 1020, 'break_minutes' => 60]);
    $s = resolver()->resolve($emp, '2026-08-08');
    expect($s->isRestDay)->toBeFalse()->and($s->scheduledMinutes)->toBe(420)->and($s->source)->toBe(ScheduleSource::Override);
});

it('resolves a cross-midnight night shift with end_minute beyond 1439', function (): void {
    $office = Office::factory()->create();
    $t = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Night']);
    foreach (Weekday::cases() as $wd) {
        ShiftTemplateDay::create(['shift_template_id' => $t->id, 'weekday' => $wd, 'is_rest' => false,
            'start_minute' => 1020, 'end_minute' => 1620, 'break_minutes' => 0]); // 17:00 -> 03:00
    }
    $office->update(['default_shift_template_id' => $t->id]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    $r = resolver()->resolve($emp, '2026-08-04'); // Tuesday
    expect($r->endMinute)->toBe(1620)->and($r->scheduledMinutes)->toBe(600);
});

it('throws when the office has no default template', function (): void {
    $office = Office::factory()->create(['default_shift_template_id' => null]);
    $emp = Employee::factory()->create(['current_office_id' => $office->id]);
    expect(fn () => resolver()->resolve($emp, '2026-08-03'))->toThrow(OfficeHasNoDefaultTemplate::class);
});

it('throws when the employee has no office', function (): void {
    $emp = Employee::factory()->create(['current_office_id' => null]);
    expect(fn () => resolver()->resolve($emp, '2026-08-03'))->toThrow(EmployeeHasNoOffice::class);
});
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: `ResolvedSchedule` value object.**
```php
<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

/** The resolved schedule for one (employee, date). scheduledMinutes = (end-start)-break, 0 when rest. */
final class ResolvedSchedule
{
    public function __construct(
        public readonly bool $isRestDay,
        public readonly ?int $startMinute,
        public readonly ?int $endMinute,
        public readonly ?int $breakMinutes,
        public readonly int $scheduledMinutes,
        public readonly ScheduleSource $source,
    ) {}

    public static function rest(ScheduleSource $source): self
    {
        return new self(true, null, null, null, 0, $source);
    }

    public static function working(int $start, int $end, int $break, ScheduleSource $source): self
    {
        return new self(false, $start, $end, $break, ($end - $start) - $break, $source);
    }
}
```

- [ ] **Step 4: The two domain exceptions.** Mirror `EmploymentRecordExists.php`. `OfficeHasNoDefaultTemplate` (422 `office_has_no_default_template`, details `{office_id}`), `EmployeeHasNoOffice` (422 `employee_has_no_office`, details `{employee_id}`).

- [ ] **Step 5: `ScheduleResolver`.** A Domain query class; no transaction; queries like `OfficeScope`. Turn a `ShiftTemplateDay`/`ScheduleOverride` row into a `ResolvedSchedule` via a shared private helper.
```php
<?php
declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Exceptions\Domain\EmployeeHasNoOffice;
use App\Exceptions\Domain\OfficeHasNoDefaultTemplate;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;

final class ScheduleResolver
{
    public function resolve(Employee $employee, string $date): ResolvedSchedule
    {
        $override = ScheduleOverride::query()
            ->where('employee_id', $employee->id)->whereDate('date', $date)->first();
        if ($override !== null) {
            return $this->fromShape($override->is_rest, $override->start_minute, $override->end_minute,
                $override->break_minutes, ScheduleSource::Override);
        }

        $employeeAssignment = $this->latestAssignment(
            ScheduleAssignment::query()->where('employee_id', $employee->id), $date);
        if ($employeeAssignment !== null) {
            return $this->fromTemplate($employeeAssignment->shift_template_id, $date, ScheduleSource::Employee);
        }

        if ($employee->current_department_id !== null) {
            $deptAssignment = $this->latestAssignment(
                ScheduleAssignment::query()->where('department_id', $employee->current_department_id), $date);
            if ($deptAssignment !== null) {
                return $this->fromTemplate($deptAssignment->shift_template_id, $date, ScheduleSource::Department);
            }
        }

        if ($employee->current_office_id === null) {
            throw new EmployeeHasNoOffice($employee->id);
        }
        $office = $employee->currentOffice; // belongsTo current_office_id — add if missing (see note)
        $defaultId = $office?->default_shift_template_id;
        if ($defaultId === null) {
            throw new OfficeHasNoDefaultTemplate($employee->current_office_id);
        }
        return $this->fromTemplate($defaultId, $date, ScheduleSource::OfficeDefault);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<ScheduleAssignment> $query */
    private function latestAssignment(\Illuminate\Database\Eloquent\Builder $query, string $date): ?ScheduleAssignment
    {
        return $query->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }

    private function fromTemplate(string $templateId, string $date, ScheduleSource $source): ResolvedSchedule
    {
        $weekday = Weekday::from(self::weekdayIndex($date));
        /** @var ShiftTemplateDay $day */
        $day = ShiftTemplateDay::query()->where('shift_template_id', $templateId)->where('weekday', $weekday->value)->sole();
        return $this->fromShape($day->is_rest, $day->start_minute, $day->end_minute, $day->break_minutes, $source);
    }

    private function fromShape(bool $isRest, ?int $start, ?int $end, ?int $break, ScheduleSource $source): ResolvedSchedule
    {
        return $isRest
            ? ResolvedSchedule::rest($source)
            : ResolvedSchedule::working((int) $start, (int) $end, (int) $break, $source);
    }

    /** 0=Monday..6=Sunday from a Y-m-d string, matching Weekday and the frontend. */
    public static function weekdayIndex(string $date): int
    {
        // Carbon: dayOfWeekIso is 1=Mon..7=Sun; subtract 1 for 0=Mon..6=Sun.
        return (int) \Illuminate\Support\Carbon::parse($date)->dayOfWeekIso - 1;
    }
}
```
**Note:** the resolver uses `$employee->currentOffice`. If `Employee` has no `currentOffice()` relation, add `public function currentOffice(): BelongsTo { return $this->belongsTo(Office::class, 'current_office_id'); }` to `Employee.php` in this task (it's a Task-4 dependency). Verify first — M2 may already define it.

- [ ] **Step 6: Run the resolver test → PASS.** Fix until all branches green.
- [ ] **Step 7: Arch suite.**
- [ ] **Step 8: Commit.**
```bash
git commit -m "Schedules: ScheduleResolver — override -> employee -> department -> office default"
```

---

### Task 5: Templates — list + create endpoints

**Files:**
- Create: controllers `backend/app/Http/Controllers/Office/Schedules/{ListTemplates,CreateTemplate}Controller.php`; requests `backend/app/Http/Requests/{ListShiftTemplates,CreateShiftTemplate}Request.php`; action `backend/app/Actions/Schedules/CreateShiftTemplate.php` (+ `CreateShiftTemplateInput.php`); resource `backend/app/Http/Resources/ShiftTemplateResource.php`
- Modify: `backend/routes/api.php` (a `Route::prefix('office')` group already exists from M4a — add the routes there)
- Test: `backend/tests/Feature/Office/ShiftTemplateReadWriteTest.php`

**Interfaces:**
- Consumes: `OfficeScope::administered`, `ShiftTemplate`, `Weekday`.
- Produces: `GET /office/shift-templates?office=<uuid>` → `ShiftTemplateResource[]`; `POST /office/shift-templates` `{office_id,name,days:[7]}` → 201. `ShiftTemplateResource` shape: `{id, office_id, name, days:[{weekday,is_rest,start_minute,end_minute,break_minutes}]}` (days ordered by weekday).

- [ ] **Step 1: Failing feature test.** Mirror `HolidayReadWriteTest.php`'s harness (`hrAdminOf`, `Sanctum::actingAs`). Cover: create returns 201 with the 7 days echoed; out-of-scope office 404 byte-identical to a fabricated office; list returns the office's templates; **an invalid `days` (not exactly 7 weekdays, or a rest day carrying hours) is 400**; the create logs an activity with the template as uuid subject.

```php
it('creates a template with seven days for an administered office, and logs it', function (): void {
    $office = holidayOffice(); // reuse the M4a helper name or define an equivalent
    $hr = hrAdminOf($office);
    Sanctum::actingAs($hr);
    $days = collect(range(0,6))->map(fn (int $wd) => $wd < 5
        ? ['weekday' => $wd, 'is_rest' => false, 'start_minute' => 480, 'end_minute' => 1080, 'break_minutes' => 60]
        : ['weekday' => $wd, 'is_rest' => true])->all();
    $res = $this->postJson('/api/v1/office/shift-templates', ['office_id' => $office->id, 'name' => 'Office', 'days' => $days])
        ->assertCreated();
    expect($res->json('data.days'))->toHaveCount(7);
    expect(\Spatie\Activitylog\Models\Activity::where('subject_id', $res->json('data.id'))->exists())->toBeTrue();
});

it('rejects days that are not exactly the seven weekdays', function (): void {
    $office = holidayOffice(); $hr = hrAdminOf($office); Sanctum::actingAs($hr);
    $this->postJson('/api/v1/office/shift-templates', ['office_id' => $office->id, 'name' => 'X',
        'days' => [['weekday' => 0, 'is_rest' => true]]])
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('404s creating for an office not administered, identically to a fabricated office', function (): void {
    $mine = holidayOffice(); $other = holidayOffice(); $hr = hrAdminOf($mine); Sanctum::actingAs($hr);
    $body = fn (string $id) => ['office_id' => $id, 'name' => 'X', 'days' => collect(range(0,6))
        ->map(fn (int $wd) => ['weekday' => $wd, 'is_rest' => true])->all()];
    $oos = $this->postJson('/api/v1/office/shift-templates', $body($other->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/shift-templates', $body((string) \Illuminate\Support\Str::uuid7()))->assertStatus(404);
    $oos->assertExactJson($fake->json());
});
```
(If M4a's `holidayOffice()`/`hrAdminOf()` helpers are file-local to `HolidayReadWriteTest.php`, copy equivalent helpers into this test file — Pest helper functions are file-scoped.)

- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: `CreateShiftTemplateRequest`** — shape-only, NO `exists:`. Rules:
```php
public function rules(): array {
    return [
        'office_id' => ['required', 'uuid'],
        'name' => ['required', 'string'],
        'days' => ['required', 'array', 'size:7'],
        'days.*.weekday' => ['required', 'integer', 'between:0,6'],
        'days.*.is_rest' => ['required', 'boolean'],
        'days.*.start_minute' => ['nullable', 'integer', 'between:0,1439', 'required_if:days.*.is_rest,false'],
        'days.*.end_minute' => ['nullable', 'integer', 'between:1,2879', 'required_if:days.*.is_rest,false'],
        'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'required_if:days.*.is_rest,false'],
    ];
}
public function withValidator(\Illuminate\Validation\Validator $v): void {
    $v->after(function ($v): void {
        $weekdays = collect($this->input('days', []))->pluck('weekday')->map(fn ($w) => (int) $w);
        if ($weekdays->unique()->sort()->values()->all() !== range(0, 6)) {
            $v->errors()->add('days', 'days must cover each weekday 0..6 exactly once');
        }
        foreach ($this->input('days', []) as $i => $day) {
            if (($day['is_rest'] ?? null) === false) {
                $s = (int) ($day['start_minute'] ?? 0); $e = (int) ($day['end_minute'] ?? 0); $b = (int) ($day['break_minutes'] ?? 0);
                if (! ($e > $s && $e <= $s + 1440 && $b < ($e - $s))) {
                    $v->errors()->add("days.$i", 'invalid working-day minutes');
                }
            }
        }
    });
}
```
- [ ] **Step 4: `ListShiftTemplatesRequest`** — `office` required uuid (mirror `ListHolidaysRequest`, minus `year`).
- [ ] **Step 5: `CreateShiftTemplate` action** — one transaction: create the template, insert the 7 day rows, return the template with `days`. Mirror `CreateHoliday.php`'s transaction shape. Input DTO carries `officeId`, `name`, and a `days` array of value arrays.
- [ ] **Step 6: `ShiftTemplateResource`** — `{id, office_id, name, days: [...ordered by weekday]}`.
- [ ] **Step 7: Controllers** — `ListTemplatesController` (mirror `Holidays/ListController`: `OfficeScope::administered($request->user(), $request->string('office')) ?? throw new NotFoundHttpException;` then `$office->shiftTemplates()->with('days')->get()`), `CreateTemplateController` (mirror `Holidays/CreateController`: resolve office → 404, call action, 201).
- [ ] **Step 8: Routes** in `routes/api.php` inside the existing office group:
```php
Route::get('/shift-templates', ListTemplatesController::class);
Route::post('/shift-templates', CreateTemplateController::class);
```
- [ ] **Step 9: Test → PASS. Step 10: Arch. Step 11: Commit** `git commit -m "Schedules: list + create shift templates, office-scoped, 404 not 403"`.

---

### Task 6: Templates — show + update + delete (with in-use guard)

**Files:**
- Create: controllers `Office/Schedules/{ShowTemplate,UpdateTemplate,DeleteTemplate}Controller.php`; request `UpdateShiftTemplateRequest.php`; actions `Actions/Schedules/{UpdateShiftTemplate,DeleteShiftTemplate}.php`; exception `backend/app/Exceptions/Domain/TemplateInUse.php`
- Modify: `backend/routes/api.php`
- Test: extend `ShiftTemplateReadWriteTest.php`

**Interfaces:**
- Consumes: `OfficeScope::administers`, route-model-bound `{template}`.
- Produces: `GET/PATCH/DELETE /office/shift-templates/{template}`; `TemplateInUse` (422 `template_in_use`).

- [ ] **Step 1: Failing tests** — show returns days; update replaces name + days (all 7 re-validated); **delete refuses (422 `template_in_use`) when the template is an office default OR has an assignment**, and succeeds (204) otherwise; out-of-scope `{template}` 404 byte-identical to a fabricated id (route-model binding + `OfficeScope::administers` on `$template->office_id`).

```php
it('refuses to delete a template that is an office default', function (): void {
    $office = holidayOffice(); $hr = hrAdminOf($office); Sanctum::actingAs($hr);
    $t = \App\Models\ShiftTemplate::create(['office_id' => $office->id, 'name' => 'D']);
    $office->update(['default_shift_template_id' => $t->id]);
    $this->deleteJson("/api/v1/office/shift-templates/{$t->id}")
        ->assertStatus(422)->assertJsonPath('error.code', 'template_in_use');
});

it('refuses to delete a template that has an assignment', function (): void {
    $office = holidayOffice(); $hr = hrAdminOf($office); Sanctum::actingAs($hr);
    $t = \App\Models\ShiftTemplate::create(['office_id' => $office->id, 'name' => 'A']);
    $emp = \App\Models\Employee::factory()->create(['current_office_id' => $office->id]);
    \App\Models\ScheduleAssignment::create(['shift_template_id' => $t->id, 'employee_id' => $emp->id, 'effective_from' => '2026-08-01']);
    $this->deleteJson("/api/v1/office/shift-templates/{$t->id}")->assertStatus(422)->assertJsonPath('error.code', 'template_in_use');
});
```

- [ ] **Step 2–7:** `UpdateShiftTemplateRequest` (name + days, same `withValidator` completeness/minute checks as create — DRY: extract the shared validation into a small trait `ValidatesShiftDays` in `app/Http/Requests/Concerns/` and use it in both requests). `UpdateShiftTemplate` action (one transaction: update name, delete + re-insert the 7 day rows). `DeleteShiftTemplate` action: guard first —
```php
if ($template->office->default_shift_template_id === $template->id
    || ScheduleAssignment::where('shift_template_id', $template->id)->exists()) {
    throw new TemplateInUse($template->id);
}
$template->delete();
```
`TemplateInUse` mirrors `HolidayExists` (422). Controllers mirror `Holidays/{Update,Delete}Controller` (route-bound `{template}`, `if (! OfficeScope::administers($request->user(), $template->office_id)) throw new NotFoundHttpException;`). Routes: `GET/PATCH/DELETE /shift-templates/{template}`.
- [ ] **Step 8: Test → PASS. Step 9: Arch. Step 10: Commit** `git commit -m "Schedules: show, update, delete templates; in-use delete is 422"`.

---

### Task 7: Assignments — list + create + delete

**Files:**
- Create: controllers `Office/Schedules/{ListAssignments,CreateAssignment,DeleteAssignment}Controller.php`; requests `{ListScheduleAssignments,CreateScheduleAssignment}Request.php`; action `Actions/Schedules/CreateScheduleAssignment.php`; resource `ScheduleAssignmentResource.php`; exception `ScheduleAssignmentExists.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Office/ScheduleAssignmentTest.php`

**Interfaces:**
- Produces: `GET/POST /office/schedule-assignments`, `DELETE /office/schedule-assignments/{assignment}`; `ScheduleAssignmentResource` `{id, shift_template_id, employee_id, department_id, effective_from}`; `ScheduleAssignmentExists` (422).

- [ ] **Step 1: Failing tests** — create with `employee_id` 201; create with `department_id` 201; **body with both or neither → 400**; duplicate `(employee, effective_from)` → 422 `schedule_assignment_exists`; the target's office must be administered — an assignment for an employee in another office 404s byte-identical to a fabricated employee id; delete 204.

Scope note: the controller resolves the **target's** office. For `employee_id`: `Employee::find(id)?->current_office_id`; for `department_id`: `Department::find(id)?->office_id`. Then `OfficeScope::administers($user, $officeId)` or `throw new NotFoundHttpException`. The FormRequest stays shape-only (uuid), so a fabricated employee and an out-of-scope one both 404. Also verify the `shift_template_id` belongs to that same office (else 404) — a template from another office cannot be assigned.

- [ ] **Step 2–7:** `CreateScheduleAssignmentRequest` — `shift_template_id` uuid required; `employee_id`/`department_id` nullable uuid; `effective_from` required date; `withValidator` asserting exactly one of employee/department present (else `errors->add`). `CreateScheduleAssignment` action — resolve nothing about scope (controller did it); pre-check duplicate under a lock (mirror `CreateHoliday`'s lock-then-check, locking the target employee/department row) and throw `ScheduleAssignmentExists`; else create. Controller resolves target office + template office, 404s, calls action, 201. `DeleteAssignmentController` route-bound, scope via `$assignment` target office. Routes.
- [ ] **Step 8: Test → PASS. Step 9: Arch. Step 10: Commit** `git commit -m "Schedules: assignment CRUD, target-office-scoped, duplicate is 422"`.

---

### Task 8: Overrides — list + create + update + delete

**Files:**
- Create: controllers `Office/Schedules/{ListOverrides,CreateOverride,UpdateOverride,DeleteOverride}Controller.php`; requests `{ListScheduleOverrides,CreateScheduleOverride,UpdateScheduleOverride}Request.php`; action `Actions/Schedules/CreateScheduleOverride.php`; resource `ScheduleOverrideResource.php`; exception `ScheduleOverrideExists.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Office/ScheduleOverrideTest.php`

**Interfaces:**
- Produces: `GET /office/schedule-overrides?office=&employee=&month=<YYYY-MM>`, `POST`, `PATCH/{override}`, `DELETE/{override}`; `ScheduleOverrideResource` `{id, employee_id, date, is_rest, start_minute, end_minute, break_minutes, note}`; `ScheduleOverrideExists` (422).

- [ ] **Step 1: Failing tests** — create a rest override (201); create a working override with hours (201); is_rest XOR hours enforced by the request (400 when rest carries hours or working omits them — reuse `ValidatesShiftDays`-style rule for a single day shape); duplicate `(employee, date)` → 422 `schedule_override_exists`; PATCH edits an existing override; the employee's office must be administered (out-of-scope employee 404 byte-identical to a fabricated id); list filters by employee + month.
- [ ] **Step 2–7:** requests (shape-only, single-day is_rest XOR hours + minute-range), `CreateScheduleOverride` action (lock the employee row, pre-check `(employee,date)`, throw `ScheduleOverrideExists`, else create), controllers resolve the employee's office for scope. Routes.
- [ ] **Step 8: Test → PASS. Step 9: Arch. Step 10: Commit** `git commit -m "Schedules: per-date override CRUD, employee-office-scoped"`.

---

### Task 9: Office default + the resolved read endpoint

**Files:**
- Create: controllers `Office/Schedules/{SetDefaultTemplate,ResolvedScheduleController}.php`; requests `{SetDefaultTemplate,ResolvedSchedule}Request.php`; resource `ResolvedScheduleResource.php` (or inline map)
- Modify: `backend/routes/api.php`, `backend/app/Http/Resources/OfficeResource.php` if one exists (add `default_shift_template_id`) — else the set-default controller returns the fresh office minimally
- Test: `backend/tests/Feature/Office/OfficeDefaultTemplateTest.php`, `backend/tests/Feature/Office/ResolvedScheduleTest.php`

**Interfaces:**
- Produces: `PATCH /office/default-template {office_id, template_id}` → 200; `GET /office/schedule/resolved?employee=<uuid>&month=<YYYY-MM>` → `{ data: { "YYYY-MM-DD": {is_rest, start_minute, end_minute, break_minutes, scheduled_minutes, source} } }`.

- [ ] **Step 1: Failing tests** —
  - set-default: office + template both in scope → 200 and `offices.default_shift_template_id` updated; **template from another office → 404** (byte-identical to a fabricated template id); office out of scope → 404.
  - resolved: for a seeded assigned employee, every date of the month is present with correct `is_rest`/`scheduled_minutes`/`source`; a night-shift template yields `end_minute:1620`; an office with no default → 422 `office_has_no_default_template`; out-of-scope employee → 404; malformed `month` → 400.
- [ ] **Step 2–6:** `SetDefaultTemplateRequest` (office_id, template_id both uuid, shape-only). `SetDefaultTemplateController`: resolve office via `OfficeScope::administered` (404), resolve template via `$office->shiftTemplates()->find($templateId) ?? throw NotFound` (this enforces template-belongs-to-office AND scope in one 404), `$office->update(['default_shift_template_id' => $template->id])`, activity-log against the office, return the office. `ResolvedScheduleRequest` (employee uuid, month `regex:/^\d{4}-(0[1-9]|1[0-2])$/`). `ResolvedScheduleController`: resolve employee via a scoped lookup — `Employee` whose `current_office_id` is administered by the user, else 404 (build the date list for the month, call `ScheduleResolver::resolve` per date, catch `OfficeHasNoDefaultTemplate`/`EmployeeHasNoOffice` and let the envelope map them to 422, map each `ResolvedSchedule` to the wire shape keyed by date). Routes: `PATCH /default-template`, `GET /schedule/resolved`.
- [ ] **Step 7: Test → PASS. Step 8: Arch. Step 9: Commit** `git commit -m "Schedules: set office default (template must belong to office) + resolved read"`.

---

### Task 10: Frontend — keys, api client, hooks

**Files:**
- Modify: `frontend/web/src/lib/keys.ts`, `frontend/web/src/lib/api.ts`
- Create: `frontend/web/src/hooks/useShiftTemplates.ts`, `useScheduleAssignments.ts`, `useScheduleOverrides.ts`, `useResolvedMonth.ts`
- Test: `frontend/web/src/hooks/useShiftTemplates.test.tsx` (and light tests for the others)

**Interfaces:**
- Produces: `keys.schedules` factory; `api.shiftTemplates/scheduleAssignments/scheduleOverrides/resolvedSchedule/officeDefaultTemplate`; the four hooks + their mutations, invalidating the matching keys.

- [ ] **Step 1: Extend `keys.ts`.**
```ts
schedules: {
  templates: (officeId: string) => ['schedules', 'templates', officeId] as const,
  assignments: (officeId: string) => ['schedules', 'assignments', officeId] as const,
  overrides: (employeeId: string, month: string) => ['schedules', 'overrides', employeeId, month] as const,
  resolved: (employeeId: string, month: string) => ['schedules', 'resolved', employeeId, month] as const,
},
```
- [ ] **Step 2: Wire types + `api` calls in `api.ts`** (verified against the Task 5–9 resources). Weekday is a number 0–6 on the wire; source is the string union. Add:
```ts
export type Weekday = 0 | 1 | 2 | 3 | 4 | 5 | 6
export type ScheduleSource = 'override' | 'employee' | 'department' | 'office_default'
export type ShiftDay = { weekday: Weekday; is_rest: boolean; start_minute: number | null; end_minute: number | null; break_minutes: number | null }
export type ShiftTemplate = { id: string; office_id: string; name: string; days: ShiftDay[] }
export type ScheduleAssignment = { id: string; shift_template_id: string; employee_id: string | null; department_id: string | null; effective_from: string }
export type ScheduleOverride = { id: string; employee_id: string; date: string; is_rest: boolean; start_minute: number | null; end_minute: number | null; break_minutes: number | null; note: string | null }
export type ResolvedDay = { is_rest: boolean; start_minute: number | null; end_minute: number | null; break_minutes: number | null; scheduled_minutes: number; source: ScheduleSource }
export type ResolvedMonth = Record<string, ResolvedDay>
```
plus `api.shiftTemplates.{list(office),create(body),get(id),update(id,body),delete(id)}`, `api.scheduleAssignments.{list(params),create(body),delete(id)}`, `api.scheduleOverrides.{list(params),create(body),update(id,body),delete(id)}`, `api.officeDefaultTemplate.set({office_id,template_id})`, `api.resolvedSchedule.get(employee,month)`. Mirror `api.holidays.*` exactly (paths WITHOUT `/api/v1`).
- [ ] **Step 3: The hooks.** `useShiftTemplates(officeId)` (query) + `useCreateShiftTemplate/useUpdateShiftTemplate/useDeleteShiftTemplate` mutations invalidating `keys.schedules.templates(officeId)`. `useScheduleAssignments(officeId)` + mutations. `useScheduleOverrides(employeeId, month)` + mutations invalidating both the overrides key AND the resolved key (an override changes resolution). `useResolvedMonth(employeeId, month)` query. Mirror `useHolidays.ts` shape.
- [ ] **Step 4: Tests** — mirror `useHolidays.test.tsx`: each query hits the right key/URL; a create mutation invalidates; the override mutation invalidates BOTH overrides and resolved keys.
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint`. Step 6: Commit** `git commit -m "Schedules(web): keys, api client, query hooks"`.

---

### Task 11: Frontend — `WeekEditor` component

**Files:**
- Create: `frontend/web/src/components/domain/WeekEditor.tsx`, `frontend/web/src/lib/minutes.ts` (a `minutesToHHMM`/`hhmmToMinutes` pair — check `lib/duration.ts` first and extend it instead if it fits)
- Test: `frontend/web/src/components/domain/WeekEditor.test.tsx`, and `lib/minutes.test.ts`

**Interfaces:**
- Produces: `WeekEditor({ value, onChange })` where `value: ShiftDay[]` (7 entries) and `onChange(next: ShiftDay[])`. Renders 7 rows (Mon–Sun via a `['Mon',…]` label array), each: a rest toggle; when working, `HH:MM` start/end inputs + a break-minutes input; a small "+1 day" hint when `end_minute >= 1440`.

- [ ] **Step 1: `lib/minutes.ts` + failing test** — `minutesToHHMM(1620) === '03:00 (+1)'`? No — keep it pure: `minutesToHHMM(90) === '01:30'`, `minutesToHHMM(1620)` should render `'03:00'` with the caller deciding the +1 badge; `hhmmToMinutes('08:00') === 480`. Test round-trips and the >1439 case (`minutesToHHMM(1500) === '01:00'` conceptually — decide: the editor stores absolute minutes, so a night-shift end is entered as an HH:MM plus a "next day" checkbox that adds 1440). **Simpler contract:** the editor keeps `start`/`end` HH:MM plus a `crossesMidnight` boolean per working row; `end_minute = hhmmToMinutes(end) + (crossesMidnight ? 1440 : 0)`. Test that mapping.
- [ ] **Step 2–4:** implement `lib/minutes.ts` and `WeekEditor` (token-only styling; reuse `TextInput`/`Select`/a checkbox styled per `carbon.css`; each row emits an updated `ShiftDay`). Component test: toggling rest disables/clears hours; entering 08:00–18:00/60 yields `{is_rest:false,start_minute:480,end_minute:1080,break_minutes:60}`; checking "crosses midnight" on 17:00→03:00 yields `end_minute:1620`.
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint`. Step 6: Commit** `git commit -m "Schedules(web): WeekEditor + minutes helpers"`.

---

### Task 12: Frontend — `/office/schedules` screen: templates + office default + nav

**Files:**
- Create: `frontend/web/src/app/(app)/office/schedules/page.tsx`
- Modify: `frontend/web/src/components/SideNav.tsx` (`ROUTES.office` gains `{ href: '/office/schedules', label: 'Schedules' }`)
- Test: `frontend/web/src/app/(app)/office/schedules/schedules.test.tsx`, extend `SideNav.test.tsx`

**Interfaces:**
- Consumes: `useShiftTemplates` + mutations, `useSession().hr_offices`, `WeekEditor`, `Dialog`, `Select`, `api.officeDefaultTemplate`.
- Produces: the screen's templates region + office-default Select; the Schedules nav entry.

- [ ] **Step 1: Failing tests** — HR admin sees an **Office → Schedules** nav entry (extend `SideNav.test.tsx`); the screen lists the office's templates; "New template" opens a Dialog with a `WeekEditor`, submitting calls `api.shiftTemplates.create` and invalidates; the office-default Select calls `api.officeDefaultTemplate.set`; office picker from `hr_offices` (mirror holidays). Match the holidays screen-test mocking harness.
- [ ] **Step 2–4:** implement. Reuse the `/office/holidays/page.tsx` scaffold (AppShell, SectionHeader, office picker, loading/error via Skeleton/InlineNotification). Templates list → each row edits via a `WeekEditor` Dialog; a create button; a default-template `Select`. Add the nav entry (one line in `ROUTES.office`).
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint && npm run build`** (confirm `/office/schedules` in the route table). **Step 6: Commit** `git commit -m "Schedules(web): templates + office default screen, Schedules nav"`.

---

### Task 13: Frontend — `/office/schedules`: assignments region

**Files:**
- Modify: `frontend/web/src/app/(app)/office/schedules/page.tsx`
- Test: extend `schedules.test.tsx`

**Interfaces:**
- Consumes: `useScheduleAssignments` + mutations, `api.scheduleAssignments`. Needs the office's employees + departments to pick a target — reuse whatever list endpoint exists, or (YAGNI) accept an employee/department id via a `Select` populated from a minimal existing source. **Verify** an employee/department list endpoint exists; if not, scope this region to assigning by an employee chosen from a simple id `Select` sourced from the resolved-employee picker used in Task 14, and note the limitation.

- [ ] **Step 1: Failing test** — an "Assign" Dialog with a target-type toggle (employee/department), a target Select, a template Select, and an effective-date input; submitting calls `api.scheduleAssignments.create` with exactly one of employee/department and invalidates; the assignments list renders with effective dates; delete calls `api.scheduleAssignments.delete`.
- [ ] **Step 2–4:** implement the assignments region.
- [ ] **Step 5: gate. Step 6: Commit** `git commit -m "Schedules(web): assignment management"`.

---

### Task 14: Frontend — `/office/schedules`: resolved MonthCalendar + per-date override

**Files:**
- Modify: `frontend/web/src/app/(app)/office/schedules/page.tsx`
- Create: `frontend/web/src/components/domain/ResolvedDayCell.tsx`
- Test: extend `schedules.test.tsx`, `ResolvedDayCell.test.tsx`

**Interfaces:**
- Consumes: `useResolvedMonth(employeeId, month)`, `useScheduleOverrides` mutations, `MonthCalendar` (renderDay), `Dialog`, `WeekEditor`-style single-day editor.
- Produces: an employee picker + a `MonthCalendar` whose `renderDay` shows each day's resolved state via `ResolvedDayCell`; click-a-day opens an override Dialog (rest, or custom hours), submitting calls create/update override and invalidates the resolved + overrides keys.

- [ ] **Step 1: Failing tests** — `ResolvedDayCell` renders "Rest" for a rest day and the hours for a working day, plus a small `source` badge; the screen, given a mocked resolved month, renders rest days and working hours on the calendar; clicking a day opens the override Dialog; submitting a rest override calls `api.scheduleOverrides.create` and the resolved query refetches.
- [ ] **Step 2–4:** implement `ResolvedDayCell` (token-only; mirror `DayCell`'s honesty — show exactly what resolved, no invented totals) and wire the override Dialog into `MonthCalendar`'s `renderDay` (a single-day is_rest/hours editor — reuse `WeekEditor`'s row internals or a small shared `DayShapeFields` component; if extracting, do it here and refactor `WeekEditor` to consume it).
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint && npm run build`. Step 6: Commit** `git commit -m "Schedules(web): resolved calendar + per-date override editing"`.

---

### Task 15: Docs, seeder, e2e, and the full gate

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md` (M4b **Status: complete**), `docs/features.md`
- Create: `scripts/e2e-schedules.sh`
- Modify: `backend/database/seeders/CompanySeeder.php`

- [ ] **Step 1: Seeder** — for Manila and Cebu: create a "Standard Mon–Fri 08:00–18:00" template (Sat/Sun rest), set it as each office's `default_shift_template_id`, and add an employee-level assignment for one seeded employee (e.g. Miguel) so `/office/schedules` and the resolved read are non-empty on `make dev`. Also seed one override (a rest-day swap) for demonstration. Match the seeder's existing `Model::create` style.
- [ ] **Step 2: `scripts/e2e-schedules.sh`** — mirror `scripts/e2e-holidays.sh` structure (shebang, header comment block, `set -euo pipefail`, `API` default, login helper, jq assertions). Walk: log in as `hr.manila@hris.test`; create a Mon–Fri template; set it as Manila's default; assign it to a seeded Manila employee; `GET /office/schedule/resolved?employee=<id>&month=<YYYY-MM>` and assert Sat/Sun `is_rest:true` and a weekday's `scheduled_minutes:540`; create a template with a Tue 17:00→03:00 night shift and assert `end_minute:1620` on resolve; POST an override that makes one Saturday working and the following Monday rest, and assert both flip with `source:"override"`; as `hr.cebu@hris.test`, GET/PATCH/DELETE the Manila template id AND a fabricated id, asserting both are 404 with **identical bodies**; read the `activity_log` (via `psql`, mirroring e2e-holidays) for the template's causer + uuid subject. `bash -n` clean; run live only if `curl -sf http://127.0.0.1:8001/api/v1/health` succeeds.
- [ ] **Step 3: Docs** — verify each claim against code. `02-data-model.md`: the four tables + `offices.default_shift_template_id` + the resolver's chain. `03-api.md`: all endpoints (templates, assignments, overrides, default, resolved) with their `not_found`/`validation_failed`/`template_in_use`/`schedule_assignment_exists`/`schedule_override_exists`/`office_has_no_default_template`/`employee_has_no_office` codes and the byte-identical-404 note. `05-rbac.md`: OfficeScope already covers who; add that schedules are the same office-scoped authority. `06-roadmap.md`: M4b **Status: complete** block with real counts + the note that M4c (pay rules) remains and RecomputeRange is M5. `features.md`: the user-facing schedule features (build weekly templates, assign, per-date overrides, see a resolved calendar).
- [ ] **Step 4: The full gate** — `cd backend && ./vendor/bin/pest && ./vendor/bin/pest --testsuite=Arch`; `cd ../frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts. If the containerized `test-web` fails on the new deps (none added here, but node_modules can drift), sync with `docker compose -f compose.dev.yml exec web npm install` (mirroring M4a's note) and re-run.
- [ ] **Step 5: Commit** `git commit -m "Schedules: docs, seeder, e2e, M4b status"`.

## Done When

The spec's "Done when" holds: an HR admin builds a Mon–Fri 08:00–18:00 (Sat/Sun rest) template for Manila, sets it as the office default, assigns it to Miguel; the resolved read returns Sat/Sun `is_rest:true, scheduled_minutes:0` and weekdays at 540; a Tue 17:00→03:00 template resolves `end_minute:1620`; an override swaps a Saturday to working and the next Monday to rest, both flipping with `source:"override"`; a Cebu-only HR admin gets byte-identical 404s touching a Manila template; the activity log names who did what; no pay computed. Full suite green (backend + arch + frontend), `e2e-schedules.sh` passes live.
