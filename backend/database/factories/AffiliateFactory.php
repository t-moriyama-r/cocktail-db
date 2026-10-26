<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ノードの行(brand_id が null)を作る。商品の値はどれも空で、取り込む前の行になる。
 *
 * @extends Factory<Affiliate>
 */
class AffiliateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'brand_id' => null,
        ];
    }
}
