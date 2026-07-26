# M6a — The approval spine (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M6a — first slice of M6 (Requests & approvals). Slicing: **M6a spine → M6b
leave → M6c overtime pre-auth**.
**Depends on:** M3.6 (the request/approval spine, single-step, attendance adjustments),
M5a/M5b (recompute-on-approval already wired).

## Goal

Turn M3.6's attendance-only approval into a reusable, **single-step** request spine with
two scope-based approval queues, and give it its first full browser UI — proven end to end
with attendance adjustments. Leave (M6b) and overtime pre-authorization (M6c) then plug in
as new request *types* against the same spine, machine, queues, and card.

## Context — what already exists (M3.6), verified

- **`requests` table** (`2026_07_26_000002_create_requests_table.php`): `id`, `type` (CHECK
  `IN ('attendance_adjustment')`), `employee_id` (requester), `state` (CHECK `IN
  ('pending','approved','rejected','cancelled')`, default `pending`), `note` (required),
  `decided_by`, `decided_at`, `decision_note` (DB CHECK: non-null when rejected). Indexes
  `(employee_id, state)` and `(type, state)` — the latter is "the approval queue query".
- **`attendance_adjustment_details`** — 1:1 (PK *is* `request_id`), `operation IN
  ('add','void','amend')`, `target_log_id`, `direction`, `punched_at`.
- **`Request` model** — `HasUuids`, casts `type`→`RequestType`, `state`→`RequestState`;
  `employee()`, `decidedBy()`, `attendanceAdjustmentDetail()`; `HasMedia` single optional
  `attachment` (pdf/jpeg/png).
- **State machine is single-step**: `pending → {approved | rejected | cancelled}`. Enum
  docblock: "No draft — a request is submitted directly."
- **`RequestAuthority::canDecide(User, Request): bool`** — requester's employee visible to
  the approver under `EmployeeScope::visibleTo()` **AND** approver is not the requester.
  One gate: manager over direct reports, HR over their offices, sys-admin over all; never
  self; out-of-scope reads as 404.
- **Actions**: `ApproveRequest` (locks the row, authority→pending→**effect**→state write; the
  effect is a hardcoded `ApplyAttendanceAdjustment::apply` call inside the same lock),
  `RejectRequest` (authority→pending→note-required), `CancelRequest` (requester-only). There
  is **no** generic `SubmitRequest`; submission is `SubmitAttendanceAdjustment`.
- **Routes** (`routes/api.php`): `POST /attendance/adjustments` (submit), `GET
  /attendance/adjustments` (mine), `GET /attendance/adjustments/pending` (the one queue),
  `POST .../{request}/{approve|reject|cancel}`, `GET .../{request}`, `GET
  .../{request}/attachment`. Approval queue = `state=pending ∧ requester ∈
  EmployeeScope::visibleTo(me) ∧ requester ≠ me`.
- **No request/approval frontend exists.** No `/team/approvals`, `/office/approvals`,
  `RequestCard`, or requests hooks. Filing a correction has no screen (M3.6 was API-only).
- `leave.approve` permission is **seeded but dormant** (`05-rbac.md`).

## Scope

### In scope (M6a)

1. **Per-type effect dispatch** — generalize `ApproveRequest` off its hardcoded attendance
   dependency onto a `RequestEffect` interface resolved by `RequestType`.
2. **Two scope-filtered queues** — `/team/approvals` (direct reports) and `/office/approvals`
   (HR offices), replacing the single combined pending list.
3. **Route generalization** — the read/decision surface moves to a type-agnostic
   `/requests/*`; submission stays type-specific.
4. **The full attendance-correction vertical in the browser** — file a correction, view my
   requests, and the two approval queues.

### Out of scope (deferred, with the slice that owns it)

- The multi-step `draft → submitted → manager_approved → hr_approved → approved` machine, the
  `requires_hr_step` flag, and the `submitted`/`draft` vocabulary → **M6b** (leave is the
  first type that needs a second hop).
- Leave types, `leave_ledger`, balances → **M6b**.
- Overtime pre-authorization and the `min(actual, approved)` compute integration → **M6c**.
- Any change to the append-only ledger or the M5 recompute path (already correct).

