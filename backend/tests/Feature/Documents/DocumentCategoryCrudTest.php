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
