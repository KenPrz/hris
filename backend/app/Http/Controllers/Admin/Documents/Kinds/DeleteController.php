<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Kinds;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\DeleteDocumentInput;
use App\Http\Requests\Documents\DeleteDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

final class DeleteController
{
    public function __invoke(DeleteDocumentRequest $request, Document $document, DeleteDocument $action): JsonResponse
    {
        $action->execute(new DeleteDocumentInput(documentId: $document->id));

        // Returns the remaining list rather than 204, so the client's cache updates in one
        // round trip instead of needing a follow-up GET.
        return DocumentResource::collection(
            Document::query()->orderBy('code')->get()
        )->response();
    }
}
