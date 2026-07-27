# M6c — Overtime pre-authorization (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M6c — the third and final slice of M6 (Requests & approvals). Completes M6.
**Depends on:** M6a (the request spine — `RequestState`, `RequestAuthority::canDecide`,
`ApprovalQueues`, `ApproveRequest`, `RequestEffect`/`RequestEffectFactory`), M6b-b (the
two-hop machine and `RequestType::requiresHrStep()`, `leave_details` PK-is-request-id
pattern, `RequestResource` type-branching, `RecomputeRange`-on-approval), and M5/M5b (the
compute engine — `DailyComputation`, `DailyComputationInput`, `OvertimeThreshold`,
`ComputeDailySummary`, `RecomputeTrigger`).

## Goal

An employee pre-authorizes overtime for a date; their manager (or office HR) approves it;
the compute engine then pays **`min(actual_worked_overtime, approved_overtime)`** for that
day and surfaces the remainder as **unpaid excess time** — visible, never silently
converted to money and never silently vanishing. Overtime with **no** approval is unpaid:
the documented model is "overtime requires pre-authorization" (`docs/00-overview.md`), so an
unauthorized long day caps its paid overtime at zero.

This is the **third reuse of the M6a spine** and structurally the simplest: single-hop
(like an attendance adjustment, not two-hop like leave), and its `RequestEffect` writes no
ledger at all — the durable fact is the approved request plus its detail row, and the
effect merely enqueues a recompute so the compute engine re-prices the day under the new
cap.

## Decisions (from brainstorm)

1. **Unauthorized overtime is unpaid** (strict model). No approval covering a date →
   `approvedOvertimeMinutes = 0` → every worked minute beyond the overtime threshold is
   unpaid excess. This matches `docs/00-overview.md`'s "Overtime | Pre-authorization
   required." It **changes existing compute behavior**: today the engine pays all worked
   overtime; after M6c it pays `min(actual, approved)`. Existing OT compute tests must pass
   an `approvedOvertimeMinutes` covering their worked overtime to preserve their
   assertions; new tests cover the cap and the excess.
2. **Single-hop approval** (`requiresHrStep() = false`), identical routing to an attendance
   adjustment: at `pending` the employee's **manager OR office HR** can approve, and the
   effect fires on that one approval. No new state-machine, authority, or queue code —
   single-hop is already proven by attendance adjustment. A managerless requester needs no
   auto-advance (a single-hop `pending` request already surfaces to HR at `/office`).
3. **Approve-as-filed.** The manager approves the exact minutes the employee filed.
   Approving a *smaller* amount than requested is a spine change (deciding **with** an
   amount, which the decide endpoints do not do for any type today) and is **deferred**.
4. **Unpaid excess is a summary column**, `daily_attendance_summaries.unpaid_overtime_minutes`,
   sitting beside `late_minutes` and `undertime_minutes` — the same species (a non-premium
   scalar magnitude, not a priced `daily_summary_lines` row). Not a zero-basis-point line.
5. **art82-exempt short-circuits the cap.** Managerial/field personnel earn no overtime
   premium, so there is no overtime pay to withhold; the cap is not consulted for them and
   their behavior is unchanged — consistent with "every premium computation reads
   `is_art82_exempt` first."

## Data model

House rules throughout: uuid v7 PKs where a table has its own id, string columns + PHP
backed enums + `CHECK` constraints (never a Postgres native enum, pinned to `Enum::cases()`
by a schema test), integer minutes, `timestamptz`.

### `overtime_details` (new) — the request's 1:1 detail
Mirrors `leave_details` / `attendance_adjustment_details`: **the primary key IS the
request's id** (no separate id column), one request → one detail, enforced by the database.

- `request_id` uuid **primary key**, FK `requests(id)` cascade-on-delete.
- `date` — the business date the overtime is authorized for.
- `minutes` `integer` CHECK `> 0` — the requested, and (approve-as-filed) approved, overtime
  minutes.

CHECK: `overtime_details_minutes_pos_check` (`minutes > 0`). No day-part, no date-range —
overtime pre-auth is a single date.

### `daily_attendance_summaries` (modify)
- Add `unpaid_overtime_minutes` `integer NOT NULL DEFAULT 0`. The worked overtime minutes
  that fell beyond the approved cap — unpaid, shown, never priced. Zero on every day that
  had no excess (the overwhelming majority), so the default is 0 and existing rows backfill
  to 0 cleanly.

### `requests` (modify — CHECK widen only)
- Widen `requests_type_check` to include `'overtime'`. No state-check change (single-hop
  reuses the existing states).

## Backend

Action-class architecture (final, own transaction, Input DTO, returns a domain object, no
HTTP). Every piece mirrors an existing M6a/M6b-b counterpart.

### The type and its detail
- `RequestType::Overtime = 'overtime'`; `requiresHrStep()` returns `false` for it.
- `OvertimeDetail` Eloquent model (PK `request_id`, no incrementing/uuid generation on the
  model — the request owns the id, same as `LeaveDetail`); `Request::overtimeDetail()`
  hasOne. Factory + schema test pinning the CHECK.
