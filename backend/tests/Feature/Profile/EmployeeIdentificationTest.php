<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

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

it('cascades identifications away when the employee is deleted', function (): void {
    $employee = Employee::factory()->create();
    EmployeeIdentification::factory()->create(['employee_id' => $employee->id]);

    $employee->delete();

    expect(EmployeeIdentification::query()->count())->toBe(0);
});
