<?php

declare(strict_types=1);

namespace App\Http\Controllers\Requests;

use App\Domain\Requests\ApprovalQueues;
use App\Http\Resources\RequestResource;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The HR queue: pending requests from members of the offices the actor HR-administers.
 *  Scope enforced by ApprovalQueues::hrOfficesOf (an out-of-scope request simply isn't in
 *  the set). */
final class OfficeApprovalsController
{
    public function __invoke(HttpRequest $http): AnonymousResourceCollection
    {
        return RequestResource::collection(ApprovalQueues::hrOfficesOf($http->user())->get());
    }
}
