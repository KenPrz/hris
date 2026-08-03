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
  archived_at       timestamptz,               -- M8a: archive-never-delete (see below)
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);

create table departments (
  id          uuid primary key default uuidv7(),
  office_id   uuid not null references offices(id) on delete cascade,
  name        text not null,
  code        text not null,
  archived_at timestamptz,                     -- M8a: archive-never-delete (see below)
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

**`archived_at`: archive-never-delete, non-cascading *(M8a)*.** Neither an office nor a
department is ever `DELETE`d — the admin org-tree surface (`03-api.md`, §Admin — the
organization tree) has no `DELETE` route at all. Retiring one stamps a nullable
`archived_at` and nothing more; the default admin list hides archived rows, and
`?include_archived=1` reveals them. The stamp is deliberately **non-cascading**: archiving
an office does not archive its departments, and — critically — it does not touch the
`employees.current_office_id`/`current_department_id` snapshots or the historical
`employment_records` that point at it, so a closed office's payroll history and its
inspector-facing ledger stay intact and readable. (This is why archive, not delete: the
`on delete cascade` FKs above would take employment history with the row.) The three
org-tree models carry spatie's **`LogsActivity`** (M8a): every create/update/archive/unarchive
writes an `activity_log` row — `log_name` `organization`/`office`/`department`, `subject_id`
the row, the acting System Admin the `causer` — so a runtime change to the company's shape
is auditable even though there is no viewer endpoint yet (`scripts/e2e-admin-org.sh` asserts
the rows straight at the table).

---

## Employees: immutable identity plus a current-state cache

```sql
create table employees (
  id                    uuid primary key default uuidv7(),
  employee_no           text not null unique,

  -- The person's name (M8b). Filipino convention: middle_name is the mother's maiden
  -- surname and name_suffix carries Jr./Sr./III, so both are separate nullable columns
  -- rather than folded into one field. Composed for display by the `full_name` accessor
  -- (below), never stored — a stored copy would be a second source of truth to keep in sync.
  first_name            text not null,
  middle_name           text,
  last_name             text not null,
  name_suffix           text,

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
(`employee_no` and their name), when they joined and left, which organization they belong
to, and the optional `user_id` that links to a login. Everything that *does* change —
office, department, manager, employment type, Art. 82 status, base rate — lives in
`employment_records`, one row per change (below).

### The name, and `full_name`

Before M8b an employee had only an `employee_no`; every screen that needed a human label
fell back to it (`MNL-0001` where a name belonged — the M7b/M8a gap). M8b adds the four
name columns and one read model: **`Employee::full_name`** (a Laravel accessor, not a
column) composes `first middle last suffix`, collapsing any gap left by a null middle name
or suffix to a single space. Every API name field (`EmployeeResource`,
`EmployeeListResource`, `EmployeeDetailResource`) reads this accessor, so display is
composed in exactly one place. `employee_no` stays the immutable identity — a name edit
(`PATCH /admin/employees/{id}`, `03-api.md`) never touches it.

Employees now carry spatie's **`LogsActivity`** (the same trait the org tree grew in M8a):
onboarding and every name edit write an `activity_log` row — `log_name 'employee'`, logging
only `employee_no`, the four name fields, `organization_id`, `hired_at`, and `separated_at`
— so who renamed whom is on the record, not lost in an in-place update.

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
punch's id has no matching row in `attendance_annulments`. This was defined here because it
was the single most important thing for whoever built the M5 compute engine to get right
about attendance — **M5 reads the effective ledger, not the raw table**, via
`App\Domain\Attendance\EffectivePunches::forDate()` (below), M5a's real implementation of
exactly this query. M3.6 itself does **not** wire this filter into any read endpoint: `GET
/me/attendance` and
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
direct object link anywhere in the API: `GET /requests/{request}/attachment`
streams the file through the app after the same visibility check as the request's `show`
route (`03-api.md`), so RustFS itself is never reachable from outside the container network.
Feature tests use `Storage::fake('attachments')`; only `scripts/e2e-adjustments.sh` exercises
a live RustFS round-trip.

---

## Holidays: the per-office calendar *(M4a)*

```sql
create table holidays (
  id          uuid primary key default uuidv7(),
  office_id   uuid not null references offices(id) on delete cascade,
  date        date not null,                      -- a calendar date, no timezone
  day_type    text not null,
  name        text not null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),

  check (day_type in ('special_working','special_non_working','regular_holiday',
                       'double_regular_holiday')),
  unique (office_id, date)
);
```

**A holiday maps a calendar date to a non-`Ordinary` `DayType` (`01-architecture.md`,
`App\Domain\Pay\DayType`) — `Ordinary` is the absence of a row, never a stored value.**
`day_type` follows the same string-column-plus-`CHECK` pattern as `attendance_logs`'
`direction`/`source`/`verification`: plain `text` in Postgres, cast to the backed enum in
the model, with a `CHECK` mirroring the enum's non-`Ordinary` cases so the database still
rejects garbage. `HolidaySchemaTest` pins the `CHECK` list against `DayType::cases()` (minus
`Ordinary`) so the two cannot drift.

**`unique(office_id, date)` is the whole "one holiday per office per day" rule** — a second
`POST` for the same office and date is a Postgres unique-violation, not a silent duplicate or
an application-level pre-check that could race it.

Philippine holidays are set by annual presidential proclamation and the movable ones (Eid'l
Fitr, Eid'l Adha) genuinely move — this table is data precisely because a hardcoded calendar
would be wrong by January. `office_id` cascades on delete, the same as `departments` — a
holiday belonging to a deleted office is meaningless, not archival.

**Clone-from-previous-year copies month/day, never `+365` days.** `App\Actions\Holidays\CloneHolidays`
builds each target date from the source's month and day directly (`sprintf('%04d-%02d-%02d',
$toYear, $month, $day)`), so a `2023-03-15` source lands on `2024-03-15` even though 2024 is a
leap year — a naive `addDays(365)` would land on `2024-03-14` instead. A source date of Feb 29
with no Feb 29 in the target year is skipped outright (`checkdate()`), never slid to Mar 1. Any
target date already occupied is skipped, not overwritten, so cloning the same range twice
creates nothing the second time — re-runnable by design, not by accident.

### `OfficeScope`: the M4 config boundary

`App\Domain\Scope\OfficeScope` (`app/Domain/Scope/`) is `EmployeeScope`'s sibling for
per-office configuration — "which offices may this user administer," as a query constraint,
not a boolean:

- **System Admin** — every office (`Office::query()`, unconstrained).
- **HR Admin** — exactly the offices in their `hr_admin_offices` pivot
  (`administeredBy()` joins `hrAdminOffices()->pluck('offices.id')`).
- **Anyone else** — zero offices, forced empty (`whereRaw('1 = 0')`), never unconstrained.

Two pure, HTTP-agnostic helpers built on `administeredBy()`, used by every holiday **and
schedule** endpoint (M4b reuses the exact same two calls — see below):
`administered(User, ?officeId): ?Office` (the list/create/clone endpoints, which take an
office id in the request) and `administers(User, officeId): bool` (the update/delete
endpoints, which already have the record via route-model binding). Both hand the 404 decision
to the controller — `OfficeScope` itself only ever returns `null`/`false`/a constrained
query, never throws. See `05-rbac.md` for the full authority model and `03-api.md` for the
byte-identical-404 proof this scope makes possible.

---

## Shift templates, assignments, overrides, and the resolution chain: schedules *(M4b)*

```sql
create table shift_templates (
  id          uuid primary key default uuidv7(),
  office_id   uuid not null references offices(id) on delete cascade,
  name        text not null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);

create table shift_template_days (
  id                 uuid primary key default uuidv7(),
  shift_template_id  uuid not null references shift_templates(id) on delete cascade,
  weekday            smallint not null,     -- 0=Monday..6=Sunday (App\Domain\Schedule\Weekday)
  is_rest            boolean not null,
  start_minute       smallint,               -- null when is_rest
  end_minute         smallint,               -- null when is_rest; may exceed 1439 (cross-midnight)
  break_minutes      smallint,               -- null when is_rest
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),

  unique (shift_template_id, weekday),
  check (weekday between 0 and 6),
  check (   -- is_rest XOR hours
    (is_rest = true  and start_minute is null and end_minute is null and break_minutes is null)
    or
    (is_rest = false and start_minute is not null and end_minute is not null and break_minutes is not null)
  ),
  check (   -- working-row minute ranges; rest rows short-circuit
    is_rest = true or (
      start_minute >= 0 and start_minute < 1440
      and end_minute > start_minute and end_minute <= start_minute + 1440
      and break_minutes >= 0 and break_minutes < (end_minute - start_minute)
    )
  )
);

create table schedule_assignments (
  id                 uuid primary key default uuidv7(),
  shift_template_id  uuid not null references shift_templates(id) on delete cascade,
  employee_id        uuid references employees(id) on delete cascade,
  department_id      uuid references departments(id) on delete cascade,
  effective_from     date not null,
  created_by         uuid references users(id) on delete set null,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),

  check (   -- exactly one of employee_id / department_id
    (employee_id is not null and department_id is null)
    or (employee_id is null and department_id is not null)
  )
);
-- one row per target per effective date — see below for why these are PARTIAL indexes
create unique index schedule_assignments_employee_effective_unique
  on schedule_assignments (employee_id, effective_from) where employee_id is not null;
create unique index schedule_assignments_department_effective_unique
  on schedule_assignments (department_id, effective_from) where department_id is not null;

create table schedule_overrides (
  id             uuid primary key default uuidv7(),
  employee_id    uuid not null references employees(id) on delete cascade,
  date           date not null,
  is_rest        boolean not null,
  start_minute   smallint,
  end_minute     smallint,
  break_minutes  smallint,
  note           text,
  created_by     uuid references users(id) on delete set null,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),

  unique (employee_id, date)
  -- plus the same is_rest XOR hours / minute-range CHECKs as shift_template_days
);

alter table offices add column default_shift_template_id
  uuid references shift_templates(id) on delete set null;
