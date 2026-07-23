# M3 — Timekeeping Ingestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest punches into an append-only `attendance_logs` ledger — idempotent, verification-flagged, single-writer — and read them back raw, grouped by office-local date. Nothing downstream (no pairing, no business-day attribution, no pay).

**Architecture:** One action, `RecordPunch`, is the sole writer of `attendance_logs` (arch-guarded, like M2's cache writer). Three write paths (self-service, manual HR, a deferred device path) funnel through it. Idempotency middleware ported from POS opens the transaction the action nests inside. Verification flags but never rejects. Columns are `text` + `CHECK`; the app casts to PHP backed enums.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · Sanctum · Pest 4

## Global Constraints

- **PHP 8.5**, **Laravel 13**, **PostgreSQL 18**. Pinned.
- **`declare(strict_types=1);` at the top of every PHP file** in `app/`, `database/`, `tests/`. CI greps all three.
- **All PKs uuid with `default(DB::raw('uuidv7()'))`; all FKs uuid.** Never `bigIncrements`. Every uuid model overrides `newUniqueId()` → `Str::uuid7()`.
- **Never call `env()` outside `config/`.** Nothing in `app/Domain/` calls `config()` or a facade (still enforced) — **except** a Domain *query* service may touch Eloquent (the `EmployeeScope` carve-out precedent); a pure enum touches nothing.
- **String columns + PHP backed enums + `CHECK` constraints.** No Postgres native enum types. The `CHECK` value list and the enum cases must match; a test asserts it.
- **`attendance_logs` is append-only.** No `UPDATE`, no `DELETE`, no route that mutates a row. A correction is a new row.
- **`RecordPunch` is the only writer of `attendance_logs`.** An arch guard enforces it (the M2 cache-writer grep pattern).
- **Verification flags, never rejects.** An off-allowlist punch lands with `verification: flagged`, never a 4xx.
- **Self-service `punched_at` = server time. Manual entry accepts a supplied time.** A web employee cannot backdate their own punch.
- **`office_id` is snapshotted** from the employee's `current_office_id` at ingestion, never joined live.
- **Refusals: 404 for out-of-scope subjects (via `EmployeeScope`), 403 for unauthorized actors.** M2's rule, reused.
- **All timestamps `timestamptz`, stored UTC; office-local conversion uses the snapshot office's timezone.**
- **Tests run against real PostgreSQL, never SQLite.** Two real connections for the idempotency concurrency case.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL.

## File structure

```
backend/app/Domain/Attendance/
  PunchDirection.php  PunchSource.php  PunchVerification.php   the three backed enums
  PunchVerifier.php                                            IP/geofence → Verified|Flagged
  VerificationResult.php                                       {status, reason}
backend/app/Models/AttendanceLog.php                          casts to the enums
backend/app/Models/IdempotencyKey.php                         ported from POS
backend/app/Http/Middleware/EnsureIdempotency.php             ported, user-scoped hash
backend/app/Exceptions/Domain/IdempotencyKeyReused.php        409
backend/app/Actions/Attendance/
  RecordPunch.php  RecordPunchInput.php                       the one writer
backend/app/Http/Controllers/Attendance/
  PunchController.php                                          self-service
  ListMyAttendanceController.php  ListEmployeeAttendanceController.php
backend/app/Http/Controllers/Admin/Attendance/
  ManualPunchController.php                                   HR manual entry
backend/app/Http/Requests/
  PunchRequest.php  ManualPunchRequest.php
backend/app/Http/Resources/AttendanceLogResource.php
backend/database/migrations/
  ..._create_idempotency_keys_table.php
  ..._create_attendance_logs_table.php
backend/database/factories/AttendanceLogFactory.php
```

---

### Task 1: Enums, `attendance_logs` schema, model, factory

The append-only ledger and its typed enums. Schema only — no writer yet.

**Files:**
- Create: `backend/app/Domain/Attendance/PunchDirection.php`, `PunchSource.php`, `PunchVerification.php`
- Create: `backend/database/migrations/2026_07_25_000001_create_attendance_logs_table.php`
- Create: `backend/app/Models/AttendanceLog.php`
- Create: `backend/database/factories/AttendanceLogFactory.php`
- Test: `backend/tests/Feature/Schema/AttendanceLogSchemaTest.php`

**Interfaces:**
- Consumes: `Employee`, `Office`, `User` (M2).
- Produces:
  - `App\Domain\Attendance\PunchDirection` — backed string enum: `In = 'in'`, `Out = 'out'`.
  - `App\Domain\Attendance\PunchSource` — `Web = 'web'`, `Manual = 'manual'`, `Device = 'device'`.
  - `App\Domain\Attendance\PunchVerification` — `Verified = 'verified'`, `Flagged = 'flagged'`.
  - `App\Models\AttendanceLog` — `HasUuids`, columns per the schema below, casts `punched_at` → datetime, `direction`/`source`/`verification` → the enums, `ip_address` → string. Relations `employee()`, `office()`, `recordedBy()`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Schema/AttendanceLogSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores a punch with typed enums and a snapshot office', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create();

    $log = AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'direction' => PunchDirection::In,
        'source' => PunchSource::Web,
        'verification' => PunchVerification::Verified,
    ]);

    $fresh = $log->fresh();
    expect($fresh->direction)->toBe(PunchDirection::In)          // cast back to the enum
        ->and($fresh->source)->toBe(PunchSource::Web)
        ->and($fresh->verification)->toBe(PunchVerification::Verified)
        ->and($fresh->office_id)->toBe($office->id)
        ->and($fresh->employee->is($employee))->toBeTrue();
});

