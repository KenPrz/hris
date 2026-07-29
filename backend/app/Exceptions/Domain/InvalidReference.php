<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a well-formed-but-nonexistent foreign key is given for a parent record —
 * a `organization_id` on office create/update, or an `office_id` on department
 * create/update. The FormRequest for both is deliberately shape-only (uuid, not
 * `exists:...`) since this is a system-admin surface, so this is the clean translation
 * of a dangling FK into the error envelope rather than a raw FK-violation 500. Generic
 * across reference types so both call sites share one exception.
 */
final class InvalidReference extends DomainException
{
    // Named $referenceType/$referenceId, not $code — Exception already declares a
    // non-readonly $code property, and PHP refuses to redeclare it as readonly in a
    // subclass (see DuplicateOfficeCode).
    public function __construct(
        private readonly string $referenceType,
        private readonly string $referenceId,
    ) {
        parent::__construct("This {$referenceType} does not exist.");
    }

    public function errorCode(): string
    {
        return 'invalid_reference';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['reference_type' => $this->referenceType, 'reference_id' => $this->referenceId];
    }
}
