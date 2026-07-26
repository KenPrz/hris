<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveLedger */
final class LeaveLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'entry_type' => $this->entry_type,
            'minutes' => $this->minutes,
            'reason' => $this->reason,
            'source' => $this->source,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
