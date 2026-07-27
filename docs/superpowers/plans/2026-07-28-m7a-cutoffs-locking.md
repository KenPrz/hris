# M7a — Cutoffs & locking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An HR admin closes a semi-monthly cutoff period for an office; the close is refused while unresolved exceptions remain and otherwise freezes every in-period summary to `locked`; an approval whose effect would change a locked day is refused with a domain error; no recompute can overwrite a locked summary; and `ReopenCutoff` (reason-required, audited) unlocks.

**Architecture:** A new per-office `cutoff_periods` table (semi-monthly windows via a pure `CutoffCalendar`). `CloseCutoff`/`ReopenCutoff` action classes. A shared `RequestAffectedDates` domain query maps any request to the calendar date(s) its effect would change — used by both the close exception-gate and the approval refusal. The concurrency spine: **`CloseCutoff`, `ApproveRequest`, and `ComputeDailySummary` all serialize on the per-employee `Employee` row lock `ComputeDailySummary` already holds** — so a close cannot race an approval or a recompute into an inconsistent freeze. Two genuine two-real-Postgres-connections tests prove it.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres, `proc_open` two-connection tests); Next 16 / React 19 / TS / Tailwind + Carbon (Vitest).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/`/`tests/`. Arch-enforced.
- Never call `env()` outside `config/`. Arch-enforced.
- Action classes are `final`, own their transaction, take an Input DTO, return a domain object, know nothing about HTTP. Controllers are `final` and invokable. Domain layer is framework-agnostic except Eloquent query-builder wrappers (the `LeaveDayLookup`/`ApprovalQueues` precedent).
- Integer minutes/centavos/basis points — never floats. Envelope: success `{"data":...}`, error `{"error":...}`.
- **404-not-403 existence discipline.** FormRequests validate ids/inputs SHAPE-ONLY (`uuid`/`date`/`string`), never `exists:`. An out-of-office period must 404 like a nonexistent one.
- uuid v7 PKs (`DB::raw('uuidv7()')` default + `HasUuids`). String columns + PHP backed enums + `CHECK` constraints — never a native Postgres enum; the CHECK list is pinned to `Enum::cases()` by a schema test. `timestamptz`; calendar dates as `date` / `YYYY-MM-DD` strings on the wire.
- Tests run against real PostgreSQL, never SQLite.
- **Append-only:** `attendance_logs` are never mutated; raw punches are never refused (a locked period only refuses *approvals*, not punches).
- **The lock invariant:** every write that can change or freeze an employee-day's summary — `ComputeDailySummary` (compute), `CloseCutoff` (freeze), `ApproveRequest` final hop (the effect that triggers a recompute) — takes `Employee::query()->lockForUpdate()->findOrFail($employeeId)` for the affected employee, so they serialize per-employee. Never weaken this to a summary-row lock (a summary may not exist yet) or a period-row lock alone.
- **`cutoff.manage`** permission is ALREADY seeded (RbacSeeder). Do not re-add it; gate the endpoints with it + `OfficeScope`.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL. Message body only. This applies to the PR body too.

---

### Task 1: `cutoff_periods` table + `CutoffState` enum + model + factory

**Files:**
- Create: `backend/app/Domain/Cutoff/CutoffState.php`
- Create: `backend/database/migrations/2026_08_10_000001_create_cutoff_periods_table.php`
- Create: `backend/app/Models/CutoffPeriod.php`
- Create: `backend/database/factories/CutoffPeriodFactory.php`
- Test: `backend/tests/Feature/Schema/CutoffPeriodSchemaTest.php`

**Interfaces:**
- Produces: `CutoffState` enum (`Open='open'`, `Closed='closed'`); `cutoff_periods(id, office_id, start_date, end_date, state, closed_by?, closed_at?, timestamps)`; `CutoffPeriod` model with `office()`/`closedBy()` relations, `state` cast to `CutoffState`, `LogsActivity`.

- [ ] **Step 1: Write the enum.**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

/** The lifecycle of a cutoff period. Widens only if a mid-state is ever needed. */
enum CutoffState: string
{
    case Open = 'open';
    case Closed = 'closed';
}
```

- [ ] **Step 2: Write the migration.** Mirror the `leave_types`/`daily_attendance_summaries` migration style (string + CHECK, never native enum).

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-office semi-monthly cutoff periods. A period is `open` until CloseCutoff freezes it
 * (`closed`), locking every daily_attendance_summary in its (office, [start,end]) window.
 * The period a summary belongs to is DERIVED by office + date range — no FK is stamped on
 * the summary (no second source of truth to keep consistent across recompute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutoff_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('state')->default('open');
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['office_id', 'start_date']);
        });

        DB::statement("ALTER TABLE cutoff_periods ADD CONSTRAINT cutoff_periods_state_check CHECK (state IN ('open','closed'))");
        DB::statement('ALTER TABLE cutoff_periods ADD CONSTRAINT cutoff_periods_dates_check CHECK (end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cutoff_periods');
    }
};
```

- [ ] **Step 3: Write the model.** Mirror `LeaveType`/`DailyAttendanceSummary` (final, `HasUuids`, `LogsActivity`, casts).

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cutoff\CutoffState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class CutoffPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\CutoffPeriodFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'office_id', 'start_date', 'end_date', 'state', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'state' => CutoffState::class,
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['office_id', 'start_date', 'end_date', 'state', 'closed_by', 'closed_at'])
            ->logOnlyDirty();
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
```

> Verify the exact `LogsActivity` idiom against `LeaveType`/`Holiday` (the `getActivitylogOptions` signature, whether they use `logOnlyDirty`) and match it verbatim; the snippet above is the common shape but the sibling is authoritative.

- [ ] **Step 4: Write the factory.**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CutoffPeriod;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CutoffPeriod> */
final class CutoffPeriodFactory extends Factory
{
    protected $model = CutoffPeriod::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-15',
            'state' => 'open',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['state' => 'closed', 'closed_at' => now()]);
    }
}
```

- [ ] **Step 5: Write the schema test.** Pin the CHECK to the enum + assert the unique + the date CHECK.

```php
<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffState;
use App\Models\CutoffPeriod;
use App\Models\Office;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('pins the state CHECK to CutoffState::cases()', function (): void {
    $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'cutoff_periods_state_check'")->def;
    foreach (CutoffState::cases() as $case) {
        expect($def)->toContain("'{$case->value}'");
    }
});

