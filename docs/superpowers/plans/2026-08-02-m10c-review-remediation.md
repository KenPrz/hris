# M10c — Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the ten findings from the 2026-08-02 full-system review that produce a wrong payroll figure, an unbounded credential, or a silently-undone background job.

**Architecture:** Three independently mergeable phases. Phase 1 fixes the compute engine — six wrong-money defects, four of which trace to one root cause (`ResolvedSchedule::scheduledMinutes` used as three different quantities it is not). Phase 2 closes the production and auth gaps. Phase 3 fixes the accessibility floor and corrects two docs that describe mechanisms which do not exist. Every phase ends green on both suites and is safe to ship alone.

**Tech Stack:** Laravel 13.21 / PHP 8.5 / PostgreSQL 18 / Pest · Next.js 16 / React 19 / TypeScript / Vitest · Docker Compose / FrankenPHP

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/`. An arch test enforces it.
- Never call `env()` outside `config/`. An arch test enforces it.
- Worked time is integer minutes; money is integer centavos; multipliers are integer basis points. Never a float, in any layer.
- One system action = one route = one controller = one Action class. Actions take an Input DTO, return a domain object, and know nothing about HTTP.
- Success is always `{"data": ...}`; errors are always `{"error": ...}`. Validation failures map to **400**, not 422.
- Tests run against real PostgreSQL, never SQLite. `phpunit.xml` is deliberately repointed; do not "fix" it back.
- Punches are append-only. A correction is a new row read alongside the original, never over it.
- A locked summary and a closed period are immutable — no fix in this plan may write into one.
- Every premium computation reads `is_art82_exempt` first.
- Calendar dates on the wire are `YYYY-MM-DD` strings, never `Date` objects.
- `DESIGN.md` is the token authority; `frontend/web/src/styles/carbon.css` is the only place a token enters code. A raw hex in a component is a bug.
- Commit messages carry no attribution trailers — message body only.
- Run the frontend suite as `npm test -- --maxWorkers=4 --testTimeout=20000`. A bare `npm test` times out ~16 tests from worker contention.
- `attendance.test.tsx`'s "renders Clock in for today even when other days this month have punches" is a **pre-existing red** that fails whenever the container clock resolves to the 2nd of the month. It is out of scope for every task here. Do not fix it, and do not count it as a regression.

## Stated assumptions

Two findings need a policy call that the review could not make on its own. Both are implemented as written below; if the answer differs, the named task changes and nothing else does.

1. **Overtime begins after 8 hours (480 minutes), always** — Art. 83. The demo shift template is 540 net minutes, so today an employee earns no overtime premium for their ninth hour. Task 2 caps the threshold at 480. **This is wrong for a legally compressed workweek** (DOLE D.O. 02-04 permits a 4×10 with a waiver, where overtime begins after 600). Compressed workweeks are on the Deferred table and no office runs one today. If one ever does, the fix is an `offices.is_compressed_workweek` flag that lifts the cap to the scheduled length — not a config change.
2. **Approved leave pays exactly what it debits.** Today the ledger debit reads `offices.minutes_per_leave_day` and the pay line reads the employee's resolved `scheduledMinutes`; a 4×10 employee debits 480 and is paid 600. Task 5 snapshots one number onto `leave_details` at submit time and has both sides read it. The alternative — pay the scheduled length and debit the scheduled length — was rejected because it makes a leave balance mean different things for different employees.

---

## File structure

| File | Responsibility after this plan |
| --- | --- |
| `backend/config/hris.php` | Gains `meal_break.applies_over_minutes` (300) and `overtime.statutory_threshold_minutes` (480) — the two statutory constants the compute engine was hardcoding wrongly. |
| `backend/app/Domain/Compute/DailyComputationInput.php` | Gains `mealBreakAppliesOverMinutes` and `leaveMinutes`; `onApprovedLeave: bool` is replaced by `leaveMinutes: int`. |
| `backend/app/Domain/Compute/DailyComputation.php` | Uses the passed meal-break threshold; emits `leave_with_pay` on punched days too, capped so leave + worked never exceeds the scheduled day. |
| `backend/app/Actions/Compute/ComputeDailySummary.php` | Resolves both statutory constants from config; caps the overtime threshold at 480. |
| `backend/app/Actions/Attendance/RecordPunch.php` | Recomputes the punch's own local date **and** the day before it, so a post-midnight out-punch reaches the shift it closes. |
| `backend/app/Domain/Attendance/PunchOrdering.php` | **New.** The one place that decides whether a new punch is orderable against the employee's existing ledger for that day. |
| `backend/app/Domain/Time/PunchPairer.php` | A duplicate minute no longer throws; it yields an incomplete day, which the cutoff gate already refuses to close over. |
| `backend/app/Domain/Leave/LeaveDayLookup.php` | Returns the minutes attributable to a date, not a bare boolean. |
| `backend/app/Actions/Cutoff/CloseCutoff.php` | Materializes a summary for every employee-day in the period before freezing, so holiday and absence days exist to be locked and exported. |
| `backend/app/Domain/Leave/LeaveOverlap.php` | **New.** The approved-leave overlap check, run under the employee lock `ApproveRequest` already holds. |
| `backend/config/sanctum.php` | **New.** Token expiry. |
| `compose.prod.yml` | Gains a `queue` service running `queue:work`. |
| `frontend/web/src/styles/carbon.css` | `--ink-subtle` and `--success` moved to AA-passing values. |

---

# Phase 1 — Payroll correctness

Six wrong-money defects. Nothing in this phase is optional before a real payroll run.

---

### Task 1: Deduct the meal break against the statutory threshold, not the scheduled day

**The defect.** `DailyComputation.php:75` passes `$in->scheduledMinutes` as `MealBreakPolicy`'s `appliesOverMinutes`. That parameter means "the span above which a meal break is assumed" — DOLE's rule is 5 hours. `scheduledMinutes` is the *net paid length* (540 for the shipped template), so the break is deducted only above 540, producing a non-monotonic cliff: an employee who punches out at 17:00 (gross 540) is credited 540 minutes with zero undertime, and one who punches out at 17:01 is credited 481 with 59 minutes of undertime. Leaving earlier pays more. The policy's own unit test passes `appliesOverMinutes: 300` with the comment "applied to any span over 5 hours" — the caller is the thing that is wrong.

**Files:**
- Modify: `backend/config/hris.php`
- Modify: `backend/app/Domain/Compute/DailyComputationInput.php`
- Modify: `backend/app/Domain/Compute/DailyComputation.php:75`
- Modify: `backend/app/Actions/Compute/ComputeDailySummary.php:103-115`
- Test: `backend/tests/Unit/Domain/Compute/DailyComputationTest.php`

**Interfaces:**
- Produces: `DailyComputationInput` gains a constructor parameter `public int $mealBreakAppliesOverMinutes` positioned immediately after `public int $breakMinutes`. Task 5 adds one more parameter to the same DTO; both tasks must keep the parameter list in the order written here.

- [ ] **Step 1: Write the failing monotonicity test**

Add to `backend/tests/Unit/Domain/Compute/DailyComputationTest.php`:

```php
it('never credits more worked minutes for a shorter span', function (): void {
    $previous = -1;

    // 08:00 in, punching out every 10 minutes from 12:00 to 19:00.
    for ($out = 720; $out <= 1140; $out += 10) {
        $computed = DailyComputation::compute(new DailyComputationInput(
            punches: [480, $out],
            dayType: DayType::Ordinary,
            isRestDay: false,
            scheduledMinutes: 540,
            overtimeThresholdMinutes: 480,
            scheduledStartMinute: 480,
            breakMinutes: 60,
            mealBreakAppliesOverMinutes: 300,
            isArt82Exempt: false,
            rates: PayRatesFactory::statutory(),
            onApprovedLeave: false,
            approvedOvertimeMinutes: 0,
        ));

        expect($computed->workedMinutes)->toBeGreaterThanOrEqual($previous);
        $previous = $computed->workedMinutes;
    }
});

it('deducts the meal break above five hours, not above the scheduled day', function (): void {
    $sevenHourSpan = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 900],   // 08:00-15:00, gross 420
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 540,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        mealBreakAppliesOverMinutes: 300,
        isArt82Exempt: false,
        rates: PayRatesFactory::statutory(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 0,
    ));

    // 420 gross is over the 300-minute threshold, so the 60-minute break comes out.
    expect($sevenHourSpan->workedMinutes)->toBe(360);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='never credits more worked minutes|deducts the meal break above five hours'"
```

Expected: FAIL — `DailyComputationInput::__construct()` does not accept `mealBreakAppliesOverMinutes`.

- [ ] **Step 3: Add the statutory constant to config**

In `backend/config/hris.php`, immediately after the `'organization_name'` entry:

```php
    // The DOLE statutory time constants (Arts. 83, 87). Like `pay_floors` below, these are
    // set by the Labor Code and not by an admin, so they are config rather than columns.
    'meal_break' => [
        // Art. 83 / IRR Book III Rule I s.7: a meal period is owed after five consecutive
        // hours. The assumed-break policy deducts only above this span — NOT above the
        // scheduled day, which would make a shorter span pay more than a longer one.
        'applies_over_minutes' => 300,
    ],

    'overtime' => [
        // Art. 83: eight hours is the normal working day, and work beyond it is overtime.
        // A shift template scheduled longer than this does not move the boundary; a legally
        // compressed workweek (D.O. 02-04) would, and needs an office flag, not this value.
        'statutory_threshold_minutes' => 480,
    ],
```

- [ ] **Step 4: Add the parameter to the input DTO**

In `backend/app/Domain/Compute/DailyComputationInput.php`, add to the constructor immediately after `public int $breakMinutes,`:

```php
        public int $mealBreakAppliesOverMinutes,
```

and add to the docblock, after the `$scheduledStartMinute` entry:

```php
     * @param  int  $mealBreakAppliesOverMinutes  The gross span above which the assumed
     *   meal break is deducted — the statutory 300 (five hours), from
     *   config('hris.meal_break.applies_over_minutes'). Deliberately NOT scheduledMinutes:
     *   deducting only above the scheduled day makes worked minutes non-monotonic in the
     *   out-punch, so leaving earlier can pay more than staying.
