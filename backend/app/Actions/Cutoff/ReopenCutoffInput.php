<?php

declare(strict_types=1);

namespace App\Actions\Cutoff;

final readonly class ReopenCutoffInput
{
    public function __construct(
        public string $periodId,
        public string $reason,
        public string $actorId,
    ) {}
}
