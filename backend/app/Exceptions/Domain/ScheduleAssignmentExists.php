<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating a schedule assignment whose (target, effective_from) matches a row
 * that already exists — an employee or department can only have one shift template
 * effective on a given date. The partial unique indexes on schedule_assignments make two
 * assignments on the same target-date structurally one; this turns that into a clean error
 * instead of a database-constraint violation surfacing as a 500. The target's office-scope
 * 404 always runs first, so this is only ever reachable for a target the caller already
 * administers — it leaks nothing about other offices.
 */
final class ScheduleAssignmentExists extends DomainException
{
    public function __construct(
        private readonly string $targetType,
        private readonly string $targetId,
        private readonly string $effectiveFrom,
    ) {
        parent::__construct('This target already has a schedule assignment effective on that date.');
    }

    public function errorCode(): string
    {
        return 'schedule_assignment_exists';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'effective_from' => $this->effectiveFrom,
        ];
    }
}
