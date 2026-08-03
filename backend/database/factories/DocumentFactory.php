<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('DOC_?????')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'category_id' => DocumentCategory::factory(),
            'applies_to' => null,
            'is_required' => false,
            'validity_months' => null,
        ];
    }
}
