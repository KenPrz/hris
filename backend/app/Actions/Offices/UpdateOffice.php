<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Exceptions\Domain\DuplicateOfficeCode;
use App\Models\Office;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Updates one office row. Re-checks the global unique `code` only when it actually
 * changed (excluding this office's own row), same reasoning as CreateOffice: a pre-check
 * for the clean 422 in the common case, a try/catch backstop around the write for the
 * race a concurrent update could still slip through.
 */
final class UpdateOffice
{
    public function execute(UpdateOfficeInput $in): Office
    {
        return DB::transaction(function () use ($in): Office {
            $office = Office::query()->findOrFail($in->officeId);

            if ($in->code !== $office->code
                && Office::query()->where('code', $in->code)->where('id', '!=', $office->id)->exists()
            ) {
                throw new DuplicateOfficeCode($in->code);
            }

            try {
                $office->fill([
                    'organization_id' => $in->organizationId,
                    'name' => $in->name,
                    'code' => $in->code,
                    'timezone' => $in->timezone,
                    'geofence_lat' => $in->geofenceLat,
                    'geofence_lng' => $in->geofenceLng,
                    'geofence_radius_m' => $in->geofenceRadiusM,
                    'ip_allowlist' => $in->ipAllowlist,
                    'default_shift_template_id' => $in->defaultShiftTemplateId,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateOfficeCode($in->code);
            }

            return $office;
        });
    }
}
