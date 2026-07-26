<?php

declare(strict_types=1);

use App\Domain\Requests\RequestState;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
| The two scope-filtered VIEWS of the pending queue that replace the old combined
| /attendance/adjustments/pending: /team/approvals (a manager's direct reports) and
| /office/approvals (an HR admin's office members). Neither changes RequestAuthority —
| they are subsets of the same in-scope-minus-self set, just split by which hat the
| approver is wearing. See ApprovalQueues.
*/

uses(RefreshDatabase::class);

require_once __DIR__.'/support.php';

it('shows a manager only their direct reports pending requests', function (): void {
    // Build: office, manager (employee+user), a direct report, an unrelated employee.
    [$manager, $report, $stranger] = makeManagerReportStranger();   // support helper (see below)

    $mine = Request::factory()->for($report, 'employee')->create(['state' => RequestState::Pending]);
    Request::factory()->for($stranger, 'employee')->create(['state' => RequestState::Pending]);

    $res = actingAs($manager->user)->getJson('/api/v1/team/approvals')->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('shows an HR admin only their office\'s pending requests', function (): void {
    [$hrUser, $office] = makeHrAdmin();

    $officeWorker = Employee::factory()->create(['current_office_id' => $office->id]);
    $mine = Request::factory()->for($officeWorker, 'employee')->create(['state' => RequestState::Pending]);

    $otherOffice = Office::factory()->create();
    $outsider = Employee::factory()->create(['current_office_id' => $otherOffice->id]);
    Request::factory()->for($outsider, 'employee')->create(['state' => RequestState::Pending]);

    $res = actingAs($hrUser)->getJson('/api/v1/office/approvals')->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('excludes the actor\'s own request from both queues', function (): void {
    // Team: the manager's own pending request must never appear in /team/approvals.
    [$manager, $report] = makeManagerReportStranger();
    $ownTeamRequest = Request::factory()->for($manager, 'employee')->create(['state' => RequestState::Pending]);
    $reportRequest = Request::factory()->for($report, 'employee')->create(['state' => RequestState::Pending]);

    $teamRes = actingAs($manager->user)->getJson('/api/v1/team/approvals')->assertOk();

    expect(collect($teamRes->json('data'))->pluck('id')->all())
        ->toBe([$reportRequest->id])
        ->not->toContain($ownTeamRequest->id);

    // Office: the HR admin's own employee sits inside the office they administer, so their
    // own pending request would satisfy the office-membership filter unless explicitly
    // excluded — this is the case that actually exercises the self-exclusion clause.
    [$hrUser, $office] = makeHrAdmin();
    $hrEmployee = $hrUser->employee;
    $ownOfficeRequest = Request::factory()->for($hrEmployee, 'employee')->create(['state' => RequestState::Pending]);
    $officeWorker = Employee::factory()->create(['current_office_id' => $office->id]);
    $workerRequest = Request::factory()->for($officeWorker, 'employee')->create(['state' => RequestState::Pending]);

    $officeRes = actingAs($hrUser)->getJson('/api/v1/office/approvals')->assertOk();

    expect(collect($officeRes->json('data'))->pluck('id')->all())
        ->toBe([$workerRequest->id])
        ->not->toContain($ownOfficeRequest->id);
});

it('drops a decided request from both queues', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $decidedTeam = Request::factory()->for($report, 'employee')->create(['state' => RequestState::Approved]);

    $teamRes = actingAs($manager->user)->getJson('/api/v1/team/approvals')->assertOk();

    expect(collect($teamRes->json('data'))->pluck('id')->all())->not->toContain($decidedTeam->id);

    [$hrUser, $office] = makeHrAdmin();
    $officeWorker = Employee::factory()->create(['current_office_id' => $office->id]);
    $decidedOffice = Request::factory()->for($officeWorker, 'employee')->create([
        'state' => RequestState::Rejected,
        'decision_note' => 'Not enough evidence.',
    ]);

    $officeRes = actingAs($hrUser)->getJson('/api/v1/office/approvals')->assertOk();

    expect(collect($officeRes->json('data'))->pluck('id')->all())->not->toContain($decidedOffice->id);
});

it('lets a user who is both a manager and an HR admin see the same request in both queues', function (): void {
    [$hrUser, $office] = makeHrAdmin();
    $hrEmployee = $hrUser->employee;

    // A direct report of the HR admin who also happens to work in the office they
    // administer — the one request this satisfies both scope conditions for.
    $report = Employee::factory()->create([
        'current_office_id' => $office->id,
        'current_reports_to_id' => $hrEmployee->id,
    ]);
    $request = Request::factory()->for($report, 'employee')->create(['state' => RequestState::Pending]);

    $teamRes = actingAs($hrUser)->getJson('/api/v1/team/approvals')->assertOk();
    $officeRes = actingAs($hrUser)->getJson('/api/v1/office/approvals')->assertOk();

    expect(collect($teamRes->json('data'))->pluck('id')->all())->toBe([$request->id])
        ->and(collect($officeRes->json('data'))->pluck('id')->all())->toBe([$request->id]);
});
