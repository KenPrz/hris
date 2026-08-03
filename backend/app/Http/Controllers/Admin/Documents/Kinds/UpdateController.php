<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Kinds;

use App\Actions\Documents\UpdateDocument;
use App\Actions\Documents\UpdateDocumentInput;
use App\Http\Requests\Documents\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateDocumentRequest $request, Document $document, UpdateDocument $action): JsonResponse
    {
        $updated = $action->execute(new UpdateDocumentInput(
            documentId: $document->id,
            code: $request->string('code')->toString(),
            name: $request->string('name')->toString(),
            // input(), not string() — see CreateController's identical comment.
            description: $request->input('description'),
            categoryId: $request->string('category_id')->toString(),
            appliesTo: $request->input('applies_to'),
            isRequired: $request->has('is_required') ? $request->boolean('is_required') : false,
            validityMonths: $request->input('validity_months') !== null ? (int) $request->input('validity_months') : null,
        ));

        return DocumentResource::make($updated)->response();
    }
}
