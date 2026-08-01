<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Documents\Kinds;

use App\Http\Requests\Documents\ListDocumentsRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

/**
 * A controller-only read — no Action, the same shape as Admin\Documents\Categories\
 * ListController. The FormRequest exists purely to carry the manageCatalog gate (empty
 * rules()), so the authorization shape is identical across all four document routes.
 */
final class ListController
{
    public function __invoke(ListDocumentsRequest $request): JsonResponse
    {
        return DocumentResource::collection(
            Document::query()->orderBy('code')->get()
        )->response();
    }
}
