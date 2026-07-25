<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Pay\PayRates;
use App\Models\PayRule;

/**
 * Builds a PayRates matrix from either side of the app boundary: the statutory floors
 * in config, or a configured pay_rules version and its dayRates.
 *
 * This lives outside App\Domain, not inside PayRates itself, because
 * tests/Arch/ConventionsTest.php's "the domain layer never reads configuration" rule
 * forbids config() (and the ORM reads fromVersion() needs) anywhere under App\Domain.
 * PayRates stays a pure holder; this factory is the one place the two boundary reads —
 * config('hris.pay_floors') and a PayRule model — happen.
 */
final class PayRatesFactory
{
    public static function statutory(): PayRates
    {
        /** @var array<string, mixed> $floors */
        $floors = config('hris.pay_floors');

        return new PayRates(
            worked: $floors['worked'],
            unworked: $floors['unworked'],
            overtimeOrdinaryBp: $floors['overtime_ordinary'],
            overtimePremiumBp: $floors['overtime_premium'],
            nightDiffBp: $floors['night_diff'],
        );
    }

    public static function fromVersion(PayRule $payRule): PayRates
    {
        $worked = [];
        $unworked = [];

        foreach ($payRule->dayRates as $dayRate) {
            $worked[$dayRate->day_type->value] = [$dayRate->worked_bp, $dayRate->worked_rest_bp];
            $unworked[$dayRate->day_type->value] = $dayRate->unworked_bp;
        }

        return new PayRates(
            worked: $worked,
            unworked: $unworked,
            overtimeOrdinaryBp: $payRule->overtime_ordinary_bp,
            overtimePremiumBp: $payRule->overtime_premium_bp,
            nightDiffBp: $payRule->night_diff_bp,
        );
    }
}
