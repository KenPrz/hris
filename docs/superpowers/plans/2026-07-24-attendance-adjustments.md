# Attendance Adjustments & the Request/Approval Subsystem — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An employee files a request to correct a missed/wrong punch (add/void/amend, required note, optional attachment); a manager or HR approves; the correction supersedes the append-only ledger via annulment records — never mutation. Ships the shared `requests` spine + state machine + approval-authority rule that leave and overtime reuse.

**Architecture:** A generic `requests` table + state machine, with a 1:1 `attendance_adjustment_details` type table. Approval dispatches to a per-type effect (`ApplyAttendanceAdjustment`) that writes through the M3 single writer `RecordPunch` (for `add`) and a new single writer `RecordAnnulment` (for `void`) — both arch-guarded and append-only. Approval authority is M2's `EmployeeScope`, minus self. Attachments use `spatie/laravel-medialibrary` on a RustFS (S3-compatible) disk.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 · Sanctum · spatie/laravel-medialibrary 11 · league/flysystem-aws-s3-v3 · RustFS · Pest 4

## Global Constraints

- **PHP 8.5**, **Laravel 13**, **PostgreSQL 18**. Pinned. `spatie/laravel-medialibrary:^11.23`, `league/flysystem-aws-s3-v3:^3.35` (both verified to resolve).
- **`declare(strict_types=1);` at the top of every PHP file** in `app/`, `database/`, `tests/`. CI greps all three.
- **All PKs uuid with `default(DB::raw('uuidv7()'))`; all FKs uuid.** Every uuid model overrides `newUniqueId()` → `Str::uuid7()`. Media Library's `media.model_id` morph must be **uuid**, not the default bigint (the M2 cascade gotcha).
- **String columns + PHP backed enums + `CHECK` constraints.** No Postgres native enum types; the `CHECK` value list and the enum cases must match (a test asserts it).
- **The append-only ledger is never mutated.** M3's invariant stands: `attendance_logs` rows are never updated/deleted; `RecordPunch` is its sole writer (arch-guarded). A void/amend records an **annulment**, never an edit.
- **`attendance_annulments` has exactly one writer (`RecordAnnulment`) and is append-only.** A new arch guard, mirroring the `RecordPunch` one.
- **The effective ledger = `attendance_logs` minus `attendance_annulments`.** The M5 compute engine reads that, not the raw table. Nothing in this milestone computes pay.
- **Approval authority = the requester is in `EmployeeScope::visibleTo(approver)` AND `approver.employee.id !== requester.employee.id`** (no self-approval).
- **Refusals: 404 for out-of-scope subjects/requests, 403 for unauthorized actors, 422 for domain rules.** M2's rule, reused.
- **All timestamps `timestamptz`, stored UTC.** `punched_at` on a detail is normalized to UTC by `RecordPunch` (the M3 `->utc()` fix).
- **Actions own their transaction, never touch HTTP. One action = one route = one invokable controller = one FormRequest = one resource.**
- **Tests run against real PostgreSQL, never SQLite.** Two real connections for the approve-vs-cancel concurrency case. `Storage::fake('attachments')` for flow tests; a live RustFS round-trip only in the e2e.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL.

## File structure

```
compose.dev.yml                                         + rustfs service + bucket-init
backend/config/filesystems.php                          + attachments (s3→rustfs) disk
backend/database/migrations/
  ..._create_permission_tables? no — media:
  ..._create_media_table.php                            published, model_id → uuid morph
  ..._create_requests_table.php
  ..._create_attendance_adjustment_details_table.php
  ..._create_attendance_annulments_table.php
  ..._add_adjustment_to_attendance_logs_source_check.php  widen the source CHECK
backend/app/Domain/Requests/
  RequestType.php  RequestState.php                     backed enums
backend/app/Domain/Attendance/PunchSource.php           + Adjustment case
backend/app/Domain/Attendance/AdjustmentOperation.php   add|void|amend enum
backend/app/Models/
  Request.php (HasMedia)  AttendanceAdjustmentDetail.php  AttendanceAnnulment.php
backend/app/Actions/Attendance/
  RecordAnnulment.php                                   the annulment single writer
  SubmitAttendanceAdjustment.php (+Input)
  ApplyAttendanceAdjustment.php                          the approval effect (add/void/amend)
backend/app/Actions/Requests/
  ApproveRequest.php  RejectRequest.php  CancelRequest.php
backend/app/Http/Controllers/Attendance/Adjustments/    submit, approve, reject, cancel, list, show, download
backend/app/Http/Requests/  Http/Resources/
```

---

### Task 1: RustFS + Media Library infrastructure

The dev-stack object store, the S3 disk, and Media Library wired to a uuid model.

