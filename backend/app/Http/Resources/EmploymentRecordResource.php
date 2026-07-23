<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmploymentRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmploymentRecord */
final class EmploymentRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'effective_from' => $this->effective_from?->toDateString(),
            'office_id' => $this->office_id,
            'department_id' => $this->department_id,
            'reports_to_id' => $this->reports_to_id,
            'employment_type' => $this->employment_type,
            'is_art82_exempt' => $this->is_art82_exempt,
            'base_rate_cents' => $this->base_rate_cents,
        ];
    }
}
