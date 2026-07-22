# HRIS — Design Docs

Design-first. Read in order; each assumes the one before it.

| Doc | What's in it |
| --- | --- |
| [00-overview.md](00-overview.md) | What we're building, why hours-before-payroll, principles, v1 decisions, non-goals, glossary. |
| [01-architecture.md](01-architecture.md) | Stack and versions, topology, time and money rules, timezone handling, auth, idempotency, concurrency, error format, testing. |
| 02-data-model.md | Full Postgres schema with rationale. The core artifact. *(M2)* |
| 03-api.md | REST surface, auth flows, request lifecycle, the device ingestion contract, error codes. *(M2)* |
| [04-backend-conventions.md](04-backend-conventions.md) | Action-class architecture: controller → request → action → resource. Rules, layering, worked example, configuration. |
| 05-rbac.md | `spatie/laravel-permission` without teams: global roles, the `hr_admin_offices` scope pivot, `EmployeeScope`, policies. *(M2)* |
| [06-roadmap.md](06-roadmap.md) | Milestones M0–M8, the invariants they're measured against, and the deferred table. |

Written as each milestone reaches it — the marker says which. The three unmarked docs
above plus `06-roadmap.md` exist today; `02`, `03`, and `05` arrive with M2.

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

M0 is complete — the skeleton boots, `/api/v1/health` is a real action, and CI runs the
gates. Next is M1 in [06-roadmap.md](06-roadmap.md): the time and pay primitives, built
before any schema, with the whole DOLE premium matrix as a table-driven unit test and
zero database.
