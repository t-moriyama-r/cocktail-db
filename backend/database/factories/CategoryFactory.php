<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * categories の行だけを作る。閉包表(category_closures)の行は書き換える場所が CategoryTree(#24)に
 * 限られているので作らず、親子を持たない単体のノードになる。
 *
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'name_search' => $name,
            'name_eng' => $name,
            'description' => null,
            'role' => null,
            'is_major' => false,
            'is_like' => false,
            'is_powder' => false,
            'famous_brand_id' => null,
            'sort_order' => 0,
        ];
    }
}
