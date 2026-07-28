<?php

declare(strict_types=1);

namespace App\Actions\Offices;

use App\Exceptions\Domain\DuplicateOfficeCode;
use App\Exceptions\Domain\InvalidReference;
use App\Models\Office;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Updates one office row. Re-checks the global unique `code` only when it actually
 * changed (excluding this office's own row), same reasoning as CreateOffice: a pre-check
 * for the clean 422 in the common case, a try/catch backstop around the write for the
 * race a concurrent update could still slip through.
 *
 * The full-object update sets organization_id unconditionally, so the same
 * changed-parent guard is needed here as CreateOffice: only re-check existence when the
 * incoming organization_id actually differs from the current one, backstopped by a
 * catch on the FK-violation SQLSTATE (23503) for the concurrent-delete race.
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

            if ($in->organizationId !== $office->organization_id
                && ! Organization::query()->whereKey($in->organizationId)->exists()
            ) {
                throw new InvalidReference('organization', $in->organizationId);
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
            } catch (QueryException $e) {
                if ($e->getCode() === '23503') {
                    throw new InvalidReference('organization', $in->organizationId);
                }

                throw $e;
            }

            return $office;
        });
    }
}
