<?php

declare(strict_types=1);

namespace App\Actions\Offices;

final readonly class CreateOfficeInput
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $code,
        public string $timezone,
        public ?float $geofenceLat,
        public ?float $geofenceLng,
        public ?int $geofenceRadiusM,
        public ?array $ipAllowlist,
        public ?string $defaultShiftTemplateId,
        public ?string $actorId,
    ) {}
}
