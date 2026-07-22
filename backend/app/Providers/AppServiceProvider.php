<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        self::assertConfigured();
    }

    /**
     * Fail fast, at boot, rather than as a wrong number at payday.
     *
     * A missing currency or a non-UTC timezone surfaces as data that looks corrupt
     * rather than as misconfiguration, and by the time anyone notices, the bad rows
     * are already mixed in with the good ones.
     *
     * Static and public so it can be tested without booting a second application.
     */
    public static function assertConfigured(): void
    {
        if (blank(config('hris.currency'))) {
            throw new RuntimeException('HRIS_CURRENCY is not set.');
        }

        if (blank(config('hris.organization_name'))) {
            throw new RuntimeException('HRIS_ORGANIZATION_NAME is not set.');
        }

        if (config('app.timezone') !== 'UTC') {
            throw new RuntimeException(
                'APP_TIMEZONE must be UTC. Display timezone belongs on offices.'
            );
        }
    }
}
