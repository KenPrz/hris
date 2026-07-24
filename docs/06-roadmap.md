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
sections below (`## M5` onward) still carry their *pre-resequencing* titles and content —
"Requests and approvals," "Cutoffs," and so on. Each is renumbered and re-specced through
its own brainstorm when it is reached (as M3 was here); until then, read the table for
order and the sections below for the substance of each unit of work, not their heading
number.

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

**Status: complete.** **266 backend tests** (M0–M3's 201 + 65 for this milestone — schema,
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

**Status: complete.** **165 frontend tests** (up from 16 at the end of M3.6), backend
**unchanged at 267 + 17 arch** — this milestone touches no PHP. `lint`, `test`, `typecheck`,
and `build` are all green, native and inside the `make test` containers alike. What the
building turned on, for whoever extends the frontend next:

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

Everything M3.5 and M5 will read becomes admin-editable, per office.

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
  enqueue a recompute that is itself audited.
- UI: `/office/holidays`, `/office/schedules`, `/admin/pay-rules`, using `<MonthCalendar>`
  for the third time — the reason it exists.

**Done when:** HR adds August 21 (Ninoy Aquino Day) as a special non-working day for the
Manila office only, recompute runs, affected Manila days flip 100% → 130%, Cebu is
untouched, and the activity log names who did it and when.

## M5 — Requests and approvals

- Leave types configurable per office: paid/unpaid, requires attachment, deducts from
  balance, convertible to cash, max carryover. Seeded with the PH statutory set — SIL
  (5 days after one year, Art. 95), Maternity 105 days (RA 11210), Paternity 7 days
  (RA 8187), Solo Parent 7 days (RA 11861), VAWC 10 days (RA 9262), Magna Carta special
  leave (RA 9710) — plus company VL/SL.
- `leave_ledger`: every credit and debit is a row with a reason. Balances are derived,
  never stored as a mutable number, for the same reason POS made stock a ledger.
- Overtime pre-authorization. The engine pays `min(actual_worked, approved)` and surfaces
  the remainder as **unpaid excess time** — visible, never silently converted to money.
- Attendance adjustments. A punch-in with no punch-out computes as **zero paid hours**,
  flagged `incomplete`; the employee files an adjustment, and an approved adjustment
  creates a correction row that the engine reads alongside — never over — the original.
- **One state machine, three request types.** `draft → submitted → manager_approved →
  hr_approved → approved`, with `rejected` and `cancelled` terminal. A per-type
  `requires_hr_step` flag decides whether step two exists. One `SubmitRequest`,
  `ApproveRequest`, `RejectRequest`; one `<RequestCard>`; one approval queue.
- Optimistic updates, confined to the approval queue — short list, status flip, obvious
  rollback. Everywhere else invalidates by key prefix.
- `/team/approvals` for managers, `/office/approvals` for HR.

**Done when:** an employee forgets to clock out, the day shows zero hours and
`incomplete`, they file an adjustment, their manager approves, HR approves, the day
recomputes to the correct breakdown — and the original punch row is byte-identical to
what it was before. `scripts/e2e-leave-and-ot.sh` proves the leave and OT paths.

## M6 — Cutoffs, locking, and payroll export

The milestone that makes the number defensible.

- `cutoff_periods` per office. Semi-monthly by default (1–15, 16–EOM), which is what
  most PH employers actually run.
- `CloseCutoff` refuses while unresolved exceptions remain — incomplete days, pending
  adjustments on in-period dates. Closing sets the period's summaries to `locked`.
- `ApproveRequest` must `lockForUpdate()` the affected summaries and refuse on a locked
  period; `CloseCutoff` locks the period row first. **This race needs the two-real-
  connections test** `04-backend-conventions.md` demands — a single-process test passes
  whether or not the lock is even there, which makes it worse than no test.
- `ReopenCutoff` exists, requires a reason, and is loudly audited.
- Payroll export: per employee per period, the full earnings breakdown — regular, late,
  undertime, OT, night differential, holiday premium, leave with pay — in integer minutes
  and basis points, with the `rule_version_id` that produced each line.

**Done when:** close a period; an approval on a locked day is refused with a domain error
rather than silently succeeding; the export reconciles line-for-line against the calendar
view; and `make restore-drill`-style, a recompute of a closed period returns byte-identical
numbers.

## M7 — Admin portal and audit

- Organization, office, and department CRUD; the multi-step employee profiler
  (`<Wizard>`); role management; `hr_admin_offices` assignment; activity-log viewer with
  filters by actor, subject, and action.
- **Archive, never delete.** No `DELETE` route anywhere under `/admin/*`, carried over
  from POS — an employment record is a legal document with a retention obligation, not a
  row someone gets to remove.

**Done when:** a company can be configured from an empty database entirely through the
UI, and the audit log shows every step of it.

## M8 — Containerization and production

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
