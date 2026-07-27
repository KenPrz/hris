<?php

declare(strict_types=1);

use App\Actions\Cutoff\ReopenCutoff;
use App\Actions\Cutoff\ReopenCutoffInput;
use App\Domain\Cutoff\CutoffState;
use App\Exceptions\Domain\CutoffNotClosed;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| M7a Task 5: ReopenCutoff — the mirror image of CloseCutoff. Reopening is
| reason-required and loudly audited: `state` flips back to `open`, `closed_by`/
| `closed_at` are cleared, in-period `locked` summaries flip back to `computed`, and the
| reopen is recorded in the activity log carrying the reason.
*/

function reopenCutoff(string $periodId, string $reason, string $actorId): CutoffPeriod
{
    return (new ReopenCutoff)->execute(new ReopenCutoffInput($periodId, $reason, $actorId));
}

it('reopens a closed period, unlocks its in-period summaries, and audits the reason', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'closed_by' => $actor->id,
    ]);

    $inPeriod = DailyAttendanceSummary::factory()->locked()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
    ]);

    $reopened = reopenCutoff($period->id, 'Payroll found a missed correction.', $actor->id);

    expect($reopened->state)->toBe(CutoffState::Open)
        ->and($reopened->closed_by)->toBeNull()
        ->and($reopened->closed_at)->toBeNull()
        ->and($inPeriod->fresh()->status)->toBe('computed');

    $activity = Activity::query()
        ->where('subject_id', $period->id)
        ->where('description', 'cutoff_reopened')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject_type)->toBe(CutoffPeriod::class)
        ->and($activity->properties->get('reason'))->toBe('Payroll found a missed correction.');
});

it('refuses to reopen an open period', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();

    $period = CutoffPeriod::factory()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'state' => 'open',
    ]);

    try {
        reopenCutoff($period->id, 'Any reason', $actor->id);
        $this->fail('Expected CutoffNotClosed to be thrown.');
    } catch (CutoffNotClosed $e) {
        expect($e->httpStatus())->toBe(409)
            ->and($e->errorCode())->toBe('cutoff_not_closed')
            ->and($e->details()['cutoff_period_id'])->toBe($period->id);
    }
});

it('does not unlock a locked summary belonging to a different period/office', function (): void {
    $office = Office::factory()->create();
    $otherOffice = Office::factory()->create();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $otherEmployee = Employee::factory()->create(['current_office_id' => $otherOffice->id]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'closed_by' => $actor->id,
    ]);

    $inPeriod = DailyAttendanceSummary::factory()->locked()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
    ]);

    // Locked, but belongs to a different office/period entirely — closing a separate
    // cutoff, unrelated to $period. Must be left untouched by this reopen.
    $otherOfficeLocked = DailyAttendanceSummary::factory()->locked()->create([
        'employee_id' => $otherEmployee->id,
        'office_id' => $otherOffice->id,
        'date' => '2026-07-10',
    ]);

    // Locked, same employee/office, but outside $period's date window.
    $outOfPeriodLocked = DailyAttendanceSummary::factory()->locked()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-20',
    ]);

    reopenCutoff($period->id, 'Correcting an approved dispute.', $actor->id);

    expect($inPeriod->fresh()->status)->toBe('computed')
        ->and($otherOfficeLocked->fresh()->status)->toBe('locked')
        ->and($outOfPeriodLocked->fresh()->status)->toBe('locked');
});

it('rejects an empty reason defensively', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->create();

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'closed_by' => $actor->id,
    ]);

    expect(fn () => reopenCutoff($period->id, '', $actor->id))
        ->toThrow(InvalidArgumentException::class);

    expect($period->fresh()->state)->toBe(CutoffState::Closed);
});
