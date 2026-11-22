<?php

namespace App\Http\Resources;

use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Models\Method;
use App\Support\DescriptionParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 用語の詳細(GET /api/dictionaries/{id}。#46)。
 *
 * 種類(kind)は、技法に結び付いた用語(`methods.dictionary_id`)なら method、分類「グラス」の用語なら glass、
 * それ以外は other。技法なら、その技法の ID(method_id)も返す。旧は分類の名前で判定し、技法の ID と用語の ID が
 * たまたま同じことに頼っていた(docs/decisions/dictionary.md)。
 *
 * 説明文(description)は、文字とリンクの区切りの並び(DescriptionParser。#286)にして返す(ノード・銘柄の詳細と同じ。#542)。
 * 画像(image)は保存した値のまま返す(カクテルの詳細と同じ)。
 *
 * @property Dictionary $resource
 */
class DictionaryDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dictionary = $this->resource;
        $category = $dictionary->requiredCategory();
        $methodId = Method::query()->where('dictionary_id', $dictionary->id)->orderBy('id')->value('id');
        $methodId = is_numeric($methodId) ? (int) $methodId : null;

        return [
            'id' => $dictionary->id,
            'name' => $dictionary->name,
            'name_eng' => $dictionary->name_eng,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_eng' => $category->name_eng,
            ],
            'kind' => match (true) {
                $methodId !== null => 'method',
                $category->id === DictionaryCategory::GLASS_ID => 'glass',
                default => 'other',
            },
            // 技法の用語なら、その技法の ID(技法から探すカクテルの条件に使う)。技法でなければ null
            'method_id' => $methodId,
            // 型は DescriptionParser::parse の戻り値。Scramble が app() の戻り値から型を読めないので、ここに書く
            /** @var list<array{type: 'text', text: string}|array{type: 'node'|'cocktail'|'brand'|'dictionary', id: int, text: string, has_youtube: bool}>|null */
            'description' => app(DescriptionParser::class)->parse($dictionary->description),
            'youtube' => $dictionary->youtube,
            'youtube_start_seconds' => $dictionary->youtube_start_seconds,
            'image' => $dictionary->image,
            'image_txt' => $dictionary->image_txt,
            'image_url' => $dictionary->image_url,
        ];
    }
}
