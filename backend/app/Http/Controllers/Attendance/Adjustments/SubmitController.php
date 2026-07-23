<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Actions\Attendance\SubmitAttendanceAdjustment;
use App\Actions\Attendance\SubmitAttendanceAdjustmentInput;
use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\SubmitAdjustmentRequest;
use App\Http\Resources\RequestResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class SubmitController
{
    public function __invoke(SubmitAdjustmentRequest $request, SubmitAttendanceAdjustment $action): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee;
        }

        $direction = $request->string('direction')->toString();

        $result = $action->execute(new SubmitAttendanceAdjustmentInput(
            employeeId: $employee->id,
            operation: AdjustmentOperation::from($request->string('operation')->toString()),
            note: $request->string('note')->toString(),
            targetLogId: $request->input('target_log_id'),
            direction: $direction === '' ? null : PunchDirection::from($direction),
            punchedAt: $request->input('punched_at'),
            attachment: $request->file('attachment'),
        ));

        return RequestResource::make($result)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
