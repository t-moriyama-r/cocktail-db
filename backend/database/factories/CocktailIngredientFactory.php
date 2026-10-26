<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 銘柄を決めない材料を作る。position は主キーの一部で、同じカクテルの中でぶつかると入らないため、
 * 決めて渡す(既定の値を持たない)。
 *
 * @extends Factory<CocktailIngredient>
 */
class CocktailIngredientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cocktail_id' => Cocktail::factory(),
            'category_id' => Category::factory(),
            'brand_id' => null,
            'amount' => '30ml',
            'is_optional' => false,
        ];
    }
}