```

**A shift template is a named week, not a date.** `shift_templates` carries just an
`office_id` and a `name`; the actual shape lives in `shift_template_days`, exactly seven
rows — one per `App\Domain\Schedule\Weekday` case, `0=Monday..6=Sunday`, the one int-backed
enum in the system, because a weekday's identity genuinely *is* an ordinal (aligned 1:1 with
the frontend's `weekdayIndex`), unlike `DayType` where the string itself is the meaning. Each
row is either `is_rest` (all three minute columns `null`) or a working day (all three
required) — the same is_rest-XOR-hours `CHECK` pattern on both `shift_template_days` and
`schedule_overrides`, so the database rejects a rest day carrying hours regardless of
whether a form got it right.

**Minutes, not times — and a cross-midnight shift is a range past 1440, never a wrap.**
`start_minute`/`end_minute` are minutes-since-midnight (`08:00` = 480), matching
`01-architecture.md`'s "never a float, never a `Date` object" rule for worked time. A shift
that crosses midnight (17:00 → 03:00) is `start_minute: 1020, end_minute: 1620` —
`end_minute` is allowed up to `start_minute + 1440`, one full day past start, rather than
wrapping back to a smaller number that would make `end > start` false and the shift look
zero- or negative-length. `App\Domain\Schedule\ResolvedSchedule::scheduledMinutes` is always
`(end - start) - break` (`0` for a rest day), computed the same way whether or not the shift
crosses midnight.

**`schedule_assignments` targets exactly one of an employee or a department, never both,
never neither** — one `CHECK` makes the two mutually exclusive, and two *partial* unique
indexes (one scoped to `employee_id IS NOT NULL`, one to `department_id IS NOT NULL`)
enforce "one assignment per target per effective date." A plain `unique(employee_id,
effective_from)` would not work here: Postgres treats `NULL` as distinct from every other
`NULL` in a unique index, but only one of the two target columns is ever populated per row,
so an unscoped index would silently let duplicate department-only rows through (or vice
versa) — the partial index is what actually enforces one-per-target.

**`schedule_overrides` is the per-date exception, one row per `(employee_id, date)`.** It
carries the same is_rest-XOR-hours shape as a template day, plus a free-text `note` for why
— a rest-day swap, a one-off early release — that a template day has no room for.

**`offices.default_shift_template_id` is the resolution chain's floor.** Nullable, `on
delete set null` (deleting a template a caller has retired never orphans the office row),
and gated from the *other* side by `App\Exceptions\Domain\TemplateInUse` — a template that
is either an office's default or still pointed at by a `schedule_assignments` row refuses to
be deleted (`422 template_in_use`) rather than leaving a dangling reference or a silent
`NULL` an employee's schedule would then have nowhere to fall back to.

**`App\Domain\Schedule\ScheduleResolver` is the one place "what is this employee scheduled
to work on this date" is answered**, for a single `(employee, date)` pair — a pure read, no
transaction, no writes: the single interface M5's compute engine calls (`ComputeDailySummary`,
above). It walks, in order, and returns the first hit:

1. **`schedule_overrides`** for that exact `(employee_id, date)` — `source: "override"`.
2. **The employee's own `schedule_assignments` row** with the latest `effective_from` on or
   before the date — `source: "employee"`.
3. **The employee's `current_department_id`'s `schedule_assignments` row**, same
   latest-effective-from rule — `source: "department"`.
4. **The employee's `current_office_id`'s `default_shift_template_id`** — `source:
   "office_default"`. If the employee has no current office, `EmployeeHasNoOffice`; if the
   office has no default template, `OfficeHasNoDefaultTemplate` — both `422`s, since
   resolution genuinely cannot proceed any further.

---

## Pay rules: effective-dated rates, floored by law *(M4c)*

```sql
create table pay_rules (
  id                    uuid primary key default uuidv7(),
  effective_from        date not null unique,
  overtime_ordinary_bp  integer not null,
  overtime_premium_bp   integer not null,
  night_diff_bp         integer not null,
  note                  text,
  created_by            uuid references users(id) on delete set null,
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now(),

  check (overtime_ordinary_bp >= 0 and overtime_premium_bp >= 0 and night_diff_bp >= 0)
);

create table pay_rule_day_rates (
  id             uuid primary key default uuidv7(),
  pay_rule_id    uuid not null references pay_rules(id) on delete cascade,
  day_type       text not null,
  worked_bp      integer not null,
  worked_rest_bp integer not null,
  unworked_bp    integer not null,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now(),

  check (day_type in ('ordinary','special_working','special_non_working',
                       'regular_holiday','double_regular_holiday')),
  check (worked_bp >= 0 and worked_rest_bp >= 0 and unworked_bp >= 0),
  unique (pay_rule_id, day_type)
);
```

**A `pay_rules` row is a version, not a setting.** `effective_from` is `unique` — two
versions cannot take effect the same day — and there is deliberately no `PATCH` route
(`03-api.md`): a rate correction is always a *new* version, effective from a later date,
read alongside every earlier one, never an edit in place. `App\Http\Controllers\Admin\PayRules\DeleteController`
is the only other write a version ever sees after creation, and it cascades onto its five
`pay_rule_day_rates` rows via the FK.

**Three scalars live directly on `pay_rules`** — `overtime_ordinary_bp`, `overtime_premium_bp`,
`night_diff_bp` — because they don't vary by `DayType`; the five `day_type`-keyed rows in
`pay_rule_day_rates` (`unique(pay_rule_id, day_type)`, one row per non-nullable
`App\Domain\Pay\DayType` case, `Ordinary` included this time — unlike `holidays`, a pay
rule prices every kind of day an employee can work, not just the non-ordinary ones) carry
`worked_bp`/`worked_rest_bp`/`unworked_bp`, the same worked/worked-on-a-rest-day/unworked
three-way split M1's premium matrix already encodes. `pay_rule_day_rates.day_type` follows
the same string-column-plus-`CHECK` pattern as `holidays.day_type` — plain `text` in
Postgres, cast to the backed enum in the model, with a `CHECK` mirroring
`DayType::cases()` so the database still rejects garbage regardless of what the model
layer does.

**Every rate is validated against a statutory *floor* before it is ever validated against
the database.** `App\Domain\Pay\StatutoryFloor::violations()` — pure, framework-agnostic,
no config read of its own — compares a proposed matrix cell-by-cell against
`config('hris.pay_floors')` (`04-backend-conventions.md`), which encodes the same DOLE
minimums M1's premium matrix was built against (Arts. 86-94): worked floors of
100%/130% (ordinary, not-rest/rest), 100%/130% (special working), 130%/150% (special
non-working), 200%/260% (regular holiday), 300%/390% (double regular holiday); unworked
floors of 0% for ordinary/special (no work, no pay) and 100%/200% for regular/double
regular holiday (paid even unworked); plus the 125%/130% overtime and 110%
night-differential scalar floors. A cell sitting *exactly* at the floor is compliant;
only strictly-below is a violation. `App\Actions\PayRules\CreatePayRule` runs this check
before its transaction ever opens — a below-floor write never reaches the database at
all, and refuses with every violating cell at once
(`App\Exceptions\Domain\PayRateBelowFloor`, `03-api.md`), not one field at a time.

**The duplicate-`effective_from` guard is the unique constraint itself, not an
`exists()` pre-check.** Unlike `CreateHoliday`, there is no parent row to `lockForUpdate()`
first — a pay rule is a company singleton, not a child of some office row — so
`CreatePayRule` tries the insert and translates a `UniqueConstraintViolationException` into
the clean `App\Exceptions\Domain\PayRuleExists` (`409`, `03-api.md`), which is race-safe
(two concurrent creates on the same date can't both succeed) and covers the sequential
duplicate identically.

**Resolution is effective-dated, the same shape as `employment_records`':** the version
that applies to a given worked date is the one with the greatest `effective_from` on or
before that date. Nothing resolved this at the time M4c shipped — M5a's `ComputeDailySummary`
is now the first and only reader (see the compute-engine section below).

**`created_by` is nullable and `on delete set null`**, matching `schedule_assignments`/
`schedule_overrides` — a version created by a since-deleted user's account is not
meaningless the way a version belonging to a deleted office would be, so it survives the
user's deletion rather than cascading.

`PayRule`'s `Spatie\Activitylog\Traits\LogsActivity` (log name `pay_rule`) logs every
create/delete with the `PayRule` itself as the uuid-morph subject, causer resolved
automatically from the authenticated guard, `logOnlyDirty()` on the five logged columns
(`effective_from`, the three scalar rates, and `note`) (`pay_rule_day_rates` rows are not
separately logged — they are created once,
atomically, with their parent and never edited).

---

## Daily attendance summaries and lines: the compute engine's priced output *(M5a)*

```sql
create table daily_attendance_summaries (
  id                 uuid primary key default uuidv7(),
  employee_id        uuid not null references employees(id) on delete cascade,
  date               date not null,
  office_id          uuid references offices(id) on delete set null,   -- SNAPSHOT (M5b)
  day_type           text not null,
  is_rest_day        boolean not null,
  scheduled_minutes  integer not null,
  is_art82_exempt    boolean not null,
  rule_version_id    uuid references pay_rules(id) on delete restrict,   -- nullable
  worked_minutes     integer not null,
  late_minutes       integer not null,
  undertime_minutes  integer not null,
  unpaid_overtime_minutes integer not null default 0,   -- M6c: worked overtime beyond the approved cap
  status             text not null default 'pending',
  is_incomplete      boolean not null default false,
  computed_at        timestamptz,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now(),

  check (day_type in ('ordinary','special_working','special_non_working',
                       'regular_holiday','double_regular_holiday')),
  check (status in ('pending','computed','disputed','locked')),
  check (scheduled_minutes >= 0 and worked_minutes >= 0
         and late_minutes >= 0 and undertime_minutes >= 0
         and unpaid_overtime_minutes >= 0),
  unique (employee_id, date)
);

create index daily_attendance_summaries_office_id_date
  on daily_attendance_summaries (office_id, date);   -- M5b: AffectedSummaries::forHoliday/forOffice

