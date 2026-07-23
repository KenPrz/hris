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
structurally one change — the second write is a `409`-shaped conflict rather than a silent
second row the resolver would have to tie-break.

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
