<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class UpdateEmployeeInput
{
    public function __construct(
        public string $employeeId,
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        public ?string $nameSuffix,
        public ?string $actorId,
    ) {}
}
