<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * How an office handles the Art. 83 meal break. Configurable per office — see
 * docs/superpowers/specs/2026-07-23-hris-foundation-design.md for the decision and
 * what it costs.
 *
 * Assumed: a fixed unpaid break is deducted from any span over a threshold. A day is
 * two punches. Simple, and what most PH employers run — at the cost that an employee
 * who works through lunch is unpaid for it with no record either way.
 *
 * Explicit: employees punch out and back in, so the break is already absent from the
 * paired intervals and nothing is deducted. Working through lunch becomes visible and
 * payable, at the cost of doubled punch volume and more incomplete days.
 *
 * Takes its parameters as constructor arguments and never reads config, so it stays
 * testable without a booted container. The office column that selects it lands in M2.
 */
final readonly class MealBreakPolicy
{
    private function __construct(
        private int $breakMinutes,
        private int $appliesOverMinutes,
    ) {}

    public static function assumed(int $breakMinutes, int $appliesOverMinutes): self
    {
        if ($breakMinutes < 0) {
            throw new InvalidArgumentException("A meal break cannot be negative: {$breakMinutes} minutes.");
        }

        if ($appliesOverMinutes < 0) {
            throw new InvalidArgumentException("A meal-break threshold cannot be negative: {$appliesOverMinutes} minutes.");
        }

        return new self($breakMinutes, $appliesOverMinutes);
    }

    public static function explicit(): self
    {
        return new self(0, PHP_INT_MAX);
    }

    /**
     * Net worked minutes after the policy is applied.
     *
     * Clamps at zero rather than throwing: a threshold configured shorter than the break
     * is a misconfiguration to surface in reporting, not a reason to fail a payroll run
     * mid-computation.
     */
    public function netWorked(Minutes $gross): Minutes
    {
        if ($gross->value <= $this->appliesOverMinutes) {
            return $gross;
        }

        return Minutes::of(max(0, $gross->value - $this->breakMinutes));
    }
}
