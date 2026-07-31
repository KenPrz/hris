# HRIS — Design Docs

Design-first. Read in order; each assumes the one before it.

| Doc | What's in it |
| --- | --- |
| [00-overview.md](00-overview.md) | What we're building, why hours-before-payroll, principles, v1 decisions, non-goals, glossary. |
| [01-architecture.md](01-architecture.md) | Stack and versions, topology, time and money rules, timezone handling, auth, idempotency, concurrency, error format, testing. |
| [02-data-model.md](02-data-model.md) | Full Postgres schema with rationale. The core artifact. |
| [03-api.md](03-api.md) | REST surface, auth flows, the `/me` session envelope, error codes. |
| [04-backend-conventions.md](04-backend-conventions.md) | Action-class architecture: controller → request → action → resource. Rules, layering, worked example, configuration. |
| [05-rbac.md](05-rbac.md) | `spatie/laravel-permission` without teams: global roles, the `hr_admin_offices` scope pivot, `EmployeeScope`, policies. |
| [06-roadmap.md](06-roadmap.md) | Milestones M0–M9 and M10a, the invariants they're measured against, and the deferred table. |

Written as each milestone reaches it. `02`, `03`, and `05` arrived with M2; M3 extended
`02` (the `attendance_logs` ledger and `idempotency_keys`), `03` (the punch and read
endpoints), and `06` (M3's status). M3.6 extended `02` (the `requests` spine,
`attendance_adjustment_details`, `attendance_annulments`, the effective-ledger definition,
and the Media Library `media` table), `03` (the adjustment submit/decide/read endpoints),
`06` (M3.6's status), and `features.md` (filing and approving a correction). M3.5 built
the frontend itself against that API — no schema or endpoint changes, so `02` and `03`
are untouched — and extended `06` (M3.5's status) and `features.md` (signing in from a
browser, the clock-in/out screen, the month ledger). All seven docs exist today.

## Design records

| Spec | Covers |
| --- | --- |
| [superpowers/specs/2026-07-23-hris-foundation-design.md](superpowers/specs/2026-07-23-hris-foundation-design.md) | The decisions v1 is built on, and where HRIS deliberately diverges from POS. |
| [superpowers/specs/2026-07-24-m3-timekeeping-ingestion-design.md](superpowers/specs/2026-07-24-m3-timekeeping-ingestion-design.md) | M3: turning a punch into an append-only ledger row. |
| [superpowers/specs/2026-07-24-attendance-adjustments-design.md](superpowers/specs/2026-07-24-attendance-adjustments-design.md) | M3.6: the shared `requests` spine, the annulment model, and the effective ledger. |
| [superpowers/specs/2026-07-29-m9-containerization-production-design.md](superpowers/specs/2026-07-29-m9-containerization-production-design.md) | M9: the production stack, the single TLS edge, backups, and the first login on an empty database. |
| [superpowers/specs/2026-07-30-m10a-employee-profiling-design.md](superpowers/specs/2026-07-30-m10a-employee-profiling-design.md) | M10a: the personnel file — profile-as-side-table, IDs as catalog rows against a category table, and why designation/labor type/region live off the profile. |

`docs/superpowers/specs/` holds one of these per milestone from M3 onward; the table above
names only the ones that define structure the whole system inherits.

## The five-line version

An HRIS for one Philippine company across several offices. It owns the path from a raw
attendance punch to a defensible, Labor-Code-correct statement of what a day is worth —
schedule resolution, holiday overlay, premium multipliers, approvals — and stops before
gross-to-net payroll. Laravel 13 + Postgres 18 + Next.js 16. Worked time is integer
minutes, money is integer centavos, multipliers are integer basis points, punches are
append-only, and a closed period is immutable.

## Conventions inherited from POS

This codebase is a sibling of `../pos` and does not invent a second house style. The
action pattern, error envelope, config-versus-database rule, real-Postgres testing, and
`tests/Arch/` enforcement all carry over unchanged.

Two things deliberately differ, both argued in the foundation spec:

- **spatie runs without teams.** POS's per-location teams were affordable because a device
  token made the team context unambiguous. There is no device here, so scope lives in an
  `hr_admin_offices` pivot and roles carry none.
- **One frontend, not two.** POS split because its two sessions genuinely differed. Every
  HRIS user authenticates identically, and every admin is also an employee.

## Next step

**M0 through M9 are complete, and M10a — employee profiling — has shipped on top of the
finished roadmap.** The system does the whole path it set out to own: a punch lands in an
append-only, forensically intact `attendance_logs` row; an employee corrects their own
attendance through a request a manager or HR approves, the correction superseding the
ledger via an append-only annulment rather than ever editing a punch; holidays, shift
templates, and `pay_rules` are admin-editable per office; the compute engine resolves a
schedule, overlays the holiday calendar, applies the DOLE premium matrix behind
`is_art82_exempt`, and writes a daily summary; leave and overtime run through the shared
approval spine; a cutoff locks a period and exports payroll; the admin portal configures a
company from an empty database and the activity log shows every step; M9 puts all of it
behind a single TLS edge, with a first-login command for an empty database and a backup
whose restore has actually been drilled; and M10a adds the personnel file — contact and
personal details, dependents, and government/financial IDs with a scanned copy of each —
that an HR Admin configures per office, an employee reads for themselves, and a manager
sees a redacted view of, at its own HR/manager-reachable route (`/employees/{id}/profile`)
separate from the system-admin-only employee roster. **865 backend tests (20 of them Arch)
+ 577 frontend tests**, plus a `scripts/e2e-*.sh` per milestone that walks its flow against
a live stack.

No milestone is open. **M10b — a document management module (a `Document`/`DocumentBucket`/
`DocumentCategory` catalog and a polymorphic file table) — was deliberately split out of
M10a's design and is not built**; it is the nearest open follow-on. Beyond that,
[06-roadmap.md](06-roadmap.md)'s **Deferred** table is the list of what comes next and what
would revive each item — gross-to-net payroll, biometric device ingestion, a mobile app
with GPS geofence, rotating rosters, tenure-based leave accrual, recursive manager scope,
and multi-tenancy (the one flagged expensive-to-change: revisit early or not at all).

The one honest gap outside that table: **there is no browser-level e2e harness.** Every
`e2e-*.sh` drives the API or the booted stack, never a rendered page, and M3.5's screens —
and M10a's `/me/profile` and `/employees/{id}/profile` — have never been visually
confirmed in a real browser.