create table daily_summary_lines (
  id          uuid primary key default uuidv7(),
  summary_id  uuid not null references daily_attendance_summaries(id) on delete cascade,
  kind        text not null,
  minutes     integer not null,
  applied_bp  integer not null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),

  check (kind in ('regular_day','regular_night','overtime_day','overtime_night',
                  'holiday_unworked')),
  check (minutes > 0 and applied_bp >= 0),
  unique (summary_id, kind)
);
```

**One row per employee-day (`unique(employee_id, date)`), and every value on it is a
snapshot, never a live join.** `office_id` (added M5b) is one more snapshot in that same
family — the employee's resolved office *on the date being computed*, captured the same
way `attendance_logs.office_id` snapshots at punch time (`02-data-model.md`'s Attendance
section, above). It exists so a config-change recompute can find "every existing summary
for office X" (`App\Domain\Compute\AffectedSummaries::forHoliday`/`forOffice`) without
joining out to `employment_records` for an effective-dated office lookup on every row — the
same "keep the hot scoping query a plain `WHERE`" reasoning `employees.current_office_id`
was built on. Nullable and `on delete set null` (not `cascade`) because a summary is a
computed historical fact about a worked day, not a child record of the office the way a
department or a holiday is — deleting an office should not silently delete the pay history
of everyone who once worked there. `day_type`, `is_rest_day`, `scheduled_minutes`,
`is_art82_exempt` are the business context *as it was resolved on the date being
computed*, not as it reads today — so a summary keeps answering "what was true about this
day" correctly even after a holiday is added retroactively, a schedule changes, or an
employee is promoted out of Art. 82 exemption next month. `status` defaults to `pending`
in the schema, but every row `App\Actions\Compute\ComputeDailySummary` writes is
`computed`; `disputed` is a read column value with no writer yet. `locked` **now has a
writer**: M7a's `App\Actions\Cutoff\CloseCutoff` flips every in-period summary to `locked`
when its office's cutoff period closes, and `ReopenCutoff` flips it back to `computed` — see
`cutoff_periods` below.

**The compute pipeline, one piece at a time** (`App\Actions\Compute\ComputeDailySummary`):

1. **Resolve the day's business context**, each from the record effective *on the date
   being computed*, never from a `current_*` cache: `App\Domain\Employment\EmploymentResolver::on()`
   (the same effective-dated lookup `employment_records` above defines) supplies
   `is_art82_exempt` and the office to check for a holiday — falling back to
   `Employee::current_office_id` and "not exempt" only for a date before the employee's
   first employment record. `day_type` is `Ordinary` unless an M4a `holidays` row exists
   for that `(office, date)`. The schedule comes from `App\Domain\Schedule\ScheduleResolver`
   (M4b). The `pay_rules` version is the greatest `effective_from <= date` (M4c);
   `App\Support\PayRatesFactory::fromVersion()` turns it into a `PayRates` matrix, or
   `PayRatesFactory::statutory()` (reading `config('hris.pay_floors')`) stands in when no
   version has been configured yet for that date.
2. **Gather the day's effective punches.** `App\Domain\Attendance\EffectivePunches::forDate()`
   reads the append-only `attendance_logs` ledger for the employee's *shift window* (the
   local calendar day, or later if the schedule's end minute runs past midnight — the
   cross-midnight shift case), excludes anything an `attendance_annulments` row has voided,
   and expresses every surviving punch as minutes from that date's local midnight. This is
   the one place M3's raw, corrigible ledger and M3.6's annulments both get read together,
   as the effective truth of what happened — never merged or rewritten, only filtered.
3. **Price it.** The pure `App\Domain\Compute\DailyComputation::compute()` pairs the
   punches, nets out the meal break, splits the net total into regular vs. overtime against
   `scheduledMinutes`, splits each of those into day vs. night, and prices each of the (up
   to four) non-zero buckets through `App\Domain\Pay\PayMultiplier`, which reads the
   resolved `PayRates` rather than a hardcoded matrix — the same reconciliation that lets
   M1's statutory-floor tests and a live `pay_rules` version share one pricing function. An
   odd punch count is `is_incomplete`: zero worked minutes, no lines, no late/undertime — a
   guess is never persisted where an adjustment request (M3.6) belongs instead.
4. **Persist idempotently.** `ComputeDailySummary::execute()` `lockForUpdate()`s the
   employee row, deletes any existing summary for `(employee_id, date)`, and inserts the
   fresh one plus its lines in the same transaction — so calling it twice for the same day
   (two rapid punches each triggering their own compute, or a future manual recompute)
   yields exactly one summary and never a duplicate line, with no upsert/on-conflict
   trickery needed. **A punch-derived, rule-priced line is only ever persisted when a
   `pay_rules` version was actually configured for that date** — for those lines,
   `rule_version_id` is non-null precisely when the summary carries one, and null precisely
   when it doesn't (no configured version, or the calculator itself produced none: an
   incomplete day, an unworked rest day, an unworked ordinary/special day, …). This action
   never attributes a priced line to a rate that wasn't actually in effect.
   **`leave_with_pay` (M6b-b, below) is the one line kind exempt from that gate**: it's a
   flat 100%, resolved from `App\Domain\Leave\LeaveDayLookup` rather than the `pay_rules`
   matrix, so it persists even on a date with no configured version — but `rule_version_id`
   still stays null whenever `leave_with_pay` is the ONLY line the day carries, even if a
   `pay_rules` version IS configured for that date, since no rule actually priced it. **The
   corrected invariant, superseding an earlier draft of this document that read "any summary
   with lines has a non-null `rule_version_id`": a summary carries a non-null
   `rule_version_id` exactly when it has at least one punch-derived, rule-priced line —
   never merely because it has lines at all.**

**No pesos anywhere in either table.** Every minute column (`worked_minutes`,
`late_minutes`, `undertime_minutes`, `scheduled_minutes`, `daily_summary_lines.minutes`) is
an integer minute count, and `applied_bp` is an integer basis-point multiplier (`10000` =
100%) — never a peso amount. This is the invariant `01-architecture.md` states for the
whole system: the compute engine answers "how many premium-weighted hours," not "how many
pesos" — turning a priced line into money is a gross-to-net decision this HRIS deliberately
defers (`00-overview.md`).

**`daily_summary_lines` holds one row per non-zero bucket, not one row per kind
regardless.** The four worked kinds (`regular_day`/`regular_night`/`overtime_day`/
`overtime_night`) are peers — a plain on-schedule day entirely in daylight still gets a
`regular_day` line (its `applied_bp` simply reads 100% for an ordinary day), while a rest
day nobody was expected to work, or a plain absence, gets no lines at all. `holiday_unworked`
is the one kind with no `is_overtime`/`is_night` axis: it prices a *paid* holiday (regular
or double-regular) that a non-exempt employee did not work, at the unworked rate from
`PayRates::unworked()` — the one case `DailyComputation` prices a day with zero punches.

**`rule_version_id` is `nullable` but `RESTRICT`s, not `SET NULL`, on delete — the M4c
seam made durable.** A pay-rule version can never be deleted while any summary is stamped
with it: once a rate has actually priced a real day, retracting it would silently
re-attribute history to a rate that was never in effect. (`pay_rules` itself has no delete
guard of its own — `App\Http\Controllers\Admin\PayRules\DeleteController`, `03-api.md` —
so this FK is the only thing standing between a careless `DELETE` and a corrupted audit
trail; `scripts/e2e-compute.sh` proves the refusal against the live database.) Nullable
because a summary can be legitimately priced against the statutory floor with nothing
persisted for it to reference — see step 4 above — or because the only line it carries is
`leave_with_pay` (M6b-b, below), which is never priced from a `pay_rules` version at all.

**The synchronous on-write trigger.** `App\Actions\Attendance\RecordPunch` — the sole
writer of `attendance_logs` — registers a `DB::afterCommit()` callback that calls
`ComputeDailySummary::execute()` for the punch's own office-local date, once the
*outermost* transaction commits (whether that's a direct punch or `RecordPunch` running
nested inside `ApplyAttendanceAdjustment`/`ApproveRequest`'s transaction for an approved
add/amend). A compute failure therefore can never roll back an already-durable punch.
`EmployeeHasNoOffice`/`OfficeHasNoDefaultTemplate` (both raised by `ScheduleResolver`) are
caught and logged rather than propagated — "no schedule configured for this employee-day
yet" is an expected pre-M4 state, not a compute bug; anything else still surfaces
uncaught. There is no batch/range recompute yet — that is M5b's `RecomputeRange`, which
will need to resolve the one window-overlap case `EffectivePunches`' own doc comment
already flags: a repeating cross-midnight shift's day-N window and day-N+1's window can
overlap, which does not arise for M5a's single-occurrence scope.

`DailyAttendanceSummary`'s `Spatie\Activitylog\Traits\LogsActivity` (log name
`daily_attendance_summary`) logs every field above with `logOnlyDirty()`; `daily_summary_lines`
rows are not separately logged, the same "created once with its parent, never edited"
reasoning `pay_rule_day_rates` already uses.

**`EffectivePunches::forDate()`'s window now tiles across consecutive days instead of
overlapping — the deferred item M5a's own roadmap entry flagged for M5b.** A *repeating*
cross-midnight shift's day-N window (which runs past local midnight to catch the
post-midnight out-punch) used to start at day N's local midnight regardless of what day
N-1's own window already claimed, so a punch inside `[00:00, day-N-1's scheduled end -
1440]` was claimable by both `forDate(N-1)` and `forDate(N)` — a real double-claim on a
genuinely repeating night shift, not merely a theoretical one. `windowStartMinutes()`
fixes it: it resolves day N-1's schedule and, if that shift ran past midnight
(`endMinute > 1440`), starts day N's window at `endMinute - 1440` instead of `0` — the
minutes before that point already belong to day N-1's window. `forDate()` also treats a
bounded start as *exclusive* (`>` instead of `>=`), so the exact boundary instant — a punch
timestamped precisely at the previous window's end — is claimed by exactly one of the two
dates, never both and never neither. A normal (non-repeating, non-cross-midnight, or rest)
previous day leaves the start at `0`, unchanged from M5a.

---

## Recompute: an audited, queued re-price of existing summaries *(M5b)*

**M5b completes M5.** M5a's only writer of `daily_attendance_summaries` was the
synchronous on-punch trigger — nothing re-priced a day after the fact. M5b adds the other
half: a config change (a holiday added/edited/deleted, a new `pay_rules` version, a shift
template/assignment/override/office-default change) enqueues an audited, queued recompute
of every summary that change could have affected.

```sql
create table recompute_runs (
  id           uuid primary key default uuidv7(),
  trigger_type text not null,
  trigger_id   uuid,                                    -- nullable: some triggers name no single row
  reason       text not null,
  pair_count   integer not null,
  batch_id     text,                                    -- Bus::batch's own id, nullable until dispatch
  status       text not null default 'queued',
  caused_by    uuid references users(id) on delete set null,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now(),

  check (trigger_type in ('holiday', 'pay_rule', 'shift_template', 'schedule_assignment',
                          'schedule_override', 'office_default')),
  check (status in ('queued', 'completed', 'failed')),
  check (pair_count >= 0)
);
```

**One row per `RecomputeRange::dispatch()` call, audited the same
string-column-plus-`CHECK` way every other enum-shaped column in this schema is.**
`trigger_type` names which of the six config-change kinds fired the run
(`App\Domain\Compute\RecomputeTrigger`, a plain framework-agnostic enum); `trigger_id` is
the specific row that changed where one exists (a holiday's id, a pay rule's id) and
`null` for a trigger with no single natural id (deleting a schedule assignment still names
the assignment's own id, so in practice every current trigger does supply one, but the
column stays nullable for a future trigger that might not). `reason` is a human-readable
sentence naming what changed (`"Holiday {id} created for office {id} on {date}"`), for the
same "read the trail without joining five tables to reconstruct it" reason
`CloneHolidays`'s own activity-log summary exists. `pair_count` is the deduped
`(employee_id, date)` pair count the run dispatched — the size of the batch, captured at
creation so it survives even if every underlying `RecomputeDay` job is later pruned.
`batch_id` is Laravel's own `Bus::batch()` id, written back onto the row **after**
`dispatch()` returns (the id doesn't exist before dispatch), so it can be cross-referenced
against Laravel's own `job_batches` table for job-level detail this table doesn't
duplicate. `caused_by` is nullable and `on delete set null` — the same reasoning
`pay_rules.created_by`/`schedule_assignments.created_by` already use — because a recompute
triggered by a since-deleted user's edit is not meaningless the way one belonging to a
deleted office would be.

**`App\Actions\Compute\RecomputeRange::dispatch(pairs, trigger, triggerId, reason,
causedBy): ?RecomputeRun`** is the one place a `recompute_runs` row is written. It:

1. **Dedups** the incoming `(employee_id, date)` pairs — `collect($pairs)->unique(...)` —
   so a pair named by more than one resolver (a holiday added the same day a pay rule
   changes, say) is recomputed exactly once, not once per reason it had.
2. **Empty pairs is a clean no-op**: `dispatch()` returns `null` and writes nothing at
   all — a config change with no existing affected summaries (nothing has been computed
   for that office/date/employee yet) never produces a `recompute_runs` row with
   `pair_count: 0`, because there is nothing to audit having happened.
3. Otherwise **creates the `recompute_runs` row `status: 'queued'`**, then dispatches a
   named `Bus::batch()` of one `App\Jobs\RecomputeDay` per deduped pair, writing the
   batch's own id back onto the row. The batch's `->then()` callback flips the row to
   `'completed'` once every job in it finishes; `->catch()` flips it to `'failed'` if any
   job throws. The row therefore always ends in one of `queued` (still running, or the
   process crashed before the batch finished) → `completed`/`failed`, never silently stuck
   claiming success it didn't earn.

**`App\Domain\Compute\AffectedSummaries` resolves a config change to the exact
`(employee_id, date)` pairs to recompute — existing summaries only.** `forHoliday`,
`forPayRule`, `forShiftTemplate`, `forEmployee`, and `forOffice` each query
`daily_attendance_summaries` directly and never fabricate a pair for a day nothing has
computed yet — there is nothing to recompute until `ComputeDailySummary` has run once for
that `(employee, date)`. **Over-inclusion is deliberately safe, not merely tolerated**:
because `ComputeDailySummary::execute()` is itself idempotent (`lockForUpdate()`s the
employee row, deletes any existing summary for the day, inserts the fresh one), recomputing
a summary that turns out unaffected by the actual change costs nothing beyond the extra
job — the reverse, silently skipping a summary that *was* affected, is the actual bug this
class exists to prevent. `forHoliday`/`forPayRule` narrow by date (the config itself names
an exact date or a clean `effective_from` lower bound, so narrowing there is exact, not an
approximation); `forShiftTemplate`/`forEmployee`/`forOffice` recompute every existing
summary for the affected employees, full stop.

**One config-adjacent edit is deliberately outside this recompute contract: a retroactive
`employment_records` change.** A summary snapshots `is_art82_exempt` and `office_id` from the
record effective on its date, so an employment change recorded effective a *past* date (a
back-dated exemption or transfer) would leave already-computed days stale — and art82 exemption
suppresses *every* premium, so that delta is large. M5b's trigger set is the six config-spine
changes (holidays, pay rules, schedules); `RecordEmploymentChange` is not among them. This is a
known boundary, not a regression (M5a already snapshotted art82 with no recompute): a later
milestone adds a `RecomputeTrigger::Employment` case (the `forEmployee` resolver already exists
for it), or an explicit operational rule that a back-dated employment edit is followed by a
manual recompute of the affected range.

**`App\Jobs\RecomputeDay` is the queued unit of work — `ShouldQueue` + `Batchable` +
`InteractsWithQueue`, carrying only `$employeeId`/`$date` (ids, never a model)** — a job is
serialized onto the queue connection, and an id round-trips through that cleanly where a
full `Employee` model would go stale between dispatch and execution. Its `handle()` is a
strict no-op over three cases before it ever calls `ComputeDailySummary::execute()`: the
batch was cancelled (`$this->batch()?->cancelled()`), the employee no longer exists, or —
the one that matters most — **the existing summary for `(employeeId, date)` is already
`status: 'locked'`**. A locked period's numbers are frozen (M7's cutoff close); the job
does not delete it, does not recompute it, does not touch it at all. Both `Batchable` *and*
`InteractsWithQueue` are required on the class, not just the one `Batchable` needs for its
own `cancelled()` check — `CallQueuedHandler::ensureSuccessfulBatchJobIsRecorded()` will not
call `$batch->recordSuccessfulJob()` without both present, and without that call every
batch containing this job would sit at `pending_jobs > 0` forever, so `RecomputeRange`'s
`->then()` callback (the thing that ever flips a `recompute_runs` row to `completed`) would
never fire.

**No `attendance_logs` row is ever mutated by any of this — the append-only ledger stays
append-only across a recompute exactly the way it stays append-only across an approved
adjustment (M3.6, above).** `RecomputeDay`/`ComputeDailySummary` only ever read
`attendance_logs` (via `EffectivePunches::forDate()`) and write
`daily_attendance_summaries`/`daily_summary_lines`; nothing in the recompute path calls
`create`/`update`/`delete`/`save` against `AttendanceLog`, so the same arch guard that
proves `RecordPunch` is the sole writer (above) already covers this by construction rather
than needing a parallel M5b-specific rule. `scripts/e2e-recompute.sh` proves it live: a
Manila employee's `attendance_logs` rows — same ids, same order — before and after a
holiday-triggered recompute of their day.

**Every config-change action that can invalidate an existing summary wires the same
`DB::afterCommit(() => RecomputeRange::dispatch(...))` shape**, mirroring `RecordPunch`'s
own on-write trigger (M5a, above) so a recompute-enqueue failure can never roll back an
already-durable config write:

| Action | Trigger | Resolver |
| --- | --- | --- |
| `Holidays\CreateHoliday` / `UpdateHoliday` / `DeleteHoliday` | `holiday` | `forHoliday(officeId, [date])` |
| `Holidays\CloneHolidays` | `holiday` | `forHoliday(officeId, createdDates)` — `[]` for a brand-new date, so this is a real no-op in practice |
| `PayRules\CreatePayRule` | `pay_rule` | `forPayRule(effectiveFrom)` |
| `Schedules\CreateShiftTemplate` / `UpdateShiftTemplate` / `DeleteShiftTemplate` | `shift_template` | `forShiftTemplate(templateId)` |
| `Schedules\CreateScheduleAssignment` | `schedule_assignment` | `forEmployee`/`forOffice`-shaped, by target |
| `Office\Schedules\DeleteAssignmentController` (inline — no dedicated Action class; a single unconditional write with no business rule beyond the scope check) | `schedule_assignment` | same, by target |
| `Schedules\CreateScheduleOverride` / `UpdateScheduleOverride` / `DeleteScheduleOverride` | `schedule_override` | `forEmployee(employeeId)` |
| `Office\Schedules\SetDefaultTemplateController` (inline — same "no Action class needed" reasoning as the assignment delete) | `office_default` | `forOffice(officeId)` |

**M4's original "Done when" line — HR adds a holiday, affected Manila days flip 100% →
130%, Cebu is untouched — is only provable end to end as of this milestone**, because it
needs an engine to turn a `DayType` into a multiplier (M5a) and a trigger that actually
re-runs that engine over already-computed days (M5b, here). `scripts/e2e-recompute.sh`
walks exactly this: a seeded ordinary day, an HR-created `special_non_working` holiday, the
queue drained, the flip to 130%, the audited `recompute_runs` row, and the untouched ledger.

**M7a resolved the forward note this section used to carry.** `RecomputeDay`'s locked-skip
(`$existing?->status === 'locked'`) was a plain, unlocked `first()` read — correct for M5b,
where nothing raced to lock a summary out from under a recompute. M7a's `CloseCutoff` now
does set `status: 'locked'` on a batch of summaries, so a close and a `RecomputeDay` racing
the same row is a real concurrency question — and it is answered the predicted way:
`RecomputeDay` takes the affected employee's `Employee` row lock (the same one `CloseCutoff`
holds) before its read, so the close-vs-recompute race serializes rather than interleaves.
`CloseVsRecomputeConcurrencyTest` proves it with two real Postgres connections, the precedent
`ApproveRequestConcurrencyTest` (M3.6, above) set — not a single-process sequential test,
which `04-backend-conventions.md` calls out as worse than no test at all. See `cutoff_periods`
above and `06-roadmap.md`'s M7a section.

---

## Leave: per-office types, the append-only ledger, and derived balances *(M6b-a)*

**M6b-a is the leave foundation only** — per-office leave-type config, HR's ability to
manually credit a balance, and reading it back. It deliberately does **not** include an
employee filing a leave request, the manager→HR two-step approval chain that request will
need, tenure-based accrual, carryover, cash-out, or the compute engine reading leave
alongside attendance — all of that is M6b-b and later. See `docs/06-roadmap.md`.

```sql
alter table offices add column minutes_per_leave_day smallint not null default 480;
```

**The nominal length of a leave day, per office** — the divisor `App\Domain\Leave\LeaveUnit`
uses to convert a leave amount an employee actually thinks in (days, half a shift, hours,
minutes) into the integer minutes every ledger row and balance is stored and summed in.
Default 480 (8h) so a fresh office needs no configuration to behave sensibly; `PATCH
/office/leave-day` (`App\Actions\Offices\SetOfficeLeaveDay`) is the only writer, scoped by
`OfficeScope::administered` the same as every other per-office config write in this
document.

```sql
create table leave_types (
  id                     uuid primary key default uuidv7(),
  office_id              uuid not null references offices(id) on delete cascade,
  name                   text not null,
  code                   text,                          -- slug for a seeded statutory type; null for ad-hoc
  is_paid                boolean not null default true,
  requires_attachment    boolean not null default false,
  deducts_balance        boolean not null default true,  -- false = event entitlement (Maternity etc.)
  is_cash_convertible    boolean not null default false,
  max_carryover_minutes  integer,                        -- null = unlimited; the year-end job that reads it is deferred
  is_active              boolean not null default true,
  created_at             timestamptz not null default now(),
  updated_at             timestamptz not null default now(),

  check (max_carryover_minutes is null or max_carryover_minutes >= 0),
  unique (office_id, code)        -- one 'sil' per office; multiple null codes allowed (Postgres treats NULLs distinct)
);
```

**`deducts_balance` is the whole balance-vs-event axis.** `true` (SIL, VL, SL) means the
type holds a balance an employee is granted *into* and later spends *from* — a real number
that can run out. `false` (Maternity, Paternity, Solo Parent, VAWC, Magna Carta) means an
entitlement keyed to a qualifying event, never banked, never granted a balance — the ledger
never credits or debits one, in this milestone or the next. `App\Actions\Leave\GrantLeave`
enforces this at the write boundary: a manual grant against a `deducts_balance: false` type
is refused (`App\Exceptions\Domain\LeaveTypeNotGrantable`, `422
leave_type_not_grantable`) rather than silently crediting a balance the type was never
supposed to have.

**No delete route.** A retired leave type is set `is_active: false` via `PATCH
/office/leave-types/{leaveType}`, never removed — the same "archive, never delete" instinct
M8 states outright for employment records, applied here because a deleted type would orphan
every `leave_ledger` row that ever referenced it. `CreateLeaveTypeRequest`/`SetLeaveDayRequest`
follow the same shape-only-validation, `office_id` never `exists:offices,id`, that every
other office-scoped config write in this document uses — the 404-not-403 discipline needs
the scope check to happen in the controller, uniformly, or a fabricated office id would 400
while an out-of-scope real one 404s, reopening the enumeration oracle that discipline exists
to close.

`CompanySeeder` seeds every office with the full PH statutory + company set on a fresh
`make dev`: **SIL** (`deducts_balance: true`, cash-convertible — Art. 95), the five event
types **Maternity / Paternity / Solo Parent / VAWC / Magna Carta** (`deducts_balance:
false`), and the two company benefits **VL / SL** (`deducts_balance: true`, not statutory,
given the same balance shape as SIL). None of this seeding grants a single day — every
balance starts empty; a grant is always a deliberate, logged HR action.

```sql
create table leave_ledger (
  id            uuid primary key default uuidv7(),
  employee_id   uuid not null references employees(id),
  leave_type_id uuid not null references leave_types(id),
  entry_type    text not null,                     -- 'credit' | 'debit'
  minutes       integer not null,
  reason        text not null,
  source        text not null,                     -- 'manual_grant' today; taking/accrual widen this in M6b-b+
  request_id    uuid references requests(id) on delete set null,   -- null until a leave request writes here (M6b-b)
  created_by    uuid not null references users(id),
  created_at    timestamptz not null,               -- no updated_at — append-only

  check (entry_type in ('credit','debit')),
  check (source in ('manual_grant')),
  check (minutes > 0)
);

