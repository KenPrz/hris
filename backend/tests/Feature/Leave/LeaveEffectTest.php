<?php

declare(strict_types=1);

use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Leave\LeaveBalances;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Domain\Schedule\Weekday;
use App\Exceptions\Domain\InsufficientLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\RecomputeRun;
use App\Models\Request;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| Task 8: LeaveEffect — the real, final-hop leave effect. Completes Task 5's two-hop
| assertions (which used a spy RequestEffectResolver, since LeaveEffect and leave_details
| didn't exist yet) by driving a full two-hop `leave` request through ApproveRequest with
| the REAL effect wired: no debit at manager_approved (deferred), exactly one debit row
| (source=leave_taken) at approved, insufficient balance rolls the whole approval back
| (state stays manager_approved, no row), and an event type (deducts_balance=false) writes
| no ledger row at all. Bus::fake() proves the leave-span recompute is dispatched.
|
| Task 8 review Minor: the "no debit before approved" proof is ONE continuous test on a
| single request — submit via the real POST /leave/requests, a real manager hop-1
| approve, then a real HR hop-2 approve — rather than two separate fixtures where hop 2
| seeded `manager_approved` directly (which only proves hop 2 in isolation, never that the
| SAME request carried no debit through hop 1 into hop 2).
*/

function leaveEffectOffice(): Office
{
    return Office::factory()->create(['ip_allowlist' => null]);
}

/** Mon-Fri 08:00-18:00 (60m break), Sat/Sun rest — same shape as SubmitLeaveRequestTest's. */
function leaveEffectOfficeWithSchedule(int $minutesPerLeaveDay = 480): Office
{
    $office = Office::factory()->create(['ip_allowlist' => null, 'minutes_per_leave_day' => $minutesPerLeaveDay]);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Office']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id, 'weekday' => $wd, 'is_rest' => $rest,
            'start_minute' => $rest ? null : 480, 'end_minute' => $rest ? null : 1080,
            'break_minutes' => $rest ? null : 60,
        ]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    return $office;
}

/** @return array{0: User, 1: Employee} */
function leaveEffectEmployeeWithUser(Office $office, array $overrides = []): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(array_merge(['current_office_id' => $office->id], $overrides));

    return [$user, $employee];
}

/** @return array{0: User, 1: Office} */
function leaveEffectHrAdmin(Office $office): array
{
    $hrUser = User::factory()->create();
    Employee::factory()->for($hrUser)->create(['current_office_id' => $office->id]);
    $hrUser->hrAdminOffices()->attach($office->id);

    return [$hrUser, $office];
}

function managerApprovedLeaveRequest(Employee $report, Employee $manager, User $managerUser, LeaveType $leaveType, array $detailOverrides = []): Request
{
    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);

    LeaveDetail::factory()->for($request)->create(array_merge([
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-04',
        'day_part' => 'full',
        'amount_minutes' => 960,
    ], $detailOverrides));

    return $request->fresh();
}

it('proves no debit through hop 1, then exactly one on hop 2, on the SAME request: submit -> manager approve (no row) -> HR approve (one row)', function (): void {
    Bus::fake();

    $office = leaveEffectOfficeWithSchedule(480);
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [$reportUser, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 2000,
    ]);

    // Submit for real: Monday 2026-08-03 through Tuesday 2026-08-04, 2 scheduled working
    // days at 480 minutes/day = 960 minutes, server-computed (never client-supplied).
    Sanctum::actingAs($reportUser);
    $submitted = $this->postJson('/api/v1/leave/requests', [
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-04',
        'day_part' => 'full',
        'note' => 'Family trip.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.state', 'pending')
        ->assertJsonPath('data.detail.amount_minutes', 960);

    $requestId = $submitted->json('data.id');

    expect(LeaveLedger::query()->where('entry_type', 'debit')->count())->toBe(0);

    // Hop 1 — the manager. Still no debit; state advances only to manager_approved.
    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/requests/{$requestId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'manager_approved');

    expect(LeaveLedger::query()->where('entry_type', 'debit')->count())->toBe(0);

    // Hop 2 — HR, the final hop. Exactly one debit row, on THIS SAME request.
    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$requestId}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.decided_by', $hrUser->id);

    $debits = LeaveLedger::query()->where('entry_type', 'debit')->get();
    expect($debits)->toHaveCount(1);

    $debit = $debits->first();
    expect($debit->source)->toBe('leave_taken')
        ->and($debit->minutes)->toBe(960)
        ->and($debit->request_id)->toBe($requestId)
        ->and($debit->created_by)->toBe($hrUser->id)
        ->and($debit->employee_id)->toBe($report->id)
        ->and($debit->leave_type_id)->toBe($leaveType->id);

    expect(LeaveBalances::forEmployee($report->fresh())[$leaveType->id])->toBe(2000 - 960);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2);

    $run = RecomputeRun::query()->where('trigger_type', RecomputeTrigger::Leave)->sole();
    expect($run->trigger_id)->toBe($requestId)
        ->and($run->pair_count)->toBe(2);
});

