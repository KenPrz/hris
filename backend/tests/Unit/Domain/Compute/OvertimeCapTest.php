<?php

declare(strict_types=1);

use App\Domain\Compute\ComputedDay;
use App\Domain\Compute\DailyComputation;
use App\Domain\Compute\DailyComputationInput;
use App\Domain\Pay\DayType;
use App\Domain\Pay\SummaryLineKind;
use App\Support\PayRatesFactory;
use Tests\TestCase;

/*
| M6c: the overtime pre-authorization cap. DailyComputation pays
| min(actual_worked_overtime, approved_overtime); worked minutes beyond the approved
| ceiling become a bare `unpaidOvertimeMinutes` scalar (shown, never priced). Pure — no
| DB. art82-exempt short-circuits the cap entirely (no overtime premium to withhold).
|
| Needs config('hris.pay_floors') via PayRatesFactory::statutory(), so it opts into the
| booted TestCase like DailyComputationTest. Helpers are cap-prefixed to avoid colliding
| with DailyComputationTest's top-level rates()/etc when the whole Unit suite loads in one
| process.
*/
uses(TestCase::class);

/**
 * Builds a pure ordinary-day input: continuous work from $start (no break, so net == gross),
 * an $scheduled-minute regular/overtime boundary, and $approvedOvertime pre-authorized.
 */
function capInput(int $workedMinutes, int $scheduled, int $approvedOvertime, bool $isArt82Exempt = false, int $start = 480): DailyComputationInput
{
    return new DailyComputationInput(
        punches: [$start, $start + $workedMinutes],
        dayType: DayType::Ordinary,
        isRestDay: false,
        scheduledMinutes: $scheduled,
        overtimeThresholdMinutes: $scheduled,
        scheduledStartMinute: $start,
        breakMinutes: 0,
        mealBreakAppliesOverMinutes: 300,
        isArt82Exempt: $isArt82Exempt,
        rates: PayRatesFactory::statutory(),
        onApprovedLeave: false,
        approvedOvertimeMinutes: $approvedOvertime,
    );
}

/** Sums minutes over the day's overtime lines (overtime_day + overtime_night). */
function capOvertimeMinutes(ComputedDay $day): int
{
    return array_sum(array_map(
        fn ($l): int => $l->minutes,
        array_filter($day->lines, fn ($l): bool => in_array($l->kind, [SummaryLineKind::OvertimeDay, SummaryLineKind::OvertimeNight], true)),
    ));
}

/** Sums minutes over the day's regular lines (regular_day + regular_night). */
function capRegularMinutes(ComputedDay $day): int
{
    return array_sum(array_map(
        fn ($l): int => $l->minutes,
        array_filter($day->lines, fn ($l): bool => in_array($l->kind, [SummaryLineKind::RegularDay, SummaryLineKind::RegularNight], true)),
    ));
}

it('pays all overtime when it is within the approved cap', function (): void {
    // 10h worked, 8h scheduled => 120 OT; 120 approved => all 120 paid, 0 excess.
    $day = DailyComputation::compute(capInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 120));

    expect($day->unpaidOvertimeMinutes)->toBe(0);
    expect(capOvertimeMinutes($day))->toBe(120);
    expect(capRegularMinutes($day))->toBe(480);
});

it('caps paid overtime at the approved amount and marks the rest unpaid', function (): void {
    // 10h worked, 8h scheduled => 120 OT; only 60 approved => 60 paid OT, 60 excess.
    $day = DailyComputation::compute(capInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 60));

    expect($day->unpaidOvertimeMinutes)->toBe(60);
    expect(capOvertimeMinutes($day))->toBe(60);
    expect(capRegularMinutes($day))->toBe(480);
});

it('treats all overtime as unpaid excess when nothing is approved', function (): void {
    // 10h worked, 8h scheduled => 120 OT; 0 approved => 0 paid OT, 120 excess; regular still paid.
    $day = DailyComputation::compute(capInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 0));

    expect($day->unpaidOvertimeMinutes)->toBe(120);
    expect(capOvertimeMinutes($day))->toBe(0);
    expect(capRegularMinutes($day))->toBe(480);
    // No overtime line at all when nothing is paid.
    expect($day->lines)->toHaveCount(1);
    expect($day->lines[0]->kind)->toBe(SummaryLineKind::RegularDay);
});

it('never caps an art82-exempt employee', function (): void {
    // exempt, 10h worked, 8h scheduled, 0 approved => no excess, OT minutes still attributed.
    $day = DailyComputation::compute(capInput(workedMinutes: 600, scheduled: 480, approvedOvertime: 0, isArt82Exempt: true));

    expect($day->unpaidOvertimeMinutes)->toBe(0);
    expect(capOvertimeMinutes($day))->toBe(120);
    expect(capRegularMinutes($day))->toBe(480);
});

it('day/night-splits the PAID overtime portion correctly when a cap applies', function (): void {
    // Start 13:00 (780), work 11h straight to 24:00 (1440), scheduled 480, approved 120.
    //   regular  = running [0,480)   => 13:00-21:00 (780-1260), all daytime => regular_day 480.
    //   paid OT  = running [480,600) => 21:00-23:00 (1260-1380): 60 day (21-22) + 60 night (22-23).
    //   excess   = running [600,660) => 23:00-24:00 (1380-1440) => 60 unpaid, day/night moot.
    $day = DailyComputation::compute(capInput(workedMinutes: 660, scheduled: 480, approvedOvertime: 120, start: 780));

    expect($day->unpaidOvertimeMinutes)->toBe(60);
    expect(capOvertimeMinutes($day))->toBe(120); // == approved, split across the 22:00 boundary

    $byKind = collect($day->lines)->keyBy(fn ($l) => $l->kind->value);
    expect($byKind['regular_day']->minutes)->toBe(480);
    expect($byKind['overtime_day']->minutes)->toBe(60);
    expect($byKind['overtime_night']->minutes)->toBe(60);
});
