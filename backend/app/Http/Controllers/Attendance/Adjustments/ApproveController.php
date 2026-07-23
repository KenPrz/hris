<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Actions\Requests\ApproveRequest;
use App\Http\Requests\ApproveAdjustmentRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\JsonResponse;

// Authorization boundary for this controller is RequestAuthority, enforced inside
// ApproveRequest against the row-locked request (404 if the approver is out of scope or
// is the requester themselves). This file is exempted by name in
// tests/Arch/ConventionsTest.php's Attendance-scope guard, whose two grep patterns can't
// see an action-layer check; tests/Feature/Attendance/AdjustmentTransitionsTest.php
// proves the boundary instead.
final class ApproveController
{
    // $http is the FormRequest; $request is the route-bound App\Models\Request (the
    // parameter name must match the {request} route segment for implicit model binding).
    public function __invoke(ApproveAdjustmentRequest $http, Request $request, ApproveRequest $action): JsonResponse
    {
        $result = $action->execute($request, $http->user());

        return RequestResource::make($result)->response();
    }
}