create index leave_ledger_employee_id_leave_type_id_index on leave_ledger (employee_id, leave_type_id);
```

**The leave bank statement — append-only, exactly the way `attendance_logs` and
`attendance_annulments` are (M3, M3.6, above).** `App\Actions\Leave\GrantLeave` is the sole
writer and only ever `create`s; the model manages `created_at` alone (`const UPDATED_AT =
null`), so an already-persisted row cannot pick up an `updated_at` even by an accidental
`save()`. **A correction is a second row, never an edit of the first** — re-granting is a
second credit, and the day M6b-b's leave-taking ships, an over-grant gets fixed by a
compensating debit row, not by rewriting the original credit.

**Balances are DERIVED, never stored — there is no balance column anywhere in this schema.**
`App\Domain\Leave\LeaveBalances::forEmployee()` is the one place that turns the ledger into a
number: `SUM(credit) - SUM(debit)`, grouped by `leave_type_id`, computed fresh on every read.
The same reasoning POS applied to stock applies here — a mutable running total invites drift
between the number and the rows that are supposed to explain it; a derived sum cannot drift,
because it *is* the rows. `request_id` is nullable and unused by M6b-a (every row today has
`source: 'manual_grant'` and no request); it exists now so M6b-b's leave-taking debit can
reference the request that produced it without a later migration.

**Reading a balance** (`GET /me/leave`, `GET /employees/{employee}/leave`) merges every
*active*, *balance-deducting* leave type in the employee's current office against
`LeaveBalances::forEmployee()` — an absent key means a real zero balance, not that the type
doesn't exist, since the ledger has no row at all until a first grant. Both routes return the
identical shape; `GET /me/leave` is the caller's own record, `GET
/employees/{employee}/leave` is broader (self, direct reports, or HR-office members, via
`EmployeeScope::visibleTo`, `03-api.md`) — deliberately wider than *granting*
(`OfficeScope::administers`, HR-only), because a manager may see a direct report's balance
even though only HR may credit it. Both 404, not 403, on an out-of-scope employee, the same
discipline every scoped read in this document already keeps.

**Grants are HR-over-the-office, not manager-over-report.** `POST /leave/grants`
(`App\Http\Controllers\Leave\GrantController`) scopes via `OfficeScope::administers` against
the **employee's current office** — not `EmployeeScope`, which would also let a manager
grant into their own direct reports' balances. Every lookup in the grant path (employee,
leave type) 404s uniformly on failure, so an out-of-scope subject and a nonexistent one stay
indistinguishable to the caller. `scripts/e2e-leave-foundation.sh` proves the whole path
live: HR creates an office-scoped leave type, confirms the office's leave day, grants an
employee 5 days as one 2400-minute credit row, that same employee reads the identical
balance back decomposed into `{days: 5, hours: 0, minutes: 0}`, and a grant attempted against
an event type is refused 422 with no row written.

---

## Leave requests: the two-hop (manager → HR) machine, and compute integration *(M6b-b)*

M6b-a built the leave foundation — types, the ledger, manual grants, derived balances —
and deliberately stopped short of a leave *request*. M6b-b is the second slice: an
employee files their own leave request on the shared `requests` spine (above), and the
approval machine widens for the first time since M6a, because leave is the first
`RequestType` that genuinely needs two decisions instead of one.

**`requests.state` widens to five values, and two new columns record the intermediate
hop:**

```sql
alter table requests
  add column manager_decided_by uuid references users(id) on delete set null,
  add column manager_decided_at timestamptz;

