# RBAC

`spatie/laravel-permission`, installed **without the teams feature**. Authority has two
independent axes and the design keeps them independent: **what you may do** (verbs, via
spatie) and **over whom** (scope, via one query constraint). A policy check is the
conjunction of the two — `has-permission(verb) AND subject-in-scope(actor, subject)` — and
neither half is allowed to stand in for the other.

## The divergence from POS: no teams

POS enables spatie's teams feature with the team key mapped to `location_id`, so a role
assignment is `(user, role, location)` and a supervisor at one store is not a supervisor at
another. **HRIS does not do this, and the reason is specific.**

POS's teams were affordable because the team context was never ambiguous or user-supplied:
a register is bound to a location, the device token identifies the register, so "which team
is this request about?" is answered by the physical terminal the request came from. That is
the one thing that makes spatie's teams pleasant instead of a footgun — normally the app
has to decide which team a request concerns, and users switch between them, and a **stale
team context returns a silently wrong answer**, which for an authorization boundary is the
worst failure mode available.

**There is no device in HRIS.** An employee logs in with an email and a password from
whatever browser they happen to be at. If scope were a spatie team, the team context would
have to come from the user or the request — which is exactly the ambiguity POS's design
spent a device token to eliminate. So HRIS puts scope in data instead: an `hr_admin_offices`
pivot (`02-data-model.md`) answers "over whom," roles answer only "what," and the two never
have to be reconciled through a mutable per-request team id.

Consequence: **roles are global.** `roles.name` is globally unique (no team column), and a
role grants the same verbs to whoever holds it, everywhere. Scope is not a role's job.

## The two axes

### Verbs — spatie permissions

Named `resource.action`, matching a `can('employee.manage')` call site. The permission
*catalog* is code, seeded from `RbacSeeder`, never created at runtime in M2. One role holds
them all:

**`HR Admin`** — the only spatie role seeded in M2:

| Permission | Gates |
| --- | --- |
| `employee.manage` | Create and edit employee records |
| `employee.pii.edit` | Edit personally-identifiable / sensitive fields |
| `leave.approve` | Approve a leave request (feature lands M6) |
| `schedule.manage` | Manage schedules (M4) |
| `holiday.manage` | Manage the holiday calendar (M4) |
| `cutoff.manage` | Open and close cutoff periods (M7) |

Four of these six gate features that do not exist yet. They are seeded now anyway because
the role catalog is the "fully configurable" surface the brief asked for, and naming a
permission before its endpoint exists is cheaper than a migration per feature — the same
forward-declaration the schema uses for `offices.geofence_*`. **M2 enforces none of the six
yet — this is data seeded for M4+ to wire, not a verb any reachable endpoint checks.**
`employee.manage` is referenced exactly once in the codebase, in `EmployeePolicy::update()`,
and no controller calls `update` (or `authorize('update', ...)`) in M2 — the only
`EmployeePolicy` ability any endpoint exercises is `view`. The admin employee endpoints
(`POST /admin/employees`, the login-provisioning and employment-change routes) are gated by
`$this->user()?->is_system_admin` in each FormRequest's `authorize()` — a system-admin
check, not a permission check — which is why a non-admin hitting them gets `403`. An HR
Admin's actual authority in M2 is entirely scope-based: `EmployeeScope` plus
`EmployeePolicy::view` let them view and list employees within their `hr_admin_offices`, and
that authority exists whether or not they hold the `HR Admin` role's verbs at all. The six
verbs become load-bearing only when M4–M8 wire an employee-edit or leave-approval endpoint
that names one.

### Scope — `EmployeeScope`

`App\Domain\Scope\EmployeeScope::visibleTo(User): Builder` is the **single** definition of
"which employees may this user see." It returns a **query constraint, not a boolean**, so
it composes into any index query and there is exactly one place the boundary lives. A policy
that checks a verb but forgets the subject is precisely the bug this exists to prevent.

Four scopes, composed **additively** — the visible set is the union of whichever apply:

| Actor | Constraint |
| --- | --- |
| Employee (baseline) | `id = own employee id` |
| Manager (derived) | `current_reports_to_id = own employee id` |
| HR Admin | `current_office_id IN (their hr_admin_offices)` |
| System Admin | unconstrained |

An HR Admin who also has direct reports sees the union of both — the constraints `orWhere`
together inside one `where` group. A user with none of these (no employee, no reports, no
HR offices) is forced to an empty result (`whereRaw('1 = 0')`), never an unconstrained one:
the absence of any grant is "sees nothing," not "sees everything."

