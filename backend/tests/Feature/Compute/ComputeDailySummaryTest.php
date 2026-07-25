<?php

declare(strict_types=1);

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Domain\Pay\DayType;
use App\Domain\Pay\SummaryLineKind;
use App\Domain\Schedule\Weekday;
use App\Models\DailySummaryLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\Holiday;
use App\Models\Office;
use App\Models\PayRule;
use App\Models\ShiftTemplate;
use App\Models\ShiftTemplateDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
| Task 5: ComputeDailySummary — wiring + idempotent persistence, real Postgres. The
| exhaustive bp matrix is Task 4's (DailyComputationTest, pure); this file proves the
| action resolves context correctly and upserts what DailyComputation hands back.
*/

/** Mon-Fri 08:00-18:00 (480-1080 minutes, 60m break), Sat/Sun rest — set as the office default. */
function computeOffice(): Office
{
    $office = Office::factory()->create(['timezone' => 'Asia/Manila']);

    $template = ShiftTemplate::create(['office_id' => $office->id, 'name' => 'Standard']);
    foreach (Weekday::cases() as $wd) {
        $rest = in_array($wd, [Weekday::Saturday, Weekday::Sunday], true);
        ShiftTemplateDay::create([
            'shift_template_id' => $template->id,
            'weekday' => $wd,
            'is_rest' => $rest,
            'start_minute' => $rest ? null : 480,
            'end_minute' => $rest ? null : 1080,
            'break_minutes' => $rest ? null : 60,
        ]);
    }
    $office->update(['default_shift_template_id' => $template->id]);

    return $office;
}

/** An employee with a resolvable EmploymentRecord (office/department/art82) effective from 2026-01-01. */
function computeEmployee(Office $office, bool $art82Exempt = false): Employee
{
    $department = Department::factory()->create(['office_id' => $office->id]);

    $employee = Employee::factory()->create([
        'organization_id' => $office->organization_id,
        'current_office_id' => $office->id,
        'current_department_id' => $department->id,
    ]);

    EmploymentRecord::factory()->create([
        'employee_id' => $employee->id,
        'office_id' => $office->id,
        'department_id' => $department->id,
        'effective_from' => '2026-01-01',
        'is_art82_exempt' => $art82Exempt,
    ]);

    return $employee;
}

/**
 * A pay-rules version at exactly the statutory floor, effective from 2026-01-01, with
 * per-day-type overrides applied on top (keyed by DayType::value, each value
 * [workedBp, workedRestBp, unworkedBp], any cell omitted keeps the floor).
 *
 * @param  array<string, array{0: int, 1: int, 2: int}>  $overrides
 */
function seedPayRule(string $effectiveFrom = '2026-01-01', array $overrides = []): PayRule
{
    $floors = config('hris.pay_floors');

    $rule = PayRule::create([
        'effective_from' => $effectiveFrom,
        'overtime_ordinary_bp' => $floors['overtime_ordinary'],
        'overtime_premium_bp' => $floors['overtime_premium'],
        'night_diff_bp' => $floors['night_diff'],
    ]);

    foreach (DayType::cases() as $dayType) {
        [$workedBp, $workedRestBp] = $floors['worked'][$dayType->value];
        $unworkedBp = $floors['unworked'][$dayType->value];

        if (isset($overrides[$dayType->value])) {
            [$workedBp, $workedRestBp, $unworkedBp] = $overrides[$dayType->value];
        }

        $rule->dayRates()->create([
            'day_type' => $dayType->value,
            'worked_bp' => $workedBp,
            'worked_rest_bp' => $workedRestBp,
            'unworked_bp' => $unworkedBp,
        ]);
    }

    return $rule;
}

/** Records a manual, self-verifying punch (no IP) at local $time on $date in $office's timezone. */
function recordManualPunch(Employee $employee, Office $office, string $date, string $time, PunchDirection $direction): void
{
    app(RecordPunch::class)->execute(new RecordPunchInput(
        employeeId: $employee->id,
        direction: $direction,
        source: PunchSource::Manual,
        punchedAt: Carbon::parse("{$date} {$time}", $office->timezone)->utc(),
        recordedBy: null,
        ipAddress: null,
        deviceId: null,
        geoLat: null,
        geoLng: null,
    ));
}

it('computes an ordinary punched 8h day: one regular_day line at the floor, rule_version_id set', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $rule = seedPayRule();

    $date = '2026-08-03'; // Monday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->status)->toBe('computed')
        ->and($summary->is_incomplete)->toBeFalse()
        ->and($summary->day_type)->toBe(DayType::Ordinary)
        ->and($summary->is_rest_day)->toBeFalse()
        ->and($summary->scheduled_minutes)->toBe(540)
        ->and($summary->worked_minutes)->toBe(480)
        ->and($summary->rule_version_id)->toBe($rule->id)
        ->and($summary->computed_at)->not->toBeNull();

    expect($summary->lines)->toHaveCount(1);
    $line = $summary->lines->first();
    expect($line->kind)->toBe(SummaryLineKind::RegularDay)
        ->and($line->minutes)->toBe(480)
        ->and($line->applied_bp)->toBe(10000);

    $this->assertDatabaseCount('daily_attendance_summaries', 1);
    $this->assertDatabaseCount('daily_summary_lines', 1);
});

