# M2 — Schema, Auth, and RBAC — Design

Date: 2026-07-23

The database milestone. It builds the org hierarchy, the employee record with its
effective-dated history, authentication, and the office-scoped authorization model — and
proves the model by a four-actor scope matrix rather than by assertion.

This spec records the decisions M2 turns on and the reasoning behind each. The foundation
spec (`2026-07-23-hris-foundation-design.md`) already fixed the big ones — three tiers as
flat FKs, spatie without teams, the `hr_admin_offices` scope pivot, direct-reports-only,
`EmployeeScope` returning a query constraint. What follows is what M2 adds on top, and two
places where the earlier decisions had a consequence worth stating plainly.

## Decisions settled for M2

| Decision | Choice | Why it mattered |
| --- | --- | --- |
| Employee ↔ login | Employee owns an optional `user` | The employment record is the point; a punch-only worker needs no account |
| Effective dating | One `employment_records` history table | Payroll for a past period must read that period's state, not reconstruct it |
| Current-state read | Denormalized cache columns on `employees` | Keeps scope a plain `WHERE`, the flat-FK model's whole purpose |
| Auth scope | Login + session + admin-set passwords | Proves RBAC without dragging a mail transport into a schema milestone |
| Manager authority | Derived from having reports, not a role | Manager-ness can't drift from the org chart because it *is* the org chart |
| Spatie's job | Carry the HR-Admin verb set as editable data | Honors "fully configurable" and gives future roles a home, without over-building |

## 1. The employee/user split

`employees` is the core record; `users` is an optional login that hangs off it via a
nullable `employees.user_id`. An employee can exist before an account does (pre-boarding,
or field and factory staff who punch a device and never open the portal), and the account
is provisioned later.

Consequences:

- Authentication flows through `users`; scope and policies resolve through `employees`.
- `EmployeeScope` reads the employee side, so a login is never required for a person to be
  a valid subject of a query.
- A punch-only worker is a first-class case, seeded explicitly so the nullable path is
  exercised rather than assumed.

## 2. Effective-dated employment — the load-bearing table

Every attribute that changes over a career lives in `employment_records`, one row per
change:

```
employment_records
  id, employee_id, effective_from (date),
  office_id, department_id, reports_to_id (nullable),
  employment_type, is_art82_exempt (bool), base_rate_cents (bigint),
  created_by, created_at
```

`effective_to` is **derived** — the day before the next row's `effective_from` — never
stored. One date per row means there is no second value to keep consistent and no overlap
to police.

`employees` holds only what does not change: `employee_no`, `hired_at`, `separated_at`,
`organization_id`, the person's identity, and the nullable `user_id`.

**Why a full history rather than current columns plus an audit log:** the pay engine
computing March's payroll after a June promotion must read March's `is_art82_exempt` and
March's base rate. With a history table that is a lookup; with an audit log it is a replay,
and the M1 whole-branch review named a wrong multiplier on a past period as the most
expensive failure the system can produce. A lookup cannot drift; a replay can.

## 3. The current-state cache — where an earlier promise met reality

The foundation spec promised office scoping stays a plain `WHERE office_id = ?` — the
entire reason the hierarchy is flat FKs rather than a tree. But `office_id`,
`department_id`, and `reports_to_id` now live in the effective-dated history, so "employees
in my office" would become "employees whose *current* record has `office_id = ?`" — a join
to a derived row, on the single most-executed query in the system.

**Resolution:** `employees` carries `current_office_id`, `current_department_id`, and
`current_reports_to_id` as a cache. The history table is the source of truth; these three
columns are derived from it.

**One action owns both writes.** `RecordEmploymentChange` inserts the new
`employment_records` row and updates the three cache columns on `employees` in the same
transaction. Because one action writes both, they cannot disagree. An arch test asserts
that no other code writes the `current_*` columns — the discipline is mechanically
enforced, not trusted.

