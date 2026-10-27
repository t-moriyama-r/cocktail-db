<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 所属するノードは CategoryFactory で作る(親子を持たない単体のノード)。
 *
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'name_search' => $name,
            'name_eng' => $name,
            'volume' => fake()->randomFloat(1, 0, 96),
            'description' => null,
            'is_major' => false,
            'is_like' => false,
        ];
    }
}
