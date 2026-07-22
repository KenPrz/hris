<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

it('exposes the hris config namespace', function (): void {
    expect(config('hris.currency'))->toBe('PHP')
        ->and(config('hris.organization_name'))->toBe('Test Company Inc.')
        ->and(config('hris.version'))->toBe('test');
});

it('stores timestamps in UTC regardless of where the company operates', function (): void {
    // Display timezone belongs on `offices` (M2). An app defaulted to Asia/Manila
    // writes local times into timestamptz and is wrong the moment a second office
    // opens in another zone — by which point the data is already mixed.
    expect(config('app.timezone'))->toBe('UTC');
});

it('refuses to boot without a currency', function (): void {
    config()->set('hris.currency', null);

    expect(fn () => AppServiceProvider::assertConfigured())
        ->toThrow(RuntimeException::class, 'HRIS_CURRENCY');
});

it('refuses to boot when the app timezone is not UTC', function (): void {
    config()->set('app.timezone', 'Asia/Manila');

    expect(fn () => AppServiceProvider::assertConfigured())
        ->toThrow(RuntimeException::class, 'APP_TIMEZONE');
});
