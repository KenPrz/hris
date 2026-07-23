<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RbacSeeder::class));

it('seeds the HR Admin role with its verb catalog', function (): void {
    $user = User::factory()->create();
    $user->assignRole('HR Admin');

    expect($user->can('leave.approve'))->toBeTrue()
        ->and($user->can('employee.pii.edit'))->toBeTrue()
        ->and($user->can('schedule.manage'))->toBeTrue()
        ->and($user->can('cutoff.manage'))->toBeTrue();
});

it('grants a system admin every ability via Gate::before', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);

    // A permission that exists but was never assigned to this user.
    expect($admin->can('leave.approve'))->toBeTrue()
        // ...and one that does not exist as a permission at all.
        ->and($admin->can('anything.at.all'))->toBeTrue();
});

it('does not grant a plain user HR abilities', function (): void {
    $user = User::factory()->create();

    expect($user->can('leave.approve'))->toBeFalse();
});
