<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** An HR Admin scoped to one office: the role (verbs) plus the pivot (scope). */
function hrAdminFor(Office $office): User
{
    $user = User::factory()->create();
    $user->assignRole('HR Admin');
    $user->hrAdminOffices()->attach($office->id);

    return $user->fresh();
}

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->cebu = Office::factory()->create(['code' => 'CEB']);
    $this->manila = Office::factory()->create(['code' => 'MNL']);

    $this->subject = Employee::factory()->create(['current_office_id' => $this->cebu->id]);
});

it('lets an employee read their own profile in full but not edit it', function (): void {
    $self = User::factory()->create();
    $this->subject->update(['user_id' => $self->id]);

    expect($self->fresh()->can('viewFullProfile', $this->subject->fresh()))->toBeTrue()
        ->and($self->fresh()->can('updateProfile', $this->subject->fresh()))->toBeFalse();
});

it('lets an in-scope HR Admin read in full and edit', function (): void {
    $hr = hrAdminFor($this->cebu);

    expect($hr->can('viewFullProfile', $this->subject))->toBeTrue()
        ->and($hr->can('updateProfile', $this->subject))->toBeTrue();
});

it('denies an HR Admin of a different office entirely', function (): void {
    $hr = hrAdminFor($this->manila);

    expect($hr->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($hr->can('updateProfile', $this->subject))->toBeFalse()
        ->and($hr->can('viewRedactedProfile', $this->subject))->toBeFalse();
});

it('gives a manager the redacted view only, never the full one', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->cebu->id,
    ]);
    $this->subject->update(['current_reports_to_id' => $manager->id]);

    $managerUser = $managerUser->fresh();

    expect($managerUser->can('viewRedactedProfile', $this->subject->fresh()))->toBeTrue()
        ->and($managerUser->can('viewFullProfile', $this->subject->fresh()))->toBeFalse()
        ->and($managerUser->can('updateProfile', $this->subject->fresh()))->toBeFalse();
});

// The consequence spelled out in the spec: authority follows the office pivot, not the org
// chart. An HR Admin of Cebu who manages someone in Manila gets the REDACTED view of that
// report — being their manager does not widen HR authority across offices.
it('denies full read to an HR Admin managing a report in an office they do not administer', function (): void {
    $hrUser = hrAdminFor($this->cebu);
    $hrEmployee = Employee::factory()->create([
        'user_id' => $hrUser->id,
        'current_office_id' => $this->cebu->id,
    ]);

    $manilaReport = Employee::factory()->create([
        'current_office_id' => $this->manila->id,
        'current_reports_to_id' => $hrEmployee->id,
    ]);

    $hrUser = $hrUser->fresh();

    expect($hrUser->can('viewRedactedProfile', $manilaReport))->toBeTrue()
        ->and($hrUser->can('viewFullProfile', $manilaReport))->toBeFalse()
        ->and($hrUser->can('updateProfile', $manilaReport))->toBeFalse();
});

it('denies a stranger everything', function (): void {
    $stranger = User::factory()->create();

    expect($stranger->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($stranger->can('viewRedactedProfile', $this->subject))->toBeFalse()
        ->and($stranger->can('updateProfile', $this->subject))->toBeFalse();
});

it('grants a system admin everything through Gate::before', function (): void {
    $admin = User::factory()->create(['is_system_admin' => true]);

    expect($admin->can('viewFullProfile', $this->subject))->toBeTrue()
        ->and($admin->can('viewRedactedProfile', $this->subject))->toBeTrue()
        ->and($admin->can('updateProfile', $this->subject))->toBeTrue();
});

// Scope without the verb is not enough: an HR-Admin pivot row with no role grants nothing.
it('denies an actor holding the office pivot but not the permission', function (): void {
    $user = User::factory()->create();
    $user->hrAdminOffices()->attach($this->cebu->id);

    expect($user->fresh()->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($user->fresh()->can('updateProfile', $this->subject))->toBeFalse();
});
