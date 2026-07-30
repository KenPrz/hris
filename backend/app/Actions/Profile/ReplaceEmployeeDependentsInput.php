<?php

declare(strict_types=1);

namespace App\Actions\Profile;

final readonly class ReplaceEmployeeDependentsInput
{
    /** @param array<int, array{name: string, relationship_id: string, birth_date?: string|null}> $dependents */
    public function __construct(
        public string $employeeId,
        public array $dependents,
    ) {}
}
