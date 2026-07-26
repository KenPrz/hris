<?php

declare(strict_types=1);

use App\Actions\Holidays\CreateHoliday;
use App\Actions\Holidays\CreateHolidayInput;
use App\Actions\PayRules\CreatePayRule;
use App\Actions\PayRules\CreatePayRuleInput;
use App\Actions\Schedules\CreateScheduleOverride;
use App\Actions\Schedules\CreateScheduleOverrideInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Compute\RecomputeTrigger;
use App\Domain\Pay\DayType;
use App\Models\AttendanceLog;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use App\Models\RecomputeRun;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

/*
| M5b Task 6: wiring RecomputeRange into the config-change actions. A representative
| subset (Bus::fake) proves each trigger type enqueues correctly and that "no existing
| summary" stays a clean no-op; the end-to-end test (no fake, QUEUE_CONNECTION=sync per
| phpunit.xml) proves the whole loop for real — a holiday flip changes a computed day_type
| and its applied bp, while the underlying attendance_logs ledger is never touched.
|
| computeOffice/computeEmployee/seedPayRule/recordManualPunch come from support.php,
| shared with the other Compute feature tests. Note (mirrors ComputeOnWriteTest): under
| RefreshDatabase, Laravel's testing DatabaseTransactionsManager fires afterCommit
| callbacks once a nested transaction returns to the test's own wrapping level, so no
| special non-transactional handling is needed here either.
*/

it('creates a holiday recompute run for an office+date with an existing summary', function (): void {
    Bus::fake();

    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-21'; // Friday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $this->assertDatabaseHas('daily_attendance_summaries', ['employee_id' => $employee->id, 'date' => $date]);

    app(CreateHoliday::class)->execute(new CreateHolidayInput(
        officeId: $office->id,
        date: $date,
        dayType: DayType::SpecialNonWorking,
        name: 'Ninoy Aquino Day',
    ));

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);

    $run = RecomputeRun::query()->sole();
    expect($run->trigger_type)->toBe(RecomputeTrigger::Holiday)
        ->and($run->pair_count)->toBe(1);
});

it('is a clean no-op creating a holiday for a date with no existing summaries', function (): void {
    Bus::fake();

    $office = computeOffice();
    computeEmployee($office); // no punch, no summary

    app(CreateHoliday::class)->execute(new CreateHolidayInput(
        officeId: $office->id,
        date: '2026-08-21',
        dayType: DayType::SpecialNonWorking,
        name: 'Ninoy Aquino Day',
    ));

    Bus::assertNothingBatched();
    $this->assertDatabaseCount('recompute_runs', 0);
});

it('creates a pay_rule recompute run for a version effective F', function (): void {
    Bus::fake();

    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule('2026-01-01'); // an earlier version so the day below has SOME rule to price against

    $date = '2026-08-21';
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $this->assertDatabaseHas('daily_attendance_summaries', ['employee_id' => $employee->id, 'date' => $date]);

    $floors = config('hris.pay_floors');

    app(CreatePayRule::class)->execute(new CreatePayRuleInput(
        effectiveFrom: '2026-08-01',
        overtimeOrdinaryBp: $floors['overtime_ordinary'],
        overtimePremiumBp: $floors['overtime_premium'],
        nightDiffBp: $floors['night_diff'],
        dayRates: array_map(
            fn (string $dayType): array => [
                'day_type' => $dayType,
                'worked_bp' => $floors['worked'][$dayType][0],
                'worked_rest_bp' => $floors['worked'][$dayType][1],
                'unworked_bp' => $floors['unworked'][$dayType],
            ],
            array_map(fn (DayType $d): string => $d->value, DayType::cases()),
        ),
        note: null,
        actorId: null,
    ), $floors);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);

    $run = RecomputeRun::query()->sole();
    expect($run->trigger_type)->toBe(RecomputeTrigger::PayRule)
        ->and($run->pair_count)->toBe(1);
});

