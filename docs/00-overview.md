# HRIS — Overview and Scope

## What this is

A Human Resource Information System for a single Philippine company operating across
multiple offices. It owns the path from a raw attendance punch to a defensible,
Labor-Code-correct statement of what an employee is owed for a day — schedule resolution,
holiday overlay, premium multipliers, approvals, cutoffs — and stops there.

Gross-to-net payroll is deliberately out of scope for v1.

## The central idea: hours before payroll

Payroll is the visible product. It is also the wrong thing to build first.

Every gross-to-net calculation is a multiplication of two things: a **number of premium
minutes** and a **rate**. The statutory half — SSS contribution brackets, PhilHealth's
5%, Pag-IBIG, TRAIN withholding, 13th month, de minimis ceilings — is well-documented,
changes on a published schedule, and is the part a company can and usually does buy from
an outside provider. The other half, *how many minutes at which multiplier*, is specific
to this company's schedules, offices, holiday proclamations, and approval culture, and
nobody sells it.

So the split is by liability, not by effort:

- **Withholding tax accuracy is a legal exposure that recurs** every time BIR reissues a
  bracket table. Taking it on before the attendance engine is proven correct means
  debugging two regulated systems at once, with each one's bugs looking like the other's.
- **A wrong premium multiplier applied to a closed period is not a bug report.** It is
  back-pay, a recomputation of every payslip since the mistake, and a conversation with
  DOLE. That is the expensive failure, so that is what gets built first and proven with
  pure unit tests before a single table exists.

M6's payroll export is designed as the payroll module's *input* — per employee, per
period, the full earnings breakdown in integer minutes and basis points, each line
carrying the `rule_version_id` that produced it. Adding gross-to-net later bolts on; it
does not refactor.

## Principles

1. **Worked time is integer minutes.** Never decimal hours, in any layer, ever. Wire
   suffix `_minutes`. See `01-architecture.md`.
2. **Money is integer centavos and multipliers are integer basis points.** 200% is
   `20000`. All rounding goes through `Money::fraction()`, so there is exactly one place a
   centavo can be created or destroyed.
3. **Punches are append-only.** A correction is a new row in `attendance_adjustments`,
   read *alongside* the original and never over it. The raw log is the thing you show an
   inspector, so nothing may update or delete it.
4. **A locked period is immutable.** Once a cutoff closes, the engine refuses to write. A
   late correction becomes a visible adjustment in the next period, never a silent edit
   to paid history.
5. **Computed days are materialized, with the rule version that produced them.**
   Recomputing a 2026 day in 2029 must still yield 2026's answer — that is the difference
   between explaining a payslip to an inspector and not being able to.
6. **Art. 82 gates every premium.** Managerial employees and field personnel get no
   overtime, no night differential, no holiday premium, and no SIL. Any computation that
   produces a premium without reading `is_art82_exempt` is a bug, and `tests/Arch/` says so.
7. **Statutory floors live in code; rates live in the database.** Admins change
   multipliers without a deploy, and cannot configure below the Labor Code minimum — the
   write is refused at the boundary rather than discovered at payday.
8. **The server owns the truth.** Client-side guards are UX. The policy already refused it.

## Decisions locked for v1

Chosen deliberately; the reasoning matters as much as the choice, and the reversibility
column is what says whether a decision can be revisited casually or not at all. The full
argument for each is in
[`superpowers/specs/2026-07-23-hris-foundation-design.md`](superpowers/specs/2026-07-23-hris-foundation-design.md).

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

Three of these carry most of the weight:

**Single company.** `Organization` is an internal grouping — legal entities or business
units under one owner — not a tenant boundary. There is no `tenant_id`, no global query
scope, and no row-level isolation beyond office and reporting-chain scoping. A System
Admin genuinely sees everything. This is the same call POS made and flagged as expensive
to reverse, and it stays flagged: selling this to a second company is a migration, not a
feature.

**Three tiers as explicit FKs**, `organizations → offices → departments → employees`. Not
a tree. An adjacency list would buy arbitrary depth and make every scope check recursive
and every index query more expensive, for flexibility nobody asked for. Explicit FKs mean
office scoping is `WHERE office_id = ?`, which is the entire reason the model is flat.

**spatie without teams**, which is where HRIS diverges from POS most sharply. POS scopes
roles per location using spatie's teams feature, and its RBAC doc is explicit that this
was affordable *because a device token made the team context unambiguous*. There is no
device here — an HR Admin signs in from a browser — so whatever set the team context
would read it from the user's own record or from the request, which is precisely the
awkwardness POS's design eliminated. So roles are global and answer only *what may this
person do*; an `hr_admin_offices` pivot answers *over whom* and is the single source of
scope truth. `users.is_system_admin` + `Gate::before` handles global oversight, and is not
a role because a global role assignment cannot exist — `model_has_roles.<team_key>` is
part of the primary key and therefore `NOT NULL`.