it('rejects an unknown state at the database', function (): void {
    $office = Office::factory()->create();
    expect(fn () => DB::table('cutoff_periods')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15',
        'state' => 'nonsense', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects end_date before start_date', function (): void {
    $office = Office::factory()->create();
    expect(fn () => DB::table('cutoff_periods')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'office_id' => $office->id, 'start_date' => '2026-07-15', 'end_date' => '2026-07-01',
        'state' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces one period per (office, start_date)', function (): void {
    $office = Office::factory()->create();
    CutoffPeriod::factory()->create(['office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15']);
    expect(fn () => CutoffPeriod::factory()->create(['office_id' => $office->id, 'start_date' => '2026-07-01', 'end_date' => '2026-07-15']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 6: Run + verify.** `cd backend && ./vendor/bin/pest --filter=CutoffPeriodSchema`. Expected: PASS.
- [ ] **Step 7: Commit.** `git commit -m "M7a: cutoff_periods table + CutoffState enum + model + factory"`

---

### Task 2: `CutoffCalendar` — the semi-monthly window rule

**Files:**
- Create: `backend/app/Domain/Cutoff/CutoffCalendar.php`
- Test: `backend/tests/Unit/Domain/Cutoff/CutoffCalendarTest.php`

**Interfaces:**
- Produces: `CutoffCalendar::windowFor(string $date): array{start: string, end: string}` (the semi-monthly window containing `$date`, `YYYY-MM-DD`); `CutoffCalendar::isValidStart(string $date): bool` (true iff day-of-month is 1 or 16).

- [ ] **Step 1: Write the failing tests.**

```php
<?php

declare(strict_types=1);

use App\Domain\Cutoff\CutoffCalendar;

it('returns the first-half window for a date on or before the 15th', function (): void {
    expect(CutoffCalendar::windowFor('2026-07-01'))->toBe(['start' => '2026-07-01', 'end' => '2026-07-15']);
    expect(CutoffCalendar::windowFor('2026-07-15'))->toBe(['start' => '2026-07-01', 'end' => '2026-07-15']);
});

it('returns the second-half window to end-of-month for a date on or after the 16th', function (): void {
    expect(CutoffCalendar::windowFor('2026-07-16'))->toBe(['start' => '2026-07-16', 'end' => '2026-07-31']);
    expect(CutoffCalendar::windowFor('2026-07-31'))->toBe(['start' => '2026-07-16', 'end' => '2026-07-31']);
});

it('resolves end-of-month correctly for 30-day months and February', function (): void {
    expect(CutoffCalendar::windowFor('2026-06-20'))->toBe(['start' => '2026-06-16', 'end' => '2026-06-30']);
    expect(CutoffCalendar::windowFor('2026-02-20'))->toBe(['start' => '2026-02-16', 'end' => '2026-02-28']); // 2026 not a leap year
    expect(CutoffCalendar::windowFor('2028-02-20'))->toBe(['start' => '2028-02-16', 'end' => '2028-02-29']); // 2028 leap year
});

it('recognises only the 1st and 16th as valid period starts', function (): void {
    expect(CutoffCalendar::isValidStart('2026-07-01'))->toBeTrue();
    expect(CutoffCalendar::isValidStart('2026-07-16'))->toBeTrue();
    expect(CutoffCalendar::isValidStart('2026-07-02'))->toBeFalse();
    expect(CutoffCalendar::isValidStart('2026-07-15'))->toBeFalse();
});
```

- [ ] **Step 2: Run, verify fail.** `./vendor/bin/pest --filter=CutoffCalendar`. Expected: FAIL (class missing).

- [ ] **Step 3: Implement.** Pure, `Carbon`-based, no DB.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use Illuminate\Support\Carbon;

/**
 * The semi-monthly cutoff window rule: the 1st–15th and the 16th–end-of-month. Pure — no
 * DB, no models. Per-office custom schedules (weekly/monthly/arbitrary) are deferred; this
 * is the roadmap's stated default and the only rule M7a implements.
 */
final class CutoffCalendar
{
    private function __construct() {}

    /** @return array{start: string, end: string} the window (inclusive) containing $date. */
    public static function windowFor(string $date): array
    {
        $d = Carbon::parse($date);

        if ($d->day <= 15) {
            return ['start' => $d->copy()->startOfMonth()->toDateString(), 'end' => $d->copy()->day(15)->toDateString()];
        }

        return ['start' => $d->copy()->day(16)->toDateString(), 'end' => $d->copy()->endOfMonth()->toDateString()];
    }

    public static function isValidStart(string $date): bool
    {
        $day = Carbon::parse($date)->day;

        return $day === 1 || $day === 16;
    }
}
```

- [ ] **Step 4: Run, verify pass.** `./vendor/bin/pest --filter=CutoffCalendar`. Expected: PASS.
- [ ] **Step 5: Commit.** `git commit -m "M7a: CutoffCalendar semi-monthly window rule"`

---

### Task 3: `RequestAffectedDates` — the request → date(s) map

**Files:**
- Create: `backend/app/Domain/Cutoff/RequestAffectedDates.php`
- Test: `backend/tests/Feature/Cutoff/RequestAffectedDatesTest.php`

**Interfaces:**
- Consumes: `Request` + its `attendanceAdjustmentDetail`/`leaveDetail`/`overtimeDetail`, `RequestType`, the office `timezone`.
- Produces: `RequestAffectedDates::for(Request $request): array<string>` — the ascending, unique `YYYY-MM-DD` business dates the request's effect would change: attendance adjustment → the office-timezone calendar date of its punch (`punched_at` for `add`; the target log's `punched_at` for `void`/`amend`); leave → every date in `[start_date, end_date]`; overtime → the single `date`.

> **Known limitation (documented, deferred):** for an attendance adjustment the affected date is the office-timezone calendar date of the punch. A punch on a cross-midnight shift can belong to a *business* date one day off its calendar date; precise business-date attribution for adjustments is deferred. This is SAFE for the close gate (over-inclusion at worst blocks a close it needn't), and only mis-targets the approval refusal for a cross-midnight punch within one day of a period boundary — note it in the class docblock.

- [ ] **Step 1: Write the failing tests.** One per type + the empty/edge cases.

```php
<?php

declare(strict_types=1);

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Cutoff\RequestAffectedDates;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps an overtime request to its single date', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => '2026-07-10', 'minutes' => 120]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-10']);
});

it('maps a leave request to every date in its range', function (): void {
    $office = Office::factory()->create();
    $type = LeaveType::factory()->create(['office_id' => $office->id]);
    $request = Request::factory()->create(['type' => 'leave']);
    LeaveDetail::query()->create(['request_id' => $request->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-07-14', 'end_date' => '2026-07-16', 'day_part' => 'full', 'amount_minutes' => 1440]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-14', '2026-07-15', '2026-07-16']);
});

it('maps an add adjustment to the office-timezone date of its punch', function (): void {
    // Asia/Manila is UTC+8: 2026-07-10T20:30:00Z is 2026-07-11 04:30 local.
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $request = Request::factory()->for($employee)->create(['type' => 'attendance_adjustment']);
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add, 'target_log_id' => null,
        'direction' => PunchDirection::In, 'punched_at' => '2026-07-10T20:30:00Z',
    ]);
    expect(RequestAffectedDates::for($request->fresh()))->toBe(['2026-07-11']);
});
```

> The implementer must confirm the `Request::factory()->for($employee)` / employee-office wiring against a sibling (e.g. how `RequestAffectedDatesTest`'s attendance case resolves the office timezone — the office comes from the request's employee's `currentOffice`). For a `void`/`amend` adjustment the punch is the TARGET log's `punched_at`; add a test for that using an `AttendanceLog` target if the sibling factories make it easy, else note it as covered by the add case's date logic.

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement.** Domain-Eloquent wrapper.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use App\Domain\Requests\RequestType;
use App\Models\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonPeriod;

/**
 * The calendar date(s) a request's effect would change — the one fact both the CloseCutoff
 * exception gate and the ApproveRequest cutoff refusal need. Leave and overtime carry their
 * dates explicitly; an attendance adjustment's date is the office-timezone calendar date of
 * the punch it adds or annuls.
 *
 * KNOWN LIMITATION: for an adjustment this is the punch's office-tz CALENDAR date, which for
 * a cross-midnight shift can differ by a day from the BUSINESS date its summary is keyed by.
 * Safe for the close gate (over-inclusion only), imprecise only for the approval refusal of a
 * cross-midnight punch within a day of a period boundary. Precise business-date attribution
 * for adjustments is deferred.
 *
 * @return array<int, string> ascending, unique YYYY-MM-DD
 */
final class RequestAffectedDates
{
    private function __construct() {}

    /** @return array<int, string> */
    public static function for(Request $request): array
    {
        $dates = match ($request->type) {
            RequestType::Overtime => [$request->overtimeDetail?->date?->toDateString()],
            RequestType::Leave => self::leaveDates($request),
            RequestType::AttendanceAdjustment => [self::adjustmentDate($request)],
        };

        $dates = array_values(array_unique(array_filter($dates)));
        sort($dates);

        return $dates;
    }

    /** @return array<int, string> */
    private static function leaveDates(Request $request): array
    {
        $detail = $request->leaveDetail;
        if ($detail === null) {
            return [];
        }

        return collect(CarbonPeriod::create($detail->start_date, $detail->end_date))
            ->map(fn ($d): string => $d->toDateString())
            ->all();
    }

    private static function adjustmentDate(Request $request): ?string
    {
        $detail = $request->attendanceAdjustmentDetail;
        if ($detail === null) {
            return null;
        }

        // add carries punched_at directly; void/amend point at the target log's punched_at.
        $punchedAt = $detail->punched_at ?? $detail->targetLog?->punched_at;
        if ($punchedAt === null) {
            return null;
        }

        $timezone = $request->employee?->currentOffice?->timezone ?? 'UTC';

        return Carbon::parse($punchedAt)->setTimezone($timezone)->toDateString();
    }
}
```

