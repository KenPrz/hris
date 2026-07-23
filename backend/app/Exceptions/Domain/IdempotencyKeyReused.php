<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * A key was reused for a genuinely different request (different actor or body). Replaying
 * the original outcome would be wrong, so this is a hard conflict. See docs/03-api.md.
 */
final class IdempotencyKeyReused extends DomainException
{
    public function __construct(private readonly string $key)
    {
        parent::__construct('This idempotency key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['key' => $this->key];
    }
}
