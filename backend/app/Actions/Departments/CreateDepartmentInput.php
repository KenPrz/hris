<?php

declare(strict_types=1);

namespace App\Actions\Departments;

final readonly class CreateDepartmentInput
{
    public function __construct(
        public string $officeId,
        public string $name,
        public string $code,
        public ?string $actorId,
    ) {}
}
