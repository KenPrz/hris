# M5a — Compute Engine (core) design

> The pivot milestone's first slice: `ComputeDailySummary` turns one employee-day of raw
> punches into a premium-weighted-hours breakdown — the first thing in the system that
> makes punches into pay. Reads M3 punches + M4 config; runs the M1 primitives; writes
> `daily_attendance_summaries` + `daily_summary_lines`, stamped with the `rule_version_id`
> that produced it. **Stores minutes and basis points — never a centavo.** The queued
> multi-date recompute is M5b; this slice computes one date, synchronously, on write.

## Why this exists

M1–M4 assembled every input and the math, but nothing computes yet. `PayMultiplier`,
`OvertimeThreshold`, `NightDiffSplitter`, `PunchPairer`, `MealBreakPolicy`, `WorkInterval`,
`Minutes` (M1) are built and unit-tested; `ScheduleResolver` (M4b), the `holidays`
calendar (M4a), and `pay_rules` (M4c) resolve a date's schedule, day-type, and multipliers.
M5a is the assembly: the action that reads them and writes the authoritative daily record
M6 (requests/approvals) and M7 (cutoffs/export) build on.

## Decisions locked in brainstorming

1. **Two slices.** M5a = the compute core + a read + a screen. M5b = `RecomputeRange` (the
   queued, audited multi-date recompute + config-change triggers).
2. **Output = integer minutes + basis points, no pesos.** The summary stops at
   premium-weighted hours; the peso conversion (× base rate) is payroll's job. Consistent
   with the product's scope ("the hours, not the gross-to-net").
3. **Compute is synchronous, single-date, on write.** A punch or approved adjustment for
   `(employee, date)` computes that one summary in the same request. The queue arrives in
   M5b for the config-change fan-out.
4. **Summary + lines**, not one wide row — the export iterates premium lines and M6 adds a
   `leave` line kind; mirrors the `pay_rules`/`pay_rule_day_rates` shape.

## Global constraints (inherited, non-negotiable)

- Laravel 13 / PHP 8.5 / PostgreSQL 18; Next 16 / React 19 / TS.
- `declare(strict_types=1);` every PHP file; actions final, own their transaction, no HTTP;
  Domain framework-agnostic.
- **Integer minutes / basis points, never a float, in any layer.** All rounding through
  `Money::fraction` / `BasisPoints` composition.
- **`is_art82_exempt` gates every premium** — a managerial/field employee gets no OT, no
  night differential, no holiday premium (every bucket collapses to 100%). Read it first.
- **The append-only ledger is read alongside, never over.** Compute reads the *effective*
  punches (`attendance_logs` minus M3.6 annulments, plus approved-adjustment corrections);
  it never mutates a punch row.
- String columns + backed enums + CHECK; uuid v7 PKs; uuid FKs; activity_log uuid morph.
- Calendar dates on the wire are `YYYY-MM-DD` strings; office-local via `offices.timezone`.
- Tests run against **real PostgreSQL, never SQLite.**
- Commit messages carry **no attribution trailers.**

---

## Section 1 — Data model

### `daily_attendance_summaries` (one row per `(employee_id, date)`)
- `id` uuid v7 PK, `employee_id` uuid FK (cascade), `date` date, `unique(employee_id, date)`.
- **Context, snapshotted at compute:** `day_type` (`DayType` text + CHECK, `ordinary` when no
  holiday), `is_rest_day` boolean, `scheduled_minutes` int, `is_art82_exempt` boolean
  (snapshotted — gates every premium), `rule_version_id` uuid FK → `pay_rules(id)`
  **`ON DELETE RESTRICT`** (a consumed version can never be orphaned — closes the M4c seam).
- **Day-level minutes:** `worked_minutes`, `late_minutes`, `undertime_minutes` (all ≥ 0 CHECK).
- **State:** `status` text + CHECK (`pending`|`computed`|`disputed`|`locked`), `is_incomplete`
  boolean, `computed_at` timestamptz nullable, timestamps.
- `LogsActivity` (log name `daily_attendance_summary`).