## Architecture — backend

### 1. Per-type effect dispatch

Today `ApproveRequest` depends on and calls `ApplyAttendanceAdjustment` directly. Introduce:

- **`App\Domain\Requests\RequestEffect`** (interface): `applyOnApproval(Request $request,
  string $approverUserId): void`. Framework-agnostic (Domain layer); the *implementations*
  live in the Actions layer where they may touch models.
- **`App\Actions\Requests\Effects\AttendanceAdjustmentEffect`** implements it by delegating
  to the existing `ApplyAttendanceAdjustment::apply(...)`. No behavior change.
- **`App\Actions\Requests\RequestEffectFactory`** (the `PayRatesFactory` pattern): maps a
  `RequestType` to its `RequestEffect`. For M6a the only case is `AttendanceAdjustment`.
- `ApproveRequest` resolves the effect by `$request->type` and calls it **inside the lock
  and transaction it already holds**, in the same position the hardcoded call sits now. If a
  type has no registered effect, that is a programming error (an exception), never a silent
  approve.

Result: attendance approval is behaviorally identical; leave/OT add an effect class + a
factory case and nothing else in the approval path changes.

### 2. Two scope-filtered queues

Replace the single `ListPending` with two read endpoints, each a scoped view of the same
`state=pending ∧ requester≠me` set:

- **`/team/approvals`** — requester's `current_reports_to_id = me.employee.id` (a manager's
  direct reports; direct reports only, not the sub-tree — matches `05-rbac.md`).
- **`/office/approvals`** — requester's `current_office_id ∈ me.hrAdminOffices` (an HR
  admin's office members).

A user who is both a manager and an HR admin sees both queues (a request may appear in both;
whichever they act on first decides it). **`RequestAuthority::canDecide` is unchanged** — the
queues are two scoped *views* of what the user may already act on, not a new authority model.
Both primitives (`current_reports_to_id`, `hr_admin_offices`) already exist on `EmployeeScope`.

Each queue is type-agnostic (returns every pending request type in scope), so leave/OT appear
in the same queues automatically.

**System administrator:** a sys-admin has no employee record, no direct reports, and no HR
offices, so both queues are empty for them — deliberately. Approvals are a manager/HR *org*
flow; the sys-admin is not an org approver. They retain `RequestAuthority::canDecide` at the
API (unchanged), but get no approval-queue screen. This is a deliberate narrowing of the UI
surface, not of authority; if a sys-admin approval view is ever wanted it is an office-scoped
read, never a new authority tier.

### 3. Routes

Generalize the **read/decision** surface to type-agnostic `/requests/*`; keep **submission**
type-specific because each type's payload/detail differs:

| M6a route | Replaces (M3.6) | Notes |
| --- | --- | --- |
| `GET /requests` | `GET /attendance/adjustments` | my own requests, all types |
| `GET /requests/{request}` | `GET /attendance/adjustments/{request}` | detail; requester-or-canDecide gate |
| `GET /requests/{request}/attachment` | `.../{request}/attachment` | private stream |
| `POST /requests/{request}/approve` | `.../{request}/approve` | delegates to `ApproveRequest` |
| `POST /requests/{request}/reject` | `.../{request}/reject` | note required |
| `POST /requests/{request}/cancel` | `.../{request}/cancel` | requester-only |
| `GET /team/approvals` | (split from) `GET /attendance/adjustments/pending` | manager scope |
| `GET /office/approvals` | (split from) `.../pending` | HR scope |
| `POST /attendance/adjustments` | unchanged | type-specific submission |

This is a **one-time contract change** to M3.6's endpoints, made while the only client is the
frontend M6a is writing. M3.6's request/approval feature tests move to the new paths.

### 4. State vocabulary

Keep `pending` (and the existing four states). Single-step makes `pending` exactly right; the
roadmap's `draft`/`submitted`/`manager_approved`/`hr_approved` vocabulary and the state-set
expansion land in **M6b**, when the multi-step machine actually exists. Renaming now is churn
for a distinction that does not yet exist.