**Files:**
- Modify: `compose.dev.yml` (add `rustfs` + a one-shot bucket-create)
- Modify: `.env.example`, `backend/.env.example`, `backend/phpunit.xml`
- Modify: `backend/config/filesystems.php`
- Create: `backend/database/migrations/2026_07_26_000001_create_media_table.php` (published, uuid morph)
- Modify: `backend/composer.json`/`composer.lock` (via `composer require`)
- Test: `backend/tests/Feature/Attachments/MediaLibraryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: an `attachments` disk (s3 driver → RustFS); a `media` table whose `model_id` is `uuid`; a `Request`-attachable media capability proven against a uuid model.

- [ ] **Step 1: Install the packages**

```bash
cd backend
composer require spatie/laravel-medialibrary:^11.23 league/flysystem-aws-s3-v3:^3.35 --no-interaction
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
```

- [ ] **Step 2: Edit the published media migration for uuid**

The published migration is `..._create_media_table.php`. Rename it to `2026_07_26_000001_create_media_table.php` so it orders after M0–M3. Add `declare(strict_types=1);`. Its `$table->uuidMorphs('model')` may already be uuid in v11 — **check**: if it uses `$table->morphs('model')` or `$table->nullableMorphs('model')` (bigint), change to `$table->uuidMorphs('model')`. The `media` table's own `id` stays as Media Library ships it (it uses a bigint auto-increment id by default; that is fine — nothing FKs to `media.id` by uuid; only `model_id` must match our uuid models). Record which morph form the published stub used.

- [ ] **Step 3: Add the `rustfs` service to compose**

In `compose.dev.yml`, add under `services:` (host port offset from POS which holds 9000/9001 nowhere, but pick 9100/9101 to avoid any clash):

```yaml
  rustfs:
    image: rustfs/rustfs:latest
    environment:
      RUSTFS_ACCESS_KEY: ${HRIS_DEV_RUSTFS_KEY:-hrislocal}
      RUSTFS_SECRET_KEY: ${HRIS_DEV_RUSTFS_SECRET:-hrislocalsecret}
      RUSTFS_ADDRESS: ":9000"
      RUSTFS_CONSOLE_ENABLE: "true"
      RUSTFS_CONSOLE_ADDRESS: ":9001"
      RUSTFS_VOLUMES: /data
    ports:
      - "${HRIS_DEV_RUSTFS_PORT:-9100}:9000"
      - "${HRIS_DEV_RUSTFS_CONSOLE_PORT:-9101}:9001"
    volumes:
      - rustfs_data:/data
    # RustFS runs as uid 10001; the named volume is created root-owned on first boot, so
    # the container can't write /data. Same class of fix as the api/web services: start as
    # root, chown once, drop to the rustfs user. If the image provides no shell/user to su
    # to, instead set the volume's ownership via a tmpfs-free init container (see Step 3b).
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost:9000/ >/dev/null 2>&1 || exit 1"]
      interval: 5s
      timeout: 5s
      retries: 12
      start_period: 30s

  rustfs-init:
    image: amazon/aws-cli:latest
    depends_on:
      rustfs: { condition: service_healthy }
    environment:
      AWS_ACCESS_KEY_ID: ${HRIS_DEV_RUSTFS_KEY:-hrislocal}
      AWS_SECRET_ACCESS_KEY: ${HRIS_DEV_RUSTFS_SECRET:-hrislocalsecret}
      AWS_DEFAULT_REGION: us-east-1
    entrypoint: ["/bin/sh","-c"]
    # Create the bucket if absent, then exit. --endpoint-url points at the in-network S3 API.
    command:
      - |
        aws --endpoint-url http://rustfs:9000 s3api head-bucket --bucket hris-attachments 2>/dev/null \
          || aws --endpoint-url http://rustfs:9000 s3 mb s3://hris-attachments
    restart: "no"