it('rejects a direction outside the CHECK constraint', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();

    // A raw insert bypassing the enum cast must still be rejected by the DB CHECK.
    expect(fn () => DB::table('attendance_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'punched_at' => now(),
        'direction' => 'sideways',           // not in ('in','out')
        'source' => 'web',
        'verification' => 'verified',
        'created_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps the CHECK value lists in sync with the enum cases', function (): void {
    // If someone adds an enum case without widening the CHECK (or vice versa), this fails.
    expect(array_map(fn ($c) => $c->value, PunchDirection::cases()))->toBe(['in', 'out'])
        ->and(array_map(fn ($c) => $c->value, PunchSource::cases()))->toBe(['web', 'manual', 'device'])
        ->and(array_map(fn ($c) => $c->value, PunchVerification::cases()))->toBe(['verified', 'flagged']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/AttendanceLogSchemaTest.php`
Expected: FAIL — `Class "App\Domain\Attendance\PunchDirection" not found`.

- [ ] **Step 3: Write the three enums**

Create `backend/app/Domain/Attendance/PunchDirection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/** Explicit on every punch — the client records what the person meant (see the spec). */
enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';
}
```

Create `backend/app/Domain/Attendance/PunchSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/** Where the punch came from. `device` exists in the contract; its auth path is deferred. */
enum PunchSource: string
{
    case Web = 'web';
    case Manual = 'manual';
    case Device = 'device';
}
```

Create `backend/app/Domain/Attendance/PunchVerification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

/**
 * Metadata on the row, never a gate. A flagged punch still lands — the Labor Code cares
 * that time was worked, not which network recorded it. See the spec.
 */
enum PunchVerification: string
{
    case Verified = 'verified';
    case Flagged = 'flagged';
}
```

- [ ] **Step 4: Write the migration**

Create `backend/database/migrations/2026_07_25_000001_create_attendance_logs_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only attendance ledger — the raw record shown a DOLE inspector. Nothing ever
 * updates or deletes a row; a correction is a new (manual) row. Enum-valued columns are
 * text + CHECK, cast to PHP backed enums in the model — never a Postgres native enum type,
 * which is a migration dance to alter. See docs/02-data-model.md and the M3 spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained();
            // Snapshot: the office the punch belonged to at the instant it happened.
            $table->foreignUuid('office_id')->constrained('offices');
            $table->timestampTz('punched_at');

            $table->text('direction');
            $table->text('source');
            $table->text('verification');
            $table->text('flag_reason')->nullable();

            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->text('ip_address')->nullable();          // inet stored as text; cast in the model
            $table->text('device_id')->nullable();
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // The query M5 and the read API run: an employee's punches within a time range.
            $table->index(['employee_id', 'punched_at']);
        });

        // The enum cases live in app/Domain/Attendance/*; these CHECK lists must match them
        // (AttendanceLogSchemaTest pins both). text + CHECK, never a Postgres native enum.
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_direction_check CHECK (direction IN ('in','out'))");
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_source_check CHECK (source IN ('web','manual','device'))");
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_verification_check CHECK (verification IN ('verified','flagged'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
```

- [ ] **Step 5: Write the model**

Create `backend/app/Models/AttendanceLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AttendanceLog extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceLogFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    public $timestamps = false;   // created_at only, set by the DB default / the action

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'created_at' => 'datetime',
            'direction' => PunchDirection::class,
            'source' => PunchSource::class,
            'verification' => PunchVerification::class,
            'geo_lat' => 'string',
            'geo_lng' => 'string',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
```

- [ ] **Step 6: Write the factory**

Create `backend/database/factories/AttendanceLogFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceLog> */
final class AttendanceLogFactory extends Factory
{
    protected $model = AttendanceLog::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'office_id' => Office::factory(),
            'punched_at' => now(),
            'direction' => PunchDirection::In,
            'source' => PunchSource::Web,
            'verification' => PunchVerification::Verified,
            'flag_reason' => null,
            'created_at' => now(),
        ];
    }
}
```

- [ ] **Step 7: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/AttendanceLogSchemaTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Attendance backend/database/migrations backend/app/Models/AttendanceLog.php backend/database/factories/AttendanceLogFactory.php backend/tests/Feature/Schema/AttendanceLogSchemaTest.php
git commit -m "Attendance: the append-only attendance_logs ledger

Enum-valued columns are text + CHECK, cast to PHP backed enums; office_id
is a snapshot. A test pins the CHECK lists to the enum cases so they can't
drift."
```

---

### Task 2: Idempotency infrastructure

Ported from POS, with the hash scoped to the acting **user** (POS scoped it to a register, which HRIS does not have).

**Files:**
- Create: `backend/database/migrations/2026_07_25_000002_create_idempotency_keys_table.php`
- Create: `backend/app/Models/IdempotencyKey.php`
- Create: `backend/app/Exceptions/Domain/IdempotencyKeyReused.php`
- Create: `backend/app/Http/Middleware/EnsureIdempotency.php`
- Modify: `backend/bootstrap/app.php` (register the `idempotent` alias)
- Test: `backend/tests/Feature/Attendance/IdempotencyTest.php`

**Interfaces:**
- Consumes: `User` (M2).
- Produces:
  - `App\Models\IdempotencyKey` — `key` (string PK), `request_hash`, `response_code` (int), `response_body` (array), `created_at`.
  - `App\Http\Middleware\EnsureIdempotency` — aliased `idempotent`. On a keyed request it opens a `DB::transaction`, stores the response for a first-seen key, replays it for a repeat, and throws `IdempotencyKeyReused` (409) on a same-key/different-body collision.
  - `App\Exceptions\Domain\IdempotencyKeyReused` — code `idempotency_key_reused`, 409.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/IdempotencyTest.php`. Because the middleware is generic, test it through a temporary route that increments a counter — this isolates idempotency from punch semantics:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A tiny keyed endpoint that records how many times the body actually executed.
    Route::post('/api/v1/_test/increment', function (): array {
        $count = cache()->increment('idem_test_calls');

        return ['data' => ['calls' => $count]];
    })->middleware(['auth:sanctum', 'idempotent']);

    cache()->forget('idem_test_calls');
});

it('runs the body once and replays the stored response on a retry with the same key', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'key-abc'];

    $first = $this->postJson('/api/v1/_test/increment', [], $headers)->assertOk();
    $second = $this->postJson('/api/v1/_test/increment', [], $headers)->assertOk();

    // The body executed exactly once; the second call replayed the first response.
    expect($first->json('data.calls'))->toBe(1)
        ->and($second->json('data.calls'))->toBe(1)
        ->and(App\Models\IdempotencyKey::count())->toBe(1);
});

it('409s when the same key is reused with a different body', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/increment', ['a' => 1], ['Idempotency-Key' => 'key-xyz'])->assertOk();

    $this->postJson('/api/v1/_test/increment', ['a' => 2], ['Idempotency-Key' => 'key-xyz'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('confines a key to the user who minted it', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Sanctum::actingAs($alice);
    $this->postJson('/api/v1/_test/increment', [], ['Idempotency-Key' => 'shared'])->assertOk();

    // Bob replaying Alice's key + identical body is a different actor: a 409, not a
    // cached response from Alice's request.
    Sanctum::actingAs($bob);
    $this->postJson('/api/v1/_test/increment', [], ['Idempotency-Key' => 'shared'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('passes through unkeyed requests without storing anything', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/_test/increment', [])->assertOk();

    expect(App\Models\IdempotencyKey::count())->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/IdempotencyTest.php`
Expected: FAIL — the `idempotent` alias / middleware does not exist.

- [ ] **Step 3: Write the migration**

Create `backend/database/migrations/2026_07_25_000002_create_idempotency_keys_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for mutations — a client-generated key stores the original outcome so
 * a retried punch returns it instead of writing a second row. The key and the work it
 * guards commit in ONE transaction, which EnsureIdempotency opens. Ported from POS.
 * See docs/01-architecture.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->text('request_hash');            // sha256(user + method + path + body)
            $table->integer('response_code');
            $table->jsonb('response_body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');             // pruning window
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
```

- [ ] **Step 4: Write the model**

Create `backend/app/Models/IdempotencyKey.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored response for a client-generated key. The key and the work it guards commit in
 * ONE transaction — EnsureIdempotency opens it. Ported from POS. See docs/01-architecture.md.
 */
final class IdempotencyKey extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const ?string UPDATED_AT = null;

    protected $fillable = ['key', 'request_hash', 'response_code', 'response_body', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Write the exception**

Create `backend/app/Exceptions/Domain/IdempotencyKeyReused.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * A key was reused for a genuinely different request (different actor or body). Replaying
 * the original outcome would be wrong, so this is a hard conflict. See docs/03-api.md.
 */
final class IdempotencyKeyReused extends DomainException
{
    public function __construct(private readonly string $key)
    {
        parent::__construct('This idempotency key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['key' => $this->key];
    }
}
```

- [ ] **Step 6: Write the middleware**

Create `backend/app/Http/Middleware/EnsureIdempotency.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\IdempotencyKeyReused;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for mutations. The subtlety: the key and the work it guards must commit
 * together or not at all, so THIS middleware opens the transaction and the action's own
 * DB::transaction() nests inside it as a savepoint. Ported from POS; the hash is scoped to
 * the acting user (POS scoped to a register, which HRIS has no equivalent of).
 * See docs/04-backend-conventions.md ("Two subtleties").
 */
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        // Fold the actor into the hash so a key is confined to whoever minted it: anyone
        // else replaying the same key + body gets a 409, never a cached response built for
        // a different person.
        $hash = hash('sha256', implode('|', [
            $request->user()?->getAuthIdentifier() ?? '',
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));

        return DB::transaction(function () use ($key, $hash, $request, $next): Response {
            $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

            if ($seen !== null) {
                if (! hash_equals($seen->request_hash, $hash)) {
                    throw new IdempotencyKeyReused($key);   // 409
                }

                return response()->json($seen->response_body, $seen->response_code);
            }

            $response = $next($request);   // the action's DB::transaction() nests here

            // Only success earns a key, so a flagged-but-stored punch (a 2xx) is recorded,
            // while a genuine failure rolls back to the savepoint and stays retryable.
            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'created_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
```

- [ ] **Step 7: Register the alias**

In `backend/bootstrap/app.php`'s `withMiddleware` closure, add the alias (create the closure body if it's currently empty):

```php
use App\Http\Middleware\EnsureIdempotency;
```

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
```

- [ ] **Step 8: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/IdempotencyTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 9: Commit**

```bash
cd /home/haru/projects/hris
git add backend/database/migrations backend/app/Models/IdempotencyKey.php backend/app/Exceptions/Domain/IdempotencyKeyReused.php backend/app/Http/Middleware/EnsureIdempotency.php backend/bootstrap/app.php backend/tests/Feature/Attendance/IdempotencyTest.php
git commit -m "Attendance: idempotency middleware, ported from POS

The key and the work it guards commit in one transaction the middleware
opens. The hash folds in the acting user, so a key is confined to whoever
minted it. Only a 2xx stores a key — a failure stays retryable."
```

---

### Task 3: `PunchVerifier`

A stateless domain service: given an office, an IP, and optional coordinates, decide `verified` or `flagged`.

**Files:**
- Create: `backend/app/Domain/Attendance/VerificationResult.php`
- Create: `backend/app/Domain/Attendance/PunchVerifier.php`
- Test: `backend/tests/Feature/Attendance/PunchVerifierTest.php`

**Interfaces:**
- Consumes: `Office` (M2), `PunchVerification` (Task 1).
- Produces:
  - `App\Domain\Attendance\VerificationResult` — `final readonly`, public `PunchVerification $status`, public `?string $reason`. Statics `verified(): self`, `flagged(string $reason): self`.
  - `App\Domain\Attendance\PunchVerifier` — `final`. Method `verify(Office $office, ?string $ipAddress, ?string $geoLat, ?string $geoLng): VerificationResult`. IP is checked against `office.ip_allowlist`; if coordinates are present and the office has a geofence configured, distance is checked too. First failing check wins the flag reason.

`PunchVerifier` reads `Office` model attributes (no query, no `config()`), which is allowed in Domain. It is deterministic and unit-testable with plain model instances.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/PunchVerifierTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchVerification;
use App\Domain\Attendance\PunchVerifier;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('verifies a punch from an allowlisted IP', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);

    $result = (new PunchVerifier())->verify($office, '203.0.113.7', null, null);

    expect($result->status)->toBe(PunchVerification::Verified)
        ->and($result->reason)->toBeNull();
});

it('flags a punch from an IP outside the allowlist', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);

    $result = (new PunchVerifier())->verify($office, '198.51.100.9', null, null);

    expect($result->status)->toBe(PunchVerification::Flagged)
        ->and($result->reason)->toBe('ip_not_allowlisted');
});

it('verifies when the office has no allowlist configured', function (): void {
    // No allowlist means no IP restriction — every IP passes that check.
    $office = Office::factory()->create(['ip_allowlist' => null]);

    expect((new PunchVerifier())->verify($office, '198.51.100.9', null, null)->status)
        ->toBe(PunchVerification::Verified);
});

it('flags a punch outside the office geofence when coordinates are supplied', function (): void {
    // Office at (14.5995, 120.9842) with a 100m radius; the punch is ~2km away.
    $office = Office::factory()->create([
        'ip_allowlist' => null,
        'geofence_lat' => '14.5995000',
        'geofence_lng' => '120.9842000',
        'geofence_radius_m' => 100,
    ]);

    $result = (new PunchVerifier())->verify($office, null, '14.6180000', '120.9842000');

    expect($result->status)->toBe(PunchVerification::Flagged)
        ->and($result->reason)->toBe('outside_geofence');
});

it('verifies a punch inside the office geofence', function (): void {
    $office = Office::factory()->create([
        'ip_allowlist' => null,
        'geofence_lat' => '14.5995000',
        'geofence_lng' => '120.9842000',
        'geofence_radius_m' => 100,
    ]);

    // ~10m away, well inside 100m.
    expect((new PunchVerifier())->verify($office, null, '14.5995500', '120.9842000')->status)
        ->toBe(PunchVerification::Verified);
});

it('ignores the geofence when the office has none configured', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null, 'geofence_lat' => null]);

    expect((new PunchVerifier())->verify($office, null, '14.6180000', '120.9842000')->status)
        ->toBe(PunchVerification::Verified);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/PunchVerifierTest.php`
Expected: FAIL — `PunchVerifier` not found.

- [ ] **Step 3: Write `VerificationResult`**

Create `backend/app/Domain/Attendance/VerificationResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

final readonly class VerificationResult
{
    private function __construct(
        public PunchVerification $status,
        public ?string $reason,
    ) {}

    public static function verified(): self
    {
        return new self(PunchVerification::Verified, null);
    }

    public static function flagged(string $reason): self
    {
        return new self(PunchVerification::Flagged, $reason);
    }
}
```

- [ ] **Step 4: Write `PunchVerifier`**

Create `backend/app/Domain/Attendance/PunchVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Models\Office;

/**
 * Decides whether a punch is verified or flagged. Never rejects — a flag is metadata on
 * the row for HR to review, because the Labor Code cares that time was worked, not which
 * network recorded it. See the M3 spec.
 *
 * A Domain service that reads Office attributes (no query, no config) and is otherwise
 * pure. The first failing check wins the reason.
 */
final class PunchVerifier
{
    private const int EARTH_RADIUS_M = 6_371_000;

    public function verify(Office $office, ?string $ipAddress, ?string $geoLat, ?string $geoLng): VerificationResult
    {
        if (! $this->ipAllowed($office, $ipAddress)) {
            return VerificationResult::flagged('ip_not_allowlisted');
        }

        if (! $this->withinGeofence($office, $geoLat, $geoLng)) {
            return VerificationResult::flagged('outside_geofence');
        }

        return VerificationResult::verified();
    }

    /** @param  Office  $office */
    private function ipAllowed(Office $office, ?string $ipAddress): bool
    {
        $allowlist = $office->ip_allowlist;

        // No allowlist configured, or no IP to check: nothing to fail.
        if ($allowlist === null || $allowlist === [] || $ipAddress === null) {
            return true;
        }

        foreach ($allowlist as $cidr) {
            if ($this->ipInCidr($ipAddress, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function withinGeofence(Office $office, ?string $geoLat, ?string $geoLng): bool
    {
        // Only checked when the punch carries coordinates AND the office defines a fence.
        if ($geoLat === null || $geoLng === null
            || $office->geofence_lat === null || $office->geofence_lng === null
            || $office->geofence_radius_m === null) {
            return true;
        }

        $distance = $this->haversineMeters(
            (float) $office->geofence_lat, (float) $office->geofence_lng,
            (float) $geoLat, (float) $geoLng,
        );

        return $distance <= (float) $office->geofence_radius_m;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;   // IPv6 or malformed — out of scope for M3's IPv4 allowlists
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/PunchVerifierTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Attendance/VerificationResult.php backend/app/Domain/Attendance/PunchVerifier.php backend/tests/Feature/Attendance/PunchVerifierTest.php
git commit -m "Attendance: PunchVerifier — flag, never reject

IP against the office allowlist, plus a geofence check that only fires
when a punch carries coordinates and the office defines a fence. The first
failing check wins the flag reason. A flag is metadata, not a gate."
```

---

### Task 4: `RecordPunch` — the one writer

The sole writer of `attendance_logs`, arch-guarded.

**Files:**
- Create: `backend/app/Actions/Attendance/RecordPunchInput.php`
- Create: `backend/app/Actions/Attendance/RecordPunch.php`
- Modify: `backend/tests/Arch/ConventionsTest.php`
- Test: `backend/tests/Feature/Attendance/RecordPunchTest.php`

**Interfaces:**
- Consumes: `Employee`, `PunchVerifier`, `PunchDirection`, `PunchSource` (Tasks 1, 3).
- Produces:
  - `App\Actions\Attendance\RecordPunchInput` — readonly DTO: `employeeId (string)`, `direction (PunchDirection)`, `source (PunchSource)`, `punchedAt (?CarbonInterface — null means "server now")`, `recordedBy (?string)`, `ipAddress (?string)`, `deviceId (?string)`, `geoLat (?string)`, `geoLng (?string)`.
  - `App\Actions\Attendance\RecordPunch` — `final`, `execute(RecordPunchInput): AttendanceLog`. Snapshots the employee's `current_office_id`, resolves `punched_at` (supplied or server now), runs `PunchVerifier`, writes the row.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/RecordPunchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Attendance\PunchVerification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('snapshots the current office and stamps server time for a self-service punch', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    Carbon::setTestNow('2026-03-02 09:00:00');
    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Web,
        punchedAt: null,                // server now
        recordedBy: $employee->user_id,
        ipAddress: '198.51.100.4',
        deviceId: null, geoLat: null, geoLng: null,
    ));
    Carbon::setTestNow();

    expect($log->office_id)->toBe($office->id)                    // snapshot
        ->and($log->punched_at->toDateTimeString())->toBe('2026-03-02 09:00:00')
        ->and($log->direction)->toBe(PunchDirection::In)
        ->and($log->verification)->toBe(PunchVerification::Verified);
});

it('stores a manual punch at the supplied time', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::Out,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse('2026-03-01 17:30:00'),   // HR correcting a missed punch
        recordedBy: Employee::factory()->create()->id,
        ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
    ));

    expect($log->source)->toBe(PunchSource::Manual)
        ->and($log->punched_at->toDateTimeString())->toBe('2026-03-01 17:30:00');
});

it('flags a punch from an IP outside the office allowlist but still stores it', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $log = app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: PunchDirection::In,
        source: PunchSource::Web,
        punchedAt: null,
        recordedBy: $employee->user_id,
        ipAddress: '198.51.100.9',            // outside the /24
        deviceId: null, geoLat: null, geoLng: null,
    ));

    expect($log->verification)->toBe(PunchVerification::Flagged)
        ->and($log->flag_reason)->toBe('ip_not_allowlisted')
        ->and(AttendanceLog::count())->toBe(1);   // stored, not rejected
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/RecordPunchTest.php`
Expected: FAIL — `RecordPunch` not found.

- [ ] **Step 3: Write the input DTO**

Create `backend/app/Actions/Attendance/RecordPunchInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use Carbon\CarbonInterface;

final readonly class RecordPunchInput
{
    public function __construct(
        public string $employeeId,
        public PunchDirection $direction,
        public PunchSource $source,
        public ?CarbonInterface $punchedAt,   // null = server now (self-service)
        public ?string $recordedBy,
        public ?string $ipAddress,
        public ?string $deviceId,
        public ?string $geoLat,
        public ?string $geoLng,
    ) {}
}
```

- [ ] **Step 4: Write the action**

Create `backend/app/Actions/Attendance/RecordPunch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\PunchVerifier;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of attendance_logs (arch-guarded). Snapshots the employee's current
 * office, resolves the punch time (supplied for a manual entry, server-now for
 * self-service), verifies, and appends the row. Never updates — a correction is a new row.
 * See the M3 spec.
 */
final class RecordPunch
{
    public function __construct(private readonly PunchVerifier $verifier) {}

    public function execute(RecordPunchInput $in): AttendanceLog
    {
        return DB::transaction(function () use ($in): AttendanceLog {
            $employee = Employee::query()->findOrFail($in->employeeId);

            // Snapshot the office the punch belongs to now, so a later transfer never
            // reinterprets this punch's timezone or geofence.
            $office = $employee->currentOffice()->firstOrFail();

            $result = $this->verifier->verify($office, $in->ipAddress, $in->geoLat, $in->geoLng);

            return AttendanceLog::query()->create([
                'employee_id' => $employee->id,
                'office_id' => $office->id,
                'punched_at' => $in->punchedAt ?? now(),
                'direction' => $in->direction,
                'source' => $in->source,
                'verification' => $result->status,
                'flag_reason' => $result->reason,
                'recorded_by' => $in->recordedBy,
                'ip_address' => $in->ipAddress,
                'device_id' => $in->deviceId,
                'geo_lat' => $in->geoLat,
                'geo_lng' => $in->geoLng,
            ]);
        });
    }
}
```

- [ ] **Step 5: Add the append-only single-writer arch guard**

In `backend/tests/Arch/ConventionsTest.php`, add a grep-based guard modelled on the existing cache-writer guard (find it; it globs `app/` and asserts write forms of the three `current_*` columns appear only in `RecordEmploymentChange`). Add an analogous rule: **`AttendanceLog::query()->create(` / `AttendanceLog::create(` / `new AttendanceLog` / `->update(` / `->delete(` on an attendance log must appear only in `RecordPunch.php`** (the factory in `database/` is exempt — the guard scans `app/` only). Match the existing guard's mechanism. The guarantee: nothing in `app/` writes `attendance_logs` except `RecordPunch`. Record the exact form used.

- [ ] **Step 6: Run the action test and the arch suite**

```bash
cd backend
./vendor/bin/pest tests/Feature/Attendance/RecordPunchTest.php
./vendor/bin/pest --testsuite=Arch
```

Expected: 3 action tests PASS; arch suite PASS with the new rule.

- [ ] **Step 7: Prove the guard bites**

Add a scratch `app/Scratch/BadPunchWriter.php` that does `AttendanceLog::create([...])`, run the arch suite, confirm it FAILS on the new rule, delete it, confirm PASS. Record the output.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Attendance backend/tests/Arch/ConventionsTest.php backend/tests/Feature/Attendance/RecordPunchTest.php
git commit -m "Attendance: RecordPunch, the one writer of the ledger

Snapshots the current office, stamps server time for self-service and the
supplied time for manual entry, verifies, appends. An arch guard proves
nothing else in app/ writes attendance_logs."
```

---

### Task 5: Self-service punch endpoint

`POST /attendance/punch` — Sanctum-authed, idempotent, server-timed.

**Files:**
- Create: `backend/app/Http/Requests/PunchRequest.php`
- Create: `backend/app/Http/Controllers/Attendance/PunchController.php`
- Create: `backend/app/Http/Resources/AttendanceLogResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Attendance/PunchEndpointTest.php`

**Interfaces:**
- Consumes: `RecordPunch`, `RecordPunchInput`, `EnsureIdempotency`, `PunchDirection` (Tasks 2, 4).
- Produces: `POST /api/v1/attendance/punch` → `201` with `{"data": <attendance log>}`; requires `Idempotency-Key`; `direction` in the body (`in`/`out`).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/PunchEndpointTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actingEmployee(array $officeOverrides = []): User
{
    $office = Office::factory()->create($officeOverrides);
    $user = User::factory()->create();
    Employee::factory()->for($user)->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($user);

    return $user;
}

it('records a self-service clock-in', function (): void {
    actingEmployee(['ip_allowlist' => null]);

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k1'])
        ->assertCreated()
        ->assertJsonPath('data.direction', 'in')
        ->assertJsonPath('data.source', 'web')
        ->assertJsonPath('data.verification', 'verified');

    expect(AttendanceLog::count())->toBe(1);
});

it('is idempotent under a retried key', function (): void {
    actingEmployee(['ip_allowlist' => null]);
    $headers = ['Idempotency-Key' => 'retry-key'];

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], $headers)->assertCreated();
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], $headers)->assertCreated();

    // The retry replayed the stored response; only one row exists.
    expect(AttendanceLog::count())->toBe(1);
});

it('requires an idempotency key', function (): void {
    actingEmployee(['ip_allowlist' => null]);

    // Decide the contract: a punch without a key is refused. (If the team prefers to allow
    // keyless punches, change this assertion — but the spec makes the key required.)
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('flags an off-allowlist punch but still stores it', function (): void {
    actingEmployee(['ip_allowlist' => ['203.0.113.0/24']]);

    // The test client's IP is 127.0.0.1, outside the /24.
    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k2'])
        ->assertCreated()
        ->assertJsonPath('data.verification', 'flagged')
        ->assertJsonPath('data.flag_reason', 'ip_not_allowlisted');
});

it('rejects a punch from a user with no employee record', function (): void {
    Sanctum::actingAs(User::factory()->create());   // a bare admin account, no employee

    $this->postJson('/api/v1/attendance/punch', ['direction' => 'in'], ['Idempotency-Key' => 'k3'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'not_an_employee');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/PunchEndpointTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Write the resource**

Create `backend/app/Http/Resources/AttendanceLogResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceLog */
final class AttendanceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'office_id' => $this->office_id,
            'punched_at' => $this->punched_at?->toIso8601String(),
            'direction' => $this->direction->value,
            'source' => $this->source->value,
            'verification' => $this->verification->value,
            'flag_reason' => $this->flag_reason,
        ];
    }
}
```

- [ ] **Step 4: Write the domain exception for a non-employee punch**

Create `backend/app/Exceptions/Domain/NotAnEmployee.php` (extends `DomainException`, code `not_an_employee`, status `422`, message "Only an employee can record a punch."). A user with no `employee` record cannot punch.

- [ ] **Step 5: Write the FormRequest and controller**

Create `backend/app/Http/Requests/PunchRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated user; the "is an employee" check is in the action
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['in', 'out'])],
        ];
    }
}
```

Create `backend/app/Http/Controllers/Attendance/PunchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\PunchRequest;
use App\Http\Resources\AttendanceLogResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PunchController
{
    public function __invoke(PunchRequest $request, RecordPunch $action): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee();
        }

        $log = $action->execute(new RecordPunchInput(
            employeeId: $employee->id,
            direction: PunchDirection::from($request->string('direction')->toString()),
            source: PunchSource::Web,
            punchedAt: null,                                  // server now — no client time
            recordedBy: $request->user()->id,
            ipAddress: $request->ip(),
            deviceId: null,
            geoLat: null,
            geoLng: null,
        ));

        return AttendanceLogResource::make($log)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 6: Register the route**

