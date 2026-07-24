<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when the schedule resolution chain falls all the way through to the office-default
 * layer and the office has no `default_shift_template_id` set. Every office must have a
 * default template before any employee in it can be scheduled at all.
 */
final class OfficeHasNoDefaultTemplate extends DomainException
{
    public function __construct(private readonly string $officeId)
    {
        parent::__construct('This office has no default shift template configured.');
    }

    public function errorCode(): string
    {
        return 'office_has_no_default_template';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['office_id' => $this->officeId];
    }
}
