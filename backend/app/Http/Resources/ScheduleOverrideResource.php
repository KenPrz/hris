<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScheduleOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScheduleOverride */
final class ScheduleOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date->toDateString(),
            'is_rest' => $this->is_rest,
            'start_minute' => $this->start_minute,
            'end_minute' => $this->end_minute,
            'break_minutes' => $this->break_minutes,
            'note' => $this->note,
        ];
    }
}
