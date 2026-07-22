<?php

declare(strict_types=1);

namespace App\Actions\System;

use App\Domain\System\HealthStatus;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Is the system able to serve? The first action in the codebase, and the shape every
 * other one copies: takes input (none here), returns a domain object, knows nothing
 * about HTTP.
 *
 * See docs/04-backend-conventions.md.
 */
final class CheckHealth
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function execute(): HealthStatus
    {
        try {
            /** @var object{version: string} $row */
            $row = $this->db->selectOne('select version() as version');

            return new HealthStatus(
                databaseOk: true,
                databaseVersion: $row->version,
                appVersion: (string) config('hris.version'),
            );
        } catch (Throwable $e) {
            // An unreachable database is a reportable state, not an exception:
            // monitoring needs a body explaining what is wrong, not a stack trace.
            return new HealthStatus(
                databaseOk: false,
                databaseVersion: null,
                appVersion: (string) config('hris.version'),
                failureReason: $e->getMessage(),
            );
        }
    }
}
