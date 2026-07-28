# M7a — Cutoffs & locking (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M7a — the first slice of M7 (Cutoffs, locking, payroll export). Slicing:
**M7a cutoffs + locking → M7b payroll export.**
**Depends on:** M5/M5b (the compute engine, `daily_attendance_summaries` with its `locked`
status value, `ComputeDailySummary`'s per-employee row lock, `RecomputeDay`'s locked-skip and
`RecomputeRange`), M6a/M6b-b/M6c (the request spine — `ApproveRequest`, the `RequestEffect`
types, the three detail tables), and M2 (`offices`, `OfficeScope`, `hr_admin_offices`, RBAC
with the already-seeded `cutoff.manage` permission).

## Goal

Make a period's numbers *freezable*. An HR admin closes a semi-monthly cutoff period for an
office; closing refuses while unresolved exceptions remain, and otherwise locks every summary
in the period. Once locked, an approval whose effect would change a locked day is refused with
a domain error rather than silently succeeding, and no recompute can overwrite a locked
summary. `ReopenCutoff` (reason-required, loudly audited) unlocks. This is the half of M7 that
makes the number defensible; M7b turns a locked period into an exported earnings breakdown.

The `daily_attendance_summaries.status` column already carries `locked` as a legal value, and
`RecomputeDay` already skips a locked summary — M7a is what actually *sets* that status, under
the locks that make the setting race-safe.

## Decisions (from brainstorm)

1. **Slicing:** M7a (cutoffs + locking) → M7b (payroll export). Each ships and tests on its own.
2. **Close gate is strict:** a close is refused if the period has ANY incomplete-day summary
   (`is_incomplete = true`) OR ANY non-terminal request (state `pending` or `manager_approved`
   — attendance adjustment, leave, or overtime) mapping onto an in-period date. You can't
   freeze a number still waiting on a decision.
3. **Lock policy is approvals-only:** on a closed period, an *approval* whose effect would
   change a locked in-period day is refused (`CutoffLocked`, 422). Raw punches still append —
   the append-only log is what an inspector sees — and `RecomputeDay` already skips a locked
   summary, so they never re-price it. Refusing raw punches (which would break append-only) is
   explicitly NOT done.

## Data model

House rules throughout: uuid v7 PK (`DB::raw('uuidv7()')` default + `HasUuids`), string columns
+ PHP backed enums + `CHECK` constraints (never a native Postgres enum; pinned to `Enum::cases()`
by a schema test), integer everything, `timestamptz`, calendar dates as `date`.

### `cutoff_periods` (new) — per-office, semi-monthly
- `id`, `office_id` (FK offices, cascade), `start_date` (date), `end_date` (date),
  `state` (text CHECK `IN ('open','closed')`, default `'open'`).
- `closed_by` (FK users, nullable, `nullOnDelete`), `closed_at` (timestamptz nullable).
- `timestampsTz()`; `LogsActivity` (close and reopen both land in the activity log, with the
  reopen reason as a log property — belt-and-suspenders with the state column).
- **Unique `(office_id, start_date)`** — one period per office per semi-monthly window.
- CHECK `end_date >= start_date`.
- A `CutoffState` PHP enum (`Open`, `Closed`) backs the column, pinned to the CHECK by a schema test.

### Period ↔ summary relationship — derived, not stamped
A summary belongs to the period whose `(office_id, [start_date, end_date])` contains its
`(office_id, date)`. **No `cutoff_period_id` FK on the summary** — the membership is a
date-range query (`daily_attendance_summaries` already carries `office_id` since M5b), and
denormalizing it would create a second source of truth to keep consistent across recompute.
The lock/close operations query summaries by `office_id` + `date BETWEEN start AND end`.

### `CutoffCalendar` (domain) — the window rule
`App\Domain\Cutoff\CutoffCalendar`: pure, no DB. `windowFor(string $date): array{start,end}`
returns the semi-monthly window containing a date — **1–15** and **16–EOM** (EOM resolved per
month, including Feb 28/29). `isValidStart(string $date): bool` — a period start is the 1st or
the 16th. Per-office custom schedules (weekly, monthly, arbitrary) are deferred; semi-monthly is
the roadmap's stated default and the only rule M7a implements.

### Period creation — lazy
Period rows are created **open** on first reference: `EnsureCutoffPeriod(officeId, date)` (or an
equivalent find-or-create inside `CloseCutoff` and the list view) resolves the window via
`CutoffCalendar` and returns the existing-or-newly-created open row. There is no eager
generator; a window with no row yet is implicitly open.

## Backend

Action-class architecture (final, own transaction, Input DTO, returns a domain object, no HTTP).