alter table requests drop constraint requests_state_check;
alter table requests add constraint requests_state_check
  check (state in ('pending','manager_approved','approved','rejected','cancelled'));

alter table requests drop constraint requests_type_check;
alter table requests add constraint requests_type_check
  check (type in ('attendance_adjustment','leave'));
```

**`manager_approved` is the hop-1 (manager) decision on a two-hop request, and only a
two-hop request ever enters it.** `App\Domain\Requests\RequestType::requiresHrStep()` is
the one place that axis is named — `false` for `attendance_adjustment` (unchanged, still a
single decision), `true` for the new `leave` type — and `App\Actions\Requests\ApproveRequest`
reads it to decide whether the decision it is about to record is the FINAL one. A
single-hop type still goes straight `pending → approved` on its one decision, exactly as
M6a shipped it; a two-hop type's first decision (by the requester's manager) writes
`manager_decided_by`/`manager_decided_at` — mirroring the existing `decided_by`/
`decided_at` pair, but named separately because a two-hop request's SECOND decision writes
`decided_by`/`decided_at` too, and the two must never be confused about which decision
actually made the request `approved`. Only the transition INTO `approved` ever dispatches
the request's `RequestEffect` (below) — the manager's hop-1 decision advances the state and
stamps the two new columns, nothing else.

**Per-hop authority is `App\Domain\Requests\RequestAuthority::canDecide`, now state- and
type-aware** (`05-rbac.md` has the full authority argument): at `pending`, a two-hop
request is the manager's alone — HR has no authority yet, even over their own office; at
`manager_approved`, it flips to HR alone, and specifically excludes whoever just decided
hop 1, so the same actor can never clear both hops of one request even if they happen to
hold both authorities (e.g. a manager who is also their own office's HR admin). A
single-hop request is unchanged: manager OR HR, either sufficient, exactly as M6a proved.
Terminal states (`approved`/`rejected`/`cancelled`) preserve the same authority set so a
second decision by a previously-authorized actor still `409`s rather than leaking a `404`
that would imply "you never had authority here" — the same existence-leak discipline M6a
established. `App\Actions\Requests\CancelRequest` (requester-only, its own narrower rule)
widens the same way: a not-yet-terminal request is withdrawable from `pending` OR
`manager_approved`, so a two-hop leave request awaiting HR is never stuck once a manager
has signed off on it.

**`ApprovalQueues` routes each hop to its own queue, unchanged in shape from M6a.**
`/team/approvals` stays `pending`-only, for both single- and two-hop types — a two-hop
request that has cleared hop 1 no longer belongs there. `/office/approvals` is hop-aware: a
single-hop type the moment it is `pending` (HR is its only decider, same as M6a), OR ANY
type once it reaches `manager_approved` — a two-hop request only ever appears there once
the manager has cleared it.

```sql
create table leave_details (
  request_id      uuid primary key references requests(id) on delete cascade,
  leave_type_id   uuid not null references leave_types(id),
  start_date      date not null,
  end_date        date not null,
  day_part        text not null,                    -- 'full' | 'half'
  amount_minutes  integer not null,

  check (day_part in ('full','half')),
  check (amount_minutes > 0),
  check (end_date >= start_date)
);
```

**`leave_details` mirrors `attendance_adjustment_details` exactly** — the primary key IS
`requests.id`, one request, one detail row, enforced by the database rather than by
convention (the M3.6 section, above). `amount_minutes` is never client-supplied:
`App\Http\Controllers\Leave\SubmitLeaveRequestController` derives it from
`App\Domain\Leave\LeaveDays::scheduledWorkingDays()` (the `[start_date, end_date]` range's
*scheduled working days only* — a rest day inside the range is never charged) times the
office's `minutes_per_leave_day` (`day_part: 'half'` halves the per-day rate) — a client
cannot inflate or shrink its own debit by sending a different number.
`App\Actions\Leave\SubmitLeaveRequest` starts a request `manager_approved` rather than
`pending` when the filing employee has no manager on file (`current_reports_to_id is
null`) — there is no hop-1 approver to wait on, so the request begins already at HR's hop
rather than sitting `pending` forever with no one authorized to ever clear it.

**`App\Actions\Requests\Effects\LeaveEffect` is the leave `RequestEffect`, and it only ever
runs on the FINAL hop.** `ApproveRequest` resolves it through the same
`RequestEffectFactory` M6a built, and calls it only when the decision about to be recorded
is the one that reaches `approved`. For a `deducts_balance` type it debits `leave_ledger`
exactly `amount_minutes`, after locking the EMPLOYEE row
(`Employee::lockForUpdate()`, not just the request row `ApproveRequest` already locks) —
two different leave requests for the SAME employee are two different `requests` rows, so
the request-level lock alone does not serialize two concurrent final-hop approvals against
one balance; the employee lock does, and a debit that would overdraw throws
`App\Exceptions\Domain\InsufficientLeaveBalance` (`422`), rolling the whole approval back —
the request stays `manager_approved`, nothing is written. An event type
(`deducts_balance: false`) never touches the ledger or takes this lock at all.
**`leave_ledger.source` widens to admit `'leave_taken'`** alongside M6b-a's
`'manual_grant'` — the debit's `reason` names the request it came from, and
`leave_ledger.request_id` (nullable since M6b-a, unused until now) is finally populated.
Both balance and event types get the approved span recomputed after commit
(`App\Domain\Compute\RecomputeTrigger::Leave`, widening the `recompute_runs.trigger_type`
CHECK the same way `holiday`/`pay_rule`/`shift_template`/`schedule_assignment`/
`schedule_override`/`office_default` already do, M5b above) — a rejected request, at
either hop, is never recomputed, because nothing about a day changes when a request that
would have priced it differently gets refused instead.

**Compute prices an approved leave day as `leave_with_pay`, a flat 100% independent of any
`pay_rules` version.** `daily_summary_lines.kind`'s CHECK widens to admit
`'leave_with_pay'` alongside the four worked kinds and `holiday_unworked` (M5a, above).
`App\Domain\Leave\LeaveDayLookup::isOnApprovedLeave(employee, date)` — pure, no DB access
of its own, `ComputeDailySummary` hands it in — is `true` exactly when an `approved` leave
request's `leave_details` span covers that date; `App\Domain\Compute\DailyComputation`
emits the line only on a day with no punches, that isn't a rest day, and that has
scheduled minutes — it takes precedence over `holiday_unworked` when both would otherwise
apply (an employee on approved leave over a paid holiday still shows the leave, not the
holiday premium), and a rest day covered by leave gets no line at all, the same as an
ordinary rest day gets none (leave never charges a day already off). See the corrected
`rule_version_id` invariant in the M5a section, above — a `leave_with_pay` line persists
even when no `pay_rules` version is configured for the date, and its presence never sets
`rule_version_id`, unlike every other line kind.

`scripts/e2e-leave.sh` proves the whole chain live: HR grants a balance, the employee files
a 3-scheduled-working-day request, it appears on the manager's queue and not HR's, the
manager's decision moves it to `manager_approved` with the balance and the raw
`leave_ledger` debit-row count completely unchanged and moves the request onto HR's queue,
HR's final decision is what actually debits the ledger and triggers the recompute that
prices the span `leave_with_pay`, and a second request that is instead REJECTED at HR's hop
leaves the balance and the debit-row count untouched.

## Overtime pre-authorization: the cap, and the unpaid excess *(M6c)*

**Overtime is strict: it is only ever paid when it was pre-authorized, and only up to the
approved minutes.** An employee who simply works past their scheduled length earns nothing
for the excess unless a manager (or office HR) approved an overtime request for that date —
and even with one, the paid overtime is capped at the approved minutes, never the worked
ones. The compute engine applies `min(actual_overtime, approved_overtime)`; whatever is
worked beyond that cap is recorded, not paid, as
`daily_attendance_summaries.unpaid_overtime_minutes` (added to the M5a table above).

**`overtime` is a fifth `RequestType` on the shared `requests` spine, and it is
single-hop.** It widens the `requests_type_check` alongside `attendance_adjustment` and
`leave`, and — because `RequestType::Overtime->requiresHrStep()` is `false` — one approval
IS the final hop: a pending overtime request is actionable at once by both the manager
(`/team/approvals`) and office HR (`/office/approvals`), the same routing a single-hop
attendance adjustment takes (`App\Domain\Requests\ApprovalQueues`, the M6a section above),
and the single approval lands it `approved` rather than `manager_approved`.

```sql
create table overtime_details (
  request_id  uuid primary key references requests(id) on delete cascade,
  date        date not null,
  minutes     integer not null,

  check (minutes > 0)
);
```

**`overtime_details` mirrors `leave_details`/`attendance_adjustment_details` exactly** —
the primary key IS `requests.id`, one request, one detail row, enforced by the database
rather than by convention. The client submits `hours` (quarter-hour granularity);
`App\Http\Controllers\Overtime\SubmitOvertimeRequestController` converts to the integer
`minutes` the domain stores, rejecting any value that would not land on a whole minute
(a `422`, never a silently rounded number) — the same integer-minutes discipline the rest
of the system holds.

**`App\Actions\Requests\Effects\OvertimeEffect` is the overtime `RequestEffect`, and unlike
`LeaveEffect` it writes NOTHING.** There is no ledger, no balance, no lock — nothing to
overdraw. The approved request plus its `overtime_details.minutes` IS the authorization the
compute engine reads: `App\Domain\Overtime\OvertimeAuthorizationLookup::approvedMinutesFor(employee,
date)` sums the `minutes` of every `approved` overtime request whose `overtime_details.date`
matches (returning `0` when none is approved — the strict model's default). The effect's
only job is to enqueue a recompute of the authorized date after commit
(`App\Domain\Compute\RecomputeTrigger::Overtime`, widening the `recompute_runs.trigger_type`
CHECK the same way the M5b/M6b triggers do), so `ComputeDailySummary` re-prices that day
under the now-non-zero cap. `App\Domain\Compute\DailyComputation` splits the worked total at
two boundaries — regular below the schedule, PAID overtime between the schedule and
`schedule + approved`, unpaid EXCESS beyond that — pricing the paid overtime as
`overtime_day`/`overtime_night` lines and storing the excess as `unpaid_overtime_minutes`.
An Art. 82-exempt employee short-circuits the whole thing: no overtime premium at all (a
`PHP_INT_MAX` ceiling, so nothing is ever excess), the same exemption gate every other
premium already respects.

`scripts/e2e-leave-and-ot.sh` proves the cap live: an employee works two identical long
days; the first, read before any request, pays zero overtime and books it all as unpaid
excess; a 1-hour pre-authorization filed for it appears at once on both the manager's and
HR's queues, and the manager's single approval re-prices the day to exactly the approved
minutes with the excess dropping by that amount, no ledger touched; the second identical day
with no request pays zero overtime and books its full overtime as unpaid. The same script
runs `scripts/e2e-leave.sh` unchanged, proving the leave and overtime paths coexist.

---

## Cutoff periods: the per-office lock on a closed pay window *(M7a)*

```sql
create table cutoff_periods (
  id          uuid primary key default uuidv7(),
  office_id   uuid not null references offices(id) on delete cascade,
  start_date  date not null,
  end_date    date not null,
  state       text not null default 'open',
  closed_by   uuid references users(id) on delete set null,
  closed_at   timestamptz,
  created_at  timestamptz,
  updated_at  timestamptz,
  unique (office_id, start_date),
  check (state in ('open','closed')),
  check (end_date >= start_date)
);
```

A `cutoff_periods` row is a per-office semi-monthly pay window that has been acted on. The
window rule is `App\Domain\Cutoff\CutoffCalendar`: the 1st–15th and the 16th–end-of-month,
the PH default (per-office custom schedules are deferred). `state` is `open` or `closed`;
`closed_by`/`closed_at` record who froze it and when.

**A row exists only once `CloseCutoff` has touched the window.** The office's current,
still-running window has no row until then, so `GET /office/cutoffs` synthesizes it
(unpersisted, `id: null`, `state: open`) rather than show a gap between the last stored row
and today. `unique (office_id, start_date)` keeps a window single-rowed per office.

**The period a summary belongs to is DERIVED, by office + date range — there is no FK on
the summary.** A `daily_attendance_summary` is "in" a period when its `office_id` matches and
its `date` falls in `[start_date, end_date]`; nothing is stamped on the summary pointing back
at a period. This is deliberate: a summary is recomputed often (M5b), and a second source of
truth to keep consistent across every recompute is exactly what the derived relationship
avoids. `CloseCutoff` reads the window's summaries by that range, and freezing them is a
**status flip on the summaries themselves** — `daily_attendance_summaries.status = 'locked'`,
in the same transaction that sets the period `closed`. `ReopenCutoff` is the inverse: period
back to `open`, every in-period `locked` summary back to `computed`.

**The M7b payroll export reads the frozen summaries by that same office + date-range
membership — never by the `status = 'locked'` label.** Selecting by membership still captures
a leaked `computed` row (the M7a known-limitation first-compute-vs-close race) that a
status-based selection would silently drop, and it pairs each `daily_summary_lines` row with
its parent summary's `rule_version_id` to roll the period up per employee. The export writes
nothing — it is a pure read over the same summaries `CloseCutoff` froze.

**The freeze and its inverse serialize on a per-employee `Employee` row lock.** `CloseCutoff`,
`ReopenCutoff`, `ApproveRequest` (final hop, via `CutoffGuard::assertOpen`), and `RecomputeDay`
each `lockForUpdate()` the affected `employees` row before touching that employee's summaries,
so a close, an approval, and a recompute for the same employee cannot interleave. Two genuine
two-real-Postgres-connections tests prove it (`CloseVsApproveConcurrencyTest`,
`CloseVsRecomputeConcurrencyTest`), the kind `04-backend-conventions.md` demands — a
single-process test would pass whether or not the lock exists. See `06-roadmap.md`'s M7a
section and `03-api.md` for the endpoints and error codes (`cutoff_locked`,
`cutoff_has_unresolved_exceptions`, `cutoff_already_closed`, `invalid_cutoff_start`).

---

## Employee profiling (M10a)

The personnel file HR opens once identity, employment, and a name (M8b) already exist but
still can't answer "what is this person's mobile number," "who do we call in an emergency,"
"what is their TIN," or "how old are they." Five new tables, plus two columns grafted onto
existing ones — **no new columns on `employees` at all.**

```sql
create table employee_profiles (
  employee_id       uuid primary key references employees(id) on delete cascade,
  salutation        text,
  nickname          text,
  home_address      text,
  personal_email    text,
  phone             text,
  fax               text,
  mobile            text,
  emergency_contact text,          -- one free-text line: name, relation, and number
  gender            text,          -- Domain\Profile\Gender
  birth_date        date,
  birthplace        text,
  marital_status    text,          -- Domain\Profile\MaritalStatus
  citizenship       text,
  religion          text,
  blood_type        text,          -- Domain\Profile\BloodType
  created_at        timestamptz,
  updated_at        timestamptz
);

