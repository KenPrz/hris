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
| [06-roadmap.md](06-roadmap.md) | Milestones M0–M8, the invariants they're measured against, and the deferred table. |

Written as each milestone reaches it. `02`, `03`, and `05` arrived with M2; M3 extended
`02` (the `attendance_logs` ledger and `idempotency_keys`), `03` (the punch and read
endpoints), and `06` (M3's status). All seven docs exist today.

## Design records

| Spec | Covers |
| --- | --- |
| [superpowers/specs/2026-07-23-hris-foundation-design.md](superpowers/specs/2026-07-23-hris-foundation-design.md) | The decisions v1 is built on, and where HRIS deliberately diverges from POS. |

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

M0 through M3 are complete — the skeleton boots, the DOLE premium matrix is a green
table-driven unit test, the schema/auth/office-scoped RBAC are proven by the four-actor
scope matrix, and timekeeping **ingestion** now turns a punch into an append-only,
forensically intact `attendance_logs` row: self-service and manual-HR punch endpoints,
idempotency ported from POS, flag-not-reject verification, and a raw month read grouped by
office-local date — with the ledger provably append-only (`02-data-model.md`, `03-api.md`).
`migrate:fresh --seed` produces a Manila/Cebu company you can log into as each of the four
scopes, and `scripts/e2e-timekeeping.sh` walks the punch path end to end. Next is **M3.5**
in [06-roadmap.md](06-roadmap.md): the frontend foundation — the IBM/Carbon design language,
the auth UI, and the punch and attendance screens, built against M3's real API.
