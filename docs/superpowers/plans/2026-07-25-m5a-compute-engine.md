# M5a — Compute Engine (core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `ComputeDailySummary` turns one employee-day of effective punches into a premium-weighted-hours breakdown (`daily_attendance_summaries` + `daily_summary_lines`, integer minutes + basis points, **no pesos**), stamped with the `rule_version_id` that produced it — the first thing that makes punches into pay.

**Architecture:** A pure `DailyComputation` Domain calculator (effective-punch minutes + day context + `PayRates` → buckets, table-tested with no DB) assembled by a `ComputeDailySummary` action that resolves context (holidays/schedule/pay_rules/art82), persists idempotently, and is triggered synchronously on write (punch, approved adjustment). `PayMultiplier` is refactored to read a `PayRates` matrix (from the effective `pay_rules` version) instead of hardcoded constants.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 · Next 16 / React 19 / TS · Pest (real Postgres) · Vitest.

**Mirror M4a/M4b/M4c (merged on `main`) for boilerplate**, changing only the stated delta:
- Schema test (live CHECK, throwing inserts): `backend/tests/Feature/Schema/PayRuleSchemaTest.php`, `HolidaySchemaTest.php`
- Model + HasUuids + LogsActivity: `backend/app/Models/PayRule.php`, `Holiday.php`; child w/ enum cast, no log: `PayRuleDayRate.php`
- Resource: `backend/app/Http/Resources/HolidayResource.php`; self-scoped read: `backend/app/Http/Controllers/Attendance/ListMyAttendanceController.php` (find its exact name/path)
- The M1 primitives to ASSEMBLE (read each before Task 4/5): `backend/app/Domain/Time/{PunchPairer,PairedPunches,MealBreakPolicy,OvertimeThreshold,NightDiffSplitter,WorkInterval,WorkedSplit,Minutes}.php`, `backend/app/Domain/Pay/{PayMultiplier,BasisPoints,DayType}.php`
- The config readers: `backend/app/Domain/Schedule/ScheduleResolver.php` (`resolve(Employee,$date): ResolvedSchedule`), `backend/app/Models/Holiday.php`, `backend/app/Models/PayRule.php`
- Effective-ledger inputs (M3/M3.6): `backend/app/Models/{AttendanceLog,AttendanceAnnulment}.php`, `backend/app/Actions/Attendance/{RecordPunch,ApplyAttendanceAdjustment}.php`
- Frontend: `frontend/web/src/lib/{keys,api}.ts`, `frontend/web/src/hooks/useMyAttendance.ts`, `frontend/web/src/app/(app)/me/attendance/page.tsx`, `frontend/web/src/components/domain/{MonthCalendar,DayCell,Duration}.tsx`

## Global Constraints

- `declare(strict_types=1);` every PHP file in `app/`+`tests/`. Actions final, own their transaction, never touch HTTP. Domain framework-agnostic (no `Illuminate\*`, no `config()`, no HTTP — the `DailyComputation` calculator and `PayRates`/`PayMultiplier` are pure).
- **Integer minutes / basis points, never a float, in any layer.** Use `Minutes`, `BasisPoints`, `Money::fraction` — never raw float math.
- **`is_art82_exempt` gates every premium** — an exempt employee's every bucket is `BasisPoints::one()` (100%): no OT, no night differential, no holiday premium. Read it and branch before applying any multiplier.
- **The append-only ledger is read alongside, never over.** Effective punches = `attendance_logs` for `(employee, date)` **minus** rows with an `attendance_annulments` row (approved adjustments already materialize their correction as an `attendance_log`; voids write an annulment). Never mutate a punch.
- uuid v7 PKs (`->default(DB::raw('uuidv7()'))`), uuid FKs; models `HasUuids` + `newUniqueId()`→`Str::uuid7()->toString()` + `uniqueIds()`. String columns + backed enums + CHECK; never native PG enums. activity_log morph uuid.
- Calendar dates on the wire are `YYYY-MM-DD` strings; office-local day boundaries via `offices.timezone` (the same helper `/me/attendance` uses to group punches by local date).
- Success `{data}` / error `{error}`, closed envelope. Tests run against **real PostgreSQL, never SQLite.** Two suites: `./vendor/bin/pest` and `./vendor/bin/pest --testsuite=Arch`.
- Frontend: token-only styling (no raw hex outside `carbon.css`); `font: var(--t-*)` + `--ls-*` (except `--t-card-title`); `'use client'`; `import type`; no `enum`.
- **Commit messages carry no attribution trailers.**

