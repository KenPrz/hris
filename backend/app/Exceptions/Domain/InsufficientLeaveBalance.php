<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a leave request's final (HR) approval would debit more minutes than the
 * employee's derived balance for that leave type holds. Only reached for a
 * `deducts_balance` type — an event type never checks a balance at all. Thrown from
 * inside ApproveRequest's transaction, so it rolls the whole approval back: the request
 * stays `manager_approved`, and no leave_ledger row is written.
 */
final class InsufficientLeaveBalance extends DomainException
{
    public function __construct(
        private readonly string $leaveTypeId,
        private readonly int $requestedMinutes,
        private readonly int $availableMinutes,
    ) {
        parent::__construct('This request would debit more than the employee\'s available leave balance.');
    }

    public function errorCode(): string
    {
        return 'insufficient_leave_balance';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'leave_type_id' => $this->leaveTypeId,
            'requested_minutes' => $this->requestedMinutes,
            'available_minutes' => $this->availableMinutes,
        ];
    }
}