```

- [ ] **Step 5: Use it in the calculator**

In `backend/app/Domain/Compute/DailyComputation.php`, replace line 75:

```php
        $net = MealBreakPolicy::assumed($in->breakMinutes, $in->scheduledMinutes)->netWorked($grossTotal);
```

with:

```php
        $net = MealBreakPolicy::assumed($in->breakMinutes, $in->mealBreakAppliesOverMinutes)->netWorked($grossTotal);
```

- [ ] **Step 6: Pass it from the action**

In `backend/app/Actions/Compute/ComputeDailySummary.php`, in the `DailyComputation::compute(new DailyComputationInput(...))` call, add immediately after `breakMinutes: $schedule->breakMinutes ?? 0,`:

```php
            mealBreakAppliesOverMinutes: (int) config('hris.meal_break.applies_over_minutes'),
```

- [ ] **Step 7: Run the new tests**

```bash
make test-backend ARGS="--filter='never credits more worked minutes|deducts the meal break above five hours'"
```

Expected: PASS.

- [ ] **Step 8: Run the full compute suite and repair the fixtures it breaks**

```bash
make test-backend ARGS="--filter=Compute"
```

Every existing `DailyComputationInput` construction now fails to compile until it carries the new parameter. Add `mealBreakAppliesOverMinutes: 300,` to each. Several assertions will also move — a case with a 540 gross span previously kept all 540 and now keeps 480. **Recompute each expectation by hand against the DOLE rule; do not adjust an assertion to match whatever the code now prints.** For each changed expectation, leave a one-line comment naming the gross span and why the new figure is right.

- [ ] **Step 9: Run both suites**

```bash
make test-backend
make test-web
```

Expected: backend fully green; frontend green except the one pre-existing `attendance.test.tsx` red named in the Global Constraints.

- [ ] **Step 10: Commit**

```bash
git add backend/config/hris.php backend/app/Domain/Compute backend/app/Actions/Compute backend/tests
git commit -m "compute: deduct the meal break above the statutory five hours, not the scheduled day

MealBreakPolicy's appliesOverMinutes is 'the span above which a break is
assumed' — DOLE sets that at five hours. The caller passed scheduledMinutes
(540 for the shipped template), so the break came out only above 540 and
worked minutes were non-monotonic in the out-punch: 17:00 credited 540 with
no undertime, 17:01 credited 481 with 59. Leaving earlier paid more."
```

---

### Task 2: Start overtime at the statutory eight hours

**The defect.** `ComputeDailySummary.php:101` sets the regular↔overtime boundary to `$schedule->scheduledMinutes` on any working day. The shipped demo template resolves to 540, so an employee's ninth hour is priced as regular time at 100% instead of overtime at 125%. Art. 83 fixes the normal working day at eight hours regardless of what a shift template says. See **Stated assumption 1** — this task caps at 480 and does not implement the compressed-workweek exception.

**Files:**
- Modify: `backend/app/Actions/Compute/ComputeDailySummary.php:97-101`
- Test: `backend/tests/Feature/Compute/ComputeDailySummaryTest.php`

**Interfaces:**
- Consumes: `config('hris.overtime.statutory_threshold_minutes')`, added by Task 1 Step 3.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Compute/ComputeDailySummaryTest.php`. Follow the fixture-construction style already used by the neighbouring tests in that file for building an employee with a shift template and a pay rule:

```php
it('prices the ninth hour of a nine-hour scheduled day as overtime', function (): void {
    // Shift template 08:00-18:00 with a 60-minute break resolves to 540 scheduled
    // minutes. Art. 83 still puts the overtime boundary at 480, not 540.
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1080, breakMinutes: 60);
    $this->punchPair($employee, '2026-03-02', inMinute: 480, outMinute: 1080);

    $summary = (new ComputeDailySummary)->execute($employee, '2026-03-02');

    $lines = $summary->lines->keyBy(fn ($line) => $line->kind->value);

    expect($lines->has('overtime_day'))->toBeTrue()
        ->and($lines['regular_day']->minutes)->toBe(480)
        ->and($lines['overtime_day']->minutes)->toBe(60);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='prices the ninth hour'"
```

Expected: FAIL — `regular_day` is 540 and no `overtime_day` line exists.

- [ ] **Step 3: Cap the threshold**

In `backend/app/Actions/Compute/ComputeDailySummary.php`, replace lines 97-101:

```php
        // The regular/overtime boundary is the actual scheduled length on a working day,
        // but the statutory 8h (480) on a rest day (scheduledMinutes 0) — a rest-day
        // worker's first 8 hours are still rest-day-worked BASE, not overtime. Zero would
        // put the boundary at the start of the day and mis-price every worked minute as OT.
        $overtimeThresholdMinutes = $schedule->scheduledMinutes > 0 ? $schedule->scheduledMinutes : 480;
```

with:

```php
        // The regular/overtime boundary is the statutory 8h (Art. 83), and a shift template
        // scheduled LONGER than that does not move it — a 540-minute template would
        // otherwise price the ninth hour at 100% instead of 125%. A template scheduled
        // SHORTER does move it down: a 240-minute half-day shift is not overtime at 241.
        // A rest day (scheduledMinutes 0) takes the statutory boundary too, so a rest-day
        // worker's first 8 hours stay rest-day-worked BASE rather than overtime; zero would
        // put the boundary at the start of the day and mis-price every worked minute.
        //
        // A legally compressed workweek (D.O. 02-04, e.g. 4x10) genuinely does begin
        // overtime at 600 — that needs an offices.is_compressed_workweek flag, not a change
        // here. See docs/06-roadmap.md's Deferred table.
        $statutory = (int) config('hris.overtime.statutory_threshold_minutes');

        $overtimeThresholdMinutes = $schedule->scheduledMinutes > 0
            ? min($schedule->scheduledMinutes, $statutory)
            : $statutory;
```

- [ ] **Step 4: Run the new test**

```bash
make test-backend ARGS="--filter='prices the ninth hour'"
```

Expected: PASS.

- [ ] **Step 5: Run the compute and cutoff suites**

```bash
make test-backend ARGS="--filter='Compute|Cutoff|Payroll'"
```

Any fixture whose template exceeds 480 now produces an overtime line it did not before. Recompute each expectation against Art. 83 by hand.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Actions/Compute backend/tests
git commit -m "compute: begin overtime at the statutory eight hours

The boundary was the resolved scheduled length, so the 540-minute demo
template priced a ninth hour at 100% rather than 125%. Art. 83 fixes the
normal working day at eight hours whatever a shift template says. A shorter
template still moves the boundary down; a compressed workweek needs an office
flag and is deferred."
```

---

### Task 3: Recompute the day a post-midnight punch actually closes

**The defect.** `RecordPunch.php:72` derives the compute date from the punch's own office-local date. `EffectivePunches::forDate` assigns a post-midnight out-punch to the *previous* date's shift window. So on a 22:00→06:00 shift the in-punch computes day N (one punch, unpaired, `is_incomplete = true`, `worked_minutes = 0`) and the out-punch computes day N+1, whose window correctly excludes it. **Day N is never recomputed by the punch that completes it.** Because `CloseCutoff` refuses to close over any incomplete in-period day, an office running night shifts can never close a cutoff at all. `ApplyAttendanceAdjustment` already handles the two-date case for an amend; the plain punch path was missed.

The fix is to recompute both candidate dates. `ComputeDailySummary` is idempotent by construction (it deletes and re-inserts under the employee row lock), so computing a day that turns out to own none of this punch's minutes is a no-op that writes the same row back.

**Files:**
- Modify: `backend/app/Actions/Attendance/RecordPunch.php:70-86`
- Test: `backend/tests/Feature/Attendance/RecordPunchTest.php`

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Attendance/RecordPunchTest.php`:

```php
it('completes the previous day when a night shift punches out after midnight', function (): void {
    // 22:00-06:00 template. Asia/Manila.
    $employee = $this->employeeOnTemplate(startMinute: 1320, endMinute: 1800, breakMinutes: 0);

    $this->recordPunch($employee, '2026-03-02T14:00:00Z', 'in');   // 22:00 Manila, 03-02
    $this->recordPunch($employee, '2026-03-02T22:00:00Z', 'out');  // 06:00 Manila, 03-03

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-03-02')
        ->firstOrFail();

    expect($summary->is_incomplete)->toBeFalse()
        ->and($summary->worked_minutes)->toBe(480);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='completes the previous day'"
```

Expected: FAIL — `is_incomplete` is `true` and `worked_minutes` is 0. The 03-02 summary was written by the in-punch and never revisited.

- [ ] **Step 3: Recompute both candidate dates**

In `backend/app/Actions/Attendance/RecordPunch.php`, replace lines 70-86:

```php
            // ->copy() so the timezone conversion below never mutates $log->punched_at
            // itself — callers (and existing tests) read that attribute back as UTC.
            $date = $log->punched_at->copy()->setTimezone($office->timezone)->format('Y-m-d');

            DB::afterCommit(function () use ($employee, $date): void {
                try {
                    $this->computeDailySummary->execute($employee, $date);
                } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate $e) {
```

with:

```php
            // ->copy() so the timezone conversion below never mutates $log->punched_at
            // itself — callers (and existing tests) read that attribute back as UTC.
            $local = $log->punched_at->copy()->setTimezone($office->timezone);

            // TWO dates, not one. EffectivePunches::forDate assigns a post-midnight punch to
            // the PREVIOUS business day's shift window (that is what makes a 22:00-06:00
            // shift one day rather than two halves), so a punch's own local date is not
            // necessarily the day it belongs to. Computing only the own-date left every
            // night shift's first day permanently unpaired and incomplete — and because
            // CloseCutoff refuses to close over an incomplete day, an office running night
            // shifts could never close a cutoff at all.
            //
            // ComputeDailySummary is idempotent under the employee row lock, so recomputing
            // a day this punch turns out not to touch rewrites the identical row. Cheaper
            // than teaching this action the shift-window rule a second time — the window
            // logic stays in EffectivePunches, where ApplyAttendanceAdjustment also reads it.
            $dates = [
                $local->copy()->subDay()->format('Y-m-d'),
                $local->format('Y-m-d'),
            ];

            DB::afterCommit(function () use ($employee, $dates): void {
                foreach ($dates as $date) {
                    try {
                        $this->computeDailySummary->execute($employee, $date);
                    } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate $e) {
```