### `CloseCutoff`
`execute(CloseCutoffInput{officeId, periodStart, actorId}): CutoffPeriod`. In one transaction:
1. Validate `periodStart` is a real semi-monthly boundary (`CutoffCalendar::isValidStart`);
   resolve the `[start,end]` window.
2. Ensure + `lockForUpdate` the `cutoff_periods` row (find-or-create open). Refuse with
   `CutoffAlreadyClosed` (409) if already `closed`.
3. **Strict exception gate** — throw `CutoffHasUnresolvedExceptions` (422, carrying the blocking
   detail: the incomplete dates and the blocking request ids) if EITHER:
   - any `daily_attendance_summaries` row for `(office, date ∈ [start,end])` has
     `is_incomplete = true`; OR
   - any non-terminal request (`state IN ('pending','manager_approved')`) maps onto an in-period
     date for an employee whose summary/office is this office — resolved by
     `RequestAffectedDates` (below): an attendance adjustment's punch business-date, a leave's
     `[start_date,end_date]` overlapping the window, an overtime's `date`.
4. **Lock the period's summaries and freeze them.** For each affected employee in the period,
   take the employee row lock (`Employee::lockForUpdate()` — the SAME lock `ComputeDailySummary`
   holds) before locking + updating that employee's in-period summaries to `status = 'locked'`.
   This is what serializes a close against a concurrent recompute/approval (see Concurrency).
5. Set the period `state = 'closed'`, `closed_by`, `closed_at`. Audited.

### `ReopenCutoff`
`execute(ReopenCutoffInput{periodId, reason, actorId}): CutoffPeriod`. Requires a non-empty
`reason` (else `ReopenReasonRequired` / FormRequest 400). Lock the period; refuse with
`CutoffNotClosed` (409) if it is `open`. Under the same per-employee row locks, set the period's
summaries `locked → computed`, set the period `state = 'open'` (clear `closed_by`/`closed_at`),
and record the reopen in the activity log **with the reason** (loudly audited).

### `RequestAffectedDates` (domain) — the request→date(s) map
`App\Domain\Cutoff\RequestAffectedDates`: given a request, returns the calendar date(s) its
effect would change — attendance adjustment → the target punch's business date (from
`punched_at` / the target log); leave → every date in `[start_date, end_date]`; overtime → the
single `date`. Used by BOTH the close gate (to find in-period pending requests) and the approval
refusal (to check whether a specific request touches a locked day). Domain-Eloquent-wrapper
style, like `LeaveDayLookup`/`OvertimeAuthorizationLookup`.

### The approval refusal — `ApproveRequest`
`ApproveRequest` currently: lock request → authority → terminal → hop/effect. M7a inserts, on the
**final hop only** (the hop that actually fires the effect), before `applyOnApproval`:
- Take the affected employee's row lock (`Employee::lockForUpdate()`), then resolve the request's
  affected dates (`RequestAffectedDates`) and check whether any falls in a **closed** period for
  that employee's office. If so, throw `CutoffLocked` (422) — the whole transaction rolls back,
  the request stays in its prior state, no effect applies.
- Taking the employee lock here is what makes the check race-safe against `CloseCutoff` (see
  Concurrency). The lock is only needed on the final hop (a manager's hop-1 advance writes no
  effect and touches no summary, so it is never cutoff-gated).

### Concurrency (the crux — two genuine two-real-Postgres-connections tests)
Both mirror `ApproveRequestConcurrencyTest` (a second real connection via `proc_open` holding a
row lock) — a single-process sequential test would pass whether or not the lock exists, which
`04-backend-conventions.md` calls worse than no test.

1. **Approval vs. close.** `ApproveRequest` (final hop) and `CloseCutoff` both take the affected
   **employee row lock** — the exact lock `ComputeDailySummary` already uses to serialize
   per-employee summary writes. Whichever transaction commits first wins: close-first → the
   approval, on acquiring the lock, sees the day's period `closed` and throws `CutoffLocked`;
   approval-first → the effect's recompute runs, and close (on acquiring the lock) freezes the
   freshly-recomputed summary. Test: connection A holds the employee lock mid-close; connection
   B's approval blocks, then refuses after A commits.
2. **Close vs. recompute.** `RecomputeDay`'s locked-skip is today a plain unlocked read (safe in
   M5b only because nothing set `locked` yet). Hardening: `ComputeDailySummary` (which
   `RecomputeDay` calls) already locks the employee row before its delete-then-insert; M7a adds a
   **re-read of the target summary's status after acquiring that lock**, aborting the recompute
   if it is now `locked`. Because `CloseCutoff` locks the same employee row before setting
   `locked`, the two serialize: a close racing an in-flight recompute either (a) the recompute
   commits first and close freezes the result, or (b) close commits first and the recompute, on
   acquiring the lock, re-reads `locked` and aborts — never overwriting a frozen summary. Test:
   two connections, one closing, one recomputing the same employee-day.

