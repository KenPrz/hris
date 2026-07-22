# HRIS Foundation — Design

Date: 2026-07-23

The decisions this system is built on, and the reasoning that produced them. The roadmap
(`docs/06-roadmap.md`) sequences the work; this document says *why the work is shaped this
way*, so a decision can be revisited on its merits rather than re-argued from scratch.

Conventions, layering, and the action pattern are inherited wholesale from the POS
codebase and are not re-derived here. Where HRIS **diverges** from POS, that divergence is
called out explicitly, because an unexplained difference between two sibling codebases is
worse than either choice on its own.

## What we're building

A Human Resource Information System for a single Philippine company operating across
multiple offices. It owns the path from a raw attendance punch to a defensible,
Labor-Code-correct statement of what an employee is owed for a day — and stops there.
Gross-to-net payroll is deliberately out of scope for v1.

## Decisions locked for v1

| Decision | Choice | Reversibility |
| --- | --- | --- |
| Payroll scope | Hours and multipliers now; gross-to-net later | **Designed for** — M6's export is the payroll module's input |
| Tenancy | Single company, multi-organization | **Expensive to change** — revisit early or not at all |
| Hierarchy | Fixed 3 tiers as explicit FKs | Cheap to deepen; expensive to make arbitrary |
| Pay rules | Data-driven, effective-dated rows | Cheap — a table, not a deploy |
| Scope enforcement | Global spatie roles + `hr_admin_offices` pivot | Moderate — policies would need rewriting |
| Manager scope | Direct reports only | Cheap to widen, expensive to narrow |
| Approval routing | Fixed Manager → HR, per-type HR step | Moderate — N-step chains are a schema change |
| Overtime | Pre-authorization required | Cheap — a config flag could add auto-detect |
| Scheduling | Templates + assignments + dated overrides | Cheap; per-day rostering is a module |
| Leave | Configurable types + simple accrual | Cheap to extend to tiered accrual |
| Timekeeping sources | Web self-service + manual entry now | **Designed for** — device contract exists from M3 |
| Frontend | One Next.js app, role-aware navigation | Moderate |

## 1. Scope and boundaries

**Payroll is phased, not omitted.** The system computes worked minutes and the pay
multiplier that applies to them. It does not compute SSS, PhilHealth, Pag-IBIG, or
withholding tax, and it does not generate payslips or remittance files.

The reason is liability surface, not effort. Withholding tax accuracy is a legal exposure
that recurs every time BIR reissues a bracket table; taking it on before the attendance
engine is proven correct means debugging two regulated systems at once. M6's export
format is designed as the payroll module's input so this bolts on rather than refactors.

**Single company.** `Organization` is an internal grouping — legal entities or business
units under one owner — not a tenant boundary. There is no `tenant_id`, no global query
scope, and no row-level isolation beyond the office and reporting-chain scoping described
below. A System Admin genuinely sees everything.

This is the same call POS made and flagged as expensive to reverse. It stays flagged.

## 2. Hierarchy

Three tiers, as explicit foreign keys:

```
organizations  →  offices  →  departments  →  employees
```

**Not a tree.** An adjacency list or nested set would buy arbitrary depth, and would make
every scope check recursive and every index query more expensive — for flexibility nobody
asked for. Explicit FKs mean office scoping is `WHERE office_id = ?`, which is the entire
reason the model is flat.

`organization_id` is denormalized onto `employees` and never joined through for scope
checks.

**`employment_records` is effective-dated.** `is_art82_exempt`, `employment_type`, and
base rate all change mid-career. A promotion to a managerial (Art. 82-exempt) role must
not retroactively strip last month's overtime, so these cannot live as mutable columns on
`employees`.

## 3. Authorization — where we diverge from POS

POS scopes roles per location using `spatie/laravel-permission`'s teams feature, keyed on
`location_id`. Its RBAC doc is explicit about *why that was affordable*:

