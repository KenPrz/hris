# M6c — Overtime pre-authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An employee pre-authorizes overtime for a date; their manager (or office HR) approves it single-hop; the compute engine then pays `min(actual_worked_overtime, approved_overtime)` and surfaces the remainder as visible unpaid excess time. Unauthorized overtime is unpaid.

**Architecture:** The third reuse of the M6a request spine — single-hop like an attendance adjustment (`requiresHrStep() = false`), so no state-machine/authority/queue changes. A new `overtime_details` 1:1 table (PK = request id), a `SubmitOvertimeRequest` action + `POST /overtime/requests`, and an `OvertimeEffect` that writes no ledger — it only enqueues a `RecomputeRange` so the compute engine re-prices the day. The compute integration reads approved overtime via a new `OvertimeAuthorizationLookup` (mirroring `LeaveDayLookup`), caps the paid overtime bucket in `DailyComputation`, and persists the excess as a new `daily_attendance_summaries.unpaid_overtime_minutes` scalar column beside `late_minutes`/`undertime_minutes`.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 backend (Pest, real Postgres); Next 16 / React 19 / TS / Tailwind + Carbon tokens frontend (Vitest).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/`. Arch-enforced.
- Never call `env()` outside `config/`. Arch-enforced.
- Action classes are `final`, own their transaction, take an Input DTO, return a domain object, and know nothing about HTTP. Controllers are `final` and invokable. Arch-enforced.
- Domain layer is framework-agnostic **except** Eloquent query-builder wrappers are allowed (the `LeaveDayLookup`/`EmployeeScope`/`ApprovalQueues` precedent).
- Integer minutes everywhere — never a float. Basis points are integers.
- Success responses are `{"data": ...}`; errors are `{"error": ...}`. Never both, never a bare array.
- **404-not-403 existence discipline.** FormRequests validate ids **shape-only** (`uuid`/`date`), never `exists:` — an out-of-scope real id must 404 in the controller exactly like a nonexistent one, never 400/403.
- uuid v7 PKs (`DB::raw('uuidv7()')` default + `HasUuids`) where a table owns its id; a 1:1 detail table's PK **is** the request's id (no separate id column). String columns + PHP backed enums + `CHECK` constraints — never a Postgres native enum; the CHECK list is pinned to `Enum::cases()` by a schema test.
- `timestamptz` for timestamps; calendar dates are `YYYY-MM-DD` strings on the wire.
- Tests run against real PostgreSQL, never SQLite.
- **Every premium computation reads `is_art82_exempt` first** — art82-exempt employees earn no overtime premium, so the cap is not consulted for them.
- **Approve-as-filed:** the manager approves the exact minutes filed. Approving a smaller amount is out of scope (deferred).
- **Strict model:** no approval covering a date → `approvedOvertimeMinutes = 0` → all worked overtime beyond the threshold is unpaid excess.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL. Message body only.

---

### Task 1: `RequestType::Overtime` + single-hop flag + `requests_type_check` widen

**Files:**
- Modify: `backend/app/Domain/Requests/RequestType.php`
- Create: `backend/database/migrations/2026_08_09_000001_add_overtime_request_type.php`
- Test: `backend/tests/Feature/Requests/RequestTypeTest.php` (or the existing enum/schema test — see Step 1)

**Interfaces:**
- Produces: `RequestType::Overtime` (`'overtime'`); `RequestType::Overtime->requiresHrStep() === false`. The `requests_type_check` CHECK admits `'overtime'`.

- [ ] **Step 1: Locate the existing RequestType test.** Run `ls backend/tests/Feature/Requests/ backend/tests/Unit/Requests/ 2>/dev/null` and `grep -rl "requiresHrStep\|requests_type_check" backend/tests`. Add the new assertions to whichever test already pins `RequestType`/the CHECK. If none exists, create `backend/tests/Feature/Requests/RequestTypeTest.php`.

- [ ] **Step 2: Write the failing test.** Add:

```php
it('marks overtime as a single-hop type', function (): void {
    expect(RequestType::Overtime->requiresHrStep())->toBeFalse();
    expect(RequestType::Overtime->value)->toBe('overtime');
});

it('admits overtime in the requests type check', function (): void {
    // The CHECK list must equal RequestType::cases() — pin them together.
    $checked = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'requests_type_check'"
    )->def;
    foreach (RequestType::cases() as $case) {
        expect($checked)->toContain("'{$case->value}'");
    }
});
```

Add `use App\Domain\Requests\RequestType;` and `use Illuminate\Support\Facades\DB;` at the top if not present.

- [ ] **Step 3: Run it, verify it fails.** Run: `cd backend && ./vendor/bin/pest --filter=RequestType`. Expected: FAIL — `Overtime` is not a case / CHECK lacks `'overtime'`.

- [ ] **Step 4: Add the enum case.** In `RequestType.php`, add the case and its `requiresHrStep()` arm:

```php
enum RequestType: string
{
    case AttendanceAdjustment = 'attendance_adjustment';
    case Leave = 'leave';
    case Overtime = 'overtime';

    /** Whether this type is a two-hop (manager -> HR) flow, vs. single-hop manager-only. */
    public function requiresHrStep(): bool
    {
        return match ($this) {
            self::AttendanceAdjustment => false,
            self::Leave => true,
            self::Overtime => false,
        };
    }
}
```

- [ ] **Step 5: Write the migration.** Mirror `2026_08_05_000001_add_leave_request_type.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the request spine to admit overtime pre-authorization alongside attendance
 * adjustment and leave. Overtime is single-hop (RequestType::requiresHrStep() === false),
 * routed exactly like an attendance adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment','leave','overtime'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment','leave'))");
    }
};
```

- [ ] **Step 6: Run the test, verify it passes.** Run: `cd backend && ./vendor/bin/pest --filter=RequestType`. Expected: PASS.

- [ ] **Step 7: Commit.**

```bash
git add backend/app/Domain/Requests/RequestType.php backend/database/migrations/2026_08_09_000001_add_overtime_request_type.php backend/tests
git commit -m "M6c: add overtime request type (single-hop) + widen requests_type_check"
```

---

### Task 2: `overtime_details` table + model + factory + `RequestResource` branch

**Files:**
- Create: `backend/database/migrations/2026_08_09_000002_create_overtime_details_table.php`
- Create: `backend/app/Models/OvertimeDetail.php`
- Create: `backend/database/factories/OvertimeDetailFactory.php`
- Modify: `backend/app/Models/Request.php` (add `overtimeDetail()` relation)
- Modify: `backend/app/Http/Resources/RequestResource.php` (add the `Overtime` match arm)
- Test: `backend/tests/Feature/Requests/OvertimeDetailSchemaTest.php`

**Interfaces:**
- Consumes: `RequestType::Overtime` (Task 1).
- Produces: `overtime_details(request_id PK, date, minutes>0)`; `OvertimeDetail` model (PK `request_id`, no timestamps, casts `date`→date, `minutes`→int); `Request::overtimeDetail(): HasOne`; `RequestResource` serializes `{ date, minutes }` under `detail` for an overtime request.

- [ ] **Step 1: Write the migration.** Mirror `2026_08_06_000001_create_leave_details_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The overtime pre-authorization request's 1:1 detail — mirrors leave_details: the primary
 * key IS the request's id (no separate id column), one request, one detail, enforced by the
 * database. A single business date and the requested-and-approved overtime minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_details', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->foreign('request_id')->references('id')->on('requests')->cascadeOnDelete();

            $table->date('date');
            $table->integer('minutes');
        });

        DB::statement('ALTER TABLE overtime_details ADD CONSTRAINT overtime_details_minutes_pos_check CHECK (minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_details');
    }
};
```

- [ ] **Step 2: Write the model.** Mirror `LeaveDetail`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OvertimeDetail extends Model
{
    /** @use HasFactory<\Database\Factories\OvertimeDetailFactory> */
    use HasFactory;

    // A true 1:1 with requests: the primary key IS the request's id, not a generated one.
    protected $primaryKey = 'request_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'date',
        'minutes',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Request, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
```

