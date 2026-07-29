# M9 — Containerization and production (design)

**Status:** decisions made autonomously (no brainstorm partner in this session), pending review
**Milestone:** M9 — the last one. Closes the roadmap.
**Depends on:** M0 (`compose.dev.yml`, `backend/Dockerfile`'s `dev` stage, `docker/entrypoint.sh`,
`Caddyfile.dev`), M3.6 (RustFS-backed attachments), M8 (the admin portal a bootstrap admin drives).

## Goal

`make prod-up` boots the whole system on one host behind TLS, and `make restore-drill` proves the
backup is not a rumor. Everything M0–M8 built has run only under `compose.dev.yml`: bind mounts,
hot reload, `APP_DEBUG=true`, a throwaway password, and no TLS anywhere. M9 is the milestone where
this becomes a thing you can hand to a company.

## Decisions

1. **Port `../pos`'s production topology; do not invent a second one.** That stack —
   `compose.prod.yml`, a single FrankenPHP edge terminating TLS and routing by host, multi-stage
   prod images, `make backup`/`restore`/`restore-drill` — is already built, already argued, and
   already survived a real deployment. `docs/README.md` says this codebase "does not invent a
   second house style"; that applies to infrastructure too. HRIS diverges in exactly three
   places, each below.

2. **One domain, not two.** POS split `POS_REGISTER_DOMAIN`/`POS_ADMIN_DOMAIN` because it has two
   frontends. HRIS has one, deliberately (`00-overview.md`: every admin is also an employee), so
   there is one `HRIS_DOMAIN`. Caddy routes `/api/*` to `php_server` and everything else to
   `reverse_proxy web:3000`.

   Note what this changes about the frontend: **Next's own `/api` rewrite is bypassed in
   production.** That rewrite exists for the dev topology, where the browser talks to Next on
   5176 and Next forwards to the API. In production Caddy is the only thing the browser talks to,
   and it splits `/api/*` off before Next ever sees it. The browser still sees exactly one origin
   and CORS still never comes up — by a different mechanism, which is worth knowing before
   someone "fixes" the now-unused rewrite.

3. **RustFS joins the production stack, with no published ports.** Attachments are a shipped
   feature (M3.6: an adjustment request carries evidence), so the object store is not optional.
   But M3.6 already established that attachments are downloaded **app-mediated, never as a direct
   object URL** — so nothing outside the compose network has any reason to reach RustFS. It gets
   no `ports:` block at all. The dev stack publishes 9100/9101 for the console; production
   publishes nothing.

4. **The first admin is an artisan command, not a seeder.** This is the genuine gap M9 has to
   close, and it is not infrastructure: **a fresh production database has no way to log in.**
   `DatabaseSeeder` calls `RbacSeeder` (the permission catalog and the `HR Admin` role — real
   configuration, required in production, and `SetHrAdminOffices` throws without it) and then
   `CompanySeeder` (a Manila/Cebu demo company, ten employees, a seeded punch pair — which must
   never touch production). They cannot be run together, and running neither leaves M8's
   done-when unreachable: you cannot "configure a company from an empty database entirely through
   the UI" if you cannot sign in to start.

   So: `php artisan hris:bootstrap-admin {email} {--name=}` runs the RBAC catalog (idempotent —
   `RbacSeeder` is `findOrCreate` throughout) and creates one `users` row with
   `is_system_admin = true`, printing a generated password exactly once.

   - **It creates a user and nothing else.** A System Admin needs no employee record:
     `SessionResource` already renders `employee: null`, and the seeded `sysadmin@hris.test` has
     none. That is what avoids the chicken-and-egg — an employee needs an organization, which is
     precisely what this admin is about to create through the UI.
   - **It refuses if a system admin already exists.** Not an upsert, not a password reset. A
     command that quietly mints a second superuser, or resets the first one's password, is a
     privilege-escalation path wearing a helpful face. Re-running it is an error with a clear
     message, and `MIGRATE_ON_BOOT` never invokes it.

5. **Backups cover both stores; the drill covers the database.** `make backup` writes a
   `pg_dump -Fc` of `hris` *and* a tar of the RustFS volume — an approved adjustment whose
   evidence cannot be produced is exactly the failure this system exists to prevent, so the
   attachments are not left out of the backup. `make restore-drill` restores the newest dump into
   a throwaway Postgres container, counts rows, and tears it down. **The attachments tar has no
   automated drill** — that is a known gap, recorded rather than smoothed over.