---

### Task 1: `daily_attendance_summaries` + `daily_summary_lines` schema & models

**Files:**
- Create: `backend/database/migrations/2026_07_30_000001_create_daily_attendance_summaries_table.php`, `backend/app/Models/DailyAttendanceSummary.php`, `backend/app/Models/DailySummaryLine.php`
- Test: `backend/tests/Feature/Schema/DailyAttendanceSummarySchemaTest.php`

**Interfaces:**
- Produces: `DailyAttendanceSummary` (hasMany `lines`; casts, LogsActivity `daily_attendance_summary`), `DailySummaryLine` (belongsTo summary; `kind` cast if an enum is introduced — see below).

- [ ] **Step 1: Failing schema test** (mirror `PayRuleSchemaTest` live-constraint style). Assert: a summary + lines persist; `unique(employee_id, date)` rejects a duplicate; `status` outside the 4 values rejected; `kind` outside the 5 values rejected; `unique(summary_id, kind)` rejects a duplicate line kind; negative minutes / negative `applied_bp` / non-positive line `minutes` rejected; `rule_version_id` **`ON DELETE RESTRICT`** — deleting a `pay_rules` row a summary references throws (this is the M4c-seam closure and MUST be exercised); cascade — deleting a summary deletes its lines.

```php
it('refuses to delete a pay_rules version a summary is stamped with (RESTRICT)', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $rule = PayRule::create(['effective_from' => '2026-01-01', 'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000, 'night_diff_bp' => 11000]);
    DailyAttendanceSummary::create(['employee_id' => $employee->id, 'date' => '2026-08-03',
        'day_type' => 'ordinary', 'is_rest_day' => false, 'scheduled_minutes' => 480,
        'is_art82_exempt' => false, 'rule_version_id' => $rule->id, 'worked_minutes' => 480,
        'late_minutes' => 0, 'undertime_minutes' => 0, 'status' => 'computed', 'is_incomplete' => false]);
    expect(fn () => $rule->delete())->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run, expect failure. Step 3: Migration** (both tables one migration):

```php
Schema::create('daily_attendance_summaries', function (Blueprint $table): void {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->text('day_type');
    $table->boolean('is_rest_day');
    $table->integer('scheduled_minutes');
    $table->boolean('is_art82_exempt');
    $table->foreignUuid('rule_version_id')->nullable()->constrained('pay_rules')->restrictOnDelete();
    $table->integer('worked_minutes');
    $table->integer('late_minutes');
    $table->integer('undertime_minutes');
    $table->text('status')->default('pending');
    $table->boolean('is_incomplete')->default(false);
    $table->timestampTz('computed_at')->nullable();
    $table->timestampsTz();
    $table->unique(['employee_id', 'date']);
});
DB::statement("ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_day_type_check CHECK (day_type IN ('ordinary','special_working','special_non_working','regular_holiday','double_regular_holiday'))");
DB::statement("ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_status_check CHECK (status IN ('pending','computed','disputed','locked'))");
DB::statement('ALTER TABLE daily_attendance_summaries ADD CONSTRAINT das_minutes_nonneg_check CHECK (scheduled_minutes >= 0 AND worked_minutes >= 0 AND late_minutes >= 0 AND undertime_minutes >= 0)');