This is POS's own pattern: a ledger for truth, a denormalized column for the hot query.
The cost is three columns and one guarded action; the return is that `EmployeeScope` stays
the plain, fast `WHERE` the flat-FK model was chosen to guarantee.

## 4. Authorization

Authority has two independent axes, and the design keeps them independent:

- **What you may do** — verbs. Only HR-Admin actions consult a verb permission.
- **Over whom** — scope, defined once in `EmployeeScope`.

A policy check is `has-permission(verb) AND subject-in-scope(actor, subject)`.

### The four scopes

`EmployeeScope` (`app/Domain/Scope/EmployeeScope.php`) returns a **query constraint, not a
boolean**, so it composes into any index query and there is exactly one place the boundary
is defined:

| Actor | Constraint |
| --- | --- |
| Employee (baseline) | `id = self` |
| Manager (derived) | `current_reports_to_id = self` |
| HR Admin | `current_office_id IN (hr_admin_offices for this user)` |
| System Admin | unconstrained, via `Gate::before` |

They **compose additively**: an HR Admin who also has direct reports sees the union of both
constraints.

**Manager is derived, never assigned.** Anyone some employee's `current_reports_to_id`
points at is a manager, and the authority to act on *their own* reports is baseline —
granted by the scope relationship itself, not by a permission. There is no "Manager" role
to assign on promotion or forget to revoke on transfer; the org chart is the single source
of manager-truth. This is why direct-reports-only scoping and derived-manager authority fit
together exactly.

### Spatie's narrowed role

With manager derived, System Admin a flag, and HR scope in the pivot, spatie carries one
thing in M2: the set of verbs an HR Admin may perform, as editable data.

- Installed **without teams** (the foundation spec's argument: there is no device token to
  make a team context unambiguous, and a stale team context returns silently wrong answers).
- One seeded `HR Admin` role holding the HR permission catalog: `employee.manage`,
  `employee.pii.edit`, `leave.approve`, `schedule.manage`, `holiday.manage`,
  `cutoff.manage`.
- An HR Admin is assigned that role **and** has `hr_admin_offices` rows. The verbs come
  from the role; the scope comes from the pivot; a policy needs both.
- `users.is_system_admin` + `Gate::before` for global oversight — not a spatie role,
  because POS proved a global role assignment cannot exist (`model_has_roles`'s team key is
  part of the primary key and therefore `NOT NULL`).

Keeping the catalog as data honors the "fully configurable" brief and gives future
specialized roles (Payroll Officer, Recruiter, read-only Auditor) a home, without building
role CRUD — that is a later milestone, and none of it is needed to prove scoping works.

### Policies

M2 ships `EmployeePolicy` end to end as the proof of the two-check shape:

- verb via spatie `can()` where the action needs one (create/edit employee, edit PII),
- subject via `EmployeeScope` always.

An arch test asserts every index action routes through `EmployeeScope` — a policy that
checks the verb but forgets the subject is exactly the bug this prevents. The leave,
schedule, and holiday policies arrive with their features in M4–M5, built on this same
shape.

**Refusals are 404, not 403.** "This exists but isn't yours" leaks the org chart, and for
salary and disciplinary records that leak is itself the disclosure. When scope excludes a
subject the controller returns `not_found`, using the closed error envelope M0 already
built.

## 5. Auth and the API surface

Sanctum token auth, browser email/password. Every endpoint is one route → invokable
controller → action → resource, so `app/Actions/` and the route list stay diffable.

```
POST /api/v1/login              email + password -> { token, user } ; rate-limited
POST /api/v1/logout             revoke the current token
GET  /api/v1/me                 the session envelope (below)

POST /api/v1/admin/employees                  create employee (+ optional user)   [system admin]
POST /api/v1/admin/employees/{id}/user        provision or set login + password    [system admin]
POST /api/v1/admin/employees/{id}/employment  RecordEmploymentChange               [system admin]
GET  /api/v1/employees                         index — constrained by EmployeeScope
GET  /api/v1/employees/{id}                     show — 404 if out of scope
```

