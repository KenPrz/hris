<?php

declare(strict_types=1);

use App\Domain\Pay\SummaryLineKind;
use App\Domain\Payroll\PayrollExport;
use App\Models\CutoffPeriod;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Office;
use App\Models\PayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reconciles summed line minutes and day totals against the raw rows for one employee', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-01-01',
        'base_rate_cents' => 61000,
    ]);
    $rule = PayRule::create([
        'effective_from' => '2026-01-01',
        'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    $day1 = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-05',
        'rule_version_id' => $rule->id,
        'worked_minutes' => 540,
        'late_minutes' => 10,
        'undertime_minutes' => 5,
        'unpaid_overtime_minutes' => 0,
    ]);
    $day1->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 480, 'applied_bp' => 10000]);
    $day1->lines()->create(['kind' => SummaryLineKind::OvertimeDay->value, 'minutes' => 60, 'applied_bp' => 12500]);

    $day2 = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-06',
        'rule_version_id' => $rule->id,
        'worked_minutes' => 540,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'unpaid_overtime_minutes' => 20,
    ]);
    $day2->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 540, 'applied_bp' => 10000]);

    $data = PayrollExport::for($period);

    expect($data->employees)->toHaveCount(1);
    $export = $data->employees[0];

    $rawLines = DailySummaryLine::query()->whereIn('summary_id', [$day1->id, $day2->id])->get();
    $expectedRegular = (int) $rawLines->where('kind', SummaryLineKind::RegularDay)->sum('minutes');
    $expectedOvertime = (int) $rawLines->where('kind', SummaryLineKind::OvertimeDay)->sum('minutes');

    $regularLine = collect($export->lines)->firstWhere('kind', SummaryLineKind::RegularDay);
    $overtimeLine = collect($export->lines)->firstWhere('kind', SummaryLineKind::OvertimeDay);

    $rawSummaries = DailyAttendanceSummary::query()->whereIn('id', [$day1->id, $day2->id])->get();

    expect($regularLine->minutes)->toBe($expectedRegular)
        ->and($regularLine->minutes)->toBe(1020)
        ->and($overtimeLine->minutes)->toBe($expectedOvertime)
        ->and($overtimeLine->minutes)->toBe(60)
        ->and($regularLine->ruleVersionId)->toBe($rule->id)
        ->and($export->workedMinutes)->toBe((int) $rawSummaries->sum('worked_minutes'))
        ->and($export->workedMinutes)->toBe(1080)
        ->and($export->lateMinutes)->toBe((int) $rawSummaries->sum('late_minutes'))
        ->and($export->lateMinutes)->toBe(10)
        ->and($export->undertimeMinutes)->toBe((int) $rawSummaries->sum('undertime_minutes'))
        ->and($export->undertimeMinutes)->toBe(5)
        ->and($export->unpaidOvertimeMinutes)->toBe((int) $rawSummaries->sum('unpaid_overtime_minutes'))
        ->and($export->unpaidOvertimeMinutes)->toBe(20);
});

it('keeps the same (kind, applied_bp) pair separate across different rule versions and groups leave_with_pay under a null version', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-01-01',
        'base_rate_cents' => 61000,
    ]);
    $ruleV1 = PayRule::create([
        'effective_from' => '2026-01-01',
        'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000,
    ]);
    $ruleV2 = PayRule::create([
        'effective_from' => '2026-07-08',
        'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    $dayV1 = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-05',
        'rule_version_id' => $ruleV1->id,
        'worked_minutes' => 480,
    ]);
    $dayV1->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 480, 'applied_bp' => 10000]);

    $dayV2 = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-10',
        'rule_version_id' => $ruleV2->id,
        'worked_minutes' => 300,
    ]);
    $dayV2->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 300, 'applied_bp' => 10000]);

    $dayLeave = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-12',
        'rule_version_id' => null,
        'worked_minutes' => 0,
    ]);
    $dayLeave->lines()->create(['kind' => SummaryLineKind::LeaveWithPay->value, 'minutes' => 540, 'applied_bp' => 10000]);

    $export = PayrollExport::for($period)->employees[0];

    $regularLines = collect($export->lines)
        ->filter(fn ($l): bool => $l->kind === SummaryLineKind::RegularDay)
        ->values();

    expect($regularLines)->toHaveCount(2)
        ->and($regularLines->firstWhere('ruleVersionId', $ruleV1->id)->minutes)->toBe(480)
        ->and($regularLines->firstWhere('ruleVersionId', $ruleV2->id)->minutes)->toBe(300);

    $leaveLine = collect($export->lines)->first(fn ($l): bool => $l->kind === SummaryLineKind::LeaveWithPay);

    expect($leaveLine)->not->toBeNull()
        ->and($leaveLine->ruleVersionId)->toBeNull()
        ->and($leaveLine->minutes)->toBe(540);
});

