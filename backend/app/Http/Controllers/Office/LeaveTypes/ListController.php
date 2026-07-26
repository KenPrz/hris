<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\LeaveTypes;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListLeaveTypesRequest;
use App\Http\Resources\LeaveTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListController
{
    public function __invoke(ListLeaveTypesRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administered($request->user(), $request->string('office')->toString())
            ?? throw new NotFoundHttpException;

        $leaveTypes = $office->leaveTypes()
            ->orderBy('name')
            ->get();

        return LeaveTypeResource::collection($leaveTypes);
    }
}