create table relationships (
  id          uuid primary key default uuidv7(),
  code        text not null unique,     -- 'spouse', 'child', 'parent', 'sibling', 'other'
  description text not null,
  created_at  timestamptz,
  updated_at  timestamptz
);

create table employee_dependents (
  id              uuid primary key default uuidv7(),
  employee_id     uuid references employees(id) on delete cascade,   -- nullable, deliberate — below
  name            text not null,
  relationship_id uuid not null references relationships(id),
  birth_date      date,
  created_at      timestamptz,
  updated_at      timestamptz
);
create index employee_dependents_employee_id on employee_dependents (employee_id);

create table employee_identification_categories (
  id          uuid primary key default uuidv7(),
  code        text not null unique,     -- 'TIN', 'SSS', 'HDMF', 'PHIC', 'BANK', 'PASSPORT', 'DL', 'PRC'
  name        text not null,
  description text,
  created_at  timestamptz,
  updated_at  timestamptz
);

create table employee_identifications (
  id          uuid primary key default uuidv7(),
  employee_id uuid not null references employees(id) on delete cascade,
  category_id uuid not null references employee_identification_categories(id),
  number      text not null,
  issued_on   date,
  expires_on  date,
  notes       text,
  created_at  timestamptz,
  updated_at  timestamptz,
  unique (employee_id, category_id)
);

