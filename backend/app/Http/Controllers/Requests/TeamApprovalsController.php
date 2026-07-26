<?php

declare(strict_types=1);

namespace App\Http\Controllers\Requests;

use App\Domain\Requests\ApprovalQueues;
use App\Http\Resources\RequestResource;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The manager queue: pending requests from the actor's direct reports. Scope enforced by
 *  ApprovalQueues::directReportsOf (an out-of-scope request simply isn't in the set). */
final class TeamApprovalsController
{
    public function __invoke(HttpRequest $http): AnonymousResourceCollection
    {
        return RequestResource::collection(ApprovalQueues::directReportsOf($http->user())->get());
    }
}
