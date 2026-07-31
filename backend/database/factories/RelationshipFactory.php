<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Relationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Relationship> */
final class RelationshipFactory extends Factory
{
    protected $model = Relationship::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->lexify('rel_?????'),
            'description' => $this->faker->word(),
        ];
    }
}
