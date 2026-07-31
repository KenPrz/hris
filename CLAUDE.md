# HRIS

A Philippine payroll-adjacent HRIS for one company across several offices: attendance,
schedules, holidays, leave, approvals, and cutoffs — the hours, not the gross-to-net.

**Read `docs/README.md` first.** The design is written down and is the source of truth;
this file only covers how to run things.

## Stack

Laravel 13.21 (PHP 8.5) · PostgreSQL 18 · React 19 + TypeScript on Next.js 16 · Docker Compose · FrankenPHP

`@tanstack/react-query` backs every authenticated screen as of M3.5 — `useSession`,
`useMyAttendance`, and `usePunch` all go through it, keyed by `src/lib/keys.ts`'s factory.
The one exception is the public health page (`src/app/page.tsx`), which still fetches
directly through `src/lib/api.ts` since it predates the query layer and has no session to
cache.

## Layout

```
backend/            Laravel API. Action-class architecture — see docs/04-backend-conventions.md
frontend/web/       Next.js app. One frontend, not two — every admin is also an employee
                    who files their own leave, so a second build would duplicate the
                    entire self-service portal. See docs/00-overview.md.
  src/app/            Routes. (auth)/login is public; (app)/* is guarded by useSession and
                      holds me/attendance today — team/office/admin route groups don't
                      exist until their milestones ship.
  src/components/     ui/ (tier-1 Carbon primitives: Button, TextInput, InlineNotification,
                      Skeleton), domain/ (Duration, DayCell, MonthCalendar), and tier-2
                      generics (AppShell, SideNav, SectionHeader, StatTile, Tag, EmptyState)
                      at the top level.
  src/hooks/          useSession, useMyAttendance, usePunch — one React Query hook per
                      concern, keyed through lib/keys.ts.
  src/lib/            api.ts (the envelope-aware client), session.ts (the only module
                      touching storage), keys.ts (query-key factory), date.ts (string
                      calendar dates), timezone.ts (the one OFFICE_TIME_ZONE constant),
                      money.ts / duration.ts (browser mirrors of the backend's Money/Minutes).
  src/styles/         carbon.css — the only place a DESIGN.md token enters code.
  src/fonts/          Self-hosted IBM Plex Sans (next/font/local), complete build so ₱ renders.
Makefile             Runner surface for the containerized stack — `make help` lists everything.
compose.dev.yml      Full dev stack: db + api + web, hot reload. `make dev`.
docs/                The design. Start at docs/README.md
DESIGN.md            The token authority for the frontend — colors, type scale, spacing,
                    radius. carbon.css is hand-written from its front-matter; nothing else
                    in the frontend should reference a raw hex or literal type step.
```

`compose.prod.yml` is the production stack (M9) — see **Production** below.

## Running it

**`make dev` is the front door.** One Postgres, one API, one frontend, all in
containers, hot-reloading against your working tree — nothing but Docker needed.

```bash
[ -f .env ] || cp .env.example .env   # first time only
make dev-key                          # first time only — mints HRIS_DEV_APP_KEY; paste into .env
make dev                              # db + api + web, hot reload
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
[ -f .env ] || cp .env.example .env
docker compose -f compose.dev.yml up -d db     # postgres:18-alpine on ${HRIS_DEV_DB_PORT:-5433}
```

`backend/.env`'s `DB_PORT` must match `HRIS_DEV_DB_PORT` (both default `5433`) and
`DB_PASSWORD` must match `HRIS_DEV_DB_PASSWORD` (both default `hris`).

**2. API** — <http://127.0.0.1:8001>

