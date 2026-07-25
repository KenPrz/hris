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
     */
    public function __construct(
        public array $punches,
        public DayType $dayType,
        public bool $isRestDay,
        public int $scheduledMinutes,
        public int $scheduledStartMinute,
        public int $breakMinutes,
        public bool $isArt82Exempt,
        public PayRates $rates,
    ) {}
}
