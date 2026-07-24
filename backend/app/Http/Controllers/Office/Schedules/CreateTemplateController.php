<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\CreateShiftTemplate;
use App\Actions\Schedules\CreateShiftTemplateInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CreateShiftTemplateRequest;
use App\Http\Resources\ShiftTemplateResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateTemplateController
{
    public function __invoke(CreateShiftTemplateRequest $request, CreateShiftTemplate $action): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // CreateShiftTemplateRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administered($request->user(), $request->string('office_id')->toString())
            ?? throw new NotFoundHttpException;

        $template = $action->execute(new CreateShiftTemplateInput(
            officeId: $office->id,
            name: $request->string('name')->toString(),
            days: $request->validated('days'),
        ));

        return ShiftTemplateResource::make($template)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
