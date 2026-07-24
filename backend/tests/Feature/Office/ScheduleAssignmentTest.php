<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ScheduleAssignment;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M4b Task 7: schedule-assignment list + create + delete. Unlike a shift template (scoped
| by its own office_id), an assignment is scoped by the TARGET's office — the employee's
| current office, or the department's office. Mirrors ShiftTemplateReadWriteTest's harness
| for the office-scoped 404-not-403 discipline.
*/

function assignmentOffice(): Office
{
    return Office::factory()->create();
}

function hrAdminOfAssignment(Office ...$offices): User
{
    $user = User::factory()->create();

    foreach ($offices as $office) {
        $user->hrAdminOffices()->attach($office->id);
    }

    return $user;
}

function scheduleAssignmentTemplateFor(Office $office): ShiftTemplate
{
    return ShiftTemplate::query()->create(['office_id' => $office->id, 'name' => 'Template']);
}

// --- Create -------------------------------------------------------------

it('creates an assignment for an employee target', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $res = $this->postJson('/api/v1/office/schedule-assignments', [
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ])->assertCreated();

    expect($res->json('data.shift_template_id'))->toBe($template->id)
        ->and($res->json('data.employee_id'))->toBe($employee->id)
        ->and($res->json('data.department_id'))->toBeNull()
        ->and($res->json('data.effective_from'))->toBe('2026-08-01');

    $this->assertDatabaseHas('schedule_assignments', [
        'id' => $res->json('data.id'),
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'department_id' => null,
    ]);
});

it('creates an assignment for a department target', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $res = $this->postJson('/api/v1/office/schedule-assignments', [
        'shift_template_id' => $template->id,
        'department_id' => $department->id,
        'effective_from' => '2026-08-01',
    ])->assertCreated();

    expect($res->json('data.department_id'))->toBe($department->id)
        ->and($res->json('data.employee_id'))->toBeNull();

    $this->assertDatabaseHas('schedule_assignments', [
        'id' => $res->json('data.id'),
        'shift_template_id' => $template->id,
        'department_id' => $department->id,
        'employee_id' => null,
    ]);
});

it('rejects a body with both employee_id and department_id', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $this->postJson('/api/v1/office/schedule-assignments', [
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'department_id' => $department->id,
        'effective_from' => '2026-08-01',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a body with neither employee_id nor department_id', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);

    $this->postJson('/api/v1/office/schedule-assignments', [
        'shift_template_id' => $template->id,
        'effective_from' => '2026-08-01',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a duplicate assignment for the same employee and effective date', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $body = [
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ];

    $this->postJson('/api/v1/office/schedule-assignments', $body)->assertCreated();

    $this->postJson('/api/v1/office/schedule-assignments', $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'schedule_assignment_exists');

    $this->assertDatabaseCount('schedule_assignments', 1);
});

it('404s creating for an employee in an office not administered, identically to a fabricated employee', function (): void {
    $mine = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($mine);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($mine);
    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);

    $body = fn (string $employeeId) => [
        'shift_template_id' => $template->id,
        'employee_id' => $employeeId,
        'effective_from' => '2026-08-01',
    ];

    $oos = $this->postJson('/api/v1/office/schedule-assignments', $body($theirEmployee->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/schedule-assignments', $body((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('schedule_assignments', 0);
});

it('404s creating for a department in an office not administered, identically to a fabricated department', function (): void {
    $mine = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($mine);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($mine);
    $theirDepartment = Department::factory()->create(['office_id' => $other->id]);

    $body = fn (string $departmentId) => [
        'shift_template_id' => $template->id,
        'department_id' => $departmentId,
        'effective_from' => '2026-08-01',
    ];

    $oos = $this->postJson('/api/v1/office/schedule-assignments', $body($theirDepartment->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/schedule-assignments', $body((string) Str::uuid7()))->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('schedule_assignments', 0);
});

it('404s creating with a template from another office, identically to a fabricated template', function (): void {
    $mine = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($mine);
    Sanctum::actingAs($hr);

    $employee = Employee::factory()->create(['current_office_id' => $mine->id]);
    $theirTemplate = scheduleAssignmentTemplateFor($other);

    $body = fn (string $templateId) => [
        'shift_template_id' => $templateId,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ];

    $foreign = $this->postJson('/api/v1/office/schedule-assignments', $body($theirTemplate->id))->assertStatus(404);
    $fake = $this->postJson('/api/v1/office/schedule-assignments', $body((string) Str::uuid7()))->assertStatus(404);

    $foreign->assertExactJson($fake->json());
    $foreign->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('schedule_assignments', 0);
});

// --- List -----------------------------------------------------------------

it("lists the office's assignments, scoped by target office", function (): void {
    $office = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $department = Department::factory()->create(['office_id' => $office->id]);

    $viaEmployee = ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);
    $viaDepartment = ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'department_id' => $department->id,
        'effective_from' => '2026-08-01',
    ]);

    // Belongs to a different office — must not show up.
    $otherTemplate = scheduleAssignmentTemplateFor($other);
    $otherEmployee = Employee::factory()->create(['current_office_id' => $other->id]);
    ScheduleAssignment::create([
        'shift_template_id' => $otherTemplate->id,
        'employee_id' => $otherEmployee->id,
        'effective_from' => '2026-08-01',
    ]);

    $res = $this->getJson("/api/v1/office/schedule-assignments?office={$office->id}")->assertOk();

    expect($res->json('data.*.id'))->toEqualCanonicalizing([$viaEmployee->id, $viaDepartment->id]);
});

it('404s listing an out-of-scope office, identically to a fabricated office', function (): void {
    $mine = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($mine);
    Sanctum::actingAs($hr);

    $oos = $this->getJson("/api/v1/office/schedule-assignments?office={$other->id}")->assertStatus(404);
    $fake = $this->getJson('/api/v1/office/schedule-assignments?office='.(string) Str::uuid7())->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');
});

// --- Delete -------------------------------------------------------------

it('deletes an assignment', function (): void {
    $office = assignmentOffice();
    $hr = hrAdminOfAssignment($office);
    Sanctum::actingAs($hr);

    $template = scheduleAssignmentTemplateFor($office);
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $assignment = ScheduleAssignment::create([
        'shift_template_id' => $template->id,
        'employee_id' => $employee->id,
        'effective_from' => '2026-08-01',
    ]);

    $this->deleteJson("/api/v1/office/schedule-assignments/{$assignment->id}")->assertNoContent();

    $this->assertDatabaseMissing('schedule_assignments', ['id' => $assignment->id]);
});

it('404s deleting an assignment not administered, identically to a fabricated assignment', function (): void {
    $mine = assignmentOffice();
    $other = assignmentOffice();
    $hr = hrAdminOfAssignment($mine);
    Sanctum::actingAs($hr);

    $theirTemplate = scheduleAssignmentTemplateFor($other);
    $theirEmployee = Employee::factory()->create(['current_office_id' => $other->id]);
    $theirs = ScheduleAssignment::create([
        'shift_template_id' => $theirTemplate->id,
        'employee_id' => $theirEmployee->id,
        'effective_from' => '2026-08-01',
    ]);

    $oos = $this->deleteJson("/api/v1/office/schedule-assignments/{$theirs->id}")->assertStatus(404);
    $fake = $this->deleteJson('/api/v1/office/schedule-assignments/'.(string) Str::uuid7())->assertStatus(404);

    $oos->assertExactJson($fake->json());
    $oos->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('schedule_assignments', ['id' => $theirs->id]);
});
