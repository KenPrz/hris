<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// hrAdminFor() is declared once, globally, in tests/Pest.php — a second file-scope
// declaration anywhere under tests/ is a PHP fatal, not a test failure. See the M10a
// follow-ups.

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
        ->and($self->fresh()->can('viewRedactedProfile', $this->subject->fresh()))->toBeTrue()
        ->and($self->fresh()->can('updateProfile', $this->subject->fresh()))->toBeFalse();
});

it('lets an in-scope HR Admin read in full and edit', function (): void {
    $hr = hrAdminFor($this->cebu);

    expect($hr->can('viewFullProfile', $this->subject))->toBeTrue()
        ->and($hr->can('viewRedactedProfile', $this->subject))->toBeTrue()
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

// The role alone grants nothing without at least one office pivot row — the verb and the
// scope are both required, and an HR Admin freshly assigned the role but not yet scoped to
// any office is scoped to nothing.
it('denies an HR Admin holding the role but no office pivot rows at all', function (): void {
    $hr = User::factory()->create();
    $hr->assignRole('HR Admin');
    $hr = $hr->fresh();

    expect($hr->can('viewFullProfile', $this->subject))->toBeFalse()
        ->and($hr->can('viewRedactedProfile', $this->subject))->toBeFalse()
        ->and($hr->can('updateProfile', $this->subject))->toBeFalse();
});

// Fix round 1, finding 2 — the spec owner's ruling: separation of duties applies to HR
// Admins too. Administering your own office is not license to edit your own PII; reading
// it in full is still fine, and editing a colleague in the same office is still fine.
it('blocks an HR Admin from editing their own PII even in their own administered office, but not from reading it in full or editing a colleague there', function (): void {
    $hrUser = hrAdminFor($this->cebu);
    $hrEmployee = Employee::factory()->create([
        'user_id' => $hrUser->id,
        'current_office_id' => $this->cebu->id,
    ]);

    $hrUser = $hrUser->fresh();

    expect($hrUser->can('updateProfile', $hrEmployee->fresh()))->toBeFalse()
        ->and($hrUser->can('viewFullProfile', $hrEmployee->fresh()))->toBeTrue()
        ->and($hrUser->can('updateProfile', $this->subject))->toBeTrue();
});

// Fix round 1, finding 1 regression — the old self-check was `$user->employee?->id ===
// $employee->id`, which is `null === null` for an actor with no employee row against an
// unsaved Employee (HasUuids assigns the id on `creating`, so `new Employee` has a null id).
// That failed open. The fix compares on `employees.user_id`, which is null on an unsaved row
// and therefore can never match, so this must stay false.
it('denies viewFullProfile to an actor with no employee row, against an unsaved Employee', function (): void {
    $stranger = User::factory()->create();

    expect($stranger->can('viewFullProfile', new Employee()))->toBeFalse();
});
