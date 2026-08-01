# M10b-a — Document catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the configurable-but-empty half of the document module — the schema, the morph map, the two permissions, the policy, an admin-editable catalog of document kinds, and the screen that edits it.

**Architecture:** Three tables (`document_categories` → `documents` → `document_files`). Spatie's `media` stays the file layer; `document_files` is a `HasMedia` model carrying only what spatie doesn't. Documentable types are a **non-enforcing** `Relation::morphMap()` fed from `config/documents.php`, so the database stores `'employee'`, never a FQCN. Two new permissions (`document.manage`, `document.manage.self`) gate everything through a `DocumentPolicy`. M10b-a creates all three tables but writes files through none of them — file endpoints are M10b-b.

**Tech Stack:** Laravel 13.21 / PHP 8.5, PostgreSQL 18, Pest, spatie/laravel-medialibrary, spatie/laravel-permission, Next.js 16 / React 19 / TypeScript, @tanstack/react-query, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-01-m10b-document-management-design.md` — read it before Task 1.

**Branch base:** this branch sits on `m10a-followups` (PR #31), not bare `main`. That is deliberate: M10b's seeder must be registered *above* `hris:bootstrap-admin`'s System-Admin guard, and only #31 puts the existing seeders there. Do not rebase onto `main`.

## Global Constraints

- `declare(strict_types=1);` at the top of **every** PHP file in `app/`, `database/`, and `tests/`. An arch test enforces it, including for migrations.
- **Never call `env()` outside `config/`.** An arch test enforces it.
- Actions are `final`, take an Input DTO, return a domain object, and **never** reference `Illuminate\Http\Request`, `Response`, `JsonResponse`, `Resources\Json\JsonResource`, or `Foundation\Http\FormRequest`. An arch test enforces it. (`Illuminate\Http\UploadedFile` is *not* on that list and is the house pattern for file params.)
- Controllers are `final` and `__invoke`-only. An arch test enforces it.
- `App\Domain` may not use `config`, `env`, `app`, `resolve`, or facades. Eloquent is allowed.
- **One system action = one route = one controller = one Action class.** Pure reads with no domain behaviour may be controller-only (precedent: `DownloadAttachmentController`, `ShowCatalogController`).
- Success responses are `{"data": ...}`; errors are `{"error": ...}`. Never both.
- **A failed FormRequest validation is HTTP `400`, not `422`.** `bootstrap/app.php` maps `ValidationException` to `400` with `error.code = 'validation_failed'`; `docs/03-api.md` reserves `422` for structurally-fine-but-semantically-rejected requests.
- **Out-of-scope or unauthorized is `404`, never `403`** — the owner id is in the URL, and a 403-for-real/404-for-nonexistent split lets any authenticated user enumerate ids.
- Calendar dates on the wire are `YYYY-MM-DD` strings, never `Date` objects.
- Every new model with a surrogate `id` uses `HasUuids` + `newUniqueId(): string { return (string) Str::uuid7(); }` + `uniqueIds(): array { return ['id']; }`.
- Migration primary keys are `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'));`.
- Tests run against **real PostgreSQL**, never SQLite. Do not change `phpunit.xml`.
- Frontend: every color/spacing/radius reads a `var(--*)` from `carbon.css`. A raw hex or literal pixel value is a bug. Any `font:` shorthand must set its `--ls-*` companion alongside.
- Use the design-system `Select` from `src/components/ui/`, never a native `<select>`.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL.

## Commands

```bash
# Backend — ALWAYS this form. A bare `./vendor/bin/pest` OOMs on the Arch suite.
make test-backend
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=SomeTest
docker compose -f compose.dev.yml exec -T --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch

# Migrations
docker compose -f compose.dev.yml exec -T --user hris api php artisan migrate

# Frontend — ALWAYS this form. A bare `npm test` runs 89 files at full parallelism and
# times out ~16 of them at 5s from worker contention. Those are NOT real failures.
docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npx vitest run --maxWorkers=4 --testTimeout=20000'
docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npm run typecheck && npm run lint && npm run build'
```

**Never restart, recreate, or `up` a container** — the developer's stack is in use. `exec` only.

**Baseline at branch point: 865 backend tests (20 Arch) + 577 frontend tests, all green.** Any failure beyond those is yours.

## Judgment calls already made

Recorded here because the spec left them open and the developer is unavailable:

1. **Catalog CRUD requires `document.manage` with no office scope.** `documents` and `document_categories` are company-wide — they have no `office_id` to scope by. Holding the permission at all is the check. File-level authorization *is* office-scoped, and lands in M10b-b.
2. **All three tables are created in M10b-a**, including `document_files`, even though nothing writes it until M10b-b. Splitting one coherent migration across two milestones is worse than an unused table, and the morph map is meaningless without it.
3. **`applies_to` and `validity_months` are validated but not enforced against existing files.** Changing a kind's `applies_to` after files exist is permitted; the spec's frozen-expiry rule means existing rows keep their computed `expires_on` regardless.

## File Structure

**Backend — created**

| File | Responsibility |
| --- | --- |
| `database/migrations/2026_08_14_000001_create_document_catalog_tables.php` | `document_categories` + `documents` |
| `database/migrations/2026_08_14_000002_create_document_files_table.php` | `document_files` |
| `app/Models/DocumentCategory.php` | Catalog shelf |
| `app/Models/Document.php` | The kind, + `LogsActivity` |
| `app/Models/DocumentFile.php` | The instance, `HasMedia('file')` |
| `database/factories/DocumentCategoryFactory.php` etc. (3) | Fixtures |
| `config/documents.php` | The documentable whitelist |
| `app/Policies/DocumentPolicy.php` | `manageCatalog` |
| `app/Exceptions/Domain/DocumentCatalogInUse.php` | 409 when deleting a row that has children |
| `app/Actions/Documents/*` (6 actions + 6 Inputs) | Category and Document create/update/delete |
| `app/Http/Requests/Documents/*` (4) | Validation + `authorize()` |
| `app/Http/Controllers/Documents/ShowCatalogController.php` | `GET /documents/catalog` |
| `app/Http/Controllers/Admin/Documents/*` (8) | Catalog CRUD |
| `app/Http/Resources/DocumentCategoryResource.php`, `DocumentResource.php` | Serialization |
| `database/seeders/DocumentCatalogSeeder.php` | The PH starter set |
| `tests/Feature/Documents/*` | Feature tests |

**Backend — modified**

| File | Change |
| --- | --- |
| `app/Providers/AppServiceProvider.php` | `Relation::morphMap(...)` + `Gate::policy(Document::class, DocumentPolicy::class)` |
| `database/seeders/RbacSeeder.php` | `document.manage`, `document.manage.self` |
| `database/seeders/DatabaseSeeder.php` | Call `DocumentCatalogSeeder` |
| `app/Console/Commands/BootstrapAdmin.php` | Call `DocumentCatalogSeeder`, beside the other two |
| `routes/api.php` | 9 routes |

**Frontend — created / modified**

| File | Responsibility |
| --- | --- |
| `src/lib/api.ts` | Types + `api.documents.*` |
| `src/lib/keys.ts` | `keys.documents.*` |
| `src/hooks/useDocumentCatalog.ts`, `useDocumentCatalogAdmin.ts` | Reads |
| `src/hooks/useSaveDocumentCatalog.ts` | The six mutations |
| `src/app/(app)/admin/documents/page.tsx` | The catalog admin screen |
| `src/components/SideNav.tsx` | Nav entry |

## Task Sequence

| # | Task | Deliverable |
| --- | --- | --- |
| 1 | Catalog tables + models | `document_categories`, `documents` |
| 2 | `document_files` + morph map | The polymorphic table, config, `Relation::morphMap` |
| 3 | Permissions + policy | `document.manage`, `document.manage.self`, `DocumentPolicy` |
| 4 | Seeder + bootstrap | PH starter set, reachable in production |
| 5 | `GET /documents/catalog` | The dropdown read |
| 6 | Category CRUD | 4 routes, in-use delete guard |
| 7 | Document CRUD | 4 routes, in-use delete guard |
| 8 | Policy matrix | The consolidated authorization test |
| 9 | Frontend API + keys | Types and client |
| 10 | Catalog admin screen | The UI |
| 11 | Docs | Data model, API, RBAC, roadmap, features, CLAUDE.md |

---

### Task 1: Catalog tables — `document_categories` and `documents`

**Files:**
- Create: `backend/database/migrations/2026_08_14_000001_create_document_catalog_tables.php`
- Create: `backend/app/Models/DocumentCategory.php`
- Create: `backend/app/Models/Document.php`
- Create: `backend/database/factories/DocumentCategoryFactory.php`
- Create: `backend/database/factories/DocumentFactory.php`
- Test: `backend/tests/Feature/Documents/DocumentCatalogModelTest.php`

