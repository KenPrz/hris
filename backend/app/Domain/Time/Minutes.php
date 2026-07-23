<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * A duration, as a whole non-negative number of minutes.
 *
 * There is deliberately no float constructor and no "hours" accessor returning a
 * decimal. `7h 20m` is `7.333…`, and a shift is not a number you may round twice —
 * every rounding in this system happens once, in Money::fraction(), on money.
 *
 * Negative is unrepresentable. Undertime is a separate non-negative magnitude
 * (see OvertimeThreshold); a negative duration reaching the pay engine would be a
 * bug wearing a value's clothes.
 *
 * See docs/01-architecture.md.
 */
final readonly class Minutes
{
    private function __construct(
        public int $value,
    ) {}

    public static function of(int $minutes): self
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException("A duration cannot be negative: {$minutes} minutes.");
        }

        return new self($minutes);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** @param  list<self>  $durations */
    public static function sum(array $durations): self
    {
        $total = 0;

        foreach ($durations as $duration) {
            $total += $duration->value;
        }

        return new self($total);
    }

    public function plus(self $other): self
    {
        return new self($this->value + $other->value);
    }

    public function minus(self $other): self
    {
        return self::of($this->value - $other->value);
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compareTo(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function greaterThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function lessThan(self $other): bool
    {
        return $this->value < $other->value;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }
}