Schema::create('daily_summary_lines', function (Blueprint $table): void {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('summary_id')->constrained('daily_attendance_summaries')->cascadeOnDelete();
    $table->text('kind');
    $table->integer('minutes');
    $table->integer('applied_bp');
    $table->timestampsTz();
    $table->unique(['summary_id', 'kind']);
});
DB::statement("ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_kind_check CHECK (kind IN ('regular_day','regular_night','overtime_day','overtime_night','holiday_unworked'))");
DB::statement('ALTER TABLE daily_summary_lines ADD CONSTRAINT dsl_minutes_pos_check CHECK (minutes > 0 AND applied_bp >= 0)');
```

- [ ] **Step 4: Models.** `DailyAttendanceSummary` — `HasUuids` + `LogsActivity` (name `daily_attendance_summary`, logOnly the day-level columns, logOnlyDirty), casts `date`→date / `is_rest_day`/`is_art82_exempt`/`is_incomplete`→bool / the minute columns→int / `computed_at`→datetime / `day_type`→`DayType::class`, `lines(): HasMany`, `newUniqueId`/`uniqueIds`. `DailySummaryLine` — `HasUuids`, casts `minutes`/`applied_bp`→int, `summary(): BelongsTo`, no activity log. Introduce a `SummaryLineKind` string enum (`app/Domain/Pay/SummaryLineKind.php`, 5 cases) and cast `kind`→it (add to the Arch enum ignore-list like `DayType`).
- [ ] **Step 5: Schema test → PASS. Step 6: Arch. Step 7: Commit** `git commit -m "Compute: daily_attendance_summaries + summary lines, rule_version RESTRICT"`.

---

### Task 2: `PayRates` value object + `PayMultiplier` reconciliation

**Files:**
- Create: `backend/app/Domain/Pay/PayRates.php`
- Modify: `backend/app/Domain/Pay/PayMultiplier.php` (take rates, drop constants), `backend/tests/Unit/Domain/Pay/PayMultiplierTest.php` (pass `PayRates::statutory()`)
- Test: `backend/tests/Unit/Domain/Pay/PayRatesTest.php`

**Interfaces:**
- Consumes: `config('hris.pay_floors')` (via `PayRates::statutory()`), `PayRule`+`dayRates` (via `PayRates::fromVersion`).
- Produces: `PayRates` (the rate matrix). `PayMultiplier::forWorkedTime(DayType, bool $isRestDay, bool $isOvertime, bool $isNightDiff, bool $isArt82Exempt, PayRates $rates): BasisPoints` and `forUnworkedDay(DayType, bool $isArt82Exempt, PayRates $rates): BasisPoints`.

- [ ] **Step 1: Failing test** — `PayRates::statutory()` equals `config('hris.pay_floors')`'s values (this is app-boundary config read — `PayRates` MAY read config since it's the composition root for rates; keep it in `app/Domain/Pay` but note it reads config, OR read config in a factory and pass in — decide: `PayRates::statutory()` reads config, `PayRates::fromVersion` reads a model, both are constructors, and the arch "domain never reads config" rule must be checked — if it trips, move `statutory()`/`fromVersion` to a non-Domain `App\Support\PayRatesFactory` and keep `PayRates` a pure holder. Verify against `tests/Arch/ConventionsTest.php`'s domain rules FIRST and choose the placement that passes). `PayRates::fromVersion($payRule)` builds the same shape from a version's `overtime_*_bp`/`night_diff_bp` + its 5 `dayRates`. And `PayMultiplier::forWorkedTime(...)` given `PayRates::statutory()` reproduces the SAME values the current hardcoded `PayMultiplierTest` asserts (regular_holiday worked = 20000, on rest = 26000, etc.) — copy those exact expectations.
- [ ] **Step 2: Run, expect failure. Step 3: `PayRates`** — holds the worked matrix `[dayType => [notRestBp, restBp]]`, unworked `[dayType => bp]`, and the 3 scalars; `worked(DayType,$isRest): int`, `unworked(DayType): int`, `overtimeOrdinary()/overtimePremium()/nightDiff(): int` accessors. **Step 4: Refactor `PayMultiplier`** — replace every `self::WORKED_BASE[...]` / `self::UNWORKED[...]` / `self::OVERTIME_*` / `self::NIGHT_DIFFERENTIAL` read with the passed `$rates` accessor; delete the constants; add the `PayRates $rates` parameter to both public methods (last param). Keep the composition logic (art82 short-circuit, overtime factor, night compounding) byte-for-byte. **Step 5: Update `PayMultiplierTest`** — every `PayMultiplier::forWorkedTime(...)`/`forUnworkedDay(...)` call gains a trailing `PayRates::statutory()` arg; assertions unchanged (statutory rates == the old constants, so every expected value holds). This proves the refactor is behavior-preserving.
- [ ] **Step 6: Both tests → PASS. Step 7: Arch** (domain purity — resolve the config-read placement per Step 1). **Step 8: Commit** `git commit -m "Compute: PayRates matrix; PayMultiplier reads rates, not constants"`.

---

### Task 3: `EffectivePunches` reader

**Files:**
- Create: `backend/app/Domain/Attendance/EffectivePunches.php` (or `app/Support/` if it must query — decide by the arch rule, mirroring how `ScheduleResolver` is allowed to query in Domain)
- Test: `backend/tests/Feature/Attendance/EffectivePunchesTest.php`

**Interfaces:**
- Produces: `EffectivePunches::forDate(Employee $e, string $date): array` — the non-annulled `attendance_logs` for `(employee, date)` in the office-local zone, as ascending integer **minutes from local midnight** (a post-midnight night-shift punch may exceed 1439; keep it, don't wrap — `WorkInterval` expects that). Each element also carries `direction` (in/out) so `PunchPairer` can pair — return an array of `['minute' => int, 'direction' => 'in'|'out']` OR whatever shape `PunchPairer::pair` consumes (read `PunchPairer` first and match it exactly).

- [ ] **Step 1: Failing test** — seed an employee + attendance_logs for a date; assert `forDate` returns them as sorted local-minute values; an **annulled** log (with an `attendance_annulments` row) is EXCLUDED; a log on a different local date (across the office-zone midnight boundary) is excluded; ordering is ascending. Mirror how `ListMyAttendanceController`/`AttendanceMonth` groups by office-local date (reuse that zone helper — do NOT reinvent date math).
- [ ] **Step 2: Run, expect failure. Step 3: Implement** — query `AttendanceLog` for the employee where the office-local date of `punched_at` equals `$date`, `whereNotExists`/`whereNotIn` the `attendance_annulments`, order by `punched_at`, map each to local minutes-from-midnight. Reuse the existing office-zone grouping helper. **Step 4: Test → PASS. Step 5: Arch. Step 6: Commit** `git commit -m "Compute: EffectivePunches — the non-annulled ledger for a date"`.

---

### Task 4: `DailyComputation` — the pure calculator (the crown jewel)

**Files:**
- Create: `backend/app/Domain/Compute/DailyComputation.php`, `backend/app/Domain/Compute/ComputedDay.php`, `backend/app/Domain/Compute/ComputedLine.php`
- Test: `backend/tests/Unit/Domain/Compute/DailyComputationTest.php`

**Interfaces:**
- Consumes: `PunchPairer`, `MealBreakPolicy`, `OvertimeThreshold`, `NightDiffSplitter`, `WorkInterval`, `Minutes`, `PayMultiplier`, `PayRates`, `DayType`, `SummaryLineKind` (Tasks 1–2).
- Produces: `DailyComputation::compute(DailyComputationInput $in): ComputedDay`. `DailyComputationInput` (pure, no models): the effective punches (from Task 3's shape), `DayType $dayType`, `bool $isRestDay`, `int $scheduledMinutes`, `int $breakMinutes`, `bool $isArt82Exempt`, `PayRates $rates`. `ComputedDay` public readonly: `int $workedMinutes`, `int $lateMinutes`, `int $undertimeMinutes`, `bool $isIncomplete`, `array $lines` (list of `ComputedLine{ SummaryLineKind $kind, int $minutes, int $appliedBp }`).

- [ ] **Step 1: Write the failing table-driven test.** This is the milestone's proof — cover the whole matrix, pure (no DB). Each case constructs a `DailyComputationInput` and asserts `workedMinutes` + the exact `lines` (kind, minutes, appliedBp). Representative cases (add all):

```php
use App\Domain\Pay\{DayType, PayRates, SummaryLineKind};
use App\Domain\Compute\{DailyComputation, DailyComputationInput};