> Confirm the relation names against the models: `Request::attendanceAdjustmentDetail`, `AttendanceAdjustmentDetail::targetLog` (the `belongsTo(AttendanceLog, 'target_log_id')`), `Request::employee`, `Employee::currentOffice`. Adjust the accessor chain to the real names.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit.** `git commit -m "M7a: RequestAffectedDates — request-to-date map for the cutoff gate and refusal"`

---

### Task 4: `CloseCutoff` — the strict gate + freeze

**Files:**
- Create: `backend/app/Actions/Cutoff/CloseCutoffInput.php`
- Create: `backend/app/Actions/Cutoff/CloseCutoff.php`
- Create: `backend/app/Exceptions/Domain/CutoffHasUnresolvedExceptions.php`
- Create: `backend/app/Exceptions/Domain/CutoffAlreadyClosed.php`
- Create: `backend/app/Exceptions/Domain/InvalidCutoffStart.php`
- Test: `backend/tests/Feature/Cutoff/CloseCutoffTest.php`

**Interfaces:**
- Consumes: `CutoffCalendar`, `RequestAffectedDates`, `CutoffPeriod`, `DailyAttendanceSummary`, `Employee`.
- Produces: `CloseCutoff::execute(CloseCutoffInput{officeId, periodStart, actorId}): CutoffPeriod` — ensures+closes the period, throwing on an invalid start, an already-closed period, or unresolved exceptions; on success flips its in-period summaries to `locked`.

- [ ] **Step 1: Write the domain exceptions.** Mirror `LeaveTypeInactive` (extends `DomainException`, `errorCode()`, `httpStatus()`, `details()`).

`CutoffHasUnresolvedExceptions` — `httpStatus() = 422`, `errorCode() = 'cutoff_has_unresolved_exceptions'`, constructor takes `array $incompleteDates`, `array $pendingRequestIds`, exposed via `details()`.
`CutoffAlreadyClosed` — `409`, `'cutoff_already_closed'`.
`InvalidCutoffStart` — `422`, `'invalid_cutoff_start'`.

(Write each as a small final class in `app/Exceptions/Domain/`, matching the `LeaveTypeInactive` shape exactly.)

- [ ] **Step 2: Write the Input DTO.**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