Then close the new `foreach` — the existing `Log::info` body and its closing braces stay as they are, indented one level deeper, with `'date' => $date` still resolving correctly inside the loop.

- [ ] **Step 4: Run the new test**

```bash
make test-backend ARGS="--filter='completes the previous day'"
```

Expected: PASS.

- [ ] **Step 5: Prove the extra compute cannot write into a closed period**

Add to the same file:

```php
it('does not resurrect a closed previous day when a punch lands after midnight', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);
    $this->closeCutoffCovering($employee, '2026-03-02');

    $this->recordPunch($employee, '2026-03-02T16:30:00Z', 'in'); // 00:30 Manila, 03-03

    expect(DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-03-02')
        ->where('status', 'locked')
        ->exists())->toBeTrue();
});
```

`ComputeDailySummary`'s existing closed-period and `status === 'locked'` guards should make this pass with no further change. If it fails, that is a real regression in this task — fix it here, not later.

- [ ] **Step 6: Run the attendance and cutoff suites**

```bash
make test-backend ARGS="--filter='Attendance|Cutoff|Punch'"
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Actions/Attendance backend/tests
git commit -m "attendance: recompute both days a punch can belong to

EffectivePunches assigns a post-midnight punch to the previous business day's
shift window, but RecordPunch computed only the punch's own local date. A
22:00-06:00 shift therefore left day N unpaired and incomplete forever, and
CloseCutoff refuses to close over an incomplete day — so an office running
night shifts could never close a cutoff. Compute is idempotent, so the extra
date is a no-op when it owns none of the punch."
```

---

### Task 4: Stop a same-minute punch from bricking the day

**The defect.** `EffectivePunches.php:133` truncates every punch to a whole minute; `PunchPairer::assertOrdered` throws `InvalidArgumentException` on `minute <= previous`. Nothing in ingestion prevents two punches landing in the same minute — a double-tap, two open tabs, or an HR admin entering `08:00` twice through the deliberately non-idempotent manual-punch route. The punch commits durably (the compute runs in `DB::afterCommit`, outside the transaction), then the compute throws, `RecordPunch` catches only the two schedule exceptions, and it escapes as a 500. Every later compute for that day throws identically, so **no summary row is ever written** — which means `CloseCutoff`'s incomplete-day gate cannot see the day at all, and the period closes with it worth zero.

Two changes, because either alone is insufficient: reject the duplicate at ingestion so it stops happening, and make the compute path survive the rows already in the database.

**Files:**
- Create: `backend/app/Domain/Attendance/PunchOrdering.php`
- Create: `backend/app/Exceptions/Domain/DuplicatePunchMinute.php`
- Modify: `backend/app/Actions/Attendance/RecordPunch.php`
- Modify: `backend/app/Domain/Time/PunchPairer.php:77`
- Test: `backend/tests/Feature/Attendance/RecordPunchTest.php`, `backend/tests/Unit/Domain/Time/PunchPairerTest.php`

**Interfaces:**
- Produces: `PunchOrdering::assertOrderable(Employee $employee, CarbonInterface $punchedAt, string $timezone): void` — throws `DuplicatePunchMinute` when the employee already has a punch in that office-local minute.

- [ ] **Step 1: Write the failing pairer test**

Add to `backend/tests/Unit/Domain/Time/PunchPairerTest.php`:

```php
it('reports an incomplete day rather than throwing on a duplicate minute', function (): void {
    $paired = PunchPairer::pair([480, 480, 1020]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->intervals)->toBe([]);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='reports an incomplete day rather than throwing'"
```

Expected: FAIL with `InvalidArgumentException: Punches must be in ascending order: 480 is followed by 480.`

- [ ] **Step 3: Make the pairer degrade instead of throwing**

In `backend/app/Domain/Time/PunchPairer.php`, find `assertOrdered` and replace the `throw` with a flag the caller reads. Change the method to return `bool` — `false` when the sequence is not strictly ascending — and have `pair()` return the same shape it already returns for an odd punch count (no intervals, `hasUnpaired() === true`) when it gets `false`. Add above the method:

```php
    /**
     * Strictly ascending is required: EffectivePunches truncates each punch to a whole
     * minute, so two punches inside one minute collide here.
     *
     * This used to throw. It must not: the punch is already durable when the compute runs
     * (DB::afterCommit), so throwing meant no summary row was ever written for that day —
     * and a day with no summary row is invisible to CloseCutoff's incomplete-day gate,
     * so the period closed with the day worth zero. Degrading to "incomplete" instead puts
     * the day in front of the gate, where an HR admin resolves it through the adjustment
     * flow like any other broken day. RecordPunch now also refuses the duplicate at
     * ingestion (PunchOrdering), so this path only ever sees rows written before that.
     */
```

- [ ] **Step 4: Run the pairer test**

```bash
make test-backend ARGS="--filter=PunchPairer"
```

Expected: PASS, including the existing ordering tests — update any that assert the throw to assert the incomplete result instead, and leave a comment naming this task.

- [ ] **Step 5: Write the failing ingestion test**

Add to `backend/tests/Feature/Attendance/RecordPunchTest.php`:

```php
it('refuses a second punch in the same office-local minute', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);

    $this->recordPunch($employee, '2026-03-02T00:00:10Z', 'in');

    expect(fn () => $this->recordPunch($employee, '2026-03-02T00:00:50Z', 'out'))
        ->toThrow(DuplicatePunchMinute::class);

    expect(AttendanceLog::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
```

- [ ] **Step 6: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='refuses a second punch in the same'"
```

Expected: FAIL — `DuplicatePunchMinute` does not exist.

- [ ] **Step 7: Add the exception**

Create `backend/app/Exceptions/Domain/DuplicatePunchMinute.php`, matching the shape of the neighbouring domain exceptions in that directory (they each map to an HTTP status through the envelope — use the same status the other conflict-style exceptions use, 409):

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class DuplicatePunchMinute extends DomainException
{
    public function __construct(string $localMinute)
    {
        parent::__construct("A punch already exists for this employee at {$localMinute}.");
    }
}
```

- [ ] **Step 8: Add the ordering guard**

Create `backend/app/Domain/Attendance/PunchOrdering.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Exceptions\Domain\DuplicatePunchMinute;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Refuses a punch that would land in an office-local minute this employee already has a
 * punch in.
 *
 * Whole minutes, not seconds, because EffectivePunches truncates to the minute before
 * pairing — two punches 40 seconds apart are indistinguishable downstream and collide in
 * PunchPairer. Rejecting here keeps the ledger append-only (nothing is edited or removed;
 * the second write simply never happens) while stopping the collision at the only place
 * that can still return an error to a caller.
 *
 * Run inside RecordPunch's transaction, under the employee row lock, so two concurrent
 * punches serialize rather than both passing the check.
 */
final class PunchOrdering
{
    private function __construct() {}

    public static function assertOrderable(Employee $employee, CarbonInterface $punchedAt, string $timezone): void
    {
        $local = $punchedAt->copy()->setTimezone($timezone);
        $minuteStart = $local->copy()->startOfMinute();
        $minuteEnd = $minuteStart->copy()->addMinute();

        $exists = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $minuteStart->utc())
            ->where('punched_at', '<', $minuteEnd->utc())
            ->exists();

        if ($exists) {
            throw new DuplicatePunchMinute($local->format('Y-m-d H:i'));
        }
    }
}
```

- [ ] **Step 9: Call it from RecordPunch**

In `backend/app/Actions/Attendance/RecordPunch.php`, inside the transaction, replace:

```php
            $employee = Employee::query()->findOrFail($in->employeeId);
```

with:

```php
            // lockForUpdate so two concurrent punches for the same employee serialize
            // through PunchOrdering below rather than both finding the minute free.
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);
```

and add immediately after the `$office` line and before `$this->verifier->verify(...)`:

```php
            PunchOrdering::assertOrderable($employee, $in->punchedAt ?? now(), $office->timezone);
```

- [ ] **Step 10: Run the ingestion test**

```bash
make test-backend ARGS="--filter='refuses a second punch in the same'"
```

Expected: PASS.

- [ ] **Step 11: Run both suites**

```bash
make test-backend
make test-web
```

- [ ] **Step 12: Commit**

```bash
git add backend/app backend/tests
git commit -m "attendance: refuse a duplicate punch minute, and stop it bricking the day

EffectivePunches truncates to the minute, so two punches inside one minute
collided in PunchPairer, which threw. The punch is durable by then (compute
runs in afterCommit), so the day got no summary row at all — invisible to
CloseCutoff's incomplete gate, and the period closed with it worth zero.

Rejects the duplicate at ingestion under the employee lock, and degrades the
pairer to 'incomplete' so rows already written land in front of the gate."
```

---

### Task 5: Make half-day leave pay what it debits, on punched and unpunched days alike

**The defect.** Three faults, one root:

- `LeaveDayLookup::isOnApprovedLeave` returns a bare boolean. `leave_details.day_part` is written at submit and read by nothing in the compute path, so a half-day is priced as a full day. Employee takes a half-day and does not punch: ledger debited 240, payroll pays 480.
- `DailyComputation` emits `leave_with_pay` only on the no-punches path. Employee takes a half-day and works the other half: punches exist, `computeUnworkedDay` is never reached, **no leave line is emitted at all** — debited 240 and paid only for the hours worked. This one disadvantages the employee and is the likelier case.
- The debit reads `offices.minutes_per_leave_day` while the pay line reads the resolved `scheduledMinutes`. A 4×10 employee debits 480 and is paid 600 — 120 free minutes on every leave day, `full` included.

Per **Stated assumption 2**, the fix snapshots one number onto `leave_details` at submit and has both sides read it.

