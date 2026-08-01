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

// Deliberately NO Relation::morphMap() for this relation. Employee and Office are already
// global morph subjects via LogsActivity's activity_log.subject_type/causer_type, and
// aliasing either model here would silently change what new activity_log rows are logged
// under while historical rows keep the FQCN — breaking the audit viewer's wire shape and its
// subject_type filter. So documentable_type stores the full class name, exactly like
// media.model_type and activity_log.subject_type already do; the alias ('employee') is a
// wire-layer-only concern handled by config/documents.php and the later DocumentFileResource.
it('stores the full class name in documentable_type, matching media and activity_log', function (): void {
    $employee = Employee::factory()->create();

    $file = DocumentFile::factory()->create([
        'documentable_id' => $employee->id,
    ]);

    $raw = DB::table('document_files')->where('id', $file->id)->first();

    expect($raw->documentable_type)->toBe(Employee::class)
        ->and($file->fresh()->documentable)->toBeInstanceOf(Employee::class)
        ->and($file->fresh()->documentable->id)->toBe($employee->id);
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
