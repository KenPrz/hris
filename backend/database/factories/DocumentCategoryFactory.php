<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentCategory> */
final class DocumentCategoryFactory extends Factory
{
    protected $model = DocumentCategory::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper($this->faker->unique()->lexify('CAT_?????')),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
        ];
    }
}
