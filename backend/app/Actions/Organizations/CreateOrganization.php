<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Creates one organization row. Organizations are unguarded ($guarded = []), so the
 * write is an explicit array rather than a mass-assignment allowlist. There is no
 * created_by column — actorId is carried on the input for interface symmetry with the
 * other admin-CRUD actions, but the audit trail comes from Organization's LogsActivity,
 * which resolves the causer from the authenticated guard automatically; nothing extra
 * is needed here.
 */
final class CreateOrganization
{
    public function execute(CreateOrganizationInput $in): Organization
    {
        return DB::transaction(fn (): Organization => Organization::query()->create([
            'name' => $in->name,
            'legal_name' => $in->legalName,
            'tin' => $in->tin,
            'timezone' => $in->timezone,
        ]));
    }
}
