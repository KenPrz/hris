<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

it('catalogues both document permissions on the HR Admin role', function (): void {
    expect(Permission::query()->where('name', 'document.manage')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'document.manage.self')->exists())->toBeTrue();

    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');

    expect($hr->fresh()->can('document.manage'))->toBeTrue()
        ->and($hr->fresh()->can('document.manage.self'))->toBeTrue();
});

it('lets any holder of document.manage edit the catalog, regardless of office', function (): void {
    // The catalog is company-wide — documents and categories have no office_id to scope by,
    // so holding the permission at all IS the check. File-level access is office-scoped and
    // lands in M10b-b.
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');

    expect($hr->fresh()->can('manageCatalog', Document::class))->toBeTrue();
});

it('denies an actor without the permission', function (): void {
    expect(User::factory()->create()->can('manageCatalog', Document::class))->toBeFalse();
});

it('denies an actor holding only the self permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('document.manage.self');

    expect($user->fresh()->can('manageCatalog', Document::class))->toBeFalse();
});

it('grants a system admin through Gate::before', function (): void {
    expect(User::factory()->create(['is_system_admin' => true])->can('manageCatalog', Document::class))->toBeTrue();
});

// Guards the trap RbacSeeder's reserved-words comment describes: spatie registers its own
// Gate::before granting any ability whose NAME matches a held permission. A permission named
// 'manageCatalog' would grant this policy ability globally. The dotted names prevent it.
it('uses dotted permission names that cannot collide with a policy ability', function (): void {
    $abilities = ['manageCatalog'];

    foreach (Permission::query()->pluck('name') as $name) {
        expect($abilities)->not->toContain($name);
    }
});
