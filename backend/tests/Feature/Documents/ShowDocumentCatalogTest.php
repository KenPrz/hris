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

    // Same ordering guarantee on the documents side (ShowCatalogController's second
    // orderBy('code')) — the earlier assertions above use firstWhere, which would still pass
    // if this orderBy were ever dropped, so this is the one assertion that actually pins it.
    expect(collect($response->json('data.documents'))->pluck('code')->all())
        ->toBe(['BUSINESS_PERMIT', 'CONTRACT', 'FILE_201', 'MEDICAL', 'NBI', 'POLICY']);
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
