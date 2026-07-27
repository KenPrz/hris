<?php

declare(strict_types=1);

namespace App\Actions\Overtime;

final readonly class SubmitOvertimeRequestInput
{
    public function __construct(
        public string $employeeId,
        public string $date,
        public int $minutes,
        public string $note,
    ) {}
}
