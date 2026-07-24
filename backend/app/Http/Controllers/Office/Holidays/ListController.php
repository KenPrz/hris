<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Domain\Scope\OfficeScope;
use App\Http\Requests\ListHolidaysRequest;
use App\Http\Resources\HolidayResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListController
{
    public function __invoke(ListHolidaysRequest $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administeredOrFail($request->user(), $request->string('office')->toString());

        $holidays = $office->holidays()
            ->whereYear('date', $request->query('year'))
            ->orderBy('date')
            ->get();

        return HolidayResource::collection($holidays);
    }
}
