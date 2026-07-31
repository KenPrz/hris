<?php

declare(strict_types=1);

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
| M9: the first login on a fresh production database. DatabaseSeeder can't serve this —
| it pairs the RBAC catalog (required in production) with the Manila/Cebu demo company
| (which must never touch it). This command runs the first half and mints exactly one
| System Admin, so M8's "configure a company from an empty database entirely through the
| UI" is actually reachable in production.
|
| Artisan::call + Artisan::output() rather than $this->artisan(...): the generated
| password is only ever printed, never stored recoverably, so the test has to read it
| out of the command's own output to prove it actually signs in.
*/

it('creates one System Admin with a working password and seeds the RBAC catalog', function (): void {
    $exit = Artisan::call('hris:bootstrap-admin', ['email' => 'admin@example.test']);
    $output = Artisan::output();

    expect($exit)->toBe(0);

    $user = User::query()->where('email', 'admin@example.test')->sole();
    expect($user->is_system_admin)->toBeTrue()
        ->and($user->name)->toBe('System Administrator');

    // The password is shown exactly once; prove the printed value is the real one.
    expect($output)->toMatch('/password:/');
    preg_match('/password:\s*(\S+)/', $output, $matches);
    expect($matches[1] ?? '')->not->toBe('');
    expect(Hash::check($matches[1], $user->password))->toBeTrue();

    // The catalog half: without it, granting HR Admin later throws PermissionDoesNotExist.
    expect(Role::query()->where('name', 'HR Admin')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'holiday.manage')->exists())->toBeTrue();

    // A System Admin needs no employee record — that is what avoids the chicken-and-egg
    // with the organization they are about to create. SessionResource renders null here.
    expect($user->employee)->toBeNull();
});

it('honours --name', function (): void {
    Artisan::call('hris:bootstrap-admin', ['email' => 'boss@example.test', '--name' => 'Sofia Reyes']);

    expect(User::query()->where('email', 'boss@example.test')->sole()->name)->toBe('Sofia Reyes');
});

it('refuses to mint a second System Admin', function (): void {
    User::factory()->create(['is_system_admin' => true]);

    $exit = Artisan::call('hris:bootstrap-admin', ['email' => 'second@example.test']);

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('already exists')
        ->and(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();
});

it('still seeds the RBAC and profile catalogs against a database that already has a System Admin', function (): void {
    // Every M9 production install already has a System Admin by the time M10a deploys, so
    // the catalogs must be reachable even though this run is refused for minting a second
    // superuser. Without the seed calls hoisted above the guard, employee_identification_
    // categories and relationships would stay empty forever on an upgraded install.
    User::factory()->create(['is_system_admin' => true]);

    $exit = Artisan::call('hris:bootstrap-admin', ['email' => 'second@example.test']);

    expect($exit)->not->toBe(0);

    expect(Role::query()->where('name', 'HR Admin')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'holiday.manage')->exists())->toBeTrue();

    expect(EmployeeIdentificationCategory::query()->count())->toBe(8)
        ->and(Relationship::query()->count())->toBe(5);
});

it('refuses an email already taken by a non-admin user', function (): void {
    User::factory()->create(['email' => 'taken@example.test', 'is_system_admin' => false]);

    $exit = Artisan::call('hris:bootstrap-admin', ['email' => 'taken@example.test']);

    expect($exit)->not->toBe(0)
        ->and(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('refuses a malformed email', function (): void {
    $exit = Artisan::call('hris:bootstrap-admin', ['email' => 'not-an-email']);

    expect($exit)->not->toBe(0)
        ->and(User::query()->where('is_system_admin', true)->exists())->toBeFalse();
});