- [ ] **Step 3: Add the relation on `Request`.** Find the existing `leaveDetail()` relation in `backend/app/Models/Request.php` and add directly after it:

```php
    /** @return HasOne<OvertimeDetail, $this> */
    public function overtimeDetail(): HasOne
    {
        return $this->hasOne(OvertimeDetail::class, 'request_id');
    }
```

If `HasOne` is not already imported, add `use Illuminate\Database\Eloquent\Relations\HasOne;` (it will be — `leaveDetail()` uses it).

- [ ] **Step 4: Write the factory.** Note `date` and `minutes` are independent (no cross-field CHECK like leave's date range), so no derivation needed:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OvertimeDetail>
 */
final class OvertimeDetailFactory extends Factory
{
    protected $model = OvertimeDetail::class;

    public function definition(): array
    {
        return [
            'request_id' => Request::factory(),
            'date' => $this->faker->date(),
            'minutes' => $this->faker->numberBetween(30, 240),
        ];
    }
}
```

- [ ] **Step 5: Write the schema test.**

```php
<?php

declare(strict_types=1);

use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects non-positive overtime minutes at the database', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);

    DB::table('overtime_details')->insert([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 0,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('persists an overtime detail keyed by the request id', function (): void {
    $request = Request::factory()->create(['type' => 'overtime']);

    $detail = OvertimeDetail::query()->create([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 120,
    ]);

    expect($detail->request_id)->toBe($request->id)
        ->and($detail->minutes)->toBe(120)
        ->and($request->fresh()->overtimeDetail->minutes)->toBe(120);
});
```

- [ ] **Step 6: Add the `RequestResource` arm.** In `RequestResource.php`'s `detail` match, add after the `RequestType::Leave` arm:

```php
                RequestType::Overtime => $this->overtimeDetail === null ? null : [
                    'date' => $this->overtimeDetail->date->toDateString(),
                    'minutes' => $this->overtimeDetail->minutes,
                ],
```

- [ ] **Step 7: Run the tests, verify they pass.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeDetailSchema`. Expected: PASS (2 tests).

- [ ] **Step 8: Commit.**

```bash
git add backend/database/migrations/2026_08_09_000002_create_overtime_details_table.php backend/app/Models/OvertimeDetail.php backend/database/factories/OvertimeDetailFactory.php backend/app/Models/Request.php backend/app/Http/Resources/RequestResource.php backend/tests
git commit -m "M6c: overtime_details table + model + factory + RequestResource branch"
```

---

### Task 3: `SubmitOvertimeRequest` action + `POST /overtime/requests`

**Files:**
- Create: `backend/app/Actions/Overtime/SubmitOvertimeRequestInput.php`
- Create: `backend/app/Actions/Overtime/SubmitOvertimeRequest.php`
- Create: `backend/app/Http/Requests/SubmitOvertimeRequestRequest.php`
- Create: `backend/app/Http/Controllers/Overtime/SubmitOvertimeRequestController.php`
- Modify: `backend/routes/api.php` (add the route)
- Modify: `backend/app/Domain/Requests/ApprovalQueues.php` (add `Overtime` to `$singleHopTypes`)
- Test: `backend/tests/Feature/Overtime/SubmitOvertimeRequestTest.php`
- Test: `backend/tests/Feature/Requests/ApprovalQueuesTest.php` (or the existing queues test — see Step 9)

**Interfaces:**
- Consumes: `RequestType::Overtime`, `OvertimeDetail`, `RequestState::Pending` (existing).
- Produces: `SubmitOvertimeRequest::execute(SubmitOvertimeRequestInput): Request` — creates one `overtime` request (`state = pending`) + one `overtime_details` row; `POST /overtime/requests` returns `201` with the `RequestResource`. Input fields: `employeeId`, `date`, `minutes`, `note`. A pending overtime request appears in **both** the manager's `/team` queue and HR's `/office` queue (single-hop routing).

**IMPORTANT — plan correction (found in Task 1 review):** `ApprovalQueues.php` line ~58 hard-codes `$singleHopTypes = [RequestType::AttendanceAdjustment->value]` — a manually-maintained list, NOT derived from `requiresHrStep()`. Without adding `Overtime` here, a single-hop overtime request would never surface to HR's `/office` queue. Steps 9–11 below fix this. (`RequestAuthority::canDecide` needs no change — it already derives from `requiresHrStep()`.)

- [ ] **Step 1: Write the Input DTO.**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Overtime;

final readonly class SubmitOvertimeRequestInput
{
    public function __construct(
        public string $employeeId,
        public string $date,
        public int $minutes,
        public string $note,
    ) {}
}
```

- [ ] **Step 2: Write the action.** Always `pending` — single-hop needs no managerless auto-advance (HR can act on a managerless requester's pending request via `/office`). Mirror `SubmitLeaveRequest` minus the state branch and attachment:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Overtime;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates an overtime pre-authorization request and its 1:1 detail row — the submit step
 * only, mirroring SubmitLeaveRequest. Overtime is single-hop, so the request always starts
 * `pending`: there is no managerless auto-advance (a single-hop pending request is already
 * actionable by office HR at /office, unlike a two-hop leave that would otherwise stall).
 * Minutes are validated positive in the controller before this is called; this only persists.
 */
final class SubmitOvertimeRequest
{
    public function execute(SubmitOvertimeRequestInput $in): Request
    {
        return DB::transaction(function () use ($in): Request {
            $request = Request::query()->create([
                'type' => RequestType::Overtime,
                'employee_id' => $in->employeeId,
                'state' => RequestState::Pending,
                'note' => $in->note,
            ]);

            OvertimeDetail::query()->create([
                'request_id' => $request->id,
                'date' => $in->date,
                'minutes' => $in->minutes,
            ]);

            return $request->fresh(['overtimeDetail']);
        });
    }
}
```

- [ ] **Step 3: Write the FormRequest.** Shape-only validation; the employee files their own (any authenticated employee). `hours` is the client-facing unit, converted to minutes in the controller:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated employee files their own; NotAnEmployee is the controller's guard
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            // Hours in 0.25 steps, client-facing; the controller converts to integer minutes.
            'hours' => ['required', 'numeric', 'gt:0'],
            'note' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller.** Convert hours→minutes; reject a non-whole-minute result. The employee files for themselves (`$request->user()->employee`):

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Overtime;

use App\Actions\Overtime\SubmitOvertimeRequest;
use App\Actions\Overtime\SubmitOvertimeRequestInput;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\SubmitOvertimeRequestRequest;
use App\Http\Resources\RequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An employee filing their own overtime pre-authorization. Any employee may file —
 * deliberately not admin-gated, mirroring the attendance-adjustment and leave submit routes.
 * The client sends hours (quarter-hour granularity); this converts to the integer minutes the
 * domain stores. A fractional-minute request (e.g. 1.1h = 66min is fine, but a value that
 * does not land on a whole minute) is a validation error, never a silently rounded debit.
 */
final class SubmitOvertimeRequestController
{
    public function __invoke(SubmitOvertimeRequestRequest $request, SubmitOvertimeRequest $action): JsonResponse
    {
        $employee = $request->user()->employee ?? throw new NotAnEmployee;

        $hours = (float) $request->input('hours');
        $minutesFloat = $hours * 60.0;
        $minutes = (int) round($minutesFloat);

        if (abs($minutesFloat - $minutes) > 0.0001) {
            throw ValidationException::withMessages([
                'hours' => 'Overtime must be a whole number of minutes.',
            ]);
        }

        $result = $action->execute(new SubmitOvertimeRequestInput(
            employeeId: $employee->id,
            date: $request->string('date')->toString(),
            minutes: $minutes,
            note: $request->string('note')->toString(),
        ));

        return RequestResource::make($result)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 5: Register the route.** In `backend/routes/api.php`, directly after the `POST /leave/requests` route (`Route::post('/leave/requests', SubmitLeaveRequestController::class);`), add:

```php
        // Same shape as the leave/attendance-adjustment submissions above: any employee may
        // file their own overtime pre-authorization, not admin-gated. Single-hop — the
        // manager (or office HR) approves it once and the compute engine reads the cap.
        Route::post('/overtime/requests', \App\Http\Controllers\Overtime\SubmitOvertimeRequestController::class);
```

(Match the existing file's import style — if that file imports controllers at the top with `use`, add a `use App\Http\Controllers\Overtime\SubmitOvertimeRequestController;` there and reference it unqualified instead.)

- [ ] **Step 6: Write the feature test.**

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('files an overtime request as pending with its detail', function (): void {
    $employee = Employee::factory()->create();
    $user = User::factory()->for($employee)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 2,
        'note' => 'Month-end close',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'overtime')
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.detail.date', '2026-07-15')
        ->assertJsonPath('data.detail.minutes', 120);

    $request = Request::query()->where('type', 'overtime')->sole();
    expect(OvertimeDetail::query()->find($request->id)->minutes)->toBe(120);
});

it('rejects zero or negative hours', function (): void {
    $employee = Employee::factory()->create();
    $user = User::factory()->for($employee)->create();

    $this->actingAs($user)->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 0,
        'note' => 'x',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects hours that do not land on a whole minute', function (): void {
    $employee = Employee::factory()->create();
    $user = User::factory()->for($employee)->create();

    // 1.001h = 60.06 min — not a whole minute.
    $this->actingAs($user)->postJson('/api/v1/overtime/requests', [
        'date' => '2026-07-15',
        'hours' => 1.001,
        'note' => 'x',
    ])->assertStatus(422);
});
```

> **Note for the implementer:** confirm the validation-failure envelope code (`error.code`) and status against how the existing `SubmitLeaveRequest`/adjustment tests assert a FormRequest failure in this codebase — match that exact shape (this project maps FormRequest validation to `422`/`400` `validation_failed`; use whatever the sibling leave test asserts). The `User::factory()->for($employee)` association mirrors how existing request tests wire a user to an employee — check a sibling test (e.g. `SubmitLeaveRequestTest`) and copy its exact user/employee setup helper if it differs.

- [ ] **Step 7: Run the submit tests, verify they pass.** Run: `cd backend && ./vendor/bin/pest --filter=SubmitOvertimeRequest`. Expected: PASS.

- [ ] **Step 8: Route the single-hop overtime request to HR's `/office` queue.** In `backend/app/Domain/Requests/ApprovalQueues.php`, find `$singleHopTypes` (around line 58) and add `RequestType::Overtime->value`:

```php
        $singleHopTypes = [RequestType::AttendanceAdjustment->value, RequestType::Overtime->value];
```

Keep the surrounding comment's intent (it documents that this list is manually kept in sync with `requiresHrStep() === false`). Do NOT touch `RequestAuthority` — it already derives from `requiresHrStep()`.

- [ ] **Step 9: Write the failing queue test.** Find the existing approval-queues test (run `grep -rl "office\|singleHop\|ApprovalQueues\|/office/approvals" backend/tests | grep -i queue`; the M6b-b work added office/team queue tests — extend that file). Add a case: a `pending` **overtime** request for an employee in an HR-administered office appears in that HR user's `/office` queue (mirror exactly how the existing single-hop attendance-adjustment queue case is set up — same office/manager/HR wiring, just `type = overtime` with an `overtime_details` row). Also assert it appears in the manager's `/team` queue.

- [ ] **Step 10: Run the queue test, verify it passes.** Run: `cd backend && ./vendor/bin/pest --filter=Queue` (or the specific filter for the queues test file). Expected: PASS — the overtime request now surfaces to both queues.

- [ ] **Step 11: Commit.**

```bash
git add backend/app/Actions/Overtime backend/app/Http/Requests/SubmitOvertimeRequestRequest.php backend/app/Http/Controllers/Overtime backend/routes/api.php backend/app/Domain/Requests/ApprovalQueues.php backend/tests/Feature/Overtime backend/tests/Feature/Requests
git commit -m "M6c: SubmitOvertimeRequest action + POST /overtime/requests + office-queue routing"
```

---

### Task 4: `OvertimeEffect` + `RecomputeTrigger::Overtime` + factory arm

**Files:**
- Modify: `backend/app/Domain/Compute/RecomputeTrigger.php` (add `Overtime`)
- Create: `backend/app/Actions/Requests/Effects/OvertimeEffect.php`
- Modify: `backend/app/Actions/Requests/RequestEffectFactory.php` (add the `Overtime` arm)
- Test: `backend/tests/Feature/Requests/OvertimeEffectTest.php`

**Interfaces:**
- Consumes: `RequestEffect` interface, `RecomputeRange::dispatch`, `Request::overtimeDetail`.
- Produces: `OvertimeEffect implements RequestEffect` — on approval writes **no** ledger and enqueues one `RecomputeRange` over the detail's single date with `RecomputeTrigger::Overtime`; `RequestEffectFactory::for(RequestType::Overtime)` returns it.

- [ ] **Step 1: Add the trigger case.** In `RecomputeTrigger.php`:

```php
    case Leave = 'leave';
    case Overtime = 'overtime';
```

(Verify whether `recompute_runs.trigger` has a CHECK constraint pinned to this enum — run `grep -rl "recompute_runs" backend/database/migrations` and inspect. If a CHECK exists, widen it in a new migration `2026_08_09_000003_add_overtime_recompute_trigger.php` following the `requests_type_check` widen idiom, and add a schema test pinning it to `RecomputeTrigger::cases()`. If the column is a free `text` with no CHECK, no migration is needed — note which in the report.)

- [ ] **Step 2: Write the failing test.** Mirror `LeaveEffect`'s recompute assertion but assert **no ledger row**:

```php
<?php

declare(strict_types=1);

use App\Actions\Compute\RecomputeRange;
use App\Actions\Requests\Effects\OvertimeEffect;
use App\Domain\Compute\RecomputeTrigger;
use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('enqueues a recompute over the overtime date and writes no ledger', function (): void {
    Bus::fake();

    $employee = Employee::factory()->create();
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => 'approved',
    ]);
    OvertimeDetail::query()->create([
        'request_id' => $request->id,
        'date' => '2026-07-15',
        'minutes' => 120,
    ]);

    (new OvertimeEffect)->applyOnApproval($request->fresh(['overtimeDetail']), $employee->user?->id ?? \App\Models\User::factory()->create()->id);

    Bus::assertDispatched(RecomputeRange::class);
    // No leave_ledger / any ledger write — overtime authorization is the request itself.
    expect(\Illuminate\Support\Facades\DB::table('leave_ledger')->count())->toBe(0);
});
```

> **Note:** `RecomputeRange` is enqueued via `DB::afterCommit`. In a test with no surrounding transaction, `afterCommit` callbacks run immediately; if this test wraps in `RefreshDatabase`'s transaction, follow exactly how `LeaveEffect`'s existing test (`grep -rl "RecomputeRange" backend/tests`) asserts the dispatch — copy its `Bus::fake()` / transaction handling verbatim, since that test already solved this timing.

- [ ] **Step 3: Run it, verify it fails.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeEffect`. Expected: FAIL — `OvertimeEffect` does not exist.

- [ ] **Step 4: Write `OvertimeEffect`.** The recompute half of `LeaveEffect`, with no debit and a single date:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Compute\RecomputeRange;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Requests\RequestEffect;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * The overtime effect: on the request's single approval (overtime is single-hop, so the
 * one approval IS the final hop) it writes NOTHING — the approved request plus its
 * overtime_details.minutes IS the authorization the compute engine reads
 * (OvertimeAuthorizationLookup). Unlike LeaveEffect there is no ledger, no balance, no
 * lock: nothing to overdraw. It only enqueues a recompute over the authorized date so
 * ComputeDailySummary re-prices that day under the now-approved cap — via DB::afterCommit,
 * since a recompute-enqueue failure must never roll back an already-durable approval
 * (mirrors LeaveEffect / CreateHoliday).
 */
final class OvertimeEffect implements RequestEffect
{
    public function applyOnApproval(Request $request, string $approverUserId): void
    {
        $detail = $request->overtimeDetail;

        DB::afterCommit(function () use ($request, $detail): void {
            RecomputeRange::dispatch(
                collect([['employee_id' => $request->employee_id, 'date' => $detail->date->toDateString()]]),
                RecomputeTrigger::Overtime,
                $request->id,
                "Overtime request {$request->id} approved for employee {$request->employee_id}",
            );
        });
    }
}
```

> **Note:** verify `RecomputeRange::dispatch`'s exact signature and the `pairs` shape from `LeaveEffect` (it builds `['employee_id' => ..., 'date' => ...]` pairs via a `collect`). Match it — a single-element collection here since overtime authorizes one date. Confirm `$detail->date` is a Carbon (the model casts `date`→date) so `->toDateString()` is correct.

- [ ] **Step 5: Add the factory arm.** In `RequestEffectFactory::for`, add before the `default`:

```php
            RequestType::Overtime => app(OvertimeEffect::class),
```

and `use App\Actions\Requests\Effects\OvertimeEffect;` at the top.

- [ ] **Step 6: Run the tests, verify they pass.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeEffect`. Expected: PASS.

- [ ] **Step 7: Commit.**

```bash
git add backend/app/Domain/Compute/RecomputeTrigger.php backend/app/Actions/Requests/Effects/OvertimeEffect.php backend/app/Actions/Requests/RequestEffectFactory.php backend/tests/Feature/Requests/OvertimeEffectTest.php backend/database/migrations
git commit -m "M6c: OvertimeEffect (no ledger, recompute-only) + RecomputeTrigger::Overtime"
```

---

### Task 5: `OvertimeAuthorizationLookup` — the approved-minutes read

**Files:**
- Create: `backend/app/Domain/Overtime/OvertimeAuthorizationLookup.php`
- Test: `backend/tests/Feature/Overtime/OvertimeAuthorizationLookupTest.php`

**Interfaces:**
- Consumes: `RequestType::Overtime`, `RequestState::Approved`, `Request::overtimeDetail`.
- Produces: `OvertimeAuthorizationLookup::approvedMinutesFor(Employee $employee, string $date): int` — sum of `overtime_details.minutes` across the employee's `approved` overtime requests whose detail `date` equals `$date`; `0` when none.

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

use App\Domain\Overtime\OvertimeAuthorizationLookup;
use App\Models\Employee;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOvertimeRequest(Employee $employee, string $date, int $minutes, string $state): void
{
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => $state,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => $date, 'minutes' => $minutes]);
}

