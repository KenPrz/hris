<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a leave type has been retired (`is_active=false`) — via a manual grant
 * (GrantController) or an employee's own leave request (SubmitLeaveRequestController). The
 * office-scope 404 (grant) or foreign-office 404 (submit) always runs first, so this is
 * only ever reachable for a leave type in an office the caller already has visibility into.
 */
final class LeaveTypeInactive extends DomainException
{
    public function __construct(private readonly string $leaveTypeId)
    {
        parent::__construct('This leave type has been retired and can no longer be used.');
    }

    public function errorCode(): string
    {
        return 'leave_type_inactive';
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
