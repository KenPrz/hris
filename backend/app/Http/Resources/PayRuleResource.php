<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayRule */
final class PayRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effective_from' => $this->effective_from->toDateString(),
            'overtime_ordinary_bp' => $this->overtime_ordinary_bp,
            'overtime_premium_bp' => $this->overtime_premium_bp,
            'night_diff_bp' => $this->night_diff_bp,
            'note' => $this->note,
            'day_rates' => $this->dayRates
                ->sortBy(fn ($rate): string => $rate->day_type->value)
                ->values()
                ->map(fn ($rate): array => [
                    'day_type' => $rate->day_type->value,
                    'worked_bp' => $rate->worked_bp,
                    'worked_rest_bp' => $rate->worked_rest_bp,
                    'unworked_bp' => $rate->unworked_bp,
                ])
                ->all(),
        ];
    }
}
