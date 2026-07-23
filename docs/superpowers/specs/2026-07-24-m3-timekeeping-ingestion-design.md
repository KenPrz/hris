# M3 — Timekeeping Ingestion — Design

Date: 2026-07-24

The first half of the attendance pipeline: turning a punch into an append-only,
forensically intact row in `attendance_logs`. Everything from a raw punch up to — but not
including — computing what a day is worth.

M3 deliberately stops before interpretation. It does not pair punches, attribute a punch
to a business day, detect a missing clock-out, or compute any pay. Those are all compute-
time concerns and belong to a later milestone, once the configuration they read exists.

## Why M3 was rescoped and the roadmap resequenced

The roadmap originally framed M3 as a vertical slice reaching a full computed daily pay
breakdown. That is not buildable: `ComputeDailySummary` needs a **holidays** table (to know
a date's day-type), a **schedule** (scheduled day length for overtime, rest-day
assignment), and **`pay_rules`** (the multiplier and its `rule_version_id`) — none of which
exist. M2 built org, employees, employment history, and RBAC; it built no schedule or
holiday tables. The roadmap's line that M3 "runs against M2's *seeded* schedules and
holidays" was a stale assumption from before M2's scope was finalized.

Rather than pull half of the configuration layer forward into M3, the milestones are
resequenced so the compute engine lands after the config it consumes:

| Old | New |
| --- | --- |
| M3 — vertical slice (ingest → compute → calendar) | **M3 — Timekeeping ingestion** (this spec) |
| — | **M3.5 — Frontend foundation** (design language, auth UI, punch/attendance screens) |
| M4 — Configuration spine | **M4 — Configuration spine** (schedules, holidays, `pay_rules`) |
| M5 — Requests & approvals | **M5 — Compute engine** (`ComputeDailySummary`) |
| M6+ | requests, cutoffs, payroll export — shift down one |

Two decisions drove the split:

- **The compute engine reads config that is M4's job.** Building it against seeded stubs
  M4 then reshapes would mean building the highest-stakes code in the system (the M1 matrix
  applied to money) twice. It waits for real inputs.
- **The frontend is its own subsystem.** The app has no real UI today (only the M0 health
  page). Landing the entire IBM/Carbon design language, the component foundation, the auth
  UI, and the first screens *alongside* backend ingestion would make M3 larger than M2 and
  mix two subsystems in one review cycle. The frontend gets its own milestone (M3.5),
  built against M3's real API.

## What M3 does NOT own

Stated plainly, because the temptation to reach further is real:

- **Pairing** punches into in/out spans — M5 (`PunchPairer` already exists from M1).
- **Business-day attribution** — a night shift's out-punch at 06:00 belongs to which
  business day is M5's problem, resolved via the office-local wall-clock conversion the M1
  primitives were designed around.
- **Missing-clock-out detection** — a day with an `in` and no `out` is a compute-time
  observation; ingestion just stores whatever punches arrive.
- **Any pay computation.** M3 produces raw instants, not money.

## 1. `attendance_logs` — the append-only ledger

The raw record you show a DOLE inspector. Nothing ever updates or deletes a row; a
correction is a new row (a manual punch), never an edit.

```
attendance_logs
  id                uuid pk (uuidv7)
  employee_id       uuid → employees
  office_id         uuid → offices        -- snapshot at punch time (see below)
  punched_at        timestamptz           -- the instant, stored UTC
  direction         text  CHECK (direction IN ('in','out'))
  source            text  CHECK (source IN ('web','manual','device'))
  verification      text  CHECK (verification IN ('verified','flagged'))
  flag_reason       text nullable         -- e.g. 'ip_not_allowlisted', 'outside_geofence'
  recorded_by       uuid nullable → users -- who created the row
  ip_address        inet nullable
  device_id         text nullable         -- for a future device; null for web/manual
  geo_lat           decimal(10,7) nullable
  geo_lng           decimal(10,7) nullable
  created_at        timestamptz
```

### String columns, PHP enums, CHECK constraints

