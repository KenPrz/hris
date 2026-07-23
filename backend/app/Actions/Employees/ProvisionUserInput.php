<?php

declare(strict_types=1);

namespace App\Actions\Employees;

final readonly class ProvisionUserInput
{
    public function __construct(
        public string $employeeId,
        public string $email,
        public string $password,
        public string $name,
    ) {}
}