> Normally spatie's teams feature is awkward, because the app must decide "which team is
> this request about?" and users switch between them. **Our architecture eliminates that
> question.** A register is bound to a location, and the device token identifies the
> register.

**HRIS has no register.** An HR Admin signs in from a browser with no device and no
location binding. Whatever sets `setPermissionsTeamId()` would read it from the user's own
record or, worse, from the request — which is precisely the awkwardness POS's design
removed. And POS's single most expensive documented gotcha is that a stale or absent team
context returns *silently wrong* answers rather than an error.

So HRIS uses spatie **without teams**:

- **Roles are global** and answer one question: *what may this person do?*
  (`leave.approve`, `employee.pii.edit`, `payrule.manage`).
- **`hr_admin_offices` answers the other question:** *over whom?* It is the single source
  of scope truth, and roles never encode scope.
- **`users.is_system_admin` + `Gate::before`** for global oversight. Not a role — POS
  proved a global role assignment cannot exist, because `model_has_roles.<team_key>` is
  part of the primary key and therefore `NOT NULL`.

This mirrors POS's actual lesson (it deleted `user_locations` because a second source of
scope truth disagreed with the first) while inverting which one survives. Here the pivot
is authoritative and roles carry no scope at all, so the two cannot disagree.

### `EmployeeScope`

One service, `app/Domain/Scope/EmployeeScope`, consumed by every policy and every index
endpoint. It returns a **query constraint, not a boolean**:

| Actor | Sees |
| --- | --- |
| Employee | `id = self` |
| Manager | `reports_to_id = self` |
| HR Admin | `office_id IN (hr_admin_offices)` |
| System Admin | everything, via `Gate::before` |

Returning a constraint rather than a boolean is what makes it compose into an index query.
A policy that checks `can()` for the verb but forgets the subject is the bug class this
prevents, and `tests/Arch/` enforces that every index action passes through it.

**Manager scope is direct reports only** — one hop, no recursion. A department head who
genuinely needs wider reach is given HR Admin scope over their office instead. Recursive
chains would require a materialized path plus cycle detection and would make the scope
check the most expensive query in the system; it's in the deferred table with its trigger.

**Refusals are 404, not 403.** "This exists but isn't yours" leaks the org chart, and for
salary and disciplinary records that leak is itself the disclosure.

## 4. The pay engine

### Input resolution

Six layers, each a pure function of `(employee_id, date)`, each overriding the last:

1. **schedule** — `override(employee, date)` → `assignment(employee|department, range)` → office default
2. **day type** — `holiday_calendar(office, date)` → `Ordinary`
3. **rest day** — from the resolved schedule, never a global Sat/Sun assumption
4. **punches** — `attendance_logs`, raw and immutable
5. **corrections** — approved `attendance_adjustments`, read *alongside* #4, never over it
6. **authorizations** — approved leave and approved overtime

Keeping every layer a pure function of employee and date is what makes the engine testable
without fixtures spanning half the schema.

### Output is materialized

One `daily_attendance_summaries` row per `(employee_id, date)`, holding the breakdown in
integer minutes and basis points, plus `rule_version_id` and a status of `pending`,
`computed`, `disputed`, or `locked`.

Computed-on-read was the alternative and loses on three counts, all of which are POS's
"an order line snapshots its price" argument applied to a higher-stakes artifact:

1. **Reproducibility.** `rule_version_id` pins which effective-dated `pay_rules` row
   produced the number. Recomputing in 2029 still yields 2026's answer — which is the
   difference between explaining a payslip to a DOLE inspector and not being able to.
2. **Recompute is explicit.** An approved adjustment enqueues a recompute of exactly the
   affected days. Nothing changes because someone edited a holiday three screens away.
3. **Locking means something.** A closed cutoff sets rows to `locked` and the engine
   refuses to write. A late correction becomes a visible next-period adjustment rather
   than a retroactive edit of paid history.

### Rules are data; floors are code