function rates(): PayRates { return PayRates::statutory(); }

it('an ordinary 8h day is 480 regular_day minutes at 100%', function (): void {
    // 08:00-17:00 with a 60m break => 480 net worked, scheduled 480.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [['minute' => 480, 'direction' => 'in'], ['minute' => 1020, 'direction' => 'out']],
        dayType: DayType::Ordinary, isRestDay: false, scheduledMinutes: 480, breakMinutes: 60,
        isArt82Exempt: false, rates: rates()));
    expect($out->isIncomplete)->toBeFalse()->and($out->workedMinutes)->toBe(480);
    expect($out->lines)->toHaveCount(1)
        ->and($out->lines[0]->kind)->toBe(SummaryLineKind::RegularDay)
        ->and($out->lines[0]->minutes)->toBe(480)->and($out->lines[0]->appliedBp)->toBe(10000);
});

it('a rest-day worked day prices the same minutes at 130%', function (): void { /* isRestDay:true => appliedBp 13000 */ });
it('a regular holiday worked is 200%', function (): void { /* DayType::RegularHoliday => 20000 */ });
it('a regular holiday NOT worked yields one holiday_unworked line at 100%', function (): void {
    // no punches, scheduled 480, DayType::RegularHoliday, not art82 => holiday_unworked 480 @ 10000
});
it('a night shift splits into regular_night carrying the compounded 110%', function (): void {
    // 22:00->06:00 (1320->1800), scheduled 480, break 0 => regular_night 480 @ 10000*11000/10000 = 11000
});
it('work beyond the scheduled day is overtime at +25% ordinary', function (): void {
    // worked 600, scheduled 480 => regular_day 480 @10000 + overtime_day 120 @12500
});
it('a compressed 10h scheduled day keeps hour nine regular', function (): void {
    // worked 600, scheduled 600 => regular_day 600 @10000, no overtime
});
it('an unpaired punch is incomplete: zero worked, no lines', function (): void {
    // single 'in' punch => isIncomplete true, workedMinutes 0, lines []
});
it('an art82-exempt employee gets every bucket at 100%', function (): void {
    // regular holiday, night, OT, isArt82Exempt:true => every appliedBp 10000, no premium anywhere
});
it('undertime and late are populated', function (): void { /* worked < scheduled, late start */ });
```

- [ ] **Step 2: Run, expect failure. Step 3: `ComputedLine`/`ComputedDay`/`DailyComputationInput`** value objects (readonly, final). **Step 4: `DailyComputation::compute`** — the pure pipeline: pair (`PunchPairer::pair`); if `hasUnpaired()` → `ComputedDay` incomplete, 0 worked, no lines. Else net the break (`MealBreakPolicy::assumed($breakMinutes, ...)->netWorked(...)`), split regular/OT (`OvertimeThreshold::split`/`undertime`), split each paired interval's night portion (`NightDiffSplitter::split(WorkInterval::of($start,$end))`), cross regular/OT × day/night into the 4 bucket minute totals, compute late, build a `ComputedLine` per non-zero bucket with `appliedBp = PayMultiplier::forWorkedTime(dayType, isRestDay, isOvertime, isNight, isArt82Exempt, $rates)->value`, and the `holiday_unworked` line when applicable via `forUnworkedDay`. Pure — no DB, no models, no config (rates passed in). **Step 5: Test → PASS (the whole matrix). Step 6: Arch** (domain purity). **Step 7: Commit** `git commit -m "Compute: DailyComputation — the pure premium-bucket calculator"`.

---

### Task 5: `ComputeDailySummary` action (resolve context + persist)

**Files:**
- Create: `backend/app/Actions/Compute/ComputeDailySummary.php`
- Test: `backend/tests/Feature/Compute/ComputeDailySummaryTest.php`

**Interfaces:**
- Consumes: `EffectivePunches` (Task 3), `DailyComputation` (Task 4), `ScheduleResolver`, `Holiday`, `PayRule`, `PayRates::fromVersion`, `DailyAttendanceSummary`/`DailySummaryLine` (Task 1), `Employee` (`current_office_id`, art82 via `employment_records`).
- Produces: `ComputeDailySummary::execute(Employee $e, string $date): DailyAttendanceSummary` — idempotent upsert of the summary + its lines.

- [ ] **Step 1: Failing feature test (DB, real Postgres)** — a few integration scenarios (the exhaustive matrix is Task 4's; here prove the wiring): a seeded employee + schedule + punched ordinary day computes a `computed` summary with the right `regular_day` line + `rule_version_id` set; the Aug-21 (special-non-working, M4a-seeded) case → 13000; **idempotent recompute** — calling `execute` twice yields one summary + identical lines (no duplicates); an incomplete day → `is_incomplete`, 0 worked, no lines; a custom `pay_rules` version (holiday at 250%) changes the computed `applied_bp` (proves it reads `pay_rules`).
- [ ] **Step 2–4:** resolve `DayType` (holiday on the date or `ordinary`), `ScheduleResolver::resolve` (→ isRestDay, scheduledMinutes, and the break — read `ResolvedSchedule` for how break is exposed), `is_art82_exempt` (employment_records effective on date), the `PayRule` version (greatest `effective_from ≤ date`) → `PayRates::fromVersion`; call `EffectivePunches::forDate` → `DailyComputation::compute`; then in **one `DB::transaction`**, delete any existing summary for `(employee,date)` (cascade drops its lines) and insert the fresh summary (with `rule_version_id`, context snapshot, `status = computed`, `computed_at = now`) + its lines. A rest-day-unworked / no-schedule / incomplete day stores `rule_version_id = null` and no lines. Final class, own transaction, no HTTP. **Step 5: Test → PASS. Step 6: Arch. Step 7: Commit** `git commit -m "Compute: ComputeDailySummary — resolve context + idempotent persist"`.

---

### Task 6: Synchronous on-write trigger (punch + adjustment approval)

**Files:**
- Modify: `backend/app/Actions/Attendance/RecordPunch.php`, `backend/app/Actions/Attendance/ApplyAttendanceAdjustment.php` (call `ComputeDailySummary` after the write commits)
- Test: `backend/tests/Feature/Compute/ComputeOnWriteTest.php`

**Interfaces:**
- Consumes: `ComputeDailySummary` (Task 5).

- [ ] **Step 1: Failing test** — recording a punch for `(employee, date)` leaves a fresh `computed` summary for that date; approving/applying an adjustment for a date (re)computes that date's summary. Assert the summary exists + reflects the write.
- [ ] **Step 2–4:** After each action's own transaction commits, call `ComputeDailySummary::execute($employee, $affectedDate)`. Derive `$affectedDate` from the punch's office-local date / the adjustment's target date. **Keep the compute OUTSIDE the write's own transaction** (compute-after-commit) so a compute failure can't roll back a valid punch — but a compute failure must surface (don't swallow). If the codebase has an after-commit hook idiom (`DB::afterCommit`), use it; otherwise call it right after the action returns. Inject `ComputeDailySummary` via the container. **Step 5: Test → PASS. Step 6: Arch. Step 7: Commit** `git commit -m "Compute: recompute a date's summary synchronously on punch and on adjustment approval"`.

---

### Task 7: `GET /me/attendance/summary` read endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Attendance/ListMySummaryController.php`, `backend/app/Http/Requests/ListMySummaryRequest.php`, `backend/app/Http/Resources/DailySummaryResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Compute/ListMySummaryTest.php`

