<?php

declare(strict_types=1);

use App\Domain\Scope\OfficeScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function administeredBy(User $user): array
{
    return OfficeScope::administeredBy($user)->pluck('id')->all();
}

it('lets a system admin administer every office', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    Office::factory()->count(3)->create();

    expect(administeredBy($admin))->toHaveCount(3);
});

it('lets an HR admin administer only their office', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();

    $hrUser = User::factory()->create();
    $hrUser->hrAdminOffices()->attach($manila->id);

    $seen = administeredBy($hrUser);
    expect($seen)->toContain($manila->id)
        ->and($seen)->not->toContain($cebu->id);
});

it('lets a plain user administer zero offices, not all of them', function (): void {
    Office::factory()->count(3)->create();
    $plainUser = User::factory()->create();

    expect(OfficeScope::administeredBy($plainUser)->count())->toBe(0);
});
