<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Employment\EmploymentResolver;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Employee */
final class EmployeeDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // "Current" is resolved the same way the pay engine will: the employment record
        // whose effective_from covers today, per EmploymentResolver — never a denormalized
        // guess. A brand-new employee with no employment record yet gets null, not an error.
        $current = EmploymentResolver::on($this->resource, Carbon::today());

        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name_suffix' => $this->name_suffix,
            'full_name' => $this->full_name,
            'hired_at' => $this->hired_at?->toDateString(),
            'has_user' => $this->user_id !== null,
            'current_employment' => $current === null ? null : [
                'office_id' => $current->office_id,
                'department_id' => $current->department_id,
                'employment_type' => $current->employment_type,
                'is_art82_exempt' => $current->is_art82_exempt,
                'base_rate_cents' => $current->base_rate_cents,
                'reports_to_id' => $current->reports_to_id,
                'effective_from' => $current->effective_from?->toDateString(),
            ],
        ];
    }
}
