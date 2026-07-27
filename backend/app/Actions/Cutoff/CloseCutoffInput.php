<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

final readonly class CloseCutoffInput
{
    public function __construct(
        public string $officeId,
        public string $periodStart,
        public string $actorId,
    ) {}
}
