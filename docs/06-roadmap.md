# Roadmap

Sequenced so that **every milestone ends with something you can actually run**. No
milestone is "build the service layer." The ordering principle, inherited from POS: the
riskiest and most expensive-to-change decisions get exercised by real code first.

What's riskiest here is not the same as it was there. POS's expensive mistake would have
been a rounding bug in money. Ours would be a **wrong pay multiplier applied to a locked
period** — because that isn't a bug report, it's back-pay and a DOLE complaint. So the
premium-pay matrix gets built as pure functions before a single table exists, and the
engine that applies it doesn't get to touch a period once payroll has consumed it.

## The invariants every milestone is measured against

These are the HRIS equivalents of POS's "money is integer cents." They are stated here
because M1 builds them and M2–M8 are only allowed to consume them.

- **Worked time is integer minutes.** Never decimal hours, in any layer, ever. `7h 20m`
  is `7.333…` and a shift is not a number you may round twice. Wire format is an integer
  with a `_minutes` suffix.
- **Money is integer centavos**, `bigint` / PHP `int`, wire suffix `_cents`. Carried over
  from POS unchanged, including `Money::fraction()` as the one place a centavo can be
  created or destroyed.
- **Pay multipliers are integer basis points.** 200% is `20000`. A multiplier is money's
  co-conspirator; it does not get to be a float either.
- **Punches are append-only.** A correction is a new row in `attendance_adjustments`. The
  raw `attendance_logs` row is never updated and never deleted, because it is the thing
  you show an inspector.
- **All timestamps are `timestamptz`, stored UTC, rendered in the office's timezone.**
  Calendar dates on the wire are `YYYY-MM-DD` strings, never `Date` objects — a punch at
  00:30 Asia/Manila belongs to the 30th, and a browser in another zone must not be able
  to disagree.
- **Art. 82 exemption gates every premium.** Managerial employees and field personnel get
  no overtime, no night differential, no holiday premium, no SIL. Any computation that
  produces a premium without reading `is_art82_exempt` is a bug, and `tests/Arch/` says so.
- **A locked period is immutable.** Once a cutoff closes, the engine refuses to write.
  A late correction becomes a visible adjustment in the next period, never a silent edit
  to paid history.
- **Statutory floors live in code; rates live in the database.** Admins change multipliers
  without a deploy; they cannot configure below the Labor Code minimum, and the write is
  refused at the boundary rather than discovered at payday.

## M0 — Skeleton that boots

- `compose.dev.yml`: `postgres:18-alpine` with a volume and healthcheck, `api`, `web`.
- `backend/`: Laravel 13 (PHP 8.5), Sanctum, Postgres connection, `GET /api/v1/health`.
- The `04-backend-conventions.md` skeleton: `app/Actions`, `app/Domain`,
  `app/Exceptions/Domain` with the `DomainException` base and its render hook, and the
  directory layout. `/api/v1/health` is built **as a real action** — controller → request
  → action → resource — so the very first endpoint sets the shape every later one copies.
- Framework exceptions mapped into the error envelope, and the envelope **closed** rather
  than enumerated: named handlers for validation/401/403/404/405/429, then a catch-all for
  every `HttpExceptionInterface` and — outside debug — every uncaught `Throwable`. POS
  learned the first half the hard way: handling only `DomainException` leaves Laravel's
  default shape leaking through and breaks the one-code-path promise before it's a day
  old. The second half is the same lesson one level up — an enumerated list fails silently
  on the case nobody remembered.
- `phpunit.xml` repointed at **real Postgres**, not the SQLite it ships with. We depend on
  `SELECT … FOR UPDATE`, partial unique indexes, `jsonb`, `timestamptz`, and range
  overlap constraints. A green SQLite suite would actively mislead.
- `tests/Arch/` from day one, not retrofitted: actions never touch HTTP, actions are
  `final`, no `env()` outside `config/` (the rule is the *directory* — stock `config/app.php`
  and `config/database.php` call it constantly and must), `declare(strict_types=1)` everywhere.
- `config/hris.php` with `version`, `currency` (PHP), and `organization_name`, plus
  `AppServiceProvider::assertConfigured()` as the fail-fast boot check. That check also
  enforces the UTC Global Constraint — but the timezone itself is **not** an `hris.php`
  key; it is Laravel's own `config('app.timezone')`, from `APP_TIMEZONE`. Only add a key
  here when the value is genuinely ours. `env()` is never called outside `config/`, which
  an arch test enforces.
- `frontend/web/`: Next.js 16 + React 19 + TS, `/api` rewrite so the browser sees one
  origin and CORS never comes up.
- `git init`, `.gitignore`, `Makefile` — `make help` lists every target: `dev`, `dev-down`,
  `dev-key`, `test`, `test-backend`, `test-web`, `clean`. No `seed` target yet; there is
  nothing to seed until M2 brings tables. CI runs `pest` on the backend and
  `lint` + `test` + `typecheck` + `build` on the web.
- `CLAUDE.md` documenting how to run all of it, pointing at `docs/README.md` as the
  source of truth.

**Done when:** `make dev`, and a browser page that says the API and database are alive.

**HRIS-specific trap to get right here, not later:** set `APP_TIMEZONE=UTC` and put the
display timezone on `offices`. A Laravel app defaulted to `Asia/Manila` will write local
times into `timestamptz` columns and be wrong in a way that only shows up when a second
office opens in another zone — by which point the data is already mixed.

**Status: complete.** Notes from actually building it, for whoever hits the same walls:

- `postgres:18` moved the recommended mount to `/var/lib/postgresql` (not `.../data`);
  mounting the old path makes the container restart-loop on first boot.
- Laravel's `phpunit.xml` ships pointing at in-memory SQLite. Repointed at real
  Postgres per the testing rule — deliberate, not an oversight.
- The framework's own exceptions (404, 405, validation) needed explicit mapping into
  the error envelope; handling only `DomainException` leaves Laravel's default shape
  leaking through, which breaks the one-code-path promise in `03-api.md`.
- `erasableSyntaxOnly` in the Next tsconfig forbids constructor parameter properties.
  `ApiError` declares its fields explicitly because of it.
- `docker compose exec` defaults to root. Against a bind mount that leaves root-owned
  files the host user cannot write; every Makefile `exec` passes `--user`.
- PHPUnit's `<env force="true">` only writes `putenv()`/`$_ENV`, but Laravel resolves
  `env()` through phpdotenv's `ServerConstAdapter` — `$_SERVER` first, first definition
  wins — and PHP's CLI SAPI pre-populates `$_SERVER` from the process environment. A
  testing value therefore needs a mirrored `<server>` entry to beat an ambient one;
  `DB_HOST`/`DB_PORT` are excluded from both blocks because they are the only values
  that legitimately differ between the native and containerized topologies.

## M1 — Time and pay primitives

Before any schema. Pure integer functions, no I/O, no container. This is where the
expensive bugs live and it is the foundation everything else computes on.

- `Minutes` — integer minutes. No float constructor exists; not discouraged, *absent*.
- `Money` — integer centavos, plus `Money::fraction(n, d)` rounding half away from zero,
  as the single rounding primitive. Ported from POS.
- `BasisPoints` — multipliers as integers, with composition (`2.0 × 1.3 × 1.3 × 1.1`)
  done in integer arithmetic through `fraction()` so the compounding order is fixed and
  testable rather than incidental.
- `DayType` — `Ordinary`, `SpecialWorking`, `SpecialNonWorking`, `RegularHoliday`,
  `DoubleRegularHoliday`.
- `PayMultiplier` — the resolver. `(DayType, isRestDay, isOvertime, isNightDiff)` →
  `BasisPoints`. Table-driven, pure, no database.
- `NightDiffSplitter` — splits a worked interval against 22:00–06:00, correctly across
  midnight, returning `Minutes` in and out of the window.
- `PunchPairer` — pure over an ordered list of punch times. Pairs **arbitrary even
  counts**, not just one in/out pair, because meal breaks are configurable per office and
  an explicit-break day is four punches. An odd count is reported as unpaired rather than
  guessed at.
- `MealBreakPolicy` — `Assumed(minutes, appliesOverMinutes)` or `Explicit`. Takes its
  parameters as constructor arguments and never reads config; the office column that
  selects it lands in M2. Both paths are built here so the engine never has to branch on
  a policy it cannot test.
- `OvertimeThreshold` — minutes beyond the scheduled day, given a schedule span.
- `frontend/web/src/lib/duration.ts` and `money.ts` — the browser mirrors.

**Done when:** the whole DOLE premium matrix is a table-driven unit test, green, with zero
database. Every cell pinned by name:

| Scenario | Multiplier |
| --- | --- |
| Ordinary day | 100% |
| Ordinary day, overtime | 125% |
| Rest day | 130% |
| Rest day, overtime | 169% (130% × 130%) |
| Special non-working, worked | 130% |
| Special non-working on rest day | 150% |
| Regular holiday, unworked | 100% |
| Regular holiday, worked | 200% |
| Regular holiday on rest day | 260% |
| Regular holiday on rest day, overtime | 338% (200% × 130% × 130%) |
| Double regular holiday, worked | 300% |
| Double regular holiday on rest day | 390% |
| Any of the above, 22:00–06:00 | × 110% |
| Employee with `is_art82_exempt` | 100%, always |

The last two rows are the ones that get skipped and shouldn't be. Night differential
**compounds on the already-premium rate**, not on base pay — 200% × 130% × 110% = 286%
for holiday overtime at 2am, and getting that wrong underpays quietly for years.

**Why first:** the same reasoning POS used for `Money`. A multiplier bug found here costs
an afternoon. Found after a cutoff closes, it costs a recomputation of every payslip since
the mistake, plus the conversation about why.

**Status: complete.** The whole matrix is a table-driven unit test — 88 unit tests, zero
database, no container booted. What the building actually turned on, for whoever extends
the matrix next:

- The rest-day adjustment is a **lookup table, not a formula.** Special non-working on a
  rest day is a flat **150%**, not 130% × 130% = 169%. Deriving the matrix from a rule is
  the single most likely way to get it wrong; `PayMultiplier::WORKED_BASE` pins every cell
  by hand and a unit test asserts this one is not 169%.
- Night differential **compounds on the already-premium rate**, not on base pay. Holiday
  overtime at 2am is 200% × 130% × 110% = **286%**, not 210%. The night factor is applied
  last and multiplicatively, and getting it wrong underpays quietly for years.
- `PunchPairer` pairs **arbitrary even counts**, because meal breaks are per-office
  configurable and an explicit-break day is four punches. An odd count is reported as
  unpaired, never guessed at.
- `NightDiffSplitter` works in minutes from the business-day start, so the 22:00–06:00
  window simply **recurs every 1440 minutes** and a shift crossing midnight needs no
  special case — and no timezone database inside a value object.
- Art. 82 enforcement is **by mandatory parameter, not an arch test.** `forWorkedTime()`
  and `forUnworkedDay()` take `bool $isArt82Exempt` with no default, so a premium cannot
  be computed without stating the employee's status — see `04-backend-conventions.md`
  rule 7. A required parameter fails to compile when omitted; an arch test only sees that a
  symbol was referenced.

## M2 — Schema, auth, and RBAC

- All migrations from `02-data-model.md`, including partial indexes and check constraints.
  Three tiers as explicit FKs (`organizations` → `offices` → `departments`), never a tree,
  so office scoping stays a plain `WHERE office_id = ?`.
- `employees` with a denormalized `organization_id` and `current_reports_to_id` — a
  self-FK cache of the effective-dated `reports_to_id` that lives on `employment_records`.
- `employment_records` — effective-dated. `is_art82_exempt`, `employment_type`, and base
  rate change mid-career, and a promotion must not retroactively strip last month's
  overtime.
- Sanctum email/password login with rate limiting.
- `spatie/laravel-permission` **without teams.** Roles answer only *what may this person
  do*. An `hr_admin_offices` pivot answers *over whom*, and is the single source of scope
  truth. See `05-rbac.md` for why POS's per-location teams don't transfer: there, the
  device token made team context unambiguous; here there is no device, so the context
  would come from the user or the request — which is the exact awkwardness POS's design
  eliminated.
- `users.is_system_admin` + `Gate::before`. Not a role. POS proved a global role
  assignment is impossible to express — `model_has_roles.<team_key>` is part of the
  primary key and therefore `NOT NULL`.
- `app/Domain/Scope/EmployeeScope` — returns a **query constraint, not a boolean**, so it
  composes into every index query and there is exactly one place the boundary is defined.
- Policies: only `EmployeePolicy` ships end to end in M2, as the proof of the two-check
  shape (verb via `can()` **and** subject via `EmployeeScope`); `RequestPolicy`,
  `SchedulePolicy`, `HolidayPolicy`, and `PayRulePolicy` arrive with their features in
  M4–M6, built on the same shape.
- `spatie/laravel-activitylog` installed; logging happens inside actions, never in model
  observers — an observer fires for seeders and migrations too, and pollutes the trail HR
  will one day be asked to defend.
- Seeders: one organization, two offices (Manila, Cebu — different enough to catch
  scope leaks), four departments, ~12 employees with a real reporting chain and one
  Art. 82-exempt manager, the 2026 PH holiday set, default `pay_rules` rows, three shift
  templates (regular 8–5, compressed 4×10, night 22:00–06:00).

**Done when:** you can log in, and:

- an employee 404s on another employee,
- a manager sees exactly their direct reports and 404s on a peer's,
- an HR Admin at Manila 404s on a Cebu employee,
- a system admin sees all of it.

**404, not 403.** Telling someone "this exists but isn't yours" leaks the org chart —
which for salary and disciplinary records is itself the disclosure.

