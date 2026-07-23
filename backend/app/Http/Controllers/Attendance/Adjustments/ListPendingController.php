<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Domain\Requests\RequestState;
use App\Domain\Scope\EmployeeScope;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListPendingController
{
    public function __invoke(HttpRequest $http): AnonymousResourceCollection
    {
        $user = $http->user();

        // In-scope-minus-self, exactly the set RequestAuthority::canDecide() would accept
        // one request at a time: the requester is visible to this approver under
        // EmployeeScope, and the approver is not the requester themself. A user with no
        // employee (a bare system-admin account) has employee?->id === null, which turns
        // the exclusion into a no-op whereNotNull('employee_id') — there is no "self" to
        // exclude, so nothing legitimate is filtered out.
        $requests = Request::query()
            ->where('state', RequestState::Pending)
            ->whereIn('employee_id', EmployeeScope::visibleTo($user)->pluck('id'))
            ->where('employee_id', '!=', $user->employee?->id)
            ->latest()
            ->get();

        return RequestResource::collection($requests);
    }
}
