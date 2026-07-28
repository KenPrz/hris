# M7b — Payroll export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn a closed cutoff period into a per-employee earnings breakdown — the period's `daily_summary_lines` rolled up in integer minutes + basis points, each line traceable to the `rule_version_id` that priced it, reconciling line-for-line against the calendar view. A JSON endpoint + an HR review screen. Closed periods only.

**Architecture:** A pure `PayrollExport` domain aggregator reads the frozen summaries by **period membership** (`office_id` + date range, NOT the `locked` label), groups each employee's lines by `(kind, applied_bp, rule_version_id)`, sums the day-level scalars, and carries the effective `base_rate_cents` as a reference. A `GET /office/cutoffs/{period}/export` endpoint (OfficeScope-gated, refuses an open period) serves it; a Carbon review screen off `/office/cutoffs` renders it. No new writes, no concurrency spine — it reads what M7a froze.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres); Next 16 / React 19 / TS / Tailwind + Carbon (Vitest).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/`/`tests/`. Arch-enforced.
- Never call `env()` outside `config/`. Arch-enforced.
- Domain layer is framework-agnostic except Eloquent query-builder wrappers (the `ApprovalQueues`/`LeaveDayLookup` precedent). The aggregator is such a wrapper: query + in-memory rollup, NO DB writes.
- Controllers `final` + invokable; return domain objects serialized by a Resource; know nothing about business rules beyond scoping.
- Integer minutes / centavos / basis points — never floats. Envelope: success `{"data":...}`, error `{"error":...}`.
- **404-not-403 existence discipline.** The `{period}` is resolved scoped to the caller's administered offices; a foreign/nonexistent period 404s identically. No `exists:` in any FormRequest.
- **Read by MEMBERSHIP, not the label:** the export selects summaries by `office_id = period.office_id AND date BETWEEN period.start_date AND period.end_date`. NEVER filter on `status = 'locked'` — a leaked `computed` row or an incomplete day must still appear (the direct payoff of M7a's forward-note).
- **`rule_version_id` is a column on `daily_attendance_summaries`, NOT on `daily_summary_lines`.** A line's rule version is its parent summary's `rule_version_id`. The grouping key `(kind, applied_bp, rule_version_id)` pairs each line with its summary's version. `rule_version_id` is null for a `leave_with_pay`-only day — such lines group under `rule_version_id = null`.
- Calendar dates are `YYYY-MM-DD` strings on the wire; `timestamptz` for timestamps.
- Tests run against real PostgreSQL, never SQLite.
- Export is **closed-only**: an open period → `422 period_not_exportable`.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL. Message body only. Applies to the PR body too.

---

### Task 1: `PayrollExport` aggregator + DTOs + `PeriodNotExportable`

**Files:**
- Create: `backend/app/Domain/Payroll/PayrollEarningsLine.php` (readonly DTO)
- Create: `backend/app/Domain/Payroll/PayrollEmployeeExport.php` (readonly DTO)
- Create: `backend/app/Domain/Payroll/PayrollExportData.php` (readonly DTO)
- Create: `backend/app/Domain/Payroll/PayrollExport.php` (the aggregator)
- Create: `backend/app/Exceptions/Domain/PeriodNotExportable.php`
- Modify: `backend/app/Models/DailyAttendanceSummary.php` (add an `employee()` belongsTo)
- Test: `backend/tests/Feature/Payroll/PayrollExportTest.php`

**Interfaces:**
- Consumes: `CutoffPeriod`, `DailyAttendanceSummary` (+ `lines`, `employee`), `DailySummaryLine`, `SummaryLineKind`, `EmploymentResolver`.
- Produces: `PayrollExport::for(CutoffPeriod $period): PayrollExportData`. DTO shapes below.

- [ ] **Step 1: Add the `employee()` relation** to `DailyAttendanceSummary` (it has `lines()` and `office()`; add the sibling). Match the existing relation style in that file:

```php
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
```

Add `use App\Models\Employee;` if not present, and ensure `BelongsTo` is imported (it is — `office()` uses it).

- [ ] **Step 2: Write the DTOs.**

`PayrollEarningsLine.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Pay\SummaryLineKind;

/** One rolled-up earnings line: summed minutes for a (kind, applied_bp, rule_version) triple. */
final readonly class PayrollEarningsLine
{
    public function __construct(
        public SummaryLineKind $kind,
        public int $appliedBp,
        public ?string $ruleVersionId,
        public int $minutes,
    ) {}
}
```

`PayrollEmployeeExport.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

/**
 * One employee's period rollup. `baseRateCents` is the period-END effective rate (reference
 * only — never multiplied out); `baseRateSegments` lists the distinct effective rates that
 * applied to in-period days (one element in the common constant-rate case).
 *
 * @param  list<PayrollEarningsLine>  $lines
 * @param  list<array{effective_from: string, base_rate_cents: int}>  $baseRateSegments
 */
final readonly class PayrollEmployeeExport
{
    public function __construct(
        public string $employeeId,
        public string $employeeNo,
        public ?int $baseRateCents,
        public array $baseRateSegments,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $undertimeMinutes,
        public int $unpaidOvertimeMinutes,
        public array $lines,
        public bool $hasIncompleteDays,
    ) {}
}
```

`PayrollExportData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Models\CutoffPeriod;

/** @param  list<PayrollEmployeeExport>  $employees */
final readonly class PayrollExportData
{
    public function __construct(
        public CutoffPeriod $period,
        public array $employees,
    ) {}
}
```

- [ ] **Step 3: Write the `PeriodNotExportable` exception** (mirror `app/Exceptions/Domain/CutoffNotClosed.php` — extends `DomainException`, `errorCode() = 'period_not_exportable'`, `httpStatus() = 422`, a `details()` carrying the period id and its state). Read `CutoffNotClosed` and match its shape exactly.

- [ ] **Step 4: Write the failing test** `PayrollExportTest.php`. Cover, with seeded fixtures (`CutoffPeriod::factory()->closed()`, `DailyAttendanceSummary::factory()` with `lines`, `Employee`, `EmploymentRecord`):
  - **Reconciliation:** for an employee with several in-period days, `PayrollExport::for($period)`'s per-`(kind, applied_bp, rule_version_id)` minutes equal the manually-summed `daily_summary_lines`; the `totals` equal the summed day scalars (worked/late/undertime/unpaid_overtime). This is THE guarantee — assert exact integer equality.
  - **Grouping by rule version:** two in-period summaries with the SAME `(kind, applied_bp)` but DIFFERENT `rule_version_id` produce TWO lines (not merged), each with its version; a `leave_with_pay`-only summary (`rule_version_id = null`) groups under `ruleVersionId === null`.
  - **Membership not label:** an in-period summary with `status = 'computed'` (NOT locked) IS included; an out-of-period date is excluded; an in-period `is_incomplete` summary sets `hasIncompleteDays = true` and contributes its scalars without inventing lines.
  - **Base rate:** a constant `base_rate_cents` → one segment, `baseRateCents` = that value; a mid-period rate change (two `EmploymentRecord`s effective within the window) → two `baseRateSegments`, `baseRateCents` = the period-end effective one.
  - **Multiple employees:** two employees in the office → two `PayrollEmployeeExport`s; an office employee with NO in-period summary is omitted.

Write these as explicit assertions on the returned DTO (build the input with the factories; the test file assembles the fixtures — mirror how `CloseCutoffTest` seeds summaries + employees).

- [ ] **Step 5: Run, verify fail.** `cd backend && ./vendor/bin/pest --filter=PayrollExport`. Expected: FAIL (aggregator missing).

- [ ] **Step 6: Implement `PayrollExport`.** The load-bearing piece — read it carefully:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Employment\EmploymentResolver;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rolls a closed cutoff period's frozen summaries into a per-employee earnings breakdown, in
 * integer minutes + basis points. Pure query + in-memory aggregation, no writes — a domain-
 * Eloquent wrapper like ApprovalQueues.
 *
 * Reads by MEMBERSHIP (office_id + date range), never by status='locked', so a leaked computed
 * row or an incomplete day still appears (M7a's forward-note). A line's rule_version_id is its
 * parent summary's column (lines don't carry it); the (kind, applied_bp, rule_version_id) group
 * key pairs each line with its summary's version.
 */
final class PayrollExport
{
    private function __construct() {}

    public static function for(CutoffPeriod $period): PayrollExportData
    {
        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();

        /** @var Collection<int, DailyAttendanceSummary> $summaries */
        $summaries = DailyAttendanceSummary::query()
            ->with('lines')
            ->where('office_id', $period->office_id)
            ->whereBetween('date', [$start, $end])
            ->get();

        $employees = $summaries
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, string $employeeId): PayrollEmployeeExport =>
                self::forEmployee($employeeId, $rows, $period))
            ->values()
            ->all();

        return new PayrollExportData($period, $employees);
    }

    /** @param  Collection<int, DailyAttendanceSummary>  $rows */
    private static function forEmployee(string $employeeId, Collection $rows, CutoffPeriod $period): PayrollEmployeeExport
    {
        $employee = Employee::query()->findOrFail($employeeId);

        // Lines: flatten, pairing each with its summary's rule_version_id, then group + sum.
        $lines = $rows
            ->flatMap(fn (DailyAttendanceSummary $s): array => $s->lines
                ->map(fn ($line): array => [
                    'kind' => $line->kind,
                    'applied_bp' => $line->applied_bp,
                    'rule_version_id' => $s->rule_version_id,
                    'minutes' => $line->minutes,
                ])->all())
            ->groupBy(fn (array $l): string => $l['kind']->value.'|'.$l['applied_bp'].'|'.($l['rule_version_id'] ?? 'null'))
            ->map(fn (Collection $group): PayrollEarningsLine => new PayrollEarningsLine(
                kind: $group->first()['kind'],
                appliedBp: $group->first()['applied_bp'],
                ruleVersionId: $group->first()['rule_version_id'],
                minutes: $group->sum('minutes'),
            ))
            ->values()
            ->all();

        [$baseRateCents, $segments] = self::baseRate($employee, $rows, $period);

        return new PayrollEmployeeExport(
            employeeId: $employeeId,
            employeeNo: $employee->employee_no,
            baseRateCents: $baseRateCents,
            baseRateSegments: $segments,
            workedMinutes: (int) $rows->sum('worked_minutes'),
            lateMinutes: (int) $rows->sum('late_minutes'),
            undertimeMinutes: (int) $rows->sum('undertime_minutes'),
            unpaidOvertimeMinutes: (int) $rows->sum('unpaid_overtime_minutes'),
            lines: $lines,
            hasIncompleteDays: $rows->contains(fn (DailyAttendanceSummary $s): bool => $s->is_incomplete),
        );
    }

    /**
     * The period-end effective base rate + the distinct effective segments that priced in-period
     * days. Loads the employee's records once (no N+1) and resolves in-memory.
     *
     * @param  Collection<int, DailyAttendanceSummary>  $rows
     * @return array{0: ?int, 1: list<array{effective_from: string, base_rate_cents: int}>}
     */
    private static function baseRate(Employee $employee, Collection $rows, CutoffPeriod $period): array
    {
        $endRecord = EmploymentResolver::on($employee, Carbon::parse($period->end_date->toDateString()));
        $endRate = $endRecord?->base_rate_cents;

        // Distinct records effective on the in-period days this employee actually has summaries for.
        $segments = $rows
            ->map(fn (DailyAttendanceSummary $s) => EmploymentResolver::on($employee, Carbon::parse($s->date->toDateString())))
            ->filter()
            ->unique(fn ($record): string => $record->id)
            ->sortBy(fn ($record): string => $record->effective_from->toDateString())
            ->map(fn ($record): array => [
                'effective_from' => $record->effective_from->toDateString(),
                'base_rate_cents' => $record->base_rate_cents,
            ])
            ->values()
            ->all();

        return [$endRate, $segments];
    }
}
```

> **Two notes for the implementer:** (1) `EmploymentResolver::on` takes a `CarbonInterface` — the `Carbon::parse($date->toDateString())` wrapping is defensive; if `$s->date` is already a Carbon, pass it directly (check the cast). (2) The per-row `EmploymentResolver::on` calls in `baseRate` each hit the DB; for M7b's period sizes (≤~16 days/employee) this is acceptable, but if you prefer, load `$employee->employmentRecords()->orderByDesc('effective_from')->get()` once and resolve in-memory (first record with `effective_from <= date`). Either is fine — keep it correct and note which you chose.

- [ ] **Step 7: Run, verify pass.** `./vendor/bin/pest --filter=PayrollExport`. Expected: PASS.
- [ ] **Step 8: Commit.** `git add backend/app/Domain/Payroll backend/app/Exceptions/Domain/PeriodNotExportable.php backend/app/Models/DailyAttendanceSummary.php backend/tests/Feature/Payroll && git commit -m "M7b: PayrollExport aggregator — per-employee period rollup in minutes + basis points"`

---

### Task 2: `GET /office/cutoffs/{period}/export` — controller, resource, route

**Files:**
- Create: `backend/app/Http/Resources/PayrollExportResource.php`
- Create: `backend/app/Http/Controllers/Cutoff/ExportCutoffController.php`
- Modify: `backend/routes/api.php` (add the route)
- Test: `backend/tests/Feature/Payroll/PayrollExportEndpointTest.php`

**Interfaces:**
- Consumes: `PayrollExport::for`, `PeriodNotExportable`, `OfficeScope::administers`.
- Produces: `GET /office/cutoffs/{period}/export` → `PayrollExportResource`.

- [ ] **Step 1: Write the resource** `PayrollExportResource.php` — serialize `PayrollExportData` to the wire shape. Mirror `CutoffPeriodResource` / `DailySummaryResource` style:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payroll\PayrollEarningsLine;
use App\Domain\Payroll\PayrollEmployeeExport;
use App\Domain\Payroll\PayrollExportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollExportData */
final class PayrollExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => [
                'id' => $this->period->id,
                'office_id' => $this->period->office_id,
                'start_date' => $this->period->start_date->toDateString(),
                'end_date' => $this->period->end_date->toDateString(),
                'state' => $this->period->state->value,
            ],
            'employees' => array_map(fn (PayrollEmployeeExport $e): array => [
                'employee' => [
                    'id' => $e->employeeId,
                    'employee_no' => $e->employeeNo,
                    'base_rate_cents' => $e->baseRateCents,
                ],
                'base_rate_segments' => $e->baseRateSegments,
                'totals' => [
                    'worked_minutes' => $e->workedMinutes,
                    'late_minutes' => $e->lateMinutes,
                    'undertime_minutes' => $e->undertimeMinutes,
                    'unpaid_overtime_minutes' => $e->unpaidOvertimeMinutes,
                ],
                'lines' => array_map(fn (PayrollEarningsLine $l): array => [
                    'kind' => $l->kind->value,
                    'applied_bp' => $l->appliedBp,
                    'rule_version_id' => $l->ruleVersionId,
                    'minutes' => $l->minutes,
                ], $e->lines),
                'has_incomplete_days' => $e->hasIncompleteDays,
            ], $this->employees),
        ];
    }
}
```

