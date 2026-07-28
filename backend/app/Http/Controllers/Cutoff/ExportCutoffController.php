<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cutoff;

use App\Domain\Cutoff\CutoffState;
use App\Domain\Payroll\PayrollExport;
use App\Domain\Scope\OfficeScope;
use App\Exceptions\Domain\PeriodNotExportable;
use App\Http\Resources\PayrollExportResource;
use App\Models\CutoffPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The payroll export for a CLOSED cutoff period. 404-not-403 for a foreign/nonexistent period
 * (route-binding + OfficeScope land in the same NotFoundHttpException); an OPEN period is refused
 * with PeriodNotExportable — an export is only defined for a finalized period.
 */
final class ExportCutoffController
{
    public function __invoke(Request $request, CutoffPeriod $period): PayrollExportResource
    {
        if (! OfficeScope::administers($request->user(), $period->office_id)) {
            throw new NotFoundHttpException;
        }

        if ($period->state !== CutoffState::Closed) {
            throw new PeriodNotExportable($period->id, $period->state->value);
        }

        return PayrollExportResource::make(PayrollExport::for($period));
    }
}
