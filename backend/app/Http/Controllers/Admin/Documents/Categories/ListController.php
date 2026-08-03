<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Categories;

use App\Http\Requests\Documents\ListDocumentCategoriesRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;

/**
 * A controller-only read — no Action, the same shape as Admin\Offices\ListController. The
 * FormRequest exists purely to carry the manageCatalog gate (empty rules()), so the
 * authorization shape is identical across all four category routes.
 */
final class ListController
{
    public function __invoke(ListDocumentCategoriesRequest $request): JsonResponse
    {
        return DocumentCategoryResource::collection(
            DocumentCategory::query()->orderBy('code')->get()
        )->response();
    }
}