it('includes an in-period computed row, excludes an out-of-period row, and flags incomplete without inventing lines', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-01-01',
        'base_rate_cents' => 61000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    $inPeriod = DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-05',
        'status' => 'computed',
        'worked_minutes' => 480,
    ]);
    $inPeriod->lines()->create(['kind' => SummaryLineKind::RegularDay->value, 'minutes' => 480, 'applied_bp' => 10000]);

    // Out-of-period: must not contribute minutes or lines.
    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-20',
        'status' => 'computed',
        'worked_minutes' => 480,
    ]);

    // In-period but incomplete: contributes scalars, no invented lines.
    DailyAttendanceSummary::factory()->incomplete()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'date' => '2026-07-06',
        'status' => 'computed',
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'unpaid_overtime_minutes' => 0,
    ]);

    $export = PayrollExport::for($period)->employees[0];

    expect($export->hasIncompleteDays)->toBeTrue()
        ->and($export->workedMinutes)->toBe(480) // in-period computed (480) + incomplete (0); excludes out-of-period's 480
        ->and(collect($export->lines))->toHaveCount(1);
});

it('resolves a constant base rate to a single segment', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-01-01',
        'base_rate_cents' => 61000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id, 'office_id' => $office->id, 'date' => '2026-07-05',
    ]);
    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id, 'office_id' => $office->id, 'date' => '2026-07-10',
    ]);

    $export = PayrollExport::for($period)->employees[0];

    expect($export->baseRateCents)->toBe(61000)
        ->and($export->baseRateSegments)->toHaveCount(1)
        ->and($export->baseRateSegments[0]['base_rate_cents'])->toBe(61000);
});

it('lists two base-rate segments across a mid-period rate change, using the period-end effective rate', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-06-01',
        'base_rate_cents' => 50000,
    ]);
    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'effective_from' => '2026-07-08',
        'base_rate_cents' => 70000,
    ]);

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id, 'office_id' => $office->id, 'date' => '2026-07-05',
    ]);
    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employee->id, 'office_id' => $office->id, 'date' => '2026-07-10',
    ]);

    $export = PayrollExport::for($period)->employees[0];

    expect($export->baseRateCents)->toBe(70000)
        ->and($export->baseRateSegments)->toHaveCount(2)
        ->and($export->baseRateSegments[0]['base_rate_cents'])->toBe(50000)
        ->and($export->baseRateSegments[1]['base_rate_cents'])->toBe(70000);
});

it('produces one export per employee with an in-period summary and omits an employee with none', function (): void {
    $office = Office::factory()->create();
    $employeeA = Employee::factory()->create(['current_office_id' => $office->id]);
    $employeeB = Employee::factory()->create(['current_office_id' => $office->id]);
    $employeeC = Employee::factory()->create(['current_office_id' => $office->id]);

    foreach ([$employeeA, $employeeB, $employeeC] as $e) {
        EmploymentRecord::factory()->create([
            'employee_id' => $e->id,
            'office_id' => $office->id,
            'effective_from' => '2026-01-01',
            'base_rate_cents' => 61000,
        ]);
    }

    $period = CutoffPeriod::factory()->closed()->create([
        'office_id' => $office->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
    ]);

    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employeeA->id, 'office_id' => $office->id, 'date' => '2026-07-05',
    ]);
    DailyAttendanceSummary::factory()->create([
        'employee_id' => $employeeB->id, 'office_id' => $office->id, 'date' => '2026-07-06',
    ]);
    // employeeC has no in-period summary — must be omitted.

    $data = PayrollExport::for($period);

    expect($data->employees)->toHaveCount(2);

    $ids = collect($data->employees)->pluck('employeeId')->all();

    expect($ids)->toContain($employeeA->id)
        ->and($ids)->toContain($employeeB->id)
        ->and($ids)->not->toContain($employeeC->id);
});