**Files:**
- Create: `backend/database/migrations/2026_08_02_000001_add_minutes_per_day_to_leave_details.php`
- Modify: `backend/app/Actions/Leave/SubmitLeaveRequest.php:47`
- Modify: `backend/app/Domain/Leave/LeaveDayLookup.php`
- Modify: `backend/app/Domain/Compute/DailyComputationInput.php`
- Modify: `backend/app/Domain/Compute/DailyComputation.php`
- Modify: `backend/app/Actions/Compute/ComputeDailySummary.php:86`
- Modify: `backend/app/Domain/Leave/LeaveEffect.php`
- Test: `backend/tests/Unit/Domain/Compute/DailyComputationTest.php`, `backend/tests/Feature/Leave/LeaveEffectTest.php`

**Interfaces:**
- Consumes: `DailyComputationInput`'s parameter order as left by Task 1.
- Produces: `LeaveDayLookup::paidMinutesFor(Employee $employee, string $date): int` — 0 when no approved paid leave covers the date. Replaces `isOnApprovedLeave`, which is deleted.
- Produces: `DailyComputationInput` gains `public int $leaveMinutes` in place of `public bool $onApprovedLeave`, in the same position.

- [ ] **Step 1: Write the failing calculator tests**

Add to `backend/tests/Unit/Domain/Compute/DailyComputationTest.php`:

```php
it('pays a half-day leave for half a day', function (): void {
    $computed = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        mealBreakAppliesOverMinutes: 300,
        isArt82Exempt: false,
        rates: PayRatesFactory::statutory(),
        leaveMinutes: 240,
        approvedOvertimeMinutes: 0,
    ));

    expect($computed->lines)->toHaveCount(1)
        ->and($computed->lines[0]->kind)->toBe(SummaryLineKind::LeaveWithPay)
        ->and($computed->lines[0]->minutes)->toBe(240)
        ->and($computed->lines[0]->appliedBp)->toBe(10000);
});

it('pays both the worked half and the leave half when an employee works the other half', function (): void {
    $computed = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 720],          // 08:00-12:00, gross 240, under the 300 break threshold
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        mealBreakAppliesOverMinutes: 300,
        isArt82Exempt: false,
        rates: PayRatesFactory::statutory(),
        leaveMinutes: 240,
        approvedOvertimeMinutes: 0,
    ));

    $byKind = collect($computed->lines)->keyBy(fn ($line) => $line->kind->value);

    expect($byKind['regular_day']->minutes)->toBe(240)
        ->and($byKind['leave_with_pay']->minutes)->toBe(240)
        ->and($computed->undertimeMinutes)->toBe(0);
});

it('never pays leave and worked time beyond the scheduled day', function (): void {
    // Full-day leave, but the employee came in and worked anyway. The leave line yields to
    // the worked time rather than stacking on top of it.
    $computed = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        mealBreakAppliesOverMinutes: 300,
        isArt82Exempt: false,
        rates: PayRatesFactory::statutory(),
        leaveMinutes: 480,
        approvedOvertimeMinutes: 0,
    ));

    $leave = collect($computed->lines)->firstWhere(fn ($line) => $line->kind === SummaryLineKind::LeaveWithPay);

    expect($computed->workedMinutes)->toBe(480)
        ->and($leave)->toBeNull();
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
make test-backend ARGS="--filter='half-day leave|works the other half|beyond the scheduled day'"
```

Expected: FAIL — `DailyComputationInput` has no `leaveMinutes`.

- [ ] **Step 3: Snapshot the per-day minutes onto the leave detail**

Create `backend/database/migrations/2026_08_02_000001_add_minutes_per_day_to_leave_details.php`:

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
        Schema::table('leave_details', function (Blueprint $table): void {
            // Snapshotted at submit, never recomputed. Both the ledger debit (LeaveEffect)
            // and the payroll line (DailyComputation) read THIS number, so the two can
            // never drift — previously the debit read offices.minutes_per_leave_day and the
            // pay line read the employee's resolved scheduledMinutes, and a 4x10 employee
            // debited 480 while being paid 600. A snapshot rather than a live read because
            // an admin changing minutes_per_leave_day must not restate an approved leave.
            $table->integer('minutes_per_day')->nullable();
        });

        // Backfill: half the office value for a half-day, the office value for a full day.
        DB::statement(<<<'SQL'
            UPDATE leave_details ld
            SET minutes_per_day = CASE
                WHEN ld.day_part = 'half' THEN o.minutes_per_leave_day / 2
                ELSE o.minutes_per_leave_day
            END
            FROM requests r
            JOIN employees e ON e.id = r.employee_id
            JOIN offices o ON o.id = e.current_office_id
            WHERE ld.request_id = r.id
        SQL);

        DB::statement('ALTER TABLE leave_details ALTER COLUMN minutes_per_day SET NOT NULL');
        DB::statement('ALTER TABLE leave_details ADD CONSTRAINT leave_details_minutes_per_day_check CHECK (minutes_per_day > 0)');
    }

    public function down(): void
    {
        Schema::table('leave_details', function (Blueprint $table): void {
            $table->dropColumn('minutes_per_day');
        });
    }
};
```

- [ ] **Step 4: Write it at submit**

In `backend/app/Actions/Leave/SubmitLeaveRequest.php`, alongside the existing `'day_part' => $in->dayPart,` in the `leave_details` create, add:

```php
                // Snapshot, not a live read: an admin lowering the office's
                // minutes_per_leave_day must not restate leave already filed.
                'minutes_per_day' => $in->dayPart === 'half'
                    ? intdiv($office->minutes_per_leave_day, 2)
                    : $office->minutes_per_leave_day,
```

Resolve `$office` from the employee's current office at the top of the same method if it is not already in scope there.

- [ ] **Step 5: Return minutes from the lookup**

Replace the body of `backend/app/Domain/Leave/LeaveDayLookup.php`'s method, renaming it:

```php
    /**
     * The paid-leave minutes attributable to this date — 0 when no approved, paid leave
     * covers it.
     *
     * Was a bare boolean, which made a half-day pay like a full day: day_part was written
     * at submit and read by nothing downstream. Reads leave_details.minutes_per_day, the
     * same snapshot LeaveEffect debits the balance by, so pay and debit cannot disagree.
     */
    public static function paidMinutesFor(Employee $employee, string $date): int
    {
        return (int) Request::query()
            ->where('employee_id', $employee->id)
            ->where('type', RequestType::Leave)
            ->where('state', RequestState::Approved)
            ->whereHas('leaveDetail', function ($query) use ($date): void {
                $query->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->whereHas('leaveType', function ($query): void {
                        $query->where('is_paid', true);
                    });
            })
            ->join('leave_details', 'leave_details.request_id', '=', 'requests.id')
            ->sum('leave_details.minutes_per_day');
    }
```

Keep the existing class docblock, amending its first paragraph to describe minutes rather than a boolean.

- [ ] **Step 6: Swap the DTO field**

In `backend/app/Domain/Compute/DailyComputationInput.php`, replace `public bool $onApprovedLeave,` with `public int $leaveMinutes,` and replace its docblock entry with:

```php
     * @param  int  $leaveMinutes  Paid-leave minutes attributable to this date
     *   (LeaveDayLookup::paidMinutesFor, resolved by the caller — this class stays pure).
     *   0 when none. Consulted on BOTH the punched and unpunched paths: a half-day leave
     *   whose other half is worked must pay for both, and the leave portion is capped so
     *   leave + worked never exceeds the scheduled day.
```

- [ ] **Step 7: Emit the leave line on both paths**

In `backend/app/Domain/Compute/DailyComputation.php`:

In `compute()`, after `$lines = self::buildLines(...)` and before the `$firstPunch` line, insert:

```php
        // Leave can coexist with worked time — a half-day leave whose other half is worked
        // pays for both. Capped at the unworked remainder of the scheduled day so a
        // full-day leave the employee ignored and worked through pays once, not twice.
        $leaveCredit = min($in->leaveMinutes, max(0, $in->scheduledMinutes - $net->value));

        if ($leaveCredit > 0 && ! $in->isRestDay) {
            $lines[] = new ComputedLine(
                kind: SummaryLineKind::LeaveWithPay,
                minutes: $leaveCredit,
                appliedBp: 10000,
            );
        }
```

and change the `$undertime` line immediately below it to credit the leave:

```php
        $undertime = OvertimeThreshold::undertime(
            Minutes::of($net->value + $leaveCredit),
            Minutes::of($in->scheduledMinutes),
        )->value;
```

In `computeUnworkedDay()`, the order matters — `$leaveCredit` must be defined **before** `$undertime`, which is currently the method's first statement. Replace the method's opening two lines:

```php
        $undertime = OvertimeThreshold::undertime(Minutes::zero(), Minutes::of($in->scheduledMinutes))->value;

        $lines = [];
```

with:

```php
        $leaveCredit = min($in->leaveMinutes, $in->scheduledMinutes);

        // A day fully covered by leave is not undertime — the employee owes no hours.
        // Previously this passed Minutes::zero() unconditionally, so a full-day leave
        // recorded a full day of undertime alongside its own leave_with_pay line.
        $undertime = OvertimeThreshold::undertime(
            Minutes::of($leaveCredit),
            Minutes::of($in->scheduledMinutes),
        )->value;

        $lines = [];
