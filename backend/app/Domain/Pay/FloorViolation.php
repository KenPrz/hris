<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/** One cell of a proposed pay-rule matrix that falls strictly below its statutory floor. */
final class FloorViolation
{
    public function __construct(
        public readonly string $multiplier,
        public readonly int $proposedBp,
        public readonly int $floorBp,
    ) {}
}
