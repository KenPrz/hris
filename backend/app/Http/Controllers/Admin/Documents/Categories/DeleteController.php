<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Categories;

use App\Actions\Documents\DeleteDocumentCategory;
use App\Actions\Documents\DeleteDocumentCategoryInput;
use App\Http\Requests\Documents\DeleteDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;

final class DeleteController
{
    public function __invoke(DeleteDocumentCategoryRequest $request, DocumentCategory $category, DeleteDocumentCategory $action): JsonResponse
    {
        $action->execute(new DeleteDocumentCategoryInput(categoryId: $category->id));

        // Returns the remaining list rather than 204, so the client's cache updates in one
        // round trip instead of needing a follow-up GET.
        return DocumentCategoryResource::collection(
            DocumentCategory::query()->orderBy('code')->get()
        )->response();
    }
}
