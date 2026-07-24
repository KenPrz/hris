<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Holidays;

use App\Actions\Holidays\DeleteHoliday;
use App\Domain\Scope\OfficeScope;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteController
{
    public function __invoke(Request $request, Holiday $holiday, DeleteHoliday $action): Response
    {
        // 404, not 403: same office-scope discipline as UpdateController — a holiday
        // whose office the caller doesn't administer 404s exactly like a nonexistent
        // {holiday}.
        $administers = OfficeScope::administeredBy($request->user())
            ->whereKey($holiday->office_id)
            ->exists();

        if (! $administers) {
            throw new NotFoundHttpException;
        }

        $action->execute($holiday);

        return response()->noContent();
    }
}