### The `/me` envelope

The single source of scope truth the frontend reads — navigation, route guards, and a
`<Can>` component all consume it, and nothing else calls a permissions endpoint:

```json
{ "data": {
  "user": { "id", "email", "name" },
  "employee": { "id", "employee_no", "current_office_id", "current_department_id" },
  "is_system_admin": false,
  "has_reports": true,
  "hr_offices": ["office-uuid"],
  "permissions": ["leave.approve"]
} }
```

`has_reports` (is anyone's `current_reports_to_id`) and `hr_offices` (from
`hr_admin_offices`) are what the four navigation segments — `me` / `team` / `office` /
`admin` — gate on, mapping 1:1 onto the four scopes so navigation and policies can be
diffed against each other.

### Login error behavior

- Wrong credentials → `401 invalid_credentials`, and the message never reveals whether the
  email exists.
- Rate-limit exceeded → `429 too_many_requests`, via the envelope M0 already handles.

## 6. Seeders

One organization, two offices deliberately far apart so a scope leak is visible in a test:

- **Manila** (HQ) and **Cebu** (branch), both `Asia/Manila`, two departments each.
- ~12 employees with a real reporting chain: a System Admin; one HR Admin per office, each
  with `hr_admin_offices` rows for *their own office only*; managers with reports;
  rank-and-file. Including **one Art. 82-exempt manager** so the M1 exemption has live
  data, and **one punch-only worker with no `user_id`** so the nullable-login path is real.
- The seeded `HR Admin` spatie role and its verb catalog.

**Holidays are not seeded in M2.** An earlier draft of this spec listed the 2026 PH holiday
set here, but §1's schema has no `holidays` table — holiday calendar management is M4's
domain, and the table lands there. Seeding holidays in M2 would require a table this
milestone does not build. The holiday set is seeded in M4, alongside the table that holds it.

The seeder prints login credentials for each of the four scopes, as POS's does.

## 7. Testing

Real Postgres, never SQLite — M2 leans on `uuidv7()`, partial and composite indexes.

- **The four-actor scope matrix, as feature tests** — the milestone's proof and its
  done-when: an employee 404s on a peer; a Manila HR Admin 404s on a Cebu employee; a
  manager sees exactly their reports and 404s on a peer's report; a System Admin sees all.
  Note the 404-not-403 rule is about out-of-scope *subjects*; an unauthorized *actor* (a
  non-admin creating an employee) is a legitimate `403 forbidden`, a different case.
- **`RecordEmploymentChange` cache coherence** — after a promotion, the history row and the
  three cache columns agree; the arch test proves only that action writes the cache.
- **Effective-date resolver** — "what was true on date D" picks the correct row across a
  promotion boundary.
- **Auth** — login rate limit; `invalid_credentials` does not leak email existence; the
  `/me` envelope shape per scope.
- **Arch** — every index action routes through `EmployeeScope`; new domain code obeys the
  M1 purity rules (no `config()`, framework-agnostic, final).

**Done when:** the four-actor scope matrix is green, a promotion keeps cache and history in
sync, and the seeder produces a company you can log into as each of the four scopes.

## Non-goals for M2

Self-service password reset, email invitation, and email verification (no mail transport
until there is an SMTP story). Role CRUD and a multi-role permission catalog. The leave,
schedule, holiday, and cutoff policies (their features are M4–M6). Any pay computation —
M2 provides the data the M5 engine will read, and nothing more.

## Open questions

None blocking. One to settle before M4: whether `hr_admin_offices` should ever grant a
whole organization at once (an "all offices" HR Admin) rather than office by office. M2
models it office by office, which the union in `EmployeeScope` already supports scaling to
many rows; a single org-wide grant is a convenience to add only if a real HR structure asks
for it.
