<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShiftTemplate */
final class ShiftTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'name' => $this->name,
            'days' => $this->days
                ->sortBy(fn ($day) => $day->weekday->value)
                ->values()
                ->map(fn ($day) => [
                    'weekday' => $day->weekday->value,
                    'is_rest' => $day->is_rest,
                    'start_minute' => $day->start_minute,
                    'end_minute' => $day->end_minute,
                    'break_minutes' => $day->break_minutes,
                ])
                ->all(),
        ];
    }
}
