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
        ->assertJsonPath('data.description', 'NBI clearance')
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

it('updates a document, keeping its own code and clearing description to null (not an empty string)', function (): void {
    // The document is created with a real, non-null description, so sending
    // description: null here is a genuine round-trip through input() vs string(): the house
    // rule (see CreateController's comment) is that has() is true for an explicit JSON
    // null, and string() would coerce it to '' — silently turning "clear the description"
    // into "set it to an empty string". Asserting the response comes back null, not '', is
    // the regression test for that exact mistake.
    $document = Document::factory()->create([
        'code' => 'KEEP',
        'category_id' => $this->category->id,
        'description' => 'Old description',
    ]);

    $this->actingAs($this->hr)
        ->patchJson("/api/v1/admin/documents/{$document->id}", [
            'code' => 'KEEP',
            'name' => 'Renamed',
            'category_id' => $this->category->id,
            'is_required' => true,
            'description' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.is_required', true)
        ->assertJsonPath('data.description', null);

    expect($document->fresh()->description)->toBeNull();
});

it('deletes a document with no files, returning the remaining catalog in the same response', function (): void {
    $deleted = Document::factory()->create(['code' => 'GONE', 'category_id' => $this->category->id]);
    $remaining = Document::factory()->create(['code' => 'STAYS', 'category_id' => $this->category->id]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/documents/{$deleted->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $remaining->id)
        ->assertJsonPath('data.0.code', 'STAYS');

    expect(Document::query()->count())->toBe(1);
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
