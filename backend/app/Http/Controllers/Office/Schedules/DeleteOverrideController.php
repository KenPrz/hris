<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office\Schedules;

use App\Actions\Schedules\DeleteScheduleOverride;
use App\Domain\Scope\OfficeScope;
use App\Models\ScheduleOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteOverrideController
{
    public function __invoke(Request $request, ScheduleOverride $override, DeleteScheduleOverride $action): Response
    {
        // 404, not 403: same office-scope discipline as UpdateOverrideController — an
        // override whose employee's office the caller doesn't administer 404s exactly
        // like a nonexistent {override}.
        if (! OfficeScope::administers($request->user(), $override->employee->current_office_id)) {
            throw new NotFoundHttpException;
        }

        $action->execute($override);

        return response()->noContent();
    }
}
