<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListShiftTemplatesRequest;
use App\Http\Resources\ShiftTemplateResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListTemplatesController
{
    public function __invoke(ListShiftTemplatesRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administered($request->user(), $request->string('office')->toString())
            ?? throw new NotFoundHttpException;

        $templates = $office->shiftTemplates()->with('days')->get();

        return ShiftTemplateResource::collection($templates);
    }
}
