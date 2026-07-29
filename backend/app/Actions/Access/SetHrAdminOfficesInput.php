<?php

declare(strict_types=1);

namespace App\Actions\Access;

final readonly class SetHrAdminOfficesInput
{
    /** @param array<int, string> $officeIds */
    public function __construct(
        public string $userId,
        public array $officeIds,
        public ?string $actorId,
    ) {}
}
