<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveType */
final class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'name' => $this->name,
            'code' => $this->code,
            'is_paid' => $this->is_paid,
            'requires_attachment' => $this->requires_attachment,
            'deducts_balance' => $this->deducts_balance,
            'is_cash_convertible' => $this->is_cash_convertible,
            'max_carryover_minutes' => $this->max_carryover_minutes,
            'is_active' => $this->is_active,
        ];
    }
}
