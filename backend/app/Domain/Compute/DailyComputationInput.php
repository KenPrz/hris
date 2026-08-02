<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Domain\Pay\DayType;
use App\Domain\Pay\PayRates;

/**
 * Everything DailyComputation needs to price one employee's business day. Pure — no
 * models, no DB, no config. The caller (Task 5's ComputeDailySummary action) is
 * responsible for resolving all of this from the schedule, the holiday calendar, the
 * effective pay_rules version, and EffectivePunches::forDate().
 */
final readonly class DailyComputationInput
{
    /**
     * @param  list<int>  $punches  Ascending minutes from the business day's local
     *                              midnight, from EffectivePunches::forDate() — may
     *                              exceed 1440 for a shift that crosses midnight.
     * @param  int  $scheduledMinutes  The actual scheduled length of the day — 0 on a
     *                                 rest day. Used for undertime and the persisted
     *                                 snapshot; NOT the regular/overtime boundary.
     * @param  int  $overtimeThresholdMinutes  The regular↔overtime boundary. Equal to
     *                                         $scheduledMinutes on a normal working day,
     *                                         but the statutory 8h (480) on a rest day
     *                                         (or any day scheduledMinutes is 0) — the
     *                                         first 8 worked hours are still regular
     *                                         (rest-day base), not overtime.
     * @param  ?int  $scheduledStartMinute  Null when there is no scheduled start (a rest
     *                                      day) — late is always 0 in that case, never a
     *                                      phantom lateness against minute 0.
     * @param  int  $mealBreakAppliesOverMinutes  The gross span above which the assumed
     *   meal break is deducted — the statutory 300 (five hours), from
     *   config('hris.meal_break.applies_over_minutes'). Deliberately NOT $scheduledMinutes,
     *   which is what it used to be: deducting only above the scheduled day makes worked
     *   minutes non-monotonic in the out-punch, so leaving earlier can pay more than
     *   staying.
     * @param  int  $leaveMinutes  Paid-leave minutes attributable to this date
     *   (LeaveDayLookup::paidMinutesFor, resolved by the caller — this class stays pure and
     *   never queries the database itself). 0 when none.
     *
     *   Was a bool, which made a half-day pay like a full day: leave_details.day_part was
     *   written at submit and read by nothing downstream. Consulted on BOTH the punched and
     *   unpunched paths now — a half-day leave whose other half is worked must pay for both,
     *   and previously emitted no leave line at all because punches existed. The credited
     *   portion is capped at the unworked remainder of the scheduled day, so leave and
     *   worked time can never sum past it.
     * @param  int  $approvedOvertimeMinutes  Overtime minutes pre-authorized for this
     *   date (OvertimeAuthorizationLookup, resolved by the caller). The paid-overtime
     *   ceiling is overtimeThresholdMinutes + this; worked minutes beyond it are unpaid
     *   excess. 0 when nothing is approved (the strict model). Not consulted for an
     *   art82-exempt employee, who has no overtime premium to withhold.
     */
    public function __construct(
        public array $punches,
        public DayType $dayType,
        public bool $isRestDay,
        public int $scheduledMinutes,
        public int $overtimeThresholdMinutes,
        public ?int $scheduledStartMinute,
        public int $breakMinutes,
        public int $mealBreakAppliesOverMinutes,
        public bool $isArt82Exempt,
        public PayRates $rates,
        public int $leaveMinutes,
        public int $approvedOvertimeMinutes,
    ) {}
}
