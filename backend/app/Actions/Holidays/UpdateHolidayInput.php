<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Domain\Pay\DayType;

final readonly class UpdateHolidayInput
{
    public function __construct(
        public DayType $dayType,
        public string $name,
    ) {}
}
