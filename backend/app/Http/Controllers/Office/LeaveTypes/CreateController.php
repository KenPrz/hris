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
            // input(), not string()/integer() behind a has() check: has() is true for an
            // explicit JSON null, and string()/integer() would then coerce it to '' / 0 —
            // silently flipping "no code"/"unlimited carryover" into "empty code string"/
            // "zero carryover". Both fields are already validated nullable|string /
            // nullable|integer, so input() is either null or the correct type as-is.
            code: $request->input('code'),
            isPaid: $request->boolean('is_paid'),
            requiresAttachment: $request->boolean('requires_attachment'),
            deductsBalance: $request->boolean('deducts_balance'),
            isCashConvertible: $request->boolean('is_cash_convertible'),
            maxCarryoverMinutes: $request->input('max_carryover_minutes') !== null ? (int) $request->input('max_carryover_minutes') : null,
            isActive: $request->has('is_active') ? $request->boolean('is_active') : true,
        ));

        return LeaveTypeResource::make($leaveType)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