```

Then replace the leave branch's condition:

```php
        if ($in->onApprovedLeave && ! $in->isRestDay && $in->scheduledMinutes > 0) {
```

with:

```php
        if ($leaveCredit > 0 && ! $in->isRestDay) {
```

and change the `minutes:` argument inside it from `$in->scheduledMinutes` to `$leaveCredit`. Keep the existing comment block explaining why leave wins over a holiday and why it is not gated on `isArt82Exempt`; append one sentence naming the half-day case.

- [ ] **Step 8: Pass minutes from the action**

In `backend/app/Actions/Compute/ComputeDailySummary.php`, replace line 86:

```php
        $onApprovedLeave = LeaveDayLookup::isOnApprovedLeave($employee, $date);
```

with:

```php
        $leaveMinutes = LeaveDayLookup::paidMinutesFor($employee, $date);
```

and the corresponding named argument in the `DailyComputationInput` construction from `onApprovedLeave: $onApprovedLeave,` to `leaveMinutes: $leaveMinutes,`.

- [ ] **Step 9: Debit the same number**

In `backend/app/Domain/Leave/LeaveEffect.php`, change the debit quantity to read `leave_details.minutes_per_day` for each covered date instead of `offices.minutes_per_leave_day`. Add above it:

```php
        // The SAME snapshot DailyComputation prices the day from — see
        // leave_details.minutes_per_day. Reading the office value here and the resolved
        // schedule there is what let a 4x10 employee debit 480 and be paid 600.
```

- [ ] **Step 10: Run the calculator tests**

```bash
make test-backend ARGS="--filter='half-day leave|works the other half|beyond the scheduled day'"
```

Expected: PASS.

- [ ] **Step 11: Write the failing end-to-end test**

Add to `backend/tests/Feature/Leave/LeaveEffectTest.php`:

```php
it('debits exactly what the payroll line pays for a half-day leave', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);
    $request = $this->approveHalfDayLeave($employee, '2026-03-02');

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-03-02')
        ->firstOrFail();

    $paid = $summary->lines->firstWhere(fn ($line) => $line->kind === SummaryLineKind::LeaveWithPay)->minutes;
    $debited = abs((int) $request->employee->leaveLedgerEntries()->sum('minutes'));

    expect($paid)->toBe($debited);
});
```

- [ ] **Step 12: Run it**

```bash
make test-backend ARGS="--filter='debits exactly what the payroll line pays'"
```

Expected: PASS. If the ledger accessor names differ from the sketch above, match the names already used by the neighbouring tests in that file rather than inventing new ones.

- [ ] **Step 13: Run both suites**

```bash
make test-backend
make test-web
```

Every existing `DailyComputationInput` construction needs `onApprovedLeave: false` changed to `leaveMinutes: 0`, or to the real minute count where the fixture meant a leave day.

- [ ] **Step 14: Commit**

```bash
git add backend/database backend/app backend/tests
git commit -m "leave: pay what the ledger debits, and pay a half-day as a half-day

day_part was written at submit and read by nothing: a half-day paid like a
full day. And leave_with_pay was emitted only on the no-punches path, so an
employee who worked the other half was debited 240 and paid for the hours
worked alone. Separately the debit read offices.minutes_per_leave_day while
the pay line read the resolved schedule, so a 4x10 employee debited 480 and
was paid 600.

leave_details.minutes_per_day is now snapshotted at submit and is the single
number both sides read."
```

---

### Task 6: Materialize a summary for every employee-day before freezing a period

**The defect.** `AffectedSummaries` queries *existing* summary rows by design, `CloseCutoff` freezes only existing rows, and `PayrollExport` reads only existing rows. The only three things that ever *create* a summary are a punch, an adjustment, and `LeaveEffect`'s date enumeration. So `computeUnworkedDay`'s `holiday_unworked` line — a non-exempt employee's statutory Art. 94 100% for an unworked regular holiday, real money owed — is unreachable in production. An employee who works Dec 29 and Dec 31 but not the Dec 30 regular holiday gets no Dec 30 summary at all, and `CreateHoliday`'s own recompute is a no-op because there is nothing yet to recompute. Confirmed against the dev database: the one `regular_holiday` row has zero summaries, and `daily_summary_lines` contains only `regular_day` and `regular_night` company-wide. An employee absent for a whole period does not appear in the payroll export at all.

The fix belongs in `CloseCutoff`, because that is the one moment the system knows the full employee × date grid that payroll is about to be run over.

**Files:**
- Modify: `backend/app/Actions/Cutoff/CloseCutoff.php`
- Test: `backend/tests/Feature/Cutoff/CloseCutoffTest.php`

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Cutoff/CloseCutoffTest.php`:

```php
it('pays an unworked regular holiday to an employee who never punched that day', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);
    $this->holiday($employee->current_office_id, '2026-03-09', DayType::RegularHoliday);
    $this->punchPair($employee, '2026-03-10', inMinute: 480, outMinute: 1020);

    (new CloseCutoff)->execute(new CloseCutoffInput(
        officeId: $employee->current_office_id,
        periodStart: '2026-03-01',
        actorId: $this->systemAdmin->id,
    ));

    $summary = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-03-09')
        ->firstOrFail();

    expect($summary->status)->toBe('locked')
        ->and($summary->lines->firstWhere(fn ($line) => $line->kind === SummaryLineKind::HolidayUnworked)->minutes)
        ->toBe(480);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='pays an unworked regular holiday'"
```

Expected: FAIL — no summary row exists for 2026-03-09.

- [ ] **Step 3: Materialize before the exception gate**

In `backend/app/Actions/Cutoff/CloseCutoff.php`, inside the transaction and immediately **before** the `// --- Strict exception gate ---` block, insert:

```php
            // Materialize every employee-day in the period before gating or freezing.
            //
            // A summary row is only ever created by a punch, an adjustment, or LeaveEffect,
            // so a day nobody punched has no row — which made three things unreachable in
            // production: an unworked regular holiday's statutory Art. 94 pay, a plain
            // absence, and a leave-only day for anyone LeaveEffect had not enumerated. An
            // employee absent for a whole period did not appear in the payroll export at
            // all. Closing the period is the one moment the full employee x date grid is
            // known, so it is where the grid gets filled.
            //
            // ComputeDailySummary is idempotent and skips anything already `locked` or
            // inside a closed period, so this rewrites the identical row for every day that
            // already had one. It runs BEFORE the exception gate deliberately: a day
            // materialized as incomplete must be caught by the gate, not silently frozen.
            $employees = Employee::query()
                ->where('current_office_id', $in->officeId)
                ->orderBy('id')
                ->get();

            $compute = app(ComputeDailySummary::class);

            foreach ($employees as $employee) {
                for (
                    $date = CarbonImmutable::parse($window['start']);
                    $date->lessThanOrEqualTo(CarbonImmutable::parse($window['end']));
                    $date = $date->addDay()
                ) {
                    try {
                        $compute->execute($employee, $date->format('Y-m-d'));
                    } catch (EmployeeHasNoOffice|OfficeHasNoDefaultTemplate) {
                        // Same tolerance RecordPunch and ApplyAttendanceAdjustment already
                        // apply: an employee with no resolvable schedule for a date has no
                        // day to compute, and that is not a reason to refuse the close.
                    }
                }
            }
```

Add the four imports the block needs (`CarbonImmutable`, `ComputeDailySummary`, and the two schedule exceptions) to the file's `use` list.

- [ ] **Step 4: Run the new test**

```bash
make test-backend ARGS="--filter='pays an unworked regular holiday'"
```

Expected: PASS.

- [ ] **Step 5: Prove an absent employee reaches payroll**

Add to the same file:

```php
it('includes an employee who was absent all period in the export', function (): void {
    $absent = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);

    (new CloseCutoff)->execute(new CloseCutoffInput(
        officeId: $absent->current_office_id,
        periodStart: '2026-03-01',
        actorId: $this->systemAdmin->id,
    ));

    expect(DailyAttendanceSummary::query()->where('employee_id', $absent->id)->count())->toBe(15);
});
```

- [ ] **Step 6: Run the cutoff and payroll suites**

```bash
make test-backend ARGS="--filter='Cutoff|Payroll'"
```

Some existing close tests will now refuse to close because a day the fixture never punched materializes as an absence — that is correct behaviour, not a regression. Where a test's intent was "this period closes cleanly", give its employee punches or leave for the period; where the intent was the gate, assert the new refusal.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Actions/Cutoff backend/tests
git commit -m "cutoff: materialize every employee-day before freezing the period

Summaries were only ever created by a punch, an adjustment, or LeaveEffect, so
a day nobody punched had no row at all. That made an unworked regular holiday's
statutory Art. 94 pay unreachable in production, dropped plain absences, and
left an employee absent for a whole period out of the payroll export entirely.
Closing is the one moment the full employee x date grid is known."
```

---

### Task 7: Refuse a second approved leave that overlaps an existing one

**The defect.** `leave_details` carries only `PRIMARY KEY (request_id)`; `pg_constraint` confirms the schema has zero exclusion constraints anywhere. Neither `SubmitLeaveRequest` nor `ApproveRequest` checks for overlap, and `LeaveEffect` checks only that the balance suffices. Two overlapping 5-day requests both reach final approval: `LeaveEffect` writes two ledger debits, while the compute path emits one `leave_with_pay` line per day. The employee is charged twice and paid once. `OvertimeAuthorizationLookup` has the mirror gap — it `SUM`s across all approved requests for a date, so two approvals silently double the authorized overtime cap with nothing bounding it.

The check goes in the approval path, under the employee row lock `ApproveRequest` already holds, which is what makes it race-safe without a schema change.

**Files:**
- Create: `backend/app/Domain/Leave/LeaveOverlap.php`
- Create: `backend/app/Exceptions/Domain/OverlappingLeave.php`
- Modify: `backend/app/Domain/Leave/LeaveEffect.php`
- Test: `backend/tests/Feature/Leave/LeaveEffectTest.php`, `backend/tests/Feature/Leave/LeaveEffectConcurrencyTest.php`

**Interfaces:**
- Produces: `LeaveOverlap::assertNoneFor(Employee $employee, string $startDate, string $endDate, string $exceptRequestId): void` — throws `OverlappingLeave`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Leave/LeaveEffectTest.php`:

```php
it('refuses to approve leave that overlaps leave already approved', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);

    $this->approveLeave($employee, '2026-03-02', '2026-03-06');
    $second = $this->submitLeave($employee, '2026-03-04', '2026-03-10');

    expect(fn () => $this->approve($second))->toThrow(OverlappingLeave::class);

    expect(abs((int) $employee->leaveLedgerEntries()->sum('minutes')))->toBe(5 * 480);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='refuses to approve leave that overlaps'"
```

Expected: FAIL — the second approval succeeds and the ledger shows twelve days debited.

- [ ] **Step 3: Add the exception**