- [ ] **Step 2: Write the controller** `ExportCutoffController.php` — mirror `ReopenCutoffController` (the `{period}` binding + `OfficeScope::administers` 404), plus the closed-check:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutoff;

use App\Domain\Cutoff\CutoffState;
use App\Domain\Payroll\PayrollExport;
use App\Domain\Scope\OfficeScope;
use App\Exceptions\Domain\PeriodNotExportable;
use App\Http\Resources\PayrollExportResource;
use App\Models\CutoffPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The payroll export for a CLOSED cutoff period. 404-not-403 for a foreign/nonexistent period
 * (route-binding + OfficeScope land in the same NotFoundHttpException); an OPEN period is refused
 * with PeriodNotExportable — an export is only defined for a finalized period.
 */
final class ExportCutoffController
{
    public function __invoke(Request $request, CutoffPeriod $period): PayrollExportResource
    {
        if (! OfficeScope::administers($request->user(), $period->office_id)) {
            throw new NotFoundHttpException;
        }

        if ($period->state !== CutoffState::Closed) {
            throw new PeriodNotExportable($period->id, $period->state->value);
        }

        return PayrollExportResource::make(PayrollExport::for($period));
    }
}
```

> Confirm `PeriodNotExportable`'s constructor signature matches how you wrote it in Task 1 (id + state string). Adjust the call if you chose a different shape.

- [ ] **Step 3: Register the route.** In `routes/api.php`, in the `office` group next to the other cutoff routes (after the reopen route), add — matching the top-of-file `use` import style:

```php
Route::get('/cutoffs/{period}/export', \App\Http\Controllers\Cutoff\ExportCutoffController::class);
```

(Add a `use App\Http\Controllers\Cutoff\ExportCutoffController;` at the top and reference it unqualified, matching the sibling cutoff controllers.)

- [ ] **Step 4: Write the endpoint test** `PayrollExportEndpointTest.php`. Cover (mirror `CutoffEndpointsTest`'s setup — an HR admin over the office via `hrAdminOffices`, `Sanctum::actingAs`): a closed period → 200 with `data.period` + `data.employees[]` shape (assert a known line's minutes reconciles); an OPEN period → 422 `period_not_exportable`; a foreign-office period → 404 (`assertExactJson` identical to a fabricated-uuid 404); an unauthenticated/unauthorized caller matches the sibling discipline.

- [ ] **Step 5: Run, verify pass.** `./vendor/bin/pest --filter=PayrollExportEndpoint`. Expected: PASS.
- [ ] **Step 6: Commit.** `git add backend/app/Http/Resources/PayrollExportResource.php backend/app/Http/Controllers/Cutoff/ExportCutoffController.php backend/routes/api.php backend/tests/Feature/Payroll/PayrollExportEndpointTest.php && git commit -m "M7b: GET /office/cutoffs/{period}/export endpoint (closed-only, OfficeScope)"`

---

### Task 3: Frontend data layer — types, api, keys, hook

**Files:**
- Modify: `frontend/web/src/lib/api.ts` (export wire types + `api.cutoffs.export`)
- Modify: `frontend/web/src/lib/keys.ts` (`keys.payrollExport`)
- Create: `frontend/web/src/hooks/usePayrollExport.ts` + `.test.tsx`

**Interfaces:**
- Consumes: the `GET /office/cutoffs/{period}/export` shape.
- Produces: `PayrollExport` wire type, `api.cutoffs.export(periodId)`, `keys.payrollExport.forPeriod(periodId)`, `usePayrollExport(periodId)` query hook.

- [ ] **Step 1: Write the failing hook test** — mirror `frontend/web/src/hooks/useCutoffs.ts` + its test (or `useMyAttendanceSummary`): `usePayrollExport(periodId)` calls `api.cutoffs.export(periodId)` and is keyed by `keys.payrollExport.forPeriod(periodId)`. Assert it fetches and returns the data.

- [ ] **Step 2: Run, verify fail.** `cd frontend/web && npm test -- usePayrollExport`. FAIL.

- [ ] **Step 3: Add wire types** in `api.ts` (near the `CutoffPeriod` type). Match the backend shape exactly; reuse the existing `SummaryLineKind` type:

```ts
export type PayrollEarningsLine = {
  kind: SummaryLineKind
  applied_bp: number
  rule_version_id: string | null
  minutes: number
}

