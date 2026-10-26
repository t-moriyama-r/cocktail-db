<?php

namespace Database\Factories;

use App\Models\DictionaryCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DictionaryCategory>
 */
class DictionaryCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'name_eng' => $name,
        ];
    }
}
