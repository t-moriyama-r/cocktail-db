<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use App\Support\DescriptionParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 新着動画のカクテル(GET /api/cocktails/latest-video。#39)。トップの動画の枠に出す項目。
 *
 * 説明文(description)は、文字とリンクの区切りの並び(DescriptionParser。#286)にして返す。
 *
 * @property Cocktail $resource
 */
class LatestVideoCocktailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cocktail = $this->resource->loadMissing('baseCategory');

        return [
            'id' => $cocktail->id,
            'name' => $cocktail->name,
            'name_eng' => $cocktail->name_eng,
            'youtube' => $cocktail->youtube,
            'youtube_created_at' => $cocktail->youtube_created_at,
            'base' => NodeRefResource::make($cocktail->baseCategory),
            // 型は DescriptionParser::parse の戻り値。Scramble が app() の戻り値から型を読めないので、ここに書く
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'description' => app(DescriptionParser::class)->parse($cocktail->description),
        ];
    }
}