it('creates a schedule_override recompute run for an employee with an existing summary that date', function (): void {
    Bus::fake();

    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-21';
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $this->assertDatabaseHas('daily_attendance_summaries', ['employee_id' => $employee->id, 'date' => $date]);

    app(CreateScheduleOverride::class)->execute(new CreateScheduleOverrideInput(
        employeeId: $employee->id,
        date: $date,
        isRest: true,
        startMinute: null,
        endMinute: null,
        breakMinutes: null,
        note: 'Ad-hoc rest day',
        actorId: null,
    ));

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1);

    $run = RecomputeRun::query()->sole();
    expect($run->trigger_type)->toBe(RecomputeTrigger::ScheduleOverride)
        ->and($run->pair_count)->toBe(1);
});

it('flips a punched ordinary day to special_non_working end-to-end when its holiday is created, leaving the ledger untouched', function (): void {
    // Deliberately no Bus::fake(): QUEUE_CONNECTION=sync (phpunit.xml) runs the
    // RecomputeDay batch in-process, for a genuine before/after assertion.
    $manila = computeOffice();
    $cebu = computeOffice();
    $manilaEmployee = computeEmployee($manila);
    $cebuEmployee = computeEmployee($cebu);
    seedPayRule();

    $date = '2026-08-21'; // Friday

    recordManualPunch($manilaEmployee, $manila, $date, '08:00', PunchDirection::In);
    recordManualPunch($manilaEmployee, $manila, $date, '16:00', PunchDirection::Out);

    recordManualPunch($cebuEmployee, $cebu, $date, '08:00', PunchDirection::In);
    recordManualPunch($cebuEmployee, $cebu, $date, '16:00', PunchDirection::Out);

    $manilaSummaryBefore = DailyAttendanceSummary::query()
        ->where('employee_id', $manilaEmployee->id)->whereDate('date', $date)->sole();

    expect($manilaSummaryBefore->day_type)->toBe(DayType::Ordinary);

    $lineBefore = DailySummaryLine::query()->where('summary_id', $manilaSummaryBefore->id)->sole();
    expect($lineBefore->applied_bp)->toBe(10000);

    // Snapshot the Manila employee's raw punch ledger before the config change — this is
    // what must be BYTE-IDENTICAL afterward: the recompute may only ever touch the derived
    // summary, never the append-only attendance_logs rows themselves.
    $logsBefore = AttendanceLog::query()
        ->where('employee_id', $manilaEmployee->id)
        ->orderBy('punched_at')
        ->get(['id', 'punched_at', 'direction'])
        ->map(fn (AttendanceLog $log): array => [
            'id' => $log->id,
            'punched_at' => $log->punched_at->toIso8601String(),
            'direction' => $log->direction,
        ])
        ->all();

    expect($logsBefore)->toHaveCount(2);

    app(CreateHoliday::class)->execute(new CreateHolidayInput(
        officeId: $manila->id,
        date: $date,
        dayType: DayType::SpecialNonWorking,
        name: 'Ninoy Aquino Day',
    ));

    $manilaSummaryAfter = DailyAttendanceSummary::query()
        ->where('employee_id', $manilaEmployee->id)->whereDate('date', $date)->sole();

    expect($manilaSummaryAfter->day_type)->toBe(DayType::SpecialNonWorking);

    $lineAfter = DailySummaryLine::query()->where('summary_id', $manilaSummaryAfter->id)->sole();
    expect($lineAfter->applied_bp)->toBe(13000); // 100% -> 130%

    $cebuSummaryAfter = DailyAttendanceSummary::query()
        ->where('employee_id', $cebuEmployee->id)->whereDate('date', $date)->sole();

    expect($cebuSummaryAfter->day_type)->toBe(DayType::Ordinary);

    $run = RecomputeRun::query()->where('trigger_type', RecomputeTrigger::Holiday)->sole();
    expect($run->status)->toBe('completed')
        ->and($run->pair_count)->toBe(1);

    $logsAfter = AttendanceLog::query()
        ->where('employee_id', $manilaEmployee->id)
        ->orderBy('punched_at')
        ->get(['id', 'punched_at', 'direction'])
        ->map(fn (AttendanceLog $log): array => [
            'id' => $log->id,
            'punched_at' => $log->punched_at->toIso8601String(),
            'direction' => $log->direction,
        ])
        ->all();

    expect($logsAfter)->toBe($logsBefore);
});
