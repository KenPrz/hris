# M7b — Payroll export (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M7b — the second and final slice of M7 (Cutoffs, locking, payroll export).
Completes M7. Slicing: **M7a cutoffs + locking (done) → M7b payroll export.**
**Depends on:** M7a (`cutoff_periods`, `CutoffPeriod`, `CutoffState`, the `/office/cutoffs`
screen, the frozen `daily_attendance_summaries.status = 'locked'`), M5/M5b (the compute engine,
`daily_attendance_summaries` + `daily_summary_lines`, `SummaryLineKind`, `rule_version_id`),
and M2 (`offices`, `OfficeScope`, `employment_records.base_rate_cents`, `EmploymentResolver`).

## Goal

Turn a closed cutoff period into a per-employee earnings breakdown — the artifact a payroll
clerk hands to the downstream gross-to-net system. Per employee, per **closed** period: the
period's `daily_summary_lines` rolled up into earnings lines **in integer minutes and basis
points**, each traceable to the `rule_version_id` that priced it, plus the day-level totals
(worked/late/undertime/unpaid-overtime). The export **reconciles line-for-line against the
calendar view** (it is the same `daily_summary_lines`, summed) and is stable/reproducible for
a locked period.

This is a read-and-aggregate slice: no new concurrency spine, no new writes to attendance or
summaries. It reads the numbers M7a froze and serializes them.

## Decisions (from brainstorm)

1. **Unit: hours + basis points, not pesos.** Each line carries summed minutes at its
   `applied_bp` with its `rule_version_id`; the employee's effective `base_rate_cents` is
   carried as a **reference** field, never multiplied out. Honors the system's "the hours, not
   the gross-to-net" boundary, matches the roadmap's "minutes and basis points", and is what
   lets the export reconcile line-for-line against the minutes-based calendar. Peso gross-pay
   computation is explicitly out of scope.
2. **Delivery: a JSON endpoint + an HR review screen.** No CSV/file download in this slice
   (deferred) — the endpoint + screen is the inspectable core.
3. **Closed periods only.** The export is the artifact of a finalized, locked period; an open
   period has no export (the endpoint refuses it). A draft/preview of an open period is deferred.
4. **Source = period membership, not the `locked` label.** The export selects summaries by
   `office_id = period.office_id AND date BETWEEN period.start_date AND period.end_date`, NOT by
   `status = 'locked'` — per M7a's own forward-note, so the documented M7a residual (a leaked
   `computed` row) or an incomplete day is never silently excluded from payroll.

## What the export contains

Per closed period, an object per included employee. **Included employees:** those with at least
one `daily_attendance_summaries` row in the period (employees with attendance data). A full
roster including zero-attendance employees is an M8 concern.

For each employee:
- **`employee`**: `{ id, employee_no, base_rate_cents }`. There is no name field on `employees`
  yet (the person's name is M8's employee profiler); `employee_no` (unique, always present, even
  for a punch-only worker with no login) is the payroll identifier — matching `EmployeeResource`.
  `base_rate_cents` is the effective rate — see "Base rate" below.
