<?php

declare(strict_types=1);

use App\Actions\Employees\RecordEmploymentChange;
use App\Actions\Employees\RecordEmploymentChangeInput;
use App\Domain\Profile\LaborType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * `APP_TIMEZONE` is UTC by rule, so a naive `Carbon::today()` is UTC-today. Between 00:00
 * and 08:00 Asia/Manila that is still YESTERDAY, so an employment record effective TODAY
 * (Manila) silently failed to appear — while `EmployeeProfile::age`, which already anchors
 * to the office timezone, had already rolled over. Freeze the clock at a UTC instant that is
 * already the next day in Manila (2026-01-22 16:30 UTC = 2026-01-23 00:30 Manila), matching
 * the fixture EmployeeProfileModelTest.php already uses for the age accessor, and prove the
 * four HTTP resources now agree with it: EmployeeProfileResource,
 * EmployeeProfileSummaryResource, EmployeeAssignmentPresenter (work_shift), and
 * EmployeeDetailResource.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-01-22 16:30:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows an employment record effective on the office-local date on /me/profile, before UTC has rolled over', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'current_office_id' => $office->id,
    ]);

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2026-01-23', // Manila's today; still UTC-tomorrow at freeze time.
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

    $this->actingAs($user->fresh())
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.assignment.employment_status', 'regular');
});

it('shows it in the redacted resource too, for a manager viewing a direct report', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $office->id,
    ]);

    $reportUser = User::factory()->create();
    $report = Employee::factory()->create([
        'user_id' => $reportUser->id,
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager->id,
    ]);

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $report->id,
        effectiveFrom: '2026-01-23',
        officeId: $office->id,
        departmentId: $department->id,
        reportsToId: $manager->id,
        employmentType: 'regular',
        isArt82Exempt: false,
        baseRateCents: 3000000,
        actorId: null,
        designation: 'Backend Software Developer',
        laborType: LaborType::Direct->value,
    ));

    $this->actingAs($managerUser->fresh())
        ->getJson("/api/v1/employees/{$report->id}/profile")
        ->assertOk()
        ->assertJsonPath('data.assignment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.assignment.employment_status', 'regular');
});

it('resolves work_shift from a schedule assignment effective on the office-local date', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'New Shift']);

    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'current_office_id' => $office->id,
    ]);

    ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-01-23', // Manila's today; still UTC-tomorrow at freeze time.
    ]);

    $this->actingAs($user->fresh())
        ->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.assignment.work_shift', 'New Shift');
});

it('shows it on the admin employee detail endpoint (EmployeeDetailResource)', function (): void {
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);
    $department = Department::factory()->create(['office_id' => $office->id]);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $admin = User::factory()->create(['is_system_admin' => true]);

    app(RecordEmploymentChange::class)->execute(new RecordEmploymentChangeInput(
        employeeId: $employee->id,
        effectiveFrom: '2026-01-23',
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

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('data.current_employment.designation', 'Backend Software Developer')
        ->assertJsonPath('data.current_employment.effective_from', '2026-01-23');
});
