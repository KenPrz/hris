# M5b — RecomputeRange (design)

> The compute engine's consistency layer. M5a computes a daily summary synchronously when a
> **punch** lands; M5b keeps those summaries consistent when **config** changes. A holiday,
> pay-rule, or schedule edit never silently mutates computed history — it enqueues an
> **audited recompute** of exactly the affected *existing* `(employee, date)` summaries, which
> re-run `ComputeDailySummary`. Completes M5.

## Why this exists

M5a's trigger fires on `RecordPunch` / an approved adjustment — the only writers of the punch
ledger. But a summary also depends on config it didn't write: the day's `DayType` (holidays),
the schedule (`ScheduleResolver`), and the rates (`pay_rules`). When an admin edits any of
those, every already-computed summary that depended on it is now stale. RecomputeRange is the
audited, queued fan-out that refreshes them. It is the mechanism behind M4's original "done
when": *HR adds Aug 21 as a holiday, recompute runs, affected days flip 100% → 130%.*

## Decisions locked in brainstorming

1. **Recompute only EXISTING summaries in scope.** A config change re-runs
   `ComputeDailySummary` for every `(employee, date)` that already has a
   `daily_attendance_summaries` row within the affected scope. Naturally bounded — never a day
   nobody worked. Creating a summary for a never-computed day (a no-show's unworked-regular-
   holiday 100% pay) needs a separate "compute the scheduled period" driver, **deferred to M7**.
2. **One cohesive backend subsystem, no frontend.**
3. **Over-inclusion is safe, missing is not.** Because `ComputeDailySummary` is idempotent
   (delete-and-reinsert under an employee-row lock), a resolver may return a *superset* of the
   truly-affected existing summaries — the extra recomputes re-produce identical rows at the
   cost of a job. Resolvers stay simple; correctness rides only on not *missing* an affected
   summary.

## Global constraints (inherited)

- Laravel 13 / PHP 8.5 / PostgreSQL 18. `declare(strict_types=1);` everywhere. Actions final,
  own their transaction, no HTTP. Domain framework-agnostic.
- **Integer minutes / basis points, never floats.** No pesos.
- **The append-only ledger is read alongside, never over** — a recompute rewrites the derived
  `daily_attendance_summaries`, NEVER an `attendance_logs` row.
- uuid v7 PKs, string + backed enum + CHECK, `LogsActivity`, activity_log uuid morph.
- The **database queue** is already configured (`QUEUE_CONNECTION=database`).
- Tests run against **real PostgreSQL, never SQLite**; queued-job behavior asserted via
  `Bus::fake`/`Queue::fake` and by running jobs.
- Commit messages carry **no attribution trailers**.

---

## Section 1 — The recompute mechanism

A config-change action, after it commits, hands `RecomputeRange` a description of what changed;
it resolves the affected existing summaries into a deduped `(employee_id, date)` set and
enqueues an audited batch.

### `RecomputeDay` (queued job)
- `handle()` calls `ComputeDailySummary::execute($employee, $date)`. Idempotent, so a duplicate
  or retried job is harmless.
- **Skips a `locked` summary:** if the existing summary for `(employee, date)` has
  `status = 'locked'`, the job returns without recomputing (M7's cutoffs freeze a locked
  period; the guard is present from day one though nothing is locked yet).

### `RecomputeRange` + the batch
- Resolves the affected existing-summary pairs (Section 2), **dedups** them, and dispatches a
  Laravel **`Bus::batch`** of `RecomputeDay` jobs on the database queue.
- The batch gives progress + completion tracking for free; its `batchId` links to the audit row.
- **Empty is a no-op:** a change affecting zero existing summaries (a holiday on a future date
  nobody worked) enqueues nothing and records nothing — not an error.

### `recompute_runs` (the audit)
- uuid v7 PK, `trigger_type` (text + CHECK: `holiday`|`pay_rule`|`shift_template`|
  `schedule_assignment`|`schedule_override`|`office_default`), `trigger_id` uuid nullable,
  `reason` text, `pair_count` int, `batch_id` text nullable, `status` (text + CHECK:
  `queued`|`completed`|`failed`), `caused_by` uuid FK users nullable, timestamps. `LogsActivity`.
- Answers "why did this day's numbers change, and when" — the roadmap's "a recompute that is
  itself audited." Marked `completed` on the batch's `then`/`finally` callback (or `failed`).

---

## Section 2 — The affected-set resolvers

The job: given a config change, find the **existing** `daily_attendance_summaries` rows it
touches. One resolver per config type; each intersects with rows that already exist.

### Schema addition
Add **`office_id`** (uuid FK, nullable) to `daily_attendance_summaries`, snapshotted at compute
(the office `ComputeDailySummary` resolved the day against, from the effective employment
record). Makes office-scoped resolution a trivial indexed query, no employment-record join at
recompute time. (`ComputeDailySummary` already resolves `$officeId` — it now persists it.)

