<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when an HR admin tries to manually grant into a leave type whose
 * `deducts_balance` is false — an event type (Maternity, Paternity, …) that is never
 * banked and has no balance to credit. The office-scope 404 always runs first, so this
 * is only ever reachable for a leave type in an office the caller already administers.
 */
final class LeaveTypeNotGrantable extends DomainException
{
    public function __construct(private readonly string $leaveTypeId)
    {
        parent::__construct('This leave type cannot be manually granted — it does not bank a balance.');
    }

    public function errorCode(): string
    {
        return 'leave_type_not_grantable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['leave_type_id' => $this->leaveTypeId];
    }
}
