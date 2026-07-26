# M6b-a — Leave foundation (design)

**Status:** approved (brainstorm), pending spec review
**Milestone:** M6b-a — first slice of M6b (Leave). Slicing: **M6b-a foundation → M6b-b leave
requests + the two-hop approval machine.**
**Depends on:** M2 (offices, `OfficeScope`, `hr_admin_offices`, RBAC), M6a (the request spine —
untouched by this slice).

## Goal

Stand up the leave *foundation*: admins configure leave types per office, HR grants an
employee leave (fully logged), and everyone can see their balance. This slice is the "given"
side of leave plus the balance view — **no taking, no approval, no compute integration**.
Taking leave, the manager→HR two-hop machine, and the `LeaveEffect` ledger debit are M6b-b.

## Decisions (from brainstorm)

1. **Slicing:** M6b-a foundation (types + ledger + manual grant + balances) → M6b-b requests +
   two-hop machine.
2. **Unit:** the ledger stores **integer minutes** (the atomic unit — chosen so hourly leave and
   a later late/undertime→leave conversion are representable). Readable units (day / half-shift /
   hour / minute) are a **presentation layer**: input is converted to minutes on the way in,
   balances decompose back to a readable form on the way out.
3. **Day conversion:** a per-office **nominal** `minutes_per_leave_day` (default **480** = 8h).
   "1 day" of leave is 480 minutes for everyone in that office, independent of their actual
   scheduled shift length — because leave *entitlement* is legally day-denominated. Half-shift =
   ÷2, hour = 60, minute = 1 all derive from it.
4. **Accrual:** **manual grant only.** HR credits an employee by hand; every grant is an
   append-only ledger row (amount, reason, actor, timestamp) plus an activity-log entry. **No
   automatic accrual engine** in this slice (SIL's 1-year/Art. 82 rule, monthly proration,
   carryover/expiry are all later).
5. **Config scope:** `leave_types` are **per-office** config (Manila and Cebu each own an
   editable list), the same pattern as holidays and shift templates.
6. **Balances are derived, never stored** — always summed from the ledger, never a mutable
   number, the same discipline as the append-only punch ledger.

## Data model

House rules apply throughout: uuid v7 PKs (`DB::raw('uuidv7()')` default + `HasUuids`), string
columns + PHP backed enums + `CHECK` constraints (never a Postgres native enum, pinned to
`Enum::cases()` by a schema test), integer minutes, `timestamptz`.

### `offices` (modify)
- Add `minutes_per_leave_day` `smallint NOT NULL DEFAULT 480`. The office's nominal leave-day
  length. (Consistent with `default_shift_template_id`, added to `offices` in M4b.)

### `leave_types` (new) — per-office, admin-editable config
- `id`, `office_id` (FK offices, cascade), `name` (e.g. "Service Incentive Leave"),
  `code` (nullable slug, e.g. `sil` — identifies a seeded statutory type for idempotent
  re-seed and later floor rules; null for ad-hoc company types).
- Flags (roadmap): `is_paid` bool, `requires_attachment` bool, `deducts_balance` bool,
  `is_cash_convertible` bool, `max_carryover_minutes` int nullable (null = unlimited carryover;
  stored now, the year-end processing that uses it is deferred).
- `is_active` bool default true (retire a type without deleting its history).
- **`deducts_balance` is the balance-vs-event axis** (one field, not two): a `deducts_balance=true`
  type holds a running balance you grant into and (later) debit (SIL, VL, SL); a
  `deducts_balance=false` type is an entitlement granted per occurrence, not banked (Maternity,
  Paternity, Solo Parent, VAWC, Magna Carta). In THIS slice only `deducts_balance=true` types
  receive grants and show a balance; the event types are configured now and used in M6b-b.
- `LogsActivity`. Scoped/edited by HR over the office (`OfficeScope`), 404-not-403 for
  out-of-office reads — the same discipline holidays/schedules use.

### `leave_ledger` (new) — append-only, the source of every balance
- `id`, `employee_id` (FK employees), `leave_type_id` (FK leave_types).
- `entry_type` `text` CHECK `IN ('credit','debit')`.
- `minutes` `integer` CHECK `> 0` (magnitude; `entry_type` carries the sign).
- `reason` `text NOT NULL` (required on every row — the "with a reason" rule).
- `source` `text` CHECK `IN ('manual_grant')` for this slice (M6b-b adds `'leave_taken'`,
  and the CHECK widens then). Documents what created the row.
- `request_id` nullable (FK requests, `nullOnDelete`) — null for a manual grant; M6b-b's
  approved-leave debit links here.
- `created_by` (FK users — the granting actor), `created_at` (`timestamptz`). **No `updated_at`,
  no updates, no deletes** — a correction is a compensating row, never an edit.
- Index `(employee_id, leave_type_id)` — the balance query.
- `LogsActivity` (the grant is also in the activity log, belt-and-suspenders with the row itself).

### Derived balance
`App\Domain\Leave\LeaveBalances` — a pure query service: `for(Employee): array<leave_type_id,
int minutes>` computes `SUM(credit) − SUM(debit)` per type from `leave_ledger`. Never persisted.

## Backend

Action-class architecture (final, own transaction, Input DTO, returns a domain object, no HTTP).

