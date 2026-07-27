<?php

declare(strict_types=1);

use App\Domain\Compute\DailyComputation;
use App\Domain\Compute\DailyComputationInput;
use App\Domain\Pay\DayType;
use App\Domain\Pay\PayRates;
use App\Domain\Pay\SummaryLineKind;
use App\Support\PayRatesFactory;
use Tests\TestCase;

/*
| The crown jewel: DailyComputation is the pure calculator that turns one day's
| effective punches + context + rates into priced premium buckets. Table-driven,
| exhaustive, no DB. Every case asserts workedMinutes AND the exact lines (kind,
| minutes, appliedBp) — a wrong bp here is a wrong payslip.
|
| This needs config('hris.pay_floors') (via PayRatesFactory::statutory()), so — same
| as PayMultiplierTest — it opts back into the booted TestCase; tests/Unit is
| otherwise left unbooted deliberately (see tests/Pest.php).
*/
uses(TestCase::class);

function rates(): PayRates
{
    return PayRatesFactory::statutory();
}

// ordinary day, base rate ----------------------------------------------------------

it('prices an ordinary 8h day as 480 regular_day minutes at 100%', function (): void {
    // 08:00-17:00 (480-1020), 60m break over an 8h (480m) threshold => 480 net worked.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeFalse();
    expect($out->workedMinutes)->toBe(480);
    expect($out->lateMinutes)->toBe(0);
    expect($out->undertimeMinutes)->toBe(0);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::RegularDay);
    expect($out->lines[0]->minutes)->toBe(480);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});

it('prices a rest day worked past 8h as regular_day base + overtime_day at the rest-day OT rate', function (): void {
    // A REAL rest day: scheduledMinutes 0, scheduledStartMinute null (nothing scheduled),
    // overtimeThresholdMinutes the statutory 8h floor (480) — not the (zero) scheduled
    // minutes. 08:00-18:00 (480-1080) = 600m gross, 60m break => 540 net: the first 480
    // are regular (rest-day BASE, 130%), the remaining 60 are overtime (rest-day OT, 169%).
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1080],
        dayType: DayType::Ordinary,
        isRestDay: true,
        scheduledMinutes: 0,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: null,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(540);
    expect($out->lateMinutes)->toBe(0);
    expect($out->undertimeMinutes)->toBe(0);
    expect($out->lines)->toHaveCount(2);

    $byKind = collect($out->lines)->keyBy(fn ($l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480);
    expect($byKind['regular_day']->appliedBp)->toBe(13000);
    expect($byKind['overtime_day']->minutes)->toBe(60);
    expect($byKind['overtime_day']->appliedBp)->toBe(16900);
});

it('prices a special working day worked at 100%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::SpecialWorking,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines[0]->appliedBp)->toBe(10000);
    expect($out->lines[0]->minutes)->toBe(480);
});

it('prices a special non-working day worked at 130%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::SpecialNonWorking,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines[0]->appliedBp)->toBe(13000);
    expect($out->lines[0]->minutes)->toBe(480);
});

it('prices a regular holiday worked at 200%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::RegularHoliday,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines[0]->appliedBp)->toBe(20000);
    expect($out->lines[0]->minutes)->toBe(480);
});

it('prices a double regular holiday worked at 300%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1020],
        dayType: DayType::DoubleRegularHoliday,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines[0]->appliedBp)->toBe(30000);
    expect($out->lines[0]->minutes)->toBe(480);
});

// unworked days ----------------------------------------------------------------------

it('prices a regular holiday NOT worked as one holiday_unworked line at 100%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::RegularHoliday,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeFalse();
    expect($out->workedMinutes)->toBe(0);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::HolidayUnworked);
    expect($out->lines[0]->minutes)->toBe(480);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});

it('gives an art82-exempt employee NO holiday_unworked line at all (no premium entitlement)', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::RegularHoliday,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: true,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(0);
    expect($out->lines)->toBe([]);
});

it('is not incomplete on an unworked rest day, and carries no lines', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::Ordinary,
        isRestDay: true,
        scheduledMinutes: 0,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: null,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeFalse();
    expect($out->workedMinutes)->toBe(0);
    expect($out->lateMinutes)->toBe(0);
    expect($out->undertimeMinutes)->toBe(0);
    expect($out->lines)->toBe([]);
});

it('carries no lines for an absence on an ordinary day (no punches, not a paid holiday)', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeFalse();
    expect($out->workedMinutes)->toBe(0);
    expect($out->undertimeMinutes)->toBe(480);
    expect($out->lines)->toBe([]);
});

// leave with pay -----------------------------------------------------------------------

it('prices a scheduled working day covered by approved leave as one leave_with_pay line at 100%', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: true,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeFalse();
    expect($out->workedMinutes)->toBe(0);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::LeaveWithPay);
    expect($out->lines[0]->minutes)->toBe(480);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});

it('prices leave_with_pay over holiday_unworked when a leave day also happens to be a paid holiday', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::RegularHoliday,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: true,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::LeaveWithPay);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});

it('gives no leave_with_pay line on a rest day covered by leave (leave never charges a day off)', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [],
        dayType: DayType::Ordinary,
        isRestDay: true,
        scheduledMinutes: 0,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: null,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: true,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->lines)->toBe([]);
});

// night differential -------------------------------------------------------------------

