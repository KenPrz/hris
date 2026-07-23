<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * An amount of money, as an integer number of minor units (cents).
 *
 * There is deliberately **no float constructor**. Not discouraged — absent. `0.1 + 0.2`
 * is not `0.3`, and a payroll run will eventually find the input that proves it. Under
 * strict_types a float handed to any method here is a TypeError, which is the point:
 * the mistake is unrepresentable rather than merely frowned upon.
 *
 * Currency is not stored. It is fixed per business at setup (config('hris.currency')) —
 * carrying it on every amount would be 40 chances for two rows to disagree.
 *
 * See docs/01-architecture.md.
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
    ) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal string such as '12.34' — for admin price entry, which is the only
     * place a human types an amount.
     *
     * Parsed with string arithmetic, never a float cast: `(int) (float) '1.15' * 100`
     * famously yields 114. More than two decimal places is rejected rather than rounded,
     * because silently discarding a third digit is losing money quietly.
     */
    public static function parse(string $amount): self
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m) !== 1) {
            throw new InvalidArgumentException(
                "Not a well-formed amount: '{$amount}'. Expected digits with at most two decimal places."
            );
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fraction = (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($sign * ($whole * 100 + $fraction));
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function negated(): self
    {
        return new self(-$this->cents);
    }

    public function absolute(): self
    {
        return new self(abs($this->cents));
    }

    /**
     * The single rounding primitive. Every percentage in the system — tax, discounts,
     * fractional quantities — is a fraction of an amount, so there is exactly one place
     * where a cent can be created or destroyed, and one place to test.
     *
     * Rounds half away from zero, matching PHP's round() and the intuition that 0.5c
     * rounds up to 1c rather than vanishing.
     */
    public function fraction(int $numerator, int $denominator): self
    {
        self::assertNoOverflow($this->cents, $numerator);

        return new self(self::divideRoundHalfUp($this->cents * $numerator, $denominator));
    }

    /**
     * Split into `$parts` amounts that sum **exactly** back to this one.
     *
     * 1000 three ways is 334, 333, 333 — the earliest part absorbs the remainder. The
     * rule is arbitrary; that it is deterministic and total is not. Never split by
     * dividing and rounding each share, which invents or destroys pennies.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException("Cannot split money into {$parts} parts.");
        }

        return $this->allocateByRatios(array_fill(0, $parts, 1));
    }

    /**
     * Split proportionally — prorating an order-level discount across lines so each line
     * carries its share for tax purposes.
     *
     * @param  list<int>  $ratios  Non-negative weights.
     * @return list<self>
     */
    public function allocateByRatios(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Cannot allocate across zero ratios.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Ratios must be non-negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total === 0) {
            throw new InvalidArgumentException('Ratios must not sum to zero.');
        }

        // Truncate every share, then hand out the leftover pennies one at a time. The
        // leftover is always smaller than the number of parts, so this terminates.
        $shares = [];
        $allocated = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv($this->cents * $ratio, $total);
            $shares[] = $share;
            $allocated += $share;
        }

        $remainder = $this->cents - $allocated;
        $step = $remainder >= 0 ? 1 : -1;

        for ($i = 0; $i < abs($remainder); $i++) {
            $shares[$i % count($shares)] += $step;
        }

        return array_map(static fn (int $cents): self => new self($cents), $shares);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function compareTo(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }

    /** @param  list<self>  $amounts */
    public static function sum(array $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->cents;
        }

        return new self($total);
    }

    /**
     * PHP silently promotes integer overflow to float, and a float reaching the private
     * arithmetic below is a TypeError under strict_types — loud, but with a message that
     * explains nothing at 2am. Fail with the actual reason instead.
     *
     * Unreachable with real money: a $10M order (1e9 cents) at 100% (1e6 micros) is 1e15,
     * four orders of magnitude below PHP_INT_MAX.
     */
    private static function assertNoOverflow(int $cents, int $factor): void
    {
        if ($cents === 0 || $factor === 0) {
            return;
        }

        if (abs($cents) > intdiv(PHP_INT_MAX, abs($factor))) {
            throw new InvalidArgumentException(
                "Amount {$cents}c is too large to multiply by {$factor} without integer overflow."
            );
        }
    }

    /**
     * Integer division rounding half away from zero. Kept private and used by everything:
     * one rounding rule, one implementation, one set of tests.
     */
    private static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);

        $quotient = intdiv($n, $d);

        if (($n % $d) * 2 >= $d) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
