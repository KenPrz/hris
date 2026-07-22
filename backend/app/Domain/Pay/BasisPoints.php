<?php

declare(strict_types=1);

namespace App\Domain\Pay;

use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * A pay multiplier, as an integer number of basis points. 100% is 10000; 260% is 26000.
 *
 * A multiplier is money's co-conspirator, so it does not get to be a float either. The
 * DOLE premium rates compound — 200% x 130% x 130% x 110% for holiday overtime at 2am —
 * and the order they compound in has to be fixed and testable rather than incidental.
 *
 * Composition rounds half away from zero, through the same rule as Money::fraction().
 * In practice the published rates all land on whole basis points, so nothing rounds; the
 * rule exists so that a future rate ending in an odd fraction cannot behave surprisingly.
 *
 * See docs/01-architecture.md.
 */
final readonly class BasisPoints
{
    /** 100%. One whole, unmultiplied. */
    public const int ONE = 10_000;

    private function __construct(
        public int $value,
    ) {}

    public static function of(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException("A pay multiplier cannot be negative: {$basisPoints} bp.");
        }

        return new self($basisPoints);
    }

    public static function one(): self
    {
        return new self(self::ONE);
    }

    /**
     * Compose two multipliers. 200% times 130% is 260%, not 330%.
     */
    public function times(self $other): self
    {
        if ($this->value !== 0 && abs($other->value) > intdiv(PHP_INT_MAX, $this->value)) {
            throw new InvalidArgumentException(
                "Composing {$this->value} bp with {$other->value} bp would overflow."
            );
        }

        return new self(self::divideRoundHalfUp($this->value * $other->value, self::ONE));
    }

    /**
     * Apply to an amount. Delegates to Money::fraction() rather than doing its own
     * arithmetic, because there is exactly one place in this system where a centavo
     * may be created or destroyed.
     */
    public function applyTo(Money $amount): Money
    {
        return $amount->fraction($this->value, self::ONE);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * For payslips and reports: '260%', '371.8%', '125.25%'. Trailing zeros are trimmed
     * so the common whole-percent case reads as a whole percent.
     */
    public function toPercentString(): string
    {
        $whole = intdiv($this->value, 100);
        $fraction = $this->value % 100;

        if ($fraction === 0) {
            return "{$whole}%";
        }

        $decimals = rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');

        return "{$whole}.{$decimals}%";
    }

    /**
     * The same rounding rule as Money::divideRoundHalfUp(). Duplicated deliberately
     * rather than exposed from Money: making it public there would invite call sites
     * to round outside fraction(), which is the one thing that rule exists to prevent.
     */
    private static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);

        if (($numerator % $denominator) * 2 >= $denominator) {
            $quotient++;
        }

        return $quotient;
    }
}
