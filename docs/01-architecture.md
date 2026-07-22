# Architecture

The structural decisions everything else assumes: what runs where, how numbers and time
are represented, how requests are authenticated and made safe to retry, and how the
suite proves any of it.

`00-overview.md` says what we're building and why. This says what it's made of.

## Stack

| Piece | Version | Why it's pinned |
| --- | --- | --- |
| PHP | 8.5 | Matches `../pos`. `composer.json` floors at `^8.3`; the containers and CI both run 8.5. |
| Laravel | 13.21.1 | `composer.json` constrains `^13.8`; the lock resolves 13.21.1. |
| Laravel Sanctum | 4.3.3 | Token auth, M2. Installed at M0 so the dependency is settled. |
| Pest | 4.7.5 (PHPUnit 12.5) | Test runner, including `tests/Arch/`. |
| PostgreSQL | 18 (`postgres:18-alpine`) | `timestamptz`, partial indexes, exclusion constraints, `jsonb`, `SELECT … FOR UPDATE`. |
| Next.js | 16.2.11 | One frontend, not two — see `00-overview.md`. |
| React | 19.2.4 | |
| TypeScript | stable `typescript` for Next, `@typescript/native-preview` (tsgo) for the gate | Next can't drive tsgo; `npm run typecheck` is the real check and Next's own is disabled. |
| FrankenPHP | `dunglas/frankenphp:php8.5` | The API image, dev and (M8) prod. |
| Node | 24 (`node:24-alpine`) | Web container and CI. |

Versions are pinned, not floating. An HRIS computes numbers someone will one day have to
defend; "it worked on the version we had that week" is not a defence.

## Topology

### Development

`make dev` is the front door — `compose.dev.yml`, project name `hris-dev`:

```
db    postgres:18-alpine       host 5433 → 5432    volume pgdata:/var/lib/postgresql
api   FrankenPHP (dev target)  host 8001 → 8000    bind ./backend, volume api_vendor
web   node:24-alpine           host 5176 → 5176    bind ./frontend/web, volume web_node_modules
```

Host ports differ from `../pos` on purpose (POS holds 5432, 8000, 5174/5175), because
both repos live on the same machine and a port collision between two sibling stacks is a
confusing failure, not an obvious one.

The browser talks only to `web`. Next rewrites `/api/*` to `API_ORIGIN` (the `api`
service in containers, `http://127.0.0.1:8001` natively), so the browser sees **one
origin and CORS never comes up**. That is a deliberate architectural choice, not a
convenience: a CORS configuration is a security surface with no upside here.

The native path — any Postgres, `php artisan serve`, `npm run dev` — stays fully
supported. The compose file adds a path; it does not remove one.

### Production

**Deferred to M8.** The intended shape is POS's: a single FrankenPHP edge, host-routed
TLS, the same no-CORS single-origin story preserved end to end, plus backups with a
runnable restore drill. `compose.prod.yml` does not exist yet, and nothing in M0–M7 may
assume it does.

## The numeric rules

These are the invariants in `06-roadmap.md`, stated here as representation decisions.
There is no layer — database, PHP, JSON, TypeScript — where any of them becomes a float.

**Worked time is integer minutes.** Never decimal hours. `7h 20m` is `7.333…`, and a
shift is not a number you may round twice: rounded on the way into a summary and again on
the way into a pay computation, a few seconds a day becomes a payroll discrepancy nobody
can reconstruct. Wire suffix `_minutes`, column type `integer`.

**Money is integer centavos.** `bigint` in Postgres, `int` in PHP, wire suffix `_cents`.
Carried over from POS unchanged, including that `Money` (M1) has **no float
constructor** — not discouraged, *absent*.

**Pay multipliers are integer basis points.** 200% is `20000`. A multiplier is money's
co-conspirator; it does not get to be a float either. Wire suffix `_bp`. This matters
more here than the analogous decision did in POS, because HRIS multipliers *compose*:
holiday overtime at 2am on a rest day is 200% × 130% × 130% × 110%. In floating point the
answer depends on the order of operations. In basis points through a fixed primitive, it
does not.

**`Money::fraction()` is the single rounding primitive.** Every multiplication of an
amount by a rate — a premium, a proration, a per-minute rate applied to a span — goes
through it, rounding half away from zero. One place a centavo can be created or
destroyed. Don't add a second: the moment there are two, the two disagree on a boundary
case and the disagreement is discovered by an employee counting their own payslip.

