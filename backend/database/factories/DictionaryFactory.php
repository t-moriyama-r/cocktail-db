<?php

namespace Database\Factories;

use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dictionary>
 */
class DictionaryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'dictionary_category_id' => DictionaryCategory::factory(),
            'name' => $name,
            'name_eng' => $name,
            'name_search' => $name,
            'description' => fake()->sentence(),
        ];
    }
}
