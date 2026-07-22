# HRIS

A Philippine payroll-adjacent HRIS for one company across several offices: attendance,
schedules, holidays, leave, approvals, and cutoffs — the hours, not the gross-to-net.

**Read `docs/README.md` first.** The design is written down and is the source of truth;
this file only covers how to run things.

## Stack

Laravel 13.21 (PHP 8.5) · PostgreSQL 18 · React 19 + TypeScript on Next.js 16 · Docker Compose · FrankenPHP

`@tanstack/react-query` is installed but not yet used — the data layer lands in M3. The
health page fetches directly through `src/lib/api.ts`.

## Layout

```
backend/          Laravel API. Action-class architecture — see docs/04-backend-conventions.md
frontend/web/     Next.js app. One frontend, not two — every admin is also an employee
                  who files their own leave, so a second build would duplicate the
                  entire self-service portal. See docs/00-overview.md.
Makefile          Runner surface for the containerized stack — `make help` lists everything.
compose.dev.yml   Full dev stack: db + api + web, hot reload. `make dev`.
docs/             The design. Start at docs/README.md
```

Production compose lands in M8; there is no `compose.prod.yml` yet.

## Running it

**`make dev` is the front door.** One Postgres, one API, one frontend, all in
containers, hot-reloading against your working tree — nothing but Docker needed.

```bash
cp -n .env.example .env        # first time only
make dev-key                   # first time only — mints HRIS_DEV_APP_KEY; paste into .env
make dev                       # db + api + web, hot reload
```

First boot installs `vendor/` and `node_modules` into fresh named volumes; it takes
minutes, and `make dev` says so.

<http://127.0.0.1:8001/api/v1/health> (API) · <http://127.0.0.1:5176> (web). Host ports
differ from `../pos` on purpose — that stack holds 5432/8000/5174/5175 on this machine.

| Target | Does |
| --- | --- |
| `make dev` / `make dev-down` | Bring the dev stack up / down (volumes survive `dev-down`) |
| `make dev-key` | Mint an `APP_KEY` for `.env` |
| `make test` | Both suites, in containers (`test-backend` / `test-web` individually) |
| `make clean` | Stack down **and volumes destroyed** — asks first |

Full recipes: `Makefile`. Compose file: `compose.dev.yml`.

### Native path (no Docker for the app)

Still fully supported — the dev compose adds a path, it doesn't remove one.

**1. Postgres.** Run just the `db` service, or point `backend/.env` at any Postgres 18:

```bash
cp -n .env.example .env
docker compose -f compose.dev.yml up -d db     # postgres:18-alpine on ${HRIS_DEV_DB_PORT:-5433}
```

`backend/.env`'s `DB_PORT` must match `HRIS_DEV_DB_PORT` (both default `5433`) and
`DB_PASSWORD` must match `HRIS_DEV_DB_PASSWORD` (both default `hris`).

**2. API** — <http://127.0.0.1:8001>

```bash
cd backend
cp -n .env.example .env && php artisan key:generate   # first time only
composer install
php artisan serve --port=8001
```

**3. Web** — <http://127.0.0.1:5176>

```bash
cd frontend/web
npm install
npm run dev
```

Next rewrites `/api` to the API, so the browser sees one origin and CORS never comes up.

Check it works: <http://127.0.0.1:5176> should say **System healthy** and print the
Postgres version.

## Tests