`rule_version_id` is **nullable**: an incomplete day (early return at step 2, before any
version is resolved), a rest-day-unworked, or a no-schedule day prices nothing and stores
`null`. Any summary that has lines has a non-null `rule_version_id`. The FK `ON DELETE
RESTRICT` still fires for every stamped (non-null) version, so a consumed version can never
be orphaned regardless.

### `daily_summary_lines` (one row per non-zero premium bucket; child, cascade)
- `id` uuid v7 PK, `summary_id` uuid FK (cascade), `kind` text + CHECK
  (`regular_day`, `regular_night`, `overtime_day`, `overtime_night`, `holiday_unworked`),
  `minutes` int (> 0 CHECK — zero buckets are simply absent), `applied_bp` int (≥ 0 CHECK).
- `unique(summary_id, kind)`.

`applied_bp` is the *compound* multiplier the bucket earns: a regular-holiday OT minute at
2 a.m. carries `200% × OT-factor × night` folded into one integer. "Night differential" and
"holiday premium" as export lines (M7) are derived from these buckets and their `applied_bp`
(the delta over 100%) — never stored separately. No pesos anywhere.

---

## Section 2 — `ComputeDailySummary` (the engine)

`App\Actions\Compute\ComputeDailySummary::execute(Employee $e, string $date):
DailyAttendanceSummary` — one transaction, **idempotent** (deletes the day's existing
summary + lines and re-inserts, so a recompute is a clean re-run; makes M5b trivial).

1. **Effective punches** — `attendance_logs` for `(employee, date)` in `offices.timezone`,
   minus M3.6 annulments, plus approved-adjustment corrections → a list of minutes-from-
   local-midnight (an after-midnight night-shift punch may exceed 1440, per `WorkInterval`).
2. **Pair** (`PunchPairer::pair`). If `hasUnpaired()` → `is_incomplete = true`,
   `worked_minutes = 0`, **no lines**, `status = computed`. Return.
3. **Resolve the day** — `DayType` from `holidays` (`ordinary` if none); `ScheduleResolver::
   resolve` → `isRestDay` + `scheduledMinutes`; `is_art82_exempt` from the
   `employment_records` effective on `date`; the `pay_rules` version with the greatest
   `effective_from ≤ date` → `rule_version_id` + a `PayRates` matrix (Section 3).
