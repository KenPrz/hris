<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating or updating a department whose `code` collides with another
 * department's within the SAME office. `departments.code` is unique per
 * `(office_id, code)` (confirmed against the M2 migration — unlike offices.code, which
 * is global), so the pre-check and this exception are scoped to office_id rather than
 * global.
 */
final class DuplicateDepartmentCode extends DomainException
{
    // Named $departmentCode, not $code — Exception already declares a non-readonly
    // $code property, and PHP refuses to redeclare it as readonly in a subclass.
    public function __construct(private readonly string $departmentCode)
    {
        parent::__construct('A department with this code already exists in this office.');
    }

    public function errorCode(): string
    {
        return 'duplicate_department_code';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['code' => $this->departmentCode];
    }
}
