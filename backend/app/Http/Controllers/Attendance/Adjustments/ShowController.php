<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Domain\Requests\RequestAuthority;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowController
{
    // $http is the plain HTTP request; $request is the route-bound App\Models\Request
    // (the parameter name must match the {request} route segment for implicit binding).
    public function __invoke(HttpRequest $http, Request $request): JsonResponse
    {
        $employee = $http->user()->employee;

        // 404, not 403: visible to the requester themself, or to an approver authorized
        // under RequestAuthority (a query-scope check on the requester, not the
        // requester). An unrelated caller must see the same response a nonexistent id
        // would produce.
        $isRequester = $employee !== null && $request->employee_id === $employee->id;

        if (! $isRequester && ! RequestAuthority::canDecide($http->user(), $request)) {
            throw new NotFoundHttpException();
        }

        return RequestResource::make($request)->response();
    }
}
