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
| `leave.approve` | Approve a leave request (landed M6b-b — see the note below) |
| `leave.manage` | Manage leave-type config and manually grant leave (M6b-a) |
| `schedule.manage` | Manage schedules (M4) |
| `holiday.manage` | Manage the holiday calendar (M4) |
| `cutoff.manage` | Open and close cutoff periods (M7a) |
| `document.manage` | Manage the document catalog (M10b-a); office-scoped file access (M10b-b) |
| `document.manage.self` | File and read your OWN documents (M10b-b) — never delete |

Five of these seven gate features that do not exist yet. They are seeded now anyway because
the role catalog is the "fully configurable" surface the brief asked for, and naming a
permission before its endpoint exists is cheaper than a migration per feature — the same
forward-declaration the schema uses for `offices.geofence_*`. **M2 enforces none of the
seven yet — this is data seeded for M4+ to wire, not a verb any reachable endpoint checks.**
`employee.manage` is referenced exactly once in the codebase, in `EmployeePolicy::update()`,
and no controller calls `update` (or `authorize('update', ...)`) in M2 — the only
`EmployeePolicy` ability any endpoint exercises is `view`. The admin employee endpoints
(`POST /admin/employees`, the login-provisioning and employment-change routes) are gated by
`$this->user()?->is_system_admin` in each FormRequest's `authorize()` — a system-admin
check, not a permission check — which is why a non-admin hitting them gets `403`. An HR
Admin's actual authority in M2 is entirely scope-based: `EmployeeScope` plus
`EmployeePolicy::view` let them view and list employees within their `hr_admin_offices`, and
that authority exists whether or not they hold the `HR Admin` role's verbs at all. The seven
verbs become load-bearing only when M4–M8 wire an employee-edit or leave-approval endpoint
that names one.

**`leave.manage` is the first of these to widen from catalogued to actually reachable
(M6b-a), and it widens the same way `holiday.manage`/`schedule.manage` already had:
`RbacSeeder` seeds it onto the `HR Admin` role, and `GET/POST/PATCH /office/leave-types`,
`PATCH /office/leave-day`, and `POST /leave/grants` all exist and are reachable — but none
of them calls `can('leave.manage')` or `authorize(...)` against it anywhere in the codebase.
The actual boundary on every one of those routes is `OfficeScope::administered`/
`administers`, the same per-office config scope holidays and schedules already use (see
`02-data-model.md`). `leave.manage` sits in the catalog alongside `holiday.manage` and
`schedule.manage` for the same reason they do: it names the capability for a future
role-management UI (M8) to display and assign, but no controller in this codebase branches
on it today. Treat it as documentation of intent, not as a second, redundant gate behind
`OfficeScope`.**

**`leave.approve` reached the same state in M6b-b, and for the identical reason.** A leave
request's decision runs through `POST /requests/{request}/approve|reject`, generalized
already in M6a — there is no `leave`-specific route for either verb, so there is nothing
for `leave.approve` to gate that isn't also reachable by an attendance-adjustment decision.
The actual boundary is `App\Domain\Requests\RequestAuthority::canDecide` (below), which
never reads a spatie permission at all — a manager or HR admin decides a leave request
because the org chart or `hr_admin_offices` says they may, not because they hold
`leave.approve`. It stays cataloged for the same future role-management reason
`leave.manage`/`holiday.manage`/`schedule.manage` do.

**`employee.pii.edit` is the third of these seven to leave "catalogued but unread," landing
in M10a — and unlike `leave.manage`/`leave.approve` above, this one became a real gate a
controller actually checks, not just a name a future UI will display.** `RbacSeeder`
has carried it on the `HR Admin` role since M2; no endpoint read it until
`App\Policies\EmployeePolicy::viewFullProfile`/`updateProfile` (below) started calling
`$user->can('employee.pii.edit')`, paired with the `hr_admin_offices` pivot — the same
two-axis shape (verb via spatie, scope via a pivot) this whole document argues for.
`employee.manage` remains in the state it was in through M2: referenced only in
`EmployeePolicy::update()`, which nothing calls. M10a activates `employee.pii.edit` rather
than overloading `employee.manage`, because "edit personal data" (an address, a TIN) and
"onboard/transfer/rename" are different acts that happen to share a subject.

