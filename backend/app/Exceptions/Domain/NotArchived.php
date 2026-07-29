<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when an unarchive action is asked to unarchive a subject whose `archived_at`
 * is already null. Generic across the org tree — see AlreadyArchived's docblock; the
 * same subjectType-as-string shape is reused verbatim by Office and Department.
 */
final class NotArchived extends DomainException
{
    public function __construct(private readonly string $subjectType, private readonly string $subjectId)
    {
        parent::__construct('This record is not archived.');
    }

    public function errorCode(): string
    {
        return 'not_archived';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['subject_type' => $this->subjectType, 'subject_id' => $this->subjectId];
    }
}
