<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\ProfileCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns both catalogs to any authenticated user', function (): void {
    $this->seed(ProfileCatalogSeeder::class);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/profile/catalog')
        ->assertOk()
        ->assertJsonCount(5, 'data.relationships')
        ->assertJsonCount(8, 'data.identification_categories')
        ->assertJsonPath('data.identification_categories.0.code', 'BANK');   // ordered by code
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/profile/catalog')->assertUnauthorized();
});

it('returns empty lists rather than failing on an unseeded database', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/profile/catalog')
        ->assertOk()
        ->assertJsonPath('data.relationships', [])
        ->assertJsonPath('data.identification_categories', []);
});