- **`totals`** (day-level scalars summed over the employee's in-period summaries):
  `{ worked_minutes, late_minutes, undertime_minutes, unpaid_overtime_minutes }`.
- **`lines`**: the period's `daily_summary_lines` for this employee, grouped by
  **`(kind, applied_bp, rule_version_id)`**, each `{ kind, applied_bp, rule_version_id, minutes }`
  where `minutes` is the summed line minutes. Grouping includes `rule_version_id` because a
  period can straddle a `pay_rules` version change — a `regular_day @ 10000bp` priced under rule
  v1 and under rule v2 are distinct earnings lines, each traceable to the version that produced
  it. (`rule_version_id` is null only for a `leave_with_pay`-only day, which the compute layer
  already leaves rule-unversioned; such lines group under `rule_version_id = null`.)
- **`has_incomplete_days`**: true if any in-period summary for this employee has
  `is_incomplete = true`. A clean closed period has none (the close gate forbids incomplete
  in-period days), so this is a data-quality signal surfacing the M7a leaked-row residual, not a
  normal state.

### Base rate
The effective `base_rate_cents` per in-period day is `EmploymentResolver::on($employee, $date)
?->base_rate_cents` (the greatest `effective_from <= date`). If the rate is constant across the
period (the common case), the export carries that single value. If it changed mid-period, the
export carries the **distinct** effective rates that applied, as
`base_rate_cents: <period-end effective rate>` PLUS a `base_rate_segments: [{ effective_from,
base_rate_cents }]` list covering the in-period regimes — so a downstream system can apply the
right rate to the right days without this system computing money. (A single constant rate yields
a one-element segment list; the top-level `base_rate_cents` is always the period-end effective
rate for convenience.)

## Backend

Action/domain-class architecture; the aggregator is pure query + rollup, no HTTP.

### `PayrollExport` (domain aggregator)
`App\Domain\Payroll\PayrollExport` (domain-Eloquent-wrapper style, like `ApprovalQueues`):
`for(CutoffPeriod $period): PayrollExportData` — reads all `daily_attendance_summaries` (with
their `lines`) where `office_id = period.office_id AND date BETWEEN start AND end`, groups by
employee, and builds the per-employee structure above. Returns a plain, serializable value
object (`PayrollExportData` + `PayrollEmployeeLine`/`PayrollEarningsLine` readonly DTOs) — pure,
deterministic, no DB writes. Line grouping is a `groupBy` over `(kind, applied_bp,
rule_version_id)` summing `minutes`.

### `ExportCutoffController` + route
`GET /office/cutoffs/{period}/export` (`auth:sanctum`):
- Resolve `{period}` scoped to the caller's administered offices — `OfficeScope::administers`
  (404-not-403 for a foreign/nonexistent period), mirroring `ReopenCutoffController`.
- **Refuse an open period** — throw `PeriodNotExportable` (422, `'period_not_exportable'`) if
  `period.state !== closed`. (An export is only defined for a finalized period.)
- Return `PayrollExportResource::make(PayrollExport::for($period))`.

No new permission — same `cutoff.manage`/`OfficeScope` boundary as the other cutoff endpoints.

### `PayrollExportResource`
Serializes `PayrollExportData` to the JSON shape in "What the export contains", with the period
header (`{ id, office_id, start_date, end_date, state }`). Calendar dates as `YYYY-MM-DD`.

## Frontend

Carbon, React-Query through `keys.ts`, mirroring the `/office/cutoffs` and other office-admin
screens.
- Off `/office/cutoffs`, a **"View export"** action on each **closed** period row → a review
  screen (a route like `/office/cutoffs/[period]/export`, or an in-page panel — implementer's
  choice matching the codebase's routing pattern).
- The screen: a per-employee section showing the earnings lines
  (`kind · applied_bp% · minutes`, grouped, with the rule version reachable) and the day-level
  totals; the `has_incomplete_days` flag surfaced as a warning tag where set. The line labels
  reuse the calendar's `SummaryLineKind` copy so the two read identically.
- `keys.payrollExport.forPeriod(periodId)`; `api.cutoffs.export(periodId)`; a `usePayrollExport`
  query hook.

## Error handling
Envelope unchanged. An open period → 422 `period_not_exportable`. A foreign/nonexistent period
→ 404 (no existence leak). The FormRequest (if any) validates shape only.

## Testing
- **Backend (real Postgres):**
  - `PayrollExport::for` rolls up a seeded closed period correctly: per employee, the export's
    per-`(kind, applied_bp, rule_version_id)` minutes **equal** the summed `daily_summary_lines`,
    and the `totals` equal the summed day scalars — the reconciliation guarantee, asserted
    exactly.
  - Grouping: a period straddling two `pay_rules` versions yields separate lines per
    `rule_version_id` (not merged); a `leave_with_pay`-only day groups under `rule_version_id =
    null`.
  - Membership, not label: a `computed` (unlocked) in-period summary IS included (proving the
    export doesn't filter on `status = 'locked'`); an out-of-period day is excluded; an
    in-period `is_incomplete` day sets `has_incomplete_days` and contributes its (zero-worked)
    scalars without inventing lines.
  - Base rate: constant rate → single segment; a mid-period `base_rate_cents` change → the
    distinct effective segments, with the top-level rate = period-end effective.
  - Endpoint: an HR admin over the office can export a **closed** period (200, the resource
    shape); an **open** period → 422 `period_not_exportable`; a foreign/nonexistent period →
    404; an unauthorized caller matches the office-scope discipline the sibling cutoff endpoints
    use.
- **Frontend:** the export review screen renders the per-employee lines + totals, surfaces the
  incomplete flag, and the line labels match the calendar's `SummaryLineKind` copy; the "View
  export" action appears only on closed periods.
- **`scripts/e2e-payroll-export.sh`:** live — close a period → `GET /office/cutoffs/{period}/
  export` → assert the per-employee line totals reconcile against `/me/attendance/summary` for
  those employees over the same dates; re-hit the export and assert it is **byte-identical**
  (the period is locked, so the numbers are stable — the reproducibility the done-when asks
  for); an open period's export is refused `period_not_exportable`.

## Done when
An HR admin exports a closed period and gets, per employee, the earnings breakdown in minutes +
basis points with each line's `rule_version_id`; the export reconciles line-for-line against the
calendar view; a re-export of the locked period is byte-identical; and an open period's export
is refused. Backend + frontend suites green; `scripts/e2e-payroll-export.sh` runs live, exit 0.
**With M7b merged, M7 is complete.**

## Explicitly deferred (with the slice/milestone that owns it)
- **CSV / file download** of the export — the endpoint + review screen is the M7b core; a
  downloadable artifact (column schema, escaping, a download route) is a later addition.
- **Peso gross earnings** (base_rate × minutes × applied_bp via `Money`) — out of scope; this
  system does the hours, not the gross-to-net. `base_rate_cents` is exported as reference only.
- **Open-period draft/preview** export — closed-only now; a draft that can still change under
  recompute is a softer, later feature.
- **Full-roster export** (including zero-attendance employees) → **M8** (a roster concern, not
  an attendance one).
- Fully closing the M7a **zero-summary-employee-racing-close** residual — out of M7b's scope;
  M7b's membership-based read already ensures a leaked `computed` row is *included* in the
  export (the reason M7a chose membership over the `locked` label), which is the M7b-relevant
  half of that note.