In `backend/routes/api.php`, inside the `auth:sanctum` group:

```php
use App\Http\Controllers\Attendance\PunchController;
```

```php
        Route::post('/attendance/punch', PunchController::class)->middleware('idempotent');
```

- [ ] **Step 7: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/PunchEndpointTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Http/Requests/PunchRequest.php backend/app/Http/Controllers/Attendance/PunchController.php backend/app/Http/Resources/AttendanceLogResource.php backend/app/Exceptions/Domain/NotAnEmployee.php backend/routes/api.php backend/tests/Feature/Attendance/PunchEndpointTest.php
git commit -m "Attendance: the self-service punch endpoint

Sanctum-authed, idempotent, server-timed — a web employee cannot backdate
their own punch. An off-allowlist punch lands flagged, never refused. A
user with no employee record gets 422 not_an_employee."
```

---

### Task 6: Manual HR punch endpoint

`POST /admin/attendance/punch` — HR backfills a punch for an employee in their scope, at a supplied time.

**Files:**
- Create: `backend/app/Http/Requests/ManualPunchRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Attendance/ManualPunchController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/ManualPunchTest.php`

**Interfaces:**
- Consumes: `RecordPunch`, `EmployeeScope`, `PunchDirection`, `PunchSource` (Tasks 4, M2).
- Produces: `POST /api/v1/admin/attendance/punch` → `201`; body carries `employee_id`, `direction`, `punched_at`; the target employee must be in the actor's `EmployeeScope` (else 404), source is `manual`, `recorded_by` is the HR user.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/ManualPunchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets an HR admin backfill a manual punch for an employee in their office', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null]);
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);

    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'out',
        'punched_at' => '2026-03-01T17:30:00+08:00',
    ])->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.direction', 'out');

    $log = AttendanceLog::first();
    expect($log->recorded_by)->toBe($hrUser->id)
        ->and($log->employee_id)->toBe($target->id);
});

it('404s when HR backfills for an employee outside their scope', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $cebuWorker->id,
        'direction' => 'in',
        'punched_at' => '2026-03-01T08:00:00+08:00',
    ])->assertStatus(404);
});

it('requires a supplied punched_at for a manual entry', function (): void {
    $office = Office::factory()->create();
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);
    $target = Employee::factory()->create(['current_office_id' => $office->id]);
    Sanctum::actingAs($hrUser);

    $this->postJson('/api/v1/admin/attendance/punch', [
        'employee_id' => $target->id,
        'direction' => 'in',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/ManualPunchTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Write the FormRequest**

Create `backend/app/Http/Requests/ManualPunchRequest.php`. It validates `employee_id` (uuid, exists), `direction` (in `in`/`out`), `punched_at` (required, a date). `authorize()` returns `true` — the scope check (404-not-403 for an out-of-scope subject) belongs in the controller against `EmployeeScope`, exactly like `ShowEmployeeController`, not in `authorize()` (which would 403).

- [ ] **Step 4: Write the controller**

Create `backend/app/Http/Controllers/Admin/Attendance/ManualPunchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Attendance;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Scope\EmployeeScope;
use App\Http\Requests\ManualPunchRequest;
use App\Http\Resources\AttendanceLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManualPunchController
{
    public function __invoke(ManualPunchRequest $request, RecordPunch $action): JsonResponse
    {
        $employeeId = $request->string('employee_id')->toString();

        // 404, not 403: an out-of-scope subject is indistinguishable from a nonexistent
        // one — the M2 rule. HR can only backfill for an employee in their scope.
        $inScope = EmployeeScope::visibleTo($request->user())->whereKey($employeeId)->exists();
        if (! $inScope) {
            throw new NotFoundHttpException();
        }

        $log = $action->execute(new RecordPunchInput(
            employeeId: $employeeId,
            direction: PunchDirection::from($request->string('direction')->toString()),
            source: PunchSource::Manual,
            punchedAt: Carbon::parse($request->string('punched_at')->toString()),
            recordedBy: $request->user()->id,
            ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
        ));

        return AttendanceLogResource::make($log)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 5: Register the route**

In `backend/routes/api.php`, inside the existing `admin` prefix group:

```php
use App\Http\Controllers\Admin\Attendance\ManualPunchController;
```

```php
            Route::post('/attendance/punch', ManualPunchController::class);
```

Note: manual entry is deliberately **not** behind `idempotent` — HR entering a correction is a considered, one-off action, not a retryable network event. (If the team wants it idempotent too, add the middleware; the spec does not require it.)

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/ManualPunchTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Http/Requests/ManualPunchRequest.php backend/app/Http/Controllers/Admin/Attendance/ManualPunchController.php backend/routes/api.php backend/tests/Feature/Admin/ManualPunchTest.php
git commit -m "Attendance: HR manual punch entry, scoped

Backfills a punch at a supplied time (source: manual, recorded_by set).
The target must be in the HR admin's EmployeeScope — out of scope is 404,
not 403, per the M2 org-chart-leak rule."
```

---

### Task 7: Read API — raw attendance by office-local date

`GET /me/attendance` and the scoped `GET /employees/{employee}/attendance`.

**Files:**
- Create: `backend/app/Http/Controllers/Attendance/ListMyAttendanceController.php`
- Create: `backend/app/Http/Controllers/Attendance/ListEmployeeAttendanceController.php`
- Modify: `backend/routes/api.php`, `backend/tests/Arch/ConventionsTest.php`
- Test: `backend/tests/Feature/Attendance/ReadAttendanceTest.php`

**Interfaces:**
- Consumes: `AttendanceLog`, `EmployeeScope`, `Office` (timezone), `AttendanceLogResource` (Tasks 1, 5, M2).
- Produces:
  - `GET /api/v1/me/attendance?month=YYYY-MM` → `{"data": {<local-date>: [<log>, ...], ...}}`, the caller's own punches for the month, grouped by office-local calendar date.
  - `GET /api/v1/employees/{employee}/attendance?month=YYYY-MM` → same shape, scoped by `EmployeeScope` (404 out of scope).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/ReadAttendanceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchDirection;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns my punches for a month grouped by office-local date', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    // 08:00 and 17:00 Manila on 2026-03-02 — stored UTC (00:00 and 09:00).
    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::In, 'punched_at' => '2026-03-02 00:00:00']);
    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::Out, 'punched_at' => '2026-03-02 09:00:00']);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance?month=2026-03')->assertOk()->json('data');

    expect($data)->toHaveKey('2026-03-02')
        ->and($data['2026-03-02'])->toHaveCount(2);
});

it('buckets a cross-midnight punch on its own office-local date', function (): void {
    // A night-shift out-punch at 22:00 UTC on the 2nd is 06:00 Manila on the 3rd.
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $user = User::factory()->create();
    $me = Employee::factory()->for($user)->create(['current_office_id' => $office->id]);

    AttendanceLog::factory()->create(['employee_id' => $me->id, 'office_id' => $office->id, 'direction' => PunchDirection::Out, 'punched_at' => '2026-03-02 22:00:00']);
    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/me/attendance?month=2026-03')->assertOk()->json('data');

    // Raw view: no pairing, no business-day. The out-punch lands on its LOCAL date (the 3rd).
    expect($data)->toHaveKey('2026-03-03');
});

it('lets an HR admin read an in-scope employee and 404s out of scope', function (): void {
    $manila = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $cebu = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $manila->id]);
    $hrUser->hrAdminOffices()->attach($manila->id);

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);
    Sanctum::actingAs($hrUser);

    $this->getJson("/api/v1/employees/{$manilaWorker->id}/attendance?month=2026-03")->assertOk();
    $this->getJson("/api/v1/employees/{$cebuWorker->id}/attendance?month=2026-03")->assertStatus(404);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/ReadAttendanceTest.php`
Expected: FAIL — routes 404.

- [ ] **Step 3: Write a shared grouping helper**

Both controllers group the same way. Put the grouping in a small private-ish helper on each controller, or a shared `App\Domain\Attendance\AttendanceMonth` value/service that takes a `Collection<AttendanceLog>` and a resolver for each log's office timezone and returns `array<string, list<array>>` keyed by local date. Implement one shared unit so the two controllers do not duplicate the bucketing. The bucketing: for each log, convert `punched_at` to the log's `office.timezone`, format `Y-m-d`, group. Sort keys ascending, and within a date sort by `punched_at`.

Create `backend/app/Domain/Attendance/AttendanceMonth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use Illuminate\Support\Collection;

/**
 * Groups raw punches by office-local calendar date — no pairing, no business-day logic.
 * Each punch's local date is computed in ITS snapshot office's timezone, so a transfer
 * never reinterprets an old punch. A cross-midnight punch lands on its own local date;
 * interpretation is M5's job. See the M3 spec.
 */
final class AttendanceMonth
{
    /**
     * @param  Collection<int, AttendanceLog>  $logs  each with `office` loaded
     * @return array<string, list<array<string, mixed>>>
     */
    public static function group(Collection $logs): array
    {
        return $logs
            ->sortBy('punched_at')
            ->groupBy(fn (AttendanceLog $log): string => $log->punched_at
                ->setTimezone($log->office->timezone)
                ->format('Y-m-d'))
            ->map(fn (Collection $day) => $day
                ->map(fn (AttendanceLog $log) => AttendanceLogResource::make($log)->resolve())
                ->values()
                ->all())
            ->sortKeys()
            ->all();
    }
}
```

- [ ] **Step 4: Write the two controllers**

`ListMyAttendanceController` loads the caller's own `AttendanceLog` rows for the requested `month` (a `?month=YYYY-MM`, default current month) with `office` eager-loaded, and returns `['data' => AttendanceMonth::group($logs)]`. The month filter: `punched_at` between the month's start and end. (Because bucketing is by local date, include a one-day margin on each side of the month's UTC bounds so an edge-of-month local date isn't dropped — or filter after grouping. Keep it correct; a test covers the cross-midnight case.)

`ListEmployeeAttendanceController` takes `{employee}` via route-model binding, checks `EmployeeScope::visibleTo($user)->whereKey($employee->id)->exists()` and throws `NotFoundHttpException` if not (the M2 404 rule), then groups that employee's logs the same way. Both are thin.

Show the `ListMyAttendanceController` in full so the month handling is concrete:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendanceMonth;
use App\Exceptions\Domain\NotAnEmployee;
use App\Models\AttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ListMyAttendanceController
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee();
        }

        $month = Carbon::parse(($request->string('month')->toString() ?: now()->format('Y-m')).'-01');

        // A one-day margin each side so a punch whose LOCAL date falls in the month but
        // whose UTC instant sits just outside it is still included; grouping keys by local
        // date, and the response only surfaces dates the punches actually fall on.
        $logs = AttendanceLog::query()
            ->with('office')
            ->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [
                $month->copy()->startOfMonth()->subDay(),
                $month->copy()->endOfMonth()->addDay(),
            ])
            ->get();

        return response()->json(['data' => AttendanceMonth::group($logs)]);
    }
}
```

`ListEmployeeAttendanceController` mirrors it with the scope check and `$employee` from the route.

- [ ] **Step 5: Routes + arch rule**

In `backend/routes/api.php`, inside `auth:sanctum`:

```php
        Route::get('/me/attendance', ListMyAttendanceController::class);
        Route::get('/employees/{employee}/attendance', ListEmployeeAttendanceController::class);
