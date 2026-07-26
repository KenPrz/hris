<?php

declare(strict_types=1);

namespace App\Actions\Offices;

final readonly class SetOfficeLeaveDayInput
{
    public function __construct(
        public string $officeId,
        public int $minutesPerLeaveDay,
    ) {}
}