6. **`.dockerignore` is a security requirement here, not tidiness.** The prod stage is `COPY . .`.
   Without it, the host's `backend/.env` — a real `APP_KEY` and a real database password — is
   baked into an image layer, and the host's `vendor/` (dev dependencies included) is copied over
   the `--no-dev` one the `vendor` stage just built.

7. **CI builds both production images on every PR and pushes nothing.** There is no registry and
   no deploy target yet, so a push would be ceremony. Proving the two Dockerfiles still build is
   the part with value today, and — being outside every existing test path — the part that rots
   silently.

## What ships

| File | Change |
| --- | --- |
| `backend/Dockerfile` | `vendor` stage (composer layer cached off the lockfile) + `prod` stage |
| `backend/docker/Caddyfile.prod` | The public edge: TLS, `/api/*` → PHP, everything else → `web:3000` |
| `backend/docker/entrypoint.sh` | A production branch: guard `HRIS_DOMAIN`, `config:cache`, `route:cache` |
| `backend/.dockerignore`, `frontend/web/.dockerignore` | Keep `.env`, `vendor/`, `node_modules/`, `.git` out of the build context |
| `frontend/web/Dockerfile` | `deps` / `build` / `runner` stages over the existing `output: 'standalone'` |
| `compose.prod.yml` | `name: hris`; db + api (80/443) + web + rustfs + rustfs-init, no bind mounts |
| `.env.prod.example` | Every production variable, documented |
| `app/Console/Commands/BootstrapAdmin.php` | `hris:bootstrap-admin` (decision 4) |
| `Makefile` | `build`, `prod-up`, `prod-down`, `prod-logs`, `backup`, `restore`, `restore-drill` |
| `.github/workflows/ci.yml` | An `images` job |
| `scripts/e2e-prod-boot.sh` | The live proof |

Also folded in: `frontend/web/next.config.ts` currently declares `allowedDevOrigins` **twice**
(an uncommitted edit added a second key that silently shadows the first). One key, both values.

## Error handling

- **Required production variables are guarded at boot, in `entrypoint.sh`, not by `:?` in
  compose.** A required variable with no default fails interpolation for the *whole* compose
  file, on *every* command — including `down -v` and `config`, i.e. exactly the commands you need
  when something is already wrong. Compose keeps soft `:-` defaults; the loud failure lives in
  the one service that actually needs the value. (This is M0's reasoning for `APP_KEY`, extended
  to `HRIS_DOMAIN`. `HRIS_CURRENCY`/`HRIS_ORGANIZATION_NAME` are already guarded by
  `AppServiceProvider::assertConfigured()`, and the database password by the postgres image.)
- `hris:bootstrap-admin` refuses a malformed email, refuses a duplicate email, and refuses to run
  when a system admin already exists — each a distinct, non-zero-exit message.

## Testing

- `BootstrapAdmin` gets a Pest feature test: it creates a system admin, the RBAC catalog exists
  afterwards, the second invocation is refused with a non-zero exit, and the created user carries
  `is_system_admin = true` with a working password hash.
- Everything else in this milestone is infrastructure, and is proven by `scripts/e2e-prod-boot.sh`
  against a genuinely booted stack rather than by unit tests. A Caddyfile has no meaningful unit
  test; booting it and getting a `200` over TLS does.

## Done when

`make prod-up`, with `HRIS_TLS_ISSUER=internal` and `HRIS_DOMAIN=hris.localhost`, serves the app
over HTTPS from a single edge; `hris:bootstrap-admin` mints a login that then works through that
edge; `make backup` followed by `make restore-drill` passes. `scripts/e2e-prod-boot.sh` walks all
of it and tears the stack down after.

`hris.localhost` resolves to 127.0.0.1 with no `/etc/hosts` edit, and `internal` makes Caddy mint
from its own CA — so the whole proof runs on a laptop with no DNS and no ACME round trip.

## Explicitly deferred

| Item | Why not now |
| --- | --- |
| Registry push, real deploy | No registry and no target host exist. Building the image is the part that can rot; pushing it nowhere is not. |
| Off-host backup shipping (cron + bucket) | A backup you have never restored is a rumor. The drill is what makes shipping it worth automating, so the drill comes first. |
| An attachments restore drill | The tar is captured (decision 5); proving it restores needs a RustFS fixture the DB drill doesn't. Recorded as a gap. |
| Queue workers, horizontal scale | Nothing is queued today. `RecomputeRange` (M5b) is the first candidate, and it runs synchronously. |
| Log shipping, metrics, alerting | Single host, `make prod-logs`. Real observability is a decision about a real operator, not a guess. |
