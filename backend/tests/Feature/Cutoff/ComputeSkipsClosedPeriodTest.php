<?php

declare(strict_types=1);

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchDirection;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Compute/support.php';

uses(RefreshDatabase::class);

/*
| Task 7, single-process half: ComputeDailySummary is period-aware. A closed cutoff period
| is frozen — under the employee row lock the action already takes, if the (office, date)
| falls in a `closed` period the action returns WITHOUT deleting, recomputing, or creating
| anything. This is strictly stronger than RecomputeDay's old `status === 'locked'` read:
| it also refuses to create a brand-new summary into a closed period for a date that had no
| summary at all — the case a status read can never catch, because there is no row to read.
|
| The genuine close-vs-recompute race (that the check is under the SAME employee lock the
| close takes, so the two serialize) is proven separately in CloseVsRecomputeConcurrencyTest.
| This file proves the guard's behaviour deterministically in one process.
*/

it('leaves a locked summary in a closed period byte-identical', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-10'; // Monday, inside 2026-08-01..15

    CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
    ]);

    // A locked summary carrying a sentinel a real recompute of the punches below would
    // never reproduce (a punched 08:00-16:00 day computes 480 worked minutes).
    $summary = DailyAttendanceSummary::factory()->locked()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => $date,
        'worked_minutes' => 999,
    ]);

    // Punches that WOULD recompute to a different row if the guard were absent.
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    app(ComputeDailySummary::class)->execute($employee, $date);

    $fresh = $summary->fresh();
    expect($fresh->id)->toBe($summary->id)
        ->and($fresh->status)->toBe('locked')
        ->and($fresh->worked_minutes)->toBe(999);

    // Exactly one summary for this day — nothing deleted, nothing re-created.
    expect(DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', $date)
        ->count())->toBe(1);
});

it('creates nothing when computing a summary-less date in a closed period', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-11'; // Tuesday, inside the closed window, NO summary exists

    CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
    ]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $returned = app(ComputeDailySummary::class)->execute($employee, $date);

    // A closed period is frozen: a brand-new unlocked row must NOT appear for a date that
    // had no summary. The return is a null-object standing in for "nothing to compute".
    expect($returned->exists)->toBeFalse()
        ->and(DailyAttendanceSummary::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->count())->toBe(0);
});

it('computes normally for a date in an OPEN period (control)', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $rule = seedPayRule();

    $date = '2026-08-12'; // Wednesday

    // Same office, but the covering period is OPEN — the guard must be inert.
    CutoffPeriod::factory()->create([
        'office_id' => $office->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-15',
        'state' => 'open',
    ]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->exists)->toBeTrue()
        ->and($summary->status)->toBe('computed')
        ->and($summary->worked_minutes)->toBe(480)
        ->and($summary->rule_version_id)->toBe($rule->id);
});
