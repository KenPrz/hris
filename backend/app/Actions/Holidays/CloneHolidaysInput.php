<?php

declare(strict_types=1);

namespace App\Actions\Holidays;

use App\Models\Office;
use App\Models\User;

final readonly class CloneHolidaysInput
{
    public function __construct(
        public Office $office,
        public int $fromYear,
        public int $toYear,
        public User $causer,
    ) {}
}
