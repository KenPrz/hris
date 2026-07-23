<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class RecordEmploymentChangeInput
{
    public function __construct(
        public string $employeeId,
        public string $effectiveFrom,      // 'YYYY-MM-DD'
        public string $officeId,
        public string $departmentId,
        public ?string $reportsToId,
        public string $employmentType,
        public bool $isArt82Exempt,
        public int $baseRateCents,
        public ?string $actorId,
    ) {}
}