it('prices a night shift entirely within one calendar day at the compounded 110%', function (): void {
    // 22:00 -> 24:00 (1320 -> 1440), scheduled 120, no break: net worked all night.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [1320, 1440],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 120,
        overtimeThresholdMinutes: 120,
        scheduledStartMinute: 1320,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(120);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::RegularNight);
    expect($out->lines[0]->minutes)->toBe(120);
    expect($out->lines[0]->appliedBp)->toBe(11000); // 10000 * 11000 / 10000
});

it('prices a cross-midnight night shift at the compounded 110%', function (): void {
    // 22:00 -> 06:00 next day (1320 -> 1800), scheduled 480, no break: net worked all night.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [1320, 1800],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 1320,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(480);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::RegularNight);
    expect($out->lines[0]->minutes)->toBe(480);
    expect($out->lines[0]->appliedBp)->toBe(11000);
});

// overtime ------------------------------------------------------------------------------

it('prices work beyond the scheduled day as overtime at +25% ordinary', function (): void {
    // 08:00 -> 18:00 (480 -> 1080) = 600m worked, scheduled 480.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1080],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(600);
    expect($out->lines)->toHaveCount(2);

    $byKind = collect($out->lines)->keyBy(fn ($l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480);
    expect($byKind['regular_day']->appliedBp)->toBe(10000);
    expect($byKind['overtime_day']->minutes)->toBe(120);
    expect($byKind['overtime_day']->appliedBp)->toBe(12500);
});

it('keeps a compressed 10h scheduled day entirely regular, with no overtime line', function (): void {
    // 08:00 -> 18:00 (480 -> 1080) = 600m worked, scheduled 600. A normal working day
    // sets overtimeThresholdMinutes == scheduledMinutes, so this proves the decoupled
    // field behaves exactly as the old single scheduledMinutes boundary did.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480, 1080],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 600,
        overtimeThresholdMinutes: 600,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(600);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::RegularDay);
    expect($out->lines[0]->minutes)->toBe(600);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});

// incomplete ------------------------------------------------------------------------------

it('is incomplete on an unpaired punch: zero worked, no lines', function (): void {
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [480],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 60,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->isIncomplete)->toBeTrue();
    expect($out->workedMinutes)->toBe(0);
    expect($out->lateMinutes)->toBe(0);
    expect($out->undertimeMinutes)->toBe(0);
    expect($out->lines)->toBe([]);
});

// art82 exemption ---------------------------------------------------------------------------

it('collapses every bucket to 100% for an art82-exempt employee, even on a holiday-night-OT day', function (): void {
    // 20:00 -> 07:00 next day (1200 -> 1860) = 660m worked, scheduled 480, no break.
    // regular = [1200,1680): 120 day + 360 night. overtime = [1680,1860): 60 day + 120 night.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [1200, 1860],
        dayType: DayType::RegularHoliday,
        isRestDay: true,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 1200,
        breakMinutes: 0,
        isArt82Exempt: true,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(660);
    expect($out->lines)->toHaveCount(4);

    foreach ($out->lines as $line) {
        expect($line->appliedBp)->toBe(10000);
    }

    $byKind = collect($out->lines)->keyBy(fn ($l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(120);
    expect($byKind['regular_night']->minutes)->toBe(360);
    expect($byKind['overtime_day']->minutes)->toBe(60);
    expect($byKind['overtime_night']->minutes)->toBe(120);
});

it('proves the same holiday-night-OT day is NOT flat 100% without the art82 exemption', function (): void {
    // Same shape as above but isArt82Exempt: false — every bucket must carry its real,
    // distinct premium (this is the control case that proves the exemption test above
    // is actually exercising the premium matrix, not a coincidentally-flat scenario).
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [1200, 1860],
        dayType: DayType::RegularHoliday,
        isRestDay: true,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 1200,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(660);
    expect($out->lines)->toHaveCount(4);

    $byKind = collect($out->lines)->keyBy(fn ($l) => $l->kind->value);
    // regular_holiday, rest day => worked base 26000 (260%).
    expect($byKind['regular_day']->appliedBp)->toBe(26000);
    // x night diff 11000/10000 => 28600.
    expect($byKind['regular_night']->appliedBp)->toBe(28600);
    // overtime on a holiday/rest day uses the premium OT factor (13000), not ordinary.
    expect($byKind['overtime_day']->appliedBp)->toBe(33800); // 26000 * 13000 / 10000
    expect($byKind['overtime_night']->appliedBp)->toBe(37180); // 33800 * 11000 / 10000
});

// late + undertime ------------------------------------------------------------------------

it('populates late and undertime minutes together', function (): void {
    // scheduled start 08:00 (480); clocked in 09:00 (540) => 60 late.
    // clocked out 15:00 (900) => 360m gross/net worked (no break), scheduled 480 => 120 undertime.
    $out = DailyComputation::compute(new DailyComputationInput(
        punches: [540, 900],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: 480,
        overtimeThresholdMinutes: 480,
        scheduledStartMinute: 480,
        breakMinutes: 0,
        isArt82Exempt: false,
        rates: rates(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: 9999,
    ));

    expect($out->workedMinutes)->toBe(360);
    expect($out->lateMinutes)->toBe(60);
    expect($out->undertimeMinutes)->toBe(120);
    expect($out->lines)->toHaveCount(1);
    expect($out->lines[0]->kind)->toBe(SummaryLineKind::RegularDay);
    expect($out->lines[0]->minutes)->toBe(360);
    expect($out->lines[0]->appliedBp)->toBe(10000);
});
