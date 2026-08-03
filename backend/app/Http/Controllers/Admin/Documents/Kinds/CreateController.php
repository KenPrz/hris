<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Kinds;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\CreateDocumentInput;
use App\Http\Requests\Documents\CreateDocumentRequest;
use App\Http\Resources\DocumentResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateController
{
    public function __invoke(CreateDocumentRequest $request, CreateDocument $action): JsonResponse
    {
        $document = $action->execute(new CreateDocumentInput(
            code: $request->string('code')->toString(),
            name: $request->string('name')->toString(),
            // input(), not string(): has() is true for an explicit JSON null, and string()
            // would coerce it to '' — silently turning "no description" into an empty
            // string. description is already validated nullable|string, so input() is
            // either null or the correct type as-is. See CreateDocumentCategoryController's
            // identical comment for the same reasoning.
            description: $request->input('description'),
            categoryId: $request->string('category_id')->toString(),
            // applies_to is already validated against the Documentable enum's backed
            // values (or null) — input() hands it straight through.
            appliesTo: $request->input('applies_to'),
            // Explicit has()-check, not boolean('is_required') alone: an omitted flag must
            // default false rather than being coerced from a missing key.
            isRequired: $request->has('is_required') ? $request->boolean('is_required') : false,
            validityMonths: $request->input('validity_months') !== null ? (int) $request->input('validity_months') : null,
        ));

        return DocumentResource::make($document)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