Create `backend/app/Exceptions/Domain/OverlappingLeave.php`, matching the shape of the neighbouring domain exceptions and using the same 409 conflict status they use:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class OverlappingLeave extends DomainException
{
    public function __construct(string $conflictingRequestId)
    {
        parent::__construct("This leave overlaps an already-approved request ({$conflictingRequestId}).");
    }
}
```

- [ ] **Step 4: Add the check**

Create `backend/app/Domain/Leave/LeaveOverlap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Leave;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Exceptions\Domain\OverlappingLeave;
use App\Models\Employee;
use App\Models\Request;

/**
 * Refuses an approval whose date span overlaps leave this employee already holds approved.
 *
 * There is no database constraint behind this: leave_details has only a primary key, and
 * the range lives across two columns on a side table, so an exclusion constraint would need
 * employee_id denormalized onto leave_details. This check instead runs inside LeaveEffect,
 * under the employee row lock ApproveRequest already takes — which is what makes two
 * concurrent approvals serialize rather than both finding the span free.
 *
 * Without it, two overlapping approved requests each wrote a ledger debit while the compute
 * path emitted one leave_with_pay line per day: charged twice, paid once.
 */
final class LeaveOverlap
{
    private function __construct() {}

    public static function assertNoneFor(
        Employee $employee,
        string $startDate,
        string $endDate,
        string $exceptRequestId,
    ): void {
        $conflict = Request::query()
            ->where('employee_id', $employee->id)
            ->where('type', RequestType::Leave)
            ->where('state', RequestState::Approved)
            ->where('id', '!=', $exceptRequestId)
            ->whereHas('leaveDetail', function ($query) use ($startDate, $endDate): void {
                $query->whereDate('start_date', '<=', $endDate)
                    ->whereDate('end_date', '>=', $startDate);
            })
            ->first();

        if ($conflict !== null) {
            throw new OverlappingLeave($conflict->id);
        }
    }
}
```

- [ ] **Step 5: Call it from LeaveEffect**

In `backend/app/Domain/Leave/LeaveEffect.php`, immediately before the existing balance-sufficiency check, add:

```php
        LeaveOverlap::assertNoneFor(
            $request->employee,
            $detail->start_date->toDateString(),
            $detail->end_date->toDateString(),
            $request->id,
        );
```

Match the local variable names already in scope in that method rather than the sketch above.

- [ ] **Step 6: Run the new test**

```bash
make test-backend ARGS="--filter='refuses to approve leave that overlaps'"
```

Expected: PASS.

- [ ] **Step 7: Add the two-process concurrency test**

`backend/tests/Feature/Leave/LeaveEffectConcurrencyTest.php` already spawns a real second process with `proc_open` and synchronizes on a `"LOCKED"` signal. Add a case in exactly that established shape: two overlapping leave requests for one employee, approved concurrently from two connections; assert that the second **blocked** on the employee row lock, that it surfaced `OverlappingLeave`, and that the ledger holds exactly one span's worth of debits. Do not add `RefreshDatabase` to that file — it is deliberately absent so the child process can see the fixtures.

- [ ] **Step 8: Run the concurrency suite**

```bash
make test-backend ARGS="--filter=Concurrency"
```

- [ ] **Step 9: Commit**

```bash
git add backend/app backend/tests
git commit -m "leave: refuse an approval overlapping leave already approved

Two overlapping requests both reached final approval, each writing a ledger
debit, while the compute path emitted one leave_with_pay line per day: charged
twice, paid once. Checked inside LeaveEffect under the employee row lock
ApproveRequest already holds, so two concurrent approvals serialize."
```

---

# Phase 2 — Security and production

---

### Task 8: Run a queue worker in production

**The defect.** `RecomputeDay implements ShouldQueue`. `config/queue.php:16` defaults `QUEUE_CONNECTION` to `database`, and nothing sets it in `.env.prod.example` or `compose.prod.yml`. There is no worker service in either compose file. So every recompute triggered by a holiday edit, a pay-rule change, or an approval queues into a `jobs` table that nothing drains. **The test suite cannot catch this**: `phpunit.xml:73,91` force `QUEUE_CONNECTION=sync`, so all 911 tests run their jobs inline. Four `scripts/e2e-*.sh` files paper over it by manually running `queue:work --stop-when-empty`.

**Files:**
- Modify: `compose.prod.yml`
- Modify: `compose.dev.yml`
- Modify: `.env.prod.example`
- Modify: `Makefile`
- Modify: `CLAUDE.md`
- Test: `scripts/e2e-prod-boot.sh`

- [ ] **Step 1: Add the worker service to production**

In `compose.prod.yml`, add a service alongside `api`, reusing the api image and its environment block verbatim (same image, same env, same volumes, no ports):

```yaml
  queue:
    # Same image and environment as `api` — this is the api container with a different
    # command. RecomputeDay implements ShouldQueue and QUEUE_CONNECTION defaults to
    # `database`, so without this service every recompute triggered by a holiday edit, a
    # pay-rule change, or an approval queued into a table nothing drained. The test suite
    # cannot catch its absence: phpunit.xml forces QUEUE_CONNECTION=sync.
    build:
      context: ./backend
      target: prod
    restart: unless-stopped
    depends_on:
      db:
        condition: service_healthy
    env_file: .env
    user: hris
    command: ["php", "artisan", "queue:work", "--tries=3", "--max-time=3600", "--sleep=1"]
    healthcheck:
      test: ["CMD", "php", "artisan", "queue:monitor", "database:default", "--max=1000"]
      interval: 60s
      timeout: 10s
      retries: 3
```

Copy the `environment:`, `volumes:`, and any `networks:` keys from the existing `api` service verbatim so the two containers resolve identical config. `--max-time=3600` recycles the worker hourly so a deployed code change is picked up without a manual restart.

- [ ] **Step 2: Add it to the dev stack too**

Add the same service to `compose.dev.yml`, pointed at the dev target and the dev bind mounts, so a developer sees the same asynchronous behaviour production has. Without it, dev runs jobs through whatever `backend/.env` says and diverges from production silently.

- [ ] **Step 3: Make the connection explicit**

In `.env.prod.example`, add under the existing app section:

```bash
# The queue the `queue` service drains. `database` needs no broker; it is the default in
# config/queue.php and is named here so it is visible rather than implicit.
QUEUE_CONNECTION=database
```

- [ ] **Step 4: Add a Makefile target**

In `Makefile`, alongside `prod-logs`:

```makefile
.PHONY: prod-queue-logs
prod-queue-logs: ## Tail the production queue worker
	docker compose -f compose.prod.yml logs -f queue
```

- [ ] **Step 5: Prove it in the boot script**

In `scripts/e2e-prod-boot.sh`, after the bootstrap-admin and sign-in steps, add an assertion that a queued job is actually drained: create a holiday through the API, then poll the `jobs` table until it is empty (30s ceiling, fail loudly on timeout), then assert the affected summary was recomputed. Delete any `queue:work --stop-when-empty` line the script currently uses to work around the missing worker, and leave a comment naming this task where it was.

- [ ] **Step 6: Boot the stack and watch a job drain**

```bash
./scripts/e2e-prod-boot.sh
```

Expected: green, including the new drain assertion.

- [ ] **Step 7: Update the docs**

In `CLAUDE.md`'s **Production** section, add to the "Things worth knowing before you change any of it" list:

```markdown
- **The `queue` service is not optional.** `RecomputeDay implements ShouldQueue` and
  `QUEUE_CONNECTION` is `database`, so without a worker every recompute after a holiday
  edit, a pay-rule change, or an approval queues into a table nothing drains — and the
  summaries silently never update. The test suite cannot catch this: `phpunit.xml` forces
  `QUEUE_CONNECTION=sync`, so all 911 tests run their jobs inline. `make prod-queue-logs`
  tails it.
```

- [ ] **Step 8: Commit**

```bash
git add compose.prod.yml compose.dev.yml .env.prod.example Makefile scripts CLAUDE.md
git commit -m "prod: run a queue worker

RecomputeDay implements ShouldQueue and QUEUE_CONNECTION defaults to database,
but no compose file ran a worker — so every recompute after a holiday edit, a
pay-rule change, or an approval queued into a table nothing drained. The suite
could not catch it: phpunit.xml forces sync, so all 911 tests run jobs inline."
```

---

### Task 9: Bound the session token and rate-limit the login

**The defect.** `config/sanctum.php` does not exist, so no expiry is configured and a token is valid forever. `LoginController.php:30` calls `$user->createToken('web')` with no abilities, which Sanctum expands to `['*']`. There is no `throttle:api` on the login route and no `SANCTUM_*` variable anywhere. A leaked token is permanent, unscoped, and the endpoint that mints one is unlimited.

**Files:**
- Create: `backend/config/sanctum.php`
- Modify: `backend/app/Http/Controllers/Auth/LoginController.php:30`
- Modify: `backend/routes/api.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `.env.example`, `.env.prod.example`
- Test: `backend/tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Feature/Auth/LoginTest.php`:

```php
it('mints a token that expires', function (): void {
    expect(config('sanctum.expiration'))->not->toBeNull();
});

it('rate-limits repeated failed logins', function (): void {
    foreach (range(1, 6) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(429);
});
```

- [ ] **Step 2: Run them and watch them fail**

```bash
make test-backend ARGS="--filter='mints a token that expires|rate-limits repeated failed logins'"
```

Expected: FAIL — `sanctum.expiration` is null, and the seventh login returns 401 rather than 429.

- [ ] **Step 3: Add the Sanctum config**

Create `backend/config/sanctum.php`:

```php
<?php

declare(strict_types=1);

return [
    /*
     | Minutes until an issued token expires. Sanctum's own default is null — never — which
     | makes a leaked token permanent. 12 hours matches one working day plus a margin: an
     | employee who signs in at the start of a shift is not asked again during it.
     */
    'expiration' => (int) env('SANCTUM_EXPIRATION_MINUTES', 720),

    'stateful' => [],

    'guard' => ['web'],

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
```

- [ ] **Step 4: Scope the token**

In `backend/app/Http/Controllers/Auth/LoginController.php`, replace line 30:

```php
        $token = $user->createToken('web')->plainTextToken;
```

with:

