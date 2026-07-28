<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Exceptions\Domain\DuplicateOfficeCode;
use App\Models\Office;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates one office row. `offices.code` is a GLOBAL unique constraint (confirmed
 * against the M2 migration — not scoped per organization), so the pre-check below is a
 * global `where('code', ...)->exists()`, not scoped to organization_id.
 *
 * The pre-check is the clean-422 path for the overwhelmingly common sequential case; the
 * try/catch around the insert is the race-safe backstop for a concurrent create that
 * slips past the pre-check between it and the insert (same pattern as
 * CreatePayRule::execute) — so the worst case a client can observe is still a clean 422
 * DuplicateOfficeCode, never a raw unique-violation 500.
 *
 * Office is unguarded ($guarded = []), so the write is an explicit array rather than a
 * mass-assignment allowlist.
 */
final class CreateOffice
{
    public function execute(CreateOfficeInput $in): Office
    {
        return DB::transaction(function () use ($in): Office {
            if (Office::query()->where('code', $in->code)->exists()) {
                throw new DuplicateOfficeCode($in->code);
            }

            try {
                return Office::query()->create([
                    'organization_id' => $in->organizationId,
                    'name' => $in->name,
                    'code' => $in->code,
                    'timezone' => $in->timezone,
                    'geofence_lat' => $in->geofenceLat,
                    'geofence_lng' => $in->geofenceLng,
                    'geofence_radius_m' => $in->geofenceRadiusM,
                    'ip_allowlist' => $in->ipAllowlist,
                    'default_shift_template_id' => $in->defaultShiftTemplateId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateOfficeCode($in->code);
            }
        });
    }
}
