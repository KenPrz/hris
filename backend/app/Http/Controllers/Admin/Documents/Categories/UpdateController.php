<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Categories;

use App\Actions\Documents\UpdateDocumentCategory;
use App\Actions\Documents\UpdateDocumentCategoryInput;
use App\Http\Requests\Documents\UpdateDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateDocumentCategoryRequest $request, DocumentCategory $category, UpdateDocumentCategory $action): JsonResponse
    {
        $updated = $action->execute(new UpdateDocumentCategoryInput(
            categoryId: $category->id,
            code: $request->string('code')->toString(),
            name: $request->string('name')->toString(),
            // input(), not string() — see CreateController's identical comment.
            description: $request->input('description'),
        ));

        return DocumentCategoryResource::make($updated)->response();
    }
}
