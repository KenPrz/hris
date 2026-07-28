<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payroll\PayrollEarningsLine;
use App\Domain\Payroll\PayrollEmployeeExport;
use App\Domain\Payroll\PayrollExportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollExportData */
final class PayrollExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'period' => [
                'id' => $this->period->id,
                'office_id' => $this->period->office_id,
                'start_date' => $this->period->start_date->toDateString(),
                'end_date' => $this->period->end_date->toDateString(),
                'state' => $this->period->state->value,
            ],
            'employees' => array_map(fn (PayrollEmployeeExport $e): array => [
                'employee' => [
                    'id' => $e->employeeId,
                    'employee_no' => $e->employeeNo,
                    'base_rate_cents' => $e->baseRateCents,
                ],
                'base_rate_segments' => $e->baseRateSegments,
                'totals' => [
                    'worked_minutes' => $e->workedMinutes,
                    'late_minutes' => $e->lateMinutes,
                    'undertime_minutes' => $e->undertimeMinutes,
                    'unpaid_overtime_minutes' => $e->unpaidOvertimeMinutes,
                ],
                'lines' => array_map(fn (PayrollEarningsLine $l): array => [
                    'kind' => $l->kind->value,
                    'applied_bp' => $l->appliedBp,
                    'rule_version_id' => $l->ruleVersionId,
                    'minutes' => $l->minutes,
                ], $e->lines),
                'has_incomplete_days' => $e->hasIncompleteDays,
            ], $this->employees),
        ];
    }
}