```php
        // Explicit abilities, not Sanctum's implicit ['*']. There is one client and one
        // session shape today, so the ability set is a single grant — but naming it means a
        // future device or integration token is a different grant rather than a silent
        // superset of this one.
        $token = $user->createToken('web', ['session'])->plainTextToken;
```

Then confirm the `auth:sanctum` middleware path still authorizes every existing route. If any route checks `tokenCan`, this changes its outcome — grep for `tokenCan` before committing and reconcile.

- [ ] **Step 5: Rate-limit the login route**

In `backend/bootstrap/app.php`, inside the existing `->withMiddleware(...)` closure, register the limiter alongside the existing `alias` call:

```php
        RateLimiter::for('login', fn (Request $request) => [
            // Per-email and per-IP together: a shared office NAT must not lock out a whole
            // floor because one person is fat-fingering their password, and one email must
            // not be brute-forceable from a botnet.
            Limit::perMinute(5)->by((string) $request->input('email')),
            Limit::perMinute(20)->by($request->ip() ?? 'unknown'),
        ]);
```

In `backend/routes/api.php`, add `->middleware('throttle:login')` to the login route definition.

- [ ] **Step 6: Add the env vars**

In both `.env.example` and `.env.prod.example`:

```bash
# Minutes until an API token expires. 720 = 12 hours, one working day plus a margin.
SANCTUM_EXPIRATION_MINUTES=720
```

- [ ] **Step 7: Run the tests**

```bash
make test-backend ARGS="--filter=Login"
```

Expected: PASS.

- [ ] **Step 8: Confirm the frontend handles expiry**

An expired token now returns 401 mid-session where it previously never did. Verify `frontend/web/src/lib/api.ts` maps a 401 to a redirect to `/login` rather than surfacing a raw error, and that `useSession` clears stored session state on that path. If it does not, fix it here — a permanent token was the only thing making the gap invisible.

```bash
cd frontend/web && npm test -- --maxWorkers=4 --testTimeout=20000
```

- [ ] **Step 9: Run both suites**

```bash
make test-backend
make test-web
```

- [ ] **Step 10: Commit**

```bash
git add backend/config backend/app backend/routes backend/bootstrap .env.example .env.prod.example frontend backend/tests
git commit -m "auth: expire tokens, scope them, and throttle the login

config/sanctum.php did not exist, so expiration was null and a leaked token was
permanent. createToken('web') passed no abilities, which Sanctum expands to
['*']. And nothing rate-limited the endpoint that mints them."
```

---

### Task 10: Make the idempotency middleware actually idempotent, and apply it

**The defect.** Two parts.

`EnsureIdempotency.php:42` takes `SELECT … FOR UPDATE` on the key's primary key. On first use the row does not exist, so **no lock is taken** and a concurrent duplicate does not wait. Both requests run `$next($request)` — the guarded work executes twice — and then the loser's plain `create()` at `:57` raises a 23505 with no savepoint, rolling back its whole transaction and surfacing as a **500** where the client should have received a replayed 200.

And `routes/api.php` has 56 mutating routes; `idempotent` appears on **one** of them (the self-service punch). Two omissions are deliberate and commented. The rest are not — approve, reject, close-cutoff, reopen, and `POST /leave/grants` are all unguarded, and a retried grant silently double-credits a balance.

**Files:**
- Modify: `backend/app/Http/Middleware/EnsureIdempotency.php:38-60`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Idempotency/EnsureIdempotencyTest.php`

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/Idempotency/EnsureIdempotencyTest.php`, in the two-process shape the concurrency tests already use:

```php
it('replays rather than 500s when two first uses of a key race', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);
    $key = (string) Str::uuid();
    $payload = ['employee_id' => $employee->id, 'leave_type_id' => $this->sil->id, 'minutes' => 480];

    // Second OS process, its own Postgres backend, same key, no prior row. Signals "LOCKED"
    // once it is inside the middleware so the parent fires its own request into the window
    // rather than guessing at a sleep.
    $child = $this->spawnConcurrentRequest('POST', '/api/v1/leave/grants', $payload, $key);
    $this->awaitSignal($child, 'LOCKED');

    $parent = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/leave/grants', $payload);
    $childStatus = $this->awaitExit($child);

    expect($parent->status())->toBeLessThan(300)
        ->and($childStatus)->toBeLessThan(300)
        ->and((int) $employee->leaveLedgerEntries()->sum('minutes'))->toBe(480)
        ->and(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(1);
});
```

`spawnConcurrentRequest` / `awaitSignal` / `awaitExit` are the helper names to give the `proc_open` harness in this file. Build them by copying `backend/tests/Feature/Leave/LeaveEffectConcurrencyTest.php`'s existing pattern verbatim — including its `lock_timeout` backstop reset in `finally` and its deliberate absence of `RefreshDatabase` (the outer transaction would hide the fixtures from the child process). If that file already exposes equivalent helpers on a shared base class, use those names instead of introducing new ones.

- [ ] **Step 2: Run it and watch it fail**

```bash
make test-backend ARGS="--filter='replays rather than 500s'"
```

Expected: FAIL — one process returns 500 from the 23505.

- [ ] **Step 3: Insert first, then branch**

In `backend/app/Http/Middleware/EnsureIdempotency.php`, replace the lock-then-create sequence with an `insertOrIgnore` claim followed by a lock:

```php
        // Claim the key BEFORE running the guarded work. `SELECT … FOR UPDATE` on a row
        // that does not exist yet locks nothing, so two first uses of the same key both
        // passed the check, both ran $next(), and the loser's create() raised a 23505 with
        // no savepoint — rolling back its whole transaction and surfacing as a 500 where a
        // replayed 200 was owed.
        //
        // insertOrIgnore is the atomic claim: exactly one caller inserts. Everyone else
        // falls through to the lockForUpdate below, which now has a real row to wait on, and
        // replays the stored response once the winner commits it.
        $claimed = DB::table('idempotency_keys')->insertOrIgnore([
            'key' => $key,
            'user_id' => $request->user()?->id,
            'request_hash' => $hash,
            'created_at' => now(),
        ]);

        $existing = DB::table('idempotency_keys')->where('key', $key)->lockForUpdate()->first();
```

Then: when `$claimed === 0` and `$existing->response_body` is populated, replay it. When `$claimed === 0` and the body is still null, the winner is mid-flight — the `lockForUpdate` above already blocked until it committed, so re-read once and replay. When `$claimed === 1`, run `$next($request)` and write the response back onto the claimed row. Keep the existing request-hash mismatch branch unchanged.

- [ ] **Step 4: Run the race test**

```bash
make test-backend ARGS="--filter='replays rather than 500s'"
```

Expected: PASS.

- [ ] **Step 5: Apply the middleware to the unguarded state-changing routes**

In `backend/routes/api.php`, add `idempotent` to every route whose retry would duplicate an effect. At minimum: request approve, request reject, cutoff close, cutoff reopen, and `POST /leave/grants`. Leave the two routes that already carry a comment explaining their deliberate exclusion exactly as they are, and add a one-line comment to any further route you decide to exclude, naming why. Do **not** add it to a pure read.

- [ ] **Step 6: Write a replay test for the highest-risk route**

```php
it('does not double-credit a leave grant replayed under one key', function (): void {
    $employee = $this->employeeOnTemplate(startMinute: 480, endMinute: 1020, breakMinutes: 60);
    $key = (string) Str::uuid();

    $payload = ['employee_id' => $employee->id, 'leave_type_id' => $this->sil->id, 'minutes' => 480];

    $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/leave/grants', $payload)->assertCreated();
    $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/leave/grants', $payload)->assertCreated();

    expect((int) $employee->leaveLedgerEntries()->sum('minutes'))->toBe(480);
});
```

- [ ] **Step 7: Run the idempotency and leave suites**

```bash
make test-backend ARGS="--filter='Idempotency|Leave'"
```

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Middleware backend/routes backend/tests
git commit -m "idempotency: claim the key before the work, and guard the routes that need it

SELECT ... FOR UPDATE on a key that does not exist yet locks nothing, so two
first uses both ran the guarded work and the loser's insert raised a 23505 as a
500. insertOrIgnore is the atomic claim. Separately, only 1 of 56 mutating
routes carried the middleware — a retried leave grant double-credited a
balance."
```

---

# Phase 3 — Standards and honest docs

---

### Task 11: Bring the two failing color tokens to WCAG AA

**The defect.** Independently recomputed: `--ink-subtle: #8c8c8c` on `#ffffff` is **3.36:1** and `--success: #24a148` on `#ffffff` is **3.35:1**, against the 4.5:1 AA floor for body text. `--ink-subtle` is `DESIGN.md`'s documented token for helper text and captions and is used as real body text in roughly 20 files, so nearly every muted label in the app is under-contrast. No test in the 600-strong suite could have caught this — jsdom has no layout engine and no rendering.

**Files:**
- Modify: `DESIGN.md`
- Modify: `frontend/web/src/styles/carbon.css:15,26`
- Test: `frontend/web/src/styles/contrast.test.ts` (new)

- [ ] **Step 1: Write the failing test**

Create `frontend/web/src/styles/contrast.test.ts`:

```ts
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/** WCAG 2.1 relative luminance. */
function luminance(hex: string): number {
  const channels = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

function ratio(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

function token(name: string): string {
  const css = readFileSync(new URL('./carbon.css', import.meta.url), 'utf8')
  const match = css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`))
  if (match === null) throw new Error(`token --${name} not found in carbon.css`)
  return match[1]
}

describe('carbon.css text tokens meet WCAG AA on the page background', () => {
  // Both of these shipped below the floor: --ink-subtle at 3.36:1 and --success at 3.35:1.
  // They are text tokens, not decoration, so 4.5:1 is the bar, not 3:1.
  it.each(['ink', 'ink-subtle', 'success', 'danger'])('--%s reaches 4.5:1 on white', (name) => {
    expect(ratio(token(name), '#ffffff')).toBeGreaterThanOrEqual(4.5)
  })
})
```

- [ ] **Step 2: Run it and watch it fail**

```bash
cd frontend/web && npm test -- --maxWorkers=4 --testTimeout=20000 src/styles/contrast.test.ts
```

Expected: FAIL on `--ink-subtle` (3.36) and `--success` (3.35).

- [ ] **Step 3: Darken both tokens in DESIGN.md first**

`DESIGN.md` is the token authority and `carbon.css` is hand-written from it, so the value changes there first or the two diverge. Replace `#8c8c8c` with **`#6f6f6f`** (4.61:1) and `#24a148` with **`#198038`** (4.58:1). Both are IBM Carbon ramp steps — Gray 60 and Green 60 — so this stays inside the design system rather than inventing a shade. Add one line beneath each noting the AA ratio and that the previous value failed.

