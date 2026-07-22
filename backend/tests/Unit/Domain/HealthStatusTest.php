<?php

declare(strict_types=1);

use App\Domain\System\HealthStatus;

it('is healthy when the database answered', function (): void {
    $status = new HealthStatus(
        databaseOk: true,
        databaseVersion: 'PostgreSQL 18.0',
        appVersion: 'test',
    );

    expect($status->isHealthy())->toBeTrue();
});

it('is unhealthy when the database did not answer', function (): void {
    $status = new HealthStatus(
        databaseOk: false,
        databaseVersion: null,
        appVersion: 'test',
        failureReason: 'connection refused',
    );

    expect($status->isHealthy())->toBeFalse()
        ->and($status->failureReason)->toBe('connection refused');
});
