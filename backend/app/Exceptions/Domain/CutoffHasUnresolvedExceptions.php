<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown by CloseCutoff when its strict exception gate finds an in-period day still
 * `is_incomplete`, or a non-terminal request (pending/manager_approved) whose effect maps
 * onto an in-period date. Both lists are carried so the caller can point an operator at
 * exactly what needs resolving before the period can close.
 */
final class CutoffHasUnresolvedExceptions extends DomainException
{
    /**
     * @param  array<int, string>  $incompleteDates
     * @param  array<int, string>  $pendingRequestIds
     */
    public function __construct(private readonly array $incompleteDates, private readonly array $pendingRequestIds)
    {
        parent::__construct('This cutoff period has unresolved exceptions and cannot be closed.');
    }

    public function errorCode(): string
    {
        return 'cutoff_has_unresolved_exceptions';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'incomplete_dates' => $this->incompleteDates,
            'pending_request_ids' => $this->pendingRequestIds,
        ];
    }
}