## Routes / RBAC
All `auth:sanctum`, gated by `cutoff.manage` (already seeded to HR Admin) + `OfficeScope`,
404-not-403 for an out-of-office period.
- `GET /office/cutoffs?office={id}` — the office's periods (closed rows + the current open
  window, computed).
- `POST /office/cutoffs/close` — body `{office_id, period_start}`.
- `POST /office/cutoffs/{period}/reopen` — body `{reason}`.
- The approval refusal surfaces as `CutoffLocked` (422 `cutoff_locked`) on the existing
  `POST /requests/{request}/approve` — no new route.

## Frontend
Carbon, React-Query through `keys.ts`, mirroring the M4/M6 office-admin screens. Tight — the
export screen is M7b.
- **`/office/cutoffs`** (HR) — the office's periods with state and dates; a **Close period**
  action (on refusal, surface the blocking exceptions — incomplete dates + pending request
  count — via `InlineNotification`); a **Reopen** action with a required-reason prompt.
- The existing approval UI surfaces the `cutoff_locked` error inline when an approval hits a
  locked day (no new screen — the RequestCard/queue error path already renders a domain error).
- `keys.cutoffs`: `list(officeId)`. `useCloseCutoff` / `useReopenCutoff` invalidate it (and the
  approvals keys, since a close changes what's approvable).

## Error handling
Envelope unchanged. `CutoffHasUnresolvedExceptions` (422, with blocking detail),
`CutoffAlreadyClosed` (409), `CutoffNotClosed` (409), `CutoffLocked` (422),
`ReopenReasonRequired` (400 via FormRequest / 422 domain). Out-of-office close/reopen/list → 404.
FormRequests validate shape only (`office_id`/`period_start`/`reason`), never `exists:`.

## Testing
- **Backend (real Postgres):** `cutoff_periods` schema (CHECK pinned to `CutoffState::cases()`,
  the `(office_id,start_date)` unique, the date CHECK); `CutoffCalendar` window math (1–15,
  16–EOM across 28/29/30/31-day months incl. Feb, and `isValidStart`); `CloseCutoff` — the strict
  gate blocks on an incomplete day and on a pending adjustment/leave/overtime mapping into the
  window, and a clean period closes and flips its summaries to `locked`; `ReopenCutoff` — reason
  required, unlocks `locked → computed`, audited with the reason; the approval refusal — approving
  a leave/overtime/adjustment on a locked in-period day throws `CutoffLocked`, and an
  out-of-period approval is unaffected; `RequestAffectedDates` per type; and **both
  two-real-connections concurrency tests** (approval-vs-close, close-vs-recompute), each verified
  to FAIL with the lock removed.
- **Frontend:** the `/office/cutoffs` list + close (with the refusal surfacing the blocking
  exceptions) + reopen (reason required, invalidation); the approval UI rendering `cutoff_locked`.
- **`scripts/e2e-cutoffs.sh`:** live — compute a clean period → `POST /office/cutoffs/close`
  (summaries flip to `locked`) → an approval on an in-period day is refused `cutoff_locked` →
  `POST /office/cutoffs/{period}/reopen` with a reason (summaries back to `computed`) → the same
  approval now succeeds → the append-only `attendance_logs` are byte-identical throughout.

## Done when
An HR admin closes a period; the close is refused while an incomplete day or a pending in-period
request remains; a clean period closes and its summaries read `locked`; an approval on a locked
day is refused with `CutoffLocked` rather than silently succeeding; a recompute cannot overwrite
a locked summary (proven by the two-connection test); and `ReopenCutoff` (reason-required,
audited) unlocks. Backend + frontend suites green; `scripts/e2e-cutoffs.sh` runs live, exit 0.

## Explicitly deferred (with the slice that owns it)
- **Payroll export** — the per-employee-per-period earnings breakdown (regular/late/undertime/OT/
  night-diff/holiday/leave-with-pay in minutes + basis points with `rule_version_id`), and the
  line-for-line reconciliation against the calendar view → **M7b**.
- **Per-office cutoff schedule config** (weekly/monthly/custom windows) — semi-monthly is the
  default and the only rule now; a configurable schedule is a later addition.
- **Raw-punch-on-locked-day policy** — punches remain append-only and are never refused; a locked
  summary simply is not recomputed. A future "disputed"/correction-after-lock flow is out of scope.
- **Cross-office / company-wide close** — close is per-office; a batch "close all offices" is later.
