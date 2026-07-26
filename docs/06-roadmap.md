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
- The leave request itself and the two-hop machine → **M6b-b**, below (next).
- Overtime pre-authorization and the `min(actual, approved)` compute integration → **M6c**.

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

## M6b-b — Leave requests and the two-hop approval machine *(next)*

The second slice: an employee actually files a leave request against a type from M6b-a's
catalog, and the approval machine widens for the first time since M6a, because leave is the
first request type that genuinely needs a second hop.

- **The multi-step machine.** `draft → submitted → manager_approved → hr_approved →
  approved`, `rejected`/`cancelled` terminal, a per-type `requires_hr_step` flag deciding
  whether the second hop exists at all — leave is the type that needs it, so it lands here
  rather than being speculatively built into M6a or M6b-a. `RequestEffect` and the two
  scoped queues (`/team/approvals`, `/office/approvals`) are unchanged; leave plugs in as a
  new `RequestType` and a new effect, the same shape M6a's design proved out.
- **Filing debits nothing until fully approved** — the ledger only ever gains a debit row
  once the chain reaches `approved`, never on `submitted` or `manager_approved`, so a
  rejected-at-HR request never touched the balance it would have spent.
- A `leave_request` reuses `<RequestCard>` and both approval queues — no new UI shell, only
  a new type-specific submission form (mirroring the M6a correction form) and a new
  detail table, the same "submission stays type-specific, everything after doesn't" split
  M6a established.

**Done when:** an employee requests SIL, their manager approves the first step, HR approves
the second, the leave ledger debits the balance, and the compute engine reads it alongside
attendance the way an approved adjustment already does. `scripts/e2e-leave.sh` proves it
live.

## M6c — Overtime pre-authorization

- Overtime pre-authorization. The engine pays `min(actual_worked, approved)` and surfaces
  the remainder as **unpaid excess time** — visible, never silently converted to money.
- A new `RequestType` and `RequestEffect`, same spine, same queues, same card — the
  pattern M6a proved and M6b-b will have exercised a second time by then.

**Done when:** an employee's pre-authorized overtime caps what the engine pays for a day
that ran long, and the excess shows up as unpaid time rather than vanishing or silently
being paid anyway. `scripts/e2e-leave-and-ot.sh` proves the leave and OT paths together.

## M7 — Cutoffs, locking, and payroll export

The milestone that makes the number defensible.

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

## M8 — Admin portal and audit

- Organization, office, and department CRUD; the multi-step employee profiler
  (`<Wizard>`); role management; `hr_admin_offices` assignment; activity-log viewer with
  filters by actor, subject, and action.
- **Archive, never delete.** No `DELETE` route anywhere under `/admin/*`, carried over
  from POS — an employment record is a legal document with a retention obligation, not a
  row someone gets to remove.

**Done when:** a company can be configured from an empty database entirely through the
UI, and the audit log shows every step of it.

## M9 — Containerization and production

- `compose.prod.yml`: single FrankenPHP edge, host-routed TLS, no-CORS preserved end to
  end. Production images for API and web.
- Backups with a runnable restore drill.
- CI building images on every PR; all suites green inside the stack via `make test`.

**Done when:** `make prod-up` and `make restore-drill` are both green.

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
