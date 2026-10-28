<?php

namespace App\Http\Resources;

use App\Models\Cocktail;
use App\Support\DescriptionParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * カクテルの詳細(GET /api/cocktails/{id}・GET /api/admin/cocktails/{id}/preview。#40)。
 *
 * 説明文(description)・補足(note)は、文字とリンクの区切りの並び(DescriptionParser。#286)にして返す。
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
        $method = $cocktail->requiredMethod();
        $glass = $cocktail->glass;
        $ice = $cocktail->ice;
        $parser = app(DescriptionParser::class);

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
            'strength' => $cocktail->strength(),
            'sweet_degree' => $cocktail->sweet_degree,
            'rate' => $cocktail->rate,
            // 型は DescriptionParser::parse の戻り値。Scramble が app() の戻り値から型を読めないので、ここに書く
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'description' => $parser->parse($cocktail->description),
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'note' => $parser->parse($cocktail->note),
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
