<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\LeaveTypes;

use App\Actions\Leave\CreateLeaveType;
use App\Actions\Leave\CreateLeaveTypeInput;
use App\Domain\Scope\OfficeScope;
use App\Http\Requests\CreateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateController
{
    public function __invoke(CreateLeaveTypeRequest $request, CreateLeaveType $action): JsonResponse
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline). This is why
        // CreateLeaveTypeRequest validates office_id as shape only (uuid), never `exists`.
        $office = OfficeScope::administered($request->user(), $request->string('office_id')->toString())
            ?? throw new NotFoundHttpException;

        $leaveType = $action->execute(new CreateLeaveTypeInput(
            officeId: $office->id,
            name: $request->string('name')->toString(),
            code: $request->has('code') ? $request->string('code')->toString() : null,
            isPaid: $request->boolean('is_paid'),
            requiresAttachment: $request->boolean('requires_attachment'),
            deductsBalance: $request->boolean('deducts_balance'),
            isCashConvertible: $request->boolean('is_cash_convertible'),
            maxCarryoverMinutes: $request->has('max_carryover_minutes') ? $request->integer('max_carryover_minutes') : null,
            isActive: $request->has('is_active') ? $request->boolean('is_active') : true,
        ));

        return LeaveTypeResource::make($leaveType)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
