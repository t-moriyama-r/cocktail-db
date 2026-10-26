<?php

namespace Database\Factories;

use App\Models\LegacyIdMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyIdMap>
 */
class LegacyIdMapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legacy_table' => 'm_category',
            'legacy_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'new_table' => 'categories',
            'new_id' => fake()->numberBetween(1, 1_000_000),
        ];
    }
}
