<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Cocktail;
use App\Models\CocktailIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定では銘柄を決めない材料を作る。position は主キーの一部で、同じカクテルの中でぶつかると入らないため、
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

    /**
     * 銘柄を決めた材料。材料のノードは、銘柄の所属ノードと同じにする(docs/schema.md の「材料が銘柄を持つときのノード」)。
     */
    public function forBrand(Brand $brand): static
    {
        return $this->state([
            'brand_id' => $brand->id,
            'category_id' => $brand->category_id,
        ]);
    }
}
