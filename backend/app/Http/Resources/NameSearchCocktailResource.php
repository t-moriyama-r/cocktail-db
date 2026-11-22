<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 名前の横断検索(GET /api/search。#172)で、名前か別名(#82)が一致したカクテル 1 つ。
 *
 * 一覧の表と同じ項目(CocktailSummaryResource)に、当たった別名(matched_alias)を足す。名前で当たったカクテルは null。
 * 当たった別名は NameSearch が属性 matched_alias に入れる。
 *
 * @property Cocktail $resource
 */
class NameSearchCocktailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $matchedAlias = $this->resource->getAttribute('matched_alias');

        return [
            ...CocktailSummaryResource::make($this->resource)->toArray($request),
            /** @var string|null */
            'matched_alias' => is_string($matchedAlias) ? $matchedAlias : null,
        ];
    }
}
