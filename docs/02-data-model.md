# Data Model

Postgres 18. The DDL here is the design of record; the Laravel migrations in
`backend/database/migrations/` implement it. Where the two ever disagree, the migration is
the truth and this document is the bug.

## Conventions

- **PKs are `uuid default uuidv7()`.** Native in Postgres 18 — no `pgcrypto`, no extension,
  no application-side generation. UUIDv7 is time-ordered, so it indexes like a sequence
  (no B-tree page-split churn from random UUIDv4) while staying unguessable and stable.
  Models generate the same shape with `Str::uuid7()` via `HasUuids`, so a row inserted
  through Eloquent and one inserted by a raw SQL `default` are indistinguishable.
- **Money is `bigint` centavos.** PHP `int`, wire suffix `_cents`. `base_rate_cents` is the
  only monetary column in M2 and it follows the rule — the integer-centavos invariant from
  `01-architecture.md`, never a float, never a decimal-peso column.
- **Worked time is integer minutes, multipliers integer basis points.** No such column
  lands until M3; the invariants are stated in `06-roadmap.md` so the tables that carry
  them inherit the rule rather than inventing it.
- **Timestamps are `timestamptz`, stored UTC.** Always. Display timezone lives on
  `offices.timezone`, never in `APP_TIMEZONE` (which is pinned to UTC, enforced at boot).
  Calendar facts that are genuinely date-only — `hired_at`, `effective_from` — are `date`,
  not `timestamptz`: a hire is a day, not an instant, and giving it a time zone would
  invent a lie about the hour someone started.
