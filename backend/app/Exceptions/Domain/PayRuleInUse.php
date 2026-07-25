<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when deleting a pay_rules version that at least one daily_attendance_summaries
 * row still references (rule_version_id) — a summary priced against a version that then
 * vanished would leave its lines pointing at nothing. The FK is `ON DELETE RESTRICT`, so
 * this turns what would otherwise be a raw QueryException (a 500) into a clean, expected
 * error. Mirrors HolidayExists / TemplateInUse.
 */
final class PayRuleInUse extends DomainException
{
    public function __construct(private readonly string $payRuleId)
    {
        parent::__construct('This pay rule version is still in use and cannot be deleted.');
    }

    public function errorCode(): string
    {
        return 'pay_rule_in_use';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['pay_rule_id' => $this->payRuleId];
    }
}
