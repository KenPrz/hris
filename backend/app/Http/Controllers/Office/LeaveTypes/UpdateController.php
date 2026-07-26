<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\LeaveTypes;

use App\Actions\Leave\UpdateLeaveType;
use App\Actions\Leave\UpdateLeaveTypeInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateController
{
    public function __invoke(UpdateLeaveTypeRequest $request, LeaveType $leaveType, UpdateLeaveType $action): JsonResponse
    {
        // 404, not 403: a leave type whose office the caller doesn't administer must be
        // indistinguishable from a nonexistent {leaveType} (which route-binding already
        // 404s on its own). The scope check lives here, not in the request, so both
        // paths land in the same NotFoundHttpException.
        if (! OfficeScope::administers($request->user(), $leaveType->office_id)) {
            throw new NotFoundHttpException;
        }

        $updated = $action->execute($leaveType, new UpdateLeaveTypeInput(
            name: $request->string('name')->toString(),
            code: $request->has('code') ? $request->string('code')->toString() : null,
            isPaid: $request->boolean('is_paid'),
            requiresAttachment: $request->boolean('requires_attachment'),
            deductsBalance: $request->boolean('deducts_balance'),
            isCashConvertible: $request->boolean('is_cash_convertible'),
            maxCarryoverMinutes: $request->has('max_carryover_minutes') ? $request->integer('max_carryover_minutes') : null,
            isActive: $request->has('is_active') ? $request->boolean('is_active') : $leaveType->is_active,
        ));

        return LeaveTypeResource::make($updated)->response();
    }
}