it('rolls back the whole approval when the balance is insufficient: state stays manager_approved, no ledger row', function (): void {
    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    // Only 500 minutes credited, but the request asks for 960 — insufficient.
    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 500,
    ]);

    $request = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'insufficient_leave_balance');

    $fresh = $request->fresh();
    expect($fresh->state)->toBe(RequestState::ManagerApproved)
        ->and($fresh->decided_by)->toBeNull()
        ->and($fresh->decided_at)->toBeNull();

    expect(LeaveLedger::query()->where('entry_type', 'debit')->count())->toBe(0);
    expect(LeaveBalances::forEmployee($report->fresh())[$leaveType->id] ?? 0)->toBe(500);
});

it('approves an event type (deducts_balance=false) with NO ledger row at all', function (): void {
    Bus::fake();

    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => false]);

    $request = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');

    expect(LeaveLedger::query()->count())->toBe(0);

    // Event types still get their span recomputed (compute prices the days) even though
    // there is no balance to touch.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2);
    expect(RecomputeRun::query()->where('trigger_type', RecomputeTrigger::Leave)->exists())->toBeTrue();
});

// overlapping leave ---------------------------------------------------------------------

it('refuses an approval whose span overlaps leave already approved, and debits only once', function (): void {
    // The leave-span recompute would otherwise resolve a schedule this bare office has no
    // template for; the overlap invariant is what this test is about.
    Bus::fake();

    // Nothing enforced this: leave_details has only a primary key, the schema has no
    // exclusion constraints, and neither submit nor approve checked. Two overlapping
    // requests both reached final approval, each writing a ledger debit, while the compute
    // path emitted one leave_with_pay line per day — charged twice, paid once.
    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 10000,   // plenty — this test is about overlap, not balance
    ]);

    $first = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType, [
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-07',
        'amount_minutes' => 2400,
    ]);
    $second = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType, [
        'start_date' => '2026-08-06',   // overlaps the 6th and 7th
        'end_date' => '2026-08-11',
        'amount_minutes' => 2400,
    ]);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$first->id}/approve")->assertOk();

    $this->postJson("/api/v1/requests/{$second->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'overlapping_leave')
        ->assertJsonPath('error.details.conflicting_request_id', $first->id);

    // The whole approval rolled back: state unchanged, and exactly one debit exists.
    expect($second->fresh()->state)->toBe(RequestState::ManagerApproved)
        ->and(LeaveLedger::query()->where('entry_type', 'debit')->count())->toBe(1);
});

it('allows a request that merely abuts an approved one', function (): void {
    // The leave-span recompute would otherwise resolve a schedule this bare office has no
    // template for; the overlap invariant is what this test is about.
    Bus::fake();

    // Ranges are closed on both ends, so 03-07 and 08-11 do not overlap. Off-by-one here
    // would refuse legitimate back-to-back leave.
    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => true]);

    LeaveLedger::factory()->for($report, 'employee')->create([
        'leave_type_id' => $leaveType->id,
        'entry_type' => 'credit',
        'minutes' => 10000,
    ]);

    $first = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType, [
        'start_date' => '2026-08-03', 'end_date' => '2026-08-07', 'amount_minutes' => 2400,
    ]);
    $second = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType, [
        'start_date' => '2026-08-08', 'end_date' => '2026-08-11', 'amount_minutes' => 1920,
    ]);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$first->id}/approve")->assertOk();
    $this->postJson("/api/v1/requests/{$second->id}/approve")->assertOk();

    expect(LeaveLedger::query()->where('entry_type', 'debit')->count())->toBe(2);
});

it('refuses an overlapping event type too, even though it has no balance to overdraw', function (): void {
    // The leave-span recompute would otherwise resolve a schedule this bare office has no
    // template for; the overlap invariant is what this test is about.
    Bus::fake();

    // The employee row lock used to be taken only inside the deducts_balance branch, so an
    // event type reached the overlap check unserialized. It is taken for every type now.
    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $report] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => false]);

    $first = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType);
    $second = managerApprovedLeaveRequest($report, $manager, $managerUser, $leaveType);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$first->id}/approve")->assertOk();
    $this->postJson("/api/v1/requests/{$second->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'overlapping_leave');
});

it('does not treat another employee\'s overlapping leave as a conflict', function (): void {
    // The leave-span recompute would otherwise resolve a schedule this bare office has no
    // template for; the overlap invariant is what this test is about.
    Bus::fake();

    $office = leaveEffectOffice();
    [$managerUser, $manager] = leaveEffectEmployeeWithUser($office);
    [, $first] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [, $second] = leaveEffectEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = leaveEffectHrAdmin($office);
    $leaveType = LeaveType::factory()->for($office, 'office')->create(['deducts_balance' => false]);

    $firstRequest = managerApprovedLeaveRequest($first, $manager, $managerUser, $leaveType);
    $secondRequest = managerApprovedLeaveRequest($second, $manager, $managerUser, $leaveType);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$firstRequest->id}/approve")->assertOk();
    $this->postJson("/api/v1/requests/{$secondRequest->id}/approve")->assertOk();
});

it('resolves the leave effect for RequestType::Leave via the factory', function (): void {
    $effect = app(App\Actions\Requests\RequestEffectFactory::class)->for(RequestType::Leave);

    expect($effect)->toBeInstanceOf(App\Domain\Requests\RequestEffect::class)
        ->and($effect)->toBeInstanceOf(App\Actions\Requests\Effects\LeaveEffect::class);
});
