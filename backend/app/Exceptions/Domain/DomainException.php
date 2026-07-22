<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * Base for every expected business-rule failure.
 *
 * Actions throw these; they never return HTTP responses and never know status codes.
 * A single render hook turns any of these into the error envelope in docs/03-api.md.
 *
 * Every `code` in that doc's error table is one subclass. The table and this directory
 * should be diffable against each other.
 */
abstract class DomainException extends RuntimeException
{
    /** Stable, machine-readable. Clients branch on this; it never changes once shipped. */
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /** @return array<string, mixed> Code-specific context. */
    public function details(): array
    {
        return [];
    }
}
