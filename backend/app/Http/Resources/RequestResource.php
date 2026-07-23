<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Request */
final class RequestResource extends JsonResource
{
    public function toArray(HttpRequest $request): array
    {
        $detail = $this->attendanceAdjustmentDetail;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'state' => $this->state->value,
            'note' => $this->note,
            'employee_id' => $this->employee_id,
            'detail' => $detail === null ? null : [
                'operation' => $detail->operation->value,
                'target_log_id' => $detail->target_log_id,
                'direction' => $detail->direction?->value,
                'punched_at' => $detail->punched_at?->toIso8601String(),
            ],
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'has_attachment' => $this->hasMedia('attachment'),
        ];
    }
}
