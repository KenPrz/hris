<?php

declare(strict_types=1);

use App\Domain\Attendance\PunchVerification;
use App\Domain\Attendance\PunchVerifier;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('verifies a punch from an allowlisted IP', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);

    $result = (new PunchVerifier)->verify($office, '203.0.113.7', null, null);

    expect($result->status)->toBe(PunchVerification::Verified)
        ->and($result->reason)->toBeNull();
});

it('flags a punch from an IP outside the allowlist', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => ['203.0.113.0/24']]);

    $result = (new PunchVerifier)->verify($office, '198.51.100.9', null, null);

    expect($result->status)->toBe(PunchVerification::Flagged)
        ->and($result->reason)->toBe('ip_not_allowlisted');
});

it('verifies when the office has no allowlist configured', function (): void {
    // No allowlist means no IP restriction — every IP passes that check.
    $office = Office::factory()->create(['ip_allowlist' => null]);

    expect((new PunchVerifier)->verify($office, '198.51.100.9', null, null)->status)
        ->toBe(PunchVerification::Verified);
});

it('flags a punch outside the office geofence when coordinates are supplied', function (): void {
    // Office at (14.5995, 120.9842) with a 100m radius; the punch is ~2km away.
    $office = Office::factory()->create([
        'ip_allowlist' => null,
        'geofence_lat' => '14.5995000',
        'geofence_lng' => '120.9842000',
        'geofence_radius_m' => 100,
    ]);

    $result = (new PunchVerifier)->verify($office, null, '14.6180000', '120.9842000');

    expect($result->status)->toBe(PunchVerification::Flagged)
        ->and($result->reason)->toBe('outside_geofence');
});

it('verifies a punch inside the office geofence', function (): void {
    $office = Office::factory()->create([
        'ip_allowlist' => null,
        'geofence_lat' => '14.5995000',
        'geofence_lng' => '120.9842000',
        'geofence_radius_m' => 100,
    ]);

    // ~10m away, well inside 100m.
    expect((new PunchVerifier)->verify($office, null, '14.5995500', '120.9842000')->status)
        ->toBe(PunchVerification::Verified);
});

it('ignores the geofence when the office has none configured', function (): void {
    $office = Office::factory()->create(['ip_allowlist' => null, 'geofence_lat' => null]);

    expect((new PunchVerifier)->verify($office, null, '14.6180000', '120.9842000')->status)
        ->toBe(PunchVerification::Verified);
});
