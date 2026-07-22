<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Worked time divided in two by some criterion: the part that matched and the part
 * that did not.
 *
 * Used for night differential (inside/outside the 22:00-06:00 window) and for overtime
 * (regular hours vs. hours beyond the schedule). One small type, two uses — the shape
 * is identical and inventing a second name for it would be abstraction without benefit.
 */
final readonly class WorkedSplit
{
    private function __construct(
        public Minutes $inside,
        public Minutes $outside,
    ) {}

    public static function of(Minutes $inside, Minutes $outside): self
    {
        return new self($inside, $outside);
    }

    public function total(): Minutes
    {
        return $this->inside->plus($this->outside);
    }
}