4. **Net the meal break** (`MealBreakPolicy` from the schedule's break) → net worked minutes.
5. **Regular vs OT** — `OvertimeThreshold::split(worked, scheduledMinutes)` → `WorkedSplit`
   (regular vs OT minutes); `OvertimeThreshold::undertime(worked, scheduled)`; `late` =
   first paired in-punch minus scheduled start, floored at 0.
6. **Night split** — each worked interval through `NightDiffSplitter::split` (window
   22:00–06:00, `1320`–`360`+1440) → inside/outside minutes. Cross with regular/OT → the
   four buckets: `regular_day`, `regular_night`, `overtime_day`, `overtime_night` (minutes).
7. **Edge cases** — rest-day-worked: normal buckets (the premium is in the multiplier).
   Rest-day-unworked: no lines. Paid-holiday-unworked (regular/double, not `art82`, worked 0
   on a scheduled day): one `holiday_unworked` line of `scheduledMinutes`.
8. **Apply multipliers** — each bucket's `applied_bp` =
   `PayMultiplier::forWorkedTime(dayType, isRestDay, isOvertime, isNight, isArt82Exempt)`
   over the resolved `PayRates` (Section 3); `holiday_unworked` uses `forUnworkedDay`.
   **`is_art82_exempt` collapses every premium to `BasisPoints::one()`** — buckets carry
   base only; no OT/night/holiday premium is ever added.
9. **Write** the summary + only the non-zero lines, `status = computed`, `computed_at` now.

**Triggering:** after they commit, the punch action and the adjustment-approval action call
`ComputeDailySummary::execute(employee, affectedDate)` synchronously (one after-commit call
each). A punch or approved correction always leaves a fresh summary.

---

## Section 3 — The `PayMultiplier` ↔ `pay_rules` reconciliation

`PayMultiplier` hardcodes `WORKED_BASE`/`UNWORKED` today (M1). M5a refactors
`forWorkedTime`/`forUnworkedDay` to **receive a rate matrix**, exactly as `StatutoryFloor`
receives the floor matrix — `PayMultiplier` becomes pure composition given rates.

A new `App\Domain\Pay\PayRates` value object (the rate matrix) has two constructors:
- `PayRates::fromVersion(PayRule $v): self` — what `ComputeDailySummary` uses (the effective
  configured rates).
- `PayRates::statutory(): self` — reads `config('hris.pay_floors')`, for the M1 unit tests
  and any default path.

The hardcoded `PayMultiplier` constants are removed; the M1 `PayMultiplierTest` is updated to
pass `PayRates::statutory()` (an expected, in-scope signature change). Because M4c's floor
CHECK refuses any below-floor `pay_rules` write, the rates `PayMultiplier` reads are always
≥ the statutory minimums — the guarantee that made the floor live in code holds end to end.

---

## Section 4 — Endpoints & screen

- `GET /me/attendance/summary?month=YYYY-MM` → the caller's own computed summaries + lines
  for the month, self-scoped like `/me/attendance` (a `SummaryResource`: the day-level facts
  + the lines array). Manager/HR reads arrive with the approval/cutoff milestones.
- **Screen:** the existing `/me/attendance` month calendar gains a **computed layer** — each
  day shows `worked_minutes` with a small badge (`incomplete` / `OT` / `premium`), and a
  day-detail panel shows the breakdown lines (regular / night-diff / OT / holiday premium)
  **alongside the raw punch times** (the ledger stays visible — the computed number never
  replaces the honest record). Reuses `MonthCalendar` (`renderDay`) and `Duration`; a
  `useMyAttendanceSummary(month)` hook + `keys.attendance.summary(month)`.

---

## Section 5 — Testing

**Backend, real Postgres, table-driven — the crown jewel.** A scenario matrix asserting the
exact minute buckets + `applied_bp` for each:
- ordinary 8h → `regular_day` 480 @ 10000.
- rest-day worked → buckets @ 13000.
- special-non-working worked → @ 13000; special-working → @ 10000.
- regular-holiday worked → @ 20000; regular-holiday *unworked* (scheduled) → `holiday_unworked`
  @ 10000; double-regular-holiday worked → @ 30000.
- a night shift (e.g. 22:00–06:00) → `regular_night` carrying the compounded ×11000.
- OT beyond schedule → `overtime_day` @ +25% (ordinary) / +30% (premium); a compressed 10h
  scheduled day → hour 9 still `regular`, not OT.
- incomplete (unpaired punch) → `worked_minutes 0`, `is_incomplete`, no lines.
- **`is_art82_exempt` → every bucket @ 10000** (no premium anywhere).
- undertime / late populated correctly.

Plus: the **reconciliation** — a custom `pay_rules` version (e.g. holiday at 250%) changes the
computed `applied_bp`, proving compute reads `pay_rules`, not constants; **`rule_version_id`
RESTRICT** — deleting a consumed version is refused; **idempotent recompute** — computing
twice yields identical rows + no duplicate lines; **sync-on-punch** — a punch leaves a fresh
summary; `PayRates::statutory()` equals the M1 statutory matrix. `scripts/e2e-compute.sh`
runs the whole path live. **Frontend:** the computed layer renders totals + badges + the
day-detail breakdown; the raw punches stay visible.

---

## Done when

A seeded Manila employee punches a normal 8-hour day → a `computed` summary with a
`regular_day` line of 480 minutes @ `10000`, stamped with the effective `rule_version_id`;
the same employee on Aug 21 (Ninoy Aquino Day, the M4a-seeded special-non-working day) →
worked minutes @ `13000`; a night shift → `regular_night` at the compounded night rate; an
`is_art82_exempt` manager → every bucket at `10000`; an incomplete day → zero worked +
`is_incomplete`; deleting the `pay_rules` version a summary was stamped with is refused
(`RESTRICT`); computing the same day twice is identical. **No pesos are stored anywhere.**
`PayMultiplier` reads `pay_rules` (the reconciliation), and the roadmap's stale section
headings are renumbered to match the authoritative resequencing table. Full suite green,
`scripts/e2e-compute.sh` passes live. M5b (`RecomputeRange`) builds the queued multi-date
recompute on this foundation.
