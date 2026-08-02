<?php

declare(strict_types=1);

use App\Actions\Compute\ComputeDailySummary;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Pay\DayType;
use App\Domain\Pay\SummaryLineKind;
use App\Models\DailySummaryLine;
use App\Models\Holiday;
use App\Models\OvertimeDetail;
use App\Models\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/support.php';

uses(RefreshDatabase::class);

/*
| Task 5: ComputeDailySummary — wiring + idempotent persistence, real Postgres. The
| exhaustive bp matrix is Task 4's (DailyComputationTest, pure); this file proves the
| action resolves context correctly and upserts what DailyComputation hands back.
|
| computeOffice/computeEmployee/seedPayRule/recordManualPunch live in support.php,
| shared with RecomputeDayTest.
*/

it('computes an ordinary punched 8h day: one regular_day line at the floor, rule_version_id set', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    $rule = seedPayRule();

    $date = '2026-08-03'; // Monday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

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

it('snapshots the resolved office_id on the summary', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03'; // Monday
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->office_id)->toBe($office->id);
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
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

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
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

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
    recordManualPunch($employee, $office, $date, '17:00', PunchDirection::Out);

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
    // Under M6c's cap, the 120 rest-day OT minutes only price if pre-authorized — this test
    // is about rest-day OT PRICING, so authorize them and keep the pricing assertion intact.
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => 'approved',
        'decision_note' => null,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => $date, 'minutes' => 120]);

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

it('persists unpaid_overtime_minutes for worked overtime nobody pre-authorized (strict default)', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03'; // Monday: scheduled 540, 60m break.
    // 08:00-19:00 gross 660, net 600. The boundary is the statutory 480, not the 540-minute
    // schedule, so that is 480 regular + 120 overtime. No approved OT => all 120 unpaid.
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '19:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->worked_minutes)->toBe(600)
        ->and($summary->unpaid_overtime_minutes)->toBe(120)
        ->and($summary->lines)->toHaveCount(1); // regular_day only — the overtime went unpaid.
    expect($summary->lines->first()->kind)->toBe(SummaryLineKind::RegularDay);
    expect($summary->lines->first()->minutes)->toBe(480);

    $this->assertDatabaseHas('daily_attendance_summaries', [
        'id' => $summary->id,
        'unpaid_overtime_minutes' => 120,
    ]);
});

it('pays worked overtime up to an approved pre-authorization and leaves the rest unpaid', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03';
    // 120 min of overtime worked (as above); only 30 approved => 30 paid overtime_day, 90 unpaid.
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => 'approved',
        'decision_note' => null,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => $date, 'minutes' => 30]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '19:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->unpaid_overtime_minutes)->toBe(90);

    $byKind = $summary->lines->keyBy(fn (DailySummaryLine $l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480)
        ->and($byKind['overtime_day']->minutes)->toBe(30)
        ->and($byKind['overtime_day']->applied_bp)->toBe(12500);
});

it('collapses every line to 10000bp for an Art. 82-exempt employee, even with overtime', function (): void {
    $office = computeOffice();
    $employee = computeEmployee($office, art82Exempt: true);
    seedPayRule();

    $date = '2026-08-03'; // Monday
    // 08:00 - 19:00 gross (660m), net of the 60m break = 600m against the statutory 480m
    // boundary: 480m regular + 120m overtime, both of which would normally price
    // differently. Never capped — an exempt employee has no overtime premium to withhold.
    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '19:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->is_art82_exempt)->toBeTrue()
        ->and($summary->worked_minutes)->toBe(600)
        ->and($summary->unpaid_overtime_minutes)->toBe(0) // exempt is never capped
        ->and($summary->lines)->toHaveCount(2);

    foreach ($summary->lines as $line) {
        expect($line->applied_bp)->toBe(10000);
    }

    $kinds = $summary->lines->pluck('kind')->map(fn (SummaryLineKind $k) => $k->value)->sort()->values()->all();
    expect($kinds)->toBe(['overtime_day', 'regular_day']);
});

it('prices the ninth hour of a nine-hour scheduled day as overtime', function (): void {
    // The shared template is 08:00-18:00 with a 60-minute break => 540 scheduled minutes.
    // The overtime boundary used to BE that 540, so an employee's ninth hour was priced as
    // regular time at 100%. Art. 83 fixes the normal working day at eight hours whatever a
    // shift template says, so the boundary is 480 and the ninth hour is overtime.
    $office = computeOffice();
    $employee = computeEmployee($office);
    seedPayRule();

    $date = '2026-08-03'; // Monday: scheduled 540, 60m break.

    // Authorize the 60 overtime minutes, else the strict cap makes them unpaid excess and
    // this test would prove the boundary moved without proving anything got PAID for it.
    $request = Request::factory()->create([
        'type' => 'overtime',
        'employee_id' => $employee->id,
        'state' => 'approved',
        'decision_note' => null,
    ]);
    OvertimeDetail::query()->create(['request_id' => $request->id, 'date' => $date, 'minutes' => 60]);

    recordManualPunch($employee, $office, $date, '08:00', PunchDirection::In);
    recordManualPunch($employee, $office, $date, '18:00', PunchDirection::Out);

    $summary = app(ComputeDailySummary::class)->execute($employee, $date);

    expect($summary->scheduled_minutes)->toBe(540)
        ->and($summary->worked_minutes)->toBe(540)
        ->and($summary->undertime_minutes)->toBe(0)
        ->and($summary->unpaid_overtime_minutes)->toBe(0)
        ->and($summary->lines)->toHaveCount(2);

    $byKind = $summary->lines->keyBy(fn (DailySummaryLine $l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480)
        ->and($byKind['regular_day']->applied_bp)->toBe(10000)
        ->and($byKind['overtime_day']->minutes)->toBe(60)
        ->and($byKind['overtime_day']->applied_bp)->toBe(12500);
});
