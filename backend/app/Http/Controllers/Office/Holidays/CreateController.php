<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Actions\Holidays\CreateHoliday;
use App\Actions\Holidays\CreateHolidayInput;
use App\Domain\Pay\DayType;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CreateHolidayRequest;
use App\Http\Resources\HolidayResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateController
{
    public function __invoke(CreateHolidayRequest $request, CreateHoliday $action): JsonResponse
    {
        $officeId = $request->string('office_id')->toString();

        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // CreateHolidayRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administeredBy($request->user())->find($officeId);

        if ($office === null) {
            throw new NotFoundHttpException;
        }

        $holiday = $action->execute(new CreateHolidayInput(
            officeId: $office->id,
            date: $request->string('date')->toString(),
            dayType: DayType::from($request->string('day_type')->toString()),
            name: $request->string('name')->toString(),
        ));

        return HolidayResource::make($holiday)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
