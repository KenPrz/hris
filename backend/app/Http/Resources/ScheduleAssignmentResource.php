<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScheduleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScheduleAssignment */
final class ScheduleAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift_template_id' => $this->shift_template_id,
            'employee_id' => $this->employee_id,
            'department_id' => $this->department_id,
            'effective_from' => $this->effective_from->toDateString(),
        ];
    }
}