```bash
cd backend
# first time only. The braces matter: `a || b && c` groups as `(a || b) && c`, so without
# them key:generate would rewrite APP_KEY every time .env already existed.
[ -f .env ] || { cp .env.example .env && php artisan key:generate; }
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

## Production

`compose.prod.yml` is a single-host stack: **one FrankenPHP container is the whole public
edge.** It terminates TLS and routes by path — `/api/*` served by PHP, everything else
proxied to Next on `web:3000`. One domain, because HRIS has one frontend on purpose.

```bash
cp .env.prod.example .env    # then fill it in — HRIS_* vars, not HRIS_DEV_*
make prod-up                 # builds the prod images and boots db + api + web + rustfs
```

**A fresh database has no login.** `DatabaseSeeder` is dev-only (it pairs the RBAC catalog
with the Manila/Cebu demo company), so production mints its first System Admin explicitly:

```bash
docker compose -f compose.prod.yml exec --user hris api \
  php artisan hris:bootstrap-admin you@example.com
```

That seeds the RBAC catalog and creates one `is_system_admin` user, printing a generated
password **once**. It refuses to run a second time — recovering a lost admin password is a
deliberate, separate act, not something a bootstrap command does quietly.

**The RBAC and profile catalogs seed unconditionally, even on that second run.** The guard
only stops a second superuser from being minted; both `db:seed` calls run first, every
time, so an M10a deploy onto an already-bootstrapped production database still gains
`employee_identification_categories` and `relationships` (idempotent — a no-op if already
seeded). If you deployed M10a onto a production database bootstrapped *before* this fix
landed, run the profile catalog seeder manually once:

```bash
docker compose -f compose.prod.yml exec --user hris api \
  php artisan db:seed --class=Database\\Seeders\\ProfileCatalogSeeder --force
```

| Target | Does |
| --- | --- |
| `make prod-up` / `make prod-down` | Boot / stop the production stack (volumes survive) |
| `make prod-logs` | Tail every service |
| `make build` | Build both production images without booting |
| `make backup` | `pg_dump` the database **and** tar the attachments → `backups/` |
| `make restore-drill` | Restore the newest dump into a throwaway db and count rows |
| `make restore FILE=…` | Restore into the **live** db — destructive, asks first |

`backup`/`restore`/`restore-drill` target the dev stack by default; add `COMPOSE=prod`
for production. `scripts/e2e-prod-boot.sh` proves the whole path — build, boot, TLS,
bootstrap, sign-in, backup, drill, teardown — under its own compose project name so it
can never touch a real stack's volumes.

Things worth knowing before you change any of it:

- **`.dockerignore` is a security boundary, not tidiness.** The prod stage is `COPY . .`;
  without it the host's `backend/.env` — a real `APP_KEY` and database password — is baked
  into an image layer.
- **Next's `/api` rewrite does not run in production.** Caddy splits `/api/*` off before
  Next ever sees it. The browser still sees one origin; the mechanism differs from dev.
- **Required production vars are guarded in `entrypoint.sh`, not with compose `:?`.** A
  required var with no default breaks interpolation for *every* compose command —
  including the `down -v` and `config` you need when something is already wrong.
- **RustFS publishes no ports in production.** Attachments are app-mediated downloads,
  never direct object URLs, so nothing outside the compose network needs to reach it.

## Tests

`make test` runs both suites inside the containers (creating `hris_test` in the compose
db if it's missing). Natively:

```bash
cd backend && ./vendor/bin/pest              # needs Postgres on 127.0.0.1:5433, db hris_test
cd frontend/web && npm test && npm run typecheck && npm run build
```

267 backend tests + 17 arch tests, and 165 frontend tests today.

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
- **Vitest does not read `tsconfig.json`'s `paths`.** The `@/*` → `./src/*` alias has to be
  declared a second time, in `vitest.config.ts`'s own `resolve.alias`, or every component
  test fails on an unresolved import before it runs at all; `setupFiles` (which registers
  `@testing-library/jest-dom`'s matchers) is equally load-bearing. Both are easy to delete
  as "duplicate config" without realizing they're the only thing making the test runner
  agree with the TypeScript compiler about where `@/` points.
- **`DESIGN.md` is the token authority; `frontend/web/src/styles/carbon.css` is the only
  place a token enters code.** Every color, spacing value, and radius in a component reads
  a `var(--*)` from `carbon.css`; a raw hex or literal pixel value in a component is a bug,
  not a shortcut. The CSS `font` shorthand `carbon.css` uses for its `--t-*` type tokens
  cannot express `letter-spacing`, so DESIGN.md's tracking rides alongside as separate
  `--ls-*` companion tokens — set both together or the shorthand silently drops tracking.
- **PHP parses a multipart body only on `POST`.** A `PUT multipart/form-data` arrives with an
  empty `$_FILES` and the uploaded file vanishes with no error — which is why Laravel ships
  `_method` spoofing. M10a's identification save is a `POST` despite being an upsert for exactly
  this reason; the profile and dependents writes stay `PUT` because they carry JSON and no file.
- **`tsgo` does not excess-property-check an object literal returned from `.map()`** unless the
  callback carries an explicit return-type annotation. `rows.map((row): DependentWrite => ({…}))`
  type-checks its keys; `rows.map((row) => ({…}))` assigned to `DependentWrite[]` does not. A
  misspelled wire field (`birthDate` for `birth_date`) then passes both the typechecker and the
  tests, and Laravel coerces the missing key to `null` — silent data loss, not a 400.

## Where things are

- Current milestone and what's next → `docs/06-roadmap.md`
- Why hours-before-payroll, decisions locked, glossary → `docs/00-overview.md`
- Versions, topology, number/time rules, concurrency, testing → `docs/01-architecture.md`
- The action pattern, errors, config-vs-database → `docs/04-backend-conventions.md`
- Schema → `docs/02-data-model.md` (M2)
- Endpoints and error codes → `docs/03-api.md` (M2)
- Roles and permissions → `docs/05-rbac.md` (M2)

## Status

**M0 through M9 complete, plus M10a — employee profiling.** The skeleton boots; the DOLE
premium matrix is a table-driven unit test; schema/auth/office-scoped RBAC are proven by a
four-actor scope matrix; timekeeping ingestion turns a punch into an append-only
`attendance_logs` row; an employee corrects their own attendance through a request a
manager or HR approves; holidays, shift templates, and `pay_rules` are admin-editable per
office; the compute engine turns punches and config into a defensible daily summary;
leave and overtime run through the approval spine; cutoffs lock a period and export
payroll; the admin portal configures a company from empty and shows the audit trail
behind every step; M9 puts all of it behind a single TLS edge with a backup whose
restore has actually been drilled; and M10a gives every employee a personnel file —
contact and personal details, dependents, and government/financial IDs with a scanned copy
of each — that an HR Admin configures per office, an employee reads for themselves, and a
manager sees a redacted view of, at its own HR/manager-reachable route
(`/employees/{id}/profile`) separate from the system-admin-only employee roster.
**854 backend tests (19 of them Arch) + 574 frontend tests.** See `docs/06-roadmap.md` for
each milestone's status and `docs/features.md` for what a user can actually do today.

One caveat worth knowing before you trust the UI: the frontend is covered by component
tests and live API walkthroughs, but **there is still no browser-level e2e harness** —
every `scripts/e2e-*.sh` drives the API (and, for M9, the booted production stack), not a
rendered page. M3.5's screens were never visually confirmed in a real browser, and M10a's
`/me/profile` and `/employees/{id}/profile` carry the identical gap — nothing since M3.5
has closed it. Load it yourself before assuming it looks right; see the M3.5 and M10a
status blocks in `docs/06-roadmap.md`.

Next: no milestone is open. **M10b — document management** (a `Document`/`DocumentBucket`/
`DocumentCategory` module and a polymorphic file table) was deliberately split out of
M10a's brainstorm rather than built alongside it, and is the nearest open follow-on —
`docs/06-roadmap.md`'s M10a section has the detail. Beyond that, `docs/06-roadmap.md`'s
**Deferred** table lists what is waiting and, for each item, the trigger that revives it —
gross-to-net payroll, biometric device ingestion, a mobile app with GPS geofence, rotating
rosters, tenure-based accrual, recursive manager scope, and multi-tenancy.
