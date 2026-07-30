# M10a — Employee profiling (design)

**Status:** brainstormed with the user 2026-07-30, approved, pending spec review
**Milestone:** M10a. First milestone after the M0–M9 roadmap closed.
**Depends on:** M2 (`employees`, `employment_records`, `offices`, `EmployeeScope`, `EmployeePolicy`),
M3.6 (spatie/medialibrary on the RustFS-backed `attachments` disk, and the app-mediated
stream pattern in `DownloadAttachmentController`), M8b (`employees.first_name`/`last_name`
and the `Employee::full_name` accessor).

**Explicitly not in scope:** the document management module (Document / DocumentBucket /
DocumentCategory / the polymorphic file table). That is **M10b**, brainstormed separately.
The two share nothing — M10a attaches exactly one media file per identification row through
the collection mechanism that already exists, and does not anticipate M10b's catalog.

## Goal

An employee record today is identity plus employment history plus a name. It cannot answer
"what is this person's mobile number", "who do we call in an emergency", "what is their TIN",
or "how old are they" — the questions an HR department opens a personnel file to answer.

M10a adds the personnel file: contact details, personal details, dependents, and
government/financial identification numbers with a scanned copy of each. HR Admins configure
it; the employee reads their own; a manager sees only enough to reach a direct report.

## Decisions

### 1. The profile is a 1:1 side table, not columns on `employees`

`02-data-model.md` states that `employees` holds "only what does **not** change over a
career". Half the profile violates that on its face — an address, a phone number, and a
marital status all change — and `employees` is the row every office-scope query
(`WHERE current_office_id = ?`) touches. Widening it by fifteen mixed-lifetime columns to
carry data no scope query reads is the wrong trade.

`employee_profiles` therefore keys on `employee_id` as its primary key (not a surrogate id —
the relationship *is* the identity) and cascades on delete.

**No history.** `employment_records` is effective-dated because the pay engine must compute
against the office and rate that were true on the day it is computing. Nothing computes
against a religion or a nickname. The profile stores current values only; `activity_log`
(decision 6) is the record of what changed.

### 2. Government IDs are rows against a catalog, not eight columns

Eight `text` columns (`tin`, `sss_no`, `hdmf_no`, …) is the smaller diff and the wrong shape.
An identification is not a bare number: it has an issuing date, an expiry, and a scanned copy
that HR is legally expected to be able to produce. Columns cannot carry those, and a ninth ID
type is a migration rather than a row.

```
employee_identification_categories   -- the ID-kind catalog
  TIN, SSS, HDMF, PHIC, BANK, PASSPORT, DL, PRC

employee_identifications             -- one row per employee per kind
  number, issued_on, expires_on, notes, + one media file
  unique (employee_id, category_id)
```

The category names the kind; the row carries the value. This is the split that makes
"every employee missing a PhilHealth number" a single join rather than a scan of eight nullable
columns. The alternative considered — category as a *grouping* (Government-Mandated /
Financial / License) with `code`/`name`/`description` repeated on every instance — was
rejected: it makes each employee's row re-declare that it is a TIN, and cross-employee
queries stop being reliable the first time someone types `Tin`.

`unique(employee_id, category_id)` is what makes the write path an upsert rather than a
create-or-update dance in the client: one employee has one TIN.

### 3. Designation, Labor Type, and Region go where they belong, not on the profile

Three of the fields in the requested "Assignment" block are not profile data:

| Field | Home | Why |
| --- | --- | --- |
| Designation | `employment_records.designation` | A promotion changes it on a date. It is effective-dated by nature; putting it on the profile would make last March's summary report the current job title. |
| Labor Type | `employment_records.labor_type` | Direct/indirect labour can change with a transfer, and it is an attribute of the posting, not the person. |
| Region | `offices.region` | Cebu is in Region VII regardless of who works there. One row, not one per employee. |

`RecordEmploymentChange` — the single writer for `employment_records` and the `current_*`
cache, enforced by an arch test — gains two input fields. Nothing else may write them.

**Nothing is cached onto `employees`.** The `current_*` columns exist so office scoping stays
a plain `WHERE`; no scope query filters by designation, so a `current_designation` would be
cache invalidation bought for nothing.

The rest of the Assignment block already exists and is read, never written, by this module:
Business Unit (`departments`), Location (`offices`), Reporting To
(`employment_records.reports_to_id`), Employment Status (`employment_records.employment_type`),
Date Hired (`employees.hired_at`), Work Shift (the active `schedule_assignments` row's
`shift_templates.name`).

