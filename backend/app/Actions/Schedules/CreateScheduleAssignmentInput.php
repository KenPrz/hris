<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

final readonly class CreateScheduleAssignmentInput
{
    public function __construct(
        public string $shiftTemplateId,
        public ?string $employeeId,
        public ?string $departmentId,
        public string $effectiveFrom,
    ) {}
}