`make test` runs both suites inside the containers (creating `hris_test` in the compose
db if it's missing). Natively:

```bash
cd backend && ./vendor/bin/pest              # needs Postgres on 127.0.0.1:5433, db hris_test
cd frontend/web && npm test && npm run typecheck && npm run build
```

23 backend tests (15 feature/unit + 8 arch) and 3 frontend tests today.

The test database is created once:

```bash
docker compose -f compose.dev.yml exec db psql -U hris -d hris -c "create database hris_test owner hris;"
# (make test-backend creates it automatically; the manual line is for native pest runs)
```

**Tests run against real Postgres, never SQLite.** We depend on `SELECT … FOR UPDATE`,
partial unique indexes, `jsonb`, `timestamptz`, and exclusion constraints; SQLite silently
lacks all of them, so a green SQLite suite would actively mislead about whether our
concurrency invariants hold. `phpunit.xml` is configured this way deliberately — don't
"fix" it back.

`tests/Arch/` enforces `docs/04-backend-conventions.md` mechanically (actions never touch
HTTP, actions are final, controllers are final and invokable, the domain layer is
framework-agnostic, no `env()` outside config, no debug helpers, strict types). If one
fails, the code broke a documented rule — change the code, not the rule.

CI (`.github/workflows/ci.yml`) runs the same commands plus `npm run lint`, which
`make test-web` deliberately does not, so a lint failure never blocks a local test run
mid-change.

## Conventions that will bite you if you skip them

Full reasoning lives in the docs; these are the ones that cause real damage.

- **PHP 8.5, Laravel 13, PostgreSQL 18, Next.js 16, React 19 — pinned.** Don't upgrade
  inside a milestone; a version bump and a behaviour change arriving in the same PR are
  indistinguishable. → `docs/01-architecture.md`
- **`declare(strict_types=1);` at the top of every PHP file** in `app/` and `tests/`.
  Without it a string silently becomes an int, which in a system built on integer minutes
  is a wrong number rather than an error. An arch test enforces it.
- **Never call `env()` outside `config/`.** `php artisan config:cache` in production makes
  every other `env()` call return `null` — silently. A `null` currency fails in ways that
  look like data corruption, not misconfiguration. An arch test enforces it.
  → `docs/04-backend-conventions.md`
- **`APP_TIMEZONE=UTC`, always.** Display timezone belongs on `offices` (M2). A Laravel
  app defaulted to `Asia/Manila` writes local times into `timestamptz` and is wrong the
  moment a second office opens in another zone. `AppServiceProvider::assertConfigured()`
  refuses to boot otherwise. → `docs/01-architecture.md`
- **Worked time is integer minutes; money is integer centavos; multipliers are integer
  basis points.** Never a float, in any layer, ever. `7h 20m` is `7.333…`, and a shift is
  not a number you may round twice. All rounding goes through `Money::fraction()` (M1) —
  one place a centavo can be created or destroyed. → `docs/01-architecture.md`
- **Calendar dates on the wire are `YYYY-MM-DD` strings**, never `Date` objects. A punch
  at 00:30 Asia/Manila belongs to the 30th, and a browser in another zone must not be able
  to disagree. → `docs/01-architecture.md`
- **Tests run against real PostgreSQL, never SQLite.** See above; `phpunit.xml` is that
  way on purpose.
- **Success is always `{"data": ...}`; errors are always `{"error": ...}`.** Never both,
  never a bare array. One definition: `app/Exceptions/ApiErrorEnvelope.php`, and it is
  **closed, not enumerated** — under `api/*` every HTTP exception, and outside debug every
  uncaught throwable, lands in the envelope. → `docs/04-backend-conventions.md`
- **One system action = one route = one controller = one Action class.** Actions take an
  Input DTO, return a domain object, and know nothing about HTTP. Serialization is the
  controller's job. → `docs/04-backend-conventions.md`
- **Config is what engineers deploy; the database is what admins change at runtime.**
  Never both — with one HRIS addition: a database-owned rate may still have a code-owned
  statutory *floor*, because the Labor Code sets one. → `docs/04-backend-conventions.md`
- **Punches are append-only and a locked period is immutable.** A correction is a new row
  read alongside the original, never over it. The raw log is what you show an inspector.
  → `docs/00-overview.md`
- **Every premium computation reads `is_art82_exempt` first.** Managerial employees and
  field personnel get no overtime, no night differential, no holiday premium, no SIL.
  → `docs/00-overview.md`
- **Env prefix is `HRIS_`; the database/user/name is `hris`; the compose project is
  `hris-dev`.** Host ports deliberately differ from `../pos`: db 5433, api 8001, web 5176.
- **Commit messages carry no attribution trailers.** No `Co-Authored-By`, no
  `Generated with`, no session URL. Message body only.

### Gotchas that will cost you an afternoon

Found while building M0. Each one cost real time.

- **`postgres:18` moved the recommended mount to `/var/lib/postgresql`, not
  `.../data`.** Mounting the old path makes the container restart-loop on first boot with
  a message that does not say so.
- **Laravel's `phpunit.xml` ships pointed at in-memory SQLite.** Ours is repointed at real
  Postgres deliberately. It is not an oversight waiting to be tidied up.
- **PHPUnit's `<env force="true">` still loses to an ambient variable.** It only writes
  `putenv()`/`$_ENV`, but Laravel resolves `env()` through phpdotenv's `ServerConstAdapter`
  — `$_SERVER` first, first definition wins — and PHP's CLI SAPI pre-populates `$_SERVER`
  from the real process environment. A testing value only beats the `api` container's own
  `environment:` block if it is *also* in `phpunit.xml`'s mirrored `<server>` block. The
  duplication is load-bearing. `DB_HOST`/`DB_PORT` are excluded from both blocks on
  purpose — they are the only values that legitimately differ between the native
  (`127.0.0.1:5433`) and containerized (`db:5432`) topologies, so `make test-backend`'s
  `exec -e` overrides are meant to win for those two alone.
- **Framework exceptions need explicit envelope mapping.** Handling only `DomainException`
  leaves Laravel's default shape leaking through for every 404, 405, and validation
  failure, which breaks the one-code-path promise before the API is a day old.
- **`erasableSyntaxOnly` in the Next tsconfig forbids constructor parameter properties.**
  `ApiError` declares its fields explicitly because of it; the compiler error does not
  mention the tsconfig flag.
- **`docker compose exec` defaults to root.** Against a bind mount that leaves root-owned
  files the host user cannot write. Every Makefile `exec` passes `--user`; a hand-run one
  needs to as well.

## Where things are

- Current milestone and what's next → `docs/06-roadmap.md`
- Why hours-before-payroll, decisions locked, glossary → `docs/00-overview.md`
- Versions, topology, number/time rules, concurrency, testing → `docs/01-architecture.md`
- The action pattern, errors, config-vs-database → `docs/04-backend-conventions.md`
- Schema → `docs/02-data-model.md` (M2)
- Endpoints and error codes → `docs/03-api.md` (M2)
- Roles and permissions → `docs/05-rbac.md` (M2)

## Status

**M0 complete** — skeleton boots end to end. `GET /api/v1/health` is built as a real
action (controller → action → resource) so the first endpoint sets the shape every later
one copies; the error envelope covers our exceptions and the framework's; `tests/Arch/`
enforces the conventions from day one; `make dev` brings up db + api + web and
<http://127.0.0.1:5176> says **System healthy** with the Postgres version.

Next: **M1 — time and pay primitives.** `Minutes`, `Money`, `BasisPoints`, `DayType`,
`PayMultiplier`, `NightDiffSplitter`, `PunchPairer`, `OvertimeThreshold`, and the whole
DOLE premium matrix as a table-driven unit test with zero database. See
`docs/06-roadmap.md`.