alter table employment_records add column designation text;
alter table employment_records add column labor_type  text;   -- Domain\Profile\LaborType
alter table offices            add column region      text;   -- 'VII'
```

`EmployeeIdentification implements HasMedia` with a single-file `scan` collection on the
`attachments` disk (RustFS), accepting `pdf`/`jpg`/`jpeg`/`png` up to 10 MB — the same
collection shape and limits `Request`'s `attachment` collection already uses (above).

**The profile is a 1:1 side table, keyed on `employee_id` as its own primary key — not
columns on `employees`.** `employees` holds only what does not change over a career
(above); half of a personnel file — an address, a phone number, a marital status — changes
routinely, and `employees` is the row every office-scope query (`WHERE current_office_id =
?`) touches. Widening it by fifteen mixed-lifetime columns to carry data no scope query
reads is the wrong trade. `employee_profiles.employee_id` is the primary key rather than a
surrogate `id` because the relationship *is* the identity — there is no "which profile"
question a second id would ever answer — and it cascades on delete, the ordinary shape for
a side table. **The profile carries current values only, no history.**
`employment_records` is effective-dated because the pay engine must compute against the
office and rate that were true on the day it's pricing; nothing computes against a religion
or a nickname mid-career, so a stored history here would be a second, unread ledger.
`activity_log` (below) is the record of what changed, not a second
`employment_records`-shaped table.

**Government and financial IDs are rows against a catalog, not eight columns
(`tin`/`sss_no`/`hdmf_no`/…) on the profile.** An identification is not a bare number — it
carries an issue date, an expiry, and a scanned copy HR is legally expected to be able to
produce, none of which a column can hold, and a ninth ID type must be a row, not a
migration. `employee_identification_categories` names the kind (`TIN`, `SSS`, `HDMF`,
`PHIC`, `BANK`, `PASSPORT`, `DL`, `PRC` — seeded by `ProfileCatalogSeeder`, below); the row
in `employee_identifications` carries the value. This is the split that turns "every
employee missing a PhilHealth number" into a single join instead of a scan across eight
nullable columns. The alternative considered — category as a *grouping*
(Government-Mandated / Financial / License) with `code`/`name`/`description` repeated on
every instance — was rejected: it makes each employee's row re-declare that it is a TIN,
and a cross-employee query stops being reliable the first time someone types `Tin`.
`unique(employee_id, category_id)` is what turns the write path into a clean upsert — one
employee has one TIN — rather than a create-or-update dance the client would otherwise have
to run.

**Designation, labor type, and region live where they're effective-dated or singular, not
on the profile.** Three fields the "Assignment" block on a personnel file naturally wants —
designation, labor type, region — are not profile data:

| Field | Home | Why |
| --- | --- | --- |
| Designation | `employment_records.designation` | A promotion changes it on a date; putting it on the profile would make last March's report show today's job title. |
| Labor Type | `employment_records.labor_type` | Direct/indirect labour can change with a transfer — an attribute of the posting, not the person. |
| Region | `offices.region` | Cebu is in Region VII regardless of who works there — one row, not one per employee. |

`RecordEmploymentChange` (the single writer of `employment_records` and the `current_*`
cache, above) gains the two `employment_records` fields as ordinary input; nothing else
writes them. **Nothing is cached onto `employees`** — the `current_*` columns exist so
office scoping stays a plain `WHERE`, and no scope query filters by designation, so a
`current_designation` would be cache invalidation bought for nothing.

**Closed sets are PHP backed enums cast on the model, not a Postgres `CHECK` and not a
lookup table.** Gender, marital status, blood type, and labor type live in
`app/Domain/Profile/` (`Gender`, `MaritalStatus`, `BloodType`, `LaborType`) beside
`App\Domain\Attendance\PunchDirection` and `App\Domain\Pay\DayType` — the same shape, for
the same reason: a `CHECK` makes adding a marital status a migration and a deploy and
splits the set's definition across two languages, and five lookup tables plus five joins on
every profile read is cost paid to make runtime-editable something no admin asked to edit.
`relationships`, by contrast, genuinely *is* a table — dependents' relationships were
specified as one, and it is referenced by a foreign key rather than merely validated, the
one place this rule bends. Religion, citizenship, birthplace, salutation, and nickname stay
plain free text — a long tail, not a closed set.

**`EmployeeProfile::age` is derived at read time, anchored to the employee's current office
timezone, never stored.** A written age is wrong the day after it's written — the same
reasoning `Employee::full_name` (M8b, above) already established for composing a display
value in exactly one place. It is computed against the office's timezone specifically, not
a naive `now()`: `APP_TIMEZONE` is `UTC` by rule (`01-architecture.md`), so an unzoned
`now()` would roll an age over up to eight hours early in Manila. An employee with no
office yet falls back to `Asia/Manila`, the same default `offices.timezone` itself carries.
**This used to be one of two places M10a reads "today," disagreeing with the other —
fixed by the M10a follow-ups round.** `EmployeeAssignmentPresenter`'s `work_shift`
resolution and `EmployeeProfileResource`/`EmployeeProfileSummaryResource`'s
`EmploymentResolver::on()` call originally used `Carbon::today()`, which is UTC-today, not
office-local today, so the two could disagree for up to eight hours a day. All three call
sites — plus the pre-existing M8b `EmployeeDetailResource`, which had the identical bug —
now go through a shared `App\Http\Resources\EmployeeLocalToday::for($employee)` helper that
anchors to `$employee->currentOffice?->timezone ?? 'Asia/Manila'`, the same fallback `age`
already used, so the whole payload now agrees on what day it is.
`App\Domain\Employment\EmploymentResolver` itself was **not** changed — it already took an
explicit date argument; only the callers that were choosing `today()` for themselves needed
fixing. See `06-roadmap.md`'s M10a section for the full account, including the correction
to that section's earlier claim that the fix required threading office timezone through
`EmploymentResolver`.

**Deleting an employee would orphan their ID scans — verified unreachable today, recorded
rather than guarded against.** `employee_identifications.employee_id` cascades at the
Postgres FK level (`on delete cascade`, above). A Postgres-level cascade deletes the row
directly at the database; it does not run through Eloquent, so spatie/medialibrary's
`deleting` model event — the hook that deletes a row's `media` entries and their RustFS
objects — never fires. Deleting an employee today would therefore leave both an orphaned
`media` row and an orphaned scan object (a government ID) sitting in RustFS with nothing
pointing at either. This is a real gap, but **verified unreachable**: there is no `DELETE`
route for an employee anywhere in `03-api.md`, no `delete()` call anywhere in `app/`, and
employees are separated via `employees.separated_at`, never removed. The spec owner's
ruling was to record the trap rather than build cleanup for a path that does not exist —
**anyone who later adds an employee-delete path must delete `employee_identifications`
through Eloquent first** (iterating and calling `->delete()` on each row, not a bulk
query-builder delete), so the model event fires and the media/RustFS cleanup happens before
the FK cascade ever runs.

**`SaveEmployeeIdentification` attaches the scan only after the DB write commits, not inside
the same transaction (M10a follow-ups).** The `scan` collection is `singleFile()`, so
replacing a scan makes medialibrary delete the OLD RustFS object as part of adding the new
one — that deletion cannot be undone by a Postgres rollback. The original version ran the
`updateOrCreate` and the `addMedia(...)->toMediaCollection('scan')` call inside one
`DB::transaction()`; if anything after the media attach had then thrown, the transaction
would roll the `number`/`issued_on`/`expires_on` write back to its old values while the old
scan object was already gone from RustFS — a media row surviving the rollback but pointing
at nothing, or worse, a row correctly restored but with no way back to the file it used to
reference. The action now runs the `updateOrCreate` alone inside the transaction, returns
once that closure commits, and only then calls `addMedia()`. A failure in the media step can
no longer revert an already-durable DB row, and a DB failure can no longer happen after the
old object is already unrecoverable — the ordering makes each half of the write fail
independently instead of coupling a RustFS side effect to a Postgres commit that a Postgres
rollback cannot see.

The trade is deliberate but not free, and the residual is worth knowing. If `addMedia()` now
fails *after* the commit — RustFS unreachable, or a file that satisfies
`SaveIdentificationRequest`'s rules but not medialibrary's own mime check — the row is
durable with the **new** `number` while the **old** scan is still attached: a record reading
`TIN 222…` over a scanned card reading `TIN 111…`, surfaced as a generic 500 that looks to
the user like nothing saved. That is strictly the better half of the trade (the alternative
lost the scan outright, permanently), and retrying is safe because the write is an upsert on
`(employee_id, category_id)`. But it means **a 500 from this endpoint does not mean "nothing
happened"** — the number may have changed. Anyone adding a reconciliation or import path
here should verify the scan matches the number rather than assuming the pair is atomic.

**`number` is deliberately excluded from `EmployeeIdentification`'s `logOnly()`, and the
profile's contact fields from `EmployeeProfile`'s.** All three new models carry spatie's
`LogsActivity` under `log_name 'employee_profile'`, matching the trait `employees` and the
org tree already use — but `EmployeeIdentification::getActivitylogOptions()` logs only
`employee_id`, `category_id`, `issued_on`, and `expires_on`, and
`EmployeeProfile::getActivitylogOptions()` logs only the closed personal-details fields
(`salutation`, `nickname`, `birthplace`, `gender`, `birth_date`, `marital_status`,
`citizenship`, `religion`, `blood_type`) — never `home_address`, `personal_email`, `phone`,
`fax`, `mobile`, or `emergency_contact`. Logging the `number` column, or the profile's
contact fields, would copy every TIN, SSS number, bank account, home address, and personal
phone number into `activity_log` — a table with different read rules (the audit viewer,
`03-api.md`) and a far longer retention than anyone reasoned about when they added it. The
log records **that** an identification or a profile changed, and by whom — never **to
what**, for the fields excluded above. The value itself lives in exactly one table,
reachable through exactly one policy (`05-rbac.md`).

Both models use an explicit `logOnly([...])` allowlist, never `logFillable()` — `logFillable()`
reads a model's `getFillable()`, which returns `[]` on a `$guarded = []` model with no
`$fillable` array (both models use mass-assignment guarding, not an allowlist), so
`logFillable()` here would silently log an empty `properties` bag on every change. This was
caught in the M10a final-fixes review; see `06-roadmap.md`'s M10a section.

**`employee_dependents.employee_id` is nullable, deliberately.** An orphan dependent row —
`employee_id null` — is unreachable by every query in the system today, which was raised
during implementation; the ruling was to keep it nullable anyway, recorded with a
`ponytail:` comment at the column so a later reader treats the nullability as intent, not
an oversight to tighten.

`employee_identification_categories` (the eight kinds above) and `relationships` (the five
above) are catalog data **production needs**, in the same category as the RBAC permission
catalog (`05-rbac.md`) — seeded by `ProfileCatalogSeeder`, called from
`hris:bootstrap-admin` alongside `RbacSeeder`, **not** from `DatabaseSeeder` (which is
dev-only and pulls in the Manila/Cebu demo company). Idempotent throughout
(`updateOrCreate` on `code`), so re-running a bootstrap-adjacent seed is safe.

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
- An office cannot have two holidays on the same date, and a holiday's `day_type` cannot be
  anything but one of the four non-`Ordinary` cases. (`unique(office_id, date)` +
  `CHECK (day_type IN (...))`, above.)
- A shift template day cannot be a rest day carrying hours, or a working day missing them,
  and a working day's minute range must actually make sense (`end > start`, `break <
  end - start`). (The is_rest-XOR-hours `CHECK` + minute-range `CHECK` on
  `shift_template_days`, mirrored on `schedule_overrides`.)
- A schedule assignment cannot target both an employee and a department, or neither, and
  two assignments cannot cover the same target on the same effective date. (`CHECK`
  + the two partial unique indexes on `schedule_assignments`, above.)
- An employee cannot have two schedule overrides on the same date. (`unique(employee_id,
  date)` on `schedule_overrides`.)
- A shift template that is still an office's default, or still pointed at by a schedule
  assignment, cannot be deleted. (`App\Exceptions\Domain\TemplateInUse`, `422
  template_in_use` — a domain refusal, not a dangling foreign key.)
- Two pay-rule versions cannot take effect on the same date, and no scalar or per-day-type
  rate can be negative or below its statutory floor. (`unique(effective_from)` + the
  non-negative `CHECK`s on `pay_rules`/`pay_rule_day_rates`, below, + `StatutoryFloor`.)
- An employee cannot have two computed summaries for the same date, and a summary cannot
  carry two lines of the same kind. (`unique(employee_id, date)` on
  `daily_attendance_summaries`, `unique(summary_id, kind)` on `daily_summary_lines`, above.)
- A `pay_rules` version that has already priced a real day cannot be deleted out from under
  it. (`daily_attendance_summaries.rule_version_id` is `ON DELETE RESTRICT`, not `SET
  NULL` — a domain-meaningful FK, not just a dangling-reference guard.)
- A `recompute_runs` row cannot claim a `trigger_type`/`status` outside the closed set
  either enum defines, and cannot claim a negative `pair_count`. (`CHECK`s on
  `recompute_runs`, above.)
- A recompute can never write, update, or delete an `attendance_logs` row, no matter how
  many config changes triggered it — only `daily_attendance_summaries`/
  `daily_summary_lines` are ever touched. (`RecordPunch` remains the sole writer of
  `attendance_logs`; the recompute path only reads it via `EffectivePunches::forDate()`.)
- Two leave types in the same office cannot share a `code` (multiple ad-hoc types with a
  null code are fine), and a leave type that does not bank a balance cannot be manually
  granted into. (`unique(office_id, code)` on `leave_types` + `LeaveTypeNotGrantable`, `422
  leave_type_not_grantable`.)
- A `leave_ledger` row can never be edited or deleted, through the API or otherwise, and a
  balance cannot drift from it because no balance is ever stored — there is no column to
  drift. (`GrantLeave` only `create`s, no `updated_at` on the model;
  `LeaveBalances::forEmployee` derives `SUM(credit) - SUM(debit)` fresh on every read.)
- A request cannot claim a `state` outside the five-value two-hop set, or a `type` outside
  `attendance_adjustment`/`leave`. (`requests_state_check`/`requests_type_check`, M6b-b
  above.)
- A leave request's detail row cannot carry a non-positive `amount_minutes`, an `end_date`
  before its `start_date`, or a `day_part` outside `full`/`half` — and it is deleted the
  instant its parent request is. (`CHECK`s + `on delete cascade` on `leave_details`, M6b-b
  above.)
- An employee cannot hold two identifications in the same category — one TIN, one SSS
  number, never two rows racing each other — and an identification's `number` never reaches
  `activity_log`, only the fact that the row changed. (`unique(employee_id, category_id)` on
  `employee_identifications` + the `logOnly()` exclusion, M10a above.)
- A dependent's relationship cannot be an arbitrary string — only a row that exists in the
  `relationships` catalog. (`relationship_id` FK, M10a above.)