### Request approval authority — `RequestAuthority` and the two-hop leave routing *(M3.6, generalized M6a, widened M6b-b, overtime M6c)*

A third scope, purpose-built to a **request's current decision hop** rather than "who sees
whom" (`EmployeeScope`) or "who administers this office" (`OfficeScope`).
`App\Domain\Requests\RequestAuthority::canDecide(User, Request): bool` answers "may this
actor decide THIS request right now" — state-aware since M6b-b, because a two-hop request's
answer differs at `pending` versus `manager_approved`.

Two pure building blocks:

- **`isManagerOf(approver, request)`** — `true` when the request's employee's
  `current_reports_to_id` is the approver's own employee id. The same derived-manager
  relationship `EmployeeScope` reads (`current_reports_to_id`, above) — there is still no
  "Manager" role.
- **`isHrOf(approver, request)`** — `true` when the request's employee's
  `current_office_id` is one of the approver's `hr_admin_offices`. The same pivot
  `OfficeScope` reads.

`canDecide` composes them, gated by state and by `RequestType::requiresHrStep()`:

1. **Never the requester themselves** — checked first, regardless of hop, scope, or role.
2. **A System Admin always may** — M6a's decision that a system admin keeps full API
   authority even though `ApprovalQueues` gives them no *queue* (below); at a terminal
   state this still yields `409` (decided) rather than `404` (never had authority),
   preserving the ordering below.
3. **At `pending`:** a single-hop type (`attendance_adjustment` or, since M6c, `overtime`)
   accepts `isManagerOf` OR `isHrOf` — either authority is enough, exactly as M6a proved. A
   two-hop type (`leave`) accepts `isManagerOf` **alone** — HR has no authority over a leave
   request that hasn't cleared the manager's hop yet, even for their own office.
4. **At `manager_approved`** (two-hop only, M6b-b): `isHrOf` **and** the approver is not the
   user who decided hop 1 (`manager_decided_by`). This is a genuine two-person rule, not
   just two titles — a manager who is *also* their own office's HR admin cannot clear both
   hops of the same request by themselves; someone else in HR must decide hop 2.
5. **Terminal** (`approved`/`rejected`/`cancelled`): the same in-scope test as `pending`
   (`isManagerOf` OR `isHrOf`) — preserved so a previously-authorized actor's second
   decision 409s (already decided) rather than 404ing (never had authority), the
   existence-leak ordering `03-api.md` documents for the single-hop case and that holds
   identically here.

**`ApprovalQueues` mirrors this routing as two views, not a second authority model.**
`/team/approvals` stays `pending`-only for every type — a two-hop request that has cleared
hop 1 no longer belongs there, because the manager's decision is done.
`/office/approvals` is hop-aware: a single-hop type the moment it is `pending` (HR is its
only decider, same as M6a), or **any** type once it reaches `manager_approved` — a two-hop
request appears there only once the manager has cleared it. Both queues remain `Builder`
views over the same underlying authority, never a redefinition of who may decide what.

**Overtime (M6c) adds no new permission.** Filing an overtime pre-authorization is
un-gated exactly as filing an attendance adjustment or leave is: any authenticated employee
may `POST /overtime/requests` for their **own** record — the requester-identity check is the
only boundary (`SubmitOvertimeRequestController` takes `$request->user()->employee`, never a
target-employee id, so there is nothing to enumerate and no admin gate to apply). *Deciding*
an overtime request reuses everything above unchanged: `overtime` is a single-hop type, so
`canDecide` accepts `isManagerOf` OR `isHrOf` at `pending`, and it surfaces on both
`/team/approvals` and `/office/approvals` the moment it is filed — the same routing
`attendance_adjustment` takes. No `overtime.*` spatie verb exists or is needed, for the same
reason `leave.approve` stays cataloged-but-unread: the boundary is `RequestAuthority`, which
reads no permission at all.

**Cancellation has its own, narrower rule, unaffected by any of the above:** only the
requester may cancel their own request (`App\Actions\Requests\CancelRequest`), from
`pending` OR `manager_approved` — a manager or HR admin who could *approve* the request may
never cancel it on the requester's behalf, at either hop.

See `02-data-model.md` for the schema this widens (`manager_decided_by`/`manager_decided_at`,
the `requests_state_check` widening) and `03-api.md` for the endpoint-level detail and the
full state table.

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