`pay_rules` rows are effective-dated and admin-editable, because DOLE reissues advisories
and a multiplier change must not require a deploy. The **statutory minimum** is a code-level
constant the engine validates writes against — configuring 100% on a regular holiday is
refused at the boundary, not discovered at payday.

This is POS's config-vs-database rule (*"does someone need to change this without a
deploy?"*) with one addition: some database-owned values still have a code-owned floor,
because the law does.

### Numeric representation

Worked time is **integer minutes**; money is **integer centavos**; multipliers are
**integer basis points**. Decimal hours through IEEE-754 is the same class of bug integer
cents exists to prevent — `7h 20m` is `7.333…`, and a shift is not a number you may round
twice. All composition goes through `Money::fraction()`, so there remains exactly one
place a centavo can be created or destroyed.

### Art. 82

Managerial employees and field personnel are outside Art. 82's coverage: no overtime, no
night differential, no holiday premium, no service incentive leave. `is_art82_exempt` is
therefore consulted by every premium computation, and an arch test asserts no premium is
produced without reading it.

## 5. Requests and approvals

**One state machine, three request types.** Leave, overtime, and attendance adjustments
share `draft → submitted → manager_approved → hr_approved → approved`, with `rejected` and
`cancelled` terminal. A per-type `requires_hr_step` flag decides whether the second step
exists. One set of actions, one card component, one approval queue.

Configurable N-step chains were considered and deferred. They need an
`approval_workflows` / `approval_steps` schema and conditional step evaluation, and the
fixed chain covers the overwhelming majority of Philippine SMEs. The trigger to revisit is
the first client who genuinely routes through Finance.

**Overtime requires pre-authorization.** The engine pays `min(actual_worked, approved)` and
surfaces the remainder as **unpaid excess time** — visible on reports, never silently
converted to money. This is standard PH practice and it closes the leak where lingering at
the office becomes payroll.

**A missing clock-out computes as zero paid hours**, flagged `incomplete`. The employee
files an adjustment stating the actual time out; it routes through the normal chain. Auto-
closing at the scheduled end time was rejected: it pays for time nobody verified, and it
silently conceals people who left early.

**Leave balances are a ledger**, not a mutable number — every credit and debit is a row
with a reason, for the same reason POS made stock a ledger rather than a count.

## 6. Timekeeping

Web self-service and manual HR entry are the v1 sources. A **device-agnostic ingestion
contract** — device registry, per-device token auth, batch payload, idempotency key,
server-side clock-skew correction — is specified from M3 even though no device exists yet,
so adding hardware later is a driver rather than a redesign. This is POS's payment-driver
posture applied to punches.

**Idempotency from the first day.** Punches carry `Idempotency-Key` and the middleware is
ported from POS unchanged, including the property that the key and the work it guards
commit together. A retrying client on a flaky connection is the normal case, and a double
punch is a double day.

**Punches are append-only.** Corrections are new rows. The raw log is what you show an
inspector, so nothing may update or delete it.

## 7. Frontend

**One Next.js app.** POS ships two because its sessions genuinely differ — device-token
auth with a hardware seam versus email/password with no location context. HRIS has no such
split: everyone authenticates identically through Sanctum, and **every admin is also an
employee** who files their own leave. Two builds would mean duplicating the entire
self-service portal into the admin app.

**Design language.** `npx getdesign@latest add ibm` produces the root `DESIGN.md`, which is
the authority; `src/styles/carbon.css` is hand-written from its front-matter and is the one
place tokens enter code. `--radius: 0px` is the brand. Identical pipeline to POS.

**Components, three tiers.** `components/ui/*` primitives and the tier-2 generics
(`DataTable`, `StatusPill`, `EmptyState`, `ConfirmDialog`, `SectionHeader`, `FieldRow`,
`StatCard`, `AppSidebar`) port from POS. Seven domain components earn their place:
`<MonthCalendar>` (the grid behind holiday config, schedule overrides, and the attendance
view — three screens, one component), `<DayCell>`, `<Duration>` (the one place minutes
become human text, mirroring `money.ts`), `<DayTypeTag>`, `<RequestCard>`,
`<EmployeePicker>` (always hits the scoped endpoint, so a manager cannot pick outside their
reports), `<Wizard>`.

