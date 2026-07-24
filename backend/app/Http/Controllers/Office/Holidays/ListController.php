<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Domain\Scope\OfficeScope;
use App\Http\Resources\HolidayResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ListController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        // 404, not 403: an out-of-scope office and a nonexistent one must be
        // indistinguishable to the caller (the 404-not-403 discipline).
        $office = OfficeScope::administeredBy($request->user())->find($request->query('office'));

        if ($office === null) {
            throw new NotFoundHttpException;
        }

        $holidays = $office->holidays()
            ->whereYear('date', $request->query('year'))
            ->orderBy('date')
            ->get();

        return HolidayResource::collection($holidays);
    }
}
