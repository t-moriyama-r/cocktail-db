<?php

namespace App\Http\Resources\Admin;

use App\Models\CocktailIngredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面のカクテルの編集で開く材料の 1 行(CocktailResource の ingredients。#292)。
 *
 * 保存のとき(SaveCocktailRequest の ingredients)に送る形のまま返す。量は整えた後の値(30 は 30ml)。
 *
 * @property CocktailIngredient $resource
 */
class CocktailIngredientResource extends JsonResource
{
    /**
     * @return array{category_id: int, brand_id: int|null, amount: string, is_optional: bool}
     */
    public function toArray(Request $request): array
    {
        $ingredient = $this->resource;

        return [
            // 材料のノード
            'category_id' => $ingredient->category_id,
            // 銘柄。銘柄を決めない材料では null
            'brand_id' => $ingredient->brand_id,
            // 量(整えた後の値)
            'amount' => $ingredient->amount,
            // 任意の材料か
            'is_optional' => $ingredient->is_optional,
        ];
    }
}