**Interfaces:**
- Produces: `App\Models\DocumentCategory` (`code` unique, `name`, `description`), `App\Models\Document` (`code` unique, `name`, `description`, `category_id`, `applies_to`, `is_required`, `validity_months`) with `category(): BelongsTo` and `DocumentCategory::documents(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/DocumentCatalogModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a category and its documents', function (): void {
    $category = DocumentCategory::query()->create([
        'code' => 'PRE_EMPLOYMENT',
        'name' => 'Pre-employment',
        'description' => 'Collected before day one',
    ]);

    Document::query()->create([
        'code' => 'NBI',
        'name' => 'NBI Clearance',
        'description' => 'National Bureau of Investigation clearance',
        'category_id' => $category->id,
        'applies_to' => 'employee',
        'is_required' => true,
        'validity_months' => 6,
    ]);

    $document = $category->fresh()->documents->first();

    expect($document->code)->toBe('NBI')
        ->and($document->category->code)->toBe('PRE_EMPLOYMENT')
        ->and($document->is_required)->toBeTrue()
        ->and($document->validity_months)->toBe(6)
        ->and($document->applies_to)->toBe('employee');
});

it('defaults a document to optional, non-expiring, and applying to both owner types', function (): void {
    $category = DocumentCategory::factory()->create();

    $document = Document::query()->create([
        'code' => 'POLICY',
        'name' => 'Company Policy',
        'category_id' => $category->id,
    ]);

    expect($document->fresh()->is_required)->toBeFalse()
        ->and($document->fresh()->validity_months)->toBeNull()
        ->and($document->fresh()->applies_to)->toBeNull();
});

it('rejects a duplicate category code', function (): void {
    DocumentCategory::query()->create(['code' => 'STATUTORY', 'name' => 'Statutory']);

    expect(fn () => DocumentCategory::query()->create(['code' => 'STATUTORY', 'name' => 'Again']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('rejects a duplicate document code', function (): void {
    $category = DocumentCategory::factory()->create();
    Document::query()->create(['code' => 'NBI', 'name' => 'NBI', 'category_id' => $category->id]);

    expect(fn () => Document::query()->create(['code' => 'NBI', 'name' => 'Again', 'category_id' => $category->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

// The catalog is reference data other rows point at. Deleting a category out from under its
// documents must be refused by the database, not silently cascade.
it('refuses to delete a category that still has documents', function (): void {
    $category = DocumentCategory::factory()->create();
    Document::factory()->create(['category_id' => $category->id]);

    expect(fn () => $category->delete())->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCatalogModelTest`
