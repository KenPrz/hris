<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Categories;

use App\Actions\Documents\CreateDocumentCategory;
use App\Actions\Documents\CreateDocumentCategoryInput;
use App\Http\Requests\Documents\CreateDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreateDocumentCategoryRequest $request, CreateDocumentCategory $action): JsonResponse
    {
        $category = $action->execute(new CreateDocumentCategoryInput(
            code: $request->string('code')->toString(),
            name: $request->string('name')->toString(),
            // input(), not string(): has() is true for an explicit JSON null, and string()
            // would coerce it to '' — silently turning "no description" into an empty
            // string. description is already validated nullable|string, so input() is
            // either null or the correct type as-is. See CreateLeaveTypeController's
            // identical comment for the same reasoning.
            description: $request->input('description'),
        ));

        return DocumentCategoryResource::make($category)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
