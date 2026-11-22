<?php

namespace App\Http\Resources\Admin;

use App\Models\CocktailIngredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * 重複チェックで並べるカクテルの材料の 1 行(CocktailDuplicateResource の ingredients。#129)。旧 RecipeResourceForEdit と
 * 同じく、画面で見比べるための名前を添える。
 *
 * @property CocktailIngredient $resource
 */
class CocktailDuplicateIngredientResource extends JsonResource
{
    /**
     * @return array{category_id: int, category_name: string, brand_id: int|null, brand_name: string|null, amount: string, is_optional: bool}
     */
    public function toArray(Request $request): array
    {
        $ingredient = $this->resource;
        // 材料のノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $ingredient->category
            ?? throw new LogicException("カクテル {$ingredient->cocktail_id} の材料 {$ingredient->position} のノードが無い");

        return [
            // 材料のノード(いちばん深いノード)
            'category_id' => $ingredient->category_id,
            'category_name' => $node->name,
            // 銘柄。銘柄を決めない材料では null
            'brand_id' => $ingredient->brand_id,
            /** @var string|null */
            'brand_name' => $ingredient->brand?->name,
            // 量(整えた後の値)
            'amount' => $ingredient->amount,
            // 任意の材料か
            'is_optional' => $ingredient->is_optional,
        ];
    }
}
