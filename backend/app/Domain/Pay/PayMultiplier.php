<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * The DOLE premium-pay matrix, as pure integer arithmetic.
 *
 * The matrix itself — the statutory floors, or a configured pay_rules version — arrives
 * as a PayRates argument (see App\Support\PayRatesFactory); this class only knows how to
 * compose it: the art82 short-circuit, which base rate applies, how overtime and night
 * differential stack on top. Keeping the composition here and the numbers in PayRates is
 * what lets M5's compute engine read the effective pay_rules version while the M1 tests
 * keep proving the statutory floors, via PayRates::statutory() (through the factory),
 * unchanged.
 *
 * Every parameter is mandatory. $isArt82Exempt especially must never acquire a default:
 * requiring it by signature is how "Art. 82 gates every premium" is enforced — it is not
 * possible to compute a multiplier here without stating the employee's status.
 *
 * See docs/06-roadmap.md for the matrix in table form.
 */
final class PayMultiplier
{
    public static function forWorkedTime(
        DayType $dayType,
        bool $isRestDay,
        bool $isOvertime,
        bool $isNightDiff,
        bool $isArt82Exempt,
        PayRates $rates,
    ): BasisPoints {
        // Managerial employees and field personnel are outside Art. 82's coverage:
        // no overtime, no night differential, no holiday premium, no SIL.
        if ($isArt82Exempt) {
            return BasisPoints::one();
        }

        $rate = BasisPoints::of($rates->worked($dayType, $isRestDay));

        if ($isOvertime) {
            $rate = $rate->times(BasisPoints::of(self::overtimeFactor($dayType, $isRestDay, $rates)));
        }

        // Applied last, and multiplicatively: 10% of the hourly rate *for that hour*,
        // not 10% of base pay. Holiday overtime at 2am is 200% x 130% x 110% = 286%.
        if ($isNightDiff) {
            $rate = $rate->times(BasisPoints::of($rates->nightDiff()));
        }

        return $rate;
    }

    public static function forUnworkedDay(DayType $dayType, bool $isArt82Exempt, PayRates $rates): BasisPoints
    {
        if ($isArt82Exempt) {
            return BasisPoints::of(0);
        }

        return BasisPoints::of($rates->unworked($dayType));
    }

    private static function overtimeFactor(DayType $dayType, bool $isRestDay, PayRates $rates): int
    {
        $isPlainWorkingDay = $dayType === DayType::Ordinary || $dayType === DayType::SpecialWorking;

        return ($isPlainWorkingDay && ! $isRestDay)
            ? $rates->overtimeOrdinary()
            : $rates->overtimePremium();
    }
}