**Status: complete.** The four-actor scope matrix is green; `migrate:fresh --seed` builds a
Manila/Cebu company you can log into as each of the four scopes. **163 backend tests**
(M0's 27 + M1's 88 + M2's feature/unit/arch), plus the arch suite that mechanically pins
the invariants below. What the plan above got reconciled to, and what the building actually
turned on — for whoever extends the schema next:

- **The `employees.user_id`-to-uuid cascade is wider than it looks.** Flipping `users.id`
  to `uuid default uuidv7()` (so `employees.user_id` can FK it) forces the change through
  everything that references a user by id, each an insert-time failure that reads like a
  framework bug if missed: `sessions.user_id` → `foreignUuid`; Sanctum's
  `personal_access_tokens` → `uuidMorphs` (default `morphs` is bigint — minting a token
  fails at insert); `activity_log.causer` → `nullableUuidMorphs`; and spatie's morph keys
  below.
- **spatie runs without teams, so roles are global and the morph key is uuid.** The
  published migration was edited: `model_has_roles.model_id` / `model_has_permissions.model_id`
  → `uuid` (users are uuidv7); with teams off, `roles` carries no team column and is
  `unique (name, guard_name)`. `roles`/`permissions` keep their `bigint` PKs deliberately —
  seeded reference data, never client-visible, the uuidv7 reasons don't apply. Manager is
  **derived from the org chart, not a role**; System Admin is a **flag via `Gate::before`,
  not a role** (POS's proof that a global role assignment can't exist carries over); spatie
  is left carrying exactly one role, `HR Admin`. See `05-rbac.md`.
- **The self-referencing FK needed a follow-up statement.** `employees.current_reports_to_id`
  references `employees.id`; adding its FK inline in the `create` runs before the table's
  own `->primary()` (Postgres's Laravel grammar appends the PK to the end of the command
  list), and Postgres rejects "no unique constraint matching given keys." A second
  `Schema::table()` call adds the FK after the PK exists. Commented at the site so it isn't
  "tidied" back inline.
- **The current-state cache has a single writer, and the arch guard distinguishes reads
  from writes.** `RecordEmploymentChange` is the only class that may write
  `current_office_id`/`current_department_id`/`current_reports_to_id` — one transaction
  writes history and cache together, so they can't disagree, and it advances the cache only
  when the new row is the latest effective date (a back-dated correction doesn't move it).
  A grep-based arch test enforces the single writer across three write forms (mass-assign,
  property, `setAttribute`); the mass-assign form is textually identical to a *read*-mapping
  in a `JsonResource`, so `app/Http/Resources/` is exempted from that one sub-pattern —
  resources read these columns for output and structurally can't call
  `create`/`update`/`fill`. Manager-derived means moving an employee under a new manager is
  one `RecordEmploymentChange`, no role edit.
- **`EmployeeScope` gets a narrow carve-out from the framework-agnostic Domain rule.** It
  lives in `app/Domain/Scope/` and returns an Eloquent `Builder` — its whole contract — so
  the arch rule that bars facades/`config()` from Domain `->ignoring()`s it explicitly. The
  rule was always about config purity, never about barring the ORM from the one class whose
  job is to hand back a constrained query.
- **`offices.code` is globally unique; `departments.code` is unique only within its office.**
  An office code stands alone (URLs, report headers); a department code never appears without
  its office, so `(office_id, code)` is its real identity — which lets `OPS` name Operations
  in both Manila and Cebu.
- **`RbacSeeder` flushes the permission cache *between* create and sync, not just at the
  end.** `findOrCreate`'s first lookup caches the still-empty permission collection, so a
  later `syncPermissions()` throws `PermissionDoesNotExist` for a permission just inserted.
  Surfaces only on a fresh boot (`migrate:fresh --seed`) where nothing warmed the cache
  first. Fixed by flushing between the two, plus a final flush so `CompanySeeder` (which
  assigns the role next) reads the fresh set. See `05-rbac.md` (Caching).
- **Reconciled against the plan bullets above.** M2 seeds **no holidays, no `pay_rules`, no
  shift templates** — the schema this milestone builds has no table for any of them; holiday
  calendars, pay-rule rows, and shift templates are M4's domain and land with their tables.
  The seeded company is one org, two offices (Manila, Cebu), four departments, and ten
  employees with a real reporting chain — an Art. 82-exempt manager per office (each with
  reports), one punch-only worker with no login — plus a System Admin and an HR Admin per
  office. Only
  **`EmployeePolicy`** ships (end to end, as the two-check `can()`-AND-`EmployeeScope`
  proof); the leave/schedule/holiday/cutoff policies arrive with their features in M4–M6.
  Refusals are **404 for out-of-scope subjects, 403 for unauthorized actors** — the
  four-actor matrix asserts both shapes, and that matrix is the milestone's proof.

## M3 — Timekeeping ingestion

Punch ingestion and nothing downstream of it: turning a punch into an append-only,
forensically intact row in `attendance_logs`. See
`docs/superpowers/specs/2026-07-24-m3-timekeeping-ingestion-design.md`.

**Rescoped from the original "vertical slice."** That slice reached a full computed pay
breakdown, which needs holidays, schedules, and `pay_rules` — none of which exist (M2 built
org/employees/RBAC only; the earlier "runs against M2's seeded schedules and holidays" line
was a stale assumption). Rather than pull the configuration layer forward, the compute
engine is resequenced to land *after* its inputs, and the frontend becomes its own
milestone. See the resequencing table below.

- `attendance_logs` — append-only ledger: `punched_at` (timestamptz UTC), `direction`
  (`in`/`out`, explicit), `source` (`web`/`manual`/`device`), `verification`
  (`verified`/`flagged`) + `flag_reason`, a **snapshot** `office_id`, `recorded_by`, and
  device/geo metadata columns. String columns + PHP backed enums + `CHECK` constraints (the
  M2 `DayType` pattern), never a Postgres native enum.
- `RecordPunch` — the one writer (arch-guarded). Self-service stamps server time
  (`source: web`); manual HR entry accepts an explicit time (`source: manual`,
  `recorded_by`), scoped by `EmployeeScope`.
- `EnsureIdempotency` middleware, ported from POS. `Idempotency-Key` required — a retry
  replays and writes no second row; the key and the row commit together.
- Verification is **flag, never reject**: an off-allowlist IP lands `flagged`, never a 4xx,
  because the Labor Code cares that time was worked, not which network recorded it.
- The **device contract is exposed, not built**: the payload accepts `source`/`device_id`/
  geo/idempotency, but device auth and batch ingestion defer with the hardware.
- `GET /api/v1/me/attendance?month=` and the scoped `/employees/{employee}/attendance` —
  raw punches grouped by office-local calendar date, labelled from the explicit direction,
  **no pairing and no business-day logic** (that is M5).

**Done when:** a seeded employee punches in and out (idempotent under retry), an off-network
punch lands flagged rather than refused, HR backfills a missed punch as `manual`, and
`GET /me/attendance?month=` returns them grouped by office-local date — with the raw log
provably append-only. `scripts/e2e-timekeeping.sh` proves it end to end.

**M3 explicitly does NOT own** pairing, business-day attribution (a night shift's 06:00
out-punch), missing-clock-out detection, or any pay computation — all compute-time (M5).

**Status: complete.** A seeded employee punches in and out (idempotent under a retried key),
an off-network punch lands `flagged` rather than refused, HR backfills a missed punch as
`manual` within their scope, and `GET /me/attendance?month=` returns them grouped by
office-local date — over a ledger provably append-only. **201 backend tests** (M0–M2's 163 +
M3's feature/unit + the arch suite, 16 of which mechanically pin the invariants), plus
`scripts/e2e-timekeeping.sh`, which walks the whole path against the running API. What the
building turned on and reconciled to, for whoever extends ingestion next:

- **The ledger is append-only, proven two ways at once.** `RecordPunch` is the *only* writer
  (a grep-based arch guard, `only RecordPunch writes attendance_logs`, matches every write
  form — `create`, `new`, `->update`/`->delete`/`->save`, `updateOrCreate`/`firstOrCreate`,
  `->upsert`, raw `DB::table('attendance_logs')->insert/update/upsert/delete` — and asserts
  it is the sole match), and it only ever `create`s. `AppendOnlyTest` closes the loop: no
  `PATCH`/`PUT`/`DELETE` route exists anywhere under `attendance`, and the sole writer
  contains no mutating form. Nothing else writes; the thing that writes only appends.
- **Enums are `text` columns + PHP backed enums + `CHECK` constraints** — the M2
  `DayType`/`employment_type` pattern, never a Postgres native enum (adding a value to which
  is an `ALTER TYPE` dance). `direction`/`source`/`verification` cast in the model; a schema
  test pins the `CHECK` lists against the enum cases so they cannot drift.
- **`office_id` is a snapshot**, captured from `current_office_id` at ingestion, so a later
  transfer never reinterprets an old punch's timezone or geofence. The same discipline the
  current-state cache uses, for the same reason.
- **Idempotency is ported from POS with a user-scoped hash.** The key and the row commit in
  one transaction (the middleware opens it, `RecordPunch` joins it); the hash folds in the
  acting user, so a key replayed by a different user — or with a different body — is
  `409 idempotency_key_reused`, never a leak of the first user's cached response. Only `2xx`
  stores a key. The self-service route requires the header (a missing key is
  `400 validation_failed`); the manual route deliberately is not idempotent.
- **Verification flags, never rejects.** An off-allowlist IP lands `flagged` /
  `ip_not_allowlisted` with a `201`, never a 4xx — the Labor Code cares that time was worked,
  not which network recorded it. The seeder gives the Manila office an `ip_allowlist` so this
  path has live data; Cebu has none, so its punches are `verified` unconditionally.
- **Manual entry is HR-only-never-self.** A plain employee/manager cannot manually punch at
  all (`403`, an actor refusal); an HR/admin targeting their *own* record is
  `422 cannot_punch_self` (separation of duties — you do not enter your own time); an
  out-of-scope target is `404` (the subject rule). This differs from the spec's original
  "scoped by `EmployeeScope`" line, which described only the scope dimension; the built
  endpoint adds the actor and self checks. **Self-corrections are a separate milestone:** an
  employee fixing their own missed punch goes through an attendance **adjustment request**
  (note + optional attachment, approved by `reports_to` or HR), which M3 does *not* build.
- **The read is the raw ledger, grouped by office-local date, with no pairing.** Each punch
  converts to *its snapshot office's* timezone and buckets by that local date, so a
  cross-midnight out-punch lands on its own calendar day — honest, and interpretation is M5's
  job. The device contract (`source`/`device_id`/geo/idempotency) is exposed in the payload
  but device auth and batch ingestion defer with the hardware.
- **A supplied UTC offset was being dropped — found and fixed in this milestone.** A manual
  entry supplying `2026-07-01T08:00:00+08:00` (the instant `00:00Z`) was stored as `08:00Z`,
  an 8-hour error: the model's `datetime` cast formatted the offset-aware Carbon in the app
  timezone *without* first normalizing to UTC — the classic Laravel gotcha. It slipped through
  Task 6 because `ManualPunchTest` asserted `source`/`direction`/`recorded_by`/scope but never
  the stored *instant*. `RecordPunch` — the one writer — now normalizes with `->utc()` before
  the write, and new tests pin the stored instant at the DB layer (a raw
  `DB::table('attendance_logs')->value('punched_at')` read), for both a supplied offset and the
  server-now path. The lesson for M5: assert the stored *instant*, never just the wire string
  or the rendered date.

### Milestones resequenced after M2

| Was | Now |
| --- | --- |
| M3 — vertical slice (ingest → compute → calendar) | M3 — Timekeeping ingestion (above) |
| — | **M3.6 — Attendance adjustments & the request/approval subsystem**: an employee files a request to correct a missed/wrong punch (add/void/amend, required note, optional RustFS attachment via Media Library), a manager or HR approves, and the correction supersedes the append-only ledger. Builds the shared `requests` spine + state machine + approval-authority rule that leave and OT reuse. See `docs/superpowers/specs/2026-07-24-attendance-adjustments-design.md`. Pulled forward from the old "Requests & approvals" milestone; independent of the frontend and the config spine. |
| — | **M3.5 — Frontend foundation**: the IBM/Carbon design language, tier-1/2 components, `lib/api.ts`/`keys.ts`/`date.ts`, the auth UI, and the punch + attendance screens, built against M3's real API |
| M4 — Configuration spine | M4 — Configuration spine (unchanged in content) |
| M5 — Requests & approvals | **M5 — Compute engine**: `ComputeDailySummary` → `daily_attendance_summaries`, consuming M3's punches and M4's config |
| M6 — Cutoffs & payroll export | M6 — Requests & approvals |
| M7 — Admin portal & audit | M7 — Cutoffs & payroll export |
| M8 — Containerization | (folds into the earlier milestones; final hardening as needed) |

The compute engine moves after the configuration spine because it reads schedules,
holidays, and `pay_rules` to resolve a day-type, a rest day, scheduled hours, and a
multiplier. Building it against seeded stubs M4 then reshapes would mean building the
system's highest-stakes code twice.

**The resequencing table above is the authority for milestone order.** The detailed
sections below are now renumbered to match it: `## M5 — Compute engine` is real,
brainstormed, and complete content, built for this slot from the start; `## M6` through
`## M9` (Requests and approvals, Cutoffs, Admin portal, Containerization) carry their
*pre-resequencing* headings' numbers corrected, but still their *pre-resequencing*
content — each is re-specced through its own brainstorm when it is reached (as M3 and M5
both were), the same way M3.6 and M3.5 were pulled forward and specced early. Until a
section is reached, read it for the substance of that unit of work, not for a promise that
its content is final.

## M3.6 — Attendance adjustments & the request/approval subsystem

An employee correcting their **own** attendance — a missed punch, a wrong direction, a punch
that shouldn't exist — files a request instead of a self-service punch (which stamps
server-now and can't backdate). A manager or HR approves; the correction supersedes the
append-only ledger without ever mutating it. Pulled forward from the old "Requests &
approvals" milestone, built independent of the frontend (M3.5) and the config spine (M4);
see `docs/superpowers/specs/2026-07-24-attendance-adjustments-design.md`.

- The shared `requests` spine (type/state/note/decision), reused later by leave and
  overtime: `pending → approved | rejected | cancelled`, no draft state.
- `attendance_adjustment_details` — a true 1:1 (`request_id` IS the primary key) holding
  `operation` (`add`/`void`/`amend`), an optional `target_log_id`, and the `direction`/
  `punched_at` an `add`/`amend` needs.
- `attendance_annulments` — append-only, `unique(attendance_log_id)`: how a `void`/`amend`
  supersedes a punch without editing or deleting the `attendance_logs` row. The **effective
  ledger** — `attendance_logs` minus `attendance_annulments` — is defined here for M5 to
  consume; M3.6 does not wire it into any read endpoint (`02-data-model.md`).
- `RecordAnnulment` — the one arch-guarded writer of `attendance_annulments`, exactly
  mirroring `RecordPunch`'s single-writer guard on `attendance_logs`.
- `ApplyAttendanceAdjustment` — the approval effect: `add` → `RecordPunch`
  (`source: adjustment`); `void` → `RecordAnnulment`; `amend` → both. Runs inside
  `ApproveRequest`'s `SELECT ... FOR UPDATE`-locked transaction, so a target that turns out
  invalid at approval time (`422 invalid_adjustment_target`) rolls back the whole approval —
  the request stays pending, nothing half-applies.
- `RequestAuthority::canDecide` — in-scope-minus-self: the requester visible to the approver
  under `EmployeeScope`, and the approver is never the requester. Cancel has its own,
  narrower rule: requester-only.
- `POST /attendance/adjustments` (submit, multipart with an optional attachment),
  `/approve`, `/reject`, `/cancel`, and the reads — `GET /attendance/adjustments` (mine),
  `/pending` (the approval queue), `/{request}` (scoped show), `/{request}/attachment`
  (private, app-mediated download) (`03-api.md`).
- RustFS (S3-protocol) + `spatie/laravel-medialibrary`, `media.model_id` patched to
  `uuidMorphs` — the `attachments` disk, private, never a direct object URL.

**Done when:** an employee files a missed-punch adjustment with a note and an attachment;
their manager or HR approves; the punch appears in the ledger via `RecordPunch`
(`source: adjustment`); a `void`, approved, records an annulment while the raw row stays
untouched; self-approval is refused (`404`); an already-decided request refuses further
transitions (`409`); the attachment downloads only for those who may see the request; two
concurrent approvals resolve to one winner; and `attendance_annulments` has one arch-guarded
writer. `scripts/e2e-adjustments.sh` walks the add-with-attachment and void paths against the
live stack (real RustFS).

**Status: complete.** **267 backend tests** (M0–M3's 201 + 66 for this milestone — schema,
submit, the three effects, transitions/authority, the two-process concurrency proof, reads,
and Media Library), **17 arch tests** (16 carried over + *"only RecordAnnulment writes
attendance_annulments"*), frontend unchanged at **16** (M3.6 is backend-only; M3.5 hasn't
landed yet). What the building turned on, for whoever extends the requests spine next:

- **The effective ledger is a query, not an endpoint, on purpose.** `attendance_logs` minus
  `attendance_annulments` is proven in `ApplyAdjustmentTest.php` (a raw
  `whereNotIn('id', AttendanceAnnulment::select('attendance_log_id'))`), but `GET
  /me/attendance` and `/employees/{employee}/attendance` deliberately keep returning the
  **raw** ledger unfiltered — an annulled punch still happened and is still shown, the same
  "record you'd show an inspector" principle M3 established. Filtering it out is M5's job,
  when there is a computation that actually needs the effective set; wiring it into the raw
  read now would blur the one thing M3 exists to keep honest. Whoever builds M5: read the
  effective ledger, never the raw table, for anything that touches pay.
- **404-vs-409-vs-422 ordering is load-bearing, and reject's is the subtle one.** Approve and
  reject both check authority (`404`) before pending-ness (`409`) before their own effect —
  an out-of-scope prober must never learn a request exists by getting a *different* refusal
  than a truly-nonexistent id would produce. Reject's `decision_note`-required check sits
  **inside** the action, after both of those, specifically because validating it in the
  `FormRequest` (which runs before route-model-bound authority) would let an out-of-scope
  caller distinguish "exists but hidden" (`400` on an empty body) from "doesn't exist"
  (`404`) — an existence leak an opus-level review caught before merge, not after. The fix
  is the ordering itself: authority → pending → note-validation.
- **The row lock had to be proven with two real Postgres sessions, not two sequential
  calls.** A same-process "approve twice in a row" test proves the *state guard* (the second
  call sees `state: approved` and 409s) but never contends for the lock — nothing is held
  open concurrently in one PHP process. `ApproveRequestConcurrencyTest` forks a genuine
  second OS process (`proc_open`, a real second Postgres backend) that takes and holds
  `ApproveRequest`'s exact row lock; this process's concurrent call must actually block at
  the database level, then see the row already decided once the holder commits. It
  deliberately skips `RefreshDatabase` (that trait's outer transaction would hide its fixture
  rows from the second, genuinely separate connection) and cleans up by hand instead.
- **A missing `ext-exif` PHP extension silently desynced the containerized `api_vendor`
  volume.** `spatie/image` (a `laravel-medialibrary` dependency) hard-requires it; the dev
  Dockerfile installed `pdo_pgsql pgsql bcmath intl opcache` but not `exif`, so every
  container-boot `composer install` refused before touching the filesystem — "Your lock file
  does not contain a compatible set of packages" — leaving the named volume holding
  pre-M3.6 packages while `composer.lock`/`installed.json` already described the post-M3.6
  set. Native `./vendor/bin/pest` (host PHP, which does have `exif`) never saw this, which is
  why it went unnoticed through Tasks 1–8. Fixed by adding `exif` to the Dockerfile's
  `install-php-extensions` line; found and fixed running this task's `make test`.
- **Media Library's `media` table needed one edit, not a fork.** `morphs('model')` (the
  package's published migration) is `bigint`; every owner here is a uuidv7 string, so it
  became `uuidMorphs('model')` — the same edit `personal_access_tokens` needed in M2, applied
  to a third table for the same reason.

## M3.5 — Frontend foundation

The IBM/Carbon design language, tier-1/2 components, `lib/api.ts`/`keys.ts`/`date.ts`, the
auth UI, and the punch and attendance screens — built against M3's and M3.6's real API, not
a mock. Ships **login and the attendance screen only**; there is no adjustments UI, no
roster, and no team/office/admin screens yet — those land with the milestones that own
their data.

- `DESIGN.md` at the repo root as the token authority (colors, type scale, spacing,
  radius, per DESIGN.md's own front-matter) and `frontend/web/src/styles/carbon.css` as the
  **one place those tokens enter code**, hand-written from it — every component reads a
  `var(--*)`, never a raw hex or literal type step. IBM Plex Sans is self-hosted via
  `next/font/local` (vendored `woff2`, one `--font-plex` CSS variable at three weights);
  `src/lib/brand.ts` names the product once (`PRODUCT_NAME`).
- Tier-1 primitives: `Button` (Carbon's label-left/icon-right layout), `TextInput`
  (filled, bottom rule, `aria-invalid`/`aria-describedby` wiring), `InlineNotification`,
  `Skeleton`. Tier-2: `AppShell`, `SideNav`, `SectionHeader`, `StatTile`, `Tag`,
  `EmptyState`. Domain: `Duration`, `DayCell`, `MonthCalendar`.
- `SideNav` splits scope rules from rendering on purpose: a pure, directly-tested
  `navEntriesFor(session)` decides which groups a session may see; the component then
  hides any group whose route list is still empty — an **earn-your-place** rule so a
  manager never sees a "Team" heading that dead-ends at nothing, because Team/Office/Admin
  screens don't exist yet.
- `src/lib/session.ts` — the one module that touches `localStorage`, SSR-safe (every
  function no-ops when `window` doesn't exist) — and `api.ts` extended: attaches
  `Authorization: Bearer`, and on a `401` clears the token and broadcasts logout *before*
  the caller ever sees the rejection, so a redirect to `/login` is one code path, not one
  per call site.
- `src/lib/keys.ts` — the query-key factory; no hook ever writes a literal array. `date.ts`
  keeps calendar dates as `YYYY-MM-DD`/`YYYY-MM` strings end to end (Monday-zero
  `weekdayIndex`, `timeInZone` for rendering a punch's instant in a given zone) — never a
  `Date` round-trip through the browser's own timezone, for the same reason the backend
  never lets one happen.
- `Providers` + `SessionProvider` + `useSession`: **one `GET /me` backs every scope
  decision** on the page — nav visibility, the `(app)` route guard, the header's account
  menu. The `(app)` layout redirects to `/login` when the session resolves to
  unauthenticated; it does not gate on the token's mere presence.
- The Carbon shell: a charcoal 44px header over `SideNav` and a main content region.
  Sign-out clears the token and navigates to `/login` in a `finally`, regardless of how
  `api.logout()` resolves — a dead network or an already-expired token can never strand a
  user signed in locally.
- The split-canvas `(auth)/login`: charcoal brand panel beside a white form. A wrong
  password and an unknown email produce the identical fixed message
  ("That email and password don't match.") — the copy never branches on the error `code`,
  matching M2's constant-time backend guarantee that the two are indistinguishable.
- `useMyAttendance` (thin `useQuery` wrapper, no abstraction over it) and `usePunch`: the
  idempotency key is minted once per **attempt** (one `mutate()` call) and reused across
  every automatic retry of that attempt — the key ref clears only in `onSettled`, which
  fires once the whole attempt (retries included) is done, so a flaky-connection retry
  replays the same key instead of minting a second punch.
- `(app)/me/attendance`: the punch hero (now/status/today's running total, derived from
  today's punches — never invented from a separate summary endpoint) sits above the month
  ledger; the viewed month lives in the URL as `?month=YYYY-MM`, independent of the hero,
  which always reflects *today* regardless of which month is being browsed.
- `MonthCalendar`/`DayCell` — **the signature: a day cell is a ledger, not a summary.** It
  renders each punch's real clock time; a total appears only when the day's punches pair
  cleanly (even count, alternating in/out, chronological); anything else — a missing
  clock-out, two `in`s in a row — renders the punches with **no invented total**, tagged
  "Unpaired — no total," because guessing at the shape of an irregular day is M5's
  authoritative-computation job, not this presentational layer's.
- **Known M3.5 limitation, stated plainly, not smoothed over:** the session carries only
  `current_office_id` (a uuid, no name, no timezone) — there is no office model yet to look
  either up from. `src/lib/timezone.ts` is a single documented constant,
  `OFFICE_TIME_ZONE = 'Asia/Manila'`, standing in for a real per-office lookup (correct
  today because every seeded office is Philippine); every caller that needs "the office's
  timezone" imports it from there rather than reaching for the viewer's own zone or
  re-declaring the literal. For the same reason, the header shows no office name — a raw
  uuid in the product header reads as broken chrome, so it shows nothing rather than
  fabricate a display name.

**Done when:** a seeded employee signs in at `/login`, lands on `/me/attendance`, clocks
in and sees the hero reflect it, clocks out, sees the punch on today's cell with its real
in/out times, navigates to the previous month, and signs out — the whole surface rendered
from `carbon.css`, with no component reading a raw token or a literal query key.

**Status: complete, with one verification gap recorded below.** **189 frontend tests** (up
from 16 at the end of M3.6, incl. the post-merge polish pass), backend **unchanged at 267 + 17 arch** — this milestone touches
no PHP. `lint`, `test`, `typecheck`, and `build` are all green, native and inside the
`make test` containers alike.

**The Done-When above was proven at the API and component-test level, not by a live browser
click-through.** The whole flow was walked against the running stack with real HTTP — sign
in, session fetch, an idempotent clock-in whose retry provably replayed the same row rather
than writing a second punch, clock-out, the month ledger grouped exactly as the calendar
consumes it, an empty previous month, sign-out, and a 401 in the real envelope — and every
screen has component tests. But the rendered UI was never visually confirmed: React
hydration would not complete in the build sandbox's browser, which reproduced identically on
M0's long-shipped health page and is therefore environmental rather than a defect in this
milestone's code. Unlike M3 and M3.6, which each ship an `e2e-*.sh` proving their flow end to
end, **M3.5 has no equivalent live-UI evidence — a human click-through is outstanding, and
an e2e harness for the frontend is unclaimed work.** Treat the visual layer as unverified
until someone loads it in a real browser.

What the building turned on, for whoever extends the frontend next:

- **Vitest does not read `tsconfig.json`'s `paths`.** The `@/*` → `./src/*` alias has to be
  declared a second time, in `vitest.config.ts`'s own `resolve.alias`, or every component
  test fails on an unresolved import before it ever runs; `setupFiles: ['./vitest.setup.ts']`
  (which registers `@testing-library/jest-dom`'s matchers) is equally load-bearing — drop
  either and the whole suite goes red for a reason that has nothing to do with the code
  under test.
- **The CSS `font` shorthand cannot carry `letter-spacing`.** `carbon.css`'s `--t-*` tokens
  use the shorthand for size/weight/line-height; DESIGN.md's tracking (0.16px on
  body/button/eyebrow, 0.32px on caption, negative tracking on the display sizes) would be
  silently dropped everywhere it applies if left to the shorthand alone. Companion `--ls-*`
  tokens carry it instead, and every component that sets `font: var(--t-*)` sets the
  matching `letter-spacing: var(--ls-*)` alongside it — a review in Task 1 caught the first
  version missing this entirely.
- **The vendored IBM Plex Sans files were the Latin-1-only subset**, missing U+20B1 (₱) —
  `money.ts` already emits the peso sign, but the font couldn't render it, so it silently
  fell back to the system font for every currency string. Replaced with `@ibm/plex-sans`'s
  complete build (verified via `file(1)` and an `fc-scan` cmap inspection to contain U+20B1
  while retaining Latin-1 and Euro coverage) at three weights (300/400/600), the only ones
  `carbon.css` uses.
- **A bare `<a>` inside the `(app)` route tree would have re-fetched the session on every
  nav click.** A plain anchor triggers a full document navigation, which remounts
  `Providers`, builds a fresh `QueryClient`, and re-runs `GET /me` — defeating the
  single-session-fetch guarantee `useSession` exists to provide. `SideNav` navigates with
  `next/link` for exactly this reason, documented at the call site so it isn't "simplified"
  back to an anchor later.
- **The raw office uuid was deliberately dropped from the header**, not forgotten. An
  earlier pass rendered `session.employee.current_office_id` next to the product name;
  review flagged that a bare uuid in product chrome reads as broken, not honest — it comes
  back once a real office name is a lookup away, not before.
- **Idempotency-key lifetime is scoped to the mutation ref, not the component.** Reusing a
  `useState` for the key would have re-minted a new one on every re-render triggered by the
  mutation's own pending state; a `useRef` cleared only in `onSettled` is what makes "one
  key per attempt, including retries" actually true rather than aspirational.

## M4 — Configuration spine

**Status: complete.** Everything M3.5 and M5 will read becomes admin-editable, per office.

**Sliced into three milestones, the same move M3 got (vertical slice → M3/M3.6/M3.5) once
the actual build made the scope concrete:**

| Slice | Covers | Status |
| --- | --- | --- |
| **M4a — Holiday Calendars** | Per-office holiday CRUD + clone-from-previous-year | **Complete** — below |
| **M4b — Shift Templates** | Template CRUD, assignment, per-date override, resolution order | **Complete** — below |
| **M4c — Pay Rules** | `pay_rules` editor, statutory-floor validation | **Complete** — below |

**`RecomputeRange` moves to M5, alongside the compute engine it exists to drive.** There is
nothing to recompute before something computes — M4's original "Done when" line below (a
100% → 130% pay flip) needs an engine that turns a `DayType` into a multiplier, and that
engine doesn't exist until M5. Building `RecomputeRange` against M4a/M4b/M4c's config tables
with no engine to invoke would mean building it twice: once now against nothing, and once
properly once M5's `PayMultiplier`/`ComputeDailySummary` exist to be its payload. Each of
M4a/M4b/M4c ships **configuration only** — real, admin-editable, fully tested data that
M5 is the first thing to actually read.

The plan below is **pre-slicing** — original scope, kept for the historical record. Read the
table above for what actually shipped and when; read M4a's own section for what M4a
specifically proves.

- Holiday calendar CRUD per office per year, with clone-from-previous-year. PH holidays
  are set by **annual presidential proclamation** — the dates move, Eid'l Fitr and Eid'l
  Adha move a lot, and a hardcoded list is wrong by January. This is data, permanently.
- Shift template CRUD; assignment to employee or department with an effective date range;
  per-date override for rest-day swaps and one-off changes. Resolution order is
  override → assignment → office default, and it is one service with one test suite.
- `pay_rules` editor. Effective-dated rows. Writes validated against the statutory floor
  in code — configuring 100% on a regular holiday is refused, not warned about.
- `RecomputeRange` action: explicit, queued, scoped to exactly the affected
  `(employee, date)` pairs. Config changes never silently mutate computed history; they
  enqueue a recompute that is itself audited. **Deferred to M5** — see above.
- UI: `/office/holidays`, `/office/schedules`, `/admin/pay-rules`, using `<MonthCalendar>`
  for the third time — the reason it exists.

**Done when** (the original, pre-slicing line — the recompute half is M5's, not M4a's):
HR adds August 21 (Ninoy Aquino Day) as a special non-working day for the Manila office
only, recompute runs, affected Manila days flip 100% → 130%, Cebu is untouched, and the
activity log names who did it and when. **M4a proves everything up to and including "the
holiday exists as configured data, scoped to Manila, logged" — nothing yet reads it to
flip a rate; that recompute is explicitly M5's job, not M4a's, per the slicing above.**

## M4a — Holiday Calendars

Per-office holiday CRUD, with clone-from-previous-year — the first slice of the
configuration spine, and the first M4-era milestone to actually ship.

- `holidays` — `(office_id, date)` unique, `day_type` a `CHECK`-constrained non-`Ordinary`
  `DayType` (`Ordinary` is the absence of a row). See `02-data-model.md`.
- `App\Domain\Scope\OfficeScope` — the M4 config boundary, `EmployeeScope`'s sibling:
  which offices an actor administers, as a query constraint, not a boolean (`05-rbac.md`).
  A System Admin administers every office; an HR Admin exactly the offices in their
  `hr_admin_offices` pivot; anyone else, none.
- `POST/GET /office/holidays`, `POST /office/holidays/clone`, `PATCH`/`DELETE
  /office/holidays/{holiday}` — every one scoped by `OfficeScope`, and every refusal is
  `404`, never `403`: the `FormRequest`s validate `office_id`/`office` as shape-only
  `uuid`, deliberately never `exists:offices,id`, so a fabricated office/holiday id and an
  out-of-scope real one are byte-identical (`03-api.md`).
- `App\Actions\Holidays\CloneHolidays` — copies the source's month/day onto the target
  year directly (never a `+365`-day shift, which breaks across a leap year), skips a
  target date already occupied rather than overwriting it, and skips a Feb 29 source with
  no Feb 29 in the target year rather than sliding it to Mar 1. Re-running an identical
  clone is a true no-op.
- The frontend `/office/holidays` screen — the second consumer of `MonthCalendar` after
  `/me/attendance` (the reason it was generalized to a `renderDay` prop in M3.5's
  follow-up): click a day with no holiday to add one, click one that has a holiday to edit
  it, "Clone from {year − 1}" seeds the whole year at once. Scoped to the offices
  `session.hr_offices` names, not `current_office_id` (the office you work at, not the
  offices you administer).
- The activity log's first real feature use: `Holiday`'s `LogsActivity` logs every
  create/update/delete with the `Holiday` itself as the uuid-morph subject; `CloneHolidays`
  logs a from/to/created-count summary against the `Office` (a clone spans many rows, not
  one). Causer resolves automatically from the authenticated guard — no action passes it
  explicitly except clone, which needs the causer for its own summary log.

**Done when:** a Manila HR admin adds Ninoy Aquino Day (Aug 21) as a special-non-working
holiday for Manila; it shows on Manila's `/office/holidays` and not on Cebu's; a Cebu-only
HR admin gets `404` — never `403` — touching it, byte-identical to a fabricated id;
"Clone from 2025" copies last year's Manila set into 2026, skipping any date already
present; and the activity log names who added it and when, with the holiday itself landing
in the uuid morph. **No pay is computed** — `holidays` is the input M5's compute engine
will read, once that engine exists.

**Status: complete.** **302 backend tests** (909 assertions — `holidays`' schema,
`OfficeScope`'s own three-actor matrix, and the four holiday endpoints'
create/list/update/delete/clone coverage, including the byte-identical-404 proof exercised
directly rather than asserted by status code alone; M3.6/M3.5's own docs recorded 267, but
`main` had already grown past that by the time this branch forked, so 302 is this run's
real total, not a precise "+35 for M4a" delta), **19 arch tests** (`OfficeScope` gets the
same Domain-layer carve-out `EmployeeScope` has, so it introduces no new arch-guarded
invariant of its own — M3.6/M3.5 recorded 17; the difference predates this branch too),
frontend at
**222 tests** (M3.5 recorded 189; the `/office/holidays` screen, `useHolidays`, and the
new `Dialog`/`Select` primitives it needed). `lint`, `typecheck`, and `build` are green
native and inside the `make test` containers alike. `scripts/e2e-holidays.sh` walks the
whole surface — create, scoped list, the byte-identical 404 on GET/PATCH/DELETE, clone
(both the skip and the genuine copy), and the activity-log row — against the live stack.
What the building turned on, for whoever picks up M4b next:

- **`OfficeScope` is `EmployeeScope`'s sibling, built the same way for the same reason.**
  A query constraint, not a boolean, so the same class answers "may render" and "may
  filter" without the two ever disagreeing; the same Domain-layer arch carve-out
  (`->ignoring()`) that lets `EmployeeScope` touch Eloquent now names `OfficeScope`
  too — the rule was always about config purity, not about barring the ORM from a class
  whose whole contract is "return a `Builder`."
- **The 404-not-403 discipline transfers to a second resource with zero new machinery.**
  Every holiday `FormRequest` validates an office id as shape-only `uuid`; every controller
  resolves scope via `OfficeScope::administered()`/`administers()` and throws
  `NotFoundHttpException` on a miss. No new envelope code, no new error code — `not_found`
  and `validation_failed` already existed from M2/M3.6 and needed nothing added.
  `HolidayReadWriteTest`/`CloneHolidaysTest` assert this with `assertExactJson` between the
  out-of-scope-real and fabricated-id responses, not just matching HTTP status.
- **The verb axis is seeded, not wired.** `holiday.manage` has existed in the permission
  catalog since M2's `RbacSeeder`, but no holiday endpoint checks it — authority is entirely
  `OfficeScope`-based today, the same position `employee.manage` was in through M2. Whoever
  wires a real verb check onto holiday edits later is adding it, not fixing an oversight.
- **Clone proves same-month/day, not `+365` days, across a genuine leap boundary.** A
  2023-03-15 source cloned into 2024 (a leap year) must land on 2024-03-15, not 2024-03-14
  — the one test pair where a naive day-count shift and the correct month/day rule actually
  disagree, so it's the case that pins the property rather than merely exercising it.
  Skip-on-occupied and skip-on-missing-Feb-29 are separately pinned so cloning is provably
  re-runnable and never invents a Mar 1 that was never proclaimed.
- **A container `node_modules` volume desync, the `ext-exif` gotcha's sibling.** The api
  container's `vendor/` volume needed `ext-exif` added to its Dockerfile back in M3.6; here
  it was the web container's `node_modules` volume, stale from before `@radix-ui/react-dialog`
  and `@radix-ui/react-select` (the `Dialog`/`Select` primitives the holiday screen needed)
  landed in `package.json` — `make test`'s containerized `npm test` failed on an unresolved
  import until `npm install` ran inside the container to catch the volume up. Native
  `npm test` (host `node_modules`, installed fresh) never saw this, the same asymmetry that
  hid the `ext-exif` gap from native `./vendor/bin/pest` in M3.6.

## M4b — Shift Templates

Shift-template CRUD, employee/department assignment, per-date overrides, an office-wide
default, and the resolution order that ties them together — the second slice of the
configuration spine.

- `shift_templates`/`shift_template_days` — a template is a named week; the seven weekday
  rows (`App\Domain\Schedule\Weekday`, `0=Monday..6=Sunday`) each carry `is_rest` XOR a
  minute-range shift, `CHECK`-constrained so a cross-midnight shift (`end_minute` up to
  `start_minute + 1440`) is representable but a nonsensical range isn't. `schedule_assignments`
  targets exactly one of an employee or a department (`CHECK` + two partial unique indexes
  on effective date); `schedule_overrides` is the per-`(employee, date)` exception.
  `offices.default_shift_template_id` is the resolution floor. See `02-data-model.md`.
- `App\Domain\Schedule\ScheduleResolver` — the single interface M5's compute engine will
  call: override → employee assignment → department assignment → office default, first hit
  wins, `source` names which layer answered. Pure read, no transaction, no writes.
- `POST/GET/PATCH/DELETE /office/shift-templates`, `/schedule-assignments`,
  `/schedule-overrides`, `PATCH /office/default-template`, `GET
  /office/schedule/resolved` — every one scoped by the same `App\Domain\Scope\OfficeScope`
  M4a built, no new authority model; every refusal is `404`, never `403`, byte-identical
  between an out-of-scope real id and a fabricated one (`03-api.md`).
- `App\Exceptions\Domain\TemplateInUse`/`ScheduleAssignmentExists`/`ScheduleOverrideExists`/
  `OfficeHasNoDefaultTemplate`/`EmployeeHasNoOffice` — the same "turn a database constraint
  or a resolution dead-end into a clean `422`, never a `500` or a silent orphan" pattern
  `HolidayExists` set in M4a.
- The frontend `/office/schedules` screen — templates, an office-default picker, an
  assignment list, and a resolved calendar built on `MonthCalendar` (its third consumer),
  click-a-day to open a single-day override editor. Two honest, cosmetic gaps, not
  correctness ones (both documented in the screen's own file-level comment):
  - **Assignment targets are employee-only in the UI.** The backend and API fully support a
    department-target assignment (tested end to end, and proven live by
    `scripts/e2e-schedules.sh`'s step 7) — there is simply no `GET /office/departments` list
    endpoint yet for a target-type toggle to source options from.
  - **The office-default indicator is session-local.** `PATCH /office/default-template` is
    write-and-echo-back only — there is no `GET` for an office's current default — so the
    screen only ever highlights a default it set *this session*; a reload, or an office it
    never touched, legitimately shows none highlighted even though one exists server-side.
- CompanySeeder seeds both offices a "Standard Mon-Fri" template as their office default, an
  employee-level assignment of it to Miguel Santos (MNL-0002), and one rest-day-swap
  override for him, so `/office/schedules` and the resolved read are non-empty on a fresh
  `make dev`.
- Every write is logged the same way M4a's holidays are:
  `ShiftTemplate`/`ScheduleAssignment`/`ScheduleOverride`'s own `LogsActivity` trait for
  their own creates/updates/deletes (the model itself as the uuid-morph subject); setting
  the office default logs manually against `Office` (which has no `LogsActivity` of its
  own), the same way `CloneHolidays` logs its from/to/created-count summary.

**Done when:** a Manila HR admin builds a Mon-Fri 08:00-18:00 (Sat/Sun rest) template,
sets it as Manila's office default, and assigns it to a seeded employee; the resolved read
returns Sat/Sun `is_rest:true, scheduled_minutes:0` and weekdays at `scheduled_minutes:540`;
a second template with a Tue 17:00→03:00 shift resolves `end_minute:1620`; an override
swaps one Saturday to working and the following Monday to rest, both flipping with
`source:"override"`; a Cebu-only HR admin gets byte-identical `404`s touching a Manila
template; the activity log names who did what. **No pay is computed** — schedules are the
second input M5's compute engine will read, alongside M4a's holidays and M4c's `pay_rules`.

**Status: complete.** **382 backend tests** (1,242 assertions — the four schedule tables'
schema, `ScheduleResolver`'s own unit suite including the cross-midnight case, and the six
schedule endpoints' create/list/show/update/delete coverage, including the byte-identical-404
proof exercised directly; M4a's own docs recorded 302, but `main` had grown past that by the
time this branch forked, so 382 is this run's real total, not a precise "+80 for M4b" delta),
**19 arch tests** (unchanged from M4a — `OfficeScope` already carried the Domain-layer carve-out
this milestone's controllers reuse, so M4b introduces no new arch-guarded invariant of its
own), frontend at **279 tests** (M4a recorded 222; the `/office/schedules` screen, its five new
hooks — `useShiftTemplates`, `useScheduleAssignments`, `useScheduleOverrides`,
`useResolvedMonth`, `useEmployees` — and the new `ResolvedDayCell` component make up most of
the difference, the same "real total, not a precise per-milestone delta" caveat M4a's own
count carries). `lint`, `typecheck`, and `build` are green native and inside the `make test`
containers alike.
`scripts/e2e-schedules.sh` walks the whole surface — template/default/assignment/night-shift/
department-assignment/override/resolve, the byte-identical 404 pair, and the activity-log row
— against the live stack. What the building turned on, for whoever picks up M4c next:

- **A fourth table family reusing `OfficeScope` unchanged proves the boundary is genuinely
  reusable, not accidentally holiday-shaped.** Unlike a holiday (which owns its own
  `office_id`), a schedule assignment or override has no office column of its own — its
  office is its *target's* office (an employee's `current_office_id`, or a department's
  `office_id`) — and the controllers resolve that indirection once, then hand the same
  `office_id` to the same two `OfficeScope` calls M4a already had. No new scope class, no
  new 404 machinery.
- **A cross-midnight shift is the one place minutes-not-times pays for itself twice over.**
  `end_minute` allowed up to `start_minute + 1440` (never wrapped to a smaller number) means
  a 17:00→03:00 shift is one row, one `CHECK`, and one resolver code path — the same
  `ResolvedScheduleTest` pins both a same-day shift and this one so they can't silently
  regress into each other.
- **Two backend-complete, UI-deferred gaps, named rather than hidden.** Department-target
  assignment and reading an office's current default both work end-to-end against the API
  (the former proven live by the e2e script) with no screen for the first and only a
  session-local view of the second — recorded above and in the screen's own comment, so
  "why doesn't the UI do X" has an answer instead of looking like an oversight.
- **Containerized frontend test runs showed transient timing flakiness across two unrelated
  files (`schedules.test.tsx`, then on a separate run `attendance.test.tsx`) that a bare
  rerun cleared both times** — neither file was touched by the failure a second time,
  pointing at container resource contention under Vitest's parallel workers rather than a
  real regression; native `npm test` never reproduced it. Different from M4a's
  `node_modules`-drift gotcha (that one was deterministic and fixed by `npm install`); this
  one is worth knowing about if a future `make test` run flakes on an unrelated file.

## M4c — Pay Rules

Effective-dated `pay_rules` versions, statutory-floor validation, and the sysadmin-gated
CRUD — the third and final slice of the configuration spine. **M4c completes M4.**

- `pay_rules`/`pay_rule_day_rates` — one version per `effective_from` (`unique`), three
  scalar rates (`overtime_ordinary_bp`/`overtime_premium_bp`/`night_diff_bp`) plus five
  `day_type`-keyed rows (`worked_bp`/`worked_rest_bp`/`unworked_bp`, one per
  `App\Domain\Pay\DayType` case, `Ordinary` included — a pay rule prices every kind of day,
  not just the non-ordinary ones a holiday marks). See `02-data-model.md`.
- `config('hris.pay_floors')` — the DOLE statutory-minimum matrix, the same numbers M1's
  premium-pay unit test encodes, now the boundary every write is validated against.
  `App\Domain\Pay\StatutoryFloor::violations()` is pure and framework-agnostic: it takes
  both matrices as arguments and returns every violating cell, never throwing itself — the
  caller (`App\Actions\PayRules\CreatePayRule`) turns a non-empty result into
  `App\Exceptions\Domain\PayRateBelowFloor` (`422 pay_rate_below_floor`) before its
  transaction ever opens.
- **No `OfficeScope` at all — a different authority model than M4a/M4b.** A pay rule is a
  company singleton, not a per-office resource, so there is nothing to scope by and nothing
  to enumerate. Every pay-rule `FormRequest` gates directly on `is_system_admin`, the same
  one-line idiom M2's onboarding endpoints use; a non-admin gets the plain `403 forbidden`
  a subject-less actor check produces, never the 404-not-403 discipline holidays/schedules
  use. See `05-rbac.md`.
- `POST/GET /admin/pay-rules`, `GET/DELETE /admin/pay-rules/{payRule}` — deliberately **no
  `PATCH`**. Versions are immutable by omission: a rate correction is always a new version,
  read alongside every earlier one, never an edit in place. `PATCH` on an existing id gets
  Laravel's own `405 method_not_allowed`, because the route simply isn't declared for that
  verb. See `03-api.md`.
- `App\Exceptions\Domain\PayRuleExists` (`409 pay_rule_exists`) — the duplicate-
  `effective_from` guard is the `unique` constraint itself, not an `exists()` pre-check:
  unlike `CreateHoliday`, there is no parent office row to `lockForUpdate()` first, so
  `CreatePayRule` attempts the insert and translates the resulting
  `UniqueConstraintViolationException`, which is race-safe as well as covering the
  sequential-duplicate case identically.
- The frontend `/admin/pay-rules` screen — a **matrix editor**, not a calendar (unlike
  `/office/holidays`/`/office/schedules`, which it otherwise mirrors structurally): no
  office picker, since a pay rule prices every office the same way. "Currently effective"
  is computed client-side (the version with the greatest `effective_from <= today`) from
  the plain list, never trusted to arrive pre-sorted for that purpose. The New-version
  dialog shows a client-side floor hint per cell (`PAY_FLOOR_PERCENT`, mirroring
  `config('hris.pay_floors')` as percentages) purely as a courtesy — the server is the
  actual authority, and a `422 pay_rate_below_floor`'s `details.violations` is what the
  screen renders against the offending cells after the fact.
- `CompanySeeder` seeds one default version, effective `2026-01-01`, every cell at exactly
  the statutory floor, `created_by` the seeded System Admin — so M5 has a version to read
  and `/admin/pay-rules` is non-empty on a fresh `make dev`.
- Every create/delete is logged the same way M4a/M4b's tables are: `PayRule`'s own
  `LogsActivity` trait (log name `pay_rule`), the `PayRule` itself as the uuid-morph
  subject, causer resolved automatically from the authenticated guard.

**Done when:** a System Admin creates a 2026 version with regular-holiday-worked at 250%
(above the 200% floor) — accepted and logged; the same at 150% — refused `422
pay_rate_below_floor`, naming exactly that cell; a duplicate `effective_from` — `409
pay_rule_exists`; a non-admin — `403 forbidden`; the version is immutable, no `PATCH`
route at all. **No pay is computed** — the version is the third and final input M5's
compute engine will read (alongside M4a's `holidays` and M4b's schedule tables) to stamp a
worked date's `rule_version_id`.

**One seam for M5 to close — closed in M5a.** `DELETE /admin/pay-rules/{payRule}` was
unrestricted at the time this was written, because nothing read `pay_rules` yet. M5a's
`daily_attendance_summaries.rule_version_id → pay_rules(id)` FK is `ON DELETE RESTRICT`
(`02-data-model.md`), exactly as anticipated here: a *consumed* version can no longer be
deleted once any summary is stamped with it — `DeleteController` itself still issues a
plain, unconditional `$payRule->delete()`, so the refusal is the database's constraint,
not new application code. `scripts/e2e-compute.sh` proves the refusal live. (The FK
violation is not yet mapped to a friendly domain error — it surfaces as the closed
envelope's generic `500 internal_error` catch-all rather than a `409`/`422`-shaped
refusal; a nicer status is a small polish item for whoever revisits `DeleteController`
next, not a correctness gap.)

**Status: complete.** **407 backend tests** (1,335 assertions — the two tables' schema,
`StatutoryFloor`'s own cell-by-cell unit suite, and the create/list/show/delete endpoints'
coverage including the below-floor/duplicate/immutable/non-admin refusals; M4b's own docs
recorded 382, but `main` had grown past that by the time this branch forked, so 407 is
this run's real total, not a precise "+25 for M4c" delta), **19 arch tests** (unchanged
from M4a/M4b — `pay_rules` introduces no new arch-guarded invariant of its own), frontend
at **297 tests** (M4b recorded 279; `usePayRules` and the `/admin/pay-rules` matrix-editor
screen make up the difference, the same "real total, not a precise per-milestone delta"
caveat M4a/M4b's own counts carry). `lint`, `typecheck`, and `build` are green native and
inside the `make test` containers alike, no flake this run.
`scripts/e2e-pay-rules.sh` walks the whole surface — floor-valid create, the below-floor
`422` naming the exact cell, the duplicate `409`, the immutable `405`, the non-admin `403`
on both GET and POST, and the activity-log row — against the live stack. **M4 — the
configuration spine — is complete**: holiday calendars (M4a), schedules (M4b), and pay
rules (M4c) are all real, admin-editable, fully tested configuration; nothing yet reads
any of it to compute pay — that's M5's `RecomputeRange` and compute engine (below), the
first and only consumer of all three tables. What the building turned on, for whoever
picks up M5 next:

- **The one place M4c's authority model genuinely diverges from M4a/M4b, not just a
  smaller version of the same thing.** `OfficeScope` was reusable across a fourth table
  family (M4b's own lesson) because every prior M4 resource had an office to scope by,
  even indirectly; a pay rule has none, so the FormRequest-level `is_system_admin` check
  isn't a simplification of `OfficeScope` — it's a different authority question ("may this
  actor touch this kind of thing at all," not "does this actor administer this subject's
  office") answered the way M2's onboarding endpoints already answered it.
- **A floor check that never touches the database is what makes "every violation at once"
  cheap.** `StatutoryFloor::violations()` takes both matrices as plain arrays and returns
  a `list<FloorViolation>` with zero I/O — the entire below-floor path is checked and
  fully reported before `CreatePayRule`'s transaction opens, so a proposal with three bad
  cells gets three named violations in one response, never a fix-one-resubmit-find-the-
  next loop.
- **The unique-constraint-catch pattern generalizes a second time.** `HolidayExists`
  (M4a) needed a row lock first because a holiday has a parent office to lock; `PayRule`
  has no such parent, so `PayRuleExists` skips straight to "attempt the insert, catch the
  violation" — proof the pattern's real shape is "let the constraint be the guard,"
  locking being an optimization for when a cheaper pre-check would otherwise race, not a
  requirement of the pattern itself.

## M5 — Compute engine

Turns M3's punches — read through M3.6's annulments, never the raw ledger alone — into
priced daily summaries, reading M4a's `holidays`, M4b's schedule tables, and M4c's
`pay_rules`: the first and only consumer of all three. Shipped in two slices, **both
complete — M5, the compute engine, is complete**: M5a (below) computes a day from a punch;
M5b (further below) recomputes a range of already-computed days when the config that
priced them changes.

### M5a — `ComputeDailySummary` and the read-only `/me/attendance/summary`

- `daily_attendance_summaries` (one row per employee-day, `unique(employee_id, date)`,
  snapshotting `day_type`/`is_rest_day`/`scheduled_minutes`/`is_art82_exempt` as resolved
  *on that date*) plus `daily_summary_lines` (one row per non-zero priced bucket —
  `regular_day`/`regular_night`/`overtime_day`/`overtime_night`/`holiday_unworked`,
  `unique(summary_id, kind)`). Every value is integer minutes or integer basis points —
  **no pesos anywhere**. See `02-data-model.md`.
- `App\Domain\Attendance\EffectivePunches::forDate()` — the effective-ledger read M3.6's
  own roadmap entry deferred: an employee's punches for one *shift window* (the calendar
  day, or later if the schedule's end minute runs past midnight), with anything an
  `attendance_annulments` row voided excluded, expressed as minutes from that date's local
  midnight (so a post-midnight out-punch on a night shift reads as e.g. `1800`, not
  wrapped down to `360`).
- `App\Domain\Compute\DailyComputation` — the pure calculator (no DB, no config): pairs
  the punches (an odd count is `is_incomplete`, never a guess), nets out the meal break,
  slices the net total into regular vs. overtime against the resolved schedule, splits
  each of those chronologically into day vs. night, and prices each of the up to four
  resulting non-zero buckets.
- `App\Domain\Pay\PayRates` + `App\Support\PayRatesFactory` — the M4c reconciliation:
  `PayRates::statutory()` (from `config('hris.pay_floors')`, the same numbers M1's premium
  matrix tested) and `PayRates::fromVersion()` (from a real `pay_rules` version) both feed
  the *same* `App\Domain\Pay\PayMultiplier`, so a live `pay_rules` version and the
  statutory floor are priced through one function, never two.
- `App\Actions\Compute\ComputeDailySummary` — resolves the day's business context
  (employment record effective *on the date*, day type from the holiday calendar,
  schedule from `ScheduleResolver`, the effective `pay_rules` version), hands it to
  `DailyComputation`, and idempotently persists the result: `lockForUpdate()`s the
  employee row, deletes any existing summary for that day, inserts the fresh one — so
  computing the same day twice yields exactly one summary, never a duplicate.
- **The synchronous on-write trigger**, not a queued job: `RecordPunch` (the sole writer
  of `attendance_logs`) and `ApplyAttendanceAdjustment` (M3.6's adjustment applier) each
  register a `DB::afterCommit()` callback that recomputes the affected day the moment the
  *outermost* transaction commits — a direct punch, or an approved add/void/amend running
  nested inside `ApproveRequest`'s transaction. "No schedule configured yet" is caught and
  logged as the expected pre-M4 state it is; anything else still propagates.
- `GET /me/attendance/summary?month=YYYY-MM` — self-scoped (no `{employee}` variant; that
  would be an enumeration hole), the caller's own computed month, ordered by date, lines
  sorted by kind. `422 not_an_employee` mirrors `/me/attendance`'s own rule. See
  `03-api.md`.
- **`rule_version_id`'s FK is `RESTRICT`, not `SET NULL`, on delete** — the seam M4c's own
  roadmap entry (above) flagged in advance is now closed: a `pay_rules` version that has
  actually priced a real day can never be deleted out from under it.
- The frontend's `/me/attendance` gains a computed layer, additive to the raw punch
  calendar it already had (M3.5): a compact in-cell indicator (`DaySummaryIndicator`) and a
  full breakdown in a day-detail panel below the calendar (`DaySummaryDetail`), showing
  each priced line next to that day's actual punch times — never a peso, never inventing a
  number for a day nothing has computed yet.
- `CompanySeeder` gives Miguel Santos (MNL-0002) a punched ordinary day (2026-01-15,
  priced at 100%) and both Miguel and Rosa Bautista (MNL-0001, the seeded Art. 82-exempt
  manager) a punched Aug 21 — the seeded special-non-working holiday — so Miguel prices at
  130% and Rosa's exemption still collapses hers to 100%, on a fresh `make dev`.

**Done when:** an ordinary punched 8-hour day prices as one `regular_day` line at 480
minutes, 10000bp (100%, the statutory floor), stamped with the effective `rule_version_id`
(`ComputeDailySummaryTest`); the same shape worked on Aug 21 (special-non-working) prices
at 13000bp (130%) instead; a night shift's hours inside the night window price at the
compounded night-differential rate (`DailyComputationTest`); an Art. 82-exempt employee's
lines all price at 10000bp regardless of overtime or holiday; an odd punch count computes
to zero worked minutes, no lines, `is_incomplete`; recomputing the same day twice is
byte-identical; deleting a stamped `pay_rules` version is refused by the database's own
`RESTRICT`. **No pesos stored anywhere.** `scripts/e2e-compute.sh` proves the 100%/130%/
Art.-82/idempotent/`RESTRICT` chain against the live stack.

**Status: complete.** **457 backend tests** (1,580 assertions — `DailyComputation`'s own
exhaustive bp-matrix unit suite, `ComputeDailySummary`'s context-resolution and
idempotent-persistence feature tests, the on-write-trigger integration test, and
`ListMySummaryController`'s scoping/validation tests, on top of everything M0-M4c already
covered), of which **19 are arch tests** (unchanged since M4c — M5a's new action/domain
classes are covered by the same general-purpose rules, not a new arch-guarded invariant of
their own). Frontend at **313 tests** (M4c recorded 297; `useMyAttendanceSummary`,
`DaySummaryIndicator`, `DaySummaryDetail`, and the `/me/attendance` computed-layer
integration tests make up the difference). `lint`, `typecheck`, and `build` are green
native and inside the `make test` containers alike. `scripts/e2e-compute.sh` walks the
full read side of the pipeline — the 100% ordinary day, the 130% holiday, the Art.
82-exempt employee's flat 100%, a live direct-recompute idempotency check, and the
`RESTRICT` refusal via `psql` — against the live stack.

M5a left two items deferred rather than solved silently, both of which M5b (below) closes:
the consecutive-night-shift window-overlap case `EffectivePunches::forDate()`'s own doc
comment flagged, and the fact that M5a's only writer of `daily_attendance_summaries` was
the synchronous on-punch trigger — there was no batch or on-demand recompute yet.

### M5b — `RecomputeRange`: an audited, queued recompute of existing summaries

Closes the two items M5a deferred, and with them, **M5 — the compute engine — is
complete.**

- **The consecutive-night-shift window fix.** `App\Domain\Attendance\EffectivePunches`'s
  `windowStartMinutes()` now bounds day N's window start at day N-1's resolved window end
  (when that previous shift ran past midnight) instead of always starting at `0`, and
  `forDate()` treats a bounded start as exclusive — so a *repeating* cross-midnight shift's
  consecutive daily windows tile instead of overlapping, and a punch timestamped exactly on
  the boundary is claimed by exactly one of the two dates, never both, never neither. See
  `02-data-model.md`.
- `daily_attendance_summaries.office_id` — a new nullable, `on delete set null` column,
  snapshotting the employee's resolved office *at compute time*, the same snapshot
  discipline `attendance_logs.office_id` already uses. Indexed `(office_id, date)`. Exists
  so a config change can find "every existing summary for office X" without an
  effective-dated join back through `employment_records` on every row. See
  `02-data-model.md`.
- `recompute_runs` — one row per `RecomputeRange::dispatch()` call: `trigger_type` (a
  `CHECK`-constrained closed set — `holiday`/`pay_rule`/`shift_template`/
  `schedule_assignment`/`schedule_override`/`office_default` — mirroring
  `App\Domain\Compute\RecomputeTrigger`), `trigger_id`, a human-readable `reason`,
  `pair_count`, `batch_id` (Laravel's own `Bus::batch` id, written back after dispatch),
  `status` (`queued`→`completed`/`failed`), `caused_by`. `Spatie\Activitylog\Traits\LogsActivity`
  on the model. See `02-data-model.md`.
- `App\Domain\Compute\AffectedSummaries` — `forHoliday`/`forPayRule`/`forShiftTemplate`/
  `forEmployee`/`forOffice`, each resolving a config change to the `(employee_id, date)`
  pairs of **existing** summaries it could affect. Over-inclusion is deliberately safe
  (`ComputeDailySummary` is idempotent), so these deliberately don't try to narrow to the
  exact dates a schedule/office change touches — completeness matters, precision doesn't.
- `App\Actions\Compute\RecomputeRange::dispatch(pairs, trigger, triggerId, reason,
  causedBy): ?RecomputeRun` — dedups the incoming pairs, no-ops (returns `null`, writes
  nothing) on an empty set, otherwise creates the `queued` `recompute_runs` row and
  dispatches a named `Bus::batch()` of `App\Jobs\RecomputeDay` — one job per pair — flipping
  the row to `completed`/`failed` from the batch's own `->then()`/`->catch()`.
- `App\Jobs\RecomputeDay` — `ShouldQueue` + `Batchable` + `InteractsWithQueue`, carrying
  only `$employeeId`/`$date` (never a model, which would go stale between dispatch and
  execution). A strict no-op over a cancelled batch, a deleted employee, or — the one that
  matters for M7 — an existing summary already `status: 'locked'`. Otherwise calls
  `ComputeDailySummary::execute()`, unchanged from M5a.
- **Every config-change action wires the same `DB::afterCommit(() =>
  RecomputeRange::dispatch(...))` shape**, mirroring `RecordPunch`'s own on-write trigger:
  `Holidays\CreateHoliday`/`UpdateHoliday`/`DeleteHoliday`/`CloneHolidays`,
  `PayRules\CreatePayRule`, `Schedules\CreateShiftTemplate`/`UpdateShiftTemplate`/
  `DeleteShiftTemplate`, `Schedules\CreateScheduleAssignment` and the inline
  `DeleteAssignmentController`, `Schedules\CreateScheduleOverride`/`UpdateScheduleOverride`/
  `DeleteScheduleOverride`, and the inline `SetDefaultTemplateController`. See
  `02-data-model.md` for the full trigger/resolver table.
- **The append-only ledger is never touched by any of this.** `RecomputeDay`/
  `ComputeDailySummary` only ever read `attendance_logs` (via `EffectivePunches`) and write
  `daily_attendance_summaries`/`daily_summary_lines` — the same arch guard that already
  proves `RecordPunch` is `attendance_logs`' sole writer covers a recompute by construction.
  `scripts/e2e-recompute.sh` proves it live.

**Done when:** an HR holiday edit for Manila enqueues an audited recompute
(`recompute_runs` row + a `Bus::batch` of `RecomputeDay` jobs) that flips exactly the
affected existing Manila summaries 100% → 130% and leaves Cebu's and every raw
`attendance_logs` row byte-identical; a new `pay_rules` version re-prices every existing
summary on/after its effective date; a `locked` summary is skipped; a config change with no
existing affected summaries is a clean no-op; two consecutive night shifts count each punch
exactly once. **No `attendance_logs` row is ever mutated.** **This is M4's original
"Done when" line, only now actually provable end to end** — M4a's own section named it as
the milestone's eventual proof and explicitly deferred it here.

**Status: complete.** **490 backend tests** (1,693 assertions — `recompute_runs`' schema,
`AffectedSummaries`' own resolver-by-resolver unit suite, `RecomputeDay`'s locked-skip and
idempotency coverage, `RecomputeRange`'s dedup/no-op/audited-batch coverage, and every
config-change action's own recompute-enqueue test, on top of everything M0–M5a already
covered; M5a's own docs recorded 457, but this is this run's real total, the same
"real total, not a precise per-milestone delta" caveat every milestone since M4a has
carried), of which **19 are arch tests** (unchanged since M4c/M5a — M5b's new action/domain/
job classes are covered by the same general-purpose rules, not a new arch-guarded invariant
of their own). Frontend **unchanged at 313 tests** — M5b is backend-only, no UI reads
`recompute_runs` or triggers a manual recompute. `lint`, `typecheck`, and `build` are green
native and inside the `make test` containers alike.
`scripts/e2e-recompute.sh` walks the whole surface live: a seeded ordinary day, confirmed
still `ordinary` immediately after the holiday write (proving the recompute is genuinely
queued, not synchronous), the queue drained (`php artisan queue:work
--stop-when-empty`), the flip to `special_non_working`/13000bp, the audited
`recompute_runs` row (`trigger_type: holiday`, `status: completed`, `pair_count: 1`), and
the employee's `attendance_logs` rows byte-identical — same ids, same order — before and
after. What the building turned on, for whoever picks up M6 next:

- **The window-tiling fix is a boundary-exclusivity problem, not a bigger-window
  problem.** The natural first instinct — widen day N's window to swallow whatever it
  might otherwise miss — just moves the double-claim to a different boundary. The actual
  fix is narrower: bound day N's start at day N-1's *resolved* window end (only when that
  previous shift genuinely ran past midnight) and treat that bound as exclusive, so the
  exact instant at the boundary belongs to exactly one side. A normal, non-repeating, or
  rest-day previous day leaves the start at `0`, unchanged from M5a.
- **Over-inclusion has to be a deliberate design choice you can point to, not just an
  accident that happens not to break anything.** `AffectedSummaries::forShiftTemplate`/
  `forOffice`/`forEmployee` recompute every existing summary for the affected employees,
  full stop — no attempt to narrow to the exact dates a schedule change touches. That's
  only safe because `ComputeDailySummary` is idempotent by construction (M5a); the
  docblock says so explicitly rather than leaving a future reader to wonder whether the
  breadth was intentional.
- **`RecomputeDay` needs *both* `Batchable` and `InteractsWithQueue`, not just the one the
  cancellation check obviously needs.** `CallQueuedHandler::ensureSuccessfulBatchJobIsRecorded()`
  silently declines to call `$batch->recordSuccessfulJob()` unless both traits are present
  — without it, every batch containing this job sits at `pending_jobs > 0` forever and
  `RecomputeRange`'s `->then()` callback (the only thing that ever marks a
  `recompute_runs` row `completed`) never fires. Caught by `RecomputeRangeTest` actually
  asserting the row flips to `completed`, not just that the batch was dispatched.
- **A container/native PHP `memory_limit` ceiling, not a test-logic failure, is worth
  knowing about before assuming `make test`/`./vendor/bin/pest` broke.** Both the native
  host's stock `php.ini` and the dev image's FrankenPHP default ship `memory_limit=128M`;
  Pest's Arch-layer docblock scan across the whole (now-larger) `App` namespace exceeds
  that ceiling deterministically, failing inside `phpstan/phpdoc-parser`/Collision's own
  error renderer rather than in any actual assertion — a local-environment ceiling, not a
  regression in this milestone's code (490/490 pass with the ceiling raised), and CI is
  unaffected: `shivammathur/setup-php`'s runner default is `memory_limit=-1`. **`make
  test-backend` now runs pest as `php -d memory_limit=512M vendor/bin/pest`**, so the
  containerized gate works out of the box; a native `./vendor/bin/pest` run relies on the
  developer's own `php.ini` (most dev machines don't cap at 128M).
- **`04-backend-conventions.md`'s locked-skip-vs-real-lock distinction is not automatically
  safe just because a lock will eventually exist.** `RecomputeDay`'s `$existing?->status ===
  'locked'` check is a plain, unlocked read — correct today because nothing else races to
  set that status yet. M7's `CloseCutoff` changes that: once it actually
  `lockForUpdate()`s and locks summary rows, the close and a `RecomputeDay` racing the same
  row become a genuine concurrency question, needing the same two-real-Postgres-connections
  proof `ApproveRequestConcurrencyTest` set the precedent for (M3.6) — a single-process test
  would pass whether or not a lock exists, which is worse than no test. Flagged here, in
  `02-data-model.md`, and repeated at M7's own section below so it isn't missed twice.

## M6a — The approval spine *(done)*

The first slice of the old single "M6 — Requests and approvals" milestone: turn M3.6's
attendance-only approval into a reusable, **still single-step** request spine with two
scope-based approval queues, and give it its first full browser UI — proven end to end with
attendance adjustments, the only request type that exists yet. Slicing: **M6a spine → M6b-a
leave foundation → M6b-b leave requests → M6c overtime pre-auth** — see
`docs/superpowers/specs/2026-07-26-m6a-approval-spine-design.md`. (M6b split into two slices
once M6b-a began — see M6b-a's own section, below, for why.)

- **Per-type effect dispatch.** `ApproveRequest` no longer hardcodes
  `ApplyAttendanceAdjustment` — it resolves a `RequestEffect` by `RequestType` through
  `RequestEffectFactory`, still inside the same row-locked transaction. Adding a request
  type is now "write an effect and register it in the factory," not "touch the approve
  action."
- **Two scope-filtered queues replace the one combined pending list.** `/team/approvals`
  (an actor's direct reports) and `/office/approvals` (an actor's HR-administered offices)
  are independent `ApprovalQueues` views over the same `RequestAuthority::canDecide`-shaped
  set — not a partition of it, so a manager who is also their office's HR admin sees the
  same pending request on both. Neither is type-specific: a future leave or overtime
  request appears on both automatically. A system-admin-only account (no employee record)
  gets neither — no reports, no HR offices, no queue at all, and that's correct, not a bug.
- **The read/decision surface generalized onto `/requests/*`.** `GET/POST
  /attendance/adjustments/{request}/*` moved to `GET/POST /requests/{request}/*`; `POST
  /attendance/adjustments` (submit) stayed where it was — submission is irreducibly
  type-specific (what fields a correction needs isn't what a leave request needs), so it's
  the one route this milestone left alone. See `03-api.md`.
- **The full correction-filing vertical, in the browser, for the first time.** A form off
  `/me/attendance` to file add/void/amend with a note and optional attachment; `/me/requests`
  to see every request you've filed and withdraw a pending one; `/team/approvals` and
  `/office/approvals` sharing one `<RequestCard>` and one optimistic-decide hook
  (`useDecideRequest`), confined to the queue the way M5's design called for — a short list,
  a status flip, an obvious rollback on failure.

**Explicitly deferred, not solved silently:**

- **The multi-step machine.** `draft → submitted → manager_approved → hr_approved →
  approved`, the `requires_hr_step` flag, and that whole vocabulary do **not** exist yet.
  M6a kept M3.6's single step, `pending → approved | rejected | cancelled` — one authorized
  approver decides, full stop. Leave is the first type that actually needs a second hop
  (manager, then HR), so the machine widens with M6b-b, not before it's needed.
  `requests.state`'s CHECK constraint is unchanged; no migration landed in M6a.
- Leave types, `leave_ledger`, derived balances, manual grants → **M6b-a**, below (done).
- The leave request itself and the two-hop machine → **M6b-b**, below (done).
- Overtime pre-authorization and the `min(actual, approved)` compute integration →
  **M6c**, below (done).

**Done when:** an employee forgets to clock out, the day shows zero hours and
`incomplete`; they file an add adjustment for the missing punch; the request shows up on
both their manager's `/team/approvals` and their office HR's `/office/approvals`; the
manager approves it through `/requests/{id}/approve`; the day recomputes synchronously to
the correct breakdown; and the pre-existing `attendance_logs` rows are byte-identical to
what they were before — `scripts/e2e-requests.sh` proves exactly this, live. **496 backend
tests (22 of them Arch) + 359 frontend tests**, all green, `lint`/`typecheck`/`build` clean
native and inside `make test`'s containers alike.

## M6b-a — The leave foundation *(done)*

The first slice of the old single "M6b — Leave" milestone: the config and ledger a leave
*request* (M6b-b, below) will need before it can exist — per-office leave types, the
minutes-per-leave-day conversion, an append-only ledger, derived balances, and HR's ability
to manually grant into one. Nothing here files a request, approves one, or debits a balance
by taking leave — that's the whole reason it's a separate slice, the same way M6a split the
approval spine from the leave type it exists to serve.

- **Leave types, configurable per office.** Paid/unpaid, requires attachment, deducts from
  balance vs. an event entitlement that doesn't (`deducts_balance`), convertible to cash,
  max carryover — `GET/POST /office/leave-types`, `PATCH /office/leave-types/{leaveType}`,
  `OfficeScope`-scoped the same as holidays and schedules. No delete route: a retired type
  is `is_active: false`, never removed. `CompanySeeder` seeds every office with the PH
  statutory set — SIL (Art. 95, cash-convertible), Maternity (RA 11210), Paternity
  (RA 8187), Solo Parent (RA 11861), VAWC (RA 9262), Magna Carta (RA 9710) — plus company
  VL/SL, with every balance starting empty.
- **`offices.minutes_per_leave_day`** (default 480), `PATCH /office/leave-day`, and
  `App\Domain\Leave\LeaveUnit` converting a request's day/half-shift/hour/minute amount
  into the integer minutes everything downstream stores.
- **`leave_ledger`: every credit is a row with a reason, append-only, exactly the way
  `attendance_logs` is.** Balances are derived (`App\Domain\Leave\LeaveBalances`), never
  stored as a mutable number, for the same reason POS made stock a ledger — a number that
  can drift from its own history is the bug, not a feature.
- **`POST /leave/grants`: HR manually crediting a balance.** One logged credit row per
  grant, scoped `OfficeScope::administers` against the employee's *current office* (not
  `EmployeeScope`, which would also let a manager grant into their own reports) — HR-only,
  by design. Granting an event type (`deducts_balance: false`) is refused, `422
  leave_type_not_grantable`, not a silent no-op.
- **Balance reads**, self (`GET /me/leave`) and overseen (`GET
  /employees/{employee}/leave`, `EmployeeScope`), both returning raw minutes and a readable
  `{days, hours, minutes}` decomposition, 404-not-403 on an out-of-scope employee.
- **`leave.manage`** seeded onto `HR Admin` (`05-rbac.md`) — cataloged the same way
  `holiday.manage`/`schedule.manage` are; the actual boundary enforced by every route above
  is `OfficeScope`, not a `can()` check.
- Frontend: `/office/leave-types` (HR config), `/me/leave` (balances, readable decomposition),
  an HR grant form, nav wiring — no new UI shell, `<Tag>`/`<StatTile>`/`<EmptyState>` reused
  from the M4/M6a work already in place.

**Explicitly deferred, not solved silently:**

- **Taking leave.** There is no way for an employee to file a leave request yet — that's
  M6b-b, below. M6b-a's grant is HR pushing minutes onto a balance, never an employee
  pulling from one.
- **The multi-step machine.** `draft → submitted → manager_approved → hr_approved →
  approved`, the `requires_hr_step` flag, and that whole vocabulary do **not** exist yet —
  M6a's single-step `pending → approved | rejected | cancelled` has nothing to widen until
  M6b-b's leave request actually needs the second hop. `requests.state`'s `CHECK` constraint
  is unchanged; no migration landed in M6b-a.
- **Accrual, carryover, and cash-out.** The schema supports all three (`max_carryover_minutes`,
  `is_cash_convertible`) but no job reads them yet — every balance moves only by a manual
  HR grant.
- **Compute integration.** The engine does not read `leave_ledger` at all; a day taken as
  leave prices exactly as it did before this milestone, because nothing files one yet.

**Done when:** HR configures a per-office leave type, confirms the office's leave day,
manually grants an employee 5 days as one 2400-minute credit row, that employee reads the
identical balance back decomposed into `{days: 5, hours: 0, minutes: 0}`, and a grant
attempted against an event type is refused 422 with nothing written —
`scripts/e2e-leave-foundation.sh` proves exactly this, live. **551 backend tests (22 of
them Arch) + 389 frontend tests**, all green, `lint`/`typecheck`/`build` clean native and
inside `make test`'s containers alike.

## M6b-b — Leave requests and the two-hop approval machine *(done)*

The second slice: an employee actually files a leave request against a type from M6b-a's
catalog, and the approval machine widens for the first time since M6a, because leave is the
first request type that genuinely needs a second hop.

- **The two-hop machine.** `pending → [manager_approved →] approved | rejected |
  cancelled` — simpler than the plan's original speculative `draft → submitted →
  manager_approved → hr_approved → approved` vocabulary; the requests spine never had a
  `draft`/`submitted` distinction to begin with (a request is filed directly, M3.6), so
  widening it only needed the one intermediate state. `App\Domain\Requests\RequestType::
  requiresHrStep()` — a method on the enum, not a stored per-row flag — decides whether the
  second hop exists at all: `false` for `attendance_adjustment` (unchanged, still one
  decision), `true` for the new `leave` type. `RequestEffect` and the two scoped queues
  (`/team/approvals`, `/office/approvals`) are unchanged in shape; leave plugs in as a new
  `RequestType` and a new effect (`LeaveEffect`), the same shape M6a's design proved out —
  `/team/approvals` stays `pending`-only, `/office/approvals` is hop-aware (a single-hop
  type from `pending`, any type from `manager_approved`).
- **Per-hop authority, not just per-request.** `RequestAuthority::canDecide` is now state-
  and type-aware: the manager alone at `pending` on a two-hop request, HR alone (and never
  the hop-1 decider) at `manager_approved` — a genuine two-person rule, since a manager who
  is also their own office's HR admin still cannot clear both hops of one request
  themselves. `CancelRequest` (requester-only) widened alongside it: withdrawable from
  `pending` OR `manager_approved`, never stuck once a manager has signed off. See
  `05-rbac.md` for the full argument.
- **Filing debits nothing until fully approved.** The ledger only ever gains a debit row
  (`leave_ledger.source: 'leave_taken'`) once the chain reaches `approved` — never on
  `pending` or `manager_approved` — so a request rejected at either hop never touched the
  balance it would have spent. `LeaveEffect` locks the EMPLOYEE row (not just the request
  row `ApproveRequest` already locks) before reading the balance, since two different leave
  requests for the same employee are two different rows the request-level lock alone
  cannot serialize; a debit that would overdraw throws `InsufficientLeaveBalance` (`422`)
  and rolls the whole approval back.
- **Compute integration, folded in from the plan's original "later" note.** Once final
  approval fires, the approved span recomputes (`RecomputeTrigger::Leave`) and each
  no-punches, non-rest, scheduled day in it prices as a new `leave_with_pay` summary line —
  a flat 100%, resolved from `App\Domain\Leave\LeaveDayLookup` rather than any `pay_rules`
  version, so it persists even with none configured for the date and never sets
  `rule_version_id`. See `02-data-model.md` for the schema and the corrected
  `rule_version_id` invariant this required.
- A leave request reuses `<RequestCard>` and both approval queues — no new UI shell, only a
  new type-specific submission form (mirroring the M6a correction form) and a new
  `manager_approved` "Awaiting HR" tag, the same "submission stays type-specific, everything
  after doesn't" split M6a established.

**Explicitly deferred, not solved silently:**

- **Half-day compute.** `leave_details.day_part: 'half'` is stored and debits half a day's
  minutes, but `ComputeDailySummary` prices a `leave_with_pay` day at the full scheduled
  minutes regardless of `day_part` — a half-day leave debits correctly but computes as if
  it were a full day. Deferred to M7, alongside the compute-side day-part read.
  `LeaveType.is_paid` similarly still goes unread by compute (unpaid leave still prices
  `leave_with_pay` at 100%) — a pre-existing, systemic gap M6b-b did not create or close.
- **A payroll-facing export of leave-with-pay days.** The line exists in
  `daily_summary_lines`; nothing yet reads it into a payroll-shaped output — that's M7's
  job, once cutoffs and export exist to read it into.
- **N-step approval chains.** Two hops (manager, then HR) is as deep as
  `requiresHrStep()` goes; a chain longer than two is not modeled and has no request type
  that would need it yet.
- Accrual, carryover, and cash-out remain M6b-a's deferred items, untouched here — a leave
  request debits an existing balance; nothing yet grows one automatically or converts it to
  cash.

**Done when:** an employee requests leave, their manager approves the first hop, HR
approves the second (final) hop, the leave ledger debits the balance — never before that
final approval — and the compute engine prices the approved days as paid leave alongside
attendance the way an approved adjustment already prices a corrected punch.
`scripts/e2e-leave.sh` proves it live: HR grants a balance, the employee files a
3-scheduled-working-day request, it shows on the manager's queue and not HR's, the
manager's decision moves it to `manager_approved` with the balance and the raw debit-row
count completely unchanged and moves it onto HR's queue, HR's final decision debits the
ledger and triggers the recompute that prices the span `leave_with_pay`, and a second,
independent request rejected at HR's hop leaves the balance and the debit-row count
untouched. **630 backend tests (22 of them Arch) + 419 frontend tests**, all green,
`typecheck`/`build` clean native and inside `make test`'s containers alike.

**M6 — requests and approvals — is complete**: M6a's single-step spine, M6b-a's leave
foundation, M6b-b's leave request and two-hop machine, and M6c's overtime pre-authorization
are all done. M6c reused the exact same spine, queues, and per-hop authority model a third
time — a new `RequestType` and `RequestEffect`, no state-machine change — the clearest proof
yet that the M6a spine generalizes.

## M6c — Overtime pre-authorization *(done)*

- Overtime pre-authorization. The engine pays `min(actual_worked, approved)` and surfaces
  the remainder as **unpaid excess time** — visible, never silently converted to money
  (`daily_attendance_summaries.unpaid_overtime_minutes`).
- A new single-hop `RequestType` (`overtime`) and `RequestEffect` (`OvertimeEffect`, which
  writes nothing — the approved request's `overtime_details.minutes` IS the authorization
  the compute engine reads), same spine, same two queues, same card — the pattern M6a proved
  and M6b-b exercised a second time. No `requests.state` change; the single approval is the
  final hop.

**Status: done.** An employee files `POST /overtime/requests` for their own record (un-gated,
like leave and adjustments); because overtime is single-hop it appears at once on both the
manager's `/team/approvals` and office HR's `/office/approvals`; the single approval enqueues
a recompute that re-prices the day at `min(actual, approved)`, booking anything beyond the
cap as `unpaid_overtime_minutes` — the strict model, where unauthorized overtime pays zero.
Art. 82-exempt employees short-circuit the premium entirely. **656 backend tests (19 of them
Arch) + 430 frontend tests**, all green. `scripts/e2e-leave-and-ot.sh` proves it live: two
identical long days — the first capped by a 1-hour pre-authorization to exactly the approved
minutes with the excess booked unpaid, the second with no request paying zero and booking its
full overtime unpaid — and `scripts/e2e-leave.sh` running unchanged alongside it, proving the
leave and overtime paths coexist on one stack.

Next: **M7 — cutoffs, locking, and payroll export.**

## M7 — Cutoffs, locking, and payroll export *(complete)*

The milestone that makes the number defensible. **Complete:** M7a shipped the
close/lock/reopen spine, M7b the payroll export that reconciles line-for-line against the
calendar — the full "Done when" below is met.

- `cutoff_periods` per office. Semi-monthly by default (1–15, 16–EOM), which is what
  most PH employers actually run.
- `CloseCutoff` refuses while unresolved exceptions remain — incomplete days, pending
  adjustments on in-period dates. Closing sets the period's summaries to `locked`.
- `ApproveRequest` must `lockForUpdate()` the affected summaries and refuse on a locked
  period; `CloseCutoff` locks the period row first. **This race needs the two-real-
  connections test** `04-backend-conventions.md` demands — a single-process test passes
  whether or not the lock is even there, which makes it worse than no test.
- **Forward note from M5b, repeated here so it isn't missed twice:** `App\Jobs\RecomputeDay`'s
  locked-skip (`$existing?->status === 'locked'`) is a plain, unlocked read — safe in M5b
  only because nothing else races to set that status yet. Once `CloseCutoff` exists and
  actually locks summary rows, a close racing a `RecomputeDay` over the same row is a real
  concurrency question: `CloseCutoff` needs its own `lockForUpdate()` over the summaries
  it's closing, and the close-vs-recompute race needs the same genuine
  two-Postgres-connections proof `ApproveRequestConcurrencyTest` already set the precedent
  for (M3.6) — not a same-process sequential test, which would pass regardless of whether
  the lock exists. See `02-data-model.md`'s M5b section for the full reasoning.
- `ReopenCutoff` exists, requires a reason, and is loudly audited.
- Payroll export: per employee per period, the full earnings breakdown — regular, late,
  undertime, OT, night differential, holiday premium, leave with pay — in integer minutes
  and basis points, with the `rule_version_id` that produced each line.

**Done when:** close a period; an approval on a locked day is refused with a domain error
rather than silently succeeding; the export reconciles line-for-line against the calendar
view; and `make restore-drill`-style, a recompute of a closed period returns byte-identical
numbers.

## M7a — Cutoffs and locking *(done)*

The first half of M7: `cutoff_periods` and the close/lock/reopen spine. Payroll export — the
rest of the "Done when" above — is **M7b**, next.

- `cutoff_periods`, one row per office per semi-monthly window (1–15, 16–EOM — the
  `CutoffCalendar` rule). A period is `open` or `closed`; a row only comes into existence the
  first time `CloseCutoff` touches its window, so `GET /office/cutoffs` synthesizes the
  current still-running window (unpersisted, `id: null`) rather than show a gap. There is no
  FK from a summary to a period — the two relate derived-ly, by office + date range; see
  `02-data-model.md`.
- `CloseCutoff` runs a strict exception gate first — any in-period `is_incomplete` day or any
  non-terminal (`pending`/`manager_approved`) request whose effect maps onto an in-period date
  refuses the close with `cutoff_has_unresolved_exceptions`, listing exactly what to resolve —
  and otherwise flips every in-period `daily_attendance_summaries.status` to `locked` and the
  period to `closed`, in one transaction. Re-closing a closed period is `cutoff_already_closed`
  (409); a non-boundary `period_start` is `invalid_cutoff_start` (422).
- `ApproveRequest`, on its final hop, calls `CutoffGuard::assertOpen` and refuses with
  `cutoff_locked` (422) any approval whose effect would change a day in a closed period. The
  remedy is to reopen the period, never to force the approval through.
- `ReopenCutoff` is the exact inverse: it requires a reason, is loudly audited (a
  `cutoff_reopened` activity-log entry carrying the reason), flips the period back to `open`
  and every in-period `locked` summary back to `computed`.
- **The locking spine is one per-employee `Employee` row lock.** `CloseCutoff`, `ReopenCutoff`,
  `ApproveRequest` (final hop), and `RecomputeDay` all `lockForUpdate()` the affected
  `employees` row before touching that employee's summaries — so a close, an approval, and a
  recompute for the same employee serialize against each other rather than interleave. Two
  genuine two-real-Postgres-connections proofs pin this down, the kind
  `04-backend-conventions.md` demands (a single-process sequential test would pass whether or
  not the lock exists): `CloseVsApproveConcurrencyTest` (a close racing a final-hop approval —
  exactly one wins; the loser sees the committed state and refuses cleanly) and
  `CloseVsRecomputeConcurrencyTest` (a close racing a `RecomputeDay` over the same summary —
  the freeze and the recompute never interleave). `ComputeSkipsClosedPeriodTest` proves the
  period-aware guard keeps a closed period's summaries immutable to any future recompute.

**KNOWN LIMITATION (deferred):**

- **A first-ever compute racing a close, for an employee with ZERO in-period summaries, can
  leave a `computed` (unlocked) row inside a closed period.** `CloseCutoff` locks and freezes
  the summaries that exist when it runs; an employee whose very first summary for that window
  is computed *after* the close began — and who therefore had no row for the close to lock —
  is not covered by the per-employee lock (there was nothing to lock). Low severity: the
  period-aware recompute guard still makes that row immutable to any *future* recompute, so the
  value is correct — only the status label reads `computed` rather than `locked`. A
  sweep-on-first-compute is deferred. Two nuances for M7b: (a) that leaked row can be
  `is_incomplete=true`, so a closed period could contain an incomplete day the close gate was
  meant to forbid — the label is wrong AND the day may be unfinished; and (b) **M7b's payroll
  export must therefore key off period MEMBERSHIP (office + date range), NOT the `status='locked'`
  label** — a membership-based selection still captures a leaked `computed` row, whereas a
  status-based one would silently exclude it from the export.
- **Adjustment cross-midnight business-date imprecision in `RequestAffectedDates`.** It
  resolves an attendance adjustment to the punch's office-timezone *calendar* date, which for a
  cross-midnight shift can differ by a day from the *business* date its summary is keyed by.
  Safe for the close gate (over-inclusion only), imprecise only for the `cutoff_locked` refusal
  of a cross-midnight punch within a day of a period boundary. Precise business-date
  attribution for adjustments is deferred.

**Status: done.** `scripts/e2e-cutoffs.sh` proves it live against the seeded stack: Manila HR's
attempt to close the second-half July window is refused (`cutoff_has_unresolved_exceptions`,
naming the seed's one incomplete day); the clean first-half window closes, freezing every
in-period summary `computed` → `locked` while the second half stays `computed`; re-closing is
`cutoff_already_closed` and a non-boundary start `invalid_cutoff_start`; a manager's approval
onto a locked in-period day is refused `cutoff_locked` and the request stays pending; reopening
flips every summary back to `computed` and lets the same approval succeed; and the requester's
raw `attendance_logs` are byte-identical before and after — the append-only ledger untouched.
**705 backend tests (19 of them Arch) + 442 frontend tests**, all green.

Next: **M7b — payroll export.**

## M7b — Payroll export *(done)*

The second half of M7: the read-only export of a closed period's frozen numbers, per employee,
into an earnings breakdown in integer minutes + basis points. No migration — it reads the
`daily_attendance_summaries` and `daily_summary_lines` M5 already froze; a domain-Eloquent
wrapper (`App\Domain\Payroll\PayrollExport`) rolls them up, `GET /office/cutoffs/{period}/export`
serves them, and a Carbon review screen renders them.

- **Reads by period MEMBERSHIP, not the `locked` label.** The aggregator selects summaries by
  `office_id` + the period's date range, never by `status = 'locked'` — so M7a's known-limitation
  leaked `computed` row (a first-ever compute that raced a close) still appears in the export
  rather than being silently dropped, and `has_incomplete_days` flags an employee whose in-period
  window holds an `is_incomplete` day.
- **Reconciles line-for-line.** Each `lines[]` entry is a summed-minutes `(kind, applied_bp,
  rule_version_id)` triple, where `rule_version_id` is the parent day's version (a summary line
  carries none of its own) — so the export is a faithful roll-up of the calendar, provable by
  summing `/me/attendance/summary` over the in-period dates and comparing. The four totals
  (`worked`/`late`/`undertime`/`unpaid_overtime`) are the summed day scalars.
- **Hours + basis points, not pesos.** `base_rate_cents` (the period-end effective rate) and
  `base_rate_segments` (the distinct effective employment records that priced in-period days)
  ride along as *reference only* — gross-to-net is downstream, out of scope. The export prices
  no earnings; it hands payroll the hours and the multipliers.
- **Closed-only.** An export is defined only for a finalized period; an `open` one (including the
  synthesized current window, or a just-reopened period) is refused `422 period_not_exportable`.
  Foreign/nonexistent periods are `404`-not-403, like the cutoff routes. A closed period's numbers
  are frozen, so the export is **reproducible** — two calls return a byte-identical `data` payload.

**Deferred (not in M7b):** CSV / file download (the export is JSON only — a spreadsheet/PDF
surface is later); **peso gross earnings** (the export is minutes + bp; multiplying by the rate is
downstream payroll); an **open-period draft** export (closed-only by decision); and a **full-roster
export** including zero-attendance employees (the export lists only employees with in-period
summaries — an employee with no computed day for the window does not appear).

**Status: done.** `scripts/e2e-payroll-export.sh` proves it live against the seeded stack: Manila
HR closes the clean first-half July window and exports it; every `(kind, applied_bp,
rule_version_id)` line and every total reconciles EXACTLY against Miguel's own calendar summed over
the in-period dates; a second export of the still-locked period is byte-identical; reopening the
period makes the export refuse `422 period_not_exportable`; and a nonexistent period is
404-not-403. **715 backend tests (19 of them Arch) + 460 frontend tests**, all green.

Next: **M8 — admin portal and audit.**

## M8 — Admin portal and audit

- Organization, office, and department CRUD; the multi-step employee profiler
  (`<Wizard>`); role management; `hr_admin_offices` assignment; activity-log viewer with
  filters by actor, subject, and action.
- **Archive, never delete.** No `DELETE` route anywhere under `/admin/*`, carried over
  from POS — an employment record is a legal document with a retention obligation, not a
  row someone gets to remove.

**Done when:** a company can be configured from an empty database entirely through the
UI, and the audit log shows every step of it.

**Status: complete.** All three slices shipped — M8a (the organization tree), M8b (the
employee profiler), and M8c (HR-admin roles/scope + the audit viewer). The done-when is
met: a System Admin can build a company from an empty database through the org-tree and
profiler UIs, grant office-admin access to the people who run it, and read the whole audit
trail — every create, edit, archive, and grant — through the activity-log viewer. **771
backend tests (19 of them Arch) + 541 frontend tests**, all green.

## M8a — Organization tree CRUD *(done)*

The first slice of M8: the company's shape — `organizations` → `offices` → `departments`
(`02-data-model.md`) — made admin-editable at runtime, System-Admin only, with a Carbon
admin screen per tier. The tables already existed (M2); M8a adds runtime CRUD, the
archive-never-delete lifecycle, and audit.

- **CRUD, `is_system_admin`-gated, not `OfficeScope`.** `POST/GET/PATCH /admin/organizations`,
  `/admin/offices`, `/admin/departments`, each an Action-class-per-route behind a
  `FormRequest::authorize()` that is the one-line `(bool) $this->user()?->is_system_admin` —
  the same idiom as pay rules (M4c). An office/organization cannot scope-check itself, so
  there is no `OfficeScope` to apply. Lists filter by parent (`?organization`, `?office`).
- **Archive-never-delete, non-cascading.** No `DELETE` route on any tier (the M8-wide rule).
  Retiring an office/department stamps a nullable `archived_at` — `POST
  /admin/{offices,departments}/{id}/archive|unarchive` — and nothing else: the row, its
  children, and every `employment_record`/`current_*` snapshot pointing at it stay intact, so
  a closed office's payroll history and inspector-facing ledger survive. Lists hide archived
  rows by default and reveal them with `?include_archived=1`. Re-archiving is `409
  already_archived`; un-archiving a live row is `409 not_archived`. Organizations have no
  archive (nothing sits above them). Duplicate codes are clean `422`s
  (`duplicate_office_code`, global; `duplicate_department_code`, per-office) — a pre-check
  plus a caught unique-violation backstop, never a raw `500`.
- **The deliberate 403-not-404 exception.** A non-admin gets `403 forbidden` on every verb,
  not the `404`-not-`403` treatment the office-scoped HR endpoints use — the org tree is
  global config with no subject to scope by, so there is nothing to hide behind a `404`
  (`05-rbac.md`).
- **Audited.** `Organization`/`Office`/`Department` carry spatie's `LogsActivity` (log names
  `organization`/`office`/`department`); every create/update/archive/unarchive writes an
  `activity_log` row — the acting admin the causer — so a runtime change to the company's
  shape is auditable even before the M8 activity-log viewer ships.
- **Frontend.** A Carbon admin nav + a screen per tier (create/edit, the archived toggle,
  archive/un-archive), reusing the M4c pay-rules screen's data-layer and table patterns.

**Done when:** a System Admin can build an organization → office → department subtree,
archive and un-archive a node, and see the audit rows — entirely through the API/UI.
`scripts/e2e-admin-org.sh` proves it live against the seeded stack: the seeded System Admin
creates an organization, an office under it (a duplicate office code refused `422
duplicate_office_code`), and a department under that — each appearing in its own `GET` list;
the office and department creates each write an `activity_log` row (asserted via `psql`,
there being no viewer yet); archiving the department drops it from the default list while
`?include_archived=1` still shows it, re-archiving is refused `409 already_archived`, and
un-archiving brings it back; and a plain employee's `POST /admin/offices` is refused `403`.
**742 backend tests (19 of them Arch) + 488 frontend tests**, all green.

Next: **M8b — the employee profiler** (the multi-step `<Wizard>`: identity, employment,
login provisioning), then role management, `hr_admin_offices` assignment, and the
activity-log viewer.

## M8b — Employee profiler *(done)*

The second slice of M8: onboarding a **person**, System-Admin only. Through M8a an employee
was an `employee_no` and a set of foreign keys — every screen that wanted a human label fell
back to `MNL-0001` (the gap M7b's payroll export and M8a's org screens both hit). M8b gives
the employee a name and a full onboarding surface.

- **The employee now has a name.** Four columns — `first_name`, `middle_name`, `last_name`,
  `name_suffix` (Filipino convention: middle is the mother's maiden surname, suffix carries
  Jr./III) — plus one read model, the `Employee::full_name` accessor that composes them and
  collapses the gaps a null middle/suffix leaves (`02-data-model.md`). Every API name field
  reads that one accessor, closing the `employee_no`-fallback gap left open since M7b/M8a.
- **CRUD, `is_system_admin`-gated, Action-class-per-route.** `POST /admin/employees` (onboard,
  now with the name fields + an optional first `employment` block recorded through
  `RecordEmploymentChange` in the same transaction), `GET /admin/employees[?office=]` (the
  company-wide roster, each row carrying `full_name` and `has_user`), `GET
  /admin/employees/{id}` (name + `has_user` + the `EmploymentResolver`-resolved current
  employment — office/department/base_rate — never the raw cache), `PATCH /admin/employees/{id}`
  (edit the name only), and `POST /admin/employees/{id}/user` (provision a login) — same
  `(bool) $this->user()?->is_system_admin` idiom as M8a/pay rules (`03-api.md`).
- **`employee_no` is immutable.** The PATCH surface has no `employee_no` field: identity is
  set once at creation and never renamed — a change to who a person *works as* is an
  employment change, not an edit.
- **The 403-vs-404 split.** A non-admin gets `403 forbidden` on the subject-less verbs
  (`POST`/`GET`/`PATCH /admin/employees`) — the global-admin exception to 404-not-403, as on
  the org tree — but `404` on `POST …/{id}/user`, whose subject id sits in the URL, so a
  status split would leak which employee ids exist (`03-api.md`).
- **Audited.** `Employee` now carries spatie's `LogsActivity` (log name `employee`, logging
  the name fields, `employee_no`, `organization_id`, `hired_at`, `separated_at`): onboarding
  and every rename write an `activity_log` row, the acting admin the causer.
- **Frontend.** The multi-step `<Wizard>` — identity → employment → optional login — plus the
  roster list and the name-edit screen, reusing the M8a admin nav and data-layer patterns.

**Done when:** a System Admin can onboard an employee by name through the wizard, find them
in the roster, inspect their current employment, give them a login, and rename them —
entirely through the API/UI. `scripts/e2e-employee-profiler.sh` proves it live against the
seeded stack: the seeded System Admin onboards "Juan Santos Cruz Jr." (`201`, `full_name`
composed) with a first employment block; the employee appears in `GET /admin/employees` with
that `full_name` and `has_user:false`; the detail shows the resolved current employment;
provisioning a login flips `has_user` to `true` and the new account logs in; a name edit
(Cruz → Delacruz) returns the updated `full_name` while `employee_no` stays immutable; and a
plain employee's `POST /admin/employees` is refused `403`. **764 backend tests (19 of them
Arch) + 523 frontend tests**, all green.

Next: **M8c — role management, `hr_admin_offices` assignment, and the activity-log viewer**
(filters by actor, subject, and action), the last slice of M8.

## M8c — Roles, scope & the audit viewer *(done)*

The third and final slice of M8, and the one that **completes M8**. M8a made the company's
shape editable and M8b made a person onboardable; M8c makes *who administers whom* editable
at runtime and opens the audit trail everything has been writing to a read surface.

- **HR-Admin access is two coupled halves, set in one write.** `POST
  /admin/employees/{id}/hr-offices` (`03-api.md`) drives the `SetHrAdminOffices` action,
  which `sync()`s the `hr_admin_offices` pivot (the *offices*) **and** `assignRole('HR
  Admin')` (the *verbs*) in one transaction — or, when `office_ids` is `[]`, clears the
  pivot and `removeRole`s. Because a policy needs both and neither alone makes an HR Admin
  (`05-rbac.md`), grant and revoke are only ever done together; this is the seeder's
  hand-paired `assignRole` + `hrAdminOffices()->attach` generalized to a live surface. The
  subject is a **login** — a login-less employee is `422 employee_has_no_login`; a
  nonexistent office is the controller's own `422 invalid_reference` (shape-only `uuid`
  validation, the M8a convention), never a `404`. `EmployeeDetailResource` now carries
  `hr_admin_office_ids` and `roles` so the grant's effect is visible without a re-fetch.
- **The audit viewer.** `GET /admin/activity` (`03-api.md`) is a read-only, filterable,
  paginated (`{data, meta}`, 50/page, newest-first) window over the one Spatie
  `activity_log` every `LogsActivity` model writes to — offices/departments/organizations
  (M8a), employee edits (M8b) — plus the manual `hr_admin_offices_set` event this slice
  logs. Filters (`log_name`, `event`, `subject_type`, `causer_id`, `from`/`to`) are optional
  and AND-combined. There is no separate audit store: the trail *is* the log, and the viewer
  only reads it.
- **`is_system_admin`-gated, 403-not-404.** Both surfaces gate on the one-line `(bool)
  $this->user()?->is_system_admin`, no `OfficeScope` — the same global-admin exception as the
  org tree (`05-rbac.md`). A non-admin gets `403`.
- **Frontend.** An office-admin access panel on the employee-detail page (grant/revoke the
  offices a login administers) and a filterable activity-log viewer screen, reusing the M8a/M8b
  admin nav and data-layer patterns.

**Done when:** a System Admin can grant an employee-with-login office-admin access, see the
role and pivot appear together, revoke them together, and browse a filterable activity log
that shows every audited change — entirely through the API/UI.
`scripts/e2e-admin-roles-audit.sh` proves it live against the seeded stack: the seeded System
Admin grants HR-Admin over an office to an employee-with-login (`200`; the detail then shows
that office in `hr_admin_office_ids` **and** `HR Admin` in `roles`); the audit viewer surfaces
both the `hr_admin_offices_set` event (cross-checked at the DB via `psql`) and the wider
`log_name=office` trail; revoking with `office_ids:[]` clears the pivot and the role together;
a login-less employee is refused `422 employee_has_no_login`; and a plain employee's `GET
/admin/activity` is refused `403`. **771 backend tests (19 of them Arch) + 541 frontend
tests**, all green.

**This completes M8** — the admin portal and audit trail. A company can now be configured
from an empty database entirely through the UI (org tree → offices → departments → people →
their admins), and the audit log shows every step of it.

Next: **M9 — containerization and production.**

## M9 — Containerization and production *(complete)*

- `compose.prod.yml`: single FrankenPHP edge, host-routed TLS, no-CORS preserved end to
  end. Production images for API and web.
- Backups with a runnable restore drill.
- CI building images on every PR; all suites green inside the stack via `make test`.

**Done when:** `make prod-up` and `make restore-drill` are both green.

**Status: complete — and this completes the roadmap.** See
`docs/superpowers/specs/2026-07-29-m9-containerization-production-design.md`.
`scripts/e2e-prod-boot.sh` proves it live: it builds both production images, boots
`compose.prod.yml` under its own compose project, serves the API *and* the app over HTTPS
from one edge at `hris.localhost`, bootstraps the first System Admin on an empty database,
signs in with the printed password through the public domain, confirms a second superuser
is refused, takes a `pg_dump` of the live production database, and runs `make restore-drill`
against it — then tears the whole stack down, volumes included. **776 backend tests (19 of
them Arch) + 541 frontend tests**, all green, plus `typecheck` and `build`.

- **Ported from `../pos`, not invented.** That stack's production topology — one FrankenPHP
  container terminating TLS and routing by host, multi-stage prod images, the
  `backup`/`restore`/`restore-drill` trio — was already argued and already deployed.
  `docs/README.md`'s "does not invent a second house style" applies to infrastructure too.
  HRIS diverges in three places only: **one domain, not two** (one frontend, on purpose),
  **RustFS joins the stack** (attachments are a shipped feature — with no published ports,
  since M3.6 made every attachment an app-mediated download rather than an object URL), and
  **a bootstrap-admin command exists**.
- **The genuine gap M9 had to close was not infrastructure: a fresh production database had
  no way to log in.** `DatabaseSeeder` pairs `RbacSeeder` (the permission catalog and the
  `HR Admin` role — real configuration, required in production, and `SetHrAdminOffices`
  throws without it) with `CompanySeeder` (the Manila/Cebu demo company, which must never
  touch production). They cannot run together, and running neither leaves **M8's done-when
  unreachable** — you cannot configure a company from an empty database entirely through the
  UI if you cannot sign in to start. `php artisan hris:bootstrap-admin {email}` runs the
  first half and mints exactly one System Admin.
  - It creates a `users` row and **nothing else**. A System Admin needs no employee record —
    `SessionResource` already renders `employee: null` and the seeded `sysadmin@hris.test`
    has none — which is what sidesteps the chicken-and-egg: an employee needs an
    organization, and creating that organization is the first thing this admin does.
  - It **refuses** when a system admin already exists, rather than upserting. A command that
    quietly mints a second superuser, or resets the first one's password, is a
    privilege-escalation path wearing a helpful face.
- **`.dockerignore` is a security boundary here, not tidiness — and the e2e proved why.**
  The prod stage is `COPY . .`. Without the ignore file the host's `backend/.env` (a real
  `APP_KEY` and database password) is baked into an image layer, and the host's dev
  `vendor/` lands on top of the `--no-dev` tree. It also caught a failure no review would
  have: **`bootstrap/cache/*.php`, the package-discovery cache written by a *dev* `composer
  install`, was being copied in** — and since it names `Laravel\Pail\PailServiceProvider`
  from a `require-dev` package, every production boot died with `Class ... not found`. The
  glob excludes the generated files while keeping the directory the prod stage chowns.
- **Next's `/api` rewrite does not run in production.** It exists for the dev topology,
  where the browser talks to Next and Next forwards to the API. In production Caddy is the
  only thing the browser talks to and it splits `/api/*` off before Next ever sees it. One
  origin either way, CORS a non-issue either way — by two different mechanisms, which is
  worth knowing before someone "cleans up" the now-unused rewrite.
- **Required production variables are guarded at boot in `entrypoint.sh`, never with compose
  `:?`.** A required variable with no default fails interpolation for the *whole* file on
  *every* command — including the `down -v` and `config` you reach for when something is
  already wrong. This is M0's `APP_KEY` reasoning extended to `HRIS_DOMAIN`.
- **A backup nobody has restored is a rumor, so the drill restores into a throwaway
  container and asserts, not just prints.** `users >= 1` is the assertion that can actually
  fail: it is the one row even a freshly bootstrapped production database is guaranteed to
  hold, and a database with no users is not one anybody backed up on purpose. `make backup`
  captures the RustFS attachments tar alongside the dump — an approved adjustment whose
  evidence cannot be produced is precisely the failure this system exists to prevent —
  though **the tar has no automated drill**, which is a recorded gap, not an oversight.
- **The e2e boots under its own compose project name (`hris-e2e-prod`), never `hris`.**
  `compose.prod.yml`'s `name: hris` would otherwise attach to a real production stack's
  `pgdata`, and the script ends in `down -v`. That override is the only thing between a
  smoke test and someone's payroll history.
- **The e2e's first honest run exited 0 while printing `FAIL`.** The `EXIT` trap's last
  command was deciding the script's exit status. `cleanup()` now captures `$?` first and
  re-raises it — a proof script that lies about passing is worse than no proof script, and
  this one nearly shipped that way.

## M10a — Employee profiling *(complete)*

The first milestone after the M0–M9 roadmap closed. An employee record through M9 was
identity plus employment history plus a name — it couldn't answer "what is this person's
mobile number," "who do we call in an emergency," "what is their TIN," or "how old are
they," the questions an HR department opens a personnel file to answer. M10a adds that
file: contact details, personal details, dependents, and government/financial
identification numbers with a scanned copy of each. See
`docs/superpowers/specs/2026-07-30-m10a-employee-profiling-design.md` (amended twice during
implementation — read it alongside this section, not instead of it) and
`.superpowers/sdd/2026-07-30-m10a-employee-profiling/progress.md` for the task-by-task
ledger this section is drawn from.

- **Five new tables, two grafted columns, no new columns on `employees`.**
  `employee_profiles` (a 1:1 side table keyed on `employee_id`), `relationships` and
  `employee_dependents`, `employee_identification_categories` and
  `employee_identifications` — plus `employment_records.designation`/`labor_type` and
  `offices.region`. Full DDL and the reasoning behind each placement: `02-data-model.md`'s
  "Employee profiling" section.
- **Nine routes, four `Action` classes.** Self-read (`GET /me/profile`), the ungated
  catalog (`GET /profile/catalog`), the HR Admin full read/write
  (`GET`/`PUT /admin/employees/{employee}/profile`, `PUT …/dependents`,
  `POST …/identifications`, `DELETE …/identifications/{id}`), the manager's redacted read
  (`GET /employees/{employee}/profile`), and the private scan stream
  (`GET /employees/{employee}/identifications/{identification}/scan`). Full request/response
  shapes: `03-api.md`'s "Employee profiling" section.
- **Three abilities on `EmployeePolicy`** — `viewFullProfile`, `viewRedactedProfile`,
  `updateProfile` — pairing the `employee.pii.edit` permission (catalogued since M2,
  **first read here**) with the `hr_admin_offices` pivot, deliberately bypassing
  `EmployeeScope` for the full-read/write pair so a manager's own-report membership in that
  scope can never unlock the full file. Full argument: `05-rbac.md`'s "Employee profiling"
  section.
- **`ProfileCatalogSeeder`** seeds the eight identification categories (TIN, SSS, HDMF,
  PHIC, BANK, PASSPORT, DL, PRC) and five relationships (spouse, child, parent, sibling,
  other) — catalog data production needs, called from `hris:bootstrap-admin` alongside
  `RbacSeeder`, not from the dev-only `DatabaseSeeder`.
- **Frontend.** `/me/profile` — read-only, five sections (Details, Contact, Personal,
  Assignment, National IDs) composed from existing tier-2 components, no new primitives.
  `/employees/{id}/profile` — a new route under the plain `(app)` group, not `/admin` —
  holds the HR Admin's read+edit view (`ProfileSections` + `ProfileForm`) for a full-read
  viewer, and the manager's redacted view (`ProfileSummarySections`) for anyone else the
  backend admits; the page tries the full read and falls back to the redacted one on a
  `404`, so it never has to reimplement the office-pivot check `EmployeePolicy` already
  owns. (This route, and its separation from `/admin/employees/{employee}`, is the
  final-fixes round below — the milestone originally shipped the Profile section stacked
  on the system-admin-only admin page, which made the HR/manager halves of this
  authorization model unreachable in a browser.) `useMyProfile`, `useEmployeeProfile(id)`,
  and `useRedactedProfile(id)`, keyed through `lib/keys.ts`. The scan preview's
  bearer-authenticated blob-URL fetch — previously inlined in `RequestCard.tsx` — was
  lifted into `lib/authedBlobUrl.ts` and both call sites now share it; this is the one
  piece of pre-existing code this milestone touches, and only because it's the code being
  reused.

**Done when:** an HR Admin fills in a Cebu employee's contact details, personal details,
two dependents, and a TIN with a scanned copy; that employee reads the full file back at
`/me/profile`; their manager, who sits in Manila, sees only contact and assignment at
`/employees/{id}/profile`; a Manila-only HR Admin gets `404` on the same Cebu employee,
byte-identical to a nonexistent one; the HR Admin who filled the file in cannot edit their
*own* record, even though they administer their own office; and a fresh database
bootstrapped with `hris:bootstrap-admin` already has the eight identification categories
and five relationships waiting.

**Status: complete.** **854 backend tests (19 of them Arch) + 574 frontend tests**, all
green — up from the 776 backend / 541 frontend the M0–M9 roadmap closed at. `lint`,
`typecheck`, and `build` are green, native and inside the `make test` containers alike.
(Task 16, below, is what took the frontend count from 560 to 563 and the backend
assertion count from 3058 to 3061 without adding a backend test — see that entry. The
final-fixes round below took it from 853/563 to 854/574. The M10a follow-ups round, after
this — a separate branch, `m10a-followups`, closing the "open follow-ups" table below —
took it further, to **865 backend tests (20 of them Arch) + 577 frontend tests**; see that
section for what changed.)

**Final-fixes round (before merge) — five findings from the whole-branch review, all
fixed:**

1. `EmployeeProfile::getActivitylogOptions()` used `logFillable()` against a
   `$guarded = []` model with no `$fillable` — `getFillable()` returned `[]`, so every
   profile change wrote an `activity_log` row with empty `properties`. Replaced with an
   explicit `logOnly([...])` allowlisting the personal-details fields and deliberately
   excluding contact PII (`home_address`, `personal_email`, `phone`, `fax`, `mobile`,
   `emergency_contact`), the same reasoning `EmployeeIdentification` already applies to
   `number`.
2. `hris:bootstrap-admin` seeded `RbacSeeder`/`ProfileCatalogSeeder` BELOW the
   System-Admin-exists guard, so every M9 production install — which by definition already
   has a System Admin — could never gain the profile catalogs after an M10a deploy. Both
   seed calls now run unconditionally, above the guard; the guard still refuses to mint a
   second superuser.
3. The Profile section lived on `/admin/employees/{employee}`, `is_system_admin`-gated on
   the frontend even though the endpoints it called never required it — the entire
   `viewFullProfile`/`viewRedactedProfile` model was unreachable in a browser, and the
   manager's redacted read had no screen at all. Moved to its own route,
   `/employees/{id}/profile` (see the Frontend bullet above); the admin employee page keeps
   only employment records, HR-office grants, and login provisioning.
4. `offices.region` had no frontend type, no form field, and no display — it could never be
   set from the browser, and an unrelated office edit silently NULLed out any region set
   via the API (`UpdateOfficeRequest` treats an absent key as an explicit null). Added to
   the `Office`/`OfficeCreateInput`/`OfficeUpdateInput` types and the offices form, handled
   exactly like `timezone` except optional.
5. `ProfileSections` rendered `labor_type` (`'direct'`/`'indirect'`) raw instead of through
   `labelForOption`, unlike gender/marital status/blood type. Added `LABOR_TYPE_OPTIONS`.
   `employment_status` stays raw deliberately — it is validated backend-side as a free
   string, not a `Rule::enum()`-backed set, so there is no closed set to label it against.

Full writeup, including the mutation-verification for each fix and the judgment calls
behind fix 3's frontend wiring:
`.superpowers/sdd/2026-07-30-m10a-employee-profiling/final-fixes-report.md`.

**Deferred, from the spec — none of these blocked the milestone, each has a stated trigger:**

| Item | Trigger that revives it |
| --- | --- |
| **Structured address** (street / barangay / city / province / postal) | The first report that must filter or group by city or province — a BIR or DOLE submission is the likely one. Until then `home_address` stays one comma-joined string. |
| **Per-ID format validation** (TIN checksums, SSS length, PhilHealth format) | A data-quality complaint, or an export rejected by a government portal. The `number` column doesn't change; only validation would be added. |
| **ID expiry alerts** | Someone asking to be told a PRC license or passport lapsed. `expires_on` exists for exactly this; only the notification is missing. |
| **Profile change history** | An audit finding that `activity_log` isn't enough. The log already records who changed what and when; a full effective-dated profile history is a second `employment_records`-shaped table, not justified by anything current. |
| **Employee self-service contact edits** | The first HR Admin who doesn't want to retype a phone number. Requires splitting the write policy so an employee may update contact fields but not identifications or assignment. |

**One known-and-accepted rough edge, recorded honestly rather than smoothed over** (a second
one — the Dependents list rendering a raw relationship code instead of its label — was found
by the Task 14 browser walkthrough, deferred, then actually fixed as Task 16 below; it no
longer belongs on this list). **This first one was later fixed too, by the `m10a-followups`
branch — kept here verbatim as the original record, with the fix and its correction to the
"deserves its own piece of work" claim below in the M10a follow-ups section:**

- **`Carbon::today()` in the profile resources is UTC-today, not office-local today.**
  `EmployeeProfileResource`/`EmployeeProfileSummaryResource`'s `EmploymentResolver::on()`
  call and `EmployeeAssignmentPresenter::workShift()`'s schedule lookup both resolve "today"
  against the server clock (`APP_TIMEZONE=UTC` by rule), so between 00:00 and 08:00
  Asia/Manila an employment record or a schedule assignment effective *today* does not
  appear in a profile read until 08:00 local. Meanwhile `EmployeeProfile::age` deliberately
  anchors to the employee's office timezone (`02-data-model.md`) — so for eight hours a day,
  the `assignment` block and the `personal.age` field in the *same payload* can be computing
  against two different "todays." Neither is wrong on its own; they simply don't agree with
  each other during that window. Fixing it properly means threading the office timezone
  through `EmploymentResolver`/`ScheduleAssignment` lookups everywhere, not just here — a
  cross-cutting change bigger than this milestone, deferred rather than patched locally.

**There is still no browser-level e2e harness — M10a's screens carry the identical gap
M3.5's status block already records.** `/me/profile` and `/employees/{id}/profile` (both
its full and redacted shapes) are covered by component tests and the backend's live-API
proof, but none was confirmed rendering in an actual browser as part of this milestone or
its final-fixes round (the brief's browser-walkthrough step was explicitly skipped, on
instruction, during Task 14). Load them yourself — including uploading and previewing a
scan — before trusting the UI.

**M10b — document management is the open follow-on, deliberately split out of this
milestone's brainstorm rather than built alongside it.** A `Document`/`DocumentBucket`/
`DocumentCategory` module with a polymorphic file table shares essentially nothing with
M10a: this milestone attaches exactly one media file per identification row through the
collection mechanism M3.6 already built, and does not anticipate M10b's catalog. It is
brainstormed separately and not started.

What the building turned on, for whoever extends the profile module next:

- **A Postgres FK cascade bypasses Eloquent, and medialibrary's cleanup hook only fires
  through Eloquent.** `employee_identifications.employee_id on delete cascade` deletes the
  row at the database when an employee is deleted, which never runs medialibrary's
  `deleting` model event — leaving an orphaned `media` row and an orphaned scan object in
  RustFS. Verified unreachable today (no employee-delete route exists anywhere), so this was
  recorded rather than guarded against; see `02-data-model.md`'s M10a section for the full
  argument and the rule for whoever adds a delete path later.
- **PHP parses a multipart body only on `POST`, which is why the identification save is a
  `POST` despite being an upsert.** A `PUT multipart/form-data` arrives with an empty
  `$_FILES` and the uploaded scan vanishes with no error — the exact reason Laravel ships
  `_method` spoofing. Cost a plan correction before Task 10 was dispatched; recorded verbatim
  in `CLAUDE.md`'s gotcha list so it isn't rediscovered the hard way on the next multipart
  route.
- **A self-comparison written as `user->employee?->id === employee->id` fails OPEN, not
  closed, for an actor with no employee row.** `null === null` is `true` in PHP, so an HR
  Admin or System Admin account with no personal `employees` row would pass a self-check
  against any employee whose id also somehow resolved null — the one check standing between
  an arbitrary user and someone else's TIN. `EmployeePolicy` instead tests
  `$employee->user_id !== null && $employee->user_id === $user->id` everywhere a
  self-comparison is needed. Caught by review before merge, not after.
  `updateProfile`'s self-denial has a second layer on top: it must outrank the HR-office
  grant, not merely exist alongside it, or a lone HR Admin whose own record sits in an
  office they administer could still edit their own PII — see `05-rbac.md`.
- **A query-builder bulk `delete()` fires no Eloquent model events, so `LogsActivity` never
  records the rows it removes.** `ReplaceEmployeeDependents`' replace-all write is the one
  action in this milestone that mass-deletes a `LogsActivity` model; a first pass used
  `Model::query()->where(...)->delete()` and a review proved — empirically, by counting
  rows — zero activity-log entries for a dependent removal. Fixed to a row-by-row loop
  (`->get()->each(fn ($d) => $d->delete())`), which is affordable specifically because the
  list is capped at 20 rows by validation. Any future bulk-delete-of-an-audited-model should
  assume the same silent gap until proven otherwise.
- **`tsgo` does not excess-property-check an object literal returned from `.map()`** unless
  the callback carries an explicit return-type annotation. This let a genuinely CRITICAL bug
  ship past both the typechecker and a green test suite: `ProfileForm` matched a dependent's
  relationship on `description` ("Spouse") while the API sends `code` ("spouse") — the match
  never succeeded, every pre-filled dependent silently fell back to `relationships[0]`
  (`child` under `orderBy('code')`), and because the write is a full replace, editing one
  dependent's name would have rewritten *every* dependent's relationship to Child. All six
  tests at the time passed because the test fixture had `dependents: []`. A reviewer proved
  it with a throwaway test before it shipped; the fix pairs the type-safety fix
  (`(row): DependentWrite => ({…})`, an explicit return type so `tsgo` actually checks the
  keys) with the logic fix (match on `code`). Recorded verbatim in `CLAUDE.md`'s gotcha list.
- **A pre-existing infrastructure gap became reachable for the first time here, and is
  still open.** The api container's `upload_max_filesize` is the base PHP image's default,
  2 MB — while every attachment-accepting route in this codebase (including M3.6's request
  attachments) advertises `max:10240` (10 MB) in its validation rule. A file between 2 MB
  and 10 MB is silently dropped by PHP *before* Laravel's validation ever runs, surfacing as
  a confusing "must be a file" `400` rather than a size-limit error. This predates M10a —
  the same gap exists for `POST /attendance/adjustments`'/`POST /leave/requests`' attachment
  fields — but a scan of a government ID is the first upload where a file in that 2–10 MB
  range is realistic, so M10a is what actually surfaced it. No `php.ini`/
  `upload_max_filesize` setting exists anywhere in the repo's Dockerfiles, compose files, or
  entrypoint scripts. **Unresolved — flagged for the next person touching file uploads or
  the container images**, not fixed as part of this milestone.
- **The scan-stream route's ownership check had zero test coverage until a review deleted
  it and proved a real cross-employee leak.** `DownloadScanController` checks
  `$identification->employee_id !== $employee->id` before the policy call; with that line
  removed, all ten existing tests still passed, and a reviewer then paired an ordinary
  employee's own id (which self-grants `viewFullProfile`) with a *different* employee's
  identification id and got back `200` plus the victim's passport bytes. Fixed with a
  dedicated ownership test, an HR-of-a-different-office test, and a `Content-Type`
  assertion — the kind of gap that "the policy passed" doesn't catch, because the policy was
  never wrong; the controller's own extra check was simply unguarded.
- **A `RefreshDatabase`-based matrix test cannot reset per-cell state by swapping the
  application instance.** The task 11 brief called for `$this->refreshApplication()`
  between matrix cells; under `RefreshDatabase`, that swaps in a brand-new `Application`
  whose database connection is a fresh Postgres session — one that cannot see any fixture
  row created inside the still-open outer transaction `RefreshDatabase` began on the
  *original* connection, turning every subsequent cell into a false negative regardless of
  actor. Proved with `pg_stat_activity` before being ruled out. The fix recreates only the
  one row a cell can mutate (the identification, by `(employee_id, category_id)`, which also
  covers the `POST` cell's own upsert-collision risk) rather than the whole fixture set.
- **The admin Profile section renders the whole personnel file twice.** The admin employee
  detail page stacks the read-only `ProfileSections` view directly above the editable
  `ProfileForm` for the same employee, so an HR Admin sees every Details/Contact/Personal
  field twice and two separate "Dependents" headings on one screen. Recorded as a deferred
  polish item, not fixed in this milestone — a future pass should either fold the two into
  one form-that-shows-current-values or gate the read view behind an edit toggle.
- **Redaction is a second resource class, not a conditional — the M8b `full_name`
  precedent (compose a display value in exactly one place) applied a second time.** `EmployeeProfileSummaryResource` shares no code with
  `EmployeeProfileResource`; a field added to the full resource cannot leak into the manager's
  view by accident, because someone has to come and add it to the summary resource on
  purpose. The redaction test asserts the exact key set on both, not just "these keys are
  absent," so a stray key in a shared sub-block (`assignment`) would still be caught.
- **Task 16 — the browser walkthrough's live-data check found a defect wider than the
  Task 14 rough edge on record: the read view and the edit form disagreed about what THREE
  fields are called, not one.** `ProfileSections` (read) printed backed wire values straight
  through — `gender: 'male'`, `marital_status: 'single'`, a dependent's `relationship`
  code (`'spouse'`) — while `ProfileForm`'s `Select`s showed `'Male'`/`'Single'`/`'Spouse'`
  for the identical fields on the identical screen, violating the invariant `ProfileForm`'s
  own doc comment states. Fixed two ways for two different reasons. (1) Gender/marital
  status/blood type: `GENDER_OPTIONS`/`MARITAL_STATUS_OPTIONS`/`BLOOD_TYPE_OPTIONS` moved out
  of `ProfileForm.tsx` into a new `src/lib/profileOptions.ts` (re-exported from
  `ProfileForm` so its own imports didn't need to change), alongside a `labelForOption()`
  helper both components now call — one label table, not two that can drift. (2) A
  dependent's relationship: `EmployeeProfileResource` gained a sibling
  `relationship_label` field (`$dependent->relationship?->description`), mirroring the
  existing `category_code`/`category_name` pair, rather than changing what `relationship`
  contains — `ProfileForm` still matches dependents on the CODE to pre-select a catalog
  entry, and changing that field's meaning is exactly the Task 14 CRITICAL bug recurring.
  Client-side resolution from `useProfileCatalog` was the other option considered and
  rejected: `ProfileSections` is presentational with no data fetching today, and
  `/me/profile` doesn't fetch the catalog at all, so a server-side field was the smaller
  change. `frontend/web/src/lib/api.ts`'s `ProfileDependent` type gained the new field;
  `docs/03-api.md`'s dependents example was updated to show it.

### M10a follow-ups — closed

The final whole-branch review triaged 29 recorded items: none blocked the merge, roughly half
were accepted as recorded, and twelve were judged worth doing, listed below in the original
value order. All twelve were cleared on the `m10a-followups` branch, plus a thirteenth —
`CompanySeeder` shipping ten employees with entirely empty personnel files — found and fixed
alongside them though it was never on this table. Fourteen commits, `git log --oneline
main..m10a-followups`; **865 backend tests (20 of them Arch) + 577 frontend tests**, up from
854/574, all green.

| Item | What shipped |
| --- | --- |
| **`upload_max_filesize` is 2M while every attachment rule says `max:10240`** | Fixed. `backend/Dockerfile`'s shared `base` stage now drops `upload_max_filesize=12M` / `post_max_size=20M` into `conf.d` (12M clears the 10 MiB validation ceiling with headroom; 20M stays well above 12M because the multipart body also carries every other form field, and PHP truncates the whole POST — not just the file — if `post_max_size` doesn't exceed `upload_max_filesize`). Both `dev` and `prod` inherit it. **This predates M10a and also fixes M3.6's existing attachment routes** (`SubmitAdjustmentRequest`, `SubmitLeaveRequestRequest`). Built and verified against a fresh image; an **already-running api container keeps serving the old 2M/8M until it is recreated** — see the Production section of `CLAUDE.md`. |
| **Self-view renders an edit form that can only 403** | Fixed. `/employees/{employee}/profile` now reads `isSelf` from `useSession()` (never inferred from a failed request) and, when true, renders the read view (`ProfileSections`) plus an `InlineNotification` explaining the separation-of-duties rule instead of `ProfileForm`. `useProfileCatalog` is skipped for self too — there is no form to populate a dropdown for. |
| **Scan replacement deletes the old RustFS object *inside* the DB transaction** | Fixed. `SaveEmployeeIdentification` now runs the `updateOrCreate` alone inside `DB::transaction()` and calls `addMedia(...)->toMediaCollection('scan')` only after that closure returns — i.e. after the DB write is durably committed. A failure in the media step can no longer roll back an already-committed number/date change, and a transaction rollback can no longer reach RustFS at all. |
| **`RbacSeeder`'s permission names are reserved words** | Fixed. A block comment above `HR_PERMISSIONS` names `viewFullProfile`, `viewRedactedProfile`, and `updateProfile` as reserved — spatie's `Gate::before` grants any ability whose *name* matches a held permission, so a permission literally named one of these would bypass `administersOfficeOf()` entirely. Comment only; nothing in `HR_PERMISSIONS` changed. |
| **`hrAdminFor()` is a global Pest file-scope function** | Fixed. Moved from `tests/Feature/Profile/ProfilePolicyTest.php` into `tests/Pest.php`'s new "Shared test helpers" section, with a comment stating why: a second declaration anywhere under `tests/` is a PHP fatal, not a test failure, and M10b will add more profile tests. |
| **No arch guard over `app/Http/Controllers/Profile/`** | Fixed. `tests/Arch/ConventionsTest.php` gained a guard sibling to the existing `Employees/`/`Attendance/` ones, checking the union of both existing patterns (`EmployeeScope`, `user()->employee`, `->cannot(`/`->can(`/`->authorize(`, `Gate::`). `ShowCatalogController` is exempted by filename — it serves static, ungated reference data by design. Arch suite: 19 → 20. |
| **`ProfileScopeMatrixTest` cannot tell 403 from 404** | Fixed. The denied branch now asserts `$status === 404` specifically rather than reusing the 2xx-or-not inversion, so a 403 — the enumeration leak the 404-not-403 discipline exists to prevent — is now caught, not just tolerated. |
| **The `assignment` sub-block's key set isn't pinned** | Fixed. `ShowProfileTest`'s redacted-manager-view case now asserts the exact key set of `$body['assignment']`, alongside the pre-existing top-level and `contact` assertions — closing the one gap in the resource where a leak into the block shared by the full and redacted resources would go uncaught. |
| **`Select`'s `withBlank` placeholder never shows on the closed trigger** | Fixed. `RadixSelect.Value` now receives `placeholder={blankLabel}`, the blank option's own label, already present at every call site that wants a blank state. No call site needed to change — affects the six pre-existing admin dropdowns too, not just the profile form. |
| **`Carbon::today()` is UTC-today across the profile resources** | Fixed — narrower than originally described; see the correction below. |
| **`useProfileCatalog()` fires for redacted viewers** | Fixed. The hook takes an `enabled: boolean = true` param (mirroring `useRedactedProfile`'s existing pattern); the page calls it with `fullQuery.isSuccess && !isSelf`, so a manager who will only ever see the redacted shape never fires the request. |
| **`gridTemplateColumns: 'minmax(8rem, 14rem) 1fr'`** | Fixed. `carbon.css` gained a `--dl-label-col: minmax(8rem, 14rem);` token with an explanatory comment; `DefinitionList` reads it. `DESIGN.md` was deliberately **not** touched — its front-matter schema has no category for grid-track sizing, and `carbon.css`'s pre-existing `--field-border` (present in `carbon.css`, absent from `DESIGN.md`, with its own "not in DESIGN.md's colors block" comment) is the accepted precedent for a token that lives in code only. |

**Correction to the `Carbon::today()` entry above.** This roadmap previously said of that item:
*"Do not patch locally: the real fix threads office timezone through `EmploymentResolver` and
`ScheduleAssignment`, and deserves its own piece of work."* **That assessment was wrong.**
Verification on this branch showed `EmploymentResolver::on()` already takes an explicit date
argument, and nothing in the compute engine ever calls `today()` itself —
`ComputeDailySummary:76` passes the day being computed, `PayrollExport:107,112` pass explicit
period dates. Only the four HTTP resources were choosing "today" for themselves:
`EmployeeProfileResource`, `EmployeeProfileSummaryResource`, `EmployeeAssignmentPresenter`, and
the pre-existing M8b `EmployeeDetailResource` (which had the identical bug and was never on
this list, because nobody had traced it back that far until this pass). The fix is a new
`app/Http/Resources/EmployeeLocalToday.php` helper — `Carbon::now($employee->currentOffice
?->timezone ?? 'Asia/Manila')->startOfDay()`, mirroring `EmployeeProfile::age`'s existing
approach exactly — used at all four call sites. **`EmploymentResolver` and `ScheduleAssignment`
were deliberately left untouched.** The record stands corrected rather than quietly deleted: the
wider fix was never necessary, the pay engine was never at risk, and the resource-layer fix also
closes the M8b `EmployeeDetailResource` instance of the same bug as a side effect.

Next: no milestone is open. **M10b — document management** is the nearest unclaimed work
(above); beyond that, the **Deferred** table below is unchanged by this milestone.

## M10b-a — Document catalog *(complete)*

M10a gave every employee a personnel file but nowhere to file a *document* — a signed
contract, an NBI clearance, a medical certificate, a company policy. M10b was split into
two milestones at the brainstorm (`docs/superpowers/specs/2026-08-01-m10b-document-management-design.md`,
amended twice during implementation — read it alongside this section, not instead of it;
task-by-task ledger: `.superpowers/sdd/2026-08-01-m10b-a-document-catalog/progress.md`).
**M10b-a builds the catalog and ships it empty; M10b-b (not started) builds the files.**

- **Three tables, no new columns anywhere else.** `document_categories` (shelves),
  `documents` (kinds — `applies_to`/`is_required`/`validity_months` behaviour lives here,
  not a second taxonomy table), `document_files` (empty after this milestone — no route
  writes it). Full DDL and reasoning, including the dropped `DocumentBucket`, the
  `expires_on`-is-stored-not-derived rule, and why there's no unique constraint on
  `(document_id, documentable_type, documentable_id)`: `02-data-model.md`'s "Document
  management" section.
- **Nine routes, six `Action` classes.** The ungated dropdown read (`GET
  /documents/catalog`) plus full CRUD on categories and kinds behind `document.manage`
  (`GET`/`POST`/`PATCH`/`DELETE /admin/document-categories`, same four verbs on
  `/admin/documents`) — three routes per resource are `create`/`update`/`delete` Actions,
  the three `GET`s (the catalog read and the two admin list routes) are controller-only
  reads with no Action, the same "a read with no domain behaviour" shape M10a's catalog and
  scan-stream controllers already use. Full request/response shapes: `03-api.md`'s "Admin —
  the document catalog" section.
- **Two permissions, one policy ability.** `document.manage` (catalog CRUD today,
  office-scoped file access in M10b-b) and `document.manage.self` (M10b-b: upload/read your
  own, never delete) on `App\Policies\DocumentPolicy::manageCatalog` — deliberately
  unscoped, since the catalog has no office to scope by. Full argument: `05-rbac.md`'s "The
  document catalog" section.
- **`DocumentCatalogSeeder`** ships a six-kind Philippine starter set (NBI Clearance,
  Medical Certificate, Employment Contract, 201 File, Company Policy, Business Permit)
  across four categories, called from `hris:bootstrap-admin` above the System-Admin guard —
  same placement as `RbacSeeder`/`ProfileCatalogSeeder` — **and idempotent by
  insert-if-absent, not overwrite**, so an admin's catalog edit survives a re-run of the
  kind the bootstrap command's own docblock instructs ops to make. See below and
  `02-data-model.md` for why this is the opposite idempotency shape from
  `ProfileCatalogSeeder`.
- **Frontend.** `/admin/documents` — two sections, Categories and Document kinds, each with
  an inline create/edit form (see the open question below) and a delete control that
  surfaces `document_catalog_in_use`'s dependent count verbatim. `useDocumentCatalog()`
  (`GET /documents/catalog`) backs both lists; `useSaveDocumentCatalog()` bundles the six
  mutations and invalidates all three document query keys on every success. No changes to
  `/employees/{id}/profile` or the office admin screen — a Documents section on each is
  M10b-b.

**Done when:** `/admin/documents` renders, creates a kind with `applies_to: null` (both
owner types), and surfaces the `409` with its dependents count when a delete is refused; a
fresh database bootstrapped with `hris:bootstrap-admin` has the document catalog, **and so
does one that already had a System Admin**; `document_files` exists and is empty — nothing
writes it yet.

**Status: complete. 911 backend tests (21 of them Arch, 3302 assertions) + 590 frontend
tests (589 passed, 1 pre-existing red — see below), up from 865/577.** `lint`, `typecheck`,
and `build` are green, native and inside the `make test` containers alike.

**Two rulings reversed the original design, both made mid-implementation with the
developer asleep and the wheel deliberately delegated — recorded here with the mistake
intact, not quietly corrected:**

1. **There is no `Relation::morphMap()`.** The spec originally registered one so
   `document_files.documentable_type` would store `'employee'` rather than
   `App\Models\Employee`. That was wrong: the map is process-global and also governs
   spatie/activitylog's `subject_type`, not just medialibrary's `model_type` — `Employee`
   and `Office` both use `LogsActivity`, and the map would have written the alias onto every
   *new* audit row while history kept the FQCN, silently breaking the M8c audit viewer's
   `subject_type` filter in both directions. It broke five existing tests, which is how it
   was caught before it ever reached a review. `document_files.documentable_type` stores
   the full class name instead, matching `media.model_type` and `activity_log.subject_type`
   — all three polymorphic tables in the schema now behave the same way, and
   `config/documents.php` is a whitelist, not a morph map. Full argument, including what's
   genuinely lost (rename safety) and why backfilling `activity_log` was rejected:
   `02-data-model.md`. **Added to `CLAUDE.md`'s gotcha list verbatim**, since the mistake is
   the kind of thing a future package integration will make again if nobody's read this.
2. **`DocumentCatalogSeeder` is idempotent by `firstOrCreate` (insert-if-absent), not
   `updateOrCreate` (overwrite).** The original plan copied `ProfileCatalogSeeder`'s
   `updateOrCreate`, correct there because TIN/SSS/PhilHealth are fixed by Philippine law
   and no UI ever edits them. It's wrong for this catalog, which **is** admin-editable —
   `updateOrCreate` would have silently reset an HR Admin's catalog edit (NBI Clearance
   changed from 6 to 12 months, say) every time ops re-ran `hris:bootstrap-admin`, which its
   own docblock instructs them to do whenever a milestone adds catalog data. The trade
   accepted: a later milestone cannot change a seeded *default* through the seeder — correct
   for admin-editable data, where a real default change should ship as an explicit
   migration, not a seeder overwrite. Full argument: `02-data-model.md`.

**Other things worth knowing about the build:**

- **A stale docblock the morph-map ruling created was left for this section to close.**
  `app/Domain/Documents/Documentable.php` said its backed values "ARE the morph aliases
  stored in `document_files.documentable_type`" — true under the original design, false
  after ruling 1 reversed it. Corrected as part of this documentation pass; the column
  stores the FQCN, and the alias is a wire-layer concern only.
- **An arch guard that only checked for an import, not an authorization check, would have
  passed a FormRequest that gates nothing.** `tests/Arch/ConventionsTest.php`'s new
  "every Admin\Documents controller is guarded by a FormRequest whose authorize() gates"
  rule started as an import-presence check — it caught "forgot a FormRequest entirely" but
  not "imported one whose `authorize()` returns `true` unconditionally," which a reviewer
  proved by constructing exactly that FormRequest and watching the rule pass it anyway.
  Fixed to a two-hop check: the imported class must be the `__invoke` type-hint, and that
  class's `authorize()` must contain `->can(`/`Gate::`/`manageCatalog`. Arch count: 20 → 21.
- **The `document.manage.self` permission is seeded and cataloged in this milestone but
  gates nothing yet** — the same "named ahead of the feature that reads it" pattern
  `leave.manage`/`leave.approve`/`holiday.manage`/`cutoff.manage` all went through before
  their features shipped (`05-rbac.md`). `DocumentCatalogScopeMatrixTest` proves it grants
  nothing on the catalog specifically, so the gap is documented, not silent. It becomes a
  real gate the moment M10b-b wires the file routes.

**Two open questions, deliberately left for the developer to decide, not resolved
unilaterally while they were away:**

1. **`/admin/documents` builds its forms inline, while all four sibling admin CRUD screens
   (offices, departments, organizations, pay-rules) use a `Dialog`.** It is the sole outlier
   of five. The implementing brief called for "inline create/edit" while also naming
   `/admin/offices` as the closest mirror — which itself uses a `Dialog` — so the brief was
   self-contradictory and the implementer picked the inline reading and disclosed it rather
   than guessing silently. Unresolved: either this screen should gain a `Dialog` to match
   its siblings, or the inline shape (arguably fine for forms this short) should be recorded
   as the new house pattern and the other four left alone.
2. **HR Admins cannot discover the screen.** `SideNav.tsx:87` gates the whole `admin` nav
   group on `session.is_system_admin`, but catalog CRUD is gated on `document.manage`,
   which `RbacSeeder` grants to the `HR Admin` role — so an HR Admin can write the catalog
   through the API but has no nav link to the screen at all. Same class of gap as M10a's
   Task 14 finding (HR Admins couldn't reach the profile UI before the final-fixes round,
   above). The counter-argument, recorded so it isn't re-litigated from scratch: every other
   company-wide config screen (pay-rules, organizations, offices, departments) is
   System-Admin-only too, so the *placement* is internally consistent — the real question is
   whether catalog CRUD should have been `is_system_admin`-gated all along, which was a
   judgment call made mid-implementation and never put to the developer. Three coherent
   resolutions, none chosen: tighten catalog CRUD to `is_system_admin` (smallest change,
   matches every sibling); add per-item nav gating so "Documents" shows for anyone holding
   `document.manage`; or give it its own HR-reachable route the way M10a's
   `/employees/{id}/profile` sits outside `/admin`.

**One pre-existing frontend failure, unrelated to this branch, recorded so it isn't
mistaken for M10b-a's.** `src/app/(app)/me/attendance/attendance.test.tsx`'s "renders Clock
in for today even when other days this month have punches" fails deterministically, not a
flake. The fixture derives its dates from the real clock: `otherDay` is
`` `${THIS_MONTH}-02` ``, and on any day the container clock resolves to the 2nd of the
month in `OFFICE_TIME_ZONE` (Asia/Manila), that fixture punch lands on *today* rather than
"another day," so the hero renders a clocked-in state and "Clock in" never appears — it
breaks on the 2nd of every month in office time, not the 1st (an earlier same-day
correction to this entry's own mechanism). `git diff --name-only` confirms the file is
untouched by this branch; baseline frontend is 577 passed of 578 the moment the branch
started, not 578 of 578, for reasons that have nothing to do with document management.
**Not fixed here** — recorded for whoever next touches `attendance.test.tsx`.

**There is still no browser-level e2e harness — `/admin/documents` carries the identical
gap M3.5's and M10a's status blocks already record.** The screen is covered by component
tests (`documents.test.tsx`) and the backend's live-API proof (`DocumentCatalogScopeMatrixTest`
and friends), but nothing in this milestone confirmed it rendering in an actual browser.
Load it yourself before trusting the UI.

**What M10b-b still owes**, per the spec's scope split: file upload/list/download/delete
for both owner types (`POST`/`GET`/`DELETE /employees/{employee}/documents[/{file}]`,
the mirrored `/offices/{office}/documents` set, and the two download streams); the two
compliance reads (`GET /admin/documents/expiring`, `GET /admin/documents/missing`, both of
which must be registered before any future parameterised `GET /admin/documents/{document}`
— `03-api.md`); a Documents section on `/employees/{id}/profile` and on the office admin
screen; and a compliance view surfacing expiring-soon and missing-required documents for
the actor's offices.

Next: **M10b-b — the document files** (above): upload, list, download, delete, the two
profile/office screens, and the compliance view. No milestone is open beyond that; the
**Deferred** table below is unchanged by this milestone.

## Deferred

| Item | Trigger that revives it |
| --- | --- |
| **Payroll (gross-to-net)** | The decision to stop paying an external payroll provider. Adds SSS MSC brackets, PhilHealth 5%, Pag-IBIG, TRAIN withholding, 13th month, de minimis, loans, payslips, BIR 1601-C/2316/alphalist. M6's export format is designed to be its input, so this bolts on rather than refactors. |
| **Biometric device ingestion** | The first hardware purchase. The device-agnostic endpoint contract (device registry, token auth, batch payload, idempotency key, clock-skew correction) is specified in `03-api.md` from M3 so no redesign is needed — only a driver. |
| **Mobile app with GPS geofence** | Field staff who can't reach a browser. `offices.geofence_*` columns exist from M2; the offline replay queue is what makes M3's idempotency keys load-bearing. |
| **Rotating shift rosters** | A client with BPO or retail rotating coverage. M4's template model handles fixed, compressed, and night shifts; per-day rostering with swap requests is a module, not a column. |
| **Tenure-based leave accrual** | A leave policy with year-based tiers (12 days at year 1, 15 at year 5). M5's ledger supports it; only the accrual job changes. |
| **Recursive manager scope** | An org chart deep enough that direct reports aren't sufficient. Costs a materialized path on `employees` plus cycle detection, and makes the scope check the most expensive query in the system — which is why it isn't in v1. |
| **Multi-tenancy** | Selling this to a second company. **Expensive to change**, exactly as POS flagged. Revisit early or not at all. |

### Still open after the follow-up branch

Four things the final review surfaced that were deliberately left, so the next reader does
not rediscover them:

- **The self-view notice is imprecise for one viewer class.** `/employees/{id}/profile` hides
  the edit form when `session.employee.id` matches the route param, and tells you your own
  details are changed by someone else. That is true for an HR Admin — but `Gate::before`
  grants a **System Admin** everything, so `updateProfile`'s self-denial never runs for them
  and the backend *would* have allowed the edit. Unreachable today (`hris:bootstrap-admin`
  creates a System Admin with no employee row, and `is_system_admin` is in `User::$guarded`),
  so it is a latent mismatch rather than a live bug. It becomes real the first time someone
  grants `is_system_admin` to a user who is also an employee.
- **The arch authorization guard covers `Http/Controllers/Profile/` but not
  `Http/Controllers/Admin/Profile/`** — the two *read* controllers, not the five that
  actually mutate a personnel file. Those authorize inside their FormRequests, which none of
  the guard's grep patterns would match anyway, so extending it means teaching the rule about
  FormRequest-based gating rather than just widening a path. A future ungated
  `Admin/Profile/*` controller gets no CI backstop.
- **The arch exemption matches on filename, not relative path.** A future
  `Http/Controllers/Profile/Something/ShowCatalogController.php` would be silently exempt.
- **`make restore-drill` still does not verify the attachments tar restores.** M9's recorded
  gap, unchanged — and identification scans now ride in that same tar, so it covers more
  than it did.