```

Add `rustfs_data:` to the `volumes:` block. Add `HRIS_DEV_RUSTFS_KEY`, `HRIS_DEV_RUSTFS_SECRET`, `HRIS_DEV_RUSTFS_PORT`, `HRIS_DEV_RUSTFS_CONSOLE_PORT` to `.env.example`. Add the api service `environment:` block the S3 vars (Step 4).

**Step 3b (uid 10001 volume ownership):** if `rustfs` fails to write `/data`, add the same `user: root` + chown-once + drop-to-user command the `api`/`web` services use, dropping to uid 10001. Confirm at boot; report the exact form used. If `rustfs/rustfs:latest` handles its own volume ownership, no change is needed — verify by watching `docker compose logs rustfs` on first boot.

- [ ] **Step 4: Configure the `attachments` disk**

In `backend/config/filesystems.php`, add to `disks`:

```php
        'attachments' => [
            'driver' => 's3',
            'key' => env('ATTACHMENTS_S3_KEY'),
            'secret' => env('ATTACHMENTS_S3_SECRET'),
            'region' => env('ATTACHMENTS_S3_REGION', 'us-east-1'),
            'bucket' => env('ATTACHMENTS_S3_BUCKET', 'hris-attachments'),
            'endpoint' => env('ATTACHMENTS_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,   // RustFS/MinIO need path-style, not vhost-style
            'throw' => true,
            'visibility' => 'private',
        ],
```

In `backend/.env.example` add: `ATTACHMENTS_S3_KEY=`, `ATTACHMENTS_S3_SECRET=`, `ATTACHMENTS_S3_REGION=us-east-1`, `ATTACHMENTS_S3_BUCKET=hris-attachments`, `ATTACHMENTS_S3_ENDPOINT=http://rustfs:9000`. In the api service's compose `environment:`, set the same (with the RustFS creds and `http://rustfs:9000`). In `backend/phpunit.xml`, force `FILESYSTEM_DISK=local` and leave the `attachments` disk unset — tests use `Storage::fake('attachments')`, never the real RustFS.

In `backend/config/medialibrary.php`, set `'disk_name' => 'attachments'` so media default to the RustFS disk.

- [ ] **Step 5: Write the failing test**

Create `backend/tests/Feature/Attachments/MediaLibraryTest.php`. This proves Media Library can attach a file to a **uuid** model (the cascade gotcha) using a faked disk. A throwaway in-test model keeps it independent of the not-yet-built `Request`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

it('attaches media to a uuid model on the attachments disk', function (): void {
    Storage::fake('attachments');

    // Use a real uuid model already in the schema — an Office — as the media owner, just
    // to prove the uuid morph works. (The Request model is Task 2.)
    $office = App\Models\Office::factory()->create();

    $office->addMedia(UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'))
        ->toMediaCollection('attachment', 'attachments');

    $media = Media::query()->first();
    expect($media)->not->toBeNull()
        ->and($media->model_id)->toBe($office->id)   // a uuid, not a truncated int
        ->and($media->model_id)->toBeString()
        ->and($media->disk)->toBe('attachments');

    Storage::disk('attachments')->assertExists($media->getPathRelativeToRoot());
});
```

**Note:** for this test `Office` must temporarily be `HasMedia`. Rather than pollute `Office`, if the trait isn't there, the test can instead assert at the migration level: insert a `media` row with a uuid `model_id` via `DB::table` and read it back unchanged (proving the column is uuid, not bigint that truncates). Use whichever cleanly proves the morph is uuid; the real `Request` HasMedia wiring lands in Task 2. Record which form you used.

- [ ] **Step 6: Run it to verify it fails, then make it pass**

Run: `cd backend && php artisan migrate:fresh && ./vendor/bin/pest tests/Feature/Attachments/MediaLibraryTest.php`
Expected: FAIL if the morph is still bigint (uuid truncates/mismatches) or the disk is misconfigured; PASS after the uuid-morph edit and disk config.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add compose.dev.yml .env.example backend/.env.example backend/phpunit.xml backend/config backend/database/migrations backend/composer.json backend/composer.lock backend/tests/Feature/Attachments
git commit -m "Attachments: RustFS disk + Media Library, uuid morph

An S3 driver pointed at a RustFS service in the dev stack; Media Library's
media.model_id switched to a uuid morph so it attaches to our uuidv7 models.
Tests fake the disk; a live RustFS round-trip is proven only in the e2e."
```

---

### Task 2: The `requests` spine — table, enums, model, factory

The generic state-machine spine, `HasMedia` for the attachment.

**Files:**
- Create: `backend/app/Domain/Requests/RequestType.php`, `RequestState.php`
- Create: `backend/database/migrations/2026_07_26_000002_create_requests_table.php`
- Create: `backend/app/Models/Request.php`
- Create: `backend/database/factories/RequestFactory.php`
- Test: `backend/tests/Feature/Schema/RequestSchemaTest.php`

**Interfaces:**
- Consumes: `Employee`, `User` (M2); Media Library (Task 1).
- Produces:
  - `App\Domain\Requests\RequestType` — backed enum, `AttendanceAdjustment = 'attendance_adjustment'`.
  - `App\Domain\Requests\RequestState` — backed enum, `Pending = 'pending'`, `Approved = 'approved'`, `Rejected = 'rejected'`, `Cancelled = 'cancelled'`.
  - `App\Models\Request` — `HasUuids`, `HasMedia`; columns `id, type, employee_id, state, note, decided_by, decided_at, decision_note`; casts `type`/`state` to enums, `decided_at` datetime; relations `employee()`, `decidedBy()`; a single-file `attachment` media collection on the `attachments` disk accepting pdf/jpeg/png; method `isPending(): bool`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Schema/RequestSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores a request with typed enums', function (): void {
    $employee = Employee::factory()->create();

    $request = Request::factory()->create([
        'employee_id' => $employee->id,
        'type' => RequestType::AttendanceAdjustment,
        'state' => RequestState::Pending,
        'note' => 'Forgot to clock out.',
    ]);

    $fresh = $request->fresh();
    expect($fresh->type)->toBe(RequestType::AttendanceAdjustment)
        ->and($fresh->state)->toBe(RequestState::Pending)
        ->and($fresh->isPending())->toBeTrue()
        ->and($fresh->employee->is($employee))->toBeTrue();
});