Expected: FAIL — `Class "App\Models\DocumentCategory" not found`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_14_000001_create_document_catalog_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document catalog: a shelf (`document_categories`) and the kinds on it (`documents`).
 *
 * Unlike M10a's `employee_identification_categories` — fixed by Philippine law, so seeded
 * config — this catalog is ADMIN-EDITABLE at runtime. "Company Policy 2027" is the company's
 * business, not an engineer's, which is the config-vs-database line in 04-backend-conventions.md.
 *
 * `applies_to` / `is_required` / `validity_months` are behaviour, not taxonomy. They live here
 * rather than in a separate "document type" table because a second table with identical
 * columns to `document_categories` would be one concept named twice — see the M10b spec,
 * decision 3.
 *
 * The FK from documents.category_id is deliberately RESTRICT (Laravel's default), not cascade:
 * deleting a shelf must not silently delete every kind on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->constrained('document_categories');

            // 'employee' | 'office' | null (both). Morph aliases, matching config/documents.php
            // — never a FQCN. Plain text with a PHP backed enum on the model, per the M10a
            // precedent: no CHECK constraint, so adding an owner type is not a migration.
            $table->text('applies_to')->nullable();

            $table->boolean('is_required')->default(false);

            // null = never expires. A signed contract does not lapse; an NBI clearance does.
            $table->integer('validity_months')->nullable();

            $table->timestamps();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
```

- [ ] **Step 4: Write the `Documentable` enum**

`backend/app/Domain/Documents/Documentable.php` — the closed set `applies_to` draws from. Lives in `App\Domain` beside `App\Domain\Profile\Gender` and `App\Domain\Attendance\PunchDirection`; there is no `app/Enums/` in this codebase and this task does not create one.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * The owner types a document may apply to. The backed values ARE the morph aliases stored in
 * `document_files.documentable_type` and declared in `config/documents.php` — one spelling,
 * three places, so a rename cannot half-land.
 */
enum Documentable: string
{
    case Employee = 'employee';
    case Office = 'office';
}
```

**After creating this file, add both cases to BOTH `ignoring()` lists in the
`'domain value objects are final'` arch rule in `backend/tests/Arch/ConventionsTest.php`** —
every existing `App\Domain` enum is listed there, and the suite fails without it. Look at how
`App\Domain\Profile\Gender` is listed and follow it exactly.

- [ ] **Step 5: Write the two models**

`backend/app/Models/DocumentCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class DocumentCategory extends Model
{
    /** @use HasFactory<DocumentCategoryFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'description'])
            ->useLogName('document_catalog')
            ->logOnlyDirty();
    }
}
```

`backend/app/Models/Document.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Documents\Documentable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applies_to' => Documentable::class,
            'is_required' => 'boolean',
            'validity_months' => 'integer',
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

    /** @return BelongsTo<DocumentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    /** @return HasMany<DocumentFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'description', 'category_id', 'applies_to', 'is_required', 'validity_months'])
            ->useLogName('document_catalog')
            ->logOnlyDirty();
    }
}
```

> `Document::files()` references `DocumentFile`, which Task 2 creates. Write the relation now
> — PHP resolves the class at call time, not parse time, and no test in THIS task calls it.
> If your editor complains, ignore it; Task 2 lands before anything invokes it.

- [ ] **Step 6: Write the two factories**

`backend/database/factories/DocumentCategoryFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentCategory> */
final class DocumentCategoryFactory extends Factory
{
    protected $model = DocumentCategory::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('CAT_?????')),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
        ];
    }
}
```

`backend/database/factories/DocumentFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('DOC_?????')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'category_id' => DocumentCategory::factory(),
            'applies_to' => null,
            'is_required' => false,
            'validity_months' => null,
        ];
    }
}
```

- [ ] **Step 7: Run the migration and the test**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api php artisan migrate
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCatalogModelTest
```
Expected: PASS — all five cases green.

- [ ] **Step 8: Run the full backend suite**

Run: `cd /home/haru/Desktop/projects/hris && make test-backend`
Expected: PASS — 865 + 5 = 870, zero failures, 20 Arch (the new enum must be in the ignore lists).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Domain/Documents backend/app/Models/Document*.php \
        backend/database/migrations/2026_08_14_000001_* \
        backend/database/factories/Document*.php \
        backend/tests/Arch/ConventionsTest.php \
        backend/tests/Feature/Documents/DocumentCatalogModelTest.php
git commit -m "M10b-a: document catalog tables, models, and the Documentable enum"
```

---

### Task 2: `document_files` and the morph map

**Files:**
- Create: `backend/database/migrations/2026_08_14_000002_create_document_files_table.php`
- Create: `backend/app/Models/DocumentFile.php`
- Create: `backend/database/factories/DocumentFileFactory.php`
- Create: `backend/config/documents.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Feature/Documents/DocumentFileModelTest.php`

**Interfaces:**
- Consumes: `App\Models\Document` (Task 1), `App\Domain\Documents\Documentable` (Task 1).
- Produces: `App\Models\DocumentFile` implementing `Spatie\MediaLibrary\HasMedia` with a `singleFile()` collection named `file` on disk `attachments`; `documentable(): MorphTo`; `document(): BelongsTo`; `uploader(): BelongsTo`. `config('documents.documentable')` returns `['employee' => Employee::class, 'office' => Office::class]`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/DocumentFileModelTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Employee;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// The whole point of the morph map: the DB stores a stable alias, never a class name, so a
// namespace move cannot orphan every row.
it('stores the morph ALIAS in documentable_type, never a class name', function (): void {
    $employee = Employee::factory()->create();

    $file = DocumentFile::query()->create([
        'document_id' => Document::factory()->create()->id,
        'documentable_type' => 'employee',
        'documentable_id' => $employee->id,
        'sha256' => str_repeat('a', 64),
    ]);

    $raw = DB::table('document_files')->where('id', $file->id)->first();

    expect($raw->documentable_type)->toBe('employee')
        ->and($raw->documentable_type)->not->toContain('App\\Models');
});

it('resolves the documentable back to the right model for both owner types', function (): void {
    $employee = Employee::factory()->create();
    $office = Office::factory()->create();

    $forEmployee = DocumentFile::factory()->for($employee, 'documentable')->create();
    $forOffice = DocumentFile::factory()->for($office, 'documentable')->create();

    expect($forEmployee->fresh()->documentable)->toBeInstanceOf(Employee::class)
        ->and($forEmployee->fresh()->documentable->id)->toBe($employee->id)
        ->and($forOffice->fresh()->documentable)->toBeInstanceOf(Office::class)
        ->and($forOffice->fresh()->documentable->id)->toBe($office->id);
});

it('attaches a file to the attachments disk as a single-file collection', function (): void {
    Storage::fake('attachments');

    $file = DocumentFile::factory()->create();

    $file->addMedia(UploadedFile::fake()->createWithContent('contract.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('file');

    expect($file->fresh()->getMedia('file'))->toHaveCount(1)
        ->and($file->fresh()->getFirstMedia('file')->disk)->toBe('attachments');
});

// singleFile() is per ROW. Two files of the same kind for the same employee are two ROWS —
// last year's contract and this year's both survive. See the M10b spec, decision 8.
it('allows many files of the same kind for the same owner', function (): void {
    $employee = Employee::factory()->create();
    $document = Document::factory()->create();

    DocumentFile::factory()->count(3)->for($employee, 'documentable')->create(['document_id' => $document->id]);

    expect(DocumentFile::query()->where('document_id', $document->id)->count())->toBe(3);
});

it('nulls uploaded_by when the uploading user is deleted, keeping the file', function (): void {
    $user = App\Models\User::factory()->create();
    $file = DocumentFile::factory()->create(['uploaded_by' => $user->id]);

    $user->delete();

    expect($file->fresh())->not->toBeNull()
        ->and($file->fresh()->uploaded_by)->toBeNull();
});

it('exposes the documentable whitelist as config', function (): void {
    expect(config('documents.documentable'))
        ->toBe(['employee' => Employee::class, 'office' => Office::class]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentFileModelTest`
Expected: FAIL — `Class "App\Models\DocumentFile" not found`.

- [ ] **Step 3: Write the migration**

`backend/database/migrations/2026_08_14_000002_create_document_files_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One filed document. The FILE itself lives in spatie's `media` table on the RustFS-backed
 * `attachments` disk — this row carries only what medialibrary does not: which kind it is,
 * what it is attached to, who filed it, its hash, and its dates.
 *
 * `documentable_type` stores a MORPH ALIAS ('employee'), never a class name, via the
 * Relation::morphMap() registered in AppServiceProvider from config/documents.php. This is the
 * codebase's first application-owned polymorphic relation; the only other uuidMorphs are
 * vendor-published (Sanctum's tokens, spatie's media).
 *
 * There is deliberately NO unique constraint on (document_id, documentable_type,
 * documentable_id). An employee holds several files of the same kind over time — last year's
 * contract and this year's. Contrast M10a's unique(employee_id, category_id), which exists
 * because one employee has exactly one TIN. See the M10b spec, decision 8.
 *
 * sha256 is integrity, not deduplication: no unique index. The same PDF legitimately attaches
 * to two employees (a shared policy, a counter-signed contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('document_id')->constrained('documents');

            $table->text('documentable_type');
            $table->uuid('documentable_id');

            // Null on delete, not cascade: losing the user must never lose the document.
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('sha256');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index('document_id');
        });

        // Partial index: only rows that can expire are worth scanning for the
        // expiring-soon compliance read (M10b-b).
        DB::statement('create index document_files_expires_on on document_files (expires_on) where expires_on is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
```

- [ ] **Step 4: Write the config**

`backend/config/documents.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;

return [

    /*
    |--------------------------------------------------------------------------
    | Documentable types
    |--------------------------------------------------------------------------
    |
    | Which models may own documents, keyed by the morph alias stored in
    | `document_files.documentable_type`. Config, not database: adding an owner
    | type needs routes, a policy branch, and UI regardless, so it is an
    | engineering act. Which document KINDS apply to which owner is a different
    | question and lives in `documents.applies_to`, which admins edit at runtime.
    |
    | The keys here must match App\Domain\Documents\Documentable's backed values.
    |
    */

    'documentable' => [
        'employee' => Employee::class,
        'office' => Office::class,
    ],

];
```

- [ ] **Step 5: Do NOT register a morph map — amended 2026-08-01 during execution**

> **This step originally said to call `Relation::morphMap(config('documents.documentable'))`.
> That was a spec defect, caught when it broke five existing tests. Do not do it.**
>
> `Relation::morphMap()` is **process-global**. It does not scope to one table — it changes
> `getMorphClass()` for every morph in the application, including **spatie/activitylog**.
> `Employee` and `Office` both use `LogsActivity`; `activity_log.subject_type` holds FQCNs
> today and is exposed via `ActivityResource` and **filtered** via `ListActivityController`.
> Registering the map writes `'office'` on new audit rows while history keeps the FQCN, so the
> audit viewer's filter silently misses half the data in both directions.
>
> The original analysis checked spatie's `media` table and stopped there. Backfilling
> `activity_log` was rejected: rewriting audit history to suit a new module's storage
> preference is what the append-only discipline exists to prevent.

**`document_files.documentable_type` stores the full class name**, exactly as `media.model_type`
and `activity_log.subject_type` already do. This is the third polymorphic table in the schema
and it now behaves like the other two.

In `backend/app/Providers/AppServiceProvider.php`, add **no** morph map. Leave a comment where
one would have gone, so nobody adds it back:

```php
        // Deliberately NO Relation::morphMap() for documentable types. It is process-global
        // and would also rewrite spatie/activitylog's subject_type for Employee and Office —
        // both use LogsActivity, activity_log holds FQCNs today, and that column is exposed
        // and filtered by the M8c audit viewer. document_files stores the class name instead,
        // matching media and activity_log. The wire contract still says 'employee': the
        // resource maps class -> alias from config('documents.documentable').
```

`config/documents.php` keeps its array; its job is the **whitelist** of models that may own
documents (validation, routing, wire serialization), not a morph map. Its docblock must say so.

- [ ] **Step 6: Write the model**

`backend/app/Models/DocumentFile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class DocumentFile extends Model implements HasMedia
{
    /** @use HasFactory<DocumentFileFactory> */
    use HasFactory, HasUuids, InteractsWithMedia, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
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

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function registerMediaCollections(): void
    {
        // Same collection shape and limits as Request's 'attachment' and
        // EmployeeIdentification's 'scan'. singleFile() is per ROW: re-uploading against the
        // same row is a CORRECTION and replaces; a renewal is a new row (spec decision 8).
        $this->addMediaCollection('file')
            ->singleFile()
            ->useDisk('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['document_id', 'documentable_type', 'documentable_id', 'uploaded_by', 'issued_on', 'expires_on'])
            ->useLogName('document_file')
            ->logOnlyDirty();
    }
}
```

- [ ] **Step 7: Write the factory**

`backend/database/factories/DocumentFileFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentFile> */
final class DocumentFileFactory extends Factory
{
    protected $model = DocumentFile::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'documentable_type' => 'employee',
            'documentable_id' => Employee::factory(),
            'uploaded_by' => null,
            'sha256' => hash('sha256', $this->faker->uuid()),
            'issued_on' => null,
            'expires_on' => null,
        ];
    }
}
```

- [ ] **Step 8: Run the migration and the test**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api php artisan migrate
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentFileModelTest
```
Expected: PASS — all six cases green.

- [ ] **Step 9: Prove the morph map did not break existing media**

This is the risk this task carries. Run the two suites that exercise spatie media on the
pre-existing `HasMedia` models:

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=EmployeeIdentificationTest
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=IdentificationEndpointsTest
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=RequestReads
```
Expected: all PASS. If any fails with a morph-map error, you used `enforceMorphMap` — switch to
`morphMap` and re-run.

- [ ] **Step 10: Run the full backend suite**

Run: `cd /home/haru/Desktop/projects/hris && make test-backend`
Expected: PASS — 870 + 6 = 876, zero failures.

- [ ] **Step 11: Commit**

```bash
git add backend/app/Models/DocumentFile.php backend/config/documents.php \
        backend/app/Providers/AppServiceProvider.php \
        backend/database/migrations/2026_08_14_000002_* \
        backend/database/factories/DocumentFileFactory.php \
        backend/tests/Feature/Documents/DocumentFileModelTest.php
git commit -m "M10b-a: document_files and the documentable morph map"
```

---

### Task 3: Permissions and `DocumentPolicy`

**Files:**
- Modify: `backend/database/seeders/RbacSeeder.php`
- Create: `backend/app/Policies/DocumentPolicy.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Feature/Documents/DocumentPolicyTest.php`

**Interfaces:**
- Produces: permissions `document.manage` and `document.manage.self` on the `HR Admin` role; `DocumentPolicy::manageCatalog(User $user): bool`, registered so `$user->can('manageCatalog', Document::class)` resolves.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/DocumentPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

it('catalogues both document permissions on the HR Admin role', function (): void {
    expect(Permission::query()->where('name', 'document.manage')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'document.manage.self')->exists())->toBeTrue();

    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');

    expect($hr->fresh()->can('document.manage'))->toBeTrue()
        ->and($hr->fresh()->can('document.manage.self'))->toBeTrue();
});

it('lets any holder of document.manage edit the catalog, regardless of office', function (): void {
    // The catalog is company-wide — documents and categories have no office_id to scope by,
    // so holding the permission at all IS the check. File-level access is office-scoped and
    // lands in M10b-b.
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');

    expect($hr->fresh()->can('manageCatalog', Document::class))->toBeTrue();
});

it('denies an actor without the permission', function (): void {
    expect(User::factory()->create()->can('manageCatalog', Document::class))->toBeFalse();
});

it('denies an actor holding only the self permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('document.manage.self');

    expect($user->fresh()->can('manageCatalog', Document::class))->toBeFalse();
});

it('grants a system admin through Gate::before', function (): void {
    expect(User::factory()->create(['is_system_admin' => true])->can('manageCatalog', Document::class))->toBeTrue();
});

// Guards the trap RbacSeeder's reserved-words comment describes: spatie registers its own
// Gate::before granting any ability whose NAME matches a held permission. A permission named
// 'manageCatalog' would grant this policy ability globally. The dotted names prevent it.
it('uses dotted permission names that cannot collide with a policy ability', function (): void {
    $abilities = ['manageCatalog'];

    foreach (Permission::query()->pluck('name') as $name) {
        expect($abilities)->not->toContain($name);
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentPolicyTest`
Expected: FAIL — the permissions do not exist and `manageCatalog` is an unknown ability.

- [ ] **Step 3: Add the permissions**

In `backend/database/seeders/RbacSeeder.php`, add both to the `HR_PERMISSIONS` array. Read the file first — it already carries a reserved-words comment about spatie's `Gate::before` name collision; put these beside the existing entries and add a short note that document handling is two-tier:

```php
        // Documents (M10b). Two tiers: `document.manage` is office-scoped at the FILE level
        // (M10b-b) and unscoped for the company-wide catalog; `document.manage.self` lets an
        // employee file and read their OWN documents but never delete one — removing a filed
        // document is HR's act. Both dotted, per the reserved-words note above.
        'document.manage',
        'document.manage.self',
```

- [ ] **Step 4: Write the policy**

`backend/app/Policies/DocumentPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the document CATALOG — the company-wide list of kinds and categories.
 *
 * Deliberately unscoped by office: `documents` and `document_categories` have no office_id,
 * so there is nothing to scope by and holding `document.manage` is the whole check. FILE
 * authorization is a different question — it IS office-scoped, through the hr_admin_offices
 * pivot — and arrives with the file endpoints in M10b-b.
 *
 * System admins never reach here; Gate::before short-circuits first.
 */
final class DocumentPolicy
{
    public function manageCatalog(User $user): bool
    {
        return $user->can('document.manage');
    }
}
```

- [ ] **Step 5: Register the policy**

In `backend/app/Providers/AppServiceProvider.php`'s `boot()`, beside the existing
`Gate::policy(Employee::class, EmployeePolicy::class);`:

```php
        Gate::policy(Document::class, DocumentPolicy::class);
```

Add the two imports. Note `manageCatalog` takes no model instance, so call sites pass the
class name: `$user->can('manageCatalog', Document::class)`.

- [ ] **Step 6: Run the test**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentPolicyTest`
Expected: PASS — all six cases green.

- [ ] **Step 7: Verify the test can fail**

Temporarily change `DocumentPolicy::manageCatalog` to `return true;`. Confirm the two "denies"
cases go red. Revert and confirm `git diff` is clean. Report what you saw.

- [ ] **Step 8: Run the full backend suite**

Run: `cd /home/haru/Desktop/projects/hris && make test-backend`
Expected: PASS — 876 + 6 = 882. Watch for pre-existing RBAC tests that assert an exact
permission count or list; if one breaks, update it to include the two new permissions rather
than loosening the assertion.

- [ ] **Step 9: Commit**

```bash
git add backend/database/seeders/RbacSeeder.php backend/app/Policies/DocumentPolicy.php \
        backend/app/Providers/AppServiceProvider.php \
        backend/tests/Feature/Documents/DocumentPolicyTest.php
git commit -m "M10b-a: document.manage permissions and the catalog policy"
```

---

### Task 4: `DocumentCatalogSeeder` and production bootstrap

**Files:**
- Create: `backend/database/seeders/DocumentCatalogSeeder.php`
- Modify: `backend/app/Console/Commands/BootstrapAdmin.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/Seed/DocumentCatalogSeederTest.php`

**Interfaces:**
- Consumes: `App\Models\DocumentCategory`, `App\Models\Document` (Task 1).
- Produces: `Database\Seeders\DocumentCatalogSeeder`, idempotent on `code`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seed/DocumentCatalogSeederTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentCategory;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the starter catalog with real behavioural values', function (): void {
    $this->seed(DocumentCatalogSeeder::class);

    $nbi = Document::query()->where('code', 'NBI')->firstOrFail();

    expect($nbi->applies_to->value)->toBe('employee')
        ->and($nbi->is_required)->toBeTrue()
        ->and($nbi->validity_months)->toBe(6);

    $permit = Document::query()->where('code', 'BUSINESS_PERMIT')->firstOrFail();

    expect($permit->applies_to->value)->toBe('office')
        ->and($permit->validity_months)->toBe(12);

    $contract = Document::query()->where('code', 'CONTRACT')->firstOrFail();

    // A signed contract does not lapse.
    expect($contract->validity_months)->toBeNull()
        ->and($contract->is_required)->toBeTrue();

    $policy = Document::query()->where('code', 'POLICY')->firstOrFail();

    // Applies to both owner types.
    expect($policy->applies_to)->toBeNull()
        ->and($policy->is_required)->toBeFalse();
});

it('is idempotent — a second run creates nothing and duplicates nothing', function (): void {
    $this->seed(DocumentCatalogSeeder::class);
    $categories = DocumentCategory::query()->count();
    $documents = Document::query()->count();

    $this->seed(DocumentCatalogSeeder::class);

    expect(DocumentCategory::query()->count())->toBe($categories)
        ->and(Document::query()->count())->toBe($documents);
});

// The M10a bug this must not repeat: the seeder call sat AFTER the System-Admin guard, so a
// production database that already had an admin could never gain the catalog.
it('is seeded by hris:bootstrap-admin even when a System Admin already exists', function (): void {
    $this->artisan('hris:bootstrap-admin', ['email' => 'first@example.com'])->assertSuccessful();

    Document::query()->delete();
    DocumentCategory::query()->delete();

    // Second run refuses to mint another admin, but must still seed.
    $this->artisan('hris:bootstrap-admin', ['email' => 'second@example.com'])->assertFailed();

    expect(Document::query()->count())->toBeGreaterThan(0)
        ->and(DocumentCategory::query()->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCatalogSeederTest`
Expected: FAIL — `Class "Database\Seeders\DocumentCatalogSeeder" not found`.

- [ ] **Step 3: Write the seeder**

`backend/database/seeders/DocumentCatalogSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Documents\Documentable;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Seeder;

/**
 * A Philippine starter set for the document catalog.
 *
 * Unlike ProfileCatalogSeeder's identification categories — TIN, SSS and friends are fixed by
 * law and no UI creates them — this catalog is ADMIN-EDITABLE. These rows are a starting
 * point so the module is usable on first boot, not the whole of it.
 *
 * Idempotent throughout (updateOrCreate on `code`), which is what lets hris:bootstrap-admin
 * call it unconditionally.
 */
final class DocumentCatalogSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string}> */
    private const array CATEGORIES = [
        'PRE_EMPLOYMENT' => ['name' => 'Pre-employment', 'description' => 'Collected before an employee starts'],
        'STATUTORY' => ['name' => 'Statutory', 'description' => 'Required by law or a government agency'],
        'PERSONNEL' => ['name' => 'Personnel', 'description' => 'The employee 201 file'],
        'COMPANY' => ['name' => 'Company', 'description' => 'Documents belonging to the company or an office'],
    ];

    /**
     * @var array<int, array{
     *     code: string, name: string, description: string, category: string,
     *     applies_to: ?string, is_required: bool, validity_months: ?int
     * }>
     */
    private const array DOCUMENTS = [
        [
            'code' => 'NBI', 'name' => 'NBI Clearance',
            'description' => 'National Bureau of Investigation clearance',
            'category' => 'PRE_EMPLOYMENT',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => 6,
        ],
        [
            'code' => 'MEDICAL', 'name' => 'Medical Certificate',
            'description' => 'Pre-employment medical examination result',
            'category' => 'PRE_EMPLOYMENT',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => 12,
        ],
        [
            'code' => 'CONTRACT', 'name' => 'Employment Contract',
            'description' => 'Signed contract of employment',
            'category' => 'PERSONNEL',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => null,
        ],
        [
            'code' => 'FILE_201', 'name' => '201 File',
            'description' => 'Miscellaneous personnel file contents',
            'category' => 'PERSONNEL',
            'applies_to' => 'employee', 'is_required' => false, 'validity_months' => null,
        ],
        [
            'code' => 'POLICY', 'name' => 'Company Policy',
            'description' => 'A policy document issued by the company',
            'category' => 'COMPANY',
            'applies_to' => null, 'is_required' => false, 'validity_months' => null,
        ],
        [
            'code' => 'BUSINESS_PERMIT', 'name' => 'Business Permit',
            'description' => 'Mayor\'s permit for the office location',
            'category' => 'COMPANY',
            'applies_to' => 'office', 'is_required' => true, 'validity_months' => 12,
        ],
    ];

    public function run(): void
    {
        $categoryIds = [];

        foreach (self::CATEGORIES as $code => $row) {
            $categoryIds[$code] = DocumentCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $row['name'], 'description' => $row['description']],
            )->id;
        }

        foreach (self::DOCUMENTS as $row) {
            Document::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'category_id' => $categoryIds[$row['category']],
                    'applies_to' => $row['applies_to'] === null ? null : Documentable::from($row['applies_to']),
                    'is_required' => $row['is_required'],
                    'validity_months' => $row['validity_months'],
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Wire it into the bootstrap command**

In `backend/app/Console/Commands/BootstrapAdmin.php`, add
`use Database\Seeders\DocumentCatalogSeeder;` and a third `callSilent` immediately after the
`ProfileCatalogSeeder` line — **above the System-Admin guard**, where the existing two already
sit. Read the surrounding comment first; extend it rather than duplicating it.

```php
        $this->callSilent('db:seed', ['--class' => DocumentCatalogSeeder::class, '--force' => true]);
```

- [ ] **Step 5: Wire it into `DatabaseSeeder`**

Add `DocumentCatalogSeeder::class` to the `$this->call([...])` list, after
`ProfileCatalogSeeder::class` and **before** `CompanySeeder::class`.

- [ ] **Step 6: Run the test**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCatalogSeederTest`
Expected: PASS — all three cases green, including the bootstrap-on-existing-admin case.

- [ ] **Step 7: Run the full backend suite**

Run: `cd /home/haru/Desktop/projects/hris && make test-backend`
Expected: PASS — 882 + 3 = 885. The pre-existing `BootstrapAdminTest` and `CompanySeederTest`
must both still pass.

- [ ] **Step 8: Commit**

```bash
git add backend/database/seeders/ backend/app/Console/Commands/BootstrapAdmin.php \
        backend/tests/Feature/Seed/DocumentCatalogSeederTest.php
git commit -m "M10b-a: document catalog seeder, wired into bootstrap-admin"
```

---

### Task 5: `GET /documents/catalog`

The lightweight read that populates upload dropdowns. Not office-scoped and not admin-gated: static company-wide reference data with nothing sensitive in it, needed by every screen that files a document. Mirrors M10a's `GET /profile/catalog`.

**Files:**
- Create: `backend/app/Http/Resources/DocumentCategoryResource.php`
- Create: `backend/app/Http/Resources/DocumentResource.php`
- Create: `backend/app/Http/Controllers/Documents/ShowCatalogController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Documents/ShowDocumentCatalogTest.php`

**Interfaces:**
- Produces: `GET /api/v1/documents/catalog` →

```jsonc
{ "data": {
  "categories": [ { "id", "code", "name", "description" } ],
  "documents":  [ { "id", "code", "name", "description", "category_id",
                    "applies_to", "is_required", "validity_months" } ]
}}
```

`applies_to` is the backed value (`"employee"` / `"office"`) or `null`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/ShowDocumentCatalogTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns both catalogs to any authenticated user', function (): void {
    $this->seed(DocumentCatalogSeeder::class);

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/documents/catalog')
        ->assertOk()
        ->assertJsonCount(4, 'data.categories')
        ->assertJsonCount(6, 'data.documents');

    $nbi = collect($response->json('data.documents'))->firstWhere('code', 'NBI');

    expect($nbi['applies_to'])->toBe('employee')
        ->and($nbi['is_required'])->toBeTrue()
        ->and($nbi['validity_months'])->toBe(6);

    // Ordered by code, so the response is stable for a client rendering a dropdown.
    expect(collect($response->json('data.categories'))->pluck('code')->all())
        ->toBe(['COMPANY', 'PERSONNEL', 'PRE_EMPLOYMENT', 'STATUTORY']);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/documents/catalog')->assertUnauthorized();
});

it('returns empty lists rather than failing on an unseeded database', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/documents/catalog')
        ->assertOk()
        ->assertJsonPath('data.categories', [])
        ->assertJsonPath('data.documents', []);
});

it('exposes no timestamps', function (): void {
    $this->seed(DocumentCatalogSeeder::class);

    $first = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/documents/catalog')
        ->json('data.documents.0');

    expect($first)->not->toHaveKey('created_at')
        ->and($first)->not->toHaveKey('updated_at');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=ShowDocumentCatalogTest`
Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Write the two resources**

`backend/app/Http/Resources/DocumentCategoryResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DocumentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentCategory */
final class DocumentCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
```

`backend/app/Http/Resources/DocumentResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            // The backed value, not the enum instance — 'employee' / 'office' / null.
            'applies_to' => $this->applies_to?->value,
            'is_required' => $this->is_required,
            'validity_months' => $this->validity_months,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

`backend/app/Http/Controllers/Documents/ShowCatalogController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Resources\DocumentCategoryResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;

/**
 * The document catalog, for populating dropdowns. Deliberately not office-scoped and not
 * admin-gated: static company-wide reference data with nothing sensitive in it, and every
 * screen that files a document needs it to turn a document_id into a name.
 *
 * No Action class — a read with no domain behaviour, the same shape as M10a's profile
 * catalog controller.
 *
 * Ordered by code so a client's dropdown is stable between requests.
 */
final class ShowCatalogController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'categories' => DocumentCategoryResource::collection(
                    DocumentCategory::query()->orderBy('code')->get()
                )->resolve(),
                'documents' => DocumentResource::collection(
                    Document::query()->orderBy('code')->get()
                )->resolve(),
            ],
        ]);
    }
}
```

- [ ] **Step 5: Register the route**

In `backend/routes/api.php`, inside the plain `auth:sanctum` group beside `/profile/catalog`:

```php
        // Static reference data for the document dropdowns — not scoped, not admin-gated,
        // exactly like /profile/catalog above.
        Route::get('/documents/catalog', ShowDocumentCatalogController::class);
```

Import it as
`use App\Http\Controllers\Documents\ShowCatalogController as ShowDocumentCatalogController;`
— `routes/api.php` already aliases several classes named `ShowController`/`ListController`, and
M10a added `App\Http\Controllers\Profile\ShowCatalogController`. Check for the collision before
adding your import.

- [ ] **Step 6: Run the test**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=ShowDocumentCatalogTest`
Expected: PASS — all four cases green.

- [ ] **Step 7: Run the arch tests and the full suite**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch
make test-backend
```
Expected: PASS — 885 + 4 = 889, 20 Arch.

> If the arch guard over `app/Http/Controllers/Profile/` (added in PR #31) is written to cover
> a list of directories rather than one, consider whether `Documents/` should join it. This
> controller is ungated **by design**, so if you extend the guard you must exempt it by name
> the way `Profile/ShowCatalogController` is exempted. Say what you decided in your report.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Resources/Document*.php backend/app/Http/Controllers/Documents \
        backend/routes/api.php backend/tests/Feature/Documents/ShowDocumentCatalogTest.php
git commit -m "M10b-a: the document catalog read"
```

---

### Task 6: Category CRUD

**Files:**
- Create: `backend/app/Exceptions/Domain/DocumentCatalogInUse.php`
- Create: `backend/app/Actions/Documents/{CreateDocumentCategory,UpdateDocumentCategory,DeleteDocumentCategory}{,Input}.php`
- Create: `backend/app/Http/Requests/Documents/{CreateDocumentCategoryRequest,UpdateDocumentCategoryRequest,DeleteDocumentCategoryRequest}.php`
- Create: `backend/app/Http/Controllers/Admin/Documents/Categories/{ListController,CreateController,UpdateController,DeleteController}.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Documents/DocumentCategoryCrudTest.php`

**Interfaces:**
- Consumes: `DocumentPolicy::manageCatalog` (Task 3), `DocumentCategoryResource` (Task 5).
- Produces: `App\Exceptions\Domain\DocumentCatalogInUse` (409, `error.code = 'document_catalog_in_use'`), reused verbatim by Task 7 with a different `subjectType`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/DocumentCategoryCrudTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr = $this->hr->fresh();
});

it('lists categories ordered by code', function (): void {
    DocumentCategory::factory()->create(['code' => 'ZULU']);
    DocumentCategory::factory()->create(['code' => 'ALPHA']);

    $this->actingAs($this->hr)
        ->getJson('/api/v1/admin/document-categories')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'ALPHA')
        ->assertJsonPath('data.1.code', 'ZULU');
});

it('creates a category', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/document-categories', [
            'code' => 'PRE_EMPLOYMENT',
            'name' => 'Pre-employment',
            'description' => 'Collected before day one',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'PRE_EMPLOYMENT');

    expect(DocumentCategory::query()->count())->toBe(1);
});

it('rejects a duplicate code with a validation error, not a 500', function (): void {
    DocumentCategory::factory()->create(['code' => 'STATUTORY']);

    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/document-categories', ['code' => 'STATUTORY', 'name' => 'Again'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('updates a category', function (): void {
    $category = DocumentCategory::factory()->create(['name' => 'Old']);

    $this->actingAs($this->hr)
        ->patchJson("/api/v1/admin/document-categories/{$category->id}", [
            'code' => $category->code,
            'name' => 'New',
            'description' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');
});

it('lets a category keep its own code on update', function (): void {
    $category = DocumentCategory::factory()->create(['code' => 'KEEP']);

    $this->actingAs($this->hr)
        ->patchJson("/api/v1/admin/document-categories/{$category->id}", [
            'code' => 'KEEP',
            'name' => 'Renamed',
        ])
        ->assertOk();
});

it('deletes an empty category', function (): void {
    $category = DocumentCategory::factory()->create();

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/document-categories/{$category->id}")
        ->assertOk();

    expect(DocumentCategory::query()->count())->toBe(0);
});

// Losing a document kind because someone tidied the catalog is not an acceptable failure.
it('refuses to delete a category that still has documents', function (): void {
    $category = DocumentCategory::factory()->create();
    Document::factory()->create(['category_id' => $category->id]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/document-categories/{$category->id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'document_catalog_in_use');

    expect(DocumentCategory::query()->count())->toBe(1);
});

it('denies every route to an actor without document.manage', function (): void {
    $stranger = User::factory()->create();
    $category = DocumentCategory::factory()->create();

    $this->actingAs($stranger)->getJson('/api/v1/admin/document-categories')->assertForbidden();
    $this->actingAs($stranger)->postJson('/api/v1/admin/document-categories', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($stranger)->patchJson("/api/v1/admin/document-categories/{$category->id}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/api/v1/admin/document-categories/{$category->id}")->assertForbidden();
});
```

> **Note the deliberate 403 here, not 404.** The catalog is company-wide reference data with no
> owner id in the URL, so there is nothing to enumerate — this is the same actor-check shape as
> `/admin/pay-rules` and `/admin/organizations`, which return the default `403`. The
> 404-not-403 rule applies where an owner id in the URL could otherwise be probed; it does not
> apply here. Do not "fix" these to 404.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCategoryCrudTest`
Expected: FAIL — routes do not exist.

- [ ] **Step 3: Write the domain exception**

`backend/app/Exceptions/Domain/DocumentCatalogInUse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a catalog row is deleted while something still points at it — a category with
 * documents, or a document with filed files.
 *
 * Generic across both, like AlreadyArchived: the subject type travels as a string rather than
 * a class-per-subject exception, because the error shape is identical either way.
 *
 * A refusal, not a cascade. Losing a signed contract because someone tidied the catalog is
 * not an acceptable failure mode.
 */
final class DocumentCatalogInUse extends DomainException
{
    public function __construct(
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly int $dependents,
    ) {
        parent::__construct('This catalog entry is still in use and cannot be deleted.');
    }

    public function errorCode(): string
    {
        return 'document_catalog_in_use';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dependents' => $this->dependents,
        ];
    }
}
```

- [ ] **Step 4: Write the three actions and their Inputs**

Follow the house shape exactly — `final`, an Input DTO, returns a domain object, no HTTP.

`CreateDocumentCategoryInput`: `public string $code, public string $name, public ?string $description`.
`UpdateDocumentCategoryInput`: `public string $categoryId, public string $code, public string $name, public ?string $description`.
`DeleteDocumentCategoryInput`: `public string $categoryId`.

`CreateDocumentCategory::execute(...): DocumentCategory` — `DB::transaction`, `DocumentCategory::query()->create([...])`.

`UpdateDocumentCategory::execute(...): DocumentCategory` — `findOrFail`, `fill([...])->save()`, return it.

`DeleteDocumentCategory::execute(...): void`:

```php
    public function execute(DeleteDocumentCategoryInput $in): void
    {
        DB::transaction(function () use ($in): void {
            $category = DocumentCategory::query()->findOrFail($in->categoryId);

            // Refuse, never cascade. The DB's RESTRICT FK is the backstop; this check exists
            // so the caller gets a 409 with a count rather than a raw QueryException 500.
            $dependents = $category->documents()->count();

            if ($dependents > 0) {
                throw new DocumentCatalogInUse('document_category', $category->id, $dependents);
            }

            $category->delete();
        });
    }
```

- [ ] **Step 5: Write the three FormRequests**

All three: `authorize()` returns `$this->user()?->can('manageCatalog', Document::class) === true`.
**Do NOT override `failedAuthorization()`** — the default 403 is correct here (see the note in
Step 1).

`CreateDocumentCategoryRequest::rules()`:

```php
            'code' => ['required', 'string', 'max:64', 'unique:document_categories,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
```

`UpdateDocumentCategoryRequest::rules()` — the `unique` rule must ignore the row being edited,
or renaming a category to its own code fails:

```php
        $categoryId = $this->route('category')?->id;

        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('document_categories', 'code')->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
        ];
```

`DeleteDocumentCategoryRequest::rules()` returns `[]`.

- [ ] **Step 6: Write the four controllers**

`ListController` is a controller-only read (no Action): `DocumentCategoryResource::collection(DocumentCategory::query()->orderBy('code')->get())->response()`. It still needs a FormRequest (or an inline gate call) for the `manageCatalog` check — use a `ListDocumentCategoriesRequest` with the same `authorize()` and empty `rules()`, so the authorization shape is identical across all four.

`CreateController` returns `DocumentCategoryResource::make($category)->response()->setStatusCode(Response::HTTP_CREATED)`.

`UpdateController` returns `DocumentCategoryResource::make($updated)->response()`.

`DeleteController` calls the action and returns the remaining list, so the client's cache
updates in one round trip:
`DocumentCategoryResource::collection(DocumentCategory::query()->orderBy('code')->get())->response()`.

Use `input()`, not `string()`, for `description` — `has()` is true for an explicit JSON null and
`string()` would coerce it to `''`, silently turning "no description" into an empty string. The
`CreateLeaveTypeController` carries a comment explaining exactly this; read it.

- [ ] **Step 7: Register the four routes**

In `backend/routes/api.php`, inside the `admin` prefix group:

```php
            // The document catalog (M10b-a). Unlike most of this group these are NOT
            // is_system_admin-gated: each FormRequest checks `manageCatalog`, so any HR Admin
            // may edit the catalog. It is company-wide reference data with no office to scope
            // by, which is why the denial is a plain 403 rather than the 404-not-403 shape
            // used where an owner id sits in the URL.
            Route::get('/document-categories', ListDocumentCategoriesController::class);
            Route::post('/document-categories', CreateDocumentCategoryController::class);
            Route::patch('/document-categories/{category}', UpdateDocumentCategoryController::class);
            Route::delete('/document-categories/{category}', DeleteDocumentCategoryController::class);
```

Route-model-bind `{category}` to `DocumentCategory`. Laravel resolves `{category}` to that
model by convention only if the type-hint says so — put `DocumentCategory $category` in the
controller signature.

**The four names above are import aliases, not class names.** `routes/api.php` already aliases
several `ListController`/`CreateController`/`UpdateController`/`DeleteController` imports, so
yours must be aliased too or they collide. Add:

```php
use App\Http\Controllers\Admin\Documents\Categories\CreateController as CreateDocumentCategoryController;
use App\Http\Controllers\Admin\Documents\Categories\DeleteController as DeleteDocumentCategoryController;
use App\Http\Controllers\Admin\Documents\Categories\ListController as ListDocumentCategoriesController;
use App\Http\Controllers\Admin\Documents\Categories\UpdateController as UpdateDocumentCategoryController;
```

Grep the file for each alias before adding it — M10a added several and a duplicate alias is a
fatal, not a warning.

- [ ] **Step 8: Run the test, the arch tests, and the full suite**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCategoryCrudTest
docker compose -f compose.dev.yml exec -T --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch
make test-backend
```
Expected: PASS — 889 + 8 = 897, 20 Arch.

- [ ] **Step 9: Verify the in-use guard can fail**

Delete the `$dependents > 0` throw from `DeleteDocumentCategory`. Confirm
`it('refuses to delete a category that still has documents')` goes red — it should now surface
as a raw `QueryException` 500 from the RESTRICT FK rather than a clean 409, which is exactly
why the application-level check exists. Revert, confirm `git diff` is clean, report what you saw.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Exceptions/Domain/DocumentCatalogInUse.php \
        backend/app/Actions/Documents backend/app/Http/Requests/Documents \
        backend/app/Http/Controllers/Admin/Documents backend/routes/api.php \
        backend/tests/Feature/Documents/DocumentCategoryCrudTest.php
git commit -m "M10b-a: document category CRUD with an in-use delete guard"
```

---

### Task 7: Document CRUD

Same shape as Task 6, one level down. The extra weight is `applies_to` / `is_required` / `validity_months`.

**Files:**
- Create: `backend/app/Actions/Documents/{CreateDocument,UpdateDocument,DeleteDocument}{,Input}.php`
- Create: `backend/app/Http/Requests/Documents/{List,Create,Update,Delete}DocumentRequest.php`
- Create: `backend/app/Http/Controllers/Admin/Documents/Kinds/{ListController,CreateController,UpdateController,DeleteController}.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Documents/DocumentCrudTest.php`

**Interfaces:**
- Consumes: `DocumentCatalogInUse` (Task 6), `DocumentPolicy::manageCatalog` (Task 3), `DocumentResource` (Task 5), `App\Domain\Documents\Documentable` (Task 1).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Documents/DocumentCrudTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentFile;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr = $this->hr->fresh();

    $this->category = DocumentCategory::factory()->create();
});

it('creates a document with its behavioural fields', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'NBI',
            'name' => 'NBI Clearance',
            'description' => 'NBI clearance',
            'category_id' => $this->category->id,
            'applies_to' => 'employee',
            'is_required' => true,
            'validity_months' => 6,
        ])
        ->assertCreated()
        ->assertJsonPath('data.applies_to', 'employee')
        ->assertJsonPath('data.is_required', true)
        ->assertJsonPath('data.validity_months', 6);
});

it('defaults applies_to to null, is_required to false, and validity to never', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'POLICY',
            'name' => 'Company Policy',
            'category_id' => $this->category->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.applies_to', null)
        ->assertJsonPath('data.is_required', false)
        ->assertJsonPath('data.validity_months', null);
});

// Rule::enum matches the backed value exactly. A capitalised option is a 400, not a silent coerce.
it('rejects an applies_to outside the closed set', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'X', 'name' => 'X', 'category_id' => $this->category->id,
            'applies_to' => 'Employee',
        ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'Y', 'name' => 'Y', 'category_id' => $this->category->id,
            'applies_to' => 'department',
        ])
        ->assertStatus(400);
});

it('rejects a negative or zero validity', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'X', 'name' => 'X', 'category_id' => $this->category->id,
            'validity_months' => 0,
        ])
        ->assertStatus(400);
});

it('rejects an unknown category', function (): void {
    $this->actingAs($this->hr)
        ->postJson('/api/v1/admin/documents', [
            'code' => 'X', 'name' => 'X',
            'category_id' => '0199a000-0000-7000-8000-000000000000',
        ])
        ->assertStatus(400);
});

it('updates a document and lets it keep its own code', function (): void {
    $document = Document::factory()->create(['code' => 'KEEP', 'category_id' => $this->category->id]);

    $this->actingAs($this->hr)
        ->patchJson("/api/v1/admin/documents/{$document->id}", [
            'code' => 'KEEP',
            'name' => 'Renamed',
            'category_id' => $this->category->id,
            'is_required' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.is_required', true);
});

it('deletes a document with no files', function (): void {
    $document = Document::factory()->create(['category_id' => $this->category->id]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/documents/{$document->id}")
        ->assertOk();

    expect(Document::query()->count())->toBe(0);
});

// The one that protects filed paper from a catalog tidy-up.
it('refuses to delete a document that has filed files', function (): void {
    $document = Document::factory()->create(['category_id' => $this->category->id]);
    DocumentFile::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/documents/{$document->id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'document_catalog_in_use')
        ->assertJsonPath('error.details.dependents', 1);

    expect(Document::query()->count())->toBe(1);
});

it('denies every route to an actor without document.manage', function (): void {
    $stranger = User::factory()->create();
    $document = Document::factory()->create(['category_id' => $this->category->id]);

    $this->actingAs($stranger)->getJson('/api/v1/admin/documents')->assertForbidden();
    $this->actingAs($stranger)->postJson('/api/v1/admin/documents', ['code' => 'X', 'name' => 'X', 'category_id' => $this->category->id])->assertForbidden();
    $this->actingAs($stranger)->patchJson("/api/v1/admin/documents/{$document->id}", ['code' => 'X', 'name' => 'X', 'category_id' => $this->category->id])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/api/v1/admin/documents/{$document->id}")->assertForbidden();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCrudTest`
Expected: FAIL — routes do not exist.

- [ ] **Step 3: Write the actions**

`CreateDocumentInput`: `public string $code, public string $name, public ?string $description, public string $categoryId, public ?string $appliesTo, public bool $isRequired, public ?int $validityMonths`.

`UpdateDocumentInput`: the same plus `public string $documentId` first.

`DeleteDocumentInput`: `public string $documentId`.

`DeleteDocument::execute(...): void` mirrors Task 6's category delete, counting `files()`
instead of `documents()` and throwing `new DocumentCatalogInUse('document', $document->id, $dependents)`.

- [ ] **Step 4: Write the FormRequests**

`authorize()` on all four: `$this->user()?->can('manageCatalog', Document::class) === true`. No
`failedAuthorization()` override — the default 403 is correct.

`CreateDocumentRequest::rules()`:

```php
            'code' => ['required', 'string', 'max:64', 'unique:documents,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            'category_id' => ['required', 'uuid', 'exists:document_categories,id'],
            'applies_to' => ['nullable', Rule::enum(Documentable::class)],
            'is_required' => ['sometimes', 'boolean'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
```

> `exists:` is correct here, unlike `office_id` in `CreateLeaveTypeRequest`. That rule omits
> `exists` because an out-of-scope office must 404 in the controller, and an `exists` failure
> would 400 instead — an enumeration oracle. Categories are company-wide reference data
> readable by any authenticated user via `/documents/catalog`, so there is nothing to
> enumerate and `exists` is the right validation.

`UpdateDocumentRequest` is identical but with `Rule::unique('documents','code')->ignore($this->route('document')?->id)`.

`validity_months` min is **1**, not 0: a zero-month validity means "expired on issue", which is
never what anyone means, and `null` already expresses "never expires".

- [ ] **Step 5: Write the controllers and register the routes**

Same four-controller shape as Task 6. Use `input()` for the nullable fields, and
`$request->has('is_required') ? $request->boolean('is_required') : false` so an omitted flag
defaults false rather than being coerced from a missing key.

```php
            Route::get('/documents', ListDocumentsController::class);
            Route::post('/documents', CreateDocumentController::class);
            Route::patch('/documents/{document}', UpdateDocumentController::class);
            Route::delete('/documents/{document}', DeleteDocumentController::class);
```

Alias these imports too, the same way Task 6 does, and grep before adding:

```php
use App\Http\Controllers\Admin\Documents\Kinds\CreateController as CreateDocumentController;
use App\Http\Controllers\Admin\Documents\Kinds\DeleteController as DeleteDocumentController;
use App\Http\Controllers\Admin\Documents\Kinds\ListController as ListDocumentsController;
use App\Http\Controllers\Admin\Documents\Kinds\UpdateController as UpdateDocumentController;
```

> **Register these AFTER any literal `/documents/...` segment you may add later.** M10b-b adds
> `GET /admin/documents/expiring` and `/missing`; if a parameterised `GET /admin/documents/{document}`
> ever exists, the literal routes must be registered first or `expiring` binds as a `{document}`
> id and 404s. This task adds no show route, so there is no collision yet — leave a comment
> saying so.

- [ ] **Step 6: Run the test, arch, and full suite**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCrudTest
docker compose -f compose.dev.yml exec -T --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch
make test-backend
```
Expected: PASS — 897 + 9 = 906, 20 Arch.

- [ ] **Step 7: Verify two tests can fail**

Drop the `Rule::enum` from `applies_to` and confirm the closed-set test goes red. Drop the
in-use throw from `DeleteDocument` and confirm the filed-files test goes red. Revert both,
confirm `git diff` is clean, report what you saw.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Actions/Documents backend/app/Http/Requests/Documents \
        backend/app/Http/Controllers/Admin/Documents backend/routes/api.php \
        backend/tests/Feature/Documents/DocumentCrudTest.php
git commit -m "M10b-a: document kind CRUD"
```

---

### Task 8: The consolidated authorization matrix

Tasks 6 and 7 each tested their own routes. This adds the one table-driven test a reviewer opens to answer "who can touch the catalog", and the one that goes red when a tenth route is added without a gate.

**Files:**
- Create: `backend/tests/Feature/Documents/DocumentCatalogScopeMatrixTest.php`

- [ ] **Step 1: Write the matrix**

Four actors × nine routes. Actors: `hr-admin` (holds `document.manage`), `self-only` (holds
only `document.manage.self`), `stranger` (no permissions), `system-admin`.

Routes: the nine from Tasks 5–7 (`GET /documents/catalog` plus the eight admin CRUD routes).

Expectations:

| Actor | `/documents/catalog` | the 8 admin routes |
| --- | --- | --- |
| hr-admin | allowed | allowed |
| self-only | allowed | **denied (403)** |
| stranger | allowed | denied (403) |
| system-admin | allowed | allowed |

`GET /documents/catalog` is allowed for everyone authenticated — it is ungated reference data by
design, and that row documents the decision rather than hiding it.

Build it as a `$expected` array keyed by actor then route name, loop, collect failures into an
array, and assert `expect($failures)->toBe([])`. **The failure message must name the actor, the
route, and the status** — a matrix whose failure says only "expected [] to be []" is nearly
useless to whoever hits it. Follow `tests/Feature/Profile/ProfileScopeMatrixTest.php`, which
does exactly this, and reuse its structure.

Assert the denied status is **403 specifically**, not merely non-2xx. A 404 here would mean
someone applied the 404-not-403 rule where it does not belong, and the matrix should catch that
drift in either direction.

Recreate any row a DELETE cell destroys before the next iteration — `ProfileScopeMatrixTest`
does this by deleting and recreating its fixture per cell, and its report explains why
`refreshApplication()` deadlocks under `RefreshDatabase`. Do not use `refreshApplication()`.

- [ ] **Step 2: Run it**

Run: `cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user hris api ./vendor/bin/pest --filter=DocumentCatalogScopeMatrixTest`
Expected: PASS.

- [ ] **Step 3: Verify the matrix can fail, and names the cell**

Flip `'self-only' => 'POST /admin/documents'` from denied to allowed. Re-run and confirm the
failure names that exact cell. Flip it back and confirm green. Report the exact message you saw.

Then flip a second, different cell and confirm it too is named — a matrix that only ever
reports its first failure is half a matrix.

- [ ] **Step 4: Run the full suite and commit**

```bash
cd /home/haru/Desktop/projects/hris && make test-backend
git add backend/tests/Feature/Documents/DocumentCatalogScopeMatrixTest.php
git commit -m "M10b-a: four-actor scope matrix across the catalog routes"
```
Expected: 906 + 1 = 907.

---

### Task 9: Frontend API client and query keys

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Modify: `frontend/web/src/lib/keys.ts`
- Test: `frontend/web/src/lib/api.test.ts` (extend)

**Interfaces:**
- Consumes: the JSON contract from Tasks 5–7. **Read the shipped resources, not this plan, if they disagree** — `backend/app/Http/Resources/DocumentResource.php` and `DocumentCategoryResource.php` are authoritative.
- Produces:
  - `DocumentCategory`, `DocumentKind`, `DocumentCatalog`, `DocumentCategoryWrite`, `DocumentKindWrite` types
  - `api.documents.catalog()`, `.listCategories()`, `.createCategory()`, `.updateCategory()`, `.deleteCategory()`, `.listKinds()`, `.createKind()`, `.updateKind()`, `.deleteKind()`
  - `keys.documents.catalog()`, `keys.documents.adminCategories()`, `keys.documents.adminKinds()`

- [ ] **Step 1: Add the types**

```ts
export type DocumentCategory = {
  id: string
  code: string
  name: string
  description: string | null
}

/** A document KIND. Named DocumentKind, not Document, because `Document` is a DOM global —
 *  shadowing it in a browser bundle is a real footgun. */
export type DocumentKind = {
  id: string
  code: string
  name: string
  description: string | null
  category_id: string
  applies_to: 'employee' | 'office' | null
  is_required: boolean
  validity_months: number | null
}

export type DocumentCatalog = {
  categories: DocumentCategory[]
  documents: DocumentKind[]
}

export type DocumentCategoryWrite = {
  code: string
  name: string
  description?: string | null
}

export type DocumentKindWrite = {
  code: string
  name: string
  description?: string | null
  category_id: string
  applies_to?: 'employee' | 'office' | null
  is_required?: boolean
  validity_months?: number | null
}
```

- [ ] **Step 2: Add the client methods**

Beside `api.profile`, following its exact shape (JSON bodies set `Content-Type`; `request()`
already adds `Accept` and `Authorization`):

```ts
  documents: {
    catalog: () => request<DocumentCatalog>('/documents/catalog'),

    listCategories: () => request<DocumentCategory[]>('/admin/document-categories'),
    createCategory: (body: DocumentCategoryWrite) =>
      request<DocumentCategory>('/admin/document-categories', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    updateCategory: (id: string, body: DocumentCategoryWrite) =>
      request<DocumentCategory>(`/admin/document-categories/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    deleteCategory: (id: string) =>
      request<DocumentCategory[]>(`/admin/document-categories/${id}`, { method: 'DELETE' }),

    listKinds: () => request<DocumentKind[]>('/admin/documents'),
    createKind: (body: DocumentKindWrite) =>
      request<DocumentKind>('/admin/documents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    updateKind: (id: string, body: DocumentKindWrite) =>
      request<DocumentKind>(`/admin/documents/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
    deleteKind: (id: string) =>
      request<DocumentKind[]>(`/admin/documents/${id}`, { method: 'DELETE' }),
  },
```

Note `deleteCategory` / `deleteKind` return the **remaining list**, matching what the delete
controllers return, so the client updates its cache in one round trip.

- [ ] **Step 3: Add the query keys**

In `frontend/web/src/lib/keys.ts`:

```ts
  // The document catalog (M10b-a). `catalog` is the ungated dropdown read and carries a long
  // staleTime — nothing writes it except the admin screens below, which invalidate this key
  // explicitly. The two admin keys are separate because they back different screens.
  documents: {
    catalog: () => ['documents', 'catalog'] as const,
    adminCategories: () => ['documents', 'admin', 'categories'] as const,
    adminKinds: () => ['documents', 'admin', 'kinds'] as const,
  },
```

- [ ] **Step 4: Add a contract test**

Extend `frontend/web/src/lib/api.test.ts` with a case asserting `api.documents.createKind`
sends **snake_case** field names — `category_id`, `applies_to`, `is_required`,
`validity_months`. A camelCase slip here is a silent 400 that no typecheck catches, because
the request body is serialized as a plain object. Follow the existing tests in that file for
the fetch-mocking shape.

- [ ] **Step 5: Verify the test can fail**

Rename one field to camelCase in `api.ts`, confirm the new test goes red, revert, confirm
`git diff` is clean. Report what you saw.

- [ ] **Step 6: Run the frontend checks**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user node web sh -c 'npx vitest run --maxWorkers=4 --testTimeout=20000'
docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm run typecheck && npm run lint && npm run build'
```
Expected: PASS — 577 + your new cases, zero failures; typecheck, lint, and build clean.

- [ ] **Step 7: Commit**

```bash
git add frontend/web/src/lib/api.ts frontend/web/src/lib/keys.ts frontend/web/src/lib/api.test.ts
git commit -m "M10b-a: document catalog API client and query keys"
```

---

### Task 10: The catalog admin screen

**Files:**
- Create: `frontend/web/src/hooks/useDocumentCatalog.ts`
- Create: `frontend/web/src/hooks/useSaveDocumentCatalog.ts`
- Create: `frontend/web/src/app/(app)/admin/documents/page.tsx`
- Create: `frontend/web/src/app/(app)/admin/documents/documents.test.tsx`
- Modify: `frontend/web/src/components/SideNav.tsx`

**Interfaces:**
- Consumes: `api.documents.*` and `keys.documents.*` (Task 9).
- Produces: `useDocumentCatalog()` → `UseQueryResult<DocumentCatalog>`; `useSaveDocumentCatalog()` exposing six mutations, each invalidating all three document keys.

- [ ] **Step 1: Read the real components first**

The brief cannot know their props and has been wrong every previous time. Read and match:
`src/components/ui/{Button,TextInput,Select,InlineNotification,Skeleton}.tsx`,
`src/components/{SectionHeader,EmptyState,SideNav}.tsx`. **Adapt your call sites to them; do
not modify those components.**

Mirror an existing admin CRUD screen — `src/app/(app)/admin/offices/page.tsx` is the closest —
for the list + inline-form shape this codebase already uses.

- [ ] **Step 2: Write the hooks**

`useDocumentCatalog.ts` — one query on `keys.documents.catalog()` calling `api.documents.catalog()`, `staleTime: 60 * 60 * 1000` (nothing writes it but the admin screen, which invalidates explicitly).

`useSaveDocumentCatalog.ts` — six `useMutation`s (category create/update/delete, kind create/update/delete). Every one invalidates all three keys on success:

```ts
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.documents.catalog() })
      void queryClient.invalidateQueries({ queryKey: keys.documents.adminCategories() })
      void queryClient.invalidateQueries({ queryKey: keys.documents.adminKinds() })
    },
```

Editing a category's name changes what the dropdown shows, so the catalog key must go too —
that is why all three, not just the one that was written.

- [ ] **Step 3: Write the page**

`/admin/documents` — two sections: Categories, and Document kinds. Each lists its rows with
inline create/edit, and a delete control. The kind form needs:

- `code`, `name`, `description` — `TextInput`
- `category_id` — the design-system `Select`, options from the categories list
- `applies_to` — the design-system `Select`, options **exactly** `[{value:'',label:'Both'}, {value:'employee',label:'Employee'}, {value:'office',label:'Office'}]`. The empty string maps to `null` on submit. `Rule::enum` matches backed values exactly, so `'Employee'` would be a 400.
- `is_required` — a checkbox
- `validity_months` — a number input, empty meaning "never expires" (send `null`, not `0`)

Export the `applies_to` options array so the test can assert its values without DOM
introspection — Radix `Select.Item` renders no `<option>` nodes, and a DOM-based assertion is
unsatisfiable by this primitive. That exact mistake cost a fix round in M10a.

**Surface the 409.** Deleting a category or kind that is in use returns
`error.code = 'document_catalog_in_use'` with `error.details.dependents`. Render a specific
message naming the count — "3 documents still use this category" — not the generic failure
text. This is the one error in the milestone a user will actually hit.

- [ ] **Step 4: Write the tests**

`documents.test.tsx` covering: the two lists render from the catalog; creating a kind sends
exact snake_case field names with `applies_to: null` for "Both"; the `applies_to` options are
exactly `['', 'employee', 'office']`; an empty `validity_months` submits `null` not `0`; and a
409 on delete renders the dependents count.

Mock `@/lib/api` and render through `<Providers>` with a `next/navigation` mock, as
`src/app/(app)/me/profile/profile.test.tsx` does — pages under `(app)/` need `AppShell`, which
needs router and session context.

- [ ] **Step 5: Add the nav entry**

Add a `Documents` link to `/admin/documents` in `SideNav.tsx`'s admin group, matching the shape
of the entries beside it. **If `SideNav.test.tsx` asserts an exact link array or a count, add
your entry to the expectation** — do not loosen the assertion to `toContain`.

- [ ] **Step 6: Verify two tests can fail**

Capitalise one `applies_to` option value (`'employee'` → `'Employee'`) and confirm the options
test goes red. Make an empty `validity_months` submit `0` instead of `null` and confirm that
test goes red. Revert both, confirm `git diff` is clean, report what you saw.

- [ ] **Step 7: Run the frontend checks**

```bash
cd /home/haru/Desktop/projects/hris
docker compose -f compose.dev.yml exec -T --user node web sh -c 'npx vitest run --maxWorkers=4 --testTimeout=20000'
docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm run typecheck && npm run lint && npm run build'
```
Expected: PASS, all three clean. Confirm `/admin/documents` appears in the build's route table.

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/hooks/useDocumentCatalog.ts frontend/web/src/hooks/useSaveDocumentCatalog.ts \
        "frontend/web/src/app/(app)/admin/documents" frontend/web/src/components/SideNav.tsx \
        frontend/web/src/components/SideNav.test.tsx
git commit -m "M10b-a: the document catalog admin screen"
```

---

### Task 11: Documentation

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `docs/features.md`, `CLAUDE.md`

- [ ] **Step 1: `docs/02-data-model.md`**

An "Document management (M10b-a)" section: the three tables' DDL, and the reasoning — spatie's
`media` stays the file layer; `DocumentBucket` was dropped because its three readings could not
be told apart; `type_id` and `DocumentCategory` were one concept named twice; **`expires_on` is
stored, not derived, so a later change to `validity_months` cannot rewrite what a filed
certificate was worth**; and the absence of a unique constraint on
`(document_id, documentable)` is deliberate.

Also record the morph-map decision: `Relation::morphMap()` and **not** `enforceMorphMap()`,
because `media.model_type` already holds FQCNs from M3.6 and M10a and enforcing would require
backfilling every existing media row. This is the codebase's first application-owned
polymorphic relation.

- [ ] **Step 2: `docs/03-api.md`**

Document the nine M10b-a routes with request/response shapes. Record two non-obvious contracts:
the catalog read is **ungated by design**, and the catalog CRUD denials are **403, not 404** —
the 404-not-403 rule applies where an owner id in the URL could be probed, and company-wide
reference data has none.

Note that `GET /admin/documents/expiring` and `/missing` are M10b-b and must be registered
before any parameterised `GET /admin/documents/{document}`.

- [ ] **Step 3: `docs/05-rbac.md`**

Add `document.manage` and `document.manage.self` to the permission table. State that the
catalog check is **unscoped** (company-wide data, no office to scope by) while file access in
M10b-b **is** office-scoped, and that `document.manage.self` permits upload and read but never
delete.

Reinforce the reserved-words note: both names are dotted because spatie's `Gate::before` grants
any ability whose name matches a held permission.

- [ ] **Step 4: `docs/06-roadmap.md`**

An M10b-a status block: what shipped, what M10b-b still owes (file upload/list/download/delete
for both owner types, the two Documents sections, the compliance view), and the gotchas found
while building.

- [ ] **Step 5: `docs/features.md`**

What a user can now do: an HR Admin defines the document kinds the company files, grouped into
categories, each marked as applying to employees or offices, required or not, and expiring
after N months or never.

- [ ] **Step 6: `CLAUDE.md`**

Update the Status test counts (measure them, see Step 7). Add to "Gotchas that will cost you an
afternoon":

```markdown
- **`Relation::morphMap()` is process-global, and it reaches spatie/activitylog, not just
  spatie/medialibrary.** M10b tried to register one so `document_files.documentable_type` would
  store `'employee'` rather than `App\Models\Employee`. Because `Employee` and `Office` both use
  `LogsActivity`, that also changed `activity_log.subject_type` for every *new* audit row while
  history kept the FQCN — and that column is exposed by `ActivityResource` and filtered by
  `ListActivityController`, so the M8c audit viewer silently missed half its data in both
  directions. Five tests caught it. There is now **no morph map**: all three polymorphic tables
  (`media`, `activity_log`, `document_files`) store full class names, and the stable
  `'employee'` alias is applied at the resource layer instead. Before adding a morph map for
  anything, enumerate every package that morphs — medialibrary and activitylog both do.
```

- [ ] **Step 7: Measure the numbers — do not estimate**

```bash
make test-backend
cd /home/haru/Desktop/projects/hris && docker compose -f compose.dev.yml exec -T --user node web \
  sh -c 'npx vitest run --maxWorkers=4 --testTimeout=20000'
```

Copy the real figures into `CLAUDE.md`'s Status section, and check whether the Arch count moved.
Then grep `docs/` and `CLAUDE.md` for any now-stale count and confirm each remaining occurrence
is a historical per-milestone checkpoint, not a present-tense claim.

- [ ] **Step 8: Commit**

```bash
git add docs/ CLAUDE.md
git commit -m "M10b-a: docs — data model, API, RBAC, roadmap, features"
```

---

## Done when

- `make test-backend` and the containerized vitest run are both green, with the counts recorded in `CLAUDE.md`.
- `docker compose -f compose.dev.yml exec -T --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch` is green — 20 arch rules, none relaxed.
- A fresh database bootstrapped with `php artisan hris:bootstrap-admin` has the document catalog, **and so does one that already had a System Admin**.
- `/admin/documents` renders, creates a kind with `applies_to: null`, and surfaces the 409 with its dependents count.
- `docs/03-api.md` documents all nine routes.
- **`document_files` exists and is empty.** No endpoint writes it; that is M10b-b.