it('prices a special_non_working holiday (Aug 21) at 13000bp', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-21'; // Friday
    Holiday::create([
        'office_id' => $office->id,
        'date' => $date,
        'day_type' => DayType::SpecialNonWorking,
        'name' => 'Ninoy Aquino Day',
    ]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->day_type)->toBe(DayType::SpecialNonWorking)
        ->and($summary->worked_minutes)->toBe(480)
        ->and($summary->lines)->toHaveCount(1);

    $line = $summary->lines->first();
    expect($line->kind)->toBe(SummaryLineKind::RegularDay)
        ->and($line->applied_bp)->toBe(13000);
});

it('is idempotent: recomputing the same day yields one summary and identical lines, never duplicates', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03';
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $first = app(ComputeDailySummary::class)->execute($employee, $date);
    $second = app(ComputeDailySummary::class)->execute($employee, $date);

    $this->assertDatabaseCount('daily_attendance_summaries', 1);
    $this->assertDatabaseCount('daily_summary_lines', 1);

    expect($second->id)->not->toBe($first->id) // delete-then-insert: a fresh row each time
        ->and($second->worked_minutes)->toBe($first->worked_minutes)
        ->and($second->lines)->toHaveCount(1);

    $firstLine = $first->lines->first();
    $secondLine = $second->lines->first();
    expect($secondLine->kind)->toBe($firstLine->kind)
        ->and($secondLine->minutes)->toBe($firstLine->minutes)
        ->and($secondLine->applied_bp)->toBe($firstLine->applied_bp);
});

it('marks a single-punch day incomplete: zero worked minutes, no lines, no rule_version_id', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-04'; // Tuesday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    // no matching out-punch

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->is_incomplete)->toBeTrue()
        ->and($summary->worked_minutes)->toBe(0)
        ->and($summary->lines)->toHaveCount(0)
        ->and($summary->rule_version_id)->toBeNull();
});

it('reads applied_bp from a custom pay_rules version, not a hardcoded constant', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    // regular_holiday worked at 250% (25000bp) instead of the 20000bp floor.
    $rule = seedPayRule('2026-01-01', [
        'regular_holiday' => [25000, 30000, 10000],
    ]);

    $date = '2026-08-10'; // Monday
    Holiday::create([
        'office_id' => $office->id,
        'date' => $date,
        'day_type' => DayType::RegularHoliday,
        'name' => 'Custom holiday',
    ]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '16:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->rule_version_id)->toBe($rule->id)
        ->and($summary->lines)->toHaveCount(1);

    $line = $summary->lines->first();
    expect($line->kind)->toBe(SummaryLineKind::RegularDay)
        ->and($line->applied_bp)->toBe(25000);
});

it('prices a rest day worked past 8h at rest-day base + rest-day OT, with zero lateness', function (): void {
    // Saturday: computeOffice() marks it is_rest, so ScheduleResolver reports
    // scheduledMinutes 0 / startMinute null for this date — the seam this whole fix is
    // about. 08:00-18:00 (no schedule-driven break on a rest day) = 600m worked: the
    // OT threshold is the statutory 8h floor (480), NOT the (zero) scheduled minutes.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-08'; // Saturday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '18:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->is_rest_day)->toBeTrue()
        ->and($summary->scheduled_minutes)->toBe(0)
        ->and($summary->worked_minutes)->toBe(600)
        ->and($summary->late_minutes)->toBe(0)
        ->and($summary->undertime_minutes)->toBe(0)
        ->and($summary->lines)->toHaveCount(2);

    $byKind = $summary->lines->keyBy(fn (DailySummaryLine $l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480)
        ->and($byKind['regular_day']->applied_bp)->toBe(13000)
        ->and($byKind['overtime_day']->minutes)->toBe(120)
        ->and($byKind['overtime_day']->applied_bp)->toBe(16900);
});

it('collapses every line to 10000bp for an Art. 82-exempt employee, even with overtime', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office, art82Exempt: true);
    seedPayRule();

    $date = '2026-08-03'; // Monday
    // 08:00 - 19:00 gross (660m), net of the 60m break = 600m against a 540m schedule:
    // 540m regular + 60m overtime, both of which would normally price differently.
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '19:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->is_art82_exempt)->toBeTrue()
        ->and($summary->worked_minutes)->toBe(600)
        ->and($summary->lines)->toHaveCount(2);

    foreach ($summary->lines as $line) {
        expect($line->applied_bp)->toBe(10000);
    }

    $kinds = $summary->lines->pluck('kind')->map(fn (SummaryLineKind $k) => $k->value)->sort()->values()->all();
    expect($kinds)->toBe(['overtime_day', 'regular_day']);
});
