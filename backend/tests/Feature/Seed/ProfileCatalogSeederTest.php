<?php

declare(strict_types=1);

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Database\Seeders\ProfileCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the eight identification kinds and five relationships', function (): void {
    $this->seed(ProfileCatalogSeeder::class);

    expect(EmployeeIdentificationCategory::query()->pluck('code')->sort()->values()->all())
        ->toBe(['BANK', 'DL', 'HDMF', 'PASSPORT', 'PHIC', 'PRC', 'SSS', 'TIN'])
        ->and(Relationship::query()->pluck('code')->sort()->values()->all())
        ->toBe(['child', 'other', 'parent', 'sibling', 'spouse']);
});

it('is idempotent — a second run creates nothing and duplicates nothing', function (): void {
    $this->seed(ProfileCatalogSeeder::class);
    $this->seed(ProfileCatalogSeeder::class);

    expect(EmployeeIdentificationCategory::query()->count())->toBe(8)
        ->and(Relationship::query()->count())->toBe(5);
});

it('is run by hris:bootstrap-admin so a fresh production database has the catalog', function (): void {
    $this->artisan('hris:bootstrap-admin', ['email' => 'admin@example.com'])
        ->assertSuccessful();

    expect(EmployeeIdentificationCategory::query()->count())->toBe(8)
        ->and(Relationship::query()->count())->toBe(5);
});
