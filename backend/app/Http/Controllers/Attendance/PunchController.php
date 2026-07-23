<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\PunchRequest;
use App\Http\Resources\AttendanceLogResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PunchController
{
    public function __invoke(PunchRequest $request, RecordPunch $action): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee;
        }

        $log = $action->execute(new RecordPunchInput(
            employeeId: $employee->id,
            direction: PunchDirection::from($request->string('direction')->toString()),
            source: PunchSource::Web,
            punchedAt: null,                                  // server now — no client time
            recordedBy: $request->user()->id,
            ipAddress: $request->ip(),
            deviceId: null,
            geoLat: null,
            geoLng: null,
        ));

        return AttendanceLogResource::make($log)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