final readonly class CloseCutoffInput
{
    public function __construct(
        public string $officeId,
        public string $periodStart,
        public string $actorId,
    ) {}
}
```

- [ ] **Step 3: Write the failing tests.** Cover: invalid start rejected; a clean period closes and flips its summaries to `locked`; an incomplete-day summary blocks; a pending attendance-adjustment on an in-period date blocks; a pending leave overlapping the window blocks; a pending overtime on an in-period date blocks; a TERMINAL (approved/rejected) request does NOT block; an out-of-period pending request does NOT block; a second close 409s.

Sketch (the implementer fills the fixtures using the factories — `DailyAttendanceSummary::factory()`, `Request::factory()` + the detail factories, `Employee`/`Office`):

```php
it('closes a clean period and locks its in-period summaries', function (): void {
    // office + employee; a computed, non-incomplete summary on 2026-07-10 (in [07-01,07-15]).
    // execute(officeId, '2026-07-01', actor). Assert period.state === closed, closed_by/at set,
    // and the summary's fresh status === 'locked'. A summary OUTSIDE the window stays 'computed'.
});

it('refuses to close while an in-period day is incomplete', function (): void {
    // a summary with is_incomplete = true on an in-period date -> CutoffHasUnresolvedExceptions,
    // and details() names that date; nothing is locked (assert the summary status unchanged).
});

it('refuses to close while a pending overtime request lands in the window', function (): void {
    // a pending overtime request with overtime_details.date in-window -> throws; details() lists its id.
});
// ...analogous: pending leave (range overlap), pending attendance adjustment (punch in-window).

it('closes over a terminal request and over an out-of-period pending request', function (): void {
    // an APPROVED request in-window and a PENDING request OUT-of-window both leave the close clean.
});

it('409s a second close of an already-closed period', function (): void { /* CutoffAlreadyClosed */ });
```

- [ ] **Step 4: Run, verify fail.**

- [ ] **Step 5: Implement `CloseCutoff`.**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

use App\Domain\Cutoff\CutoffCalendar;
use App\Domain\Cutoff\CutoffState;
use App\Domain\Cutoff\RequestAffectedDates;
use App\Domain\Requests\RequestState;
use App\Exceptions\Domain\CutoffAlreadyClosed;
use App\Exceptions\Domain\CutoffHasUnresolvedExceptions;
use App\Exceptions\Domain\InvalidCutoffStart;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Closes an office's semi-monthly cutoff period: refuses on an invalid boundary, an
 * already-closed period, or any unresolved exception (an incomplete in-period day, or a
 * non-terminal request whose effect maps onto an in-period date), and otherwise freezes
 * every in-period summary to `locked`.
 *
 * The freeze takes the per-employee row lock ComputeDailySummary/ApproveRequest also take,
 * per affected employee, BEFORE flipping that employee's summaries — so a close serializes
 * against a concurrent recompute or approval for the same employee (see the two-connection
 * tests). Setting the period `closed` and the summaries `locked` happen in one transaction.
 */
final class CloseCutoff
{
    public function execute(CloseCutoffInput $in): CutoffPeriod
    {
        if (! CutoffCalendar::isValidStart($in->periodStart)) {
            throw new InvalidCutoffStart($in->periodStart);
        }

        $window = CutoffCalendar::windowFor($in->periodStart);

        return DB::transaction(function () use ($in, $window): CutoffPeriod {
            $period = CutoffPeriod::query()->lockForUpdate()->firstOrCreate(
                ['office_id' => $in->officeId, 'start_date' => $window['start']],
                ['end_date' => $window['end'], 'state' => CutoffState::Open->value],
            );

            if ($period->state === CutoffState::Closed) {
                throw new CutoffAlreadyClosed($period->id);
            }

            // --- Strict exception gate ---
            $summaries = DailyAttendanceSummary::query()
                ->where('office_id', $in->officeId)
                ->whereBetween('date', [$window['start'], $window['end']])
                ->get();

            $incompleteDates = $summaries->where('is_incomplete', true)
                ->map(fn (DailyAttendanceSummary $s): string => $s->date->toDateString())
                ->values()->all();

            $pendingRequestIds = self::blockingRequestIds($in->officeId, $window['start'], $window['end']);

            if ($incompleteDates !== [] || $pendingRequestIds !== []) {
                throw new CutoffHasUnresolvedExceptions($incompleteDates, $pendingRequestIds);
            }

            // --- Freeze, per-employee under the shared row lock ---
            foreach ($summaries->pluck('employee_id')->unique() as $employeeId) {
                Employee::query()->lockForUpdate()->findOrFail($employeeId);

                DailyAttendanceSummary::query()
                    ->where('employee_id', $employeeId)
                    ->where('office_id', $in->officeId)
                    ->whereBetween('date', [$window['start'], $window['end']])
                    ->update(['status' => 'locked']);
            }

            $period->update([
                'end_date' => $window['end'],
                'state' => CutoffState::Closed->value,
                'closed_by' => $in->actorId,
                'closed_at' => now(),
            ]);

            return $period->fresh();
        });
    }

    /**
     * Non-terminal requests (pending/manager_approved) whose effect maps onto an in-period
     * date for an employee in this office. Scanned across the three request types via
     * RequestAffectedDates. @return array<int, string>
     */
    private static function blockingRequestIds(string $officeId, string $start, string $end): array
    {
        $employeeIds = Employee::query()->where('current_office_id', $officeId)->pluck('id');

        $candidates = Request::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('state', [RequestState::Pending->value, RequestState::ManagerApproved->value])
            ->with(['attendanceAdjustmentDetail.targetLog', 'leaveDetail', 'overtimeDetail', 'employee.currentOffice'])
            ->get();

        return $candidates
            ->filter(function (Request $request) use ($start, $end): bool {
                foreach (RequestAffectedDates::for($request) as $date) {
                    if ($date >= $start && $date <= $end) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')->values()->all();
    }
}
```

> Two correctness notes for the implementer: (1) confirm `DailyAttendanceSummary` has `office_id` (added M5b) and that `date` casts to a Carbon so `->toDateString()` works. (2) The "employee in this office" scoping uses `current_office_id`; a summary's own `office_id` (effective-dated) is what the freeze query filters on — these can differ for an employee who changed offices mid-period, but the gate scanning `current_office_id` employees is a safe superset for M7a. Note this in the report if it looks material.

- [ ] **Step 6: Run, verify pass.**
- [ ] **Step 7: Commit.** `git commit -m "M7a: CloseCutoff — strict exception gate + per-employee-locked summary freeze"`

---