```

Extend the M2 arch rule that every controller under `App\Http\Controllers\Employees` references an authorization boundary so it also covers `App\Http\Controllers\Attendance` — or add a sibling rule: every attendance read controller must reference `EmployeeScope` or the caller's own employee (`$request->user()->employee`). The guarantee: no attendance-read path serves data without a scope/self check. `ListMyAttendanceController` is self-only (its own `employee`); `ListEmployeeAttendanceController` uses `EmployeeScope`. Record the form used.

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/ReadAttendanceTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Http/Controllers/Attendance/ListMyAttendanceController.php backend/app/Http/Controllers/Attendance/ListEmployeeAttendanceController.php backend/app/Domain/Attendance/AttendanceMonth.php backend/routes/api.php backend/tests/Arch/ConventionsTest.php backend/tests/Feature/Attendance/ReadAttendanceTest.php
git commit -m "Attendance: read raw punches grouped by office-local date

No pairing, no business-day logic — a cross-midnight punch lands on its
own local date. The employee-scoped read reuses EmployeeScope + 404. An
arch rule keeps every attendance read behind a scope or self check."
```

---

### Task 8: Append-only guard, docs, seed, e2e, final gate

Prove the ledger is append-only, write the docs M3 owns, and green the whole stack.

**Files:**
- Modify: `backend/tests/Arch/ConventionsTest.php` (no-mutation rule) or a schema test
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/06-roadmap.md`, `docs/README.md`
- Modify: `backend/database/seeders/CompanySeeder.php` (an office with an `ip_allowlist`, for the e2e)
- Create: `scripts/e2e-timekeeping.sh`
- Test: `backend/tests/Feature/Attendance/AppendOnlyTest.php`

- [ ] **Step 1: Prove append-only**

Create `backend/tests/Feature/Attendance/AppendOnlyTest.php` asserting: no route in the app maps to an `UPDATE` or `DELETE` on `attendance_logs`. The robust form: a test that scans `routes/api.php` (and the router's route list) for any `PATCH`/`PUT`/`DELETE` route whose path contains `attendance`, and asserts there are none. Plus a code-level check (the arch guard from Task 4 already forbids `->update(`/`->delete(` on attendance logs outside `RecordPunch`, which itself only calls `create`). Assert both: no mutating attendance route, and `RecordPunch` contains no `update`/`delete`.

- [ ] **Step 2: Run it**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/AppendOnlyTest.php`
Expected: PASS.

- [ ] **Step 3: Seed an allowlisted office**

In `CompanySeeder`, give one office (say Manila) a non-null `ip_allowlist` (e.g. `['203.0.113.0/24']`) so the e2e can demonstrate both a `verified` punch (from an allowlisted IP, simulated) and a `flagged` one. Keep the seeder test green (it doesn't assert on `ip_allowlist`, but confirm).

- [ ] **Step 4: Write the e2e script**

Create `scripts/e2e-timekeeping.sh` following the shape of the repo's other e2e scripts: log in as a seeded employee, `POST /attendance/punch` with `direction: in` and an `Idempotency-Key`, retry the same key (assert one row / replayed response), `POST` a `direction: out`, then as HR `POST /admin/attendance/punch` a backdated manual punch for that employee, then `GET /me/attendance?month=` and assert the punches appear grouped by local date with the right `verification`/`source`. Make it executable.

- [ ] **Step 5: Write `docs/02-data-model.md` and `docs/03-api.md` additions**

Add `attendance_logs` and `idempotency_keys` to `docs/02-data-model.md` with the rationale: append-only, the office snapshot, string+CHECK enums, the single-writer invariant (point at the arch guard). Add the M3 endpoints and error codes (`idempotency_key_reused` 409, `not_an_employee` 422, plus the `not_found` scoped-subject and `validation_failed` cases) and the `/me/attendance` grouped-by-local-date response shape to `docs/03-api.md`.

- [ ] **Step 6: Update `docs/06-roadmap.md` and `docs/README.md`**

Add a `**Status: complete.**` block under M3 recording: the append-only ledger + single writer; string-column enums; idempotency ported (user-scoped hash); flag-not-reject; the device contract exposed but device auth deferred; raw read grouped by office-local date with no pairing; and any wall-hit notes. Confirm `docs/README.md`'s doc list is still accurate.

- [ ] **Step 7: Run everything**

```bash
cd /home/haru/projects/hris/backend && ./vendor/bin/pest
cd /home/haru/projects/hris/frontend/web && npm run lint && npm test && npm run typecheck && npm run build
cd /home/haru/projects/hris && make test
```

Expected: backend green (M0–M2's 164 + all M3), frontend unchanged (16), `make test` green in containers. Report the real backend count.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/tests docs scripts backend/database/seeders/CompanySeeder.php
git commit -m "Attendance: prove append-only, docs, e2e, M3 status

No route mutates attendance_logs and RecordPunch never updates/deletes.
02-data-model and 03-api document the ledger and endpoints; the e2e walks
punch-in, idempotent retry, manual backfill, and the grouped read."
```

---

## Done When

A seeded employee punches in and out (idempotent under retry), an off-network punch lands `flagged` rather than refused, HR backfills a missed punch as `manual` within their scope, and `GET /me/attendance?month=` returns the punches grouped by office-local date — with the raw log provably append-only (no mutating route, `RecordPunch` the sole writer) and the full suite green.
