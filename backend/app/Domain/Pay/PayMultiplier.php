<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * The DOLE premium-pay matrix, as pure integer arithmetic.
 *
 * These are the **statutory floors** from the Labor Code as amended (Arts. 86-94). They
 * are code, not data, precisely because they are law: an admin configures rates in the
 * pay_rules table (M4) and those rates are validated against these, so a misconfigured
 * 100% on a regular holiday is refused at the boundary rather than discovered at payday.
 *
 * Every parameter is mandatory. $isArt82Exempt especially must never acquire a default:
 * requiring it by signature is how "Art. 82 gates every premium" is enforced — it is not
 * possible to compute a multiplier here without stating the employee's status.
 *
 * See docs/06-roadmap.md for the matrix in table form.
 */
final class PayMultiplier
{
    /**
     * Base rate for time worked, keyed by [day type][is rest day].
     *
     * Note special non-working on a rest day is 150%, not 130% x 130% = 169%. DOLE
     * specifies a flat rate. This is why the table is a lookup and not a formula, and
     * the single most likely thing to get wrong by deriving it.
     *
     * @var array<string, array{false: int, true: int}>
     */
    private const array WORKED_BASE = [
        DayType::Ordinary->value => [false => 10_000, true => 13_000],
        DayType::SpecialWorking->value => [false => 10_000, true => 13_000],
        DayType::SpecialNonWorking->value => [false => 13_000, true => 15_000],
        DayType::RegularHoliday->value => [false => 20_000, true => 26_000],
        DayType::DoubleRegularHoliday->value => [false => 30_000, true => 39_000],
    ];

    /**
     * What a day pays when it is not worked. A regular holiday is paid regardless; a
     * special non-working day is "no work, no pay" absent a more generous company policy.
     *
     * @var array<string, int>
     */
    private const array UNWORKED = [
        DayType::Ordinary->value => 0,
        DayType::SpecialWorking->value => 0,
        DayType::SpecialNonWorking->value => 0,
        DayType::RegularHoliday->value => 10_000,
        DayType::DoubleRegularHoliday->value => 20_000,
    ];

    /** Overtime on an ordinary working day is +25%; everywhere else it is +30%. */
    private const int OVERTIME_ORDINARY = 12_500;

    private const int OVERTIME_PREMIUM = 13_000;

    /** Night differential, 22:00-06:00 (Art. 86). Compounds on whatever rate applies. */
    private const int NIGHT_DIFFERENTIAL = 11_000;

    public static function forWorkedTime(
        DayType $dayType,
        bool $isRestDay,
        bool $isOvertime,
        bool $isNightDiff,
        bool $isArt82Exempt,
    ): BasisPoints {
        // Managerial employees and field personnel are outside Art. 82's coverage:
        // no overtime, no night differential, no holiday premium, no SIL.
        if ($isArt82Exempt) {
            return BasisPoints::one();
        }

        $rate = BasisPoints::of(self::WORKED_BASE[$dayType->value][$isRestDay]);

        if ($isOvertime) {
            $rate = $rate->times(BasisPoints::of(self::overtimeFactor($dayType, $isRestDay)));
        }

        // Applied last, and multiplicatively: 10% of the hourly rate *for that hour*,
        // not 10% of base pay. Holiday overtime at 2am is 200% x 130% x 110% = 286%.
        if ($isNightDiff) {
            $rate = $rate->times(BasisPoints::of(self::NIGHT_DIFFERENTIAL));
        }

        return $rate;
    }

    public static function forUnworkedDay(DayType $dayType, bool $isArt82Exempt): BasisPoints
    {
        if ($isArt82Exempt) {
            return BasisPoints::of(0);
        }

        return BasisPoints::of(self::UNWORKED[$dayType->value]);
    }

    private static function overtimeFactor(DayType $dayType, bool $isRestDay): int
    {
        $isPlainWorkingDay = $dayType === DayType::Ordinary || $dayType === DayType::SpecialWorking;

        return ($isPlainWorkingDay && ! $isRestDay)
            ? self::OVERTIME_ORDINARY
            : self::OVERTIME_PREMIUM;
    }
}