### Task 5: `ReopenCutoff` — reason-required unlock

**Files:**
- Create: `backend/app/Actions/Cutoff/ReopenCutoffInput.php`
- Create: `backend/app/Actions/Cutoff/ReopenCutoff.php`
- Create: `backend/app/Exceptions/Domain/CutoffNotClosed.php`
- Test: `backend/tests/Feature/Cutoff/ReopenCutoffTest.php`

**Interfaces:**
- Produces: `ReopenCutoff::execute(ReopenCutoffInput{periodId, reason, actorId}): CutoffPeriod` — flips a closed period back to `open`, unlocking its summaries (`locked → computed`), audited with the reason; throws `CutoffNotClosed` (409) on an open period. (An empty reason is rejected by the FormRequest in Task 8; the action asserts non-empty defensively.)

- [ ] **Step 1: `CutoffNotClosed` exception** (`409`, `'cutoff_not_closed'`), mirroring `CutoffAlreadyClosed`.

- [ ] **Step 2: Input DTO** (`periodId`, `reason`, `actorId`).

- [ ] **Step 3: Failing tests.** A closed period reopens: `state === open`, `closed_by`/`closed_at` cleared, its `locked` summaries back to `computed`, and an activity-log entry exists carrying the reason. Reopening an OPEN period → `CutoffNotClosed` (409). The unlock is per-employee under the row lock (assert an out-of-period locked summary — belonging to a DIFFERENT period — is untouched).

- [ ] **Step 4: Run, verify fail.**

- [ ] **Step 5: Implement.** Mirror `CloseCutoff`'s per-employee-locked update, in reverse. Record the reason via the activity log (`activity()->performedOn($period)->withProperties(['reason' => $in->reason])->log('cutoff_reopened')` — confirm the project's activity-log idiom against how other actions log a reason, e.g. any reopen/void elsewhere; if none, use `LogsActivity`'s event with a properties bag). Set `state = open`, null `closed_by`/`closed_at`, flip in-period `locked` summaries to `computed`.

```php
// core of execute(), inside DB::transaction:
$period = CutoffPeriod::query()->lockForUpdate()->findOrFail($in->periodId);
if ($period->state !== CutoffState::Closed) {
    throw new CutoffNotClosed($period->id);
}
$summaries = DailyAttendanceSummary::query()
    ->where('office_id', $period->office_id)
    ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
    ->get();
foreach ($summaries->pluck('employee_id')->unique() as $employeeId) {
    Employee::query()->lockForUpdate()->findOrFail($employeeId);
    DailyAttendanceSummary::query()
        ->where('employee_id', $employeeId)
        ->where('office_id', $period->office_id)
        ->whereBetween('date', [$period->start_date->toDateString(), $period->end_date->toDateString()])
        ->where('status', 'locked')
        ->update(['status' => 'computed']);
}
$period->update(['state' => CutoffState::Open->value, 'closed_by' => null, 'closed_at' => null]);
activity()->performedOn($period)->causedBy($in->actorId)->withProperties(['reason' => $in->reason])->log('cutoff_reopened');
return $period->fresh();
```

> Confirm the `activity()` helper + `causedBy` accept a user id vs. a User model in this codebase (check a sibling audited action); adjust to pass a `User` model if required. The unlock only touches rows still `locked` (a summary that was `disputed`/`pending` for another reason is left alone).

- [ ] **Step 6: Run, verify pass.**
- [ ] **Step 7: Commit.** `git commit -m "M7a: ReopenCutoff — reason-required, audited unlock"`

---

### Task 6: The approval refusal + the approval-vs-close concurrency proof

**Files:**
- Create: `backend/app/Exceptions/Domain/CutoffLocked.php`
- Create: `backend/app/Domain/Cutoff/CutoffGuard.php`
- Modify: `backend/app/Actions/Requests/ApproveRequest.php`
- Test: `backend/tests/Feature/Cutoff/ApproveOnLockedDayTest.php`
- Test: `backend/tests/Feature/Cutoff/CloseVsApproveConcurrencyTest.php`
- Create: `backend/tests/Feature/Cutoff/Support/close_lock_holder.php`

**Interfaces:**
- Produces: `CutoffGuard::assertOpen(Request $request): void` — throws `CutoffLocked` (422) if any of the request's affected dates falls in a `closed` period for the request's employee's office. `ApproveRequest` calls it on the final hop, under the affected employee's row lock, before firing the effect.

- [ ] **Step 1: `CutoffLocked` exception** (`422`, `'cutoff_locked'`, `details()` carrying the offending date(s)/period), mirroring `LeaveTypeInactive`.

- [ ] **Step 2: `CutoffGuard`** (domain-Eloquent wrapper):

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cutoff;

use App\Exceptions\Domain\CutoffLocked;
use App\Models\CutoffPeriod;
use App\Models\Request;

/**
 * Refuses an approval whose effect would change a day in a CLOSED cutoff period. Called by
 * ApproveRequest on the final hop, under the affected employee's row lock — so the closed-
 * period read races correctly against a concurrent CloseCutoff (both hold that lock).
 */
final class CutoffGuard
{
    private function __construct() {}

