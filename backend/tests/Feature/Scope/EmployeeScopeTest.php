<?php

declare(strict_types=1);

use App\Domain\Scope\EmployeeScope;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seenBy(User $user): array
{
    return EmployeeScope::visibleTo($user)->pluck('id')->all();
}

it('lets a plain employee see only themselves', function (): void {
    $office = Office::factory()->create();
    $me = Employee::factory()->create(['current_office_id' => $office->id]);
    $me->user()->associate(User::factory()->create())->save();
    $peer = Employee::factory()->create(['current_office_id' => $office->id]);

    expect(seenBy($me->user))->toBe([$me->id])
        ->and(seenBy($me->user))->not->toContain($peer->id);
});

it('lets a manager see exactly their direct reports and themselves', function (): void {
    $office = Office::factory()->create();
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create(['current_office_id' => $office->id]);
    $manager->user()->associate($managerUser)->save();

    $report = Employee::factory()->create(['current_office_id' => $office->id, 'current_reports_to_id' => $manager->id]);
    $peersReport = Employee::factory()->create(['current_office_id' => $office->id]);

    $seen = seenBy($managerUser);
    expect($seen)->toContain($manager->id)
        ->and($seen)->toContain($report->id)
        ->and($seen)->not->toContain($peersReport->id);
});

it('lets an HR admin see only their office', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();

    $hrUser = User::factory()->create();
    $hr = Employee::factory()->create(['current_office_id' => $manila->id]);
    $hr->user()->associate($hrUser)->save();
    $hrUser->hrAdminOffices()->attach($manila->id);

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $cebuWorker = Employee::factory()->create(['current_office_id' => $cebu->id]);

    $seen = seenBy($hrUser);
    expect($seen)->toContain($manilaWorker->id)
        ->and($seen)->not->toContain($cebuWorker->id);
});

it('lets a system admin see everyone', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);
    Employee::factory()->count(5)->create();

    expect(seenBy($admin))->toHaveCount(5);
});

it('composes additively for an HR admin who also has reports', function (): void {
    $manila = Office::factory()->create();
    $cebu = Office::factory()->create();
    $hrUser = User::factory()->create();
    $hr = Employee::factory()->create(['current_office_id' => $cebu->id]);   // works in Cebu
    $hr->user()->associate($hrUser)->save();
    $hrUser->hrAdminOffices()->attach($manila->id);                          // but HR-admins Manila

    $manilaWorker = Employee::factory()->create(['current_office_id' => $manila->id]);
    $hrDirectReport = Employee::factory()->create(['current_office_id' => $cebu->id, 'current_reports_to_id' => $hr->id]);

    $seen = seenBy($hrUser);
    expect($seen)->toContain($manilaWorker->id)      // via HR office
        ->and($seen)->toContain($hrDirectReport->id); // via direct report in a different office
});
