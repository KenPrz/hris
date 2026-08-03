<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            // The backed value, not the enum instance — 'employee' / 'office' / null.
            'applies_to' => $this->applies_to?->value,
            'is_required' => $this->is_required,
            'validity_months' => $this->validity_months,
        ];
    }
}
