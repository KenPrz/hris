<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a leave type with `requires_attachment=true` (e.g. Sick Leave needing a
 * medical certificate) is filed with no supporting file. A shape/type check
 * (`SubmitLeaveRequestRequest`'s `attachment` rule) can't express "required only for
 * SOME leave types" without knowing which type was chosen — that decision depends on a
 * row the FormRequest never loads, so it is a domain guard, not a validation rule.
 */
final class LeaveAttachmentRequired extends DomainException
{
    public function __construct(private readonly string $leaveTypeId)
    {
        parent::__construct('This leave type requires a supporting attachment.');
    }

    public function errorCode(): string
    {
        return 'leave_attachment_required';
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