export type PayrollEmployeeExport = {
  employee: { id: string; employee_no: string; base_rate_cents: number | null }
  base_rate_segments: { effective_from: string; base_rate_cents: number }[]
  totals: { worked_minutes: number; late_minutes: number; undertime_minutes: number; unpaid_overtime_minutes: number }
  lines: PayrollEarningsLine[]
  has_incomplete_days: boolean
}

export type PayrollExport = {
  period: { id: string; office_id: string; start_date: string; end_date: string; state: 'open' | 'closed' }
  employees: PayrollEmployeeExport[]
}
```

- [ ] **Step 4: Add the api method** to the `cutoffs` block in `api.ts`:

```ts
    export: (periodId: string) => request<PayrollExport>(`/office/cutoffs/${periodId}/export`),
```

- [ ] **Step 5: Add the key** in `keys.ts`:

```ts
  payrollExport: {
    forPeriod: (periodId: string) => ['payroll-export', periodId] as const,
  },
```

- [ ] **Step 6: Write the hook** `usePayrollExport.ts` — mirror `useCutoffs.ts` (a `useQuery` keyed by `keys.payrollExport.forPeriod`, `enabled` when a periodId is present):

```ts
'use client'

import { useQuery } from '@tanstack/react-query'

import type { PayrollExport } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function usePayrollExport(periodId: string | null) {
  return useQuery<PayrollExport>({
    queryKey: keys.payrollExport.forPeriod(periodId ?? ''),
    queryFn: () => api.cutoffs.export(periodId as string),
    enabled: periodId !== null,
  })
}
```

- [ ] **Step 7: Run tests + typecheck.** `npm test -- usePayrollExport && npm run typecheck`. Green.
- [ ] **Step 8: Commit.** `git add frontend/web/src/lib/api.ts frontend/web/src/lib/keys.ts frontend/web/src/hooks/usePayrollExport.ts frontend/web/src/hooks/usePayrollExport.test.tsx && git commit -m "M7b: frontend data layer for the payroll export"`

---

### Task 4: Frontend — the export review screen

**Files:**
- Create: `frontend/web/src/app/(app)/office/cutoffs/[period]/export/page.tsx` + its test (OR an in-page panel — see Step 1)
- Modify: `frontend/web/src/app/(app)/office/cutoffs/page.tsx` (add "View export" on closed rows)
- Possibly create: `frontend/web/src/components/domain/PayrollExportView.tsx`

**Interfaces:**
- Consumes: `usePayrollExport`, the `PayrollExport` type, the `SummaryLineKind` label copy.

- [ ] **Step 1: Decide the surface** — read `src/app/(app)/office/cutoffs/page.tsx` and the existing routing convention. Prefer a dedicated route `/office/cutoffs/[period]/export` (a review page), OR an in-page detail panel if that matches the codebase better. Pick the one consistent with how other office screens drill into detail (e.g. how leave-types/holidays handle detail). Whichever you choose, the **"View export" affordance appears only on CLOSED period rows**.

- [ ] **Step 2: Write the failing screen test** — render the export view with a mocked `usePayrollExport` returning a known payload; assert it renders a per-employee section with the earnings lines (`kind` label + `applied_bp`% + minutes) and the totals, and surfaces a warning when `has_incomplete_days` is true. Mirror `cutoffs.test.tsx` / `DaySummaryDetail.test.tsx`.

- [ ] **Step 3: Run, verify fail.**

- [ ] **Step 4: Implement the view.** Carbon, `var(--*)` tokens only. Per employee: `employee_no`, the day-level totals (reuse the `Duration` component for minutes), and the earnings lines table. **Reuse the calendar's `SummaryLineKind` → label copy** so the export reads identically to the day detail — import/share the `LINE_LABEL` map from `DaySummaryDetail.tsx` (export it from there if it isn't already, rather than duplicating). Render `applied_bp` as a percent (`bp / 100`, the existing `bpToPercent` helper). Surface `has_incomplete_days` as a `<Tag kind="warning">incomplete days</Tag>`. Show `base_rate_cents` as a reference (formatted via the existing money helper if there is one; else a plain integer-centavos label — do NOT invent peso math, it's a reference).

- [ ] **Step 5: Wire the "View export" action** into the cutoffs page on closed rows (a link/button to the route, or a panel toggle). Update `cutoffs.test.tsx` to assert it appears only for closed periods.

- [ ] **Step 6: Run tests + typecheck + build.** `npm test && npm run typecheck && npm run build`. All green.
- [ ] **Step 7: Commit.** `git add frontend/web/src/app frontend/web/src/components && git commit -m "M7b: payroll export review screen off /office/cutoffs"`

---

### Task 5: e2e-payroll-export.sh + docs

**Files:**
- Create: `scripts/e2e-payroll-export.sh`
- Modify: `docs/03-api.md`, `docs/06-roadmap.md`, `docs/features.md` (and `docs/02-data-model.md` only if a note about reading-by-membership belongs there)

- [ ] **Step 1: Write `scripts/e2e-payroll-export.sh`.** Model it on `scripts/e2e-cutoffs.sh` (login, `jq` envelope parsing, base URL, per-assertion PASS/FAIL, `exit 1`). Prove, LIVE:
  1. Ensure a seeded office + employee with computed, non-incomplete summaries in a semi-monthly window (reuse the seeder; the same setup `e2e-cutoffs.sh` uses).
  2. Close the period (`POST /office/cutoffs/close`), so it's exportable.
  3. `GET /office/cutoffs/{period}/export` → 200. For the employee, assert the export's per-`(kind, applied_bp)` line minutes **reconcile** against `/me/attendance/summary?month=...` (as that employee) summed over the in-period dates — the line-for-line guarantee. Assert the `totals` match too.
  4. Re-hit the export → assert the response is **byte-identical** (the period is locked; the numbers are stable — the reproducibility the done-when asks for).
  5. An OPEN period's export → **422 `period_not_exportable`** (reopen the period, or use a different open window).
  Print per-assertion PASS/FAIL; `exit 1` on any mismatch. `chmod +x`.

- [ ] **Step 2: Run it live.** Stack is up + migrated + seeded (or seed as the sibling e2e does). `bash scripts/e2e-payroll-export.sh`. Fix any real defect it surfaces (a failing e2e is a real finding). Expected: exit 0.

- [ ] **Step 3: Docs.**
  - `03-api.md`: `GET /office/cutoffs/{period}/export` — the closed-only rule (`422 period_not_exportable`), the response shape (period header + per-employee totals + `(kind, applied_bp, rule_version_id, minutes)` lines + `base_rate_cents`/`base_rate_segments` reference + `has_incomplete_days`), the 404-not-403 scoping; add `period_not_exportable` to the errors table.
  - `06-roadmap.md`: mark **M7b complete** and **M7 complete**; note the export reads by period membership (not the `locked` label), reconciles line-for-line, is hours+bp (not pesos), and is closed-only; deferrals (CSV, peso gross, open-period draft, full roster). Update any status/count lines.
  - `features.md`: HR can export a closed period's per-employee earnings breakdown (minutes + basis points, per rule version), reconciling against the calendar.
  - `02-data-model.md`: only if it's the right home for a one-line "the export reads frozen summaries by office+date-range membership" note; otherwise skip.

- [ ] **Step 4: Commit.** `git add scripts/e2e-payroll-export.sh docs && git commit -m "M7b: e2e-payroll-export.sh + docs; M7b complete, M7 complete"`

---

## Self-Review (controller — before dispatch)

**Spec coverage:** Task 1 = the aggregator + DTOs + `PeriodNotExportable` (spec §"What the export contains", §Backend `PayrollExport`, decisions 1 & 4). Task 2 = the endpoint + resource (§Backend endpoint, decision 3 closed-only). Task 3 = frontend data layer (§Frontend). Task 4 = the review screen (§Frontend). Task 5 = e2e + docs (§Testing, §Done when). Every spec section maps to a task.

**Placeholder scan:** no TBD/TODO; Tasks 1–3 carry full code, Tasks 4–5 give concrete "read sibling X and mirror" with the sibling named (the surface-choice in Task 4 and the base-rate N+1 note in Task 1 are genuine implementer judgments, each with the decision criteria stated).

**Type consistency:** `PayrollEarningsLine`/`PayrollEmployeeExport`/`PayrollExportData` field names are used identically across the aggregator (Task 1), the resource (Task 2), and the TS wire types (Task 3): `kind`/`applied_bp`/`rule_version_id`/`minutes` on a line; `employee_no`/`base_rate_cents`/`base_rate_segments`/`totals`/`lines`/`has_incomplete_days` per employee. The grouping key `(kind, applied_bp, rule_version_id)` — with `rule_version_id` sourced from the SUMMARY not the line — is stated in the Global Constraints and implemented once in Task 1. `PeriodNotExportable` (422, `period_not_exportable`) is defined in Task 1 and asserted in Tasks 2 and 5.

**One thing flagged for the reviewer:** Task 1's base-rate segment resolution calls `EmploymentResolver::on` per in-period summary row (bounded, ≤~16/employee); the plan explicitly allows a load-once in-memory alternative. Not a correctness risk, just a perf note the reviewer may weigh.
