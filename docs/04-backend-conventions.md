# Backend Conventions

Laravel 13 / PHP 8.5. These are rules, not suggestions — the value of this pattern is
entirely in its uniformity. One system action looks exactly like every other one, so
there is nothing to learn twice.

Ported from `../pos/docs/04-backend-conventions.md`. This codebase is a deliberate
sibling and does not invent a second house style; where HRIS diverges, the divergence is
called out rather than left to be discovered.

## The shape

**One system action = one route = one single-action controller = one Action class.**

```
Route  →  Controller (__invoke)  →  FormRequest   validate + authorize + map to Input
                                 →  Action        execute the work, return a domain object
                                 →  Resource      serialize to the standard envelope
```

The controller is the whole HTTP layer, and it is three lines:

```php
final class RecordPunchController
{
    public function __invoke(RecordPunchRequest $request, RecordPunch $action): AttendanceLogResource
    {
        return new AttendanceLogResource($action->execute($request->toInput()));
    }
}
```

```php
Route::post('/attendance/punch', RecordPunchController::class)
    ->middleware(['auth:sanctum', 'idempotent']);
```

If a controller ever grows a fourth line, the logic belongs in the action.

The one endpoint that exists today, `GET /api/v1/health`, is built exactly this way
(`app/Http/Controllers/System/HealthController.php` → `app/Actions/System/CheckHealth.php`
→ `app/Http/Resources/HealthResource.php`) so the first endpoint sets the shape every
later one copies. Its controller has a fourth concern — it maps a healthy/unhealthy
domain result onto `200`/`503` rather than throwing — and that is the documented
exception, not a precedent: monitoring needs a body describing the failure either way.

## The rules

1. **An Action never touches HTTP.** No `Request`, no `Response`, no `abort()`, no status
   codes. It takes an Input DTO and returns a domain object or `void`.
2. **An Action never returns a Resource.** Serialization is the controller's job.
3. **An Action owns its transaction boundary.** The action *is* the unit of business
   work, so it is also the unit of atomicity.
4. **An Action is named as an imperative verb phrase.** `RecordPunch`,
   `ComputeDailySummary`, `CloseCutoff`. Not `AttendanceService`, not `PunchCreator`,
   not `AttendanceManager`.
5. **A FormRequest validates, authorizes, and maps.** Nothing else.
6. **Models stay thin.** Casts, relations, scopes. No business logic.
7. **A computation that produces a premium reads `is_art82_exempt` first.** Managerial
   employees and field personnel are outside Art. 82: no overtime, no night differential,
   no holiday premium, no service incentive leave. `tests/Arch/` enforces this from M1.

Rule 1 is the one that pays. Because an action has no HTTP dependency, the same
`ComputeDailySummary` is callable from a controller, an Artisan command, a seeder, a
queued recompute job, and a test — with no HTTP kernel booted and no route hit. That is
also why actions take an Input DTO rather than the FormRequest itself: passing the request
in would drag HTTP into the domain and quietly cost us every one of those call sites.

Rule 7 is the HRIS-only addition, and it is here rather than in the domain docs because
it is a *shape* rule: the exemption is an input to the computation, not a filter applied
to its output. A premium computed and then suppressed is one refactor away from being
paid.

`tests/Arch/ConventionsTest.php` mechanically enforces what is mechanically checkable —
rules 1 and 2 in full (actions never touch `Request`, `Response`, `JsonResponse`,
`JsonResource`, or `FormRequest`), plus actions are final, controllers are final and
invokable, the domain layer is framework-agnostic, domain exceptions extend the base, no
`env()` outside `config/`, no debug helpers, `strict_types` everywhere. Rules 3, 4, 5,
and 6 are review's job until the code exists to check them against. A convention nobody
checks is a suggestion.

## Layering

| Layer | Lives in | Does | Example |
| --- | --- | --- | --- |
| **Action** | `app/Actions/` | Orchestrates one system action. Owns the transaction. | `CheckHealth`, later `RecordPunch` |
| **Service** | `app/Domain/` | Stateless collaborator shared by several actions. | `EmployeeScope`, `ScheduleResolver`, `PayMultiplier` |
| **Value object** | `app/Domain/` | Pure math, no I/O. | `Minutes`, `Money`, `BasisPoints` |
| **Model** | `app/Models/` | Persistence. Casts, relations, scopes. | `Employee` |

Deciding where code goes:

- Pure math with no I/O → **value object**. (All of M1 in `06-roadmap.md` is this.)
- Needed by two or more actions → **service**.
- Otherwise → keep it in the **action**.

