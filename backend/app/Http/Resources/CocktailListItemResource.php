<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use Illuminate\Http\Request;

/**
 * トップのカクテルの一覧(GET /api/cocktails。#39)の 1 件。一覧の表(#298)の 1 行の形。
 *
 * 同じ名前の一覧(CocktailSummaryResource)と同じ項目に、技法の英名を足したもの(#39 の追記)。
 *
 * @property Cocktail $resource
 */
class CocktailListItemResource extends CocktailSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method = $this->resource->loadMissing('method')->requiredMethod();

        return [
            ...parent::toArray($request),
            'method' => [
                'id' => $method->id,
                'name' => $method->name,
                'name_eng' => $method->name_eng,
            ],
        ];
    }
}
