<?php

declare(strict_types=1);

namespace App\Domain\System;

/**
 * The result of a health check.
 *
 * A domain object, not an HTTP concern: no status codes, no response shape.
 * Translating this into HTTP is the controller's job.
 * See docs/04-backend-conventions.md.
 */
final readonly class HealthStatus
{
    public function __construct(
        public bool $databaseOk,
        public ?string $databaseVersion,
        public string $appVersion,
        public ?string $failureReason = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->databaseOk;
    }
}
