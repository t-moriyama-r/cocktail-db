<?php

namespace Database\Factories;

use App\Models\Cocktail;
use App\Models\CocktailAlias;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 検索用の名前(name_search)は、名前を NameNormalizer でそろえた値にする。
 *
 * @extends Factory<CocktailAlias>
 */
class CocktailAliasFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('????? ?????');

        return [
            'cocktail_id' => Cocktail::factory(),
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
        ];
    }

    /**
     * 名前を決めた別名。name_search も名前からそろえる。
     */
    public function named(string $name): static
    {
        return $this->state([
            'name' => $name,
            'name_search' => NameNormalizer::normalize($name),
        ]);
    }
}