    public static function assertOpen(Request $request): void
    {
        $officeId = $request->employee?->current_office_id;
        if ($officeId === null) {
            return;
        }

        foreach (RequestAffectedDates::for($request) as $date) {
            $closed = CutoffPeriod::query()
                ->where('office_id', $officeId)
                ->where('state', 'closed')
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->exists();

            if ($closed) {
                throw new CutoffLocked($date);
            }
        }
    }
}
```

- [ ] **Step 3: Failing test `ApproveOnLockedDayTest`.** For each request type (attendance adjustment, leave, overtime): a request whose affected date is inside a CLOSED period → `app(ApproveRequest::class)->execute(...)` throws `CutoffLocked` (422), the request stays in its prior state, and NO effect landed (no new punch / no ledger debit / summary unchanged). Plus: the SAME request with the period OPEN approves normally (control). Use the real `ApproveRequest` (not a spy) so the guard's placement is exercised.

- [ ] **Step 4: Run, verify fail.**

- [ ] **Step 5: Wire the guard into `ApproveRequest`.** On the final hop only, before the effect, take the employee lock and assert:

```php
if ($isFinalHop) {
    // Serialize the cutoff read against a concurrent CloseCutoff for this employee, the
    // same row lock ComputeDailySummary/CloseCutoff take. Only the final hop touches a
    // summary (via the effect's recompute); a manager's hop-1 advance never does.
    Employee::query()->lockForUpdate()->findOrFail($locked->employee_id);
    CutoffGuard::assertOpen($locked);

    $this->effects->for($locked->type)->applyOnApproval($locked, $approver->id);
    $locked->update([...Approved...]);
} else {
    ...
}
```

Add `use App\Models\Employee;` and `use App\Domain\Cutoff\CutoffGuard;`. Keep the existing lock/authority/terminal checks above this unchanged.

- [ ] **Step 6: Run, verify pass** (the single-process refusal tests). `./vendor/bin/pest --filter=ApproveOnLockedDay`.

- [ ] **Step 7: Write the close-holder script** `tests/Feature/Cutoff/Support/close_lock_holder.php`, mirroring `tests/Feature/Attendance/Support/approve_lock_holder.php` EXACTLY (boot the app, no test framework). It takes an employee id + hold-ms: opens a transaction, `Employee::lockForUpdate()->findOrFail($employeeId)`, prints `LOCKED\n`, holds `$holdMs`, then performs the freeze (set that employee's in-period summaries `locked` + the period `closed`), commits, prints `DONE\n`. (It stands in for a `CloseCutoff` mid-flight holding the employee lock.)

- [ ] **Step 8: Write the concurrency test `CloseVsApproveConcurrencyTest`.** Mirror `ApproveRequestConcurrencyTest` VERBATIM in structure (NO `RefreshDatabase`; commit fixtures; `proc_open` the close-holder; `stream_set_timeout`; `SET lock_timeout`; manual cleanup in `finally`). Scenario: a pending overtime (or leave) request on a date in an about-to-close period; the holder takes the employee lock and (after signalling `LOCKED`) freezes+closes the period; THIS process calls the real `ApproveRequest::execute()`, which must **block** on the employee lock ≥ ~0.5×holdMs, then — once the holder commits the close — throw `CutoffLocked`, with the request left un-approved and no effect applied. Assert the elapsed-time blocking, the exception code/status, and the un-changed request/effect state.

- [ ] **Step 9: Verify the lock is load-bearing.** Temporarily remove the `Employee::lockForUpdate()` line added in Step 5, run `CloseVsApproveConcurrencyTest`, and confirm it now FAILS (the approval no longer blocks / slips through). Restore the line. Note the before/after in the report — a concurrency test that passes with the lock removed is worthless.

- [ ] **Step 10: Run both tests, verify pass.**
- [ ] **Step 11: Commit.** `git commit -m "M7a: refuse approvals on a locked day (CutoffGuard) + close-vs-approve two-connection proof"`

---

### Task 7: `ComputeDailySummary` period-aware skip + close-vs-recompute proof

**Files:**
- Modify: `backend/app/Actions/Compute/ComputeDailySummary.php`
- Modify: `backend/app/Jobs/RecomputeDay.php` (docblock/comment update; the authoritative guard now lives in ComputeDailySummary)
- Test: `backend/tests/Feature/Cutoff/ComputeSkipsClosedPeriodTest.php`
- Test: `backend/tests/Feature/Cutoff/CloseVsRecomputeConcurrencyTest.php`
- Create: `backend/tests/Feature/Cutoff/Support/recompute_lock_holder.php`

**Interfaces:**
- Produces: `ComputeDailySummary::execute` becomes a no-op (returns the existing summary untouched) when the `(office, date)` is in a `closed` cutoff period — checked UNDER the employee row lock it already takes.

- [ ] **Step 1: Failing test `ComputeSkipsClosedPeriodTest`.** A `locked` summary in a closed period, then `ComputeDailySummary::execute(employee, date)` is called directly → the summary is byte-identical afterwards (same id, same values, still `locked`), and a fresh compute for a date in a closed period that has NO summary yet creates NOTHING (the closed period is frozen — no new unlocked row appears). A date in an OPEN period computes normally (control).

- [ ] **Step 2: Run, verify fail** (today ComputeDailySummary recomputes regardless).

- [ ] **Step 3: Implement the guard.** Inside `ComputeDailySummary::execute`, INSIDE the existing `DB::transaction` and immediately AFTER the existing `Employee::query()->lockForUpdate()->findOrFail($employee->id);` line, add:

```php
// A closed cutoff period is frozen: never recompute or create a summary inside one. Checked
// under the employee lock above, so a close racing this recompute serializes — if the close
// committed first we see `closed` here and skip; if we commit first, the close freezes our
// result. This subsumes RecomputeDay's old plain status read and also blocks a brand-new
// summary from being created inside a closed period (which the status read could not).
$inClosedPeriod = CutoffPeriod::query()
    ->where('office_id', $officeId)
    ->where('state', 'closed')
    ->whereDate('start_date', '<=', $date)
    ->whereDate('end_date', '>=', $date)
    ->exists();

