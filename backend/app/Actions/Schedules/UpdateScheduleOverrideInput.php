<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

final readonly class UpdateScheduleOverrideInput
{
    public function __construct(
        public bool $isRest,
        public ?int $startMinute,
        public ?int $endMinute,
        public ?int $breakMinutes,
        public ?string $note,
    ) {}
}
