<?php

declare(strict_types=1);

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Pay\DayType;
use App\Jobs\RecomputeDay;
use App\Models\DailyAttendanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

/*
| Task 3: RecomputeDay — a queued, batchable job that re-runs ComputeDailySummary for
| one (employee, date), except it must never touch a `locked` summary (a locked cutoff
| period's numbers are frozen — M7). computeOffice/computeEmployee/seedPayRule/
| recordManualPunch come from support.php, shared with ComputeDailySummaryTest.
*/

it('skips a locked summary: the row is left completely untouched', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03'; // Monday, ordinary

    $locked = DailyAttendanceSummary::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'office_id' => $office->id,
        'day_type' => DayType::Ordinary,
        'is_rest_day' => false,
        'scheduled_minutes' => 540,
        'is_art82_exempt' => false,
        'rule_version_id' => null,
        'worked_minutes' => 999,
        'late_minutes' => 999,
        'undertime_minutes' => 999,
        'is_incomplete' => false,
        'status' => 'locked',
        'computed_at' => now(),
    ]);

    (new RecomputeDay($employee->id, $locked->date->toDateString()))
        ->handle(app(ComputeDailySummary::class));

    $fresh = $locked->fresh();
    expect($fresh->status)->toBe('locked')
        ->and($fresh->worked_minutes)->toBe(999)
        ->and($fresh->late_minutes)->toBe(999)
        ->and($fresh->undertime_minutes)->toBe(999)
        ->and($fresh->id)->toBe($locked->id); // never deleted/replaced

    $this->assertDatabaseCount('daily_attendance_summaries', 1);
});

it('recomputes a non-locked day: a holiday added out-of-band is reflected after the job runs', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03'; // Monday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $before = app(ComputeDailySummary::class)->execute($employee, $date);
    expect($before->status)->toBe('computed')
        ->and($before->day_type)->toBe(DayType::Ordinary);

    // Out-of-band change: a holiday lands on this date after the original compute (e.g.
    // an admin adds it later). The stale summary still says Ordinary until recomputed.
    \App\Models\Holiday::create([
        'office_id' => $office->id,
        'date' => $date,
        'day_type' => DayType::RegularHoliday,
        'name' => 'Out-of-band holiday',
    ]);

    (new RecomputeDay($employee->id, $date))->handle(app(ComputeDailySummary::class));

    $after = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();

    expect($after->day_type)->toBe(DayType::RegularHoliday)
        ->and($after->status)->toBe('computed')
        ->and($after->id)->not->toBe($before->id); // delete-then-insert, same as a direct compute

    $this->assertDatabaseCount('daily_attendance_summaries', 1);
});

it('is idempotent: running the job twice yields one summary, identical', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03';
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    (new RecomputeDay($employee->id, $date))->handle(app(ComputeDailySummary::class));
    $first = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();

    (new RecomputeDay($employee->id, $date))->handle(app(ComputeDailySummary::class));
    $second = DailyAttendanceSummary::query()
        ->where('employee_id', $employee->id)->whereDate('date', $date)->first();

    $this->assertDatabaseCount('daily_attendance_summaries', 1);
    expect($second->worked_minutes)->toBe($first->worked_minutes)
        ->and($second->day_type)->toBe($first->day_type)
        ->and($second->status)->toBe($first->status);
});

it('a missing employee id is a no-op, not a crash', function (): void {
    $missingEmployeeId = (string) Str::uuid7();

    $run = fn () => (new RecomputeDay($missingEmployeeId, '2026-08-03'))
        ->handle(app(ComputeDailySummary::class));

    expect($run)->not->toThrow(Throwable::class);
    $this->assertDatabaseCount('daily_attendance_summaries', 0);
});
