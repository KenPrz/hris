<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Illuminate\Http\JsonResponse;

/**
 * The two profile catalogs, for populating dropdowns. Deliberately not office-scoped and not
 * admin-gated: it is static company-wide reference data with nothing sensitive in it, and
 * every profile screen needs it to turn a relationship_id into a word.
 *
 * No Action class — this is a read with no domain behaviour, the same shape as the other
 * list-only controllers in this codebase.
 */
final class ShowCatalogController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'relationships' => Relationship::query()
                    ->orderBy('code')
                    ->get(['id', 'code', 'description'])
                    ->all(),
                'identification_categories' => EmployeeIdentificationCategory::query()
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'description'])
                    ->all(),
            ],
        ]);
    }
}
