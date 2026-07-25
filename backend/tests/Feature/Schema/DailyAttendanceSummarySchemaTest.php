<?php

declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Domain\Pay\SummaryLineKind;
use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use App\Models\Employee;
use App\Models\Office;
use App\Models\PayRule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function summaryEmployee(): Employee
{
    $office = Office::factory()->create();

    return Employee::factory()->create(['current_office_id' => $office->id]);
}

/** @return array<string, mixed> */
function summaryAttributes(Employee $employee, string $date = '2026-08-03'): array
{
    return [
        'employee_id' => $employee->id,
        'date' => $date,
        'day_type' => 'ordinary',
        'is_rest_day' => false,
        'scheduled_minutes' => 480,
        'is_art82_exempt' => false,
        'rule_version_id' => null,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'status' => 'computed',
        'is_incomplete' => false,
    ];
}

it('stores a summary with a line and casts enums', function (): void {
    $employee = summaryEmployee();

    $summary = DailyAttendanceSummary::create(summaryAttributes($employee));

    DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 480,
        'applied_bp' => 10000,
    ]);

    $fresh = $summary->fresh();
    $line = $fresh->lines()->sole();

    expect($fresh->day_type)->toBe(DayType::Ordinary)
        ->and($fresh->is_rest_day)->toBeFalse()
        ->and($fresh->is_art82_exempt)->toBeFalse()
        ->and($fresh->is_incomplete)->toBeFalse()
        ->and($fresh->scheduled_minutes)->toBe(480)
        ->and($fresh->worked_minutes)->toBe(480)
        ->and($fresh->date)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($line->kind)->toBe(SummaryLineKind::RegularDay)
        ->and($line->minutes)->toBe(480)
        ->and($line->applied_bp)->toBe(10000)
        ->and($line->summary->is($fresh))->toBeTrue();
});

it('rejects a duplicate (employee_id, date)', function (): void {
    $employee = summaryEmployee();

    DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-03'));

    expect(fn () => DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-03')))
        ->toThrow(QueryException::class);
});

it('rejects day_type values outside the enum', function (): void {
    $employee = summaryEmployee();

    $insert = fn (string $dayType) => DB::table('daily_attendance_summaries')->insert([
        'id' => (string) Str::uuid7(),
        'employee_id' => $employee->id,
        'date' => '2026-08-04',
        'day_type' => $dayType,
        'is_rest_day' => false,
        'scheduled_minutes' => 480,
        'is_art82_exempt' => false,
        'rule_version_id' => null,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'status' => 'computed',
        'is_incomplete' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $insert('nonsense'))->toThrow(QueryException::class);
});

it('rejects status values outside the enum', function (): void {
    $employee = summaryEmployee();

    $attributes = summaryAttributes($employee, '2026-08-05');
    $attributes['status'] = 'nonsense';

    expect(fn () => DailyAttendanceSummary::create($attributes))->toThrow(QueryException::class);
});

it('rejects kind values outside the enum', function (): void {
    $employee = summaryEmployee();
    $summary = DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-06'));

    expect(fn () => DB::table('daily_summary_lines')->insert([
        'id' => (string) Str::uuid7(),
        'summary_id' => $summary->id,
        'kind' => 'nonsense',
        'minutes' => 60,
        'applied_bp' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate (summary_id, kind)', function (): void {
    $employee = summaryEmployee();
    $summary = DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-07'));

    DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 480,
        'applied_bp' => 10000,
    ]);

    expect(fn () => DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 60,
        'applied_bp' => 13000,
    ]))->toThrow(QueryException::class);
});

it('rejects negative minute columns on the summary', function (): void {
    $employee = summaryEmployee();

    $attributes = summaryAttributes($employee, '2026-08-08');
    $attributes['worked_minutes'] = -1;

    expect(fn () => DailyAttendanceSummary::create($attributes))->toThrow(QueryException::class);
});

it('rejects a negative applied_bp on a line', function (): void {
    $employee = summaryEmployee();
    $summary = DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-09'));

    expect(fn () => DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 60,
        'applied_bp' => -1,
    ]))->toThrow(QueryException::class);
});

it('rejects a non-positive line minutes value', function (): void {
    $employee = summaryEmployee();
    $summary = DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-10'));

    expect(fn () => DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 0,
        'applied_bp' => 0,
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a pay_rules version a summary is stamped with (RESTRICT)', function (): void {
    $office = Office::factory()->create();
    $employee = Employee::factory()->create(['current_office_id' => $office->id]);
    $rule = PayRule::create(['effective_from' => '2026-01-01', 'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000, 'night_diff_bp' => 11000]);
    DailyAttendanceSummary::create(['employee_id' => $employee->id, 'date' => '2026-08-03',
        'day_type' => 'ordinary', 'is_rest_day' => false, 'scheduled_minutes' => 480,
        'is_art82_exempt' => false, 'rule_version_id' => $rule->id, 'worked_minutes' => 480,
        'late_minutes' => 0, 'undertime_minutes' => 0, 'status' => 'computed', 'is_incomplete' => false]);
    expect(fn () => $rule->delete())->toThrow(QueryException::class);
});

it('cascades line deletion when a summary is deleted', function (): void {
    $employee = summaryEmployee();
    $summary = DailyAttendanceSummary::create(summaryAttributes($employee, '2026-08-11'));

    DailySummaryLine::create([
        'summary_id' => $summary->id,
        'kind' => SummaryLineKind::RegularDay,
        'minutes' => 480,
        'applied_bp' => 10000,
    ]);

    $summary->delete();

    expect(DailySummaryLine::count())->toBe(0);
});
