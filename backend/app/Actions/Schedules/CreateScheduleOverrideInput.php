<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

final readonly class CreateScheduleOverrideInput
{
    public function __construct(
        public string $employeeId,
        public string $date,
        public bool $isRest,
        public ?int $startMinute,
        public ?int $endMinute,
        public ?int $breakMinutes,
        public ?string $note,
        public ?string $actorId,
    ) {}
}
