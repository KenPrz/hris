<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when deleting a shift template that is still load-bearing: either it is an
 * office's `default_shift_template_id`, or a ScheduleAssignment still points at it.
 * Either way the row is not an orphan the caller can freely discard, so this turns the
 * would-be dangling reference into a clean error instead of an FK-constraint violation
 * surfacing as a 500. The office-scope 404 always runs first, so this is only ever
 * reachable for an office the caller already administers — it leaks nothing about other
 * offices.
 */
final class TemplateInUse extends DomainException
{
    public function __construct(private readonly string $templateId)
    {
        parent::__construct('This shift template is still in use and cannot be deleted.');
    }

    public function errorCode(): string
    {
        return 'template_in_use';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['template_id' => $this->templateId];
    }
}