if ($inClosedPeriod) {
    return DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', $date)
        ->first() ?? /* nothing to compute into a closed, summary-less day */ new DailyAttendanceSummary();
}
```

> The exact return shape must match `execute`'s declared return type (`DailyAttendanceSummary`). If returning a bare `new DailyAttendanceSummary()` is awkward for a summary-less closed day, prefer: fetch-or-return-existing, and for the truly-absent case return the existing (null) path in whatever way keeps callers (`RecomputeDay`, `ComputeOnWrite`) safe — `RecomputeDay` ignores the return value, so a null-object or the existing row both work. Pick the cleanest that satisfies the type; document the choice. `$officeId` is already resolved earlier in `execute` (the same var used for the holiday/DayType lookup) — reuse it; do NOT re-resolve. Import `App\Models\CutoffPeriod`.

- [ ] **Step 4: Update `RecomputeDay`.** Its early `status === 'locked'` read can stay as a cheap fast-path, but update its docblock/comment to state that the AUTHORITATIVE freeze guard now lives in `ComputeDailySummary` under the employee lock (the plain read alone was the M5b-flagged race). Do not remove the fast-path unless it complicates the test; keeping it is fine and harmless.

- [ ] **Step 5: Run `ComputeSkipsClosedPeriodTest`, verify pass.**

- [ ] **Step 6: Write `recompute_lock_holder.php`** (mirror `close_lock_holder.php`/`approve_lock_holder.php`): take the employee lock, signal `LOCKED`, hold, then run `ComputeDailySummary::execute` for the employee-day (a recompute), commit, `DONE`. Used to hold the lock while the test process closes.

- [ ] **Step 7: Write `CloseVsRecomputeConcurrencyTest`** (mirror the two-connection structure). Scenario: a computed in-period summary; the holder takes the employee lock and holds (a recompute mid-flight); THIS process runs `CloseCutoff::execute()` for that period, which must block on the employee lock ≥ ~0.5×holdMs, then commit the freeze AFTER the recompute commits — and the final summary is `locked` (the close won, the recompute's result was frozen, not left unlocked). Then the mirror direction: a close committed first, and a subsequent `ComputeDailySummary` for an in-period date leaves the `locked` summary untouched (covered by Step 1's single-process test, so the concurrency test focuses on the block-and-serialize).

- [ ] **Step 8: Verify the lock is load-bearing.** Temporarily remove the period check added in Step 3 (or the employee lock), run `CloseVsRecomputeConcurrencyTest` + `ComputeSkipsClosedPeriodTest`, confirm FAIL, restore. Note before/after in the report.

- [ ] **Step 9: Run all, verify pass.**
- [ ] **Step 10: Commit.** `git commit -m "M7a: ComputeDailySummary skips a closed period (under the employee lock) + close-vs-recompute proof"`

---

### Task 8: Routes, controllers, FormRequests

**Files:**
- Create: `backend/app/Http/Controllers/Cutoff/ListCutoffsController.php`
- Create: `backend/app/Http/Controllers/Cutoff/CloseCutoffController.php`
- Create: `backend/app/Http/Controllers/Cutoff/ReopenCutoffController.php`
- Create: `backend/app/Http/Requests/CloseCutoffRequest.php`
- Create: `backend/app/Http/Requests/ReopenCutoffRequest.php`
- Create: `backend/app/Http/Resources/CutoffPeriodResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Cutoff/CutoffEndpointsTest.php`

**Interfaces:**
- Produces: `GET /office/cutoffs?office={id}`, `POST /office/cutoffs/close`, `POST /office/cutoffs/{period}/reopen` — all `auth:sanctum`, `cutoff.manage` + `OfficeScope`, 404-not-403.

- [ ] **Step 1: Failing endpoint tests.** Mirror the leave-types/holidays endpoint tests. Cover: an HR admin over the office can list + close + reopen; a user WITHOUT `cutoff.manage` is 403 (or 404 per the project's permission-vs-scope convention — check how leave-types tests assert a missing-permission case and match it); an out-of-office period/office is 404 (not 403); `close` with a non-boundary `period_start` → 422 `invalid_cutoff_start`; `reopen` without a reason → 400 `validation_failed`; the close/reopen envelopes return the `CutoffPeriodResource`. Also assert the exception-gate 422 surfaces with its blocking `details`.

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement.** Controllers resolve the office scoped to the caller (the leave-types `OfficeScope` pattern — read `ListLeaveTypesController`/`CreateLeaveTypeController` and mirror the scoping + `cutoff.manage` gate + the 404-not-403 for a foreign office). `CloseCutoffController` calls `CloseCutoff::execute(new CloseCutoffInput(officeId, period_start, actorId))`; `ReopenCutoffController` resolves the `{period}` scoped to the caller's offices (404 if foreign) and calls `ReopenCutoff`. FormRequests validate shape-only:

```php
// CloseCutoffRequest
'office_id' => ['required', 'uuid'],
'period_start' => ['required', 'date'],
// ReopenCutoffRequest
'reason' => ['required', 'string', 'min:1'],
```

`CutoffPeriodResource` serializes `{ id, office_id, start_date (YYYY-MM-DD), end_date, state, closed_by, closed_at (ISO8601|null) }`.

The route group mirrors the existing `/office/*` group (the leave-types routes): 

```php
Route::prefix('office')->group(function (): void {
    Route::get('/cutoffs', ListCutoffsController::class);
    Route::post('/cutoffs/close', CloseCutoffController::class);
    Route::post('/cutoffs/{period}/reopen', ReopenCutoffController::class);
});
```

Place them alongside the existing `office`-scoped admin routes; match that group's middleware.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit.** `git commit -m "M7a: cutoff endpoints (list/close/reopen), OfficeScope + cutoff.manage gated"`

---

### Task 9: Frontend — `/office/cutoffs` + approval `cutoff_locked` surfacing

**Files:**
- Modify: `frontend/web/src/lib/api.ts` (cutoff wire types + `api.cutoffs`)
- Modify: `frontend/web/src/lib/keys.ts` (`keys.cutoffs`)
- Create: `frontend/web/src/hooks/useCloseCutoff.ts`, `frontend/web/src/hooks/useReopenCutoff.ts`
- Create: `frontend/web/src/app/(app)/office/cutoffs/page.tsx` + its test
- Create: `frontend/web/src/components/domain/CutoffPeriodList.tsx` (or inline in the page — match the leave-types screen's structure)
- Modify: the SideNav (add `/office/cutoffs` under **Office** when the session carries HR offices — mirror how `/office/leave-types` is gated)
- Modify: wherever an approval error renders, to surface `cutoff_locked` (likely already generic — verify)

**Interfaces:**
- Consumes: `GET/POST` cutoff endpoints. Produces: `CutoffPeriod` wire type, `api.cutoffs.{list,close,reopen}`, `useCloseCutoff`/`useReopenCutoff` (invalidate `keys.cutoffs.list` + the approvals keys).

- [ ] **Step 1: Failing tests** for the data layer (hooks) and the page — mirror `useSubmitLeaveRequest.test.tsx` and the leave-types page test. Assert: list renders periods with state; "Close period" calls `api.cutoffs.close` and, on a 422 exception-gate error, renders the blocking exceptions via `InlineNotification`; "Reopen" prompts for a reason and calls `api.cutoffs.reopen`; the approval error path renders a `cutoff_locked` message.

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement the data layer.** `CutoffPeriod` type (`{ id, office_id, start_date, end_date, state: 'open'|'closed', closed_by: string|null, closed_at: string|null }`); `api.cutoffs.list(office)`, `.close({office_id, period_start})` (JSON), `.reopen(id, {reason})` (JSON); `keys.cutoffs.list(officeId)`; the two hooks invalidating `keys.cutoffs.list()` and `keys.requests.*Approvals()`.

- [ ] **Step 4: Implement the screen.** Mirror `/office/leave-types` (Carbon, React-Query, the office-picker-when-multiple-HR-offices pattern). A table of periods (window + state + closed-at), a "Close current period" action, and per-closed-period a "Reopen" with a required-reason prompt. On a close refusal, surface the returned `details` (incomplete dates, pending request count) in an `InlineNotification`. Only `var(--*)` tokens.

- [ ] **Step 5: SideNav + approval error.** Add the `/office/cutoffs` nav entry gated on HR offices (mirror `/office/leave-types`). Verify the existing approval error rendering already shows a domain error's message (it does for other 422s) — if it maps error codes to copy, add a `cutoff_locked` label; else it renders the message generically and no change is needed. Keep this tight.

- [ ] **Step 6: Run tests + typecheck + build.** `cd frontend/web && npm test && npm run typecheck && npm run build`. Expected: all green.
- [ ] **Step 7: Commit.** `git commit -m "M7a: /office/cutoffs screen + cutoff data layer + approval cutoff_locked surfacing"`

---

### Task 10: e2e-cutoffs.sh + docs

**Files:**
- Create: `scripts/e2e-cutoffs.sh`
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`

- [ ] **Step 1: Write `scripts/e2e-cutoffs.sh`.** Mirror `scripts/e2e-leave.sh`/`scripts/e2e-leave-and-ot.sh` (auth/login helper, `jq` envelope parsing, base URL, PASS/FAIL + `exit 1`). Prove, LIVE against the running dev stack:
  1. Pick an office + employee with computed, non-incomplete summaries in a semi-monthly window (reuse the seeder; ensure no in-period incomplete days / pending requests, or resolve them first).
  2. `POST /office/cutoffs/close {office_id, period_start}` → 200, the period is `closed`, and the employee's in-period summaries now read `locked` (fetch via the summary endpoint or DB).
  3. File + attempt to approve an adjustment/leave/overtime on an in-period date → the approve is refused `cutoff_locked` (422).
  4. Try to close again → `cutoff_already_closed` (409). Try to close a period with a known incomplete day (seed one) → `cutoff_has_unresolved_exceptions` (422) listing it.
  5. `POST /office/cutoffs/{period}/reopen {reason}` → 200, summaries back to `computed`; the same approval now succeeds.
  6. Assert the append-only `attendance_logs` are byte-identical across the whole run.
  Print per-assertion PASS/FAIL; `exit 1` on any mismatch.

- [ ] **Step 2: Run it live.** Ensure the stack is up + migrated (`make dev`; run the new migrations). `bash scripts/e2e-cutoffs.sh`. Fix any real defect it surfaces (a failing e2e is a real finding). Expected: exit 0.

- [ ] **Step 3: Docs.**
  - `02-data-model.md`: `cutoff_periods` (per-office, semi-monthly, state open/closed, the derived period↔summary relationship, no FK on the summary); note the freeze sets `daily_attendance_summaries.status = 'locked'`.
  - `03-api.md`: `GET /office/cutoffs`, `POST /office/cutoffs/close`, `POST /office/cutoffs/{period}/reopen`, their bodies + envelopes; the `cutoff_locked` (422) on `/requests/{id}/approve`; the exception-gate 422 with its `details`.
  - `05-rbac.md`: `cutoff.manage` (already seeded) gates the cutoff endpoints + `OfficeScope`; no new permission.
  - `06-roadmap.md`: mark **M7a complete**; describe the locking spine (per-employee row lock serializing close/approval/recompute) and the two two-connection proofs; note M7b (payroll export) is next.
  - `features.md`: HR can close/reopen a cutoff period; a closed period freezes the numbers and refuses approvals on locked days.

- [ ] **Step 4: Commit.** `git commit -m "M7a: e2e-cutoffs.sh + docs; M7a complete"`

---

## Self-Review (controller — before dispatch)

**Spec coverage:** Task 1 = `cutoff_periods`/`CutoffState` (spec §Data model). Task 2 = `CutoffCalendar` (§CutoffCalendar). Task 3 = `RequestAffectedDates` (§the request→date map). Task 4 = `CloseCutoff` + strict gate (§CloseCutoff, decision 2). Task 5 = `ReopenCutoff` (§ReopenCutoff). Task 6 = the approval refusal + race #1 (§the approval refusal, §Concurrency #1, decision 3). Task 7 = the compute-skip hardening + race #2 (§Concurrency #2 — refined to a period-aware skip in ComputeDailySummary, strictly stronger than the spec's "re-read status", covering the no-summary-yet case). Task 8 = routes/RBAC (§Routes). Task 9 = frontend (§Frontend). Task 10 = e2e + docs (§Testing, §Done when). Every spec section maps to a task.

**Placeholder scan:** no TBD/TODO; each code step carries real code or a concrete "read sibling X and mirror" with the sibling named. The "verify against sibling" notes (activity-log idiom, permission-vs-scope assertion shape, the return-type of the compute skip) are genuine per-codebase details the implementer must read, each pointing at the exact file.

**Type consistency:** `CutoffState` (`open`/`closed`) is used consistently across the enum, the CHECK, the model cast, and the wire type. `RequestAffectedDates::for(): array<string>` feeds both `CloseCutoff::blockingRequestIds` and `CutoffGuard::assertOpen` with the same shape. The `Employee::lockForUpdate()` lock is the single serialization point named identically in Tasks 4, 6, and 7 — the load-bearing invariant. `CutoffLocked`/`CutoffHasUnresolvedExceptions`/`CutoffAlreadyClosed`/`CutoffNotClosed`/`InvalidCutoffStart` error codes are defined once (Tasks 4–6) and asserted in Tasks 8 and 10.

**One refinement flagged for review:** Task 7 implements the close-vs-recompute defense as a period-aware skip inside `ComputeDailySummary` rather than the spec's literal "`RecomputeDay` re-reads status." This is strictly stronger (covers the synchronous-punch compute path and the no-summary-yet case, both of which the status-read misses) and keeps the guard under the one employee lock. It is a refinement of the spec's intent, not a contradiction — but it is the one place the plan deliberately diverges from the spec's wording, so the reviewer should confirm the divergence is acceptable.
