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
     * @param  bool  $onApprovedLeave  Whether this employee has an APPROVED full-day
     *                                 `leave` request covering this date (LeaveDayLookup,
     *                                 resolved by the caller — this class stays pure and
     *                                 never queries the database itself). Only consulted
     *                                 on the no-punches path; a day with punches prices
     *                                 from worked time regardless.
     */
    public function __construct(
        public array $punches,
        public DayType $dayType,
        public bool $isRestDay,
        public int $scheduledMinutes,
        public int $overtimeThresholdMinutes,
        public ?int $scheduledStartMinute,
        public int $breakMinutes,
        public bool $isArt82Exempt,
        public PayRates $rates,
        public bool $onApprovedLeave,
    ) {}
}
