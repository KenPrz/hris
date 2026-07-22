# HRIS

A Human Resource Information System for a single Philippine company operating across
several offices.

## The five-line version

It owns the path from a raw attendance punch to a defensible, Labor-Code-correct
statement of what a day is worth — schedule resolution, holiday overlay, premium
multipliers, approvals, cutoffs — and stops before gross-to-net payroll. Laravel 13 +
PostgreSQL 18 + Next.js 16. Worked time is integer minutes, money is integer centavos,
multipliers are integer basis points, punches are append-only, and a closed period is
immutable.

## Running it

```bash
cp -n .env.example .env        # first time only
make dev-key                   # first time only — paste the value into .env
make dev                       # db + api + web, hot reload
```

<http://127.0.0.1:5176> should say **System healthy** and print the Postgres version.
<http://127.0.0.1:8001/api/v1/health> is the API behind it. `make help` lists every
target; `make test` runs both suites in the containers.

The native path (any Postgres, `php artisan serve`, `npm run dev`) is fully supported and
documented in `CLAUDE.md`, along with the conventions that will bite you if you skip them.

## The docs

The design is written down and is the source of truth. Start at
[`docs/README.md`](docs/README.md), which gives the reading order.

| | |
| --- | --- |
| [`docs/00-overview.md`](docs/00-overview.md) | What we're building, why hours before payroll, decisions locked, glossary |
| [`docs/01-architecture.md`](docs/01-architecture.md) | Versions, topology, the number and time rules, concurrency, testing |
| [`docs/04-backend-conventions.md`](docs/04-backend-conventions.md) | The action pattern, errors, config versus database |
| [`docs/06-roadmap.md`](docs/06-roadmap.md) | M0–M8, the invariants, and what's deferred |

`CLAUDE.md` covers how to run and work on it. Status: **M0 complete**.