it('returns 0 when no approved overtime covers the date', function (): void {
    $employee = Employee::factory()->create();
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});

it('ignores non-approved overtime requests', function (): void {
    $employee = Employee::factory()->create();
    makeOvertimeRequest($employee, '2026-07-15', 120, 'pending');
    makeOvertimeRequest($employee, '2026-07-15', 60, 'rejected');
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});

it('sums approved overtime minutes for the date only', function (): void {
    $employee = Employee::factory()->create();
    makeOvertimeRequest($employee, '2026-07-15', 120, 'approved');
    makeOvertimeRequest($employee, '2026-07-15', 30, 'approved');
    makeOvertimeRequest($employee, '2026-07-16', 90, 'approved'); // different date
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(150);
});

it('does not count another employee\'s approved overtime', function (): void {
    $employee = Employee::factory()->create();
    $other = Employee::factory()->create();
    makeOvertimeRequest($other, '2026-07-15', 120, 'approved');
    expect(OvertimeAuthorizationLookup::approvedMinutesFor($employee, '2026-07-15'))->toBe(0);
});
```

- [ ] **Step 2: Run it, verify it fails.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeAuthorizationLookup`. Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the lookup.** Mirror `LeaveDayLookup`'s Eloquent-wrapper shape:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Overtime;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;

