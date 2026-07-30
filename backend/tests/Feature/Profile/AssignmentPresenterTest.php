<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Profile\LaborType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Coverage for EmployeeAssignmentPresenter's nine "Assignment" fields, reached through
 * GET /me/profile (EmployeeProfileResource) — the presenter has no contract of its own
 * outside the two profile resources, so this is the only place its output is observable.
 *
 * Added in Task 7 fix round 1: the original ShowProfileTest suite never asserted
 * work_shift at all, and never created an EmploymentRecord, so designation/
 * employment_status/labor_type were rendered but never checked against a known value.
 *
 * Deliberately, none of the ShiftTemplate rows below get ShiftTemplateDay children. That
 * is not an oversight: EmployeeAssignmentPresenter::workShift() reads only the template's
 * `name` through the `shiftTemplate`/`defaultShiftTemplate` relations, never
 * ScheduleResolver::resolve() (which would 500 via ShiftTemplateDay::sole() on a template
 * with no days). A template with no days resolving cleanly here is itself evidence the
 * presenter takes the cheap, non-throwing path.
 */
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

/** A self-viewing employee (no profile row needed — these tests only read `assignment`). */
function assignmentTestSelf(Office $office, ?string $departmentId = null): User
{
    $user = User::factory()->create();
    Employee::factory()->create([
        'user_id' => $user->id,
        'current_office_id' => $office->id,
        'current_department_id' => $departmentId,
    ]);

    return $user->fresh();
}

it('falls back to the office default template when no assignment exists at any level', function (): void {
    $office = Office::factory()->create();
    $default = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office Default']);
    $office->update(['default_shift_template_id' => $default->id]);

    $user = assignmentTestSelf($office);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', 'Office Default');
});

it('prefers a department-level assignment over the office default', function (): void {
    $office = Office::factory()->create();
    $default = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office Default']);
    $office->update(['default_shift_template_id' => $default->id]);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $deptTemplate = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Department Shift']);
    ScheduleAssignment::create([
        'shift_template_id' => $deptTemplate->id,
        'department_id' => $department->id,
        'effective_from' => '2020-01-01',
    ]);

    $user = assignmentTestSelf($office, $department->id);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', 'Department Shift');
});

it('prefers an employee-level assignment over both the department and the office default', function (): void {
    $office = Office::factory()->create();
    $default = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office Default']);
    $office->update(['default_shift_template_id' => $default->id]);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $deptTemplate = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Department Shift']);
    ScheduleAssignment::create([
        'shift_template_id' => $deptTemplate->id,
        'department_id' => $department->id,
        'effective_from' => '2020-01-01',
    ]);

    $user = assignmentTestSelf($office, $department->id);
    $employee = $user->employee;

    $empTemplate = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Employee Shift']);
    ScheduleAssignment::create([
        'shift_template_id' => $empTemplate->id,
        'employee_id' => $employee->id,
        'effective_from' => '2020-01-01',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', 'Employee Shift');
});

// The deliberate design decision spelled out in EmployeeAssignmentPresenter's docblock: an
// override is a one-day exception, not the standing arrangement a profile's "Work Shift"
// shows. Nothing recorded this until now.
it('ignores a schedule override for today when resolving work_shift', function (): void {
    $office = Office::factory()->create();
    $standing = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Standing Shift']);

    $user = assignmentTestSelf($office);
    $employee = $user->employee;

    ScheduleAssignment::create([
        'shift_template_id' => $standing->id,
        'employee_id' => $employee->id,
        'effective_from' => '2020-01-01',
    ]);

    // A one-day exception for today. If work_shift read through ScheduleResolver (which
    // checks overrides first), this would flip the rendered shift for today specifically.
    ScheduleOverride::create([
        'employee_id' => $employee->id,
        'date' => Carbon::today()->toDateString(),
        'is_rest' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', 'Standing Shift');
});

// ScheduleResolver::resolve() throws EmployeeHasNoOffice for exactly this case — correct
// for the compute engine, fatal for a profile read. This and the next test exist solely to
// pin that the presenter's own fallback chain, not ScheduleResolver, is what's wired in.
it('renders a null work_shift, and a 200, for an employee with no office at all', function (): void {
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id, 'current_office_id' => null]);

    $this->actingAs($user->fresh())
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', null);
});

// ScheduleResolver::resolve() throws OfficeHasNoDefaultTemplate for exactly this case.
it('renders a null work_shift, and a 200, for an office with no default template', function (): void {
    $office = Office::factory()->create(['default_shift_template_id' => null]);
    $user = assignmentTestSelf($office);

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', null);
});

it('maps designation, employment_status, and labor_type from the current employment record', function (): void {
    $office = Office::factory()->create();
    $department = Department::factory()->create(['office_id' => $office->id]);
    $user = assignmentTestSelf($office, $department->id);
    $employee = $user->employee;

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2020-01-01',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: null,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: LaborType::Direct->value,
    ));

    $this->actingAs($user)
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.assignment.employment_status', 'regular')
        ->assertJsonPath('data.assignment.labor_type', 'direct');
});
