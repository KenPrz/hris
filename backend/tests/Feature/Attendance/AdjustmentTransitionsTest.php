<?php

declare(strict_types=1);

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Requests\RequestState;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceAnnulment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
| Approve / reject / cancel transitions on the shared requests spine. The matrix proves,
| in order: authority (404 for out-of-scope or self-approval) comes before pending-ness
| (409 for an already-decided request) comes before the effect (422 if the target adjust
| ment turns out invalid at approval time, rolling back the whole approval).
*/

function officeForAdjustments(): Office
{
    // ip_allowlist null so RecordPunch's verifier never rejects the adjustment-sourced
    // punch it writes (source: adjustment supplies no IP/geo at all) — same fixture
    // shape as ApplyAdjustmentTest's adjustmentEmployee() helper.
    return Office::factory()->create(['ip_allowlist' => null]);
}

function employeeWithUser(Office $office, array $overrides = []): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(array_merge(['current_office_id' => $office->id], $overrides));

    return [$user, $employee];
}

function pendingAddRequest(Employee $employee): Request
{
    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => PunchDirection::In,
        'punched_at' => '2026-07-20T08:00:00Z',
    ]);

    return $request->fresh();
}

function pendingVoidRequest(Employee $employee, string $targetLogId): Request
{
    $request = Request::factory()->for($employee)->create();
    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => AdjustmentOperation::Void,
        'target_log_id' => $targetLogId,
        'direction' => null,
        'punched_at' => null,
    ]);

    return $request->fresh();
}

it('lets a manager approve their report\'s pending add adjustment', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $report] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);

    $request = pendingAddRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.decided_by', $managerUser->id);

    expect(AttendanceLog::count())->toBe(1)
        ->and($request->fresh()->state)->toBe(RequestState::Approved);
});

it('lets an HR admin over the requester\'s office approve', function (): void {
    $office = officeForAdjustments();
    [$hrUser] = employeeWithUser($office);
    $hrUser->hrAdminOffices()->attach($office->id);

    [, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');
});

it('lets a system admin approve any pending request', function (): void {
    $office = officeForAdjustments();
    [, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    $admin = User::factory()->create(['is_system_admin' => true]);
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');
});

it('404s when the requester tries to approve their own request', function (): void {
    $office = officeForAdjustments();
    [$requesterUser, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($requesterUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending)
        ->and(AttendanceLog::count())->toBe(0);
});

it('404s when an out-of-scope employee tries to approve', function (): void {
    $office = officeForAdjustments();
    [, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    $otherOffice = officeForAdjustments();
    [$unrelatedUser] = employeeWithUser($otherOffice);

    Sanctum::actingAs($unrelatedUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('lets an authorized approver reject with a decision note', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $report] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingAddRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/reject", [
        'decision_note' => 'Not enough evidence.',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.decision_note', 'Not enough evidence.')
        ->assertJsonPath('data.decided_by', $managerUser->id);

    expect(AttendanceLog::count())->toBe(0);
});

it('400s a reject with no decision_note', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $report] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingAddRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/reject", [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('404s when an out-of-scope employee tries to reject WITH a valid note — existence must not leak', function (): void {
    $office = officeForAdjustments();
    [, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    $otherOffice = officeForAdjustments();
    [$unrelatedUser] = employeeWithUser($otherOffice);

    Sanctum::actingAs($unrelatedUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/reject", [
        'decision_note' => 'Not enough evidence.',
    ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('404s (never 400) when an out-of-scope employee rejects with an EMPTY body — the existence-leak case', function (): void {
    $office = officeForAdjustments();
    [, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    $otherOffice = officeForAdjustments();
    [$unrelatedUser] = employeeWithUser($otherOffice);

    Sanctum::actingAs($unrelatedUser);

    // Before the fix, FormRequest validation of decision_note ran BEFORE the action's
    // authority check, so an out-of-scope prober sending an empty body got 400
    // validation_failed — proof the request exists — instead of the 404 an unauthorized
    // actor must always see, indistinguishable from a nonexistent request.
    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/reject", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('409s a reject of an already-decided (approved) request even with no note', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $requester] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")->assertOk();

    // Already approved, and no decision_note in the body: pending-ness (409) still wins
    // over note-validation (400) — the ordering is authority -> pending -> note.
    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/reject", [])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_not_pending');
});

it('lets the requester cancel their own pending request', function (): void {
    $office = officeForAdjustments();
    [$requesterUser, $requester] = employeeWithUser($office);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($requesterUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');
});

it('404s when someone other than the requester tries to cancel', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $requester] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);

    $request = pendingAddRequest($requester);

    // The manager is IN scope (could approve/reject) but is not the requester, so cancel
    // must still 404 — cancellation is requester-only, narrower than approval authority.
    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/cancel")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('409s a cancel after the request is already approved', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [$requesterUser, $requester] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")->assertOk();

    Sanctum::actingAs($requesterUser);
    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_not_pending');
});

it('409s a second approval with no double effect — exactly one punch, not two', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $requester] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingAddRequest($requester);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")->assertOk();
    expect(AttendanceLog::count())->toBe(1);

    $this->postJson("/api/v1/attendance/adjustments/{$request->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_not_pending');

    // The state guard, not a second write, is what stopped this — exactly one punch.
    expect(AttendanceLog::count())->toBe(1);
});

it('422s an approval whose target was already annulled by a prior approval, rolling back the whole approval', function (): void {
    $office = officeForAdjustments();
    [$managerUser, $manager] = employeeWithUser($office);
    [, $requester] = employeeWithUser($office, ['current_reports_to_id' => $manager->id]);

    $target = AttendanceLog::factory()->create(['employee_id' => $requester->id]);

    $firstVoid = pendingVoidRequest($requester, $target->id);
    $secondVoid = pendingVoidRequest($requester, $target->id);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/attendance/adjustments/{$firstVoid->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved');

    $this->postJson("/api/v1/attendance/adjustments/{$secondVoid->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_adjustment_target');

    // The approval rolled back completely: the second request is still pending, undecided
    // — proving the effect and the state write share one transaction.
    $fresh = $secondVoid->fresh();
    expect($fresh->state)->toBe(RequestState::Pending)
        ->and($fresh->decided_by)->toBeNull()
        ->and($fresh->decided_at)->toBeNull()
        ->and(AttendanceAnnulment::count())->toBe(1);
});
