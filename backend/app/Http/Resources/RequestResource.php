<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Requests\RequestType;
use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Request */
final class RequestResource extends JsonResource
{
    public function toArray(HttpRequest $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'state' => $this->state->value,
            'note' => $this->note,
            'employee_id' => $this->employee_id,
            'detail' => match ($this->type) {
                RequestType::AttendanceAdjustment => $this->attendanceAdjustmentDetail === null ? null : [
                    'operation' => $this->attendanceAdjustmentDetail->operation->value,
                    'target_log_id' => $this->attendanceAdjustmentDetail->target_log_id,
                    'direction' => $this->attendanceAdjustmentDetail->direction?->value,
                    'punched_at' => $this->attendanceAdjustmentDetail->punched_at?->toIso8601String(),
                ],
                RequestType::Leave => $this->leaveDetail === null ? null : [
                    'leave_type_id' => $this->leaveDetail->leave_type_id,
                    'start_date' => $this->leaveDetail->start_date->toDateString(),
                    'end_date' => $this->leaveDetail->end_date->toDateString(),
                    'day_part' => $this->leaveDetail->day_part,
                    'amount_minutes' => $this->leaveDetail->amount_minutes,
                ],
            },
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'has_attachment' => $this->hasMedia('attachment'),
        ];
    }
}
