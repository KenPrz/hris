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

// Admin edits to seeded rows survive a reseed: insert-if-absent protects existing rows.
it('does not overwrite admin edits when reseeded', function (): void {
    $this->seed(DocumentCatalogSeeder::class);

    $nbi = Document::query()->where('code', 'NBI')->firstOrFail();
    expect($nbi->validity_months)->toBe(6);
    expect($nbi->name)->toBe('NBI Clearance');

    // Simulate an HR Admin editing the row through the UI
    $nbi->update([
        'validity_months' => 12,
        'name' => 'NBI Clearance (Annual)',
    ]);

    // Reseed — the catalog rows that already exist should not be touched
    $this->seed(DocumentCatalogSeeder::class);

    $nbi->refresh();

    // The admin edits survived — the seeder did not overwrite them
    expect($nbi->validity_months)->toBe(12)
        ->and($nbi->name)->toBe('NBI Clearance (Annual)');
});