**M7a's cutoff endpoints are governed by the exact same `OfficeScope`, with no new authority
model** — the same situation holidays and schedules are in. `cutoff.manage` is seeded on the
`HR Admin` role (RbacSeeder) and named in the catalog above, but **no cutoff endpoint reads
it**: the enforced boundary is `OfficeScope::administered`/`administers` alone, identical to
holidays, schedules, and leave-types. `GET /office/cutoffs` and `POST /office/cutoffs/close`
resolve the `office_id`/`office` they're handed; `POST /office/cutoffs/{period}/reopen`
resolves the bound period's own `office_id`. So a System Admin administers every office's
cutoffs; an HR Admin exactly the offices in their `hr_admin_offices` pivot; anyone else zero.
**No new permission was added** for M7a — `cutoff.manage` was already in the catalog, a verb
named ahead of the gate that will one day read it. Same 404-not-403 discipline: an out-of-scope
office (or a period in one) is `404`, indistinguishable from a nonexistent one, because the
`FormRequest`s validate the office id as shape-only `uuid`, never `exists:`. The per-employee
`Employee` row lock that serializes a close against a concurrent approval or recompute is a
*concurrency* control, not an authorization one — it sits below this scope check, inside the
action. See `03-api.md` for the endpoints and error codes and `02-data-model.md` for the
`cutoff_periods` table.

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

## The organization tree — System Admin only, the deliberate 404-not-403 exception *(M8a)*

The admin org-tree surface (`/admin/organizations|offices|departments`, `03-api.md`) is
gated exactly like pay rules: every `FormRequest::authorize()` is the one-line `(bool)
$this->user()?->is_system_admin`, no `OfficeScope`, no permission verb. It is the same
reasoning taken one step further, and it is the **one place the 404-not-403 discipline is
deliberately not applied to a subject.** An out-of-scope employee 404s so the org chart
never leaks (above) — but the org tree *is* the org chart's container, edited only by the
one actor (the System Admin) who already sees every office, so there is no narrower scope
to protect and nothing a non-admin could enumerate: they cannot reach the surface at all.
An office or department cannot be scope-checked by `OfficeScope` either, because it has no
*parent* office to gate by — it either is one, or belongs to one. So a non-admin gets the
plain `403 forbidden` from `failedAuthorization()` on every verb, create through
archive/unarchive — an actor refusal, never a subject `404`. The create `FormRequest`s
validate `organization_id`/`office_id` as shape-only `uuid` (never `exists:`), so even a
nonexistent parent surfaces as a `422` from the FK/constraint inside the action, not a
`404` — there is no id a caller could probe. `scripts/e2e-admin-org.sh` asserts the
non-admin `403` live.

## HR-Admin access at runtime, and the audit viewer *(M8c)*

The two axes above (verbs = the `HR Admin` role, scope = the `hr_admin_offices` pivot) are
what `CompanySeeder` sets by hand at seed time. **M8c makes the same pairing an admin can
change at runtime.** `POST /admin/employees/{employee}/hr-offices` (`03-api.md`) drives the
`SetHrAdminOffices` action, which writes *both halves in one transaction*: `sync()` on the
`hr_admin_offices` pivot **and** `assignRole('HR Admin')` — or, when `office_ids` is `[]`,
`removeRole('HR Admin')` alongside clearing the pivot. This is the generalization of the
seeder's `assignRole` + `hrAdminOffices()->attach` pairing to a live surface: because a
policy needs both and neither alone makes an HR Admin (above), the *only* supported way to
grant or revoke is together. A login with offices but no role, or a role but no offices, is
the exact bug this single-write action exists to prevent. The subject is a **login** — an
employee with no `user_id` is `422 employee_has_no_login`, since there is no `User` to carry
either half. `is_system_admin`-gated like the whole `/admin` surface: a non-admin gets `403`.

