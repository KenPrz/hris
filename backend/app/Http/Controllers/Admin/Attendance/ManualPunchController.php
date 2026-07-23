<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Attendance;

use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\RecordPunchInput;
use App\Domain\Attendance\PunchDirection;
use App\Domain\Attendance\PunchSource;
use App\Exceptions\Domain\CannotManuallyPunchSelf;
use App\Http\Requests\ManualPunchRequest;
use App\Http\Resources\AttendanceLogResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManualPunchController
{
    public function __invoke(ManualPunchRequest $request, RecordPunch $action): JsonResponse
    {
        $employeeId = $request->string('employee_id')->toString();
        $user = $request->user();

        // 422, not 404/403: even an HR/system admin may not manually record their own
        // punch — separation of duties. You don't enter your own time.
        if ($user->employee?->id === $employeeId) {
            throw new CannotManuallyPunchSelf();
        }

        // 404, not 403: an out-of-scope subject is indistinguishable from a nonexistent
        // one — the M2 rule. A system admin can target anyone; an HR admin can only
        // backfill for an employee in one of the offices they administer.
        $target = Employee::query()->find($employeeId);

        $inScope = $user->is_system_admin
            || ($target !== null && $user->hrAdminOffices()->where('offices.id', $target->current_office_id)->exists());

        if (! $inScope) {
            throw new NotFoundHttpException();
        }

        $log = $action->execute(new RecordPunchInput(
            employeeId: $employeeId,
            direction: PunchDirection::from($request->string('direction')->toString()),
            source: PunchSource::Manual,
            punchedAt: Carbon::parse($request->string('punched_at')->toString()),
            recordedBy: $request->user()->id,
            ipAddress: null, deviceId: null, geoLat: null, geoLng: null,
        ));

        return AttendanceLogResource::make($log)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