### 4. Closed sets are PHP backed enums; the column is plain `text`

Gender, Marital Status, Blood Type, and Labor Type are closed sets. They are enforced by
backed enums cast on the model and validated with `Rule::enum()` — **not** by a Postgres
`CHECK` constraint, and **not** by lookup tables.

- A `CHECK` makes adding a marital status a migration and a deploy, and splits the definition
  of the set across two languages.
- Lookup tables would add five tables, five seeders, and five joins to every profile read, to
  make runtime-editable something no admin has asked to edit.

The enums live in `app/Domain/Profile/` — `Gender`, `MaritalStatus`, `BloodType`, `LaborType`
— beside `app/Domain/Attendance/PunchDirection.php` and `app/Domain/Pay/DayType.php`, which
are the same thing. **There is no `app/Enums/` directory and this milestone does not create
one**; the domain layer is where closed sets already live, and an arch test already holds it
framework-agnostic.

Religion, Citizenship, Birthplace, Salutation, and Nickname are free text. They are a long
tail, not a set.

`relationships` (`id`, `code`, `description`) *is* a table, because dependents' relationships
were specified as one and because it is genuinely referenced by a foreign key rather than
being a validation concern.

### 5. Age is derived in the office's timezone, never stored

A stored age is wrong the day after it is written. It is an accessor over `birth_date`,
following `Employee::full_name` — the M8b precedent that display values are composed in
exactly one place.

It is computed against **the employee's current office timezone**, not `now()`. `APP_TIMEZONE`
is `UTC` by rule (`01-architecture.md`), so a naive `now()` would roll an employee's age over
up to eight hours early in Manila — the same class of error the calendar-dates-as-strings rule
exists to prevent.

### 6. The audit log records that an ID changed, not what it changed to

All three new models carry spatie's `LogsActivity` under `log_name 'employee_profile'`,
matching the trait `employees` and the org tree already use.

`employee_identifications.number` is **excluded from `logAttributes`**. Logging it would copy
every TIN, SSS number, and bank account into `activity_log` — a table with different read
rules, no redaction, and a much longer retention than anyone reasoned about when they added
it. The log records that the identification was created, updated, or deleted, by whom, and
when. The value lives in exactly one table, reachable through exactly one policy.

### 7. Authorization is `employee.pii.edit` + office scope, not `is_system_admin`

Every existing route under `/admin/employees/*` gates on `is_system_admin` inside its
FormRequest's `authorize()`. M10a's writes deliberately do **not**, because the requirement is
that *HR Admins* configure profiles, and an HR Admin is not a system admin.

