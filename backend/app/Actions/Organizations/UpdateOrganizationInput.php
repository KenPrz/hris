<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

final readonly class UpdateOrganizationInput
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public ?string $legalName,
        public ?string $tin,
        public string $timezone,
        public ?string $actorId,
    ) {}
}
