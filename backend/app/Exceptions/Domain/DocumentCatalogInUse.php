<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a catalog row is deleted while something still points at it — a category with
 * documents, or a document with filed files.
 *
 * Generic across both, like AlreadyArchived: the subject type travels as a string rather than
 * a class-per-subject exception, because the error shape is identical either way.
 *
 * A refusal, not a cascade. Losing a signed contract because someone tidied the catalog is
 * not an acceptable failure mode.
 */
final class DocumentCatalogInUse extends DomainException
{
    public function __construct(
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly int $dependents,
    ) {
        parent::__construct('This catalog entry is still in use and cannot be deleted.');
    }

    public function errorCode(): string
    {
        return 'document_catalog_in_use';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dependents' => $this->dependents,
        ];
    }
}
