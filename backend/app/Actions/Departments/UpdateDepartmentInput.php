<?php

declare(strict_types=1);

namespace App\Actions\Departments;

final readonly class UpdateDepartmentInput
{
    public function __construct(
        public string $departmentId,
        public string $officeId,
        public string $name,
        public string $code,
        public ?string $actorId,
    ) {}
}