The **audit viewer** (`GET /admin/activity`, `03-api.md`) is the read side. It is a
filterable, paginated window over the one Spatie `activity_log` table every `LogsActivity`
model writes to — offices/departments/organizations (M8a), employee edits (M8b) — plus the
manual `hr_admin_offices_set` event `SetHrAdminOffices` logs. There is no separate audit
store: the audit trail *is* the activity log everything already writes, and the viewer only
reads it. `is_system_admin`-gated for the same reason as the org tree above — the log spans
every subject type company-wide, nothing office-scoped to check, so a non-admin gets `403`,
not `404`. `scripts/e2e-admin-roles-audit.sh` proves the grant/revoke coupling, the
login-less `422`, the viewer surfacing both the `hr_admin_offices_set` event and the
`log_name=office` trail, and the non-admin `403`, against the live stack.

## Employee profiling — three abilities on `EmployeePolicy` *(M10a)*

The personnel file (`02-data-model.md`, `03-api.md`) needed a third shape of authorization
that neither `EmployeeScope` alone nor a plain permission check could express: `who sees
what`, not just `who sees whom`. `App\Policies\EmployeePolicy` grows three abilities beyond
`view`/`update`:

- **`viewFullProfile`** — the whole personnel file, including national IDs and dependents.
  Self, or an HR Admin who administers *this employee's specific office*.
- **`viewRedactedProfile`** — contact and assignment only. Anyone `EmployeeScope` already
  admits — which is what lets a manager in.
- **`updateProfile`** — HR Admin only, and **never the subject themselves**, HR Admin
  included.

**`viewFullProfile`/`updateProfile` deliberately do NOT use `EmployeeScope`.** `EmployeeScope`
composes self + direct reports + HR offices *additively* (above), so a manager is always
inside their own direct report's scope — which is exactly the case that must **not** unlock
the full file. The HR branch instead reads the `hr_admin_offices` pivot directly against
`employee.current_office_id`, not `EmployeeScope::visibleTo()`:

```
viewFullProfile     = self  OR  is_system_admin
                            OR (can('employee.pii.edit') AND employee.current_office_id ∈ user's hr_admin_offices)
viewRedactedProfile = EmployeeScope::visibleTo(user) contains employee    -- admits the manager
updateProfile       = NOT self
                            AND ( is_system_admin
                                  OR (can('employee.pii.edit') AND employee.current_office_id ∈ user's hr_admin_offices) )
```

**Authority follows the office pivot, not the org chart — a consequence worth stating
plainly, because it is easy to assume the opposite.** An HR Admin who administers Cebu and
who *also* happens to manage a direct report stationed in Manila (the org chart, not the
pivot, put that Manila employee under a Cebu-based manager) gets the **redacted** view of
that report, not the full one. `viewFullProfile` never asks "do you manage this person,"
only "do you administer the office they currently sit in" — `viewRedactedProfile`, by
contrast, *is* `EmployeeScope` membership, which is what admits the manager relationship at
all. Two different questions, two different checks, and a reviewer who conflates them will
predict the wrong response for exactly this case.