`Minutes`, `Money`, and `BasisPoints` are built in M1, before any schema, because a bug
in them found in M1 costs an afternoon and the same bug found after a cutoff closes costs
a recomputation of every payslip since.

## The time rules

**Every timestamp is `timestamptz`, stored UTC.** No `timestamp without time zone`
anywhere.

**`APP_TIMEZONE=UTC`, enforced at boot.** `AppServiceProvider::assertConfigured()` throws
a `RuntimeException` if `config('app.timezone')` is anything other than `UTC`, and it runs
from `boot()`, so a misconfigured app does not start. This is not defensive
programming for its own sake: a Laravel app defaulted to `Asia/Manila` writes local times
into `timestamptz` columns and is wrong in a way that only surfaces when a second office
opens in another zone — by which point months of data are already mixed and there is no
column recording which convention each row used. The same method also refuses to boot
without `HRIS_CURRENCY` and `HRIS_ORGANIZATION_NAME`, for the reason in
`04-backend-conventions.md`: a `null` currency fails in ways that look like data
corruption rather than misconfiguration.

`phpunit.xml` forces `APP_TIMEZONE=UTC` for the suite too, so the assertion is exercised
on every run rather than only in a deployed environment.

**Calendar dates on the wire are `YYYY-MM-DD` strings.** Never a `Date` object, never an
ISO timestamp that a client will re-interpret. A punch at 00:30 Asia/Manila belongs to the
30th; a browser in another zone parsing `2026-07-30T00:30:00+08:00` into a local `Date`
can render the 29th, and an attendance calendar that disagrees with the employee's own
memory of the day is not recoverable by explanation.

**The office's timezone is for display.** It lives on `offices` (M2), not in config, not
in the user's browser. Rendering is the only thing it is used for; every stored instant
and every comparison happens in UTC.

Timezone-dependent boundaries — when a day starts for schedule resolution, which cutoff a
punch falls in, whether a 22:00–06:00 night window is entered — are resolved against the
**office's** timezone explicitly, as an input to the computation, never against the
server's default.

## Authentication

**Laravel Sanctum, email and password, landing in M2.** Every user authenticates
identically: there is no device token, no PIN, no second session type. That is the
concrete reason HRIS ships one frontend where POS ships two, and it is also why
`spatie/laravel-permission` runs *without* teams here — the full argument is in
`00-overview.md` and `05-rbac.md`.

Rate limiting on login is part of M2, not an afterthought.

Nothing in M0 is authenticated. `GET /api/v1/health` is public by design: a health check
that requires credentials cannot be used by the thing that needs it most, which is the
container healthcheck at boot.

## Idempotency

**Every mutating request carries an `Idempotency-Key`, from M3.** The middleware is
`EnsureIdempotency`, ported from POS unchanged, and the property that matters is that
**the key and the work it guards commit together, or not at all** — the middleware opens
the transaction, the action's own `DB::transaction()` nests inside it as a savepoint, and
one commit covers both.

Punches are the reason it exists on day one rather than day one hundred. A mobile client
on a flaky connection retrying is the normal case, not the edge case, and a double punch
is a double day: two punch-ins with no intervening punch-out is an unpaired sequence,
which computes as an incomplete day, which becomes a support ticket and an adjustment
request for something that was never wrong.

Only `2xx` responses store a key, so a refusal that might stop being a refusal stays
retryable. The mechanics and the two tests that must exist are in
`04-backend-conventions.md`.

## Concurrency

**`lockForUpdate()` and a version check solve different problems, and both are needed.**

- The **row lock** serializes concurrent writers. Two requests touching the same summary
  take turns instead of interleaving.
- The **version check** rejects a *stale client* — one that read v7 and is acting on
  information v8 invalidated. With only the lock, both writers succeed and the second
  silently clobbers the first, which is the worst outcome available: no error, no audit
  trail, wrong number.

The race that matters most in this system is **approving a request versus closing the
cutoff that contains it** (M6). `ApproveRequest` must `lockForUpdate()` the affected
daily summaries and refuse on a locked period; `CloseCutoff` locks the period row first.
Get it wrong and an approval lands on a period payroll has already consumed — the
recomputed number and the paid number differ, and nothing in the system records that they
ever did.

