<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * The DOLE premium-pay matrix, as a plain value object: which rate applies for time
 * worked, which for a day left unworked, and the three scalars (overtime factors, night
 * differential) that compound on top.
 *
 * Pure and framework-agnostic — every value arrives through the constructor. It does not
 * know, and must not know, whether it was built from the statutory floors or from a
 * configured pay_rules version; that decision belongs to whoever builds one (see
 * App\Support\PayRatesFactory), not to this class. This is what lets PayMultiplier take
 * a PayRates instead of hardcoding the same matrix as private constants.
 */
final class PayRates
{
    /**
     * @param  array<string, array{0: int, 1: int}>  $worked  [dayType value => [notRestBp, restBp]]
     * @param  array<string, int>  $unworked  [dayType value => bp]
     */
    public function __construct(
        private readonly array $worked,
        private readonly array $unworked,
        private readonly int $overtimeOrdinaryBp,
        private readonly int $overtimePremiumBp,
        private readonly int $nightDiffBp,
    ) {}

    public function worked(DayType $dayType, bool $isRestDay): int
    {
        return $this->worked[$dayType->value][$isRestDay ? 1 : 0];
    }

    public function unworked(DayType $dayType): int
    {
        return $this->unworked[$dayType->value];
    }

    public function overtimeOrdinary(): int
    {
        return $this->overtimeOrdinaryBp;
    }

    public function overtimePremium(): int
    {
        return $this->overtimePremiumBp;
    }

    public function nightDiff(): int
    {
        return $this->nightDiffBp;
    }
}