`EmployeeScope` reads only the `current_*` cache columns (`02-data-model.md`), which is why
those columns exist — scope stays a flat `WHERE`, never a join to a derived
effective-dated row.

## Manager is derived, never assigned

**There is no "Manager" role.** Anyone some employee's `current_reports_to_id` points at is
a manager, and the authority to act on *their own* reports is baseline — granted by the
scope relationship itself, not by a permission. The org chart is the single source of
manager-truth, so manager-ness cannot drift from it: there is nothing to assign on
promotion or forget to revoke on transfer. Move an employee under a new manager (a new
`employment_records` row via `RecordEmploymentChange`, which updates
`current_reports_to_id`), and the old manager stops seeing them and the new one starts, with
no role change anywhere. This is why direct-reports-only scoping and derived-manager
authority fit together exactly — each makes the other coherent.

Scope is **direct reports only**, not the whole sub-tree. Recursive manager scope (a
skip-level seeing their reports' reports) would cost a materialized path on `employees` plus
cycle detection and would make the scope check the most expensive query in the system; it
is deferred (`06-roadmap.md`) until a real org chart demands it.

## System Admin is a flag, not a role

Global oversight is `users.is_system_admin` plus a `Gate::before` hook, not a spatie role:

```php
// AppServiceProvider::boot()
Gate::before(fn (User $user): ?bool => $user->is_system_admin ? true : null);
```

Returning **`null`, not `false`**, for everyone else is essential — `false` would deny all
non-admins outright instead of letting the normal policy chain run. This is spatie's own
recommended super-admin pattern, and it is the same shape POS uses for `is_admin`. A test
pins both halves: a system admin passes a permission that was never assigned to them *and*
one that does not exist as a permission at all; a plain user is unaffected by the bypass.

**Why it cannot be a role.** POS proved a global role assignment is impossible to express,
and HRIS inherits the proof even though it turned teams off. With teams on,
`model_has_roles`'s team key is part of the table's primary key and therefore `NOT NULL`, so
a "global" assignment with a null team fails at insert. With teams off, a role is global by
definition — but then it grants its verbs to *every* holder uniformly, and "system admin"
is not a verb set, it is the authority to **bypass** verb and scope checks entirely
(including seeing every office's employees, which no enumerable permission expresses).
`Gate::before` is that bypass; a role is not.

`EmployeeScope` handles the system admin explicitly too — it returns the unconstrained
`Employee::query()` for a system admin — because index queries call the scope directly and
`Gate::before` only short-circuits *gate* checks, not a raw query. Both paths grant "all" so
the two can never disagree.

## Why spatie carries exactly one role in M2

With manager derived, system admin a flag, and HR scope in the pivot, spatie is left
carrying one thing: the set of verbs an HR Admin may perform, as editable data. That is
deliberately minimal. Role CRUD and a multi-role catalog (Payroll Officer, Recruiter,
read-only Auditor) are a later milestone; none of it is needed to *prove* the authorization
model works, and the model is proven by the four-actor scope matrix below, not by how many
roles exist. Keeping the one catalog as data — rather than hardcoding the HR verb list in a
policy — is what gives those future roles a home without building the CRUD now.

## Refusals: 404 for subjects, 403 for actors

Two different failures, two different codes, and conflating them is a real leak:

- **An out-of-scope *subject* is `404 not_found`, never `403`.** Telling someone "this
  employee exists but isn't yours" leaks the org chart, and for salary and disciplinary
  records that leak *is* the disclosure. `ShowEmployeeController` checks
  `$request->user()->cannot('view', $employee)` and throws `NotFoundHttpException` — a
  denied view is byte-for-byte indistinguishable from a nonexistent id. The index has
  nothing to 404 on: it filters through `EmployeeScope` before loading, so an out-of-scope
  employee is simply never in the result set.
- **An unauthorized *actor* is `403 forbidden`.** A non-admin hitting
  `POST /admin/employees` is told plainly they may not create employees — "you may not do
  this kind of thing at all" is an actor check, not the out-of-scope-subject case the
  404 rule protects. The admin `FormRequest`s' `authorize()` returns false for a non-admin,
  which Laravel renders as `403` through the closed error envelope.

The distinction is the milestone's proof surface. Getting a subject refusal as a 403 would
leak; getting an actor refusal as a 404 would be a confusing lie. The scope matrix asserts
both shapes.

## Policies and the two-check shape

M2 ships `EmployeePolicy` end to end as the proof of the shape every later policy copies:

- **verb** via spatie `can()` where the action needs one (create/edit an employee, edit
  PII),
- **subject** via `EmployeeScope`, always.

`EmployeePolicy::view()` resolves "can see this record" as membership in
`EmployeeScope::visibleTo($user)` — the *same* definition the index filters on — so the
show path and the index path can never disagree about who is visible. Three arch rules keep
this honest:

1. `ListEmployeesController` must reference `EmployeeScope` — the index, which loads
   employees directly with nothing between it and the database, may never bypass the scope.
2. `EmployeePolicy` must reference `EmployeeScope` — "can see" is defined as scope
   membership, not re-derived.
3. Every controller under `app/Http/Controllers/Employees/` must reference an authorization
   boundary — `EmployeeScope`, or a per-record gate call (`->cannot(`, `->can(`,
   `->authorize(`, `Gate::`). A file with neither is an unguarded read path that can load
   and serialize an employee with no check at all. This one is a source-grep for the
   *reference*; the semantics (right ability, right query) are proven by the feature matrix,
   not the grep.

The leave, schedule, holiday, and cutoff policies arrive with their features in M4–M7, built
on this same two-check shape.

`EmployeeScope` lives in `app/Domain/Scope/` and is the one Domain class allowed to touch
Eloquent — its entire contract is "hand back a constrained query." The framework-agnostic
arch rule that bars facades and `config()` from the Domain layer carves it out explicitly
(`->ignoring('App\Domain\Scope\EmployeeScope')`); the rule was always about config purity,
never about barring the ORM from the one class whose job is to return a Builder.

## Seeding

`RbacSeeder` seeds the permission catalog and the single `HR Admin` role, and is safe to
re-run (`findOrCreate` throughout). The permission cache is flushed **between** creating the
permissions and syncing them onto the role, not only at the end: `findOrCreate`'s first
lookup loads the registrar's permission collection while it is still empty and caches that
empty result, so a subsequent `syncPermissions()` resolves names against the stale
collection and throws `PermissionDoesNotExist` for a permission that was *just* inserted.
This bites on a fresh boot (`migrate:fresh --seed`) where nothing warmed the cache with the
real rows first; flushing between create and sync forces the reload. A final flush after
writing lets the next seeder in the same process (`CompanySeeder`, which assigns the role)
read the fresh set.

`CompanySeeder` then builds the company: an HR Admin per office is *both* assigned the
`HR Admin` role (its verbs) *and* given an `hr_admin_offices` row for its own office only
(its scope) — because a policy needs both, and neither alone makes an HR Admin. See the
seeder for the full cast.

## `OfficeScope` — the M4 config boundary

`EmployeeScope` answers "which employees may this user see"; `App\Domain\Scope\OfficeScope`
(`app/Domain/Scope/`) is its sibling for per-office **configuration** — holiday calendars
(M4a) and schedules (M4b). Same shape, same reasoning: a query constraint, not a boolean,
so it composes into any office query and there is exactly one place "who may administer
this office" is defined. **`pay_rules` (M4c) deliberately does not use `OfficeScope`** —
see below.

| Actor | Constraint |
| --- | --- |
| System Admin | unconstrained — every office |
| HR Admin | `id IN (their hr_admin_offices)` |
| Anyone else (plain employee, manager with no HR grant) | none — forced empty, never unconstrained |

Three entry points, all pure and HTTP-agnostic (`administeredBy()`/`administered()`/
`administers()` never throw; every 404 decision is the controller's):

- **`administeredBy(User): Builder`** — the raw constraint, mirroring
  `EmployeeScope::visibleTo()`.
- **`administered(User, ?officeId): ?Office`** — used by the endpoints that take an office
  id *in the request body or query* (holiday list/create/clone): `null` means "not
  administered, or doesn't exist," and the controller turns that into `404`.
- **`administers(User, officeId): bool`** — used by the endpoints that already have the
  record via route-model binding (holiday update/delete): `false` means the same thing,
  turned into `404` the same way.

**Who may edit a holiday calendar.** Whether an actor may create, clone, update, or delete a
holiday for a given office is decided **entirely by `OfficeScope`** — there is no separate
verb check in M4a. The `holiday.manage` permission is seeded in the catalog (above) but is
not yet read by any holiday endpoint, the same situation `employee.manage` was in through
M2 (a permission named ahead of the feature that will check it, not a permission any
reachable code path enforces today). Concretely: a System Admin administers every office's
holidays; an HR Admin administers exactly the offices in their `hr_admin_offices` pivot,
regardless of which spatie role they hold; anyone with no HR grant — a plain employee, a
manager with no HR offices — administers zero offices, `whereRaw('1 = 0')`, same as
`EmployeeScope`'s empty-scope floor.

**The same 404-not-403 discipline, applied to a second resource.** A holiday whose office
the caller doesn't administer is `404`, indistinguishable from a nonexistent office or a
nonexistent holiday id — the `FormRequest`s validate `office_id`/`office` as shape-only
`uuid`, deliberately never `exists:offices,id`, so a fabricated id and an out-of-scope real
one take the identical code path to the identical `NotFoundHttpException`. See `03-api.md`
for the endpoint-level detail and `02-data-model.md` for the `holidays` table.

**M4b's schedules are governed by the exact same `OfficeScope`, with no new authority
model.** Every shift-template, assignment, override, default-template, and resolved-read
endpoint resolves scope through `administered()`/`administers()` the same way holidays do —
a shift template is scoped by its own `office_id` (like a holiday); a schedule assignment or
override is scoped by its *target*'s office (an employee's `current_office_id`, or a
department's `office_id` — there being no `office_id` column on the assignment/override rows
themselves), which the controller resolves before ever calling `OfficeScope`. Same
404-not-403 discipline throughout: every schedule `FormRequest` validates an id as
shape-only `uuid`, never `exists:`, so an out-of-scope real id and a fabricated one 404
identically (`scripts/e2e-schedules.sh` proves this against the live stack, mirroring
`e2e-holidays.sh`). See `03-api.md` for the endpoint-level detail and `02-data-model.md` for
the four schedule tables.

## Pay rules — System Admin only, no scope at all *(M4c)*

`pay_rules` management is gated by neither `OfficeScope` nor a spatie permission verb —
there is no `pay_rules.manage` (or similarly named) entry in the permission catalog above,
and none is needed. Every pay-rule `FormRequest`
(`CreatePayRuleRequest`/`ListPayRulesRequest`/`PayRuleAdminRequest`) authorizes with the
same one-line check the M2 onboarding endpoints use: `(bool)
$this->user()?->is_system_admin`. `Gate::before` (above) would bypass any policy or
permission check for a System Admin anyway, so this is the direct form of the identical
rule, not a different one.

**Why no scope:** `OfficeScope` answers "which offices may this actor administer" — a
question that only makes sense for a resource that *has* an office. A pay rule prices
every office identically; there is no `office_id` column to scope by, and therefore no
per-subject enumeration risk the 404-not-403 discipline exists to close off. A non-admin
gets the default `403 forbidden` from `failedAuthorization()`, exactly like a non-admin
hitting `POST /admin/employees` — an actor refusal, not a subject one. See `03-api.md` for
the endpoint detail and `02-data-model.md` for the `pay_rules`/`pay_rule_day_rates` tables.

## Testing

The milestone's proof is the **four-actor scope matrix**, as feature tests
(`tests/Feature/Employees/ScopeMatrixTest.php`), because a scope model asserted in prose is
a scope model nobody has run:

- an **employee** `404`s on a peer;
- a **manager** sees a direct report (`200`) and `404`s on a peer's report / a Cebu worker;
- a **Manila HR Admin** sees a Manila worker (`200`) and `404`s on a Cebu employee;
- a **System Admin** sees everyone (`200`);
- the **index** returns exactly the manager's own scope — themselves and their reports, not
  the Cebu worker.

Plus: `RbacSeeder`'s role-and-bypass behavior (`HrRoleTest`); the `Gate::before` bypass
returns `null` not `false` for non-admins; and the single-writer cache guard
(`02-data-model.md`) in the arch suite. Two offices deliberately far apart (Manila, Cebu)
so a scope leak shows up as a failing assertion rather than a subtle production bug.

**`OfficeScope` gets its own three-actor matrix** (`tests/Feature/Scope/OfficeScopeTest.php`):
a System Admin administers every office; an HR Admin administers only the office(s) in their
`hr_admin_offices`; a plain user with none administers zero, never all. The holiday endpoints'
own feature tests (`tests/Feature/Office/HolidayReadWriteTest.php`,
`CloneHolidaysTest.php`) then assert the byte-identical-404 proof end to end — an out-of-scope
office/holiday and a fabricated one produce `assertExactJson`-equal bodies, not just matching
status codes.