POS's rule holds — *if two screens render the same visual pattern, it is a component* — with
the inverse added, because it is how "reusable" libraries sprawl: **if only one screen
renders it, it stays in that page's file.**

**Data layer, five files, no framework.** `lib/api.ts` (unwraps `{data}`, throws typed
`ApiError` from `{error}`), `lib/keys.ts`, `lib/duration.ts`, `lib/date.ts`, and one hook
file per domain.

Five conventions:

1. **Keys come from a factory, never a literal.** Invalidation is a typed prefix, not a
   guessed string. This single rule prevents most stale-cache bugs.
2. **Hooks are thin** — a `useQuery` with a key and a fetcher. No wrapper abstraction over
   React Query. Any hook should be readable in ten seconds.
3. **Optimistic updates only in the approval queue.** Short list, status flip, obvious
   rollback. Elsewhere, invalidate — optimistic writes on schedule assignment would mean
   replicating server-side resolution in the browser, and it would drift.
4. **`useSession()` is the only source of role and scope truth.** Navigation, `<Can>`, and
   route guards all read it.
5. **The server is the authority; client guards are UX.** Hiding a button is convenience —
   the policy already refused it.

**Routes** map 1:1 onto the four backend scopes, so navigation and policies can be diffed
against each other, exactly as POS made `app/Actions/` diffable against `03-api.md`:

```
app/(auth)/login
app/(app)/
  me/      attendance, requests, payslips        every user
  team/    approvals, roster                     session.has_reports
  office/  employees, schedules, holidays,
           cutoffs, adjustments                  session.hr_offices.length
  admin/   org, offices, departments, roles,
           pay-rules, audit                      session.is_system_admin
```

## Non-goals for v1

Gross-to-net payroll and statutory filings. Multi-tenancy. Recursive manager scope.
Per-day shift rostering with swaps. Tenure-tiered leave accrual. A mobile application.
Offline punch capture. Each appears in the roadmap's deferred table with the trigger that
would revive it.

## Resolved after M0

Both questions left open at M0 were settled on 2026-07-23, before M1. Both went the same
way — **configurable per office** — which is a deliberate acceptance of cost, so the cost
is recorded here rather than discovered later.

### Meal breaks — configurable per office

An office elects one of two policies:

- **Assumed** — a fixed unpaid break of N minutes is deducted from any worked span
  exceeding a threshold. A day is two punches.
- **Explicit** — employees punch out and back in for the break, so a normal day is four
  punches and actual break time is measured rather than assumed.

Consequences M1 must absorb:

- **`PunchPairer` pairs arbitrary even punch counts**, not just two. An odd count is
  reported as unpaired rather than guessed at.
- **The deduction is its own pure policy object, not a constant.** It takes the policy and
  its parameters as constructor arguments and never reads config — per the layering rule,
  a value object that calls `config()` stops being unit-testable without a booted
  container.
- **The test matrix roughly doubles**, because every worked-span case has to be proven
  under both policies.

What it buys: an employee who works through lunch is visible and payable under the
explicit policy, which the assumed policy cannot express at all. What it costs: two code
paths through the most-executed function in the engine, forever.

The office column selecting the policy lands in M2; M1 builds both paths as pure
functions with the policy passed in.

### Cutoff cadence — configurable per office

Each office elects semi-monthly (1–15, 16–EOM), monthly, or weekly. `cutoff_periods`
therefore carries a cadence and a generator per type, and every cutoff query is
office-aware.

Semi-monthly remains the seeded default — it is what most PH employers run — but it is a
default, not an assumption baked into the schema. Month-end handling (28/29/30/31) is the
edge case that needs pinning by test regardless of cadence.

This affects M2's migration and M6's period generation. It does not affect M1.
