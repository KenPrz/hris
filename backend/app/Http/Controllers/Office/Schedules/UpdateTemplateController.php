<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\UpdateShiftTemplate;
use App\Actions\Schedules\UpdateShiftTemplateInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\UpdateShiftTemplateRequest;
use App\Http\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateTemplateController
{
    public function __invoke(UpdateShiftTemplateRequest $request, ShiftTemplate $template, UpdateShiftTemplate $action): JsonResponse
    {
        // 404, not 403: same office-scope discipline as ShowTemplateController — a
        // template whose office the caller doesn't administer 404s exactly like a
        // nonexistent {template}.
        if (! OfficeScope::administers($request->user(), $template->office_id)) {
            throw new NotFoundHttpException;
        }

        $updated = $action->execute($template, new UpdateShiftTemplateInput(
            name: $request->string('name')->toString(),
            days: $request->validated('days'),
        ));

        return ShiftTemplateResource::make($updated)->response();
    }
}
