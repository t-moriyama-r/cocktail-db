<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * カクテルの一覧の表(#298)の 1 行に出すカクテル(GET /api/cocktails/{id}/same-name。#40)。
 *
 * 旧の一覧の API(CocktailSearch\CocktailResource)が返していた項目のうち、表が使うもの。
 *
 * @property Cocktail $resource
 */
class CocktailSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cocktail = $this->resource->loadMissing(['baseCategory', 'method']);
        $method = $cocktail->requiredMethod();

        return [
            'id' => $cocktail->id,
            'name' => $cocktail->name,
            'name_eng' => $cocktail->name_eng,
            'is_major' => $cocktail->is_major,
            'base' => NodeRefResource::make($cocktail->baseCategory),
            'method' => [
                'id' => $method->id,
                'name' => $method->name,
            ],
            'recipe_count' => $cocktail->recipe_count,
            'strength' => $cocktail->strength(),
            'sweet_degree' => $cocktail->sweet_degree,
            'rate' => $cocktail->rate,
            'youtube' => $cocktail->youtube,
            'updated_at' => $cocktail->updated_at,
        ];
    }
}
