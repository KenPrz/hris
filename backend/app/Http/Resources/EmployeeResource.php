<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
final class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'name_suffix' => $this->name_suffix,
            'full_name' => $this->full_name,
            'current_office_id' => $this->current_office_id,
            'current_department_id' => $this->current_department_id,
            'current_reports_to_id' => $this->current_reports_to_id,
            'hired_at' => $this->hired_at?->toDateString(),
        ];
    }
}
