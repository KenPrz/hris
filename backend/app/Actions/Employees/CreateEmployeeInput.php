<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class CreateEmployeeInput
{
    public function __construct(
        public string $employeeNo,
        public string $organizationId,
        public string $hiredAt,
        public ?RecordEmploymentChangeInput $firstEmployment,   // null if created bare
        public ?string $actorId,
    ) {}
}
