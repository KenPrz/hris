<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * A span of worked time, as minutes from the start of the business day in the office's
 * local wall-clock time.
 *
 * Values may exceed 1440: a 22:00 -> 06:00 night shift is 1320 -> 1800. Converting UTC
 * timestamptz punches into office-local business-day minutes is the engine's job (M5),
 * not this class's — which is what lets night-differential splitting be plain integer
 * arithmetic instead of dragging a timezone database into a value object.
 */
final readonly class WorkInterval
{
    private function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {}

    public static function of(int $startMinute, int $endMinute): self
    {
        if ($startMinute < 0) {
            throw new InvalidArgumentException("A punch minute cannot be negative: {$startMinute}.");
        }

        if ($endMinute <= $startMinute) {
            throw new InvalidArgumentException(
                "An interval must end after it starts: {$startMinute} -> {$endMinute}."
            );
        }

        return new self($startMinute, $endMinute);
    }

    public function duration(): Minutes
    {
        return Minutes::of($this->endMinute - $this->startMinute);
    }
}