**Actions orchestrate; they do not nest deeply.** Prefer an action calling services over
an action calling actions. Action→action is allowed only when the inner one is a genuine
standalone system action whose side effects (audit entry, events) you actually want.
Since `DB::transaction()` nests as a savepoint, a composed action correctly joins the
caller's transaction rather than opening a second one — but a graph of actions calling
actions is how this pattern rots into the service-layer tangle it exists to avoid.

## Input DTOs

```php
final readonly class RecordPunchInput
{
    public function __construct(
        public string $employeeId,
        public CarbonImmutable $occurredAt,   // UTC; see 01-architecture.md
        public PunchSource $source,
        public string $actorId,
    ) {}
}
```

Mapping lives on the FormRequest, next to the rules that guarantee it's safe:

```php
final class RecordPunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attendance.punch');
    }

    public function rules(): array
    {
        return [
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'source'      => ['required', Rule::enum(PunchSource::class)],
        ];
    }

    public function toInput(): RecordPunchInput
    {
        return new RecordPunchInput(
            employeeId: $this->user()->employee_id,
            occurredAt: CarbonImmutable::parse($this->string('occurred_at'))->utc(),
            source:     $this->enum('source', PunchSource::class),
            actorId:    $this->user()->id,
        );
    }
}
```

Two mapping rules that carry the same weight POS's numeric-string rule carries there,
for the same reason — the wire format is where precision and meaning get silently lost:

- **Calendar dates are validated as `date_format:Y-m-d` strings, never `date`.** A punch
  at 00:30 Asia/Manila belongs to the 30th, and a lenient parser plus a browser in
  another zone is how it becomes the 29th.
- **Durations arrive and leave as integer minutes**, validated `integer`, never `numeric`.
  `numeric` lets `7.333` through on its way to an integer column, which is precisely the
  decimal-hours rounding the whole system exists to avoid (`01-architecture.md`).

## Two subtleties this pattern must get right

These are the places where "put it in the FormRequest" is the obvious move and the
wrong one. Both are inherited from POS wholesale; both land in code in M3 and M6.

### The version check cannot live in the FormRequest

Where a mutation is guarded by an expected version, it is tempting to validate that
version in `rules()` — and it would be a time-of-check-to-time-of-use race. The
FormRequest runs *before* the transaction opens, so between the check passing and the
write landing, another writer can bump the version.

The FormRequest may only confirm the header **is present and well-formed**. The actual
compare happens inside the action, inside the transaction, after the row is locked.

### Idempotency middleware wraps the action's transaction

`01-architecture.md` requires that the idempotency key and the work it guards commit
**together**, or not at all. Middleware ordinarily runs outside the transaction, so the
middleware has to open it, and the action's own `DB::transaction()` becomes a savepoint
inside it. One commit covers both the key and the punch.

Two requirements fall out, and both need tests rather than trust:

- The middleware must sit where **exceptions propagate through it**, so a failed action
  rolls back and leaves no key — a retry must be allowed to succeed.
- Only `2xx` stores a key. A refusal that might stop being a refusal — a punch rejected
  because the office IP allowlist had not been updated yet — must stay retryable.

`EnsureIdempotency` is ported from POS unchanged in M3. Punches need it from day one: a
mobile client on a flaky connection retries, and a double punch is a double day.

## Errors

Actions throw domain exceptions. They do not know HTTP status codes, because rule 1.

```php
abstract class DomainException extends \RuntimeException
{
    abstract public function errorCode(): string;   // 'period_locked'
    abstract public function httpStatus(): int;     // 409
    public function details(): array { return []; }
}
```

That base class is `app/Exceptions/Domain/DomainException.php` today. A concrete
subclass looks like:

```php
final class PeriodLocked extends DomainException
{
    public function __construct(
        private readonly string $employeeId,
        private readonly string $date,
        private readonly string $cutoffId,
    ) {
        parent::__construct('That day belongs to a closed cutoff.');
    }

    public function errorCode(): string { return 'period_locked'; }
    public function httpStatus(): int   { return 409; }

    public function details(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'date'        => $this->date,
            'cutoff_id'   => $this->cutoffId,
        ];
    }
}
```

A single place renders every `DomainException` into the envelope from `03-api.md`, so the
shape cannot drift: `app/Exceptions/ApiErrorEnvelope.php`, registered from `bootstrap/app.php`.

**HRIS-specific, and learned the hard way in POS:** that file also maps the *framework's*
own exceptions — validation, 401, 403, 404, 405, 429 — into the same envelope. Handling
only `DomainException` leaves Laravel's default shape leaking through for every 404, and
"one shape, everywhere, so the client has one code path" stops being true before the API
is a day old. Two details in there are deliberate:

- Validation failures render **400, not Laravel's default 422**. `03-api.md` reserves 422
  for requests that are structurally fine but semantically rejected.
- `details` is cast to an object, so empty details serialize as `{}` rather than `[]`. A
  client typing it as `Record<string, unknown>` should never be handed a JSON array.

