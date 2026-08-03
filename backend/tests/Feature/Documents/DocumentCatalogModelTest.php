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
        ->and($document->applies_to->value)->toBe('employee');
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
