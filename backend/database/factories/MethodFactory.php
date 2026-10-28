<?php

namespace Database\Factories;

use App\Models\Method;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Method>
 */
class MethodFactory extends Factory
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
            'description' => fake()->sentence(),
        ];
    }
}