### The resolvers (`App\Domain\Compute\RecomputeResolvers` or per-type methods on `RecomputeRange`)
- **Holiday** (create/update/delete/clone) — office `O`, date(s) `D` →
  `summaries WHERE office_id = O AND date IN (D…)`.
- **PayRule** — a new version effective `F` → `summaries WHERE date >= F` (each re-resolves to
  the now-effective version; dates governed by a still-later version re-resolve unchanged).
- **ShiftTemplate** edit → the employees on that template (an employee/department assignment to
  it, or an office whose `default_shift_template_id` is it) × their existing summaries.
- **ScheduleAssignment** (create/delete) → the target employee(s) × their existing summaries.
- **ScheduleOverride** (create/update/delete) → that one `(employee, date)` summary, if it exists.
- **OfficeDefault** set → employees in office `O` × their existing summaries.

Resolvers return `(employee_id, date)` pairs from real rows only; a superset is acceptable
(idempotency), so precision on the affected *dates* is not required — only completeness.

---

## Section 3 — Trigger wiring + the night-shift fix

### Wiring
Each config-change action, **after it commits** (`DB::afterCommit`, the M5a idiom), calls its
`RecomputeRange` resolver + dispatch — so an enqueue failure can never roll back the config
write. The ~15 actions: `Holidays/{CreateHoliday,UpdateHoliday,DeleteHoliday,CloneHolidays}`;
`PayRules/CreatePayRule`; `Schedules/{CreateShiftTemplate,UpdateShiftTemplate,DeleteShiftTemplate,
CreateScheduleAssignment,DeleteScheduleAssignment,CreateScheduleOverride,UpdateScheduleOverride,
DeleteScheduleOverride,SetOfficeDefaultTemplate}`. Each passes the changed entity's identifying
context (office/date, version, template/employee) to the matching resolver.

### The night-shift fix (M5b owns this fan-out)
RecomputeRange is the first thing to drive `EffectivePunches` across *ranges* of consecutive
days, surfacing the overlap M5a recorded: consecutive night shifts produce overlapping
business-day windows, so a single punch could be claimed by two adjacent days and
double-counted. Fix in `EffectivePunches`: **bound a day's window start at the previous day's
resolved window end**, so consecutive windows tile without overlap. A punch is claimed by
exactly one day.

---

## Section 4 — Testing

**Backend, real Postgres + queued-job assertions:**
- **Each resolver** returns the right existing-summary pairs: holiday → that `office_id` +
  date only (another office's same-date summary excluded); pay_rule → summaries with
  `date >= F` only; shift-template → the employees on the template × their summaries;
  assignment/override/office-default → the right employees × their existing summaries. A
  future-dated config change with no existing summaries → empty, no-op.
- **`RecomputeRange`** dispatches a `Bus::batch` of `RecomputeDay` for exactly the **deduped**
  pairs (`Bus::fake` — a pair touched by two overlapping resolvers is enqueued once).
- **`RecomputeDay`** runs `ComputeDailySummary` and **skips a `locked` summary** (seed a
  locked summary, run the job, assert it's unchanged).
- **The audit** — a `recompute_runs` row is created with the trigger/scope and marked
  `completed` after the batch runs.
- **`office_id` snapshot** — `ComputeDailySummary` persists the resolved office on the summary.
- **The night-overlap fix** — two consecutive night shifts, each with an in/out punch: each
  punch is counted in exactly one day's summary (no double-count).
- **End-to-end:** add the Aug-21 holiday → Manila's existing Aug-21 worked summaries recompute
  and flip 100% → 130% (`day_type` + line `applied_bp` change), Cebu's untouched; a new
  `pay_rules` version → summaries on/after its effective date re-price; **the raw
  `attendance_logs` rows are byte-identical throughout** (the ledger is never mutated — assert
  the punch rows are unchanged before/after).
- `scripts/e2e-recompute.sh` runs the holiday-flip path live.

---

## Done when

An HR holiday edit for Manila enqueues an **audited** recompute (`recompute_runs` row, a
`Bus::batch` of `RecomputeDay` jobs) that flips exactly the affected existing Manila summaries
100% → 130% and leaves Cebu's and every raw `attendance_logs` row **byte-identical**; a new
`pay_rules` version re-prices every existing summary on/after its effective date; a `locked`
summary is skipped; a config change with no existing affected summaries is a clean no-op; and
two consecutive night shifts count each punch exactly once. **No `attendance_logs` row is ever
mutated** — only derived summaries change. Full suite green, `scripts/e2e-recompute.sh` passes
live. **M5 — the compute engine — is complete.**
