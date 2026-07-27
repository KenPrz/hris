<?php

declare(strict_types=1);

use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestEffectResolver;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\AttendanceAdjustmentDetail;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

require_once __DIR__.'/support.php';

/*
| ApproveRequest advances the two-hop machine ONE HOP at a time and fires the type effect
| ONLY on the transition into `approved` — a manager's hop-1 decision on a two-hop (leave)
| request must never fire it. Single-hop (attendance_adjustment) is unchanged: pending ->
| approved on one decision, effect fires there.
|
| LeaveEffect/leave_details don't exist yet (Tasks 6/8), so the two-hop assertions below
| bind a spy RequestEffectResolver into the container in place of the real
| RequestEffectFactory — no Mockery involved (RequestEffectFactory is final and cannot
| satisfy a mocked concrete type-hint; ApproveRequest depends on the RequestEffectResolver
| interface precisely so a plain object can stand in here) — and drive a bare `leave`
| Request (no leave_details row) through it.
*/

function twoHopOffice(): Office
{
    return Office::factory()->create(['ip_allowlist' => null]);
}

function twoHopEmployeeWithUser(Office $office, array $overrides = []): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->for($user)->create(array_merge(['current_office_id' => $office->id], $overrides));

    return [$user, $employee];
}

function pendingSingleHopRequest(Employee $employee): Request
{
    $request = Request::factory()->for($employee)->create([
        'type' => RequestType::AttendanceAdjustment,
        'state' => RequestState::Pending,
    ]);

    AttendanceAdjustmentDetail::factory()->for($request)->create([
        'operation' => \App\Domain\Attendance\AdjustmentOperation::Add,
        'target_log_id' => null,
        'direction' => \App\Domain\Attendance\PunchDirection::In,
        'punched_at' => '2026-07-20T08:00:00Z',
    ]);

    return $request->fresh();
}

function pendingLeaveRequest(Employee $employee): Request
{
    return Request::factory()->for($employee)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::Pending,
    ])->fresh();
}

/** Records every call, without touching Mockery (RequestEffectFactory is final; this
 *  binds RequestEffectResolver — the interface ApproveRequest actually depends on). */
function bindSpyEffectResolver(): object
{
    $spy = new class implements RequestEffectResolver
    {
        /** @var array<int, array{type: RequestType, request_id: string, approver: string}> */
        public array $calls = [];

        public function for(RequestType $type): RequestEffect
        {
            return new class($this, $type) implements RequestEffect
            {
                public function __construct(private object $resolver, private RequestType $type) {}

                public function applyOnApproval(Request $request, string $approverUserId): void
                {
                    $this->resolver->calls[] = [
                        'type' => $this->type,
                        'request_id' => $request->id,
                        'approver' => $approverUserId,
                    ];
                }
            };
        }
    };

    app()->instance(RequestEffectResolver::class, $spy);

    return $spy;
}

// --- Single-hop (attendance_adjustment): unchanged M6a behavior — one decision, effect
// fires there, through the REAL effect (no spy). ---

it('approves a single-hop request in one hop: pending -> approved, effect fires, decided_by set', function (): void {
    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingSingleHopRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.decided_by', $managerUser->id);

    $fresh = $request->fresh();
    expect($fresh->state)->toBe(RequestState::Approved)
        ->and($fresh->decided_by)->toBe($managerUser->id)
        ->and($fresh->manager_decided_by)->toBeNull()
        ->and(AttendanceLog::count())->toBe(1);
});

// --- Two-hop (leave), deferred effect: hop 1 (manager) advances state with NO effect;
// hop 2 (HR) fires the effect exactly once. ---

it('advances a two-hop request to manager_approved on hop 1, with NO effect and decided_by still null', function (): void {
    $spy = bindSpyEffectResolver();

    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'manager_approved');

    $fresh = $request->fresh();
    expect($fresh->state)->toBe(RequestState::ManagerApproved)
        ->and($fresh->manager_decided_by)->toBe($managerUser->id)
        ->and($fresh->manager_decided_at)->not->toBeNull()
        ->and($fresh->decided_by)->toBeNull()
        ->and($fresh->decided_at)->toBeNull()
        ->and($spy->calls)->toBe([]);
});

it('fires the effect exactly once on hop 2 (HR), completing a two-hop approval', function (): void {
    $spy = bindSpyEffectResolver();

    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")->assertOk();

    expect($spy->calls)->toBe([]);

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.decided_by', $hrUser->id);

    $fresh = $request->fresh();
    expect($fresh->state)->toBe(RequestState::Approved)
        ->and($fresh->decided_by)->toBe($hrUser->id)
        ->and($fresh->decided_at)->not->toBeNull()
        ->and($fresh->manager_decided_by)->toBe($managerUser->id)
        ->and($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['type'])->toBe(RequestType::Leave)
        ->and($spy->calls[0]['request_id'])->toBe($request->id)
        ->and($spy->calls[0]['approver'])->toBe($hrUser->id);
});

// --- Reject at either hop. ---

it('lets the manager reject a two-hop request at hop 1 (pending -> rejected) with a note', function (): void {
    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/requests/{$request->id}/reject", [
        'decision_note' => 'Insufficient leave credits.',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.decided_by', $managerUser->id);

    expect($request->fresh()->state)->toBe(RequestState::Rejected);
});

it('lets HR reject a two-hop request at hop 2 (manager_approved -> rejected) with a note', function (): void {
    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/requests/{$request->id}/reject", [
        'decision_note' => 'Blackout period.',
    ])
        ->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.decided_by', $hrUser->id);

    $fresh = $request->fresh();
    expect($fresh->state)->toBe(RequestState::Rejected)
        ->and($fresh->manager_decided_by)->toBe($managerUser->id);
});

it('400s a hop-2 reject with no decision_note', function (): void {
    $office = twoHopOffice();
    [$managerUser] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => null]);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/requests/{$request->id}/reject", [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    expect($request->fresh()->state)->toBe(RequestState::ManagerApproved);
});

// --- Wrong-hop / unauthorized / terminal. ---

it('404s when HR tries to decide a two-hop request still at hop 1 (pending) — not their turn yet', function (): void {
    $office = twoHopOffice();
    [, $report] = twoHopEmployeeWithUser($office);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($hrUser);

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::Pending);
});

it('404s when the hop-1 manager tries to decide hop 2 again, even if they also administer HR for that office', function (): void {
    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $managerUser->hrAdminOffices()->attach($office->id);

    $request = Request::factory()->for($report)->create([
        'type' => RequestType::Leave,
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $managerUser->id,
        'manager_decided_at' => now(),
    ]);

    Sanctum::actingAs($managerUser);

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');

    expect($request->fresh()->state)->toBe(RequestState::ManagerApproved);
});

it('409s a second approval attempt on an already-approved two-hop request', function (): void {
    $spy = bindSpyEffectResolver();

    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")->assertOk();

    Sanctum::actingAs($hrUser);
    $this->postJson("/api/v1/requests/{$request->id}/approve")->assertOk();

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_not_pending');

    expect($spy->calls)->toHaveCount(1);
});

it('409s an approval attempt on an already-rejected two-hop request', function (): void {
    $office = twoHopOffice();
    [$managerUser, $manager] = twoHopEmployeeWithUser($office);
    [, $report] = twoHopEmployeeWithUser($office, ['current_reports_to_id' => $manager->id]);
    $request = pendingLeaveRequest($report);

    Sanctum::actingAs($managerUser);
    $this->postJson("/api/v1/requests/{$request->id}/reject", ['decision_note' => 'No.'])->assertOk();

    $this->postJson("/api/v1/requests/{$request->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'request_not_pending');
});