- **Leave-type config** (HR over office): `CreateLeaveType`, `UpdateLeaveType` (+ a
  `SetOfficeLeaveDay` for `minutes_per_leave_day`). Validated: the five flags, `accrual_basis`,
  `max_carryover_minutes ≥ 0` when present. No delete (retire via `is_active=false`) — leave
  config is referenced by ledger history.
- **`GrantLeave`** — `execute(employeeId, leaveTypeId, minutes, reason, actorId): LeaveLedger`.
  Writes ONE `credit` row (`source: manual_grant`, `created_by: actor`, required `reason`),
  inside a transaction, audited. Guards: the target employee must be in an office the actor
  administers (HR scope); the leave type must belong to that office and be
  `deducts_balance=true` (you can't bank an event type). The controller
  converts the readable input (e.g. `{amount: 5, unit: 'day'}`) to minutes via the office's
  `minutes_per_leave_day` before calling the action; the action itself takes minutes.
- **Balance read** — `ListMyLeave` (the caller's own balances per active type) and
  `ListEmployeeLeave` (an employee the caller oversees, via `EmployeeScope`). Returns each
  type's derived balance in minutes plus a readable decomposition.

### Routes (all `auth:sanctum`)
- `GET /office/leave-types`, `POST /office/leave-types`, `PATCH /office/leave-types/{leaveType}`,
  `PATCH /office/leave-day` (set `minutes_per_leave_day`) — HR/office-scoped.
- `POST /leave/grants` — HR grants (body: employee, leave_type, amount, unit, reason).
- `GET /me/leave` — my balances. `GET /employees/{employee}/leave` — an overseen employee's
  balances (EmployeeScope, 404-not-403).

### RBAC
- Add a `leave.manage` permission (configure leave types + grant credits), seeded to the
  `HR Admin` role (alongside `holiday.manage`, `schedule.manage`). `leave.approve` stays
  dormant until M6b-b. Config/grant endpoints are gated by `leave.manage` + `OfficeScope`.

## Frontend

Carbon, React-Query-backed through `keys.ts`, mirroring the M4/M6a screens.

- **`/office/leave-types`** (HR) — the office's leave types with their flags; create/edit a type;
  set the office `minutes_per_leave_day`. Mirrors the holidays/pay-rules admin screens.
- **`/me/leave`** (everyone) — the employee's balance per active leave type, shown in readable
  days (+ the minute-exact value), with the type's flags (paid, cash-convertible…) surfaced.
- **HR grant** — from an overseen employee's leave view (or the leave-types area), a "Grant
  leave" form: employee + type + amount + unit (day/half-shift/hour/minute) + required reason →
  `POST /leave/grants`. Optimistic nothing here; invalidate the balance key on success.
- `keys.leave`: `types(officeId)`, `myBalances()`, `employeeBalances(employeeId)`.
- Nav: `/me/leave` under **Me** for everyone; `/office/leave-types` under **Office** when the
  session carries HR offices (the SideNav rule pattern M6a used).

## Error handling

Envelope contract unchanged. Out-of-office config/grant/read → 404 (no existence leak). A grant
of a non-`balance` type, a negative/zero amount, an empty reason, or a cross-office target →
422 domain error. Balances can go negative only via a future debit (M6b-b) — not in this slice.

## Testing

- **Backend (real Postgres):** schema tests pinning the CHECK lists to the enums; `GrantLeave`
  writes exactly one credit row with the reason/actor and is audited; the readable→minutes
  conversion (`5 days × 480 = 2400`); balance derivation sums credits correctly and never
  persists; HR-scope guards (can't grant across offices, can't bank an event type); the
  office/employee balance reads honor `OfficeScope`/`EmployeeScope` (404-not-403).
- **Frontend:** the leave-types config form, the `/me/leave` balance view (readable
  decomposition), and the HR grant form (unit conversion, required reason, balance invalidation).
- **`scripts/e2e-leave-foundation.sh`:** HR sets up a type and the office leave-day → grants an
  employee "5 days" → the grant is one logged `leave_ledger` credit of 2400 minutes → the
  employee's `GET /me/leave` shows a 5-day balance, recomputed from the row.

## Done when

HR configures a leave type, grants an employee "5 days," the grant appears as a single logged
ledger row, and the employee's `/me/leave` shows a 5-day balance derived from the ledger (never a
stored number). Backend + frontend suites green; `scripts/e2e-leave-foundation.sh` runs live.

## Explicitly deferred (with the slice that owns it)

- **Taking leave**, `leave_details`, the `LeaveEffect` ledger debit, the submission form →
  **M6b-b**.
- **The two-hop `submitted → manager_approved → hr_approved → approved` machine**,
  `requires_hr_step`, per-hop authority, deferred-effect-until-final-hop → **M6b-b** (leave is the
  first type that needs the second hop).
- **Automatic accrual** (SIL's 1-year + Art. 82 exclusion, monthly proration), **carryover/expiry
  processing** (`max_carryover_minutes` is stored now, the year-end job is later), **cash-conversion
  payout** (`is_cash_convertible` stored now; the payout is M7 payroll export).
- **Compute integration** — M5 gaining a "leave with pay" concept. Not in M6b-a; M6b-b's
  done-when is where "the compute engine reads leave alongside attendance."
