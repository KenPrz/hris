<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Updates one organization row. Organizations are unguarded ($guarded = []), so the
 * write is an explicit array rather than a mass-assignment allowlist. Spatie's
 * LogsActivity on Organization records the `updated` event itself, with the causer
 * resolved from the authenticated guard automatically — nothing extra is needed here.
 */
final class UpdateOrganization
{
    public function execute(UpdateOrganizationInput $in): Organization
    {
        return DB::transaction(function () use ($in): Organization {
            $organization = Organization::query()->findOrFail($in->organizationId);

            $organization->fill([
                'name' => $in->name,
                'legal_name' => $in->legalName,
                'tin' => $in->tin,
                'timezone' => $in->timezone,
            ])->save();

            return $organization;
        });
    }
}