/**
 * How many overtime minutes are APPROVED (final-hop; overtime is single-hop, so `approved`
 * is the only terminal-approved state) for one employee on one business date — the cap
 * DailyComputation applies as min(actual_overtime, approved). The one fact the compute
 * engine needs; it never queries the database itself (see DailyComputation's purity
 * contract), so ComputeDailySummary resolves this and hands it in on DailyComputationInput.
 *
 * A query-builder wrapper over Eloquent, the same shape as LeaveDayLookup/EmployeeScope —
 * domain-Eloquent is allowed here for the same reason it is allowed there. Returns 0 when
 * nothing is approved: the strict model — unauthorized overtime caps at zero and reads as
 * unpaid excess.
 */
final class OvertimeAuthorizationLookup
{
    private function __construct() {}

    public static function approvedMinutesFor(Employee $employee, string $date): int
    {
        return (int) Request::query()
            ->where('employee_id', $employee->id)
            ->where('type', RequestType::Overtime)
            ->where('state', RequestState::Approved)
            ->join('overtime_details', 'overtime_details.request_id', '=', 'requests.id')
            ->whereDate('overtime_details.date', $date)
            ->sum('overtime_details.minutes');
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeAuthorizationLookup`. Expected: PASS (4 tests).

- [ ] **Step 5: Commit.**

```bash
git add backend/app/Domain/Overtime backend/tests/Feature/Overtime/OvertimeAuthorizationLookupTest.php
git commit -m "M6c: OvertimeAuthorizationLookup — approved overtime minutes per employee-day"
```

---

### Task 6: The compute cap — `min(actual, approved)` + unpaid excess

This is the atomic behavior change: the cap, the excess column, and the wiring must land together to keep the suite green (adding a required field to `DailyComputationInput` breaks every existing construction until updated).

**Files:**
- Create: `backend/database/migrations/2026_08_09_000004_add_unpaid_overtime_minutes_to_summaries.php`
- Modify: `backend/app/Domain/Compute/DailyComputationInput.php` (add `approvedOvertimeMinutes`)
- Modify: `backend/app/Domain/Compute/ComputedDay.php` (add `unpaidOvertimeMinutes`)
- Modify: `backend/app/Domain/Compute/DailyComputation.php` (cap + excess)
- Modify: `backend/app/Actions/Compute/ComputeDailySummary.php` (resolve lookup, pass field, persist column)
- Modify: `backend/app/Http/Resources/DailySummaryResource.php` (serialize `unpaid_overtime_minutes`)
- Modify: existing compute tests that construct `DailyComputationInput` or assert on summaries
- Test: `backend/tests/Feature/Compute/OvertimeCapTest.php` (new cap matrix)

**Interfaces:**
- Consumes: `OvertimeAuthorizationLookup::approvedMinutesFor` (Task 5).
- Produces: `DailyComputationInput->approvedOvertimeMinutes` (int, required, last param); `ComputedDay->unpaidOvertimeMinutes` (int); `daily_attendance_summaries.unpaid_overtime_minutes` column; `DailySummaryResource` emits `unpaid_overtime_minutes`.

- [ ] **Step 1: Write the migration.**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worked overtime minutes that fell beyond the pre-authorized cap — unpaid, shown, never
 * priced. A day-level scalar beside late_minutes/undertime_minutes (the same species: a
 * non-premium magnitude, not a priced daily_summary_lines row). Zero on every day with no
 * excess, so the default backfills every existing row cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->integer('unpaid_overtime_minutes')->default(0)->after('undertime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->dropColumn('unpaid_overtime_minutes');
        });
    }
};
```

- [ ] **Step 2: Write the failing cap test.** New matrix in `backend/tests/Feature/Compute/OvertimeCapTest.php`. Build a `DailyComputationInput` directly (pure, no DB). Use a helper mirroring existing `DailyComputation` unit tests — check `grep -rl "DailyComputation::compute" backend/tests` for the exact `PayRates`/input helper and reuse it. Assertions:

```php
// Pseudocode shape — use the existing test's input helper for punches/rates/day-type.
// Scenario: scheduled 480 (8h), worked 600 (10h) => 120 min overtime.

it('pays all overtime when it is within the approved cap', function (): void {
    // 10h worked, 8h scheduled, 120 approved => 120 paid OT, 0 excess.
    $day = DailyComputation::compute(makeInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 120));
    expect($day->unpaidOvertimeMinutes)->toBe(0);
    // overtime line minutes total 120
    expect(overtimeLineMinutes($day))->toBe(120);
});

it('caps paid overtime at the approved amount and marks the rest unpaid', function (): void {
    // 10h worked, 8h scheduled, only 60 approved => 60 paid OT, 60 excess.
    $day = DailyComputation::compute(makeInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 60));
    expect($day->unpaidOvertimeMinutes)->toBe(60);
    expect(overtimeLineMinutes($day))->toBe(60);
});

it('treats all overtime as unpaid excess when nothing is approved', function (): void {
    // 10h worked, 8h scheduled, 0 approved => 0 paid OT, 120 excess; regular 480 still paid.
    $day = DailyComputation::compute(makeInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 0));
    expect($day->unpaidOvertimeMinutes)->toBe(120);
    expect(overtimeLineMinutes($day))->toBe(0);
    expect(regularLineMinutes($day))->toBe(480);
});

it('never caps an art82-exempt employee', function (): void {
    // exempt, 10h worked, 8h scheduled, 0 approved => no excess, OT minutes still attributed.
    $day = DailyComputation::compute(makeInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 0, isArt82Exempt: true));
    expect($day->unpaidOvertimeMinutes)->toBe(0);
});
```

Implement `makeInput`/`overtimeLineMinutes`/`regularLineMinutes` helpers in the test (or reuse the existing suite's). `overtimeLineMinutes` sums `minutes` over lines whose `kind` is `overtime_day`/`overtime_night`; `regularLineMinutes` over `regular_day`/`regular_night`.

- [ ] **Step 3: Run it, verify it fails.** Run: `cd backend && ./vendor/bin/pest --filter=OvertimeCap`. Expected: FAIL — `approvedOvertimeMinutes` / `unpaidOvertimeMinutes` do not exist.

- [ ] **Step 4: Add the input field.** In `DailyComputationInput.php`, add as the **last** constructor param (after `onApprovedLeave`) with a doc line:

```php
        /**
         * @param  int  $approvedOvertimeMinutes  Overtime minutes pre-authorized for this
         *   date (OvertimeAuthorizationLookup, resolved by the caller). The paid-overtime
         *   ceiling is overtimeThresholdMinutes + this; worked minutes beyond it are unpaid
         *   excess. 0 when nothing is approved (the strict model). Not consulted for an
         *   art82-exempt employee, who has no overtime premium to withhold.
         */
        public int $approvedOvertimeMinutes,
```

- [ ] **Step 5: Add the `ComputedDay` field.** In `ComputedDay.php`, add after `undertimeMinutes`:

```php
        public int $undertimeMinutes,
        public int $unpaidOvertimeMinutes,
```

- [ ] **Step 6: Implement the cap in `DailyComputation`.** Replace `splitBuckets` and its caller:

In `compute()`, replace the `splitBuckets` call + `ComputedDay` return:

```php
        $paidCeiling = $in->isArt82Exempt
            ? PHP_INT_MAX
            : $in->overtimeThresholdMinutes + $in->approvedOvertimeMinutes;

        [$regularDay, $regularNight, $overtimeDay, $overtimeNight, $excess] =
            self::splitBuckets($keptIntervals, $in->overtimeThresholdMinutes, $paidCeiling);

        $lines = self::buildLines($in, $regularDay, $regularNight, $overtimeDay, $overtimeNight);

        $firstPunch = $paired->intervals[0]->startMinute;
        $late = $in->scheduledStartMinute === null ? 0 : max(0, $firstPunch - $in->scheduledStartMinute);
        $undertime = OvertimeThreshold::undertime($net, Minutes::of($in->scheduledMinutes))->value;

        return new ComputedDay(
            workedMinutes: $net->value,
            lateMinutes: $late,
            undertimeMinutes: $undertime,
            unpaidOvertimeMinutes: $excess,
            isIncomplete: false,
            lines: $lines,
        );
```

Update the two other `ComputedDay` constructions to pass `unpaidOvertimeMinutes: 0` — the incomplete-day early return (top of `compute()`) and `computeUnworkedDay()` (a no-punches day has no worked overtime):

```php
        // incomplete early return
        return new ComputedDay(
            workedMinutes: 0,
            lateMinutes: 0,
            undertimeMinutes: 0,
            unpaidOvertimeMinutes: 0,
            isIncomplete: true,
            lines: [],
        );
```

```php
        // end of computeUnworkedDay()
        return new ComputedDay(
            workedMinutes: 0,
            lateMinutes: 0,
            undertimeMinutes: $undertime,
            unpaidOvertimeMinutes: 0,
            isIncomplete: false,
            lines: $lines,
        );
```

Rewrite `splitBuckets` to take the paid ceiling and return the excess as a 5th element, reusing `splitAtBoundary` for the second boundary:

```php
    /**
     * Walks the (post-break) intervals in chronological order, attributing minutes to three
     * regions by two boundaries: regular below overtimeThreshold, PAID overtime between
     * overtimeThreshold and paidCeiling, and unpaid EXCESS beyond paidCeiling. Each priced
     * region is day/night split; the excess is a bare magnitude (unpaid — day/night is moot).
     *
     * @param  list<WorkInterval>  $intervals
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} regularDay, regularNight, overtimeDay, overtimeNight, excess
     */
    private static function splitBuckets(array $intervals, int $overtimeThreshold, int $paidCeiling): array
    {
        $regularDay = 0;
        $regularNight = 0;
        $overtimeDay = 0;
        $overtimeNight = 0;
        $excess = 0;
        $runningBefore = 0;

        foreach ($intervals as $interval) {
            // First boundary: regular vs. the rest (paid OT + excess).
            [$regularPart, $rest] = self::splitAtBoundary($interval, $runningBefore, $overtimeThreshold);

            if ($regularPart !== null) {
                $split = NightDiffSplitter::split($regularPart);
                $regularNight += $split->inside->value;
                $regularDay += $split->outside->value;
            }

            if ($rest !== null) {
                // Running total at the start of $rest: the boundary if the interval crossed
                // it, else where the interval began (it lies wholly beyond the threshold).
                $runningAtRest = max($runningBefore, $overtimeThreshold);

                // Second boundary: paid overtime vs. unpaid excess.
                [$paidPart, $excessPart] = self::splitAtBoundary($rest, $runningAtRest, $paidCeiling);

                if ($paidPart !== null) {
                    $split = NightDiffSplitter::split($paidPart);
                    $overtimeNight += $split->inside->value;
                    $overtimeDay += $split->outside->value;
                }

                if ($excessPart !== null) {
                    $excess += $excessPart->duration()->value;
                }
            }

            $runningBefore += $interval->duration()->value;
        }

        return [$regularDay, $regularNight, $overtimeDay, $overtimeNight, $excess];
    }
```

(`splitAtBoundary` is unchanged — it already returns `[belowPart, abovePart]` given a running-before and a boundary, and `PHP_INT_MAX` as the boundary safely yields `[wholeInterval, null]`.)

- [ ] **Step 7: Wire the lookup into `ComputeDailySummary`.** Add the resolve, pass the field, persist the column. After the `$onApprovedLeave = LeaveDayLookup::isOnApprovedLeave(...)` line:

```php
        $approvedOvertimeMinutes = OvertimeAuthorizationLookup::approvedMinutesFor($employee, $date);
```

Add `use App\Domain\Overtime\OvertimeAuthorizationLookup;` at the top. In the `new DailyComputationInput(...)` call, add as the last argument:

```php
            onApprovedLeave: $onApprovedLeave,
            approvedOvertimeMinutes: $approvedOvertimeMinutes,
```

In the `DailyAttendanceSummary::query()->create([...])` array, add after `'undertime_minutes' => $computed->undertimeMinutes,`:

```php
                'unpaid_overtime_minutes' => $computed->unpaidOvertimeMinutes,
```

Add `unpaid_overtime_minutes` to the `$computed` capture in the transaction's `use (...)` if the closure captures individual vars (it captures `$computed`, so no change needed — confirm).

- [ ] **Step 8: Add the field to `DailyAttendanceSummary` fillable/casts if needed.** Check `backend/app/Models/DailyAttendanceSummary.php` — if `late_minutes`/`undertime_minutes` are in `$fillable` and cast to `integer`, add `unpaid_overtime_minutes` alongside them the same way.

- [ ] **Step 9: Serialize it.** In `DailySummaryResource.php`, add after `'undertime_minutes' => $this->undertime_minutes,`:

```php
            'unpaid_overtime_minutes' => $this->unpaid_overtime_minutes,
```

- [ ] **Step 10: Update existing constructions.** Run `grep -rln "new DailyComputationInput" backend/tests backend/app` and `grep -rln "new ComputedDay" backend/tests backend/app`. For every existing test that builds a `DailyComputationInput`, add `approvedOvertimeMinutes:` — set it to a value **covering that test's worked overtime** (e.g. `approvedOvertimeMinutes: 9999`, a blanket "everything authorized") so the existing OT assertions are preserved unchanged. For any test that constructs a `ComputedDay` directly, add `unpaidOvertimeMinutes: 0`. Do the same for any factory/helper. **Do not change any existing assertion** — only add the new field with a value that keeps the current expectation true.

- [ ] **Step 11: Run the full compute suite, verify green.** Run: `cd backend && ./vendor/bin/pest --filter=Compute` then the new cap test `./vendor/bin/pest --filter=OvertimeCap`. Expected: PASS, including all pre-existing compute tests. Then run the whole backend suite `./vendor/bin/pest` to catch any missed construction site. Expected: PASS.

- [ ] **Step 12: Commit.**

```bash
git add backend/database/migrations/2026_08_09_000004_add_unpaid_overtime_minutes_to_summaries.php backend/app/Domain/Compute backend/app/Actions/Compute/ComputeDailySummary.php backend/app/Http/Resources/DailySummaryResource.php backend/app/Models/DailyAttendanceSummary.php backend/tests
git commit -m "M6c: compute pays min(actual, approved) overtime; unpaid excess as summary column"
```

---

### Task 7: Frontend data layer — types, api, hook, keys

**Files:**
- Modify: `frontend/web/src/lib/api.ts` (widen `RequestType`; add `OvertimeRequestDetail`, `OvertimeRequestInput`; add `api.overtime.submitRequest`; add `unpaid_overtime_minutes` to `DailySummary`)
- Modify: `frontend/web/src/lib/keys.ts` (no new key strictly needed — overtime submit invalidates `keys.requests.mine()`; add an `overtime` group only if a query is added)
- Create: `frontend/web/src/hooks/useSubmitOvertimeRequest.ts`
- Test: `frontend/web/src/hooks/useSubmitOvertimeRequest.test.tsx`

**Interfaces:**
- Consumes: existing `RequestRecord`, `request()` client, `keys.requests.mine()`.
- Produces: `RequestType` includes `'overtime'`; `OvertimeRequestDetail = { date: string; minutes: number }`; `RequestDetail` union includes it; `api.overtime.submitRequest(input: OvertimeRequestInput): Promise<RequestRecord>`; `useSubmitOvertimeRequest()` mutation invalidating `keys.requests.mine()`.

- [ ] **Step 1: Write the failing hook test.** Mirror `useSubmitLeaveRequest.test.tsx` (open it and copy its harness exactly — the `QueryClient` wrapper, the `api` mock, the invalidation assertion). Assert `api.overtime.submitRequest` is called and `keys.requests.mine()` is invalidated on success.

- [ ] **Step 2: Run it, verify it fails.** Run: `cd frontend/web && npm test -- useSubmitOvertimeRequest`. Expected: FAIL — hook/api do not exist.

- [ ] **Step 3: Widen the wire types.** In `api.ts`:

```ts
export type RequestType = 'attendance_adjustment' | 'leave' | 'overtime'
```

Add after `LeaveRequestDetail`:

```ts
export type OvertimeRequestDetail = {
  date: string // YYYY-MM-DD
  minutes: number
}
```

Widen the union:

```ts
export type RequestDetail = AttendanceAdjustmentDetail | LeaveRequestDetail | OvertimeRequestDetail | null
```

Add the input type after `LeaveRequestInput`:

```ts
export type OvertimeRequestInput = {
  date: string // YYYY-MM-DD
  hours: number
  note: string
}
```

Add `unpaid_overtime_minutes` to `DailySummary` after `undertime_minutes`:

```ts
  undertime_minutes: number
  unpaid_overtime_minutes: number
```

- [ ] **Step 4: Add the api method.** Overtime submit is JSON (no attachment), so unlike leave it sets `Content-Type` and sends a JSON body. Add a new `overtime` group in the `api` object (place after the `leave` group):

```ts
  overtime: {
    submitRequest: (input: OvertimeRequestInput) =>
      request<RequestRecord>('/overtime/requests', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      }),
  },
```

- [ ] **Step 5: Write the hook.** Mirror `useSubmitLeaveRequest` but invalidate only `keys.requests.mine()` (no balance to refresh):

```ts
'use client'

/**
 * Submits an overtime pre-authorization request (`POST /overtime/requests`). On success it
 * invalidates `keys.requests.mine()` so the new request shows up in "my requests". Unlike
 * leave there is no balance to refresh — overtime authorization writes no ledger.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'

import type { OvertimeRequestInput, RequestRecord } from '@/lib/api'
import { api } from '@/lib/api'
import { keys } from '@/lib/keys'

export function useSubmitOvertimeRequest() {
  const queryClient = useQueryClient()

  return useMutation<RequestRecord, unknown, OvertimeRequestInput>({
    mutationFn: (input) => api.overtime.submitRequest(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.requests.mine() })
    },
  })
}
```

- [ ] **Step 6: Run the tests + typecheck, verify green.** Run: `cd frontend/web && npm test -- useSubmitOvertimeRequest && npm run typecheck`. Expected: PASS + no type errors.

- [ ] **Step 7: Commit.**

```bash
git add frontend/web/src/lib/api.ts frontend/web/src/hooks/useSubmitOvertimeRequest.ts frontend/web/src/hooks/useSubmitOvertimeRequest.test.tsx
git commit -m "M6c: frontend data layer for overtime requests + unpaid_overtime_minutes"
```

---

### Task 8: Frontend UI — file-overtime form, card summary, day-detail excess

**Files:**
- Create: `frontend/web/src/components/domain/OvertimeRequestForm.tsx`
- Create: `frontend/web/src/components/domain/OvertimeRequestForm.test.tsx`
- Modify: `frontend/web/src/components/domain/RequestCard.tsx` (add `summarizeOvertime`)
- Modify: `frontend/web/src/components/domain/RequestCard.test.tsx`
- Modify: `frontend/web/src/components/domain/DaySummaryDetail.tsx` (surface `unpaid_overtime_minutes`)
- Modify: `frontend/web/src/components/domain/DaySummaryDetail.test.tsx`
- Modify: wherever `/me/attendance` mounts request entry (mirror where `LeaveRequestForm` is mounted — find with `grep -rl "LeaveRequestForm" frontend/web/src/app`)

**Interfaces:**
- Consumes: `useSubmitOvertimeRequest`, `OvertimeRequestInput`, `RequestRecord`, `DailySummary.unpaid_overtime_minutes`.
- Produces: `OvertimeRequestForm` (date + hours → submit); `RequestCard` renders an overtime summary; `DaySummaryDetail` shows unpaid excess.

- [ ] **Step 1: Write the failing form test.** Mirror `LeaveRequestForm.test.tsx`: render `OvertimeRequestForm`, fill date + hours, submit, assert `api.overtime.submitRequest` (mocked) called with `{ date, hours, note }`. Include a test that the minutes-to-be-requested preview renders (e.g. entering `2` hours shows "120 min" or "2h").

- [ ] **Step 2: Run it, verify it fails.** Run: `cd frontend/web && npm test -- OvertimeRequestForm`. Expected: FAIL.

- [ ] **Step 3: Write `OvertimeRequestForm`.** Mirror `LeaveRequestForm.tsx`'s structure (Carbon primitives, the same `useSubmit*` + `InlineNotification` error handling). Fields: a `date` (calendar date string) input, an `hours` numeric input (quarter-hour step), a required `note`. Show the derived minutes (`hours * 60`) as a read-only hint. On submit call `useSubmitOvertimeRequest().mutate({ date, hours, note })`. Use only `var(--*)` tokens from `carbon.css` — no raw hex/px. Read `LeaveRequestForm.tsx` first and match its prop shape (e.g. an `onSuccess`/`onClose` callback) and validation-disabled-button pattern.

- [ ] **Step 4: Add `summarizeOvertime` to `RequestCard`.** Open `RequestCard.tsx`; find `summarizeLeave` and add a sibling for the overtime branch. An overtime request's `detail` is `OvertimeRequestDetail` — narrow on `request.type === 'overtime'` and render its `date` + a duration for `minutes` (e.g. "2h overtime · Jul 15"). Overtime is single-hop, so `manager_approved` never appears for it — the existing state Tag logic already covers `pending`/`approved`/`rejected`/`cancelled`; do not add a `manager_approved` case for overtime. Add a test case in `RequestCard.test.tsx` rendering an overtime `RequestRecord`.

- [ ] **Step 5: Surface unpaid excess in `DaySummaryDetail`.** In `DaySummaryDetail.tsx`, destructure `unpaid_overtime_minutes` and, when `> 0`, render a line/tag like the incomplete/OT badges — e.g. a muted row "Unpaid excess · <Duration minutes={unpaid_overtime_minutes} />" and/or a `<Tag kind="warning">unpaid OT</Tag>`. Keep it visually consistent with the existing line-item rows (same `var(--t-caption)` styling). Add a `DaySummaryDetail.test.tsx` case: a summary with `unpaid_overtime_minutes: 60` renders the excess; one with `0` does not.

- [ ] **Step 6: Mount the form.** Find where `LeaveRequestForm` is surfaced off `/me` (run `grep -rl "LeaveRequestForm" frontend/web/src/app frontend/web/src/components`). Add an equivalent entry point for `OvertimeRequestForm` off `/me/attendance` (a "File overtime" action/section mirroring the leave one). If the attendance page has no such affordance yet, add the form behind a button consistent with how leave is surfaced. Keep scope tight — one entry point, matching the existing pattern.

- [ ] **Step 7: Run tests + typecheck + build, verify green.** Run: `cd frontend/web && npm test && npm run typecheck && npm run build`. Expected: PASS.

- [ ] **Step 8: Commit.**

```bash
git add frontend/web/src/components/domain/OvertimeRequestForm.tsx frontend/web/src/components/domain/OvertimeRequestForm.test.tsx frontend/web/src/components/domain/RequestCard.tsx frontend/web/src/components/domain/RequestCard.test.tsx frontend/web/src/components/domain/DaySummaryDetail.tsx frontend/web/src/components/domain/DaySummaryDetail.test.tsx frontend/web/src/app
git commit -m "M6c: overtime request form, card summary, and unpaid-excess day detail"
```

---

### Task 9: End-to-end script + docs

**Files:**
- Create: `scripts/e2e-leave-and-ot.sh`
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`

**Interfaces:**
- Consumes: the whole M6c surface end-to-end.

- [ ] **Step 1: Write `scripts/e2e-leave-and-ot.sh`.** Model it on `scripts/e2e-leave.sh` (read it first for the auth/login helper, the `jq` envelope parsing, the base URL, and the exit-on-mismatch style). The script must prove, live, against the running dev stack:
  1. **OT path:** an employee with a schedule works a long day (seed/adjust punches so worked > scheduled — reuse the seeder or the attendance-adjustment endpoint). File `POST /overtime/requests` for that date (e.g. 1h) → the request appears in the manager's `/team/approvals` (and HR's `/office/approvals`) → approve it → re-fetch `/me/attendance/summary` for the month → assert the day's overtime line minutes == `min(actualOvertime, 60)` and `unpaid_overtime_minutes` == `actualOvertime - 60`.
  2. **Unauthorized long day:** a second long day with **no** overtime request → assert its overtime line minutes == 0 and `unpaid_overtime_minutes` == its full overtime.
  3. **Leave still works:** run the existing leave chain (grant → file → manager → HR → balance −N → `leave_with_pay`) — either by sourcing/duplicating `e2e-leave.sh`'s core or invoking it — proving the two paths coexist.
  Print a clear PASS/FAIL per assertion and `exit 1` on any mismatch (match `e2e-leave.sh`'s convention exactly).

- [ ] **Step 2: Run it live.** Ensure the dev stack is up (`make dev`), then run: `bash scripts/e2e-leave-and-ot.sh`. Expected: every assertion PASS, exit 0. Fix any real defect it surfaces (a failing e2e is a real finding, not a script bug to paper over) before proceeding.

- [ ] **Step 3: Update the docs.**
  - `docs/02-data-model.md`: add `overtime_details` (PK = request_id, `date`, `minutes > 0`) and the `daily_attendance_summaries.unpaid_overtime_minutes` column, in the same style as the `leave_details` / summary sections.
  - `docs/03-api.md`: document `POST /overtime/requests` (body `{ date, hours, note }`, `201` `RequestResource` with `detail: { date, minutes }`); note that `overtime` requests flow through the same `/requests/*` decide/list/queue endpoints; add `unpaid_overtime_minutes` to the daily-summary response shape.
  - `docs/05-rbac.md`: note that filing overtime is un-gated (any employee, own request) and deciding reuses the existing request-decide authority + the two queues; no new permission.
  - `docs/06-roadmap.md`: mark M6c complete and **M6 complete**; move overtime out of "next"; update the status line/counts if the file carries them.
  - `docs/features.md`: add what a user can now do — file overtime pre-auth, have it approved, and see paid-vs-unpaid-excess on the day.

- [ ] **Step 4: Commit.**

```bash
git add scripts/e2e-leave-and-ot.sh docs
git commit -m "M6c: e2e-leave-and-ot.sh + docs; M6c complete, M6 complete"
```

---

## Self-Review (controller — before dispatch)

**Spec coverage:** Task 1 = type + single-hop + CHECK widen (spec §"The type"). Task 2 = `overtime_details` + resource branch (§"Data model", "the type and its detail"). Task 3 = submit action/route (§"Filing"). Task 4 = `OvertimeEffect` + trigger (§"The effect"). Task 5 = lookup (§"Compute reads the approval"). Task 6 = the cap + excess column + wiring + existing-test update (§"Compute reads the approval", decision 1 & 4, art82 short-circuit decision 5). Task 7–8 = frontend (§"Frontend"). Task 9 = e2e + docs (§"Testing", "Done when"). Every spec section maps to a task.

**Placeholder scan:** No TBD/TODO. Each code step carries real code or a concrete "read sibling X and mirror" instruction with the exact sibling named (no "add appropriate handling"). The few "verify against the sibling test" notes are for harness details (envelope status code, `Bus::fake` timing, user/employee factory helper) that genuinely differ across the codebase and must be read, not guessed — each names the exact file to read.

**Type consistency:** `approvedOvertimeMinutes` (input, int) and `unpaidOvertimeMinutes` (ComputedDay, int) and `unpaid_overtime_minutes` (column/wire, int) are used consistently across Tasks 6–8. `OvertimeRequestDetail = { date, minutes }` matches `RequestResource`'s Task 2 arm (`{ date, minutes }`). `RequestType::Overtime->requiresHrStep() === false` (Task 1) is what makes `ApproveRequest`'s existing `$isFinalHop = !$twoHop || ...` fire the effect on the single approval — no ApproveRequest change, consistent with the spec's "no state-machine changes."
