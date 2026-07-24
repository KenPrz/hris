<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Actions\Holidays\UpdateHoliday;
use App\Actions\Holidays\UpdateHolidayInput;
use App\Domain\Pay\DayType;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateController
{
    public function __invoke(UpdateHolidayRequest $request, Holiday $holiday, UpdateHoliday $action): JsonResponse
    {
        // 404, not 403: a holiday whose office the caller doesn't administer must be
        // indistinguishable from a nonexistent {holiday} (which route-binding already
        // 404s on its own). The scope check lives here, not in the request, so both
        // paths land in the same NotFoundHttpException.
        $administers = OfficeScope::administeredBy($request->user())
            ->whereKey($holiday->office_id)
            ->exists();

        if (! $administers) {
            throw new NotFoundHttpException;
        }

        $updated = $action->execute($holiday, new UpdateHolidayInput(
            dayType: DayType::from($request->string('day_type')->toString()),
            name: $request->string('name')->toString(),
        ));

        return HolidayResource::make($updated)->response();
    }
}
