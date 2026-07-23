<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Actions\Requests\CancelRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

// Cancel has no body to validate, so there is no FormRequest — only requester-identity
// (checked inside CancelRequest against the row-locked request; 404 if the actor is not
// the requester) matters. Same arch-test exemption reasoning as ApproveController.
final class CancelController
{
    // $http is the plain HTTP request (aliased so it doesn't collide with the bound
    // App\Models\Request below); $request is the route-bound model.
    public function __invoke(HttpRequest $http, Request $request, CancelRequest $action): JsonResponse
    {
        $result = $action->execute($request, $http->user());

        return RequestResource::make($result)->response();
    }
}