- `created_at`/`updated_at` on mutable tables. `employment_records` is an append-only
  ledger and still carries both, because a correction to a *mis-entered* row (not a change
  in the employee's actual history) is a legitimate `update` we want stamped.

---

## The organization hierarchy

Three tiers, modeled as **flat foreign keys**, never an adjacency tree:
`organizations` → `offices` → `departments`.

```sql
create table organizations (
  id          uuid primary key default uuidv7(),
  name        text not null,
  legal_name  text,                          -- as registered with the SEC
  tin         text,                           -- BIR taxpayer identification number
  timezone    text not null default 'Asia/Manila',
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create table offices (
  id                uuid primary key default uuidv7(),
  organization_id   uuid not null references organizations(id) on delete cascade,
  name              text not null,
  code              text not null unique,     -- short, human-facing; GLOBALLY unique
  timezone          text not null default 'Asia/Manila',
  -- Forward-declared for M3's punch endpoint; unused until then.
  geofence_lat      numeric(10,7),
  geofence_lng      numeric(10,7),
  geofence_radius_m integer,
  ip_allowlist      jsonb,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);

create table departments (
  id          uuid primary key default uuidv7(),
  office_id   uuid not null references offices(id) on delete cascade,
  name        text not null,
  code        text not null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  unique (office_id, code)                    -- code unique WITHIN an office
);
```

**Why flat FKs and not a tree.** The single most-executed query in the system is "which
employees are in scope for this actor," and for an HR Admin that is "employees in these
offices." A tree makes that a recursive CTE; a flat `office_id` makes it a plain
`WHERE current_office_id IN (…)`. The hierarchy is only ever three levels deep and those
levels are named, distinct things — an organization is not a kind of office — so the
generality of a self-referencing tree buys nothing and costs the hot path. This is the
decision the whole scope model is built on; `05-rbac.md` spends it.

**`offices.code` is globally unique, `departments.code` is unique only within its office.**
An office code appears on its own — in a URL, a report header, a payroll export filename —
with no parent to disambiguate it, so two offices sharing `MNL` would be a genuine
collision. A department code never appears without its office, so `(office_id, code)` is
the real identity and `OPS` can name Operations in both Manila and Cebu without conflict.
The seeded company relies on exactly that (`CompanySeeder`).

The `geofence_*` and `ip_allowlist` columns are stored now so M3's punch endpoint has a
home to validate against; nothing reads them in M2. They are forward-declared rather than
migrated in later for the same reason POS forward-declared `registers.mode` — one nullable
column at build time is cheaper than an `ALTER` over a populated table later.

---

## Employees: immutable identity plus a current-state cache

```sql
create table employees (
  id                    uuid primary key default uuidv7(),
  employee_no           text not null unique,
  -- Nullable and unique: a punch-only worker has an employee record and no login;
  -- at most one login per employee. null on delete keeps the employee if the user goes.
  user_id               uuid unique references users(id) on delete set null,
  organization_id       uuid not null references organizations(id),
  hired_at              date not null,
  separated_at          date,

  -- The current-state CACHE. Derived from employment_records; see below.
  current_office_id     uuid references offices(id),
  current_department_id uuid references departments(id),
  current_reports_to_id uuid references employees(id),   -- self-reference

  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now()
);

create index employees_current_office_id   on employees (current_office_id);
create index employees_current_reports_to  on employees (current_reports_to_id);
```

`employees` holds only what does **not** change over a career: the person's identity
(`employee_no`), when they joined and left, which organization they belong to, and the
optional `user_id` that links to a login. Everything that *does* change — office,
department, manager, employment type, Art. 82 status, base rate — lives in
`employment_records`, one row per change (below).

### The employee/user split

`user_id` is nullable because the employment record is the point, not the account. A
punch-only worker — field or factory staff who clock a device and never open the
portal — is a first-class case: `user_id` stays null, and the seeder creates one
explicitly (`MNL-0005`) so the nullable path is exercised in tests, not merely assumed.
Authentication flows through `users`; scope and policies resolve through `employees`
(`05-rbac.md`), so a login is never required for a person to be a valid *subject* of a
query. The unique constraint enforces at most one login per employee; provisioning a
second is a domain failure (`employee_already_has_login`, `03-api.md`), never a silent
overwrite.

### The `current_*` cache, and why it exists

This is the one place an earlier promise met reality and needed a deliberate answer.

The flat-FK hierarchy exists so office scoping stays `WHERE office_id = ?`. But
`office_id`, `department_id`, and `reports_to_id` are attributes that change mid-career, so
they live in the effective-dated `employment_records` history. "Employees in my office"
would therefore become "employees whose *currently effective* record has `office_id = ?`"
— a join to a derived row, on the most-executed query in the system. That join is exactly
what the flat FKs were chosen to avoid.

**Resolution:** `employees` carries `current_office_id`, `current_department_id`, and
`current_reports_to_id` as a denormalized cache of the currently-effective
`employment_records` row. The history table stays the source of truth; these three columns
are derived from it, and `EmployeeScope` reads only them — so the scope query stays the
plain, fast `WHERE` the model was designed to guarantee.

`current_reports_to_id` self-references `employees.id`, which is what makes manager
authority a plain relationship: a manager is anyone some other employee's
`current_reports_to_id` points at (`05-rbac.md` — manager is derived, never a role). Its
foreign key is added in a **follow-up `Schema::table()` call**, not inline in the
`create`, on purpose: Postgres's Laravel grammar appends the fluent `->primary()` on `id`
to the end of the blueprint's command list, *after* any inline `->constrained()` foreign
keys, so an inline self-FK would try to reference `employees.id` before that table's own
primary key exists in the same migration, and Postgres rejects the ordering ("no unique
constraint matching given keys"). Adding it in a second statement sidesteps the ordering
entirely. This is a real wall the migration hit; it is commented at the site so nobody
"tidies" it back inline.

### The single-writer invariant

**Exactly one class writes the three `current_*` columns:
`App\Actions\Employees\RecordEmploymentChange`.** It inserts the new `employment_records`
row and updates the cache in the **same transaction**, so the ledger and its cache can
never disagree. Two rules make that trustworthy:

1. **It advances the cache only when the new row is the latest effective date.** A
   back-dated correction (recording that an employee's rate was different last March)
   writes history but leaves the cache alone — "current" means the latest *effective* date,
   not the most recently *entered* row. The action re-reads the latest row after insert and
   only touches `employees` if the row it just wrote is that latest one.
2. **No other code may write those columns**, and an arch test enforces it mechanically
   rather than by review. `tests/Arch/ConventionsTest.php`'s
   *"only RecordEmploymentChange writes the employment cache columns"* greps every file
   under `app/` for the three write forms — mass assignment (`'current_office_id' => …`),
   property assignment (`->current_office_id = …`), and `setAttribute('current_office_id',
   …)` — and asserts `RecordEmploymentChange.php` is the only match. The mass-assignment
   form is textually identical whether it writes or reads (a `JsonResource` shaping output
   uses the same `'col' => $model->col` syntax), so that one sub-pattern is skipped under
   `app/Http/Resources/` — a resource structurally cannot call
   `create()`/`update()`/`fill()`, so a `'col' =>` there is always a read-mapping. The
   property and `setAttribute` forms indicate a write anywhere, including an accidental one
   in a resource, so those stay global.

`CreateEmployee` onboards through `RecordEmploymentChange` (it never touches the cache
itself), and so does `CompanySeeder` — every seeded employee's cache is populated the one
legal way. The guard is why the seeder cannot take the shortcut of writing
`current_office_id` directly: the build would fail the arch test.

---

## Employment records: the effective-dated source of truth

```sql
create table employment_records (
  id              uuid primary key default uuidv7(),
  employee_id     uuid not null references employees(id) on delete cascade,
  effective_from  date not null,

  office_id       uuid not null references offices(id),
  department_id   uuid not null references departments(id),
  reports_to_id   uuid references employees(id),        -- nullable: a top of chain reports to no one

  employment_type text not null,                        -- 'regular', 'probationary', 'contractual'
  is_art82_exempt  boolean not null default false,
  base_rate_cents  bigint not null,                     -- integer centavos, per the money rule

  created_by       uuid references users(id),           -- the actor who recorded the change
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now(),

  unique (employee_id, effective_from)                  -- one change per employee per day
);
```

Every attribute that changes over a career is here, one row per change.
**`effective_to` is derived, never stored** — a record is in force until the day before the
next record's `effective_from`, and the currently-effective record for a date `D` is simply
the latest row whose `effective_from <= D`. One date per row means there is no second value
to keep consistent and no overlap to police. The resolver that answers "what was true on
date `D`" is `App\Domain\Employment\EmploymentResolver::on()`, and it is exactly that
query: `where effective_from <= D order by effective_from desc limit 1`.

The `unique (employee_id, effective_from)` constraint makes "two changes on the same day"
structurally one change — `App\Actions\Employees\RecordEmploymentChange` pre-checks for an
existing row with the same `(employee_id, effective_from)`, inside the same locked
transaction that inserts the new one, and throws `EmploymentRecordExists`
(`422 employment_record_exists`, `03-api.md`) rather than letting a second row reach the
database. The unique constraint stays as the backstop for the pre-check — belt and
suspenders, not a silent second row the resolver would have to tie-break.

**Why a full history rather than current columns plus an audit log.** The pay engine (M5)
computing March's payroll *after* a June promotion must read March's `is_art82_exempt` and
March's `base_rate_cents`. With a history table that is a lookup; with an audit log it is a
replay — and a wrong multiplier applied to a closed period is the single most expensive
failure this system can produce (`06-roadmap.md`). A lookup cannot drift; a replay can.
`is_art82_exempt` lives here and not on `employees` for precisely this reason: an employee
promoted into a managerial (exempt) role in June must still have earned overtime in March,
and a current-only column would erase that.

`base_rate_cents` is `bigint`, integer centavos — a daily or monthly rate a later
milestone reads; M2 stores it and computes nothing. `created_by` is nullable because a
migration or a system process could in principle record a change with no human actor; the
API always sets it to the acting user, and the seeder sets it to the System Admin who
onboarded everyone.

---

## The HR-Admin scope grant

```sql
create table hr_admin_offices (
  user_id   uuid not null references users(id)   on delete cascade,
  office_id uuid not null references offices(id) on delete cascade,
  primary   key (user_id, office_id)
);
```

A composite-key pivot, and nothing more. Being in this table for an office is what confers
HR-Admin **scope** over that office's employees; the composite primary key makes a
`(user, office)` pair idempotent — you either administer an office or you don't, and there
is no third state to represent. The verbs an HR Admin may *perform* come from the spatie
`HR Admin` role; this pivot is the "over whom." Keeping the two apart — verbs in one place,
scope in another — is the whole authorization design, argued in `05-rbac.md`. Both sides
cascade on delete: remove the user or the office and the grant goes with it, because a
grant to a deleted party is meaningless, not archival.

---

## Authentication and RBAC tables

### Users, sessions, and the uuid cascade

`users` keeps Laravel's shape with one change made at the root and one added in M2:

- **`users.id` is `uuid default uuidv7()`, not `bigIncrements`.** Every id in the schema is
  uuidv7, and `employees.user_id` FKs to this column. Flipping the users PK to uuid forces
  the same change through everything that references a user by id — a cascade worth stating
  because getting one link wrong is an insert-time error that reads like a framework bug:
  - `sessions.user_id` → `uuid` (foreignUuid).
  - Sanctum's `personal_access_tokens.tokenable_id` → `uuidMorphs`, not `morphs` (the
    default `morphs` is a bigint; left alone, minting a token fails at insert).
  - spatie's `model_has_roles.model_id` / `model_has_permissions.model_id` → `uuid`
    morph key (below).
  - `activity_log.causer_id` → `nullableUuidMorphs` (the actor who did a logged thing is a
    user, hence uuid).
- **`users.is_system_admin boolean not null default false`**, added in M2. Global oversight
  is a flag resolved through `Gate::before`, not a spatie role — the reasoning, and the
  package behaviour that forces it, is in `05-rbac.md`.

### spatie/laravel-permission — without teams

Installed **without the teams feature** (`config('permission.teams') === false`). This is
the deliberate divergence from POS, whose per-location teams were affordable only because a
device token made the team context unambiguous; there is no device here, so scope lives in
`hr_admin_offices` and roles carry none. The full argument is `05-rbac.md`.

Two edits to the published migration were required, both confirmed by reading the installed
package source, not its docs:

- **`model_has_roles.model_id` and `model_has_permissions.model_id` are `uuid`**, not the
  package's default `unsignedBigInteger` — `users.id` is uuidv7, so the morph key must
  match. The config keeps the column *name* (`model_morph_key => 'model_id'`); only the
  type changes.
- **With teams off, `roles` carries no team column** and its uniqueness is
  `unique (name, guard_name)` — a role name is globally unique, because there is no team to
  scope it to. `model_has_roles` / `model_has_permissions` drop the team key from their
  primary keys accordingly.

`roles` and `permissions` keep their `bigint` `$table->id()` primary keys — a deliberate
exception to the uuid convention above, not an oversight. They are seeded reference data,
never client-visible, never sorted by creation time; the reasons for uuidv7 simply don't
apply, and fighting the package to change them would be cost with no benefit. The one
seeded role (`HR Admin`) and its verb catalog are described in `05-rbac.md`.

### activity_log

`spatie/laravel-activitylog` is installed; its `causer` morph is uuid
(`nullableUuidMorphs`), matching the user cascade above. No action logs to it in M2 —
logging lands with the features that need a defensible trail (M4+), and when it does it
happens **inside actions**, never in a model observer, because an observer fires for
seeders and migrations too and would pollute the trail HR is one day asked to defend.

---

## Attendance: the append-only ledger *(M3)*

The raw record you show a DOLE inspector. **Nothing ever updates or deletes a row** — a
correction is a *new* row (a manual punch), never an edit. This is the single most
load-bearing property of the table, and it is enforced from two directions: no route mutates
it (there is no `PATCH`/`PUT`/`DELETE` anywhere under `attendance`), and exactly one class
writes it.

```sql
create table attendance_logs (
  id            uuid primary key default uuidv7(),
  employee_id   uuid not null references employees(id),
  office_id     uuid not null references offices(id),   -- SNAPSHOT at punch time (see below)
  punched_at    timestamptz not null,                   -- the instant, stored UTC
  direction     text not null,                          -- 'in' | 'out'
  source        text not null,                          -- 'web' | 'manual' | 'device'
  verification  text not null,                          -- 'verified' | 'flagged'
  flag_reason   text,                                   -- e.g. 'ip_not_allowlisted', 'outside_geofence'
  recorded_by   uuid references users(id),              -- who created the row (the employee, or HR)
  ip_address    text,                                   -- inet stored as text; cast in the model
  device_id     text,                                   -- for a future device; null for web/manual
  geo_lat       numeric(10,7),
  geo_lng       numeric(10,7),
  created_at    timestamptz not null default now(),

  check (direction    in ('in','out')),
  check (source       in ('web','manual','device')),
  check (verification in ('verified','flagged'))
);

create index attendance_logs_employee_punched on attendance_logs (employee_id, punched_at);
```

**The `office_id` is a snapshot, not a live join.** A punch records the office it belonged
to *at the instant it happened*, captured from the employee's `current_office_id` at
ingestion. Verification (IP allowlist, geofence) runs against *that* office, and M5 later
converts `punched_at` (UTC) → office-local wall-clock → business day using this stored
office — so a mid-period transfer never retroactively reinterprets an old punch's timezone
or fence. It is the same snapshot discipline the current-state cache uses for a different
reason: freeze the fact at the moment it was true.

**String columns, PHP enums, `CHECK` constraints — the `DayType`/`employment_type`
pattern.** `direction`, `source`, and `verification` are plain `text` in the database, cast
to PHP backed string enums (`App\Domain\Attendance\PunchDirection`, `PunchSource`,
`PunchVerification`) in the model, with a `CHECK` constraint mirroring each enum's values so
the DB still rejects garbage. Postgres native enums are avoided deliberately: adding a value
to one is an `ALTER TYPE` migration dance, while a `text`-plus-`CHECK` column is both simpler
to evolve and cast-friendly. `AttendanceLogSchemaTest` pins the `CHECK` value lists and the
enum cases together so the two cannot drift.

**`ip_address` is `text`, not Postgres `inet`.** The value is cast in the model; storing it
as text keeps the column trivially portable and sidesteps `inet` literal handling, at the
cost of the DB not validating IP shape — which the application does. `geo_lat`/`geo_lng` are
`numeric(10,7)`, the same precision as the office `geofence_*` columns they are checked
against.

### The single-writer invariant

**Exactly one class writes `attendance_logs`: `App\Actions\Attendance\RecordPunch`.** It
snapshots the office, resolves the punch time (server-`now()` for self-service, the supplied
timestamp for a manual HR entry), runs `PunchVerifier`, and appends the row — inside one
transaction, the same one `EnsureIdempotency` opens for a keyed request. It only ever
`create()`s; it never updates, deletes, or saves-over a row.

Two guards make that trustworthy, the sibling of the `RecordEmploymentChange` cache-writer
guard above:

1. **`tests/Arch/ConventionsTest.php`'s *"only RecordPunch writes attendance_logs"*** greps
   every file under `app/` that references `AttendanceLog` or `attendance_logs` for any write
   form — `create(`, `new AttendanceLog`, `->update(`, `->delete(`, `->save(`,
   `updateOrCreate(`, `firstOrCreate(`, `->upsert(`, and raw
   `DB::table('attendance_logs')->insert/update/upsert/delete(` — and asserts
   `RecordPunch.php` is the only match. The model definition and `app/Http/Resources/` (a
   read-only presentation layer that structurally cannot write) are exempted.
2. **`tests/Feature/Attendance/AppendOnlyTest.php`** proves the append-only property end to
   end: it scans the registered route list and asserts no `PATCH`/`PUT`/`DELETE` route has
   `attendance` in its URI (with a companion check that attendance routes *do* exist, so the
   assertion is not vacuous), and it reads `RecordPunch.php` and asserts the sole writer
   contains a `create(` and none of the mutating forms. Nothing else writes; the thing that
   writes only appends.

**No unique constraint that would reject a genuine double punch.** Idempotency (below)
catches accidental *retries* by key; two genuinely distinct punches a second apart are both
legal and both stored. The log is a ledger — M5's pairer decides what a sequence of punches
means, and a `UNIQUE` here would throw away a real event to prevent a duplicate the
idempotency layer already prevents at the right level.

The composite index `(employee_id, punched_at)` serves the one query the read API and M5
run: an employee's punches within a time range.

## Idempotency keys *(M3)*

Replay protection for mutating requests, ported from POS unchanged.

```sql
create table idempotency_keys (
  key           text primary key,       -- the client-supplied Idempotency-Key
  request_hash  text not null,          -- sha256(user + method + path + body)
  response_code integer not null,
  response_body jsonb not null,
  created_at    timestamptz not null default now()
);

create index idempotency_keys_created_at on idempotency_keys (created_at);   -- pruning window
```

A client-generated key stores the original outcome so a retried request — a flaky mobile
connection, a double-tap — replays the stored response instead of doing the work twice. The
key row and the work it guards **commit in one transaction**, which the `EnsureIdempotency`
middleware (aliased `idempotent`) opens and the nested action joins: either both the punch
row and its key land, or neither does, so a stored key can never point at a row that was
rolled back.

**The hash folds in the acting user**, so a key is confined to whoever minted it — the same
key replayed by a different user is a `409 idempotency_key_reused`, not a leak of the first
user's cached response. Reusing a key with a *different* body is likewise `409`, because the
key is a promise about one specific request, not a general mutex. Only a `2xx` response is
stored; a failed request leaves no key, so a corrected retry can proceed. `created_at` and
its index exist for a later pruning job — a key is only useful within a retry window.

The self-service punch route requires an `Idempotency-Key` header (`03-api.md`); the manual
HR route deliberately does not — an HR correction is a considered one-off, not a retryable
network event.

---

## Requests, adjustments, and the annulment ledger *(M3.6)*

An employee correcting their **own** attendance goes through a request, not a self-service
punch — `RecordPunch`'s self-service route stamps server-now and cannot backdate; a missed
or wrong punch instead needs a note, an optional attachment, and someone else's approval.
See `docs/superpowers/specs/2026-07-24-attendance-adjustments-design.md`.

```sql
create table requests (
  id            uuid primary key default uuidv7(),
  type          text not null,                    -- 'attendance_adjustment' (widens later)
  employee_id   uuid not null references employees(id),   -- the requester
  state         text not null default 'pending',  -- 'pending'|'approved'|'rejected'|'cancelled'
  note          text not null,                     -- required on submission
  decided_by    uuid references users(id),
  decided_at    timestamptz,
  decision_note text,                              -- required on rejection (app-enforced)
  created_at    timestamptz not null,
  updated_at    timestamptz not null,

  check (type  in ('attendance_adjustment')),
  check (state in ('pending','approved','rejected','cancelled'))
);

create index requests_employee_id_state_index on requests (employee_id, state);
create index requests_type_state_index on requests (type, state);   -- the approval queue query
```

**The shared spine.** `requests` is deliberately generic — leave and overtime (later
milestones) reuse this same table and its `pending → approved | rejected | cancelled` state
machine rather than each growing a parallel one. Every type gets its own 1:1 detail table for
its type-specific columns, the same split `employment_records` uses for history versus
`employees`' identity columns.

```sql
create table attendance_adjustment_details (
  request_id    uuid primary key references requests(id) on delete cascade,
  operation     text not null,                     -- 'add' | 'void' | 'amend'
  target_log_id uuid references attendance_logs(id),   -- required for void/amend
  direction     text,                               -- required for add/amend
  punched_at    timestamptz,                        -- required for add/amend, stored UTC

  check (operation in ('add','void','amend')),
  check (direction is null or direction in ('in','out'))
);
```

**The primary key IS `requests.id`** — no separate generated id, no separate uniqueness rule
to maintain. One request, one detail row, enforced by the database rather than by
convention. Which fields are required depends on `operation` (an `add` needs
`direction`/`punched_at` and no target; a `void` needs only `target_log_id`; an `amend`
needs all three) — enforced at the HTTP layer (`SubmitAdjustmentRequest`'s
`required_if` rules), not by a CHECK, since expressing a three-way conditional-required
constraint in SQL would duplicate that validation in a second, harder-to-read place.

```sql
create table attendance_annulments (
  id                uuid primary key default uuidv7(),
  attendance_log_id uuid not null unique references attendance_logs(id),
  request_id        uuid not null references requests(id),
  created_at        timestamptz not null default now()
);
```

**How a void/amend supersedes a punch without ever mutating it.** `attendance_logs` stays
append-only — approving a `void` or `amend` never updates or deletes the target row. Instead
it records a new fact: "this punch is annulled, by this request." `unique(attendance_log_id)`
makes "at most one annulment per punch" a database invariant, not just an application check
— a double-void race hits a `QueryException`, not a silent double-annul. An `amend` is
implemented as exactly this annulment **plus** a fresh `RecordPunch` call for the corrected
time — a void-and-add pair, never an in-place correction.

**The effective ledger is `attendance_logs` minus `attendance_annulments`** — the set of
punches an inspector-facing raw dump and a pay computation disagree about. Concretely: a
punch's id has no matching row in `attendance_annulments`. This is defined here because it is
the single most important thing for whoever builds the M5 compute engine to get right about
attendance — **M5 must read the effective ledger, not the raw table.** M3.6 itself does
**not** wire this filter into any read endpoint: `GET /me/attendance` and
`GET /employees/{employee}/attendance` (`03-api.md`) are, by design, the raw append-only
ledger — "the record you'd show a DOLE inspector" includes an annulled punch, because it
still happened and was still recorded. An approved void is provable today only by its
absence from the effective-ledger *query* (`AttendanceLog::whereNotIn('id',
AttendanceAnnulment::select('attendance_log_id'))`, exercised in
`tests/Feature/Attendance/ApplyAdjustmentTest.php`), not by any HTTP response changing shape.

### The two single-writer invariants

Attendance now has **two** append-only tables, each with exactly one writer, guarded the same
way for the same reason: a corrective fact is always a new row, never an edit.

1. **`RecordPunch` is still the only writer of `attendance_logs`**, unchanged from M3 — an
   approved `add`/`amend` adjustment calls it exactly like self-service or manual entry does,
   just with `source: adjustment` and `recorded_by` the approver. See the M3 section above.
2. **`App\Actions\Attendance\RecordAnnulment` is the only writer of
   `attendance_annulments`.** It only ever `create()`s, called from
   `ApplyAttendanceAdjustment` under the request's row lock, after validating the target is
   the requester's, exists, and isn't already annulled. `tests/Arch/ConventionsTest.php`'s
   *"only RecordAnnulment writes attendance_annulments"* mirrors the `attendance_logs` guard
   exactly (same grep-based write-form scan, same model-file exemption), and asserts
   `RecordAnnulment.php` is the sole match — a later action reading the table (e.g. the
   approval-time "already annulled?" check) does not trip it, only a write does.

**Approval is serialized, not just validated.** `ApproveRequest` takes `SELECT ... FOR
UPDATE` on the `requests` row before dispatching the effect, so two concurrent approvals on
the same request cannot both apply — the second blocks on the lock, then, once the first
commits, re-reads the row as no longer pending and takes the `409` branch instead of
double-applying. `tests/Feature/Attendance/ApproveRequestConcurrencyTest.php` proves this
with two genuinely separate Postgres backend sessions (a forked PHP process holding the lock
open), not just a sequential same-process retry.

### Media Library: the `media` table and the attachment disk

```sql
create table media (
  id                    bigint primary key generated always as identity,
  model_type            text not null,
  model_id              uuid not null,             -- uuidMorphs, not the package default
  uuid                  uuid unique,
  collection_name       varchar not null,
  name                  varchar not null,
  file_name             varchar not null,
  mime_type             varchar,
  disk                  varchar not null,
  conversions_disk      varchar,
  size                  bigint not null,
  manipulations         json not null,
  custom_properties     json not null,
  generated_conversions json not null,
  responsive_images     json not null,
  order_column          integer,
  created_at            timestamptz,
  updated_at            timestamptz
);

create index media_model_type_model_id_index on media (model_type, model_id);
```

Published by `spatie/laravel-medialibrary` and edited once: `morphs('model')` (the package
default, `bigint`) → `uuidMorphs('model')`, because every owning model's primary key here is
a uuidv7 string — the bigint form would silently truncate or fail to match. `Request`
implements `HasMedia`/`InteractsWithMedia` with a single `attachment` media collection
(`singleFile()`, so a re-upload replaces rather than appends), accepting only
`application/pdf`, `image/jpeg`, `image/png`.

**The `attachments` disk is S3-protocol, backed by RustFS in dev, `visibility: private`.**
`config('media-library.disk_name')` is `attachments`; `config('filesystems.disks.attachments')`
points at `ATTACHMENTS_S3_*` env vars (endpoint, key, secret, bucket, path-style addressing
— RustFS/MinIO need path-style, not vhost-style). There is no public URL generation and no
direct object link anywhere in the API: `GET /attendance/adjustments/{request}/attachment`
streams the file through the app after the same visibility check as the request's `show`
route (`03-api.md`), so RustFS itself is never reachable from outside the container network.
Feature tests use `Storage::fake('attachments')`; only `scripts/e2e-adjustments.sh` exercises
a live RustFS round-trip.

---

## What the schema refuses to allow

Stated plainly, since these are the reasons for the constraints above:

- Two employees cannot share an `employee_no`, and one employee cannot have two logins.
  (`unique` on `employee_no`, `unique` on `user_id`.)
- Two offices cannot share a `code`. (Global `unique` — the identity an office is
  referenced by on its own.)
- An employee cannot have two employment changes on the same effective date.
  (`unique (employee_id, effective_from)`.)
- The `current_*` cache cannot be written by anything but `RecordEmploymentChange`, and
  cannot drift from the history, because one transaction writes both. (Arch test +
  single-writer action.)
- A period's past state cannot be rewritten by a later change. (Append-only history;
  `effective_to` derived, never stored, so no row is ever mutated to "close" it.)
- An attendance punch cannot be edited or deleted, through the API or otherwise. A
  correction is a new (manual) row. (`RecordPunch` is the sole writer and only `create`s; no
  route mutates the table — arch guard + `AppendOnlyTest`.)
- A retried mutation cannot write a second row or replay another user's response.
  (`idempotency_keys`, user-scoped hash, key-and-row in one transaction.)
- A punch cannot be annulled twice, and nothing but `RecordAnnulment` can write
  `attendance_annulments`. (`unique(attendance_log_id)` + arch guard.)
- A pending request cannot be decided twice, by two approvers racing each other or by one
  approver double-clicking. (`SELECT ... FOR UPDATE` on the request row, re-checked as
  pending after the lock is acquired.)
