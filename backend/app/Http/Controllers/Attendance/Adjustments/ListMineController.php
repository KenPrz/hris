<?php

declare(strict_types=1);

namespace App\Http\Controllers\Attendance\Adjustments;

use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListMineController
{
    public function __invoke(HttpRequest $http): AnonymousResourceCollection
    {
        $employee = $http->user()->employee;

        if ($employee === null) {
            throw new NotAnEmployee();
        }

        $requests = Request::query()
            ->where('employee_id', $employee->id)
            ->latest()
            ->get();

        return RequestResource::collection($requests);
    }
}
