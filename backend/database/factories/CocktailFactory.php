<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Cocktail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ベースのノードは CategoryFactory で作る(親子を持たない単体のノード)。
 *
 * methods の表はまだ無く(#23)、method_id には外部キーが無いので、行の無い ID を入れている。
 * 外部キーを足したら、MethodFactory で作るように変える。
 *
 * @extends Factory<Cocktail>
 */
class CocktailFactory extends Factory
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
            'base_category_id' => Category::factory(),
            'method_id' => 1,
            'glass_dictionary_id' => null,
            'ice_id' => null,
            'volume' => fake()->randomFloat(1, 0, 40),
            'is_draft' => false,
            'is_major' => false,
        ];
    }
}
