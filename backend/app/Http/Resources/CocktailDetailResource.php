<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * カクテルの詳細(GET /api/cocktails/{id}・GET /api/admin/cocktails/{id}/preview。#40)。
 *
 * 説明文(description)・補足(note)は、保存した記法(`@n<ID>@` など)のまま返す。リンクの区切りの並びにするのは #286。
 * 画像(image)は保存した値のまま返す(public ディスクのファイル名か、外部の URL。AdminImageStore)。
 *
 * @property Cocktail $resource
 */
class CocktailDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cocktail = $this->resource->loadMissing([
            'baseCategory', 'method', 'glass', 'ice', 'ingredients.category', 'ingredients.brand',
        ]);
        // 技法は外部キー(NOT NULL・RESTRICT)で必ずある
        $method = $cocktail->method ?? throw new LogicException("カクテル {$cocktail->id} の技法が無い");
        $glass = $cocktail->glass;
        $ice = $cocktail->ice;

        return [
            'id' => $cocktail->id,
            'name' => $cocktail->name,
            'name_eng' => $cocktail->name_eng,
            'is_draft' => $cocktail->is_draft,
            'is_major' => $cocktail->is_major,
            'base' => NodeRefResource::make($cocktail->baseCategory),
            'method' => [
                'id' => $method->id,
                'name' => $method->name,
                'name_eng' => $method->name_eng,
                // 技法を説明する用語の ID(用語の詳細へのリンク先)。用語の無い技法では null
                'dictionary_id' => $method->dictionary_id,
            ],
            // グラスは用語辞典の項目なので、ID は用語の詳細へのリンク先になる
            'glass' => $glass === null ? null : [
                'id' => $glass->id,
                'name' => $glass->name,
                'name_eng' => $glass->name_eng,
            ],
            'ice' => $ice === null ? null : [
                'id' => $ice->id,
                'name' => $ice->name,
                'name_eng' => $ice->name_eng,
            ],
            // 度数(%)。列の名前は volume だが、旧の API と画面は strength と呼んでいた
            'strength' => (float) $cocktail->volume,
            'sweet_degree' => $cocktail->sweet_degree,
            'rate' => $cocktail->rate,
            'description' => $cocktail->description,
            'note' => $cocktail->note,
            'youtube' => $cocktail->youtube,
            'youtube_created_at' => $cocktail->youtube_created_at,
            'youtube_start_seconds' => $cocktail->youtube_start_seconds,
            'instagram' => $cocktail->instagram,
            'image' => $cocktail->image,
            'image_txt' => $cocktail->image_txt,
            'image_url' => $cocktail->image_url,
            'created_at' => $cocktail->created_at,
            'updated_at' => $cocktail->updated_at,
            'ingredients' => CocktailIngredientResource::collection($cocktail->ingredients),
        ];
    }
}
