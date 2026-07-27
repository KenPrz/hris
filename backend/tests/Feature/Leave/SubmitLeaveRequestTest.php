<?php

declare(strict_types=1);

use App\Domain\Schedule\Weekday;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Request;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| M6b-b Task 7: an employee filing their own leave request. The amount debited is
| server-computed from the scheduled working days the range spans, never client-supplied
| — mirrors the attendance-adjustment submit test's shape (submit-controller + shared
| Request/detail pair), adapted for leave's two-hop initial-state branch.
*/

/** Mon-Fri 08:00-18:00 (60m break), Sat/Sun rest — same shape as ScheduleResolverTest's. */
function leaveOfficeWithSchedule(int $minutesPerLeaveDay = 480): Office
{
    $office = Office::factory()->create(['minutes_per_leave_day' => $minutesPerLeaveDay]);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create(['shift_template_id' => $template->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080, 'break_minutes' => $rest ? null : 60]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    return $office;
}

function leaveActingEmployee(Office $office, ?Employee $manager = null): Employee
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $manager?->id,
    ]);
    Sanctum::actingAs($user);

    return $employee;
}

// --- Happy path -------------------------------------------------------------

it('files a full-day leave request over 3 working days as pending, when the employee has a manager', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $manager = Employee::factory()->create(['current_office_id' => $office->id]);
    $employee = leaveActingEmployee($office, $manager);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    // Monday 2026-08-03 through Wednesday 2026-08-05: 3 scheduled working days.
    $response = $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
        'note' => 'Family trip.',
    ])->assertCreated();

    $response
        ->assertJsonPath('data.type', 'leave')
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.employee_id', $employee->id)
        ->assertJsonPath('data.detail.leave_type_id', $leaveType->id)
        ->assertJsonPath('data.detail.start_date', '2026-08-03')
        ->assertJsonPath('data.detail.end_date', '2026-08-05')
        ->assertJsonPath('data.detail.day_part', 'full')
        ->assertJsonPath('data.detail.amount_minutes', 3 * 480);

    expect(Request::count())->toBe(1);

    $request = Request::query()->first();
    expect($request->employee_id)->toBe($employee->id)
        ->and($request->state->value)->toBe('pending')
        ->and($request->leaveDetail->amount_minutes)->toBe(1440);
});

it('starts manager_approved when the requester has no manager on file', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office, null);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
        'note' => 'No manager on file.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'manager_approved');

    expect(Request::query()->first()->state->value)->toBe('manager_approved');
});

it('halves the per-day amount for a half_part day request', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-03',
        'day_part' => 'half',
        'note' => 'Half day.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.detail.amount_minutes', 240);
});

it('accepts an attachment on a type that requires one', function (): void {
    Storage::fake('attachments');
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create([
        'deducts_balance' => true,
        'requires_attachment' => true,
    ]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-03',
        'day_part' => 'full',
        'note' => 'Medical cert attached.',
        'attachment' => UploadedFile::fake()->createWithContent('cert.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)),
    ])
        ->assertCreated()
        ->assertJsonPath('data.has_attachment', true);
});

// --- Guards -------------------------------------------------------------

it('422s an inactive leave type', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create([
        'deducts_balance' => true,
        'is_active' => false,
    ]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
        'note' => 'Should not land.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leave_type_inactive');

    expect(Request::count())->toBe(0);
});

it('422s a requires_attachment type filed with no file', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create([
        'deducts_balance' => true,
        'requires_attachment' => true,
    ]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
        'note' => 'No file attached.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leave_attachment_required');

    expect(Request::count())->toBe(0);
});

it('422s a range with zero scheduled working days', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    // 2026-08-08 / 09 is a Saturday/Sunday on the Mon-Fri schedule.
    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-08',
        'end_date' => '2026-08-09',
        'day_part' => 'full',
        'note' => 'Weekend only.',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'leave_request_has_no_working_days');

    expect(Request::count())->toBe(0);
});

it('404s a foreign-office leave type identically to a fabricated one', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $otherOffice = Office::factory()->create();
    $employee = leaveActingEmployee($office);
    $foreignLeaveType = LeaveType::factory()->for($otherOffice, 'office')->create(['deducts_balance' => true]);

    $payload = fn (string $leaveTypeId): array => [
        'leave_type_id' => $leaveTypeId,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-05',
        'day_part' => 'full',
        'note' => 'Should 404.',
    ];

    $foreign = $this->postJson('/api/v1/leave/requests', $payload($foreignLeaveType->id))
        ->assertStatus(404);
    $fabricated = $this->postJson('/api/v1/leave/requests', $payload('00000000-0000-7000-8000-000000000000'))
        ->assertStatus(404);

    expect($foreign->json())->toEqual($fabricated->json());
    expect(Request::count())->toBe(0);
});

it('400s an end_date before start_date', function (): void {
    $office = leaveOfficeWithSchedule(480);
    $employee = leaveActingEmployee($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-03',
        'day_part' => 'full',
        'note' => 'Backwards range.',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(Request::count())->toBe(0);
});
