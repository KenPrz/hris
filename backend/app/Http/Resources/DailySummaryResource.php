<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DailyAttendanceSummary;
use App\Models\DailySummaryLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyAttendanceSummary */
final class DailySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date->toDateString(),
            'day_type' => $this->day_type->value,
            'is_rest_day' => $this->is_rest_day,
            'scheduled_minutes' => $this->scheduled_minutes,
            'is_art82_exempt' => $this->is_art82_exempt,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'undertime_minutes' => $this->undertime_minutes,
            'status' => $this->status,
            'is_incomplete' => $this->is_incomplete,
            'rule_version_id' => $this->rule_version_id,
            'lines' => $this->lines
                ->sortBy(fn (DailySummaryLine $line): string => $line->kind->value)
                ->values()
                ->map(fn (DailySummaryLine $line): array => [
                    'kind' => $line->kind->value,
                    'minutes' => $line->minutes,
                    'applied_bp' => $line->applied_bp,
                ])
                ->all(),
        ];
    }
}
