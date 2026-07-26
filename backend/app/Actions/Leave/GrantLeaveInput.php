<?php

declare(strict_types=1);

namespace App\Actions\Leave;

final readonly class GrantLeaveInput
{
    public function __construct(
        public string $employeeId,
        public string $leaveTypeId,
        public int $minutes,
        public string $reason,
        public string $actorId,
    ) {}
}