Two consequences worth naming here rather than only in `05-rbac.md`:

- **Scope is a query constraint, not a boolean.** `EmployeeScope` returns something that
  composes into an index query, so a policy that checks the verb but forgets the subject
  is structurally impossible rather than merely discouraged.
- **Refusals are 404, not 403.** "This exists but isn't yours" leaks the org chart, and
  for salary and disciplinary records that leak is itself the disclosure.

## Non-goals for v1

Named explicitly so they don't creep in. Each appears in `06-roadmap.md`'s deferred table
with the trigger that would revive it.

- Gross-to-net payroll, payslips, and statutory filings (SSS, PhilHealth, Pag-IBIG, BIR).
- Multi-tenancy.
- Biometric or any hardware timekeeping device. (The ingestion contract is specified from
  M3 so adding one is a driver, not a redesign. The device itself is not built.)
- A mobile application, GPS geofencing, and offline punch capture.
- Recursive manager scope. Direct reports only, one hop.
- Per-day shift rostering with swap requests.
- Tenure-tiered leave accrual.
- Recruitment, performance management, training, and asset tracking. This is an
  attendance-and-pay system, not an HR suite.

## Glossary

Terms are used in this exact sense throughout the docs and the code.

- **Art. 82** — the article of the Labor Code defining who its working-conditions
  provisions cover. Managerial employees, field personnel, family members of the
  employer, domestic helpers, and workers paid by results are **outside** its coverage,
  and therefore earn no overtime, night differential, holiday premium, or SIL. The two
  categories that matter for us are managerial employees and field personnel.
- **`is_art82_exempt`** — the boolean on `employment_records` recording that an employee
  falls outside Art. 82. It lives on the effective-dated record, not on `employees`,
  because a promotion into a managerial role must not retroactively strip last month's
  overtime. Every premium computation reads it first.
- **SIL — Service Incentive Leave** — five days of paid leave per year, earned after one
  year of service (Art. 95). Convertible to cash if unused. Art. 82-exempt employees do
  not accrue it.
- **Regular holiday** — a holiday listed in the Labor Code or proclaimed as such (New
  Year's Day, Araw ng Kagitingan, Independence Day, Christmas Day, and the rest). Paid at
  100% even when unworked; 200% when worked. The "no work, no pay" default does not apply.
- **Special non-working day** — a proclaimed day off that is *not* a regular holiday
  (Ninoy Aquino Day, All Saints' Day, and others). **No work, no pay**: unworked, it pays
  nothing; worked, it pays 130%.
- **Special working day** — a proclaimed day that is explicitly declared to be treated as
  an ordinary working day. Pays 100%, no premium. It exists as a distinct type precisely
  so it is never confused with a special non-working day; the names differ by one word and
  the pay differs by 30%.
- **Night differential** — the additional 10% owed for work between **22:00 and 06:00**
  (Art. 86). It **compounds on the already-premium rate**, not on base pay: holiday
  overtime at 2am is 200% × 130% × 110%. Getting this wrong underpays quietly for years.
- **Rest day** — the employee's scheduled non-working day, resolved from their schedule.
  Never a global Saturday/Sunday assumption; a night-shift or compressed-week employee
  rests on other days, and premium pay turns on it.
- **Cutoff** — the pay period an office runs. Semi-monthly by default (1–15, 16–EOM),
  which is what most PH employers run. Closing one locks every daily summary inside it.
- **Basis point** — one hundredth of a percent, used as the integer representation of a
  multiplier. 100% is `10000`, 130% is `13000`, 200% is `20000`. Wire suffix `_bp`.
- **Integer minutes** — the only representation of worked time. `7h 20m` is `440`, never
  `7.333`. Wire suffix `_minutes`.
- **Daily summary** — one materialized `daily_attendance_summaries` row per
  `(employee, date)`, holding the full breakdown plus the `rule_version_id` that produced
  it and a status of `pending`, `computed`, `disputed`, or `locked`.
- **Adjustment** — an employee-filed correction to attendance, routed through the normal
  approval chain. It creates a new row the engine reads alongside the raw punches; it
  never edits them.
- **Office** — a physical workplace. Owns its timezone, holiday calendar, IP allowlist,
  geofence, and cutoff periods. The unit of HR Admin scope.