**Interfaces:**
- Produces: `GET /me/attendance/summary?month=YYYY-MM` → `{ data: [ DailySummaryResource ] }` keyed/ordered by date; self-scoped (the caller's own employee). `DailySummaryResource`: the day-level facts + `lines: [{kind, minutes, applied_bp}]`.

- [ ] **Step 1: Failing test** — a caller with computed summaries for the month gets them (with lines) for their own employee; `month` validated (`regex:/^\d{4}-(0[1-9]|1[0-2])$/`) → 400 on malformed; a caller with no employee record → the same `not_an_employee` path `/me/attendance` uses (mirror it). Scoped to self — never another employee's summaries.
- [ ] **Step 2–4:** `ListMySummaryRequest` (month regex, self — no target id). Controller: resolve the caller's employee (mirror `ListMyAttendanceController`), `DailyAttendanceSummary::where('employee_id', …)->whereBetween('date', [monthStart, monthEnd])->with('lines')->orderBy('date')->get()`. `DailySummaryResource` shape. Route `GET /me/attendance/summary` in the authenticated group. **Step 5: Test → PASS. Step 6: Arch. Step 7: Commit** `git commit -m "Compute: GET /me/attendance/summary — the computed month, self-scoped"`.

---

### Task 8: Frontend — the computed layer on `/me/attendance`

**Files:**
- Modify: `frontend/web/src/lib/keys.ts`, `frontend/web/src/lib/api.ts`, `frontend/web/src/app/(app)/me/attendance/page.tsx`
- Create: `frontend/web/src/hooks/useMyAttendanceSummary.ts` (+ test), `frontend/web/src/components/domain/DaySummaryDetail.tsx` (+ test)
- Test: extend `frontend/web/src/app/(app)/me/attendance/attendance.test.tsx` (or its actual name)

**Interfaces:**
- Produces: `keys.attendance.summary(month)`; `api.attendance.summary(month)`; wire types `DailySummary`/`DailySummaryLine`; `useMyAttendanceSummary(month)`; the computed layer.

- [ ] **Step 1: keys + api + wire types** — `keys.attendance.summary = (month) => ['attendance','summary',month] as const`. Wire types verified against `DailySummaryResource`: `SummaryLineKind = 'regular_day'|'regular_night'|'overtime_day'|'overtime_night'|'holiday_unworked'`; `DailySummaryLine = { kind: SummaryLineKind; minutes: number; applied_bp: number }`; `DailySummary = { date; day_type; is_rest_day; scheduled_minutes; is_art82_exempt; worked_minutes; late_minutes; undertime_minutes; status; is_incomplete; rule_version_id: string|null; lines: DailySummaryLine[] }`; `api.attendance.summary(month): Promise<DailySummary[]>` (path `/me/attendance/summary?month=`, no `/api/v1`).
- [ ] **Step 2: `useMyAttendanceSummary(month)`** — mirror `useMyAttendance`. **Step 3: `DaySummaryDetail`** — renders one day's `worked_minutes` (via `Duration`), the badges (`incomplete`/`OT`/`premium` — `OT` when an `overtime_*` line exists, `premium` when any `applied_bp > 10000`), and the breakdown lines (label each kind, show minutes + the `%` from `applied_bp/100`). Token-only.
- [ ] **Step 4: the screen** — the `/me/attendance` `MonthCalendar` gains, per day, the computed `worked_minutes` total + a badge (from the summary map), and the day-detail shows `DaySummaryDetail` **alongside** the existing raw punch times (do NOT remove the raw-punch display — the ledger stays visible). Tests: a day with a computed summary shows its worked total + badge; a `premium`/`OT` day shows the badge; the breakdown lines render; the raw punches are still shown.
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint && npm run build`. Step 6: Commit** `git commit -m "Compute(web): the computed layer on /me/attendance"`.

---

### Task 9: Docs, seeder, e2e, roadmap renumber, and the gate

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/06-roadmap.md`, `docs/features.md`, `backend/database/seeders/CompanySeeder.php`
- Create: `scripts/e2e-compute.sh`

- [ ] **Step 1: Seeder** — ensure a seeded Manila employee has a schedule (M4b) + a punched day (an in/out pair on a seeded date), so a summary computes on `make dev` and `/me/attendance` shows a computed day. Match the seeder style; the on-write trigger (Task 6) means seeding punches auto-computes — verify a summary row results, or call `ComputeDailySummary` in the seeder for the seeded historical punches.
- [ ] **Step 2: `scripts/e2e-compute.sh`** — mirror `scripts/e2e-holidays.sh`. Walk (live): a seeded employee's punched ordinary day → `GET /me/attendance/summary?month=` shows a `regular_day` line at 100% with a `rule_version_id`; the Aug-21 special-non-working day → 130%; an `is_art82_exempt` employee → 100% everywhere; recompute is idempotent; assert (via `psql`) that deleting the stamped `pay_rules` version is refused (RESTRICT). `bash -n` clean; run live only if the stack is up.
- [ ] **Step 3: Docs** — `02-data-model.md` (the two tables + the compute pipeline + `PayRates` reconciliation + the effective-ledger read). `03-api.md` (`GET /me/attendance/summary`). `06-roadmap.md`: **an M5 (M5a) Status: complete block with real counts; renumber the stale detailed headings to match the authoritative resequencing table — `## M5 — Requests and approvals` → `## M6 — Requests and approvals`, `## M6 — Cutoffs …` → `## M7 — Cutoffs …`, `## M7 — Admin portal …` → `## M8 …`, `## M8 — Containerization …` → `## M9 …` — and add the real `## M5 — Compute engine` section**; note M5b (`RecomputeRange`) is next. `features.md` (the employee sees their computed day — premium-weighted hours, never a peso). Verify every claim against code.
- [ ] **Step 4: Full gate** — `cd backend && ./vendor/bin/pest && ./vendor/bin/pest --testsuite=Arch`; `cd ../frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts.
- [ ] **Step 5: Commit** `git commit -m "Compute: docs, seeder, e2e, M5a status; roadmap renumbered to the resequencing table"`.

## Done When

A seeded Manila employee's punched 8-hour ordinary day computes to a `computed` summary with a `regular_day` line of 480 minutes @ `10000`, stamped with the effective `rule_version_id`; the Aug-21 special-non-working day → worked minutes @ `13000`; a night shift → `regular_night` at the compounded night rate; an `is_art82_exempt` manager → every bucket @ `10000`; an incomplete day → 0 worked + `is_incomplete`; computing the same day twice is identical (idempotent); deleting the stamped `pay_rules` version is refused (`RESTRICT`); `PayMultiplier` reads `pay_rules` (the reconciliation, M1 tests still green). **No pesos stored anywhere.** The `/me/attendance` screen shows each day's computed total + breakdown alongside the raw punches. The roadmap headings match the resequencing table. Full suite green, `scripts/e2e-compute.sh` passes live. **M5b (`RecomputeRange`) builds on this.**
