<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutoff;

use App\Actions\Cutoff\ReopenCutoff;
use App\Actions\Cutoff\ReopenCutoffInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ReopenCutoffRequest;
use App\Http\Resources\CutoffPeriodResource;
use App\Models\CutoffPeriod;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReopenCutoffController
{
    // $period must match the {period} route segment for implicit model binding.
    public function __invoke(ReopenCutoffRequest $request, CutoffPeriod $period, ReopenCutoff $action): JsonResponse
    {
        // 404, not 403: a period whose office the caller doesn't administer must be
        // indistinguishable from a nonexistent {period} (which route-binding already
        // 404s on its own). The scope check lives here, not in the request, so both
        // paths land in the same NotFoundHttpException.
        if (! OfficeScope::administers($request->user(), $period->office_id)) {
            throw new NotFoundHttpException;
        }

        $reopened = $action->execute(new ReopenCutoffInput(
            periodId: $period->id,
            reason: $request->string('reason')->toString(),
            actorId: $request->user()->id,
        ));

        return CutoffPeriodResource::make($reopened)->response();
    }
}