## Data model

**No new tables, no migration.** The effect-dispatch and queue split are code-only; `requests`
and `attendance_adjustment_details` already exist; the `type` CHECK stays
`attendance_adjustment` until M6b widens it for leave. M6a's risk stays in the application
layer, not the schema.

## Frontend — the full vertical

Three Carbon surfaces, all React-Query-backed through `src/lib/keys.ts`, mirroring the M3.5
attendance screens and their conventions (envelope-aware client, string calendar dates,
`Duration`/`Money` mirrors).

1. **File a correction.** From the M3.5 month calendar (`/me/attendance`), a day (and its
   punches) gains a "Request correction" affordance → a form:
   - operation: **add** / **void** / **amend**;
   - target punch (for void/amend): picked from that day's real punches;
   - new time (for add/amend); required note; optional attachment (pdf/jpeg/png).
   - Posts to `POST /attendance/adjustments`. Closes the M3.6 "no correction screen" gap.
2. **My requests** (`/me/requests`). The employee's own requests: type, state, decision note,
   outcome; **withdraw** (cancel) a pending one. Invalidates by key prefix on change.
3. **Approval queues** — `/team/approvals` (managers) and `/office/approvals` (HR). Each is a
   list of one reusable **`<RequestCard>`** (requester, type, a per-type summary of what's
   being changed, note, attachment link) with **Approve** / **Reject** (reject requires a
   note). **Optimistic update is confined here** (the roadmap's rule): the card flips state
   immediately and rolls back on error. Everywhere else invalidates by key prefix.

`<RequestCard>` renders any request type through a small per-type summary slot, so leave/OT
reuse it unchanged.

Nav: the app shell surfaces `/me/requests` for everyone; `/team/approvals` when the session
says the user manages anyone; `/office/approvals` when the session lists HR offices (the
session payload already carries both facts).

## Error handling

Unchanged from M3.6 and the envelope contract: out-of-scope reads/decisions are 404 (no
existence leak), a second decision on a decided request is 409, a rejection without a note is
422, self-cancel-only is enforced in `CancelRequest`. The approval effect runs inside the
same transaction/lock as the state write, so a failing effect rolls the whole approval back —
already true, preserved by the dispatch refactor.

## Testing

- **Backend (real Postgres):**
  - the effect-dispatch seam: an approved attendance adjustment fires `AttendanceAdjustmentEffect`
    (the existing add/void/amend behavior + recompute), and a type with no registered effect
    raises rather than silently approving;
  - the two queue scopings: a manager sees only their direct reports' pending requests; an HR
    admin sees only their office's; a both-hats user sees both; never self; a decided request
    leaves both queues;
  - the unchanged authority / lock / recompute path (the existing M3.6 assertions, moved to
    the new routes).
- **Frontend:** component tests for the filing form (operation × target-punch validity), the
  my-requests list + withdraw, and the queue `<RequestCard>` optimistic flip **and rollback**.
- **`scripts/e2e-requests.sh`:** file a correction via the API the UI calls → it appears in
  the requester's manager queue and their office HR queue → approve → the day recomputes to
  the correct breakdown (M5 path) → **the original punch row is byte-identical**.

## Done when

An employee files a correction in the browser; it surfaces in both the manager's
`/team/approvals` and the office HR's `/office/approvals`; an approval recomputes the day
through the M5 path to the correct breakdown; and the raw `attendance_logs` ledger is
byte-identical before and after. Backend + frontend suites green; `scripts/e2e-requests.sh`
runs live.

## Decisions resolved in brainstorm

1. **Slicing:** M6a spine → M6b leave → M6c overtime pre-auth.
2. **State machine:** single-step in M6a; the manager→HR two-hop chain + `requires_hr_step` +
   the `manager_approved`/`hr_approved` states arrive in M6b with leave.
3. **Frontend:** the full vertical (file a correction + my requests + both queues), not just
   the queues.
4. **Routes:** generalize the read/decision surface to `/requests/*`; submission stays
   type-specific.
5. **Vocabulary:** keep `pending`; adopt `submitted`/`draft`/… in M6b.
