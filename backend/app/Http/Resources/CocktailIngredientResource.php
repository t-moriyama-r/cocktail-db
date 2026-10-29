<?php

namespace App\Http\Resources;

use App\Models\CocktailIngredient;
use App\Services\CategoryTree;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * カクテルの詳細の材料 1 つ(GET /api/cocktails/{id}。#40)。
 *
 * 木の深さは決まっていないので、材料のノードは根からのパンくずで返す(末尾が材料のノード)。旧はカテゴリ・ミドルの
 * 2 段を別の項目で返していた。どの段を画面に出すかは画面が決める(#283 の 3)。
 *
 * @property CocktailIngredient $resource
 */
class CocktailIngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource->brand;
        // 材料のノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $this->resource->category
            ?? throw new LogicException("カクテル {$this->resource->cocktail_id} の材料 {$this->resource->position} のノードが無い");

        return [
            'position' => $this->resource->position,
            'amount' => $this->resource->amount,
            'is_optional' => $this->resource->is_optional,
            // 根が先頭で、材料のノードが末尾
            'path' => NodeRefResource::collection(app(CategoryTree::class)->path($node)),
            'brand' => $brand === null ? null : [
                'id' => $brand->id,
                'name' => $brand->name,
                'name_eng' => $brand->name_eng,
            ],
        ];
    }
}
