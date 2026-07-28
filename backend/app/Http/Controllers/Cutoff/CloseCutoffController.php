<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutoff;

use App\Actions\Cutoff\CloseCutoff;
use App\Actions\Cutoff\CloseCutoffInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CloseCutoffRequest;
use App\Http\Resources\CutoffPeriodResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CloseCutoffController
{
    public function __invoke(CloseCutoffRequest $request, CloseCutoff $action): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // CloseCutoffRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administered($request->user(), $request->string('office_id')->toString())
            ?? throw new NotFoundHttpException;

        $period = $action->execute(new CloseCutoffInput(
            officeId: $office->id,
            periodStart: $request->string('period_start')->toString(),
            actorId: $request->user()->id,
        ));

        return CutoffPeriodResource::make($period)->response();
    }
}
