<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * categories の行だけを作る。閉包表(category_closures)の行は作らないので、親子を持たない単体のノードになる。
 * 閉包表を書き換えてよいのは CategoryTree(#24)だけのため、木を組むときはそちらを通す。
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
