<?php

declare(strict_types=1);

namespace App\Http\Controllers\Requests;

use App\Actions\Requests\CancelRequest;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

// Cancel has no body to validate, so there is no FormRequest — only requester-identity
// (checked inside CancelRequest against the row-locked request; 404 if the actor is not
// the requester) matters. Same reasoning as ApproveController about which test proves it.
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