- `RequestResource` type-branches: an `overtime` request serializes `{ date, minutes }`
  under its detail, the way it already branches leave vs. attendance-adjustment.

### Filing — `SubmitOvertimeRequest`
- `execute(SubmitOvertimeRequestInput): Request` — creates the request (`type = overtime`,
  `state = pending`, the requester's `employee_id`, optional note) and its
  `overtime_details` row, in one transaction. **Always `pending`** — no managerless
  auto-advance (single-hop; HR can act on a managerless requester's `pending` request via
  `/office`).
- `POST /overtime/requests` + `SubmitOvertimeRequestController` (final, invokable) +
  `SubmitOvertimeRequestRequest` FormRequest. Body: `date`, `minutes` (or `hours`, with the
  controller converting to minutes before calling the action). Guards: `minutes > 0` (or
  hours > 0); `date` validated **shape-only** (a real date string), never `exists:` — the
  404-not-403 existence discipline. The two-people rule (approver ≠ requester) is inherited
  from `RequestAuthority` at decide time, not re-checked here.

### The effect — `OvertimeEffect`
- `OvertimeEffect implements RequestEffect`. `applyOnApproval(Request, approverUserId)`
  writes **no** ledger and touches **no** balance — the approved request plus its
  `overtime_details.minutes` **is** the authorization. It only enqueues, via
  `DB::afterCommit` (a recompute-enqueue failure must never roll back a durable approval —
  same reasoning as `LeaveEffect`/`CreateHoliday`), a `RecomputeRange` over the single
  detail date with `RecomputeTrigger::Overtime`, so `ComputeDailySummary` re-prices that day
  under the now-approved cap.
- `RecomputeTrigger::Overtime` case added.
- `RequestEffectFactory` (bound as `RequestEffectResolver` in `AppServiceProvider`) gains the
  `Overtime => OvertimeEffect` arm. Single-hop, so `ApproveRequest` fires the effect on the
  one and only approval (it already fires on the transition into `approved`, which for a
  single-hop request is the first approval).

### Compute reads the approval
- `App\Domain\Overtime\OvertimeAuthorizationLookup::approvedMinutesFor(Employee, string $date): int`
  — sums `overtime_details.minutes` across the employee's **approved** (`state = approved`)
  overtime requests whose detail `date` equals `$date`. A query-builder wrapper over
  Eloquent, the same shape and domain-Eloquent allowance as `LeaveDayLookup`. Returns **0**
  when none — the strict model. (Multiple approved OT requests for one date sum; the common
  case is one.)
- `DailyComputationInput` gains `int $approvedOvertimeMinutes` (beside `onApprovedLeave`).
  `ComputeDailySummary` resolves it via `OvertimeAuthorizationLookup` and passes it in — the
  domain class stays pure and never queries.
- `DailyComputation::compute`: the paid overtime ceiling is
  `overtimeThresholdMinutes + approvedOvertimeMinutes`. Worked minutes are attributed in
  three chronological regions:
  - `[0, overtimeThreshold)` → regular (day/night split, priced as today);
  - `[overtimeThreshold, overtimeThreshold + approvedOvertime)` → **paid** overtime (day/night
    split, priced as today);
  - `[overtimeThreshold + approvedOvertime, worked)` → **unpaid excess**, accumulated into a
    new scalar `unpaidOvertimeMinutes` on `ComputedDay`, never priced.

  Implemented by feeding the existing `splitBuckets` walk a paid ceiling: minutes crossing
  the second boundary go to the excess accumulator instead of an overtime bucket. Reuses the
  existing chronological interval-slicing — no new pricing path, no new day/night logic.
  **art82-exempt short-circuit:** when `isArt82Exempt`, the cap is not applied
  (`approvedOvertimeMinutes` is effectively unbounded for them) and `unpaidOvertimeMinutes`
  stays 0 — exempt employees have no overtime premium to withhold, so their computation is
  unchanged. The cap otherwise applies uniformly to ordinary, rest-day, and holiday overtime
  (whatever falls beyond the overtime threshold) — no day-type special-casing.
- `ComputedDay` gains `int $unpaidOvertimeMinutes`. `ComputeDailySummary` persists it into
  `daily_attendance_summaries.unpaid_overtime_minutes`. It is a summary-level scalar like
  `late_minutes`/`undertime_minutes`, not a `daily_summary_lines` row, so the pay-rule gate
  and `rule_version_id` attribution are unaffected (excess is unpaid — it never needs a
  priced line or a rule version).

### RBAC / routes
- `POST /overtime/requests` is `auth:sanctum`, filed by the authenticated employee for
  themselves (no special permission — filing your own request mirrors leave/adjustment
  submit). Deciding reuses the existing `request.decide` authority path and the two approval
  queues unchanged. Add an `overtime` note to `docs/05-rbac.md`; no new permission is
  introduced.

## Frontend

Carbon, React-Query-backed through `keys.ts`, mirroring the leave request path.

- **File-overtime form** off `/me/attendance`: date + hours (or minutes); shows the minutes
  it will request; `POST /overtime/requests`. Mirrors `LeaveRequestForm`.
- **`RequestCard.summarizeOvertime`** — an overtime request card summarizes as its date +
  "Xh overtime" (approved/ pending per state), alongside the existing `summarizeLeave` /
  attendance summaries. `manager_approved` is irrelevant (single-hop), so the card shows
  `pending → approved | rejected | cancelled` only.
- **`RequestType` / `RequestState` TS unions** widened to include `overtime`; the
  `RequestDetail` union gains the overtime shape (`{ date, minutes }`).
- **Data layer:** `api.overtime.submitRequest`, `useSubmitOvertimeRequest`,
  `keys.overtime` (or fold under the requests keys, matching how leave-submit is keyed).
- **Attendance day view** surfaces `unpaid_overtime_minutes` on a day that had excess — the
  same treatment `undertime_minutes` gets, so a capped long day visibly shows the unpaid
  remainder rather than an invented total.

## Error handling

Envelope contract unchanged. `minutes`/`hours` ≤ 0 or a malformed `date` → 400
`validation_failed` (FormRequest). A decide on an out-of-scope/foreign request → 404 (no
existence leak). A second decision on a terminal request → 409 (inherited from the spine).
No new domain exception is required — the effect writes nothing that can fail a balance/lock
check (unlike leave), so `OvertimeEffect` has no `Insufficient*`-style throw; it is pure
recompute-enqueue.

## Testing

- **Backend (real Postgres):**
  - Schema test pinning `overtime_details`'s CHECK and the widened `requests_type_check` to
    `RequestType::cases()`; the `unpaid_overtime_minutes` column exists with default 0.
  - `RequestType::Overtime->requiresHrStep()` is `false`; a submitted overtime request starts
    `pending`; the manager **and** office HR can each decide it (single-hop authority), and a
    second decide 409s.
  - `SubmitOvertimeRequest` writes exactly one request + one `overtime_details` row with the
    filed date/minutes; the hours→minutes controller conversion.
  - `OvertimeEffect` on approval writes **no** ledger row and enqueues a `RecomputeRange`
    over the one date with `RecomputeTrigger::Overtime` (assert the dispatched job, mirroring
    `LeaveEffect`'s recompute assertion); a rejected overtime request enqueues nothing and
    prices no cap.
  - `OvertimeAuthorizationLookup::approvedMinutesFor` returns the summed approved minutes for
    a date and **0** when none / when the only covering request is not yet `approved`.
  - `DailyComputation` cap matrix: worked OT **below** the approved cap pays all of it and
    `unpaidOvertimeMinutes = 0`; worked OT **above** the cap pays exactly `approved` and the
    remainder is `unpaidOvertimeMinutes`; **zero** approved (unauthorized) → all overtime is
    excess, regular time still paid; the excess boundary respects the day/night split of the
    **paid** portion; an art82-exempt employee is uncapped (`unpaidOvertimeMinutes = 0`
    regardless of approval); rest-day and holiday overtime cap the same way.
  - `ComputeDailySummary` persists `unpaid_overtime_minutes`; approving an overtime request
    triggers the recompute that flips a previously-all-excess day to paid up to the cap.
  - Existing OT compute tests updated to pass `approvedOvertimeMinutes` covering their worked
    overtime (assertions preserved).
- **Frontend:** the file-overtime form (hours→minutes display, required date, submit +
  invalidation), `RequestCard` overtime summary, the day view rendering
  `unpaid_overtime_minutes`.
- **`scripts/e2e-leave-and-ot.sh`** (the done-when's named script — proves leave and OT
  paths together, live): file overtime for a date that ran past schedule → it appears in the
  manager's `/team` (and HR's `/office`) → approve → the day re-prices, paid OT =
  `min(actual, approved)`, the excess shows as `unpaid_overtime_minutes`; a separate long day
  with **no** approval leaves all its overtime unpaid; and the leave path (grant → file →
  manager → HR → debit → `leave_with_pay`) still passes end-to-end.

## Done when

An employee's pre-authorized overtime caps what the engine pays for a day that ran long, and
the excess shows up as `unpaid_overtime_minutes` rather than vanishing or being silently
paid; an unauthorized long day pays zero overtime. Backend + frontend suites green;
`scripts/e2e-leave-and-ot.sh` runs live, exit 0. **With M6c merged, M6 (Requests &
approvals) is complete.**

## Explicitly deferred (with the slice that owns it)

- **Partial-amount approval** — a manager approving fewer minutes than filed. This is a
  spine change (the decide endpoints gaining an amount parameter), not an overtime-only
  concern → a later milestone.
- **Auto-detect config flag** — `docs/00-overview.md`'s "a config flag could add auto-detect"
  (pay worked overtime without a pre-auth). The strict model ships now; the flag is a later,
  cheap addition.
- **Payroll export** of the overtime and unpaid-excess figures → **M7** (M6c makes compute
  *read* and *price* the cap; M7 exports the resulting lines with their `rule_version_id`).
- **Overtime spanning midnight into a second business date** — a single `overtime_details.date`
  authorizes one business day; a shift crossing midnight already attributes its worked minutes
  to the business date it belongs to (M5), so this is not a gap, but multi-date OT
  authorization (one request, a date range) is not built.
