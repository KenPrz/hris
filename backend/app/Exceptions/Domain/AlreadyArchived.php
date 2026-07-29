<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when an archive action is asked to archive a subject that already has an
 * `archived_at`. Generic across the org tree: Office (M8a Task 3) throws this with
 * subjectType 'office'; Department (Task 4) reuses it verbatim with 'department'. The
 * subject type travels as a string, not a class-per-subject exception, because the
 * error shape (`already_archived`, both fields in details) is identical regardless of
 * which table owns the row.
 */
final class AlreadyArchived extends DomainException
{
    public function __construct(private readonly string $subjectType, private readonly string $subjectId)
    {
        parent::__construct('This record has already been archived.');
    }

    public function errorCode(): string
    {
        return 'already_archived';
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
