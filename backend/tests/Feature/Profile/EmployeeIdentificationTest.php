<?php

declare(strict_types=1);

use App\Actions\Profile\SaveEmployeeIdentification;
use App\Actions\Profile\SaveEmployeeIdentificationInput;
use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

uses(RefreshDatabase::class);

it('stores one identification per employee per category', function (): void {
    $employee = Employee::factory()->create();
    $tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);

    $id = EmployeeIdentification::query()->create([
        'employee_id' => $employee->id,
        'category_id' => $tin->id,
        'number' => '653536955000',
        'issued_on' => '2020-03-01',
    ]);

    expect($id->number)->toBe('653536955000')
        ->and($id->category->code)->toBe('TIN')
        ->and($employee->fresh()->identifications)->toHaveCount(1);
});

it('rejects a second identification in the same category for the same employee', function (): void {
    $employee = Employee::factory()->create();
    $tin = EmployeeIdentificationCategory::factory()->create(['code' => 'TIN']);

    EmployeeIdentification::query()->create([
        'employee_id' => $employee->id, 'category_id' => $tin->id, 'number' => '1',
    ]);

    expect(fn () => EmployeeIdentification::query()->create([
        'employee_id' => $employee->id, 'category_id' => $tin->id, 'number' => '2',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('allows the same category across two different employees', function (): void {
    $tin = EmployeeIdentificationCategory::factory()->create(['code' => 'TIN']);

    EmployeeIdentification::factory()->create(['category_id' => $tin->id, 'number' => '1']);
    EmployeeIdentification::factory()->create(['category_id' => $tin->id, 'number' => '2']);

    expect(EmployeeIdentification::query()->count())->toBe(2);
});

it('attaches a scan to the attachments disk as a single-file collection', function (): void {
    Storage::fake('attachments');

    $id = EmployeeIdentification::factory()->create();

    $content = str_repeat('%PDF-1.4'.PHP_EOL, 20);
    $id->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', $content))
        ->toMediaCollection('scan');

    expect($id->fresh()->getMedia('scan'))->toHaveCount(1)
        ->and($id->fresh()->getFirstMedia('scan')->disk)->toBe('attachments');

    // Single-file: adding a second scan REPLACES the first rather than accumulating.
    $id->addMedia(UploadedFile::fake()->createWithContent('tin-v2.pdf', $content))
        ->toMediaCollection('scan');

    expect($id->fresh()->getMedia('scan'))->toHaveCount(1)
        ->and($id->fresh()->getFirstMedia('scan')->file_name)->toBe('tin-v2.pdf');
});

// The security-critical one: a TIN must never be copied into activity_log, which has
// different read rules and a longer retention than anyone reasoned about. See spec,
// decision 6.
it('never writes an identification number into the activity log', function (): void {
    $id = EmployeeIdentification::factory()->create(['number' => '653536955000']);
    $id->update(['number' => '999999999999']);

    $properties = Activity::query()->pluck('properties')->map(fn ($p) => (string) $p)->implode(' ');

    expect(Activity::query()->count())->toBeGreaterThan(0)
        ->and($properties)->not->toContain('653536955000')
        ->and($properties)->not->toContain('999999999999');
});

// The M10a follow-up: SaveEmployeeIdentification used to attach the replacement scan
// INSIDE the same DB::transaction() as the number/issued_on/expires_on write. singleFile()
// on the 'scan' collection replaces, which means medialibrary deletes the OLD RustFS object
// as part of adding the new one — so a rollback after that point left a committed media row
// pointing at a deleted object. The fix moves the media write to run only after the DB
// transaction has returned (i.e. committed). This proves the new ordering by forcing the
// media step to fail (a disallowed mime type, rejected by the model's own
// acceptsMimeTypes() inside addMedia() — bypassing the FormRequest's belt-and-braces
// validation on purpose, to exercise the ACTION directly) and checking that (a) the DB
// write already committed despite the later failure, and (b) the previous scan was never
// touched.
it('commits the DB write and leaves the previous scan untouched when the post-commit media attach fails', function (): void {
    Storage::fake('attachments');

    $employee = Employee::factory()->create();
    $tin = EmployeeIdentificationCategory::factory()->create(['code' => 'TIN']);

    $identification = EmployeeIdentification::query()->create([
        'employee_id' => $employee->id,
        'category_id' => $tin->id,
        'number' => '111111111111',
    ]);
    $identification->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('scan');

    $originalMediaId = $identification->fresh()->getFirstMedia('scan')->id;

    $badScan = UploadedFile::fake()->create('malware.exe', 20, 'application/x-msdownload');

    expect(fn () => app(SaveEmployeeIdentification::class)->execute(new SaveEmployeeIdentificationInput(
        employeeId: $employee->id,
        categoryId: $tin->id,
        number: '222222222222',
        scan: $badScan,
    )))->toThrow(FileUnacceptableForCollection::class);

    // The number write committed even though the media step, which runs strictly after it,
    // subsequently failed.
    expect($identification->fresh()->number)->toBe('222222222222');

    // The PREVIOUS scan is untouched — the failed attach never reached the point of
    // replacing it, so no RustFS object was orphaned or lost.
    expect($identification->fresh()->getFirstMedia('scan')?->id)->toBe($originalMediaId);
});

it('cascades identifications away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeIdentification::factory()->create(['employee_id' => $employee->id]);

    $employee->delete();

    expect(EmployeeIdentification::query()->count())->toBe(0);
});