**That test needs two real connections.** A single-process test passes whether or not
`lockForUpdate()` is even there, because there is no second transaction to be blocked by
it. Such a test is worse than no test: it is a green check mark asserting nothing, and it
will be cited in review as evidence the locking works.

## The error envelope

Success is always `{"data": ...}`. Errors are always `{"error": {code, message, details}}`.
Never both, never a bare array.

One definition: `app/Exceptions/ApiErrorEnvelope.php`, registered from
`bootstrap/app.php`. It renders our `DomainException` hierarchy **and** the framework's
own exceptions — validation, 401, 403, 404, 405, 429 — into the same shape. Mapping only
our own is the failure POS documented: every 404 comes back in Laravel's default shape,
and the promise that a client has exactly one error code path is false from the first
mistyped URL.

`code` is stable and machine-readable; clients branch on it and never on `message`.
`details` is always an object — cast, so empty details serialize `{}` and not `[]`.
Validation failures are **400**, not Laravel's default 422, because `03-api.md` reserves
422 for requests that are structurally valid but semantically rejected.

The browser half of the contract is `frontend/web/src/lib/api.ts`: one `request()` that
unwraps `data`, throws a typed `ApiError` carrying `code`, and turns an unreachable
network into an `ApiError` with code `network_unreachable` rather than an unhandled
rejection. No component unwraps an envelope or reads an HTTP status by hand.

## Testing

**Real Postgres, never SQLite.** `phpunit.xml` is repointed at Postgres deliberately;
Laravel ships it pointing at in-memory SQLite and that is not an oversight to be
restored. We depend on `SELECT … FOR UPDATE`, partial unique indexes, `jsonb`,
`timestamptz`, and exclusion constraints for overlapping effective-dated rows. SQLite
silently lacks all of them — it does not error, it ignores them — so a green SQLite suite
would actively mislead about whether the concurrency and effective-dating invariants
hold. The suite would be fastest exactly when it is least trustworthy.

The suite today is 23 tests: unit and feature coverage of the health action, the boot
assertions, and the error envelope, plus eight architecture tests in
`tests/Arch/ConventionsTest.php` that enforce `04-backend-conventions.md` mechanically —
actions never touch HTTP, actions are final, controllers are final and invokable, the
domain layer is framework-agnostic, no `env()` outside `config/`, no debug helpers,
domain exceptions extend the base, `strict_types` everywhere. If an arch test fails, the
code broke a documented rule; change the code, not the rule.

The frontend gate is `npm test` (vitest), `npm run typecheck` (tsgo), and `npm run build`.
`npm run lint` runs in CI only.

### The PHPUnit environment discovery (M0)

Non-obvious, cost real time, and will bite again the next time a testing value needs to
beat an ambient one.

`phpunit.xml`'s `<env name="X" value="Y" force="true"/>` sets `putenv()` and `$_ENV`. It
does **not** touch `$_SERVER`. Laravel resolves `env()` through vlucas/phpdotenv, whose
adapter list puts `ServerConstAdapter` (`$_SERVER`) first and returns the first
definition it finds. PHP's CLI SAPI pre-populates `$_SERVER` from the real process
environment. Put those three facts together and a `force="true"` entry **still loses** to
an ambient variable of the same name — which is exactly what happens inside the `api`
container, whose `environment:` block in `compose.dev.yml` exports `DB_DATABASE`,
`HRIS_CURRENCY`, `HRIS_ORGANIZATION_NAME` and others for the dev server.

The fix is a mirrored `<server>` block. PHPUnit writes `<server>` entries into `$_SERVER`
unconditionally, so they win where `<env>` alone could not. `backend/phpunit.xml`
therefore carries every testing value twice — once forced in `<env>`, once in `<server>` —
and the duplication is load-bearing, not sloppiness.

**`DB_HOST` and `DB_PORT` are deliberately excluded from both blocks.** They are the only
two values that legitimately differ between the two topologies: `127.0.0.1:5433` natively
(the compose db's published port) and `db:5432` inside the container, where `127.0.0.1` is
the api container itself. Leaving them unforced and out of `<server>` is what lets
`make test-backend`'s `docker compose exec -e DB_HOST=db -e DB_PORT=5432` win for those
two and only those two. Anything else that legitimately varies by topology belongs in the
same exception; anything that does not belongs in both blocks.