Every `code` in the `03-api.md` error table is one class. The table and the
`app/Exceptions/Domain/` directory should be diffable against each other.

## Resources

Success is always `{"data": ...}`; errors are always `{"error": ...}`. Never both, never
a bare array.

```php
final class DailySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date'                  => $this->date->toDateString(),   // 'YYYY-MM-DD'
            'day_type'              => $this->day_type,
            'is_rest_day'           => $this->is_rest_day,
            'worked_minutes'        => $this->worked_minutes,         // int, always
            'overtime_minutes'      => $this->overtime_minutes,
            'night_diff_minutes'    => $this->night_diff_minutes,
            'late_minutes'          => $this->late_minutes,
            'multiplier_bp'         => $this->multiplier_bp,          // int basis points
            'earned_cents'          => $this->earned_cents,           // int centavos
            'status'                => $this->status,
            'rule_version_id'       => $this->rule_version_id,
        ];
    }
}
```

The casts that keep the wire format honest:

```php
protected function casts(): array
{
    return [
        'worked_minutes' => 'integer',      // int -> JSON number, never decimal hours
        'multiplier_bp'  => 'integer',      // 200% is 20000
        'earned_cents'   => 'integer',      // bigint -> int
        'date'           => 'immutable_date',
    ];
}
```

Three suffixes, and they are load-bearing: `_minutes`, `_bp`, `_cents`. A field carrying
a duration, a multiplier, or an amount without one of them is a review comment.

Guard against N+1 by loading relations in the action's return (`$model->fresh([...])`) and
using `whenLoaded()` in the resource — never a lazy load inside serialization.

## Layout

The tree as it exists today, with the directories M1–M8 fill in:

```
app/
  Actions/
    System/      CheckHealth
                 (M3) Attendance/ RecordPunch, ComputeDailySummary
                 (M5) Requests/   SubmitRequest, ApproveRequest, RejectRequest
                 (M6) Cutoffs/    CloseCutoff, ReopenCutoff, ExportPayroll
  Domain/
    System/      HealthStatus
                 (M1) Money, Minutes, BasisPoints, DayType, PayMultiplier,
                      NightDiffSplitter, PunchPairer, OvertimeThreshold
                 (M2) Scope/ EmployeeScope
  Exceptions/
    ApiErrorEnvelope           the one definition of the error envelope
    Domain/                    DomainException + one class per code in 03-api.md
  Http/
    Controllers/ one __invoke class per action
    Requests/    one per action
    Resources/   HealthResource, ...
    Middleware/  (M3) EnsureIdempotency
  Models/
  Providers/     AppServiceProvider — boot-time configuration assertions
```

`app/Actions/` mirrors `03-api.md` almost line for line, and `routes/api.php` says so in a
comment. That's intentional: the endpoint list and the action list are the same list, so
an endpoint with no action (or vice versa) is a visible bug.

## Configuration

### The rule

**Config is what engineers change and deploy. The database is what admins change at
runtime.**

Ask one question: *does someone need to change this without a deploy?* If yes, it's a
row. If no, it's config. Nothing goes in both — a setting with two homes has two values,
and the one you're reading is the wrong one.

The trap this avoids is the settings table that grows to hold engineering knobs, at which
point tuning a rate limit means a production `UPDATE` with no code review, no history, and
no way to test the change before it's live.

**One HRIS-specific addition:** some database-owned values still have a code-owned
*floor*, because the Labor Code sets one. Pay multipliers are rows — DOLE reissues
advisories and a multiplier change must not require a deploy — but the statutory minimum
each row is validated against is a constant in `config/hris.php`. Configuring 100% on a
regular holiday is refused at the boundary, not discovered at payday. This is not an
exception to the rule above; the floor and the rate are two different settings with two
different owners, and neither lives in both places.

### Where each setting lives

| Setting | Home | Why |
| --- | --- | --- |
| Currency (PHP) | `config/hris.php` | Fixed at setup. Changing it is a data migration. |
| Organization name | `config/hris.php` | Changes roughly never; a deploy is fine. |
| App timezone (UTC) | `config/hris.php` / `.env` | Structural. Display timezone is per-office. |
| Session TTL, rate limits | `config/hris.php` | Security knobs. Should go through review. |
| Idempotency key TTL | `config/hris.php` | Engineering detail; no admin has an opinion. |
| **Statutory pay-rate floors** | `config/hris.php` | The Labor Code sets them, not an admin. Validated against on every `pay_rules` write. |
| Office timezone | `offices` | Per-office; set when a branch opens. |
| Office geofence, IP allowlist | `offices` | Per-office; operations edits it. |
| Holiday calendars | `holidays` | Set by annual proclamation. Admin-editable, has history. |
| **Pay multipliers** | `pay_rules` | DOLE reissues advisories. Effective-dated rows, never a deploy. |
| Leave types and accrual | `leave_types` | Company policy; admin-editable. |
| Roles → permissions | Seeder | Code. See `05-rbac.md`. |

