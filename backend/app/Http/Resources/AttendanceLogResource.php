<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AttendanceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceLog */
final class AttendanceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'office_id' => $this->office_id,
            'punched_at' => $this->punched_at?->toIso8601String(),
            'direction' => $this->direction->value,
            'source' => $this->source->value,
            'verification' => $this->verification->value,
            'flag_reason' => $this->flag_reason,
        ];
    }
}