- [ ] **Step 4: Mirror them into carbon.css**

In `frontend/web/src/styles/carbon.css`, change line 15 to `--ink-subtle: #6f6f6f;` and line 26 to `--success: #198038;`. Add above them:

```css
  /* Carbon Gray 60 / Green 60. Both were one ramp step lighter and failed WCAG AA on the
     page background — 3.36:1 and 3.35:1 against a 4.5:1 floor. --ink-subtle is DESIGN.md's
     token for helper text and captions, so it is body text in ~20 files, not decoration.
     No unit test could catch this: jsdom has no layout engine. See contrast.test.ts. */
```

- [ ] **Step 5: Run the contrast test**

```bash
cd frontend/web && npm test -- --maxWorkers=4 --testTimeout=20000 src/styles/contrast.test.ts
```

Expected: PASS.

- [ ] **Step 6: Look at it in a real browser**

Run `make dev`, sign in, and load `/me/attendance`, `/me/profile`, and `/admin/documents`. Confirm the muted labels read as muted rather than as body text, and that the success states still read as success rather than as a darker neutral. **This step is not optional** — it is the only check in this plan that a rendering engine performs, and the review found these two defects precisely because nothing else does.

- [ ] **Step 7: Run the frontend suite**

```bash
cd frontend/web && npm test -- --maxWorkers=4 --testTimeout=20000 && npm run typecheck && npm run lint
```

- [ ] **Step 8: Commit**

```bash
git add DESIGN.md frontend/web/src/styles
git commit -m "design: bring --ink-subtle and --success to WCAG AA

3.36:1 and 3.35:1 against a 4.5:1 floor. --ink-subtle is DESIGN.md's token for
helper text and is used as body text in ~20 files, so nearly every muted label
in the app was under-contrast. Moved one Carbon ramp step darker (Gray 60,
Green 60). jsdom has no layout engine, so nothing in the suite could see it —
contrast.test.ts now reads the tokens straight out of carbon.css."
```

---

### Task 12: Stop the docs describing a concurrency mechanism that does not exist

**The defect.** `docs/01-architecture.md:169-176` states that "the version check rejects a stale client." There is **no version or `lock_version` column in any migration** and **no `If-Match`/`ETag` handling anywhere in `app/` or `frontend/web/src`**. Every update in the system is last-write-wins: two HR admins editing the same profile, the second silently overwrites. Separately, `docs/01-architecture.md:103` states there is "no `timestamp without time zone` anywhere", but every table using plain `$table->timestamps()` has exactly that.

Building full optimistic locking touches every update route and its frontend caller, and is a milestone rather than a task. What must not persist is a document asserting a guarantee the code does not provide — that is what makes the next engineer skip building it.

**Files:**
- Modify: `docs/01-architecture.md:103,169-176`
- Modify: `docs/06-roadmap.md`

- [ ] **Step 1: Correct the concurrency claim**

Replace the version-check paragraph at `docs/01-architecture.md:169-176` with an accurate statement: row locks serialize the writes that share a row, and **there is no optimistic-concurrency layer** — concurrent edits to the same record are last-write-wins. Name what that costs (two HR admins editing one profile; the second wins silently) and point at the roadmap entry added in Step 3.

- [ ] **Step 2: Correct the timestamp claim**

Replace the `timestamp without time zone` claim at `docs/01-architecture.md:103` with what is true: `timestamptz` is used for the columns where the instant is load-bearing, and tables using Laravel's plain `$table->timestamps()` carry `timestamp without time zone`. Note the one place it currently bites — `ListActivityController`'s `whereDate` filter is UTC-anchored while the audit-log UI renders in the browser's zone, so a Manila admin filtering on a date loses rows near midnight.

- [ ] **Step 3: Add the deferred entry**

In `docs/06-roadmap.md`'s **Deferred** table, add optimistic concurrency with its reviving trigger stated concretely: the first report of a silently lost edit, or the first screen where two people routinely edit one record. Note that the shape would be a `lock_version` column plus `If-Match` on the update routes, and that the docs asserted it existed before it did.

- [ ] **Step 4: Commit**

```bash
git add docs
git commit -m "docs: stop claiming a version check and a timezone guarantee we do not have

01-architecture.md described optimistic concurrency rejecting a stale client.
There is no version column and no If-Match handling anywhere — every update is
last-write-wins. It also claimed no `timestamp without time zone` exists, while
every table using plain timestamps() has one. Both corrected; optimistic
concurrency added to Deferred with its trigger."
```

---

## Not in this plan

The review produced roughly 60 findings. The 12 tasks above cover the ten that produce a wrong payroll figure, an unbounded credential, a silently-undone background job, or an accessibility failure. These are the next tier — each is real, none is a ship-blocker, and each needs its own decision before it becomes a task. They belong in a follow-on plan written after Phase 1 lands, not folded in here.

| Finding | Where | Shape of the fix |
| --- | --- | --- |
| `CloseCutoff`'s exception gates are unlocked reads taken before any employee lock | `CloseCutoff.php:52,61` | Reorder the gates to run **after** the employee locks. Do **not** add `lockForUpdate()` to `CutoffGuard` — `ApproveRequest` takes `employees` then reads `cutoff_periods` while `CloseCutoff` does the reverse, so that "obvious" fix creates a textbook AB-BA deadlock. |
| A manual punch into a closed period is appended, then silently dropped | `ManualPunchRequest.php:33` | Validate against the cutoff state; return a 422 the way `ApproveRequest` already does rather than a 201 whose effect never lands. |
| "The office" means three different things | `ComputeDailySummary.php:77`, `CloseCutoff.php:101`, `EffectivePunches.php:46` | Pick one — the employment record effective on the date — and make the other two read it. Needs a decision about what a mid-period transfer means for a cutoff. |
| `ScheduleResolver` mixes effective-dated assignments with a non-effective-dated department | `ScheduleResolver.php:36-52` | Effective-date the department lookup. Today a department move silently re-resolves every unlocked past day. |
| `DeleteShiftTemplate` is a check-then-act whose backstop is a CASCADE | `DeleteShiftTemplate.php:33` | Lock the parent first, as `CreateHoliday.php:35` already does. |
| `pesosToCentavos` rounds a third decimal, against the documented rule | `admin/employees/new/page.tsx:44`, `[employee]/page.tsx:70` | Mirror `Money::parse`'s string arithmetic — reject a third digit rather than rounding it. Deduplicate the two copies while there. |
| Overtime is filed as an unbounded decimal float | `api.ts:474`, `SubmitOvertimeRequestRequest.php:21` | Send integer minutes on the wire. Add a `max`; `"1e3"` currently validates and overflows the column into a 500. |
| `percentToBp` rounds a below-floor rate up to exactly the floor | `basisPoints.ts:19`, `admin/pay-rules/page.tsx:253` | Block submit on `isBelowFloor`, which is already computed and only used to color a cell. |
| `ListCutoffsController` synthesizes the window from UTC "today" | `ListCutoffsController.php:35` | Use `$office->timezone`, already in hand at `:23`. Wrong for 8 hours on the 1st and 16th of every month. `CutoffEndpointsTest.php:67` mirrors the bug and passes either way. |
| Eight FormRequests validate wire dates as `date`, not `date_format:Y-m-d` | `SubmitLeaveRequestRequest.php:25` and seven others | `"2026-08-02T02:00:00+08:00"` validates and files leave for the 1st. |
| Request-decision mutations invalidate the wrong side of the state machine | `useDecideRequest`, the submit hooks | Approval writes the ledger debit and the punch correction, so it must invalidate `keys.leave.myBalances()` and `keys.attendance.all()`; submission must not. |
| S3 uploads inside a DB transaction | `SubmitAttendanceAdjustment.php:46`, `SubmitLeaveRequest.php:52` | The pattern `SaveEmployeeIdentification.php:35-46` already documents, in a five-line comment, as wrong. |
| `ReplaceEmployeeDependents` merges instead of replacing under concurrency | `ReplaceEmployeeDependents.php:99` | Take the employee lock the other write paths take. |
| No pruning job for `idempotency_keys` | `2026_07_25_000002:26` | The index comment says "pruning window"; nothing prunes. |
| `LeaveUnit::toMinutes` splits by `intdiv`, losing a minute on an odd day length | `LeaveUnit.php:99` | Route through `Money::allocate`'s remainder-distribution rule, which exists for exactly this. |

Two more from the "fragile but correct" column are worth a tripwire rather than a task: **`Money::fraction()` has no production callers**, so the "one place a centavo can be created or destroyed" guarantee is currently vacuous and the first gross-to-net feature will be the first thing to depend on it; and **append-only rests entirely on a source-text grep** — `pg_trigger` is empty and the `hris` role holds `UPDATE`/`DELETE` on `attendance_logs`, so a data-repair migration or a `tinker` session leaves no trace. A `BEFORE UPDATE OR DELETE … RAISE EXCEPTION` trigger is the only mechanism the grep cannot be routed around.

---

## Verification for the whole plan

After the last task in each phase:

```bash
make test          # both suites in containers
cd frontend/web && npm run typecheck && npm run lint && npm run build
./scripts/e2e-prod-boot.sh   # Phase 2 only — proves the queue worker drains
```

Expected: backend fully green. Frontend green except `attendance.test.tsx`'s known date-dependent red, which this plan does not touch.

The backend test count rises from 911; the frontend from 600. Update the figures in **both** `CLAUDE.md`'s Tests section and its Status section — they are documented as needing to agree, and a milestone that changes one without the other means both get re-measured rather than guessed.
