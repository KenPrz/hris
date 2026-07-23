<?php

declare(strict_types=1);

namespace App\Actions\Attendance;

use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use Carbon\CarbonInterface;

final readonly class RecordPunchInput
{
    public function __construct(
        public string $employeeId,
        public PunchDirection $direction,
        public PunchSource $source,
        public ?CarbonInterface $punchedAt,   // null = server now (self-service)
        public ?string $recordedBy,
        public ?string $ipAddress,
        public ?string $deviceId,
        public ?string $geoLat,
        public ?string $geoLng,
    ) {}
}
