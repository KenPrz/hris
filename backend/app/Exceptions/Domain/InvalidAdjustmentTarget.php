<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Thrown when a void/amend adjustment is approved against a target punch that no longer
 * qualifies: the log is missing, belongs to someone other than the requester, or has
 * already been annulled by an earlier approval. Submission deliberately defers this
 * check; approval is where it is finally enforced, under the request lock.
 */
final class InvalidAdjustmentTarget extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'invalid_adjustment_target';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