`RbacSeeder` already ships an **unused** `employee.pii.edit` permission on the `HR Admin`
role. It was catalogued in M2 and no endpoint has ever read it. That is precisely this
milestone's verb, so M10a activates it rather than inventing a new one or overloading
`employee.manage` (which means "onboard/transfer/rename", a different act from "edit personal
data").

The full-read check needs one thing office scope alone cannot give it. `EmployeeScope`
composes self + direct reports + HR offices additively, so a **manager is inside the scope of
their own report** — an `inScope` test cannot tell a manager apart from an HR Admin, and the
manager must get the redacted view. The policy therefore checks the HR office pivot directly:

```
viewFull    = self  OR  is_system_admin
                    OR (can('employee.pii.edit') AND employee.current_office_id ∈ user's hr_admin_offices)
viewRedacted= EmployeeScope::visibleTo(user) contains employee     -- catches the manager
update      = NOT self                                             -- separation of duties, see below
              AND ( is_system_admin
                    OR (can('employee.pii.edit') AND employee.current_office_id ∈ user's hr_admin_offices) )
```

Note the consequence: an HR Admin of Cebu who *manages* someone in Manila gets the redacted
view of that report, not the full one. Authority follows the office pivot, not the org chart.

**`update` denies self explicitly, and that denial outranks the HR grant.** Stating the rule as
"the full-read check minus the self branch" — as an earlier draft of this spec did — is not
enough: dropping the self branch blocks only *ordinary* employees. An HR Admin whose own
employee row sits in an office they administer would pass the pivot check and be able to
rewrite their own TIN, SSS number, and bank account. That is a self-approval hole in
payroll-adjacent data, and it is closed the same way the requests spine already stops a
requester approving their own request: an explicit self-denial evaluated first.

The operational consequence is deliberate — two HR Admins in an office maintain each other's
files, and a lone HR Admin's own file is a System Admin's job. Reading your own file is still
allowed; only editing it is not.

Both self-comparisons test `employee.user_id === user.id` with an explicit non-null guard,
never `user->employee?->id === employee->id`. The latter evaluates `null === null` to **true**
for an actor with no employee row against an unsaved `Employee`, which fails *open* in
`viewFull` — the one check standing between an arbitrary user and a personnel file.

### 8. `employee_dependents.employee_id` is nullable, deliberately

An orphan dependent row is unreachable by every query in the system, and this was raised as a
concern. The user chose to keep it nullable. The column carries a `ponytail:` comment saying
so, so that a later reader treats it as intent rather than an oversight to tighten.

## Schema

```sql
create table employee_profiles (
  employee_id       uuid primary key references employees(id) on delete cascade,
  salutation        text,          -- 'Mr.'
  nickname          text,          -- 'KENPE'
  home_address      text,          -- one field; see Deferred
  personal_email    text,
  phone             text,
  fax               text,
  mobile            text,
  emergency_contact text,          -- free text: name, relation, and number in one line
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
  employee_id     uuid references employees(id) on delete cascade,   -- nullable, deliberate
  name            text not null,
  relationship_id uuid not null references relationships(id),
  birth_date      date,
  created_at      timestamptz,
  updated_at      timestamptz
);
create index employee_dependents_employee_id on employee_dependents (employee_id);

create table employee_identification_categories (
  id          uuid primary key default uuidv7(),
  code        text not null unique,     -- 'TIN', 'SSS', 'HDMF', 'PHIC', 'BANK', ...
  name        text not null,            -- 'TIN', 'SSS ID', 'Pag-IBIG MID', ...
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

`EmployeeIdentification implements HasMedia` with a single-file collection `scan` on the
`attachments` disk (RustFS), accepting `pdf,jpg,jpeg,png` up to 10 MB — the same collection
shape and limits `Request`'s `attachment` collection already uses.

## API

Nine routes, four actions. One action = one route = one controller
(`04-backend-conventions.md`); the scan stream and the catalog read are controller-only reads
with no action, the same as `DownloadAttachmentController`.

| Route | Actor | Action |
| --- | --- | --- |
| `GET /me/profile` | self | — (read) |
| `GET /profile/catalog` | any authenticated user | — (read) |
| `GET /admin/employees/{employee}/profile` | HR Admin, scoped | — (read) |
| `GET /employees/{employee}/profile` | manager of `{employee}` | — (read, redacted) |
| `PUT /admin/employees/{employee}/profile` | HR Admin, scoped | `UpsertEmployeeProfile` |
| `PUT /admin/employees/{employee}/dependents` | HR Admin, scoped | `ReplaceEmployeeDependents` |
| `POST /admin/employees/{employee}/identifications` | HR Admin, scoped | `SaveEmployeeIdentification` |
| `DELETE /admin/employees/{employee}/identifications/{identification}` | HR Admin, scoped | `DeleteEmployeeIdentification` |
| `GET /employees/{employee}/identifications/{identification}/scan` | self or HR Admin | — (stream) |

**Dependents are replace-all.** A `PUT` carrying the whole list, diffed against the rows for
that employee. It is a zero-to-five row list nothing else references, so three routes
(`POST`/`PATCH`/`DELETE`) and the client-side id bookkeeping they require buy nothing over one.

**Identifications are per-row**, because each owns a media file. A replace-all would orphan
scans in RustFS. The route upserts on `(employee_id, category_id)` and accepts
`multipart/form-data` so the number and its scan arrive together.

It is a `POST`, not a `PUT`, despite being an upsert: **PHP parses a multipart body only on
`POST`**. A `PUT multipart/form-data` arrives with an empty `$_FILES` and the scan silently
vanishes — Laravel's `_method` spoofing exists precisely because of this. Profile and
dependents stay `PUT` because they carry JSON and no file.

**`GET /profile/catalog`** returns the `relationships` and `employee_identification_categories`
rows. The dependents and identifications writes take a `relationship_id` and a `category_id`,
and without this route nothing tells a client what those ids are — the write endpoints are
unusable from a browser. It is neither office-scoped nor admin-gated: static, company-wide
reference data with nothing sensitive in it, needed by every screen that renders a profile.

**Writes are HR Admin only** (decision 7), per the requirement. An employee cannot edit their own profile —
including their own address. This is a deliberate consequence of "only HR admin can configure
the employee details" and should be re-examined if self-service contact updates are ever asked
for.

### Redaction

Two resources over one model:

- `EmployeeProfileResource` — everything. Self and HR Admin.
- `EmployeeProfileSummaryResource` — contact (`personal_email`, `phone`, `mobile`) plus the
  assignment block. **No** home address, no personal block, no identifications, no dependents.

The rule a reviewer can hold in their head: *a manager sees how to reach you and where you
sit.* Birthplace, religion, marital status, dependents, and every government ID are not a
manager's business, and the redacted resource is a separate class rather than a conditional
inside one so that adding a field to the full resource cannot silently widen the manager's
view.

Scope resolves through the existing `EmployeePolicy` and `EmployeeScope`, including the
404-not-403 discipline `05-rbac.md` documents: an out-of-scope employee is not found, not
forbidden.

"Manager of `{employee}`" means exactly what it already means everywhere else in this
codebase: `employees.current_reports_to_id` points at the viewer's employee row. Direct
reports only — recursive manager scope is on the roadmap's Deferred table and this milestone
does not anticipate it.

Note that the two `/employees/{employee}/…` routes carry **different** policies despite
sharing a prefix: the profile read admits a manager (redacted), the scan stream does not. A
manager hitting the scan route gets a 404, because the redacted resource never gave them an
identification id to ask about in the first place.

## Seeding

`employee_identification_categories` (the eight kinds) and `relationships` (five) are catalog
data that **production needs**, in the same category as the RBAC permission catalog.

They go in a new `ProfileCatalogSeeder`, called from `hris:bootstrap-admin` alongside
`RbacSeeder` — **not** from `DatabaseSeeder`, which is dev-only and pulls in `CompanySeeder`'s
Manila/Cebu demo company. The seeder is idempotent (`updateOrCreate` on `code`) so re-running
bootstrap-adjacent seeds is safe.

## Frontend

- **`/me/profile`** — read-only, the five sections of a personnel file (Details, Contact,
  Personal, Assignment, National IDs) composed from existing tier-2 components
  (`SectionHeader`, `Tag`, `EmptyState`). No new primitives; every value reads a `var(--*)`
  token from `carbon.css`.
- **`/admin/employees/{id}`** gains a Profile tab holding the HR Admin form.
- **Hooks** `useMyProfile` and `useEmployeeProfile(id)`, keyed through `lib/keys.ts`'s factory,
  matching every other authenticated screen since M3.5.
- **Scan preview** reuses the blob-URL approach already written in `RequestCard.tsx` — the
  stream is bearer-authenticated, so a plain `<img src>` would navigate without the token and
  401. That function is lifted out of `RequestCard.tsx` into `lib/authedBlobUrl.ts` and both
  call sites use it. This is the one piece of existing code this milestone refactors, and only
  because it is the code being reused.

Dates on the wire are `YYYY-MM-DD` strings (`birth_date`, `issued_on`, `expires_on`), never
`Date` objects, per `01-architecture.md`.

## Testing

**Backend.** The policy matrix is the test that matters: self, HR Admin in scope, HR Admin out
of scope, manager of the employee, manager of someone else, and a stranger, against all eight
routes — asserting 404 (not 403) for out-of-scope, and asserting the manager's response body
does **not** contain an identification number or a home address. Beyond that: the
`unique(employee_id, category_id)` upsert path (a second `PUT` for the same category updates
rather than 500s), dependents replace-all (adds, removes, and leaves untouched rows alone),
the age accessor across a birthday boundary in Asia/Manila while the server clock is UTC, and
that `activity_log` never contains an identification `number`.

**Frontend.** Component tests for both screens, including the redacted shape rendering without
the sections it has no data for.

Tests run against real Postgres, as always.

## Deferred

| Item | Trigger that revives it |
| --- | --- |
| **Structured address** (street / barangay / city / province / postal) | The first report that must filter or group by city or province — BIR or DOLE submissions are the likely one. Until then the sample data is already a single comma-joined string. |
| **Per-ID format validation** (TIN checksums, SSS length, PhilHealth format) | A data-quality complaint, or an export rejected by a government portal. The `number` column does not change; only validation is added. |
| **ID expiry alerts** | Someone asking to be told a PRC license or passport lapsed. `expires_on` exists for exactly this; only the notification is missing. |
| **Profile change history** | An audit finding that `activity_log` is not enough. The log already records who changed what and when; a full effective-dated profile history is a second `employment_records`-shaped table and is not justified by anything current. |
| **Employee self-service contact edits** | The first HR Admin who does not want to retype a phone number. Requires splitting the write policy so an employee may update contact fields but not identifications or assignment. |