Following the house pattern (M2's `DayType`, `employment_type`): the DB stores plain
`text`, the app casts to PHP backed string enums, and a `CHECK` constraint mirrors the
enum's values so the DB still rejects garbage. Postgres native enums are avoided — altering
one (adding a value) is a migration dance, and a `text`-plus-`CHECK` column is both simpler
to evolve and cast-friendly.

```php
enum PunchDirection: string { case In = 'in'; case Out = 'out'; }
enum PunchSource: string { case Web = 'web'; case Manual = 'manual'; case Device = 'device'; }
enum PunchVerification: string { case Verified = 'verified'; case Flagged = 'flagged'; }
```

The model casts `direction`, `source`, `verification` to these enums, so every read and
write in the app is typed while the wire and column stay plain strings. A schema/arch test
asserts the `CHECK` value list and the enum cases do not drift.

### Three integrity rules

**`office_id` is a snapshot, not a live join.** A punch records the office it belonged to
*at the moment it happened*, captured from the employee's `current_office_id` at ingestion.
POS's snapshot discipline: geofence/IP is validated against that office, and M5 later
converts `punched_at` (UTC) → office-local wall-clock → business-day using this stored
office — so a mid-period transfer never retroactively reinterprets an old punch's timezone.

**`punched_at` for self-service is server time, always.** A web employee's punch is stamped
by the server clock, never a client-supplied time — otherwise anyone can backdate their own
clock-in. **Manual HR entry is the one path that accepts an explicit `punched_at`**, because
correcting a missed punch is its entire purpose; those rows carry `source: manual` and
`recorded_by` = the HR user.

**No unique constraint that would reject a genuine double punch.** Idempotency catches
accidental retries by key (§3). Two genuinely distinct punches a second apart are both
legal and both stored — the log is a ledger, and M5's pairer decides what they mean.

## 2. `RecordPunch` — the one writer

All three write paths funnel through a single action, so the log-integrity rules live in
exactly one place, and an arch guard can assert `attendance_logs` is written nowhere else
(the M2 cache-writer pattern).

`RecordPunch`:

- Resolves the subject employee (self for self-service; the target for manual entry) and
  snapshots their `current_office_id` onto the row.
- **Self-service:** `punched_at` = server now, `source: web`, `recorded_by` = the employee's
  own user id.
- **Manual:** `punched_at` = the supplied timestamp, `source: manual`, `recorded_by` = the HR
  user id; the target is constrained by `EmployeeScope` (HR can only punch for an employee
  within their office — M2's boundary, reused unchanged).
- Runs verification, sets `verification` + `flag_reason`, writes the append-only row, never
  updates.

## 3. The endpoints, idempotency, and the device contract

```
POST /api/v1/attendance/punch          self-service          [auth:sanctum]
POST /api/v1/admin/attendance/punch    manual HR entry       [auth:sanctum + scope]
  (device path deferred — see below)
```

### Verification → flag, never reject

The endpoint checks the request IP against the snapshot office's `ip_allowlist` (a column
that already exists from M2). Pass → `verification: verified`. Fail → `verification:
flagged`, `flag_reason: ip_not_allowlisted`. The geofence check is wired identically but
only fires when `geo_lat`/`geo_lng` are present (mobile, later); for a web punch it is IP
only.

**The punch always lands.** Rejecting a punch means a legitimate employee on hotel wifi, a
VPN, or a mobile hotspot cannot clock in — and the Labor Code cares that time was worked,
not which network recorded it. The append-only log keeps the forensic record; verification
is metadata on the row for HR to review, not a gate at the door. Promoting flag → enforce
per office is a small later change if a real office asks; the allowlist column is already
there.

### Idempotency

`EnsureIdempotency` middleware ported from POS; `Idempotency-Key` required on the punch
route. A retry — a flaky mobile connection, a double-tap — replays the stored response and
writes no second row, because the key and the row commit together in one transaction
(POS's exact pattern). Two genuinely distinct punches carry distinct keys and both land.

### The device contract, exposed not built

The payload already accepts `source`, `device_id`, `geo_lat`/`geo_lng`, and an idempotency
key — the shape a device's middleware would POST. But **device authentication** (a device
registry + per-device tokens) and **batch ingestion** (a device posting many punches at
once) are deferred with the hardware that needs them. Today both live paths are
Sanctum-authed. Adding a device later is a new auth guard and a `source: device` path into
the same `RecordPunch` — no schema change, no new writer.

## 4. Reading raw attendance

```
GET /api/v1/me/attendance?month=YYYY-MM              own punches   [auth:sanctum]
GET /api/v1/employees/{employee}/attendance?month=   scoped        [auth:sanctum + EmployeeScope]
```

Returns the raw `attendance_logs` rows, grouped by **office-local calendar date** — each
punch's `punched_at` converted to its snapshot office's timezone, then bucketed by that
local date. Each row carries `punched_at`, `direction`, `source`, `verification`,
`flag_reason`.

Because direction is explicit, the view labels "in 08:00 / out 17:00" **without any
pairing** — no `PunchPairer`, no business-day logic. A night shift's out-punch at 06:00
appears on its own local calendar date. That is honest: it is the raw ledger, and
interpretation is M5's job.

The employee-scoped variant reuses `EmployeeScope` and the 404-not-403 rule from M2
unchanged — an HR admin sees their office's punches, a manager their reports', an
out-of-scope subject 404s.

## 5. Testing and done-when

Real Postgres, never SQLite. The append-only invariant is front and centre.

- **`RecordPunch`** writes the row with the correct snapshot office, server-time for web,
  supplied-time for manual. A grep/arch guard asserts it is the only writer of
  `attendance_logs`.
- **Idempotency:** a replayed key writes no second row and replays the response; two
  distinct keys both land. The concurrency case uses two real Postgres connections, per the
  house rule.
- **Flag-not-reject:** an out-of-allowlist IP lands as `flagged` / `ip_not_allowlisted`,
  never a 4xx; an allowlisted IP lands `verified`.
- **Manual entry** is scoped (HR punching for another office's employee → 404) and records
  `source: manual` + `recorded_by`.
- **Append-only:** no update or delete route exists anywhere under attendance; a
  schema/arch check confirms it.
- `scripts/e2e-timekeeping.sh`: log in as a seeded employee, punch in, punch out (idempotent
  under retry), punch from a bad IP (flagged), have HR backfill a manual punch, and read
  the month back showing all of them grouped by office-local date.

**Done when:** a seeded employee punches in and out (idempotent under retry), an off-network
punch lands flagged rather than refused, HR backfills a missed punch as `manual`, and
`GET /me/attendance?month=` returns them grouped by office-local date — with the raw log
provably append-only.

## Non-goals for M3

Pairing, business-day attribution, missing-clock-out detection, and pay computation (all
M5). The frontend — design language, components, auth UI, punch and attendance screens
(M3.5). Device authentication and batch ingestion (deferred with the hardware). Schedules,
holidays, and `pay_rules` (M4).

## Open questions

None blocking. One to settle before M5: the final M2 review flagged that admin write
requests validate `office_id`/`department_id`/`reports_to_id` for existence only, not for
org/office consistency (a department under a different office, an employee reporting across
orgs). M3 doesn't make this worse — a punch snapshots the employee's own current office —
but M5's compute must decide whether a cross-office employment row is coherent before it
attributes punches to schedules. Recorded here so it isn't rediscovered mid-engine.
