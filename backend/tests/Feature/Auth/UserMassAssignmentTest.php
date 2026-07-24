<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not let mass assignment grant system-admin', function (): void {
    // The defense: even if a future endpoint carelessly does User::create($request->all()),
    // is_system_admin — which bypasses both Gate::before and EmployeeScope — cannot be set
    // that way. It stays at the DB default (false).
    $user = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@hris.test',
        'password' => 'hunter2hunter2',
        'is_system_admin' => true,   // attacker-supplied — must be ignored
    ]);

    // Guarded out entirely — never set on the model, and the DB default (false) stands.
    expect($user->fresh()->is_system_admin)->toBeFalse();
});

it('does not let a fill()/update() escalate an existing user either', function (): void {
    $user = User::create(['name' => 'Bob', 'email' => 'bob@hris.test', 'password' => 'hunter2hunter2']);

    $user->update(['is_system_admin' => true]);
    $user->fill(['is_system_admin' => true])->save();

    expect($user->fresh()->is_system_admin)->toBeFalse();
});

it('still allows an explicit grant (the one legitimate path)', function (): void {
    $user = User::create(['name' => 'Sofia', 'email' => 'sofia@hris.test', 'password' => 'hunter2hunter2']);

    $user->forceFill(['is_system_admin' => true])->save();

    expect($user->fresh()->is_system_admin)->toBeTrue();
});
