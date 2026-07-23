<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Actions\Requests\RejectRequest;
use App\Http\Requests\RejectAdjustmentRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\JsonResponse;

// See ApproveController — same authorization boundary (RequestAuthority, inside the
// action), same arch-test exemption reasoning.
final class RejectController
{
    // $http is the FormRequest; $request is the route-bound App\Models\Request.
    public function __invoke(RejectAdjustmentRequest $http, Request $request, RejectRequest $action): JsonResponse
    {
        $decisionNote = $http->input('decision_note');

        $result = $action->execute($request, $http->user(), is_string($decisionNote) ? $decisionNote : null);

        return RequestResource::make($result)->response();
    }
}
