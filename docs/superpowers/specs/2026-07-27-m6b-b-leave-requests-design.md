# M6b-b — Leave requests + the two-hop approval machine (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M6b-b — second (final) slice of M6b (Leave). M6b-a (leave foundation) is merged.
**Depends on:** M6a (the request spine — evolved here from single-step to two-hop), M6b-a
(the leave ledger / types / balances / units that leave requests debit), M5a/M5b (compute +
`RecomputeRange`, which leave now plugs into).

## Goal

Turn the single-step request spine into a two-hop **manager → HR** machine, add **leave** as a
request type that files → is approved in two hops → **debits the leave ledger on final
approval**, and make the **compute engine read approved leave** (a `leave_with_pay` day).

## Decisions (from brainstorm)

1. **Compute is in scope.** An approved leave day emits a `leave_with_pay` summary line; not
   deferred to M7.
2. **`requiresHrStep()` is per request TYPE, in code** — a method on the `RequestType` enum:
   `attendance_adjustment` = single-hop, `leave` = two-hop. No schema column, no per-request
   snapshot of the flag.
3. **Minimal state set.** `pending, manager_approved, approved, rejected, cancelled`. Drop the
   roadmap's `draft` (YAGNI) and `hr_approved` (redundant with `approved`); keep `pending` (no
   rename to `submitted`).
4. **No-manager invariant.** A two-hop request whose requester has no manager is created
   directly in `manager_approved` — so `pending` always means "manager's turn,"
   `manager_approved` always means "HR's turn."
5. **Two hops, two people.** The final (HR) approver must differ from the manager who did hop 1.
6. **Amount = scheduled working days** in the range × per-day minutes (not calendar days),
   snapshotted at submission.
7. **Balance-sufficiency check** at final approval — refuse an approval that would over-debit a
   balance type (balances never go negative).

## The two-hop machine

### States
`App\Domain\Requests\RequestState` gains `ManagerApproved = 'manager_approved'`. Final set:
`pending, manager_approved, approved, rejected, cancelled`. The `requests_state_check` CHECK
widens to these five. TS `RequestState` widens to match.

### `requiresHrStep`
`RequestType::requiresHrStep(): bool` — `AttendanceAdjustment => false`, `Leave => true`.

### Flow by type
- **Single-hop** (`requiresHrStep=false`): `pending → approved`, any in-scope non-self approver
  (manager or HR). Unchanged from M6a.
- **Two-hop** (`requiresHrStep=true`): `pending → manager_approved → approved`. Manager approves
  hop 1 (**no effect**); HR approves hop 2 (→ `approved`, effect fires).

### Per-hop authority
`RequestAuthority` becomes state-aware. Two primitives (already used by `ApprovalQueues`):
`isManagerOf(approver, request)` = `request.employee.current_reports_to_id === approver.employee.id`;
`isHrOf(approver, request)` = `request.employee.current_office_id ∈ approver.hrAdminOffices`.
Never self.
- `pending` + single-hop → `isManagerOf || isHrOf`.
- `pending` + two-hop → `isManagerOf` only.
- `manager_approved` → `isHrOf` only **AND** `approver.id !== request.manager_decided_by`
  (a person cannot do both hops; both sides are **user** ids — `manager_decided_by`, like
  `decided_by`, is a users FK). When `manager_decided_by` is null (the no-manager request created
  directly in `manager_approved`), the check is vacuously true and HR may approve.

`canDecide(User, Request): bool` encapsulates this (state + `type.requiresHrStep()` + the two
primitives). `RejectRequest` uses the same "may I act on the current hop?" gate (either hop's
authorized approver may reject). `CancelRequest` is unchanged — requester-only, from any
non-terminal state.