**`updateProfile` denies self for EVERYONE, including an HR Admin editing their own
record — separation of duties on payroll-adjacent data, the same logic that already stops a
requester approving their own request.** The self-branch is checked **first** and outranks
the HR-office grant: an HR Admin whose own `employees` row happens to sit in an office they
themselves administer would otherwise pass the pivot check and be free to rewrite their own
TIN, SSS number, and bank account. Stating the rule as "the full-read check minus the self
branch" is not enough — an earlier draft of the spec said exactly that, and dropping only the
self branch blocks an *ordinary* employee from self-editing but not an HR Admin doing the
same to their own record, which is the actual hole a review caught during implementation.
The operational consequence is deliberate: two HR Admins in the same office maintain each
other's files, and a *lone* HR Admin's own file is a System Admin's job. **Reading your own
file is still allowed** (`viewFullProfile`'s self branch) — only editing it is not.

**Both self-comparisons test `employee.user_id === user.id`, with an explicit non-null
guard — never `user->employee?->id === employee->id`.** The latter form evaluates
`null === null` to **true** whenever the acting user has no `employees` row of their own
(an HR Admin or System Admin account with no personal employee record) tested against an
employee reference that also somehow resolves null — a fail-**open** result in
`viewFullProfile`, the one check standing between an arbitrary authenticated user and
someone else's TIN. `$employee->user_id !== null && $employee->user_id === $user->id`
structurally cannot produce that false positive.

**The scan-stream route (`GET /employees/{employee}/identifications/{identification}/scan`,
`03-api.md`) gates on `viewFullProfile`, deliberately not `viewRedactedProfile`.** A
manager's redacted resource never hands back an identification id in the first place, so a
manager reaching this route at all is a guessed id or an attack, not a legitimate
click-through — and the same 404-not-403 discipline applies: an unauthorized viewer and a
nonexistent identification are byte-identical `404`s. `DeleteIdentificationRequest` layers a
second, narrower check beside the policy call: the identification in the URL must actually
belong to the employee in the URL, or an HR Admin authorized over one employee could delete
an unrelated identification by pairing mismatched ids — checked in addition to
`updateProfile`, not instead of it.

**Testing.** `tests/Feature/Profile/ProfileScopeMatrixTest.php` runs six actors — self, the
subject's manager, an in-scope HR Admin, an out-of-scope HR Admin, an unrelated stranger,
and a System Admin — against all eight authenticated M10a routes (`GET /profile/catalog` is
excluded on purpose: it is ungated reference data, and a row of eight `true`s would be
noise), asserting `2xx`-or-`404` against a hand-written expectation table for all 48 cells —
the same "assert the shape, not just that some test exists" discipline the four-actor
`EmployeeScope` matrix below already established. **The denied branch asserts `$status ===
404` specifically, not merely "not 2xx" (M10a follow-ups).** The original assertion inverted
the allowed-cell check, so a denied cell returning `403` — the exact enumeration leak the
404-not-403 discipline exists to prevent — would have passed the matrix silently; a
mutation test that swapped one controller's denial from `NotFoundHttpException` to
`AccessDeniedHttpException` proved it (the matrix went red, naming the three actors that hit
the mutated route). The 404-not-403 discipline is now genuinely pinned here, not merely
asserted elsewhere and assumed to hold in this matrix too. `tests/Feature/Profile/ProfilePolicyTest.php`
exercises `EmployeePolicy` directly, including the fail-open null-guard case above.

**This model is now reachable from the browser (M10a final-fixes round).** It was correct
server-side from the start, but the only screen that called `GET /admin/employees/{id}/profile`
was `/admin/employees/{employee}`, whose whole page was `is_system_admin`-gated on the
frontend — an HR Admin the policy above already admits could never reach it, and the
`viewRedactedProfile` branch had no screen at all. `/employees/{id}/profile` (a new route
under the plain, non-`/admin` route group) now calls the full read and falls back to the
redacted read on a `404`, rendering whichever one the backend actually authorized for that
viewer — the frontend does not reimplement the office-pivot check, it just reads the
response the policy above already produces. See `06-roadmap.md` and `features.md` for the
route and the screen.

**The UI now reflects the self-edit denial instead of presenting a form that can only fail
(M10a follow-ups).** `viewFullProfile` admits self, so before this fix `/employees/{id}/profile`
rendered the live `ProfileForm` when a viewer opened their own record — and every submit then
hit `updateProfile`'s self-denial and surfaced the generic *"That didn't save. Check your
connection and try again."*, which reads like a network fault, not a deliberate
separation-of-duties rule. The backend was never wrong; only the screen was misleading. The
page now reads `isSelf` from `useSession()` — never inferred from a failed request, so the
notice shows before anyone submits anything — and when true, renders the read-only
`ProfileSections` view plus an `InlineNotification` stating the rule, instead of `ProfileForm`.
An HR Admin can still read their own file in full (`viewFullProfile`'s self branch, above);
they simply never see a form that was always going to 403.

## The document catalog — one ability, unscoped *(M10b-a)*

`document.manage` (permission table, above) is the second of the original seven-plus
catalog to move from "seeded, unread" to a real, enforced gate — after `employee.pii.edit`
(M10a). `leave.manage` and `leave.approve` remain catalogued-but-unread even today (see
their own entries above: neither is ever passed to `can()` or `authorize()` anywhere in
`app/`), so they don't count toward this total — a grep of `app/` for `can('` turns up only
three permission strings, ever: `employee.manage` (referenced but, per above, never actually
called), `employee.pii.edit`, and `document.manage`.

**`document.manage.self` is catalogued in M10b-a but still reads nowhere.** It joins the
seeded-but-unread set rather than leaving it, and becomes a real gate only when M10b-b
wires the file routes. Do not build on it as though it already enforces something — see
"grants nothing on the catalog" below, which is the shipped behaviour today.

`App\Policies\DocumentPolicy::manageCatalog` reads only `document.manage`:

```php
public function manageCatalog(User $user): bool
{
    return $user->can('document.manage');
}
```

**The check is deliberately unscoped — no `OfficeScope`, no `hr_admin_offices` pivot,
nothing else composed in.** `documents` and `document_categories` have no `office_id`
column; they are company-wide reference data the way `pay_rules` and the organization tree
are, so holding `document.manage` **is** the whole check, the same shape M4c and M8a
already established for a resource with no office to scope by. Every catalog `FormRequest`
under `app/Http/Requests/Documents/` authorizes identically:
`$this->user()?->can('manageCatalog', Document::class) === true`, and none overrides
`failedAuthorization()` — the plain `403 forbidden` is correct, per the 404-not-403
argument above (there is no owner id in a `/admin/document-categories`/`/admin/documents`
URL for the enumeration guard to protect). `Gate::before` still grants a System Admin
everything without needing the permission at all.

**`document.manage.self` grants nothing on the catalog** — `DocumentCatalogScopeMatrixTest`
proves this at the route level across all eight admin routes: an actor holding only
`document.manage.self` is denied identically to a stranger holding neither. That is by
design, not an oversight: `document.manage` and `document.manage.self` gate two entirely
different resources. **`document.manage` is office-scoped at the FILE level** (M10b-b) —
through the same `hr_admin_offices` pivot `OfficeScope` already reads for holidays,
schedules, and cutoffs — while remaining unscoped for the company-wide catalog above; a
Cebu HR Admin cannot upload against a Manila employee's record, but any HR Admin may add a
new document kind to the shared catalog. **`document.manage.self` permits an employee to
upload and read their own filed documents, but never delete one** — filing your own NBI
clearance is ordinary self-service, the same way M10a treats attendance corrections and
leave requests as self-service, but removing a filed document stays HR's act, keeping the
personnel file append-ish in the same spirit as punches and corrections
(`02-data-model.md`). Both verbs, and the office-scoped/self split they gate, apply to
M10b-b's file routes — M10b-a ships only the catalog and its unscoped check. **Managers get
no document access at all**, consistent with M10a's redacted profile view carrying no
identifications and no dependents.

**Both permission names are dotted, reinforcing the reserved-words rule `RbacSeeder`
already carries** (above, and the M10a follow-ups): spatie's own `Gate::before` grants any
ability whose **name** matches a permission the user holds, regardless of which policy
method that ability maps to. `DocumentPolicy`'s ability is named `manageCatalog` — had the
permission been seeded as the bare string `manageCatalog` instead of `document.manage`, any
role holding it would have been granted *every* policy ability named `manageCatalog`
anywhere in the codebase, present or future, bypassing whatever scope check that ability's
real implementation performs. `document.manage`/`document.manage.self` cannot collide with
a bare-word ability name because no policy ability is ever named with a literal `.` in it —
the same reason `employee.pii.edit`, `leave.manage`, and every other seeded permission is
dotted. `RbacSeeder`'s reserved-words comment names `viewFullProfile`/`viewRedactedProfile`/
`updateProfile` explicitly because those are `EmployeePolicy`'s bare ability names; a future
reader extending that comment for `DocumentPolicy` would add `manageCatalog` to the same
list.

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

**The document catalog gets a four-actor matrix too**
(`tests/Feature/Documents/DocumentCatalogScopeMatrixTest.php`), across all nine M10b-a
routes: an **HR Admin** (holding `document.manage`) and a **System Admin** (via
`Gate::before`) pass every route; an actor holding **only `document.manage.self`** and a
plain **stranger** are denied every `/admin/*` route identically, both getting `403`
specifically, not merely "not 2xx" — the same discipline `ProfileScopeMatrixTest` pins for
`404`, applied here to the plain-`403` shape this catalog uses instead. `GET
/documents/catalog` is included for all four actors (unlike `ProfileScopeMatrixTest`, which
excludes `GET /profile/catalog` as noise) specifically to document that it is ungated by
design, not by omission. `tests/Feature/Documents/DocumentPolicyTest.php` exercises
`DocumentPolicy::manageCatalog` directly.
