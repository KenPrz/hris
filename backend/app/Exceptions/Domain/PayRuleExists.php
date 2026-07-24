<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when creating a pay rule whose effective_from matches a row that already exists.
 * The unique constraint on pay_rules makes two rules effective on the same day structurally
 * one; this turns that into a clean error instead of a database-constraint violation
 * surfacing as a 500.
 */
final class PayRuleExists extends DomainException
{
    public function __construct(private readonly string $effectiveFrom)
    {
        parent::__construct('A pay rule already takes effect on that date.');
    }

    public function errorCode(): string
    {
        return 'pay_rule_exists';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['effective_from' => $this->effectiveFrom];
    }
}
