<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Models\Office;

/**
 * Decides whether a punch is verified or flagged. Never rejects — a flag is metadata on
 * the row for HR to review, because the Labor Code cares that time was worked, not which
 * network recorded it. See the M3 spec.
 *
 * A Domain service that reads Office attributes (no query, no config) and is otherwise
 * pure. The first failing check wins the reason.
 */
final class PunchVerifier
{
    private const int EARTH_RADIUS_M = 6_371_000;

    public function verify(Office $office, ?string $ipAddress, ?string $geoLat, ?string $geoLng): VerificationResult
    {
        if (! $this->ipAllowed($office, $ipAddress)) {
            return VerificationResult::flagged('ip_not_allowlisted');
        }

        if (! $this->withinGeofence($office, $geoLat, $geoLng)) {
            return VerificationResult::flagged('outside_geofence');
        }

        return VerificationResult::verified();
    }

    private function ipAllowed(Office $office, ?string $ipAddress): bool
    {
        $allowlist = $office->ip_allowlist;

        // No allowlist configured, or no IP to check: nothing to fail.
        if ($allowlist === null || $allowlist === [] || $ipAddress === null) {
            return true;
        }

        foreach ($allowlist as $cidr) {
            if ($this->ipInCidr($ipAddress, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function withinGeofence(Office $office, ?string $geoLat, ?string $geoLng): bool
    {
        // Only checked when the punch carries coordinates AND the office defines a fence.
        if ($geoLat === null || $geoLng === null
            || $office->geofence_lat === null || $office->geofence_lng === null
            || $office->geofence_radius_m === null) {
            return true;
        }

        $distance = $this->haversineMeters(
            (float) $office->geofence_lat, (float) $office->geofence_lng,
            (float) $geoLat, (float) $geoLng,
        );

        return $distance <= (float) $office->geofence_radius_m;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;   // IPv6 or malformed — out of scope for M3's IPv4 allowlists
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }
}
