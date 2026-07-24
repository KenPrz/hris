<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Domain\Pay\DayType;

final readonly class CreateHolidayInput
{
    public function __construct(
        public string $officeId,
        public string $date,
        public DayType $dayType,
        public string $name,
    ) {}
}
