<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating or updating an office whose `code` collides with another
 * office's. `offices.code` is a GLOBAL unique constraint (not scoped per organization —
 * confirmed against the M2 migration), so this is a clean translation of that
 * constraint into the error envelope rather than a raw 500 on the unique violation.
 */
final class DuplicateOfficeCode extends DomainException
{
    // Named $officeCode, not $code — Exception already declares a non-readonly $code
    // property, and PHP refuses to redeclare it as readonly in a subclass.
    public function __construct(private readonly string $officeCode)
    {
        parent::__construct('An office with this code already exists.');
    }

    public function errorCode(): string
    {
        return 'duplicate_office_code';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['code' => $this->officeCode];
    }
}
