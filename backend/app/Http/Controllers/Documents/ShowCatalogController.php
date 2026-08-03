<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Resources\DocumentCategoryResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;

/**
 * The document catalog, for populating dropdowns. Deliberately not office-scoped and not
 * admin-gated: static company-wide reference data with nothing sensitive in it, and every
 * screen that files a document needs it to turn a document_id into a name.
 *
 * No Action class — a read with no domain behaviour, the same shape as M10a's profile
 * catalog controller.
 *
 * Ordered by code so a client's dropdown is stable between requests.
 */
final class ShowCatalogController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'categories' => DocumentCategoryResource::collection(
                    DocumentCategory::query()->orderBy('code')->get()
                )->resolve(),
                'documents' => DocumentResource::collection(
                    Document::query()->orderBy('code')->get()
                )->resolve(),
            ],
        ]);
    }
}
