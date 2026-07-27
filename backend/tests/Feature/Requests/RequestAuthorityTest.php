<?php

declare(strict_types=1);

use App\Domain\Requests\RequestAuthority;
use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/support.php';

/*
| The per-hop authority matrix: RequestAuthority::canDecide is state- and type-aware.
| Single-hop (attendance_adjustment) pending is manager-or-HR, same as M6a. Two-hop
| (leave) pending is manager ONLY (hop 1); two-hop manager_approved is HR ONLY (hop 2),
| and specifically not the same user who decided hop 1. Terminal states decide nothing.
*/

function requestFor(Employee $employee, RequestType $type, array $overrides = []): Request
{
    return Request::factory()->for($employee)->create(array_merge([
        'type' => $type,
        'state' => RequestState::Pending,
    ], $overrides));
}

// --- Single-hop (attendance_adjustment), pending: manager-or-HR, same as M6a. ---

it('lets the manager decide a single-hop pending request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::AttendanceAdjustment);

    expect(RequestAuthority::canDecide($manager->user, $request))->toBeTrue();
});

it('lets an HR admin decide a single-hop pending request', function (): void {
    [$hrUser, $office] = makeHrAdmin();
    $requester = Employee::factory()->create(['current_office_id' => $office->id]);
    $request = requestFor($requester, RequestType::AttendanceAdjustment);

    expect(RequestAuthority::canDecide($hrUser, $request))->toBeTrue();
});

it('never lets the requester decide their own single-hop pending request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::AttendanceAdjustment);

    expect(RequestAuthority::canDecide($report->user, $request))->toBeFalse();
});

it('does not let an unrelated stranger decide a single-hop pending request', function (): void {
    [, $report, $stranger] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::AttendanceAdjustment);

    expect(RequestAuthority::canDecide($stranger->user, $request))->toBeFalse();
});

// --- Two-hop (leave), pending: manager ONLY — not HR's turn yet. ---

it('lets the manager decide a two-hop pending (hop 1) request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave);

    expect(RequestAuthority::canDecide($manager->user, $request))->toBeTrue();
});

it('does NOT let HR decide a two-hop pending (hop 1) request — not their turn yet', function (): void {
    [$hrUser] = makeHrAdmin();
    $requester = Employee::factory()->create(['current_office_id' => $hrUser->hrAdminOffices()->first()->id]);
    $request = requestFor($requester, RequestType::Leave);

    expect(RequestAuthority::canDecide($hrUser, $request))->toBeFalse();
});

it('never lets the requester decide their own two-hop pending request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave);

    expect(RequestAuthority::canDecide($report->user, $request))->toBeFalse();
});

// --- Two-hop (leave), manager_approved: HR ONLY, and not the hop-1 approver. ---

it('lets HR decide a two-hop manager_approved (hop 2) request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $office = Office::factory()->create();
    $report->update(['current_office_id' => $office->id]);

    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = requestFor($report, RequestType::Leave, [
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $manager->user->id,
        'manager_decided_at' => now(),
    ]);

    expect(RequestAuthority::canDecide($hrUser, $request))->toBeTrue();
});

it('does NOT let the hop-1 manager also decide hop 2, even if they also administer HR for that office', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    // Make the manager an HR admin of the report's own office, so isHrOf($manager, ...)
    // would otherwise be true — the manager_decided_by guard must still block them.
    $manager->user->hrAdminOffices()->attach($report->current_office_id);

    $request = requestFor($report, RequestType::Leave, [
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $manager->user->id,
        'manager_decided_at' => now(),
    ]);

    expect(RequestAuthority::canDecide($manager->user, $request))->toBeFalse();
});

it('does NOT let a manager-only (non-HR) approver decide a two-hop manager_approved request — not their turn', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    // $manager has no HR-admin offices at all here — isManagerOf is true but isHrOf is
    // false, and hop 2 requires isHrOf regardless of the org chart.

    $request = requestFor($report, RequestType::Leave, [
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $manager->user->id,
        'manager_decided_at' => now(),
    ]);

    expect(RequestAuthority::canDecide($manager->user, $request))->toBeFalse();
});

it('never lets the requester decide their own two-hop manager_approved request', function (): void {
    [$manager, $report] = makeManagerReportStranger();

    $request = requestFor($report, RequestType::Leave, [
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $manager->user->id,
        'manager_decided_at' => now(),
    ]);

    expect(RequestAuthority::canDecide($report->user, $request))->toBeFalse();
});

// --- Terminal states: "any authority" (not false) — this is what lets the ACTION layer's
// separate isPending() check produce 409 (exists, already decided) for someone who had
// authority over some hop, instead of collapsing that case into 404 (never had authority)
// the moment the request is decided. A true stranger still 404s: they never had authority.

it('lets a manager-of and an HR-of the requester still "canDecide" true on a terminal request — so the action 409s, not 404s', function (RequestState $state): void {
    [$manager, $report, $stranger] = makeManagerReportStranger();
    $office = Office::factory()->create();
    $report->update(['current_office_id' => $office->id]);
    [$hrUser] = makeHrAdmin();
    $hrUser->hrAdminOffices()->attach($office->id);

    $request = requestFor($report, RequestType::Leave, [
        'state' => $state,
        // requests_rejected_note_check requires a note whenever state = 'rejected'.
        'decision_note' => $state === RequestState::Rejected ? 'Not eligible.' : null,
    ]);

    expect(RequestAuthority::canDecide($manager->user, $request))->toBeTrue()
        ->and(RequestAuthority::canDecide($hrUser, $request))->toBeTrue()
        ->and(RequestAuthority::canDecide($stranger->user, $request))->toBeFalse();
})->with([
    RequestState::Approved,
    RequestState::Rejected,
    RequestState::Cancelled,
]);

it('never lets the requester decide their own terminal-state request, even though they had no authority to begin with', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave, ['state' => RequestState::Approved]);

    expect(RequestAuthority::canDecide($report->user, $request))->toBeFalse();
});

// --- System admin: bypasses the hop rules entirely, any state — M6a took away their
// approval QUEUE (ApprovalQueues has no admin view), not their decision authority. ---

it('lets a system admin decide a single-hop pending request', function (): void {
    [, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::AttendanceAdjustment);

    $admin = User::factory()->create(['is_system_admin' => true]);

    expect(RequestAuthority::canDecide($admin, $request))->toBeTrue();
});

it('lets a system admin decide a two-hop pending (hop 1) request', function (): void {
    [, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave);

    $admin = User::factory()->create(['is_system_admin' => true]);

    expect(RequestAuthority::canDecide($admin, $request))->toBeTrue();
});

it('lets a system admin decide a two-hop manager_approved (hop 2) request', function (): void {
    [$manager, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave, [
        'state' => RequestState::ManagerApproved,
        'manager_decided_by' => $manager->user->id,
        'manager_decided_at' => now(),
    ]);

    $admin = User::factory()->create(['is_system_admin' => true]);

    expect(RequestAuthority::canDecide($admin, $request))->toBeTrue();
});

it('lets a system admin decide a terminal-state request too', function (): void {
    [, $report] = makeManagerReportStranger();
    $request = requestFor($report, RequestType::Leave, ['state' => RequestState::Approved]);

    $admin = User::factory()->create(['is_system_admin' => true]);

    expect(RequestAuthority::canDecide($admin, $request))->toBeTrue();
});
