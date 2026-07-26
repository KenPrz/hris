<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office;

use App\Actions\Offices\SetOfficeLeaveDay;
use App\Actions\Offices\SetOfficeLeaveDayInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\SetLeaveDayRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SetLeaveDayController
{
    public function __invoke(SetLeaveDayRequest $request, SetOfficeLeaveDay $action): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // SetLeaveDayRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administered($request->user(), $request->string('office_id')->toString())
            ?? throw new NotFoundHttpException;

        $updated = $action->execute(new SetOfficeLeaveDayInput(
            officeId: $office->id,
            minutesPerLeaveDay: $request->integer('minutes_per_leave_day'),
        ));

        return response()->json(['data' => [
            'id' => $updated->id,
            'minutes_per_leave_day' => $updated->minutes_per_leave_day,
        ]]);
    }
}
