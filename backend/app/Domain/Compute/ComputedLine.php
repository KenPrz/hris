<?php

declare(strict_types=1);

namespace App\Domain\Compute;

use App\Domain\Pay\SummaryLineKind;

/**
 * One non-zero premium bucket for the day: a kind, its minutes, and the basis-point
 * multiplier that applies to them. A day with nothing but on-schedule ordinary hours
 * carries no lines at all — see DailyComputation.
 */
final readonly class ComputedLine
{
    public function __construct(
        public SummaryLineKind $kind,
        public int $minutes,
        public int $appliedBp,
    ) {}
}