### Hop-aware queues
`ApprovalQueues` predicates (still scoped views, not new authority):
- **`/team`** (`directReportsOf`): `state = pending` requests from the caller's direct reports
  (both types — the manager's hop).
- **`/office`** (`hrOfficesOf`): requests from the caller's HR offices where
  `(state = pending AND type is single-hop)` **OR** `state = manager_approved`. A two-hop
  `pending` never appears here.

Single-hop `type` is expressed in SQL as `type = 'attendance_adjustment'` (the set of single-hop
types); as new types are added their `requiresHrStep` is reflected here.

### Deferred effect — `ApproveRequest`
`ApproveRequest::execute` computes the next state from `(state, type.requiresHrStep())`:
- `pending` + single-hop → `approved` (fire effect; set `decided_by`/`decided_at`).
- `pending` + two-hop → `manager_approved` (**no effect**; set `manager_decided_by`/`manager_decided_at`).
- `manager_approved` (two-hop) → `approved` (fire effect; set `decided_by`/`decided_at`).

The `RequestEffect` fires **only on the transition into `approved`**, under the same row lock. All
existing M6a ordering (lock → authority 404 → not-a-terminal-state 409 → effect-if-final → state
write) is preserved; the effect call is gated behind "next state is `approved`."

### Schema (`requests`)
- Widen `requests_state_check` to the five states (new migration — none landed in M6a/M6b-a).
- Add `manager_decided_by` (nullable FK users), `manager_decided_at` (nullable timestamptz) — the
  hop-1 record. `decided_by`/`decided_at`/`decision_note` keep their meaning for the terminal
  decision (final approve, or reject).

## Leave as a request type

### `RequestType`
Add `Leave = 'leave'`; widen `requests_type_check` to `('attendance_adjustment','leave')`.

### `leave_details` (1:1, PK = `request_id`)
Mirror `attendance_adjustment_details`: `request_id` uuid **PRIMARY KEY** + FK cascade;
`leave_type_id` FK; `start_date` date; `end_date` date; `day_part` text CHECK `IN ('full','half')`;
`amount_minutes` integer CHECK `> 0`. A schema test pins the CHECK. `Request::leaveDetail(): HasOne`.

### Submission — `POST /leave/requests`
Type-specific (mirrors `/attendance/adjustments`): `SubmitLeaveRequest` action, `SubmitLeaveRequestRequest`
FormRequest, `SubmitLeaveController`. Body: `leave_type_id`, `start_date`, `end_date`, `day_part`,
`note` (required), optional `attachment`.
- Resolve the leave type scoped to the requester's office (404-not-403); refuse an **inactive** type
  (domain 422) — and add the same `is_active` guard to `GrantLeave` (the M6b-a follow-up).
- If the leave type `requires_attachment`, the attachment is mandatory (422 without it).
- Compute `amount_minutes` = scheduled working days in `[start_date, end_date]` (via
  `ScheduleResolver`) × (`office.minutes_per_leave_day` for `full`, `intdiv(…,2)` for `half`),
  snapshotted into `leave_details`. Zero scheduled days in range → 422 (nothing to request).
- Create the `Request` in `pending` (or `manager_approved` if the requester has no manager) + the
  `leave_details` row + optional attachment, one transaction. Returns `RequestResource` 201.

### `LeaveEffect` (fires on the final hop → `approved`)
`App\Actions\Requests\Effects\LeaveEffect implements RequestEffect`; registered in
`RequestEffectFactory` (`Leave => app(LeaveEffect::class)`). `applyOnApproval(Request, approverUserId)`:
- Load `leaveDetail` + its `leaveType`.
- If `leaveType.deducts_balance`:
  - Under the employee's balance lock, if `amount_minutes > LeaveBalances::forEmployee()[type]` →
    throw `InsufficientLeaveBalance` (422) — the whole approval rolls back; the request stays
    `manager_approved`, no debit.
  - Else write **one** `leave_ledger` row: `entry_type='debit'`, `minutes=amount_minutes`,
    `source='leave_taken'` (new CHECK value), `request_id=request.id`, `reason` (e.g. "Leave
    request {id} approved"), `created_by=approverUserId`.
- If `!deducts_balance` (event type): **no ledger write** (an entitlement, not a balance spend).
- Enqueue `RecomputeRange` over `[start_date, end_date]` for this employee
  (`RecomputeTrigger::Leave`, new case) via `DB::afterCommit`, so the leave days re-price.

### Ledger `source` CHECK
Widen `leave_ledger_source_check` to `('manual_grant','leave_taken')` (new migration).

## Compute reads approved leave

- New `daily_summary_lines.kind` **`leave_with_pay`** (widen the CHECK; add to `SummaryLineKind`
  enum + the TS mirror).
- `ComputeDailySummary`, when resolving a day: if the day is a **scheduled working day** (not rest),
  is covered by an **approved** leave request (`leave_details.start_date ≤ date ≤ end_date` on a
  `state='approved'` leave `Request` for this employee), **and has no effective punches**, emit a
  `leave_with_pay` line for the scheduled minutes at **100% (10000 bp)**. A day with effective
  punches computes from punches as normal (worked time wins). **Full-day clean leave days only** —
  half-day-plus-partial-work is out of scope for M6b-b (documented).
- The read is a new seam in `ComputeDailySummary`'s context resolution (a `LeaveDays` domain query:
  "is this employee-date covered by an approved leave request?"). `DailyComputation` stays pure —
  the leave fact is passed in on its input, like `dayType`/`isRestDay`.
- Recompute-on-approval (above) makes an approved leave day show `leave_with_pay` without a re-punch.

## Serialization + frontend

- `RequestResource` branches on `type` to serialize the detail (`attendanceAdjustmentDetail` vs
  `leaveDetail`). TS `RequestDetail` becomes a discriminated union (attendance shape | leave shape:
  `{ leave_type_id, start_date, end_date, day_part, amount_minutes }`), keyed by `RequestRecord.type`.
- `<RequestCard>`: add `TYPE_LABEL.leave` + a `summarizeLeave` case (type, span, day-part, cost in
  readable days). **Both approval queues and `useDecideRequest`/`useQueueDecision` are reused
  unchanged** — the machine decides the hop server-side; the frontend still just calls approve/reject.
  A `manager_approved` state renders as a distinct `Tag` on the card / in `/me/requests`.
- New **leave request form** off `/me/leave` (mirrors `CorrectionForm`): leave type (active,
  in-office), date range, `full`/`half`, required note, optional attachment; shows the computed cost
  (scheduled days × per-day) and the current balance before submit. Posts to `/leave/requests`.
- `keys.requests` / `api.requests` reused; add `api.leave.submitRequest` (multipart) + a
  `useSubmitLeaveRequest` hook (invalidate `keys.requests.mine()` + the balance key).

## Error handling

Envelope unchanged. 404-not-403 for out-of-scope leave-type/request/employee. FormRequest validation
→ 400. Domain guards → 422: inactive type, missing required attachment, zero scheduled days,
`InsufficientLeaveBalance`, `LeaveTypeNotGrantable` (existing). A decision on a request whose current
hop the approver isn't authorized for → 404 (same subject-scope discipline). A second decision on a
terminal request → 409.

## Testing

- **Backend (real Postgres):** the state machine (`pending→manager_approved→approved` for leave,
  `pending→approved` for attendance, no-manager→`manager_approved`); the **per-hop authority matrix**
  (manager can/can't act per state; HR can/can't; the two-people rule; reject at either hop; cancel);
  the **hop-aware queues** (a two-hop `pending` in `/team` not `/office`; a `manager_approved` in
  `/office` not `/team`; single-hop in both); the **deferred effect** (no ledger row exists at
  `manager_approved`, exactly one debit at `approved`); `SubmitLeaveRequest` amount-from-scheduled-days
  + inactive/attachment/zero-day guards + is_active on grant; `LeaveEffect` debit + balance-sufficiency
  refusal (rolls back, stays `manager_approved`) + event-type-no-debit; compute emits `leave_with_pay`
  at 100% for a clean leave day and recomputes on approval.
- **Frontend:** the leave request form (cost/balance display, required fields), the `<RequestCard>`
  leave summary + `manager_approved` tag, `/me/requests` showing a two-hop state.
- **`scripts/e2e-leave.sh`:** request SIL (5 days) → in the manager's `/team` → manager approves
  (state `manager_approved`, **no** debit, now in HR's `/office`) → HR approves → `approved`, the
  ledger **debits 5 days**, `/me/leave` balance drops, and the leave days show `leave_with_pay` on
  `/me/attendance`. A reject-at-HR run leaves the balance untouched. Runs live.

## Done when

An employee requests SIL; their manager approves the first step; HR approves the second; the leave
ledger debits the balance; and the compute engine reads it alongside attendance (the leave days price
as `leave_with_pay`) — the append-only ledger never mutated, no debit before final approval. Backend +
frontend suites green; `scripts/e2e-leave.sh` runs live.

## Explicitly deferred

- Half-day-plus-partial-work compute interaction (M6b-b prices full-day clean leave days only).
- Automatic accrual, carryover/expiry, cash-conversion payout (M6b-a deferrals — still later).
- The priced payroll **export** of leave-with-pay per period → **M7** (M6b-b makes compute *read*
  leave; M7 exports it).
- N-step (>2) approval chains — the machine is fixed at manager→HR; deeper chains are a later schema
  change (`00-overview.md`).
