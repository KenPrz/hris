<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Actions\Holidays\CloneHolidays;
use App\Actions\Holidays\CloneHolidaysInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CloneHolidaysRequest;
use App\Http\Resources\HolidayResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CloneController
{
    public function __invoke(CloneHolidaysRequest $request, CloneHolidays $action): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // CloneHolidaysRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administeredOrFail($request->user(), $request->string('office_id')->toString());

        $created = $action->execute(new CloneHolidaysInput(
            office: $office,
            fromYear: $request->integer('from_year'),
            toYear: $request->integer('to_year'),
            causer: $request->user(),
        ));

        return HolidayResource::collection($created)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
