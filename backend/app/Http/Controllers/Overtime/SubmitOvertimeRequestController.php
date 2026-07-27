<?php

declare(strict_types=1);

namespace App\Http\Controllers\Overtime;

use App\Actions\Overtime\SubmitOvertimeRequest;
use App\Actions\Overtime\SubmitOvertimeRequestInput;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Requests\SubmitOvertimeRequestRequest;
use App\Http\Resources\RequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An employee filing their own overtime pre-authorization. Any employee may file —
 * deliberately not admin-gated, mirroring the attendance-adjustment and leave submit routes.
 * The client sends hours (quarter-hour granularity); this converts to the integer minutes the
 * domain stores. A fractional-minute request (e.g. 1.1h = 66min is fine, but a value that
 * does not land on a whole minute) is a validation error, never a silently rounded debit.
 */
final class SubmitOvertimeRequestController
{
    public function __invoke(SubmitOvertimeRequestRequest $request, SubmitOvertimeRequest $action): JsonResponse
    {
        $employee = $request->user()->employee ?? throw new NotAnEmployee;

        $hours = (float) $request->input('hours');
        $minutesFloat = $hours * 60.0;
        $minutes = (int) round($minutesFloat);

        if (abs($minutesFloat - $minutes) > 0.0001) {
            throw ValidationException::withMessages([
                'hours' => 'Overtime must be a whole number of minutes.',
            ]);
        }

        $result = $action->execute(new SubmitOvertimeRequestInput(
            employeeId: $employee->id,
            date: $request->string('date')->toString(),
            minutes: $minutes,
            note: $request->string('note')->toString(),
        ));

        return RequestResource::make($result)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
