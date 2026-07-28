<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
final class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'name' => $this->name,
            'code' => $this->code,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