The table is where each setting *lands*. Only the first three rows exist today — M0 has no
sessions, no rate limits, no idempotency, and no schema — and the rest arrive with the
milestone that needs them.

### `config/hris.php`

As it stands at M0:

```php
return [
    'version' => env('HRIS_VERSION', 'dev'),

    // ISO-4217. Fixed at setup — changing it is a data migration, not a setting.
    'currency' => env('HRIS_CURRENCY'),

    // The operating company. Per-office identity lives on `offices` (M2).
    'organization_name' => env('HRIS_ORGANIZATION_NAME'),
];
```

`currency` and `organization_name` deliberately have **no default**. A default is how a
missing environment variable becomes a plausible-looking wrong value instead of a failed
boot — see the fail-fast rule below.

### Rules

- **Never call `env()` outside a config file.** `php artisan config:cache` in production
  makes every `env()` call elsewhere return `null` — silently. A `null` currency or a
  `null` statutory floor fails in ways that look like data corruption, not
  misconfiguration. This is the single most common Laravel production footgun and it costs
  nothing to avoid. `tests/Arch/ConventionsTest.php` fails the build on any `env()` call
  outside `config/`.
- **Config is read at the edge, not in the domain.** A value object takes the floor as a
  constructor argument; it does not call `config()`. Otherwise every unit test needs a
  booted container to test integer arithmetic, and M1 in `06-roadmap.md` stops being pure.
- **Money in config is `_cents`, integers; multipliers are `_bp`, integers.** Same as
  everywhere else (`01-architecture.md`).
- **Fail fast on boot.** `AppServiceProvider::assertConfigured()` throws if
  `hris.currency` or `hris.organization_name` is blank, or if `app.timezone` is anything
  other than `UTC`. A missing currency should stop the app starting, not surface as a
  wrong payslip at the end of the cutoff; a non-UTC timezone should stop it starting
  rather than write Manila local times into `timestamptz` columns for a month.

### Environment

```dotenv
APP_ENV=production
APP_KEY=
APP_URL=https://hris.example.com
APP_TIMEZONE=UTC

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=hris
DB_USERNAME=hris
DB_PASSWORD=

HRIS_VERSION=dev
HRIS_CURRENCY=PHP
HRIS_ORGANIZATION_NAME="Example Company Inc."
```

Secrets live only in the environment. `backend/.env.example` ships with every key present,
so a missing variable is a diff rather than a discovery. The repo root has a *second*
`.env.example`, read only by `compose.dev.yml` — host ports, the dev app key, the dev
database password. They are separate files because they configure separate things: one is
the application, the other is the machine it runs on.

### No global settings table

There is deliberately no `settings` key-value table in v1. It's the natural home for
business-level values, and it's also how config discipline dies — untyped, untested,
unversioned, and edited in production. The values an admin genuinely needs to change
without a deploy are all *typed rows with history*: `holidays`, `pay_rules`,
`leave_types`, `offices`. That is not a coincidence; each of them is something someone
will one day have to justify to DOLE, and a key-value blob cannot be justified.

**Trigger to add one:** an admin needs to change a business-level value that is neither
per-office (that's an `offices` column) nor effective-dated (that's a table) without a
deploy. Until then, `config/hris.php` is typed, diffable, reviewable, and deployed with
the code that reads it.

## Testing

The pattern's real payoff:

- **Actions are tested directly.** Construct the input, call `execute()`, assert on the
  database. No HTTP, no routes, no serialization. This is where the majority of tests
  live, and every invariant in `06-roadmap.md` is an action test.
- **Controllers get one thin smoke test each** — right status, right envelope. There is
  no logic in them to test.
- **Value objects are tested exhaustively** and cost nothing to run. The entire DOLE
  premium matrix (M1) is one table-driven unit test with zero database.
- **Real Postgres**, never SQLite (`01-architecture.md`) — `SELECT … FOR UPDATE`, partial
  unique indexes, `jsonb`, `timestamptz`, and exclusion constraints are the whole point,
  and SQLite silently lacks all of them.

Concurrency tests need two real connections to prove the locking works. A single-process
test will pass whether or not `lockForUpdate()` is even there, which makes it worse than
no test — it's a green check mark asserting nothing. The M6 race between approving a
request and closing the cutoff that contains it is the one that must be tested this way.

## Worked example

The worked example lands in M3, when the first action with real input exists
(`RecordPunch`). Until then, `CheckHealth` is the only action and it takes no input, so
there is no FormRequest and no Input DTO — see `app/Actions/System/CheckHealth.php`.