it('requires a note', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'pending',
        'note' => null,             // NOT NULL
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects a state outside the CHECK', function (): void {
    $employee = Employee::factory()->create();

    expect(fn () => DB::table('requests')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'employee_id' => $employee->id,
        'type' => 'attendance_adjustment',
        'state' => 'half_approved',   // not in the CHECK
        'note' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps the CHECK lists in sync with the enum cases', function (): void {
    expect(array_map(fn ($c) => $c->value, RequestType::cases()))->toBe(['attendance_adjustment'])
        ->and(array_map(fn ($c) => $c->value, RequestState::cases()))->toBe(['pending', 'approved', 'rejected', 'cancelled']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/RequestSchemaTest.php`
Expected: FAIL — `RequestType` not found.

- [ ] **Step 3: Write the enums**

Create `backend/app/Domain/Requests/RequestType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/** Widens as request types are added (leave, overtime); attendance adjustment is first. */
enum RequestType: string
{
    case AttendanceAdjustment = 'attendance_adjustment';
}
```

Create `backend/app/Domain/Requests/RequestState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Requests;

/** pending → approved | rejected | cancelled. No draft — a request is submitted directly. */
enum RequestState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 4: Write the migration**

Create `backend/database/migrations/2026_07_26_000002_create_requests_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared request/approval spine. Leave and overtime reuse this table and its state
 * machine; each type gets its own 1:1 detail table. See docs/02-data-model.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('type');
            $table->foreignUuid('employee_id')->constrained();   // the requester
            $table->text('state')->default('pending');
            $table->text('note');                                 // required
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();            // required on rejection (app-enforced)
            $table->timestampsTz();

            $table->index(['employee_id', 'state']);
            $table->index(['type', 'state']);                     // the approval queue query
        });

        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment'))");
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_state_check CHECK (state IN ('pending','approved','rejected','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
```

- [ ] **Step 5: Write the model**

Create `backend/app/Models/Request.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Request extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\RequestFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'state' => RequestState::class,
            'decided_at' => 'datetime',
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

    public function isPending(): bool
    {
        return $this->state === RequestState::Pending;
    }

    public function registerMediaCollections(): void
    {
        // Optional single attachment (a photo/PDF backing the correction), on the private
        // RustFS-backed disk. singleFile() means a re-upload replaces rather than appends.
        $this->addMediaCollection('attachment')
            ->singleFile()
            ->useDisk('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasOne<AttendanceAdjustmentDetail, $this> */
    public function attendanceAdjustmentDetail(): HasOne
    {
        return $this->hasOne(AttendanceAdjustmentDetail::class);
    }
}
```

**Note:** `attendanceAdjustmentDetail()` references a model built in Task 3. If the class doesn't exist yet when this task's tests run, that's fine — the relation is only resolved when called, and no Task 2 test calls it. Keep the method.

- [ ] **Step 6: Write the factory**

Create `backend/database/factories/RequestFactory.php` producing a `pending` `attendance_adjustment` request for a new `Employee`, with a `note`.

- [ ] **Step 7: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/RequestSchemaTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Requests backend/database/migrations backend/app/Models/Request.php backend/database/factories/RequestFactory.php backend/tests/Feature/Schema/RequestSchemaTest.php
git commit -m "Requests: the shared request/approval spine

A generic requests table + state machine (pending → approved/rejected/
cancelled), HasMedia for the optional attachment. Leave and overtime reuse
this; attendance adjustment is the first type."
```

---

### Task 3: Adjustment detail, annulment table, and the `adjustment` source

The type detail, the annulment ledger, and widening `PunchSource`.

**Files:**
- Create: `backend/app/Domain/Attendance/AdjustmentOperation.php`
- Modify: `backend/app/Domain/Attendance/PunchSource.php` (+ `Adjustment`)
- Create: `backend/database/migrations/2026_07_26_000003_create_attendance_adjustment_details_table.php`
- Create: `backend/database/migrations/2026_07_26_000004_create_attendance_annulments_table.php`
- Create: `backend/database/migrations/2026_07_26_000005_add_adjustment_to_attendance_logs_source_check.php`
- Create: `backend/app/Models/AttendanceAdjustmentDetail.php`, `AttendanceAnnulment.php`
- Create: their factories
- Test: `backend/tests/Feature/Schema/AdjustmentSchemaTest.php`

**Interfaces:**
- Consumes: `Request` (Task 2), `AttendanceLog`, `PunchSource` (M3).
- Produces:
  - `App\Domain\Attendance\AdjustmentOperation` — backed enum: `Add = 'add'`, `Void = 'void'`, `Amend = 'amend'`.
  - `App\Domain\Attendance\PunchSource` — adds `Adjustment = 'adjustment'`.
  - `App\Models\AttendanceAdjustmentDetail` — PK `request_id` (1:1); `operation` (cast enum), `target_log_id` (nullable), `direction` (nullable, cast `PunchDirection`), `punched_at` (nullable datetime); `belongsTo(Request)`, `belongsTo(AttendanceLog, 'target_log_id')`.
  - `App\Models\AttendanceAnnulment` — `HasUuids`; `attendance_log_id`, `request_id`; `belongsTo` both; append-only (`$timestamps = false`, only `created_at`).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Schema/AdjustmentSchemaTest.php` asserting: a detail row round-trips its enum `operation` and nullable fields; the `attendance_adjustment_details.request_id` is a true 1:1 (a second detail for the same request violates the PK); an `attendance_annulments` row round-trips and the `unique(attendance_log_id)` rejects a second annulment of the same punch; a raw insert of `source = 'adjustment'` into `attendance_logs` now **succeeds** (the widened CHECK); and the `AdjustmentOperation` cases are `['add','void','amend']`. Also assert a raw insert of `operation = 'delete'` into the detail table is rejected by its CHECK.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/AdjustmentSchemaTest.php`
Expected: FAIL — `AdjustmentOperation` not found.

- [ ] **Step 3: Write the enum and the `Adjustment` source**

Create `backend/app/Domain/Attendance/AdjustmentOperation.php` (`Add`/`Void`/`Amend`). Add `case Adjustment = 'adjustment';` to `PunchSource`.

- [ ] **Step 4: Write the three migrations**

`attendance_adjustment_details`: PK `request_id` (uuid, FK → requests cascade), `operation` text, `target_log_id` uuid nullable FK → attendance_logs, `direction` text nullable, `punched_at` timestamptz nullable. Add `CHECK (operation IN ('add','void','amend'))` and `CHECK (direction IS NULL OR direction IN ('in','out'))`.

`attendance_annulments`: uuid PK (uuidv7), `attendance_log_id` uuid FK → attendance_logs, `request_id` uuid FK → requests, `created_at` timestamptz `useCurrent()`, `unique(attendance_log_id)`.

`add_adjustment_to_attendance_logs_source_check`: drop the existing `attendance_logs_source_check` and re-add it with `('web','manual','device','adjustment')`. (Postgres: `ALTER TABLE attendance_logs DROP CONSTRAINT attendance_logs_source_check; ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_source_check CHECK (source IN ('web','manual','device','adjustment'));`.)

- [ ] **Step 5: Write the models and factories**

`AttendanceAdjustmentDetail` — `$primaryKey = 'request_id'`, `$keyType = 'string'`, `$incrementing = false`, no `HasUuids` (the PK is the request's id, not generated here); casts `operation` and `direction` and `punched_at`; `belongsTo(Request)` and `belongsTo(AttendanceLog, 'target_log_id')`. `AttendanceAnnulment` — `HasUuids` + uuid7 override, `$timestamps = false`, `belongsTo(AttendanceLog)` + `belongsTo(Request)`.

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/AdjustmentSchemaTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Attendance backend/database/migrations backend/app/Models/Attendance*.php backend/database/factories backend/tests/Feature/Schema/AdjustmentSchemaTest.php
git commit -m "Adjustments: detail + annulment tables, the adjustment source

attendance_adjustment_details (1:1 with a request), attendance_annulments
(append-only, one annulment per punch), and PunchSource::Adjustment. A void
supersedes a punch without touching the append-only ledger."
```

---

### Task 4: `RecordAnnulment` — the annulment single writer

The one writer of `attendance_annulments`, arch-guarded, append-only.

**Files:**
- Create: `backend/app/Actions/Attendance/RecordAnnulment.php`
- Modify: `backend/tests/Arch/ConventionsTest.php`
- Test: `backend/tests/Feature/Attendance/RecordAnnulmentTest.php`

**Interfaces:**
- Consumes: `AttendanceLog`, `Request`, `AttendanceAnnulment` (Task 3).
- Produces: `App\Actions\Attendance\RecordAnnulment` — `final`, `execute(string $attendanceLogId, string $requestId): AttendanceAnnulment`. Creates the annulment row. It does **not** validate ownership/existence (the effect does that under the request lock, Task 6) — it is the low-level append. It never updates/deletes.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/RecordAnnulmentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordAnnulment;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an annulment linking a punch to the approved request', function (): void {
    $log = AttendanceLog::factory()->create();
    $request = Request::factory()->create();

    $annulment = app(RecordAnnulment::class)->execute($log->id, $request->id);

    expect($annulment->attendance_log_id)->toBe($log->id)
        ->and($annulment->request_id)->toBe($request->id)
        ->and(AttendanceAnnulment::count())->toBe(1);
});

it('refuses to annul the same punch twice (the unique backstop)', function (): void {
    $log = AttendanceLog::factory()->create();
    $r1 = Request::factory()->create();
    $r2 = Request::factory()->create();

    app(RecordAnnulment::class)->execute($log->id, $r1->id);

    expect(fn () => app(RecordAnnulment::class)->execute($log->id, $r2->id))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/RecordAnnulmentTest.php`
Expected: FAIL — `RecordAnnulment` not found.

- [ ] **Step 3: Write the action**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Models\AttendanceAnnulment;

/**
 * The single writer of attendance_annulments (arch-guarded). A void/amend supersedes a
 * punch by recording that it is annulled by an approved request — the original punch row
 * is never touched, preserving the append-only ledger. Ownership/existence/not-already-
 * annulled are validated by ApplyAttendanceAdjustment under the request lock; this is the
 * low-level append. Never updates or deletes. See docs/02-data-model.md.
 */
final class RecordAnnulment
{
    public function execute(string $attendanceLogId, string $requestId): AttendanceAnnulment
    {
        return AttendanceAnnulment::query()->create([
            'attendance_log_id' => $attendanceLogId,
            'request_id' => $requestId,
        ]);
    }
}
```

- [ ] **Step 4: Add the single-writer arch guard**

In `backend/tests/Arch/ConventionsTest.php`, add a guard mirroring the `only RecordPunch writes attendance_logs` one (find it near line 268), for `attendance_annulments`/`AttendanceAnnulment`: assert the sole `app/` file writing it (`create`/`new`/`->save`/`->update`/`->delete`/`updateOrCreate`/`upsert`/raw `DB::table('attendance_annulments')->insert|update|delete`) is `Actions/Attendance/RecordAnnulment.php`, exempting `app/Http/Resources/`. Record the form used.

- [ ] **Step 5: Run the action test + arch suite; prove the guard bites**

Run the action test (PASS, 2) and `--testsuite=Arch` (PASS). Then add a scratch `app/Scratch/BadAnnulmentWriter.php` doing `AttendanceAnnulment::create([...])`, confirm the arch suite FAILS naming it, delete it, confirm PASS. Record the output.

- [ ] **Step 6: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Attendance/RecordAnnulment.php backend/tests/Arch/ConventionsTest.php backend/tests/Feature/Attendance/RecordAnnulmentTest.php
git commit -m "Attendance: RecordAnnulment, the one writer of annulments

A void supersedes a punch by recording an annulment, never mutating the
append-only ledger. An arch guard proves nothing else in app/ writes the
annulment table; the unique(attendance_log_id) backstops a double-void."
```

---

### Task 5: Submit an attendance adjustment

The requester-facing submit: create the request + detail, attach the optional file.

**Files:**
- Create: `backend/app/Actions/Attendance/SubmitAttendanceAdjustment.php` (+ `SubmitAttendanceAdjustmentInput`)
- Create: `backend/app/Http/Requests/SubmitAdjustmentRequest.php`
- Create: `backend/app/Http/Controllers/Attendance/Adjustments/SubmitController.php`
- Create: `backend/app/Http/Resources/RequestResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Attendance/SubmitAdjustmentTest.php`

**Interfaces:**
- Consumes: `Request`, `AttendanceAdjustmentDetail`, `AdjustmentOperation`, `PunchDirection` (Tasks 2–3).
- Produces:
  - `SubmitAttendanceAdjustmentInput` — readonly DTO: `employeeId, operation (AdjustmentOperation), note, targetLogId (?string), direction (?PunchDirection), punchedAt (?string), attachment (?UploadedFile)`.
  - `SubmitAttendanceAdjustment` — `final`, `execute(SubmitAttendanceAdjustmentInput): Request`. One transaction: create the `pending` `Request` (type attendance_adjustment), the 1:1 detail, and — if present — attach the file to the `attachment` collection.
  - `POST /api/v1/attendance/adjustments` → `201` with the request resource.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/SubmitAdjustmentTest.php` covering: an `add` submission (creates a pending request + detail with direction/punched_at; note required); a `void` submission (target_log_id required, no direction/punched_at); an `amend` submission (target + direction + punched_at); an optional attachment stored via `Storage::fake('attachments')` and attached to the request's `attachment` collection; a missing `note` → 400 `validation_failed`; an `add` missing `direction`/`punched_at` → 400; a `void` missing `target_log_id` → 400; a non-employee user → 422 `not_an_employee`; and an oversized/wrong-type attachment → 400. Use `Sanctum::actingAs` an employee. Assert the request's `employee_id` is the actor's own employee (you file for yourself).

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/SubmitAdjustmentTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Write the FormRequest**

`SubmitAdjustmentRequest`: `authorize()` returns `true` (the "is an employee" check is in the controller/action, yielding 422 not 403). `rules()`: `operation` (required, in add/void/amend); `note` (required, string); `target_log_id` (`required_if:operation,void,amend`, uuid, exists:attendance_logs,id); `direction` (`required_if:operation,add,amend`, in in/out); `punched_at` (`required_if:operation,add,amend`, date); `attachment` (nullable, file, mimes:pdf,jpg,jpeg,png, max:10240). Match the existing FormRequest idiom.

- [ ] **Step 4: Write the action and DTO**

`SubmitAttendanceAdjustment::execute` in one `DB::transaction`: create the `Request` (state pending, type attendance_adjustment, employee_id, note), create the `AttendanceAdjustmentDetail` (request_id, operation, target_log_id, direction, punched_at), and if `attachment` present `$request->addMedia($in->attachment->getRealPath())->usingFileName(...)->toMediaCollection('attachment')` (or `addMediaFromRequest` at the controller — keep the file handling in the action via the `UploadedFile`). Return the `Request` with its detail loaded.

- [ ] **Step 5: Write the controller, resource, route**

`SubmitController` (invokable): resolve `$request->user()->employee` (→ `NotAnEmployee` if null), build the input from the validated request + the uploaded file, call the action, return `RequestResource` at 201. `RequestResource` serializes id, type, state, note, employee_id, the detail (operation/target_log_id/direction/punched_at), decided_by/at/note, and whether an attachment exists (`$this->hasMedia('attachment')`). Route `POST /attendance/adjustments` behind `auth:sanctum`.

- [ ] **Step 6: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Attendance/SubmitAdjustmentTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Attendance/SubmitAttendanceAdjustment*.php backend/app/Http/Requests/SubmitAdjustmentRequest.php backend/app/Http/Controllers/Attendance/Adjustments/SubmitController.php backend/app/Http/Resources/RequestResource.php backend/routes/api.php backend/tests/Feature/Attendance/SubmitAdjustmentTest.php
git commit -m "Adjustments: submit an attendance adjustment

An employee files an add/void/amend for their own attendance with a
required note and an optional attachment; the request lands pending with
its typed detail. Non-employee is 422."
```

---

### Task 6: `ApplyAttendanceAdjustment` — the approval effect

Given an approved request, apply add/void/amend — with the approval-time validation.

**Files:**
- Create: `backend/app/Actions/Attendance/ApplyAttendanceAdjustment.php`
- Create: `backend/app/Exceptions/Domain/InvalidAdjustmentTarget.php`
- Test: `backend/tests/Feature/Attendance/ApplyAdjustmentTest.php`

**Interfaces:**
- Consumes: `Request` + its detail, `RecordPunch`/`RecordPunchInput` (M3), `RecordAnnulment` (Task 4), `PunchSource::Adjustment`.
- Produces: `App\Actions\Attendance\ApplyAttendanceAdjustment` — `final`, `apply(Request $request, string $approverUserId): void`. Reads the detail; validates the target (for void/amend); performs the effect. **Called by `ApproveRequest` inside the request lock** (Task 7) — it assumes the caller holds the lock and the request is being approved. Throws `InvalidAdjustmentTarget` (422) if a void/amend target is missing, not the requester's, or already annulled.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/ApplyAdjustmentTest.php` covering, by calling `apply()` directly:
- **add** → a new `attendance_logs` row exists for the requester with `source: adjustment`, `recorded_by` = approver, the detail's direction/punched_at.
- **void** → an `attendance_annulments` row for the target; the original punch row still exists untouched; the effective ledger (a query: logs minus annulments) no longer contains the target.
- **amend** → the target is annulled AND a new corrected punch exists; effective ledger shows the corrected one, not the original.
- **invalid target** → `InvalidAdjustmentTarget` (422) when the void target belongs to a different employee, or is already annulled, or doesn't exist.
- **UTC** → an amend/add with a supplied offset stores the punch at the correct UTC instant (RecordPunch normalizes).

- [ ] **Step 2–4: RED, write `InvalidAdjustmentTarget` and the action, GREEN**

`InvalidAdjustmentTarget` extends `DomainException`, code `invalid_adjustment_target`, 422.

`ApplyAttendanceAdjustment::apply`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\InvalidAdjustmentTarget;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Request;
use Illuminate\Support\Carbon;

/**
 * Applies an approved attendance adjustment to the ledger. Called by ApproveRequest INSIDE
 * the request-row lock, so it assumes serialized approval. add → RecordPunch; void →
 * RecordAnnulment; amend → both. The append-only ledger is never mutated. See the spec.
 */
final class ApplyAttendanceAdjustment
{
    public function __construct(
        private readonly RecordPunch $recordPunch,
        private readonly RecordAnnulment $recordAnnulment,
    ) {}

    public function apply(Request $request, string $approverUserId): void
    {
        /** @var \App\Models\AttendanceAdjustmentDetail $detail */
        $detail = $request->attendanceAdjustmentDetail()->firstOrFail();

        $isVoid = $detail->operation === AdjustmentOperation::Void || $detail->operation === AdjustmentOperation::Amend;
        $isAdd = $detail->operation === AdjustmentOperation::Add || $detail->operation === AdjustmentOperation::Amend;

        if ($isVoid) {
            $this->assertAnnullable($detail->target_log_id, $request->employee_id);
            $this->recordAnnulment->execute($detail->target_log_id, $request->id);
        }

        if ($isAdd) {
            $this->recordPunch->execute(new RecordPunchInput(
                employeeId: $request->employee_id,
                direction: $detail->direction,
                source: PunchSource::Adjustment,
                punchedAt: Carbon::parse($detail->punched_at),
                recordedBy: $approverUserId,
                ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
            ));
        }
    }

    private function assertAnnullable(?string $targetLogId, string $requesterEmployeeId): void
    {
        $target = $targetLogId === null ? null : AttendanceLog::query()->find($targetLogId);

        if ($target === null || $target->employee_id !== $requesterEmployeeId) {
            throw new InvalidAdjustmentTarget('The punch to correct is missing or not yours.');
        }

        if (AttendanceAnnulment::query()->where('attendance_log_id', $targetLogId)->exists()) {
            throw new InvalidAdjustmentTarget('That punch is already annulled.');
        }
    }
}
```

Note `$detail->punched_at` is a Carbon (cast); `Carbon::parse` on it is safe, and `RecordPunch` re-normalizes to UTC. If the cast already yields a Carbon, pass it directly instead of re-parsing.

- [ ] **Step 5: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Actions/Attendance/ApplyAttendanceAdjustment.php backend/app/Exceptions/Domain/InvalidAdjustmentTarget.php backend/tests/Feature/Attendance/ApplyAdjustmentTest.php
git commit -m "Adjustments: ApplyAttendanceAdjustment — the approval effect

add → RecordPunch (source adjustment), void → RecordAnnulment, amend →
both. Validates the target at apply time (exists, is the requester's, not
already annulled). The raw ledger is never mutated."
```

---

### Task 7: Approve / reject / cancel — the transitions

The state machine, approval authority, concurrency, and the endpoints.

**Files:**
- Create: `backend/app/Actions/Requests/ApproveRequest.php`, `RejectRequest.php`, `CancelRequest.php`
- Create: `backend/app/Exceptions/Domain/RequestNotPending.php`
- Create: controllers under `backend/app/Http/Controllers/Attendance/Adjustments/` (Approve/Reject/Cancel) + FormRequests
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Attendance/AdjustmentTransitionsTest.php`

**Interfaces:**
- Consumes: `Request`, `ApplyAttendanceAdjustment` (Task 6), `EmployeeScope` (M2).
- Produces:
  - `ApproveRequest::execute(Request, User $approver): Request` — locks the request; asserts pending (`RequestNotPending` 409 else); asserts authority (below); dispatches the type effect (`ApplyAttendanceAdjustment::apply`); sets state approved, decided_by/at. One transaction.
  - `RejectRequest::execute(Request, User $approver, string $decisionNote): Request` — lock, assert pending, assert authority, set rejected + decision_note.
  - `CancelRequest::execute(Request, User $requester): Request` — lock, assert pending, assert the actor is the requester, set cancelled.
  - Endpoints `POST /attendance/adjustments/{request}/approve|reject|cancel`.
  - Approval authority helper: `App\Domain\Requests\RequestAuthority::canDecide(User $approver, Request $request): bool` = `EmployeeScope::visibleTo($approver)->whereKey($request->employee_id)->exists() && $approver->employee?->id !== $request->employee_id`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Attendance/AdjustmentTransitionsTest.php` — the matrix:
- A **manager** approves their report's pending add → 200, punch appears, request approved, decided_by = manager.
- An **HR admin** over the requester's office approves → 200.
- A **system admin** approves any → 200.
- The **requester approving their own** → 404 (they are not an authorized approver — `canDecide` false because self).
- An **unrelated employee** (out of scope) approving → 404.
- **Reject** by an authorized approver with a `decision_note` → 200, state rejected; reject without a note → 400.
- **Cancel** by the requester while pending → 200, cancelled; cancel by someone else → 404; cancel after approval → 409 `request_not_pending`.
- **Double approval** → the second is 409 `request_not_pending`.
- The employee-scope authority: put an `InvalidAdjustmentTarget` case through approve (a void whose target got annulled by another request first) → 422.

- [ ] **Step 2–4: RED, implement, GREEN**

`RequestNotPending` (409, `request_not_pending`). `RequestAuthority::canDecide` as above. The three actions each `DB::transaction` + `Request::whereKey(...)->lockForUpdate()->firstOrFail()` + assert pending + assert authority/requester + effect/state write. Approve calls `ApplyAttendanceAdjustment::apply($request, $approver->id)` — which, per Task 6, throws `InvalidAdjustmentTarget` (422) and rolls back the whole approval if the target is bad.

The three controllers are thin invokables. Approve/Reject use a FormRequest whose `authorize()` returns `true` (authority is checked in the action against `RequestAuthority`, yielding **404** for a non-authorized approver — an out-of-scope request is indistinguishable from nonexistent). Reject validates `decision_note` (required). Cancel checks the actor is the requester in the action (404 otherwise). The controllers catch nothing — the domain exceptions render via the envelope.

Routes under `auth:sanctum`:
```php
        Route::post('/attendance/adjustments/{request}/approve', Adjustments\ApproveController::class);
        Route::post('/attendance/adjustments/{request}/reject', Adjustments\RejectController::class);
        Route::post('/attendance/adjustments/{request}/cancel', Adjustments\CancelController::class);
```

**404-vs-409 note:** a non-authorized approver gets **404** (subject-scope leak rule). An *authorized* approver acting on an *already-decided* request gets **409 request_not_pending** (they can see it; it's just not actionable). Order the checks: lock → authority (404 if unauthorized) → pending (409 if decided). For cancel, the actor must be the requester (404 if not) → pending (409 if decided).

- [ ] **Step 5: Concurrency proof**

Add a two-real-connections test: two `ApproveRequest` on the same pending request → exactly one succeeds, the other gets `RequestNotPending`; and no double effect (one punch, not two). Per the house rule, a single-process test would pass whether or not the lock exists — use two connections.

- [ ] **Step 6: Run + commit**

Run the transitions test + concurrency + arch + focused suite. Commit:

```bash
git commit -m "Adjustments: approve/reject/cancel with authority and locking

Approval authority is EmployeeScope-minus-self, so a manager or HR (never
the requester) decides; a non-authorized approver is 404, a decided request
409. Approve locks the row and applies the effect atomically — two
approvers resolve to one winner, one punch."
```

---

### Task 8: Reads + scoped attachment download

My requests, the approval queue, show, and the private attachment stream.

**Files:**
- Create: controllers under `Attendance/Adjustments/` (ListMine, ListPending, Show, DownloadAttachment)
- Modify: `backend/routes/api.php`, `backend/tests/Arch/ConventionsTest.php`
- Test: `backend/tests/Feature/Attendance/ReadAdjustmentsTest.php`

**Interfaces:**
- Consumes: `Request`, `RequestAuthority`, `EmployeeScope`, `RequestResource` (Tasks 5–7).
- Produces:
  - `GET /attendance/adjustments` — the caller's own requests.
  - `GET /attendance/adjustments/pending` — pending requests the caller may decide (requester in their `EmployeeScope`, minus self, state pending).
  - `GET /attendance/adjustments/{request}` — one request, visible to the requester or an authorized approver (else 404).
  - `GET /attendance/adjustments/{request}/attachment` — streams the media if the caller may see the request (else 404); 404 if no attachment.

- [ ] **Step 1: Write the failing test**

Cover: my-requests returns only mine; the pending queue returns a report's/office's pending requests but not my own and not out-of-scope ones; show is visible to requester and approver, 404 to an unrelated employee; the attachment downloads for the requester and an authorized approver, 404 for an unrelated employee, 404 when there's no attachment. Use `Storage::fake('attachments')` and attach a file in the setup.

- [ ] **Step 2–4: RED, implement, GREEN**

The pending-queue query: `Request::where('state','pending')->whereIn('employee_id', EmployeeScope::visibleTo($user)->pluck('id'))->where('employee_id','!=',$user->employee?->id)`. The download controller: resolve the request, check `$request->employee_id === $user->employee?->id || RequestAuthority::canDecide($user,$request)` else 404; `$media = $request->getFirstMedia('attachment')` → 404 if null; `return $media` (Media Library's `Responsable`) or stream it. Add an arch rule: every controller under `Attendance/Adjustments/` references an authorization boundary (`EmployeeScope`, `RequestAuthority`, or checks `->employee`), mirroring the M2/M3 attendance-controller guard.

- [ ] **Step 5: Commit**

```bash
git commit -m "Adjustments: scoped reads and private attachment download

My requests, the approval queue (in-scope-minus-self pending), a scoped
show, and a private attachment stream — you can only fetch a file for a
request you're allowed to see, never by guessing. 404 for out of scope."
```

---

### Task 9: Docs, seed, e2e, and the gate

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/06-roadmap.md`, `docs/README.md`, `docs/features.md`
- Create: `scripts/e2e-adjustments.sh`
- Modify: `backend/database/seeders/CompanySeeder.php` (a couple of seeded punches so a void/amend has a target for the e2e)
- Modify: `.github/workflows/ci.yml` (ensure the media migration + the s3 disk don't need RustFS in CI — tests fake the disk)

- [ ] **Step 1: `e2e-adjustments.sh` against the live stack (real RustFS)** — log in as an employee, submit an `add` adjustment **with a file attachment** (multipart), log in as their manager, approve, `GET /me/attendance` and assert the punch appears (`source: adjustment`), download the attachment as the manager and assert 200, download as an unrelated employee and assert 404; submit a `void` of a seeded punch, approve as HR, assert the effective read no longer shows it; attempt self-approval and assert refused. Make it executable.

- [ ] **Step 2: Docs** — `02-data-model.md`: the `requests` spine, `attendance_adjustment_details`, `attendance_annulments`, the effective-ledger definition, the two single-writer invariants (point at the guards), the Media Library `media` table + uuid morph. `03-api.md`: the submit/approve/reject/cancel/list/pending/show/download endpoints, their auth (in-scope-minus-self, 404/409/422 codes: `not_an_employee`, `invalid_adjustment_target`, `request_not_pending`), and the multipart attachment. `06-roadmap.md`: an M3.6 `**Status: complete.**` block (real test count, the annulment/append-only story, the RustFS+Media Library note, any wall-hit notes). `README.md`/`features.md`: keep current; add the user-facing adjustment features to `features.md`.

- [ ] **Step 3: Run everything** — `cd backend && ./vendor/bin/pest`; `cd frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts. **If `make test` needs the `attachments` disk**, confirm the containerized backend either has RustFS up (the compose now includes it) or the suite fakes the disk (it does) — report which.

- [ ] **Step 4: Commit** — `git commit -m "Adjustments: docs, e2e, M3.6 status"`.

---

## Done When

An employee files a missed-punch adjustment with a note and an attachment; their manager or HR approves; the punch appears in the ledger via `RecordPunch` (`source: adjustment`) and the effective read reflects it; a `void`, approved, removes a punch from the effective ledger while its raw row stays; self-approval is refused (404); an already-decided request refuses further transitions (409); the attachment downloads only for those who may see the request; two concurrent approvals resolve to one winner; and `attendance_annulments` has one arch-guarded writer. Full suite green.
