<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Actions\Holidays\UpdateHoliday;
use App\Actions\Holidays\UpdateHolidayInput;
use App\Domain\Pay\DayType;
use App\Domain\Scope\OfficeScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;

final class UpdateController
{
    public function __invoke(UpdateHolidayRequest $request, Holiday $holiday, UpdateHoliday $action): JsonResponse
    {
        // 404, not 403: a holiday whose office the caller doesn't administer must be
        // indistinguishable from a nonexistent {holiday} (which route-binding already
        // 404s on its own). The scope check lives here, not in the request, so both
        // paths land in the same NotFoundHttpException.
        if (! OfficeScope::administers($request->user(), $holiday->office_id)) {
            throw new NotFoundHttpException;
        }

        $updated = $action->execute($holiday, new UpdateHolidayInput(
            dayType: DayType::from($request->string('day_type')->toString()),
            name: $request->string('name')->toString(),
        ));

        return HolidayResource::make($updated)->response();
    }
}
