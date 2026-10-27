<?php

namespace App\Http\Resources;

use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * 用語辞典の一覧の、分類 1 つと、その用語(GET /api/dictionaries。#46)。
 *
 * @property DictionaryCategory $resource
 */
class DictionaryCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->id,
            'name' => $category->name,
            'name_eng' => $category->name_eng,
            'dictionaries' => DictionaryRefResource::collection(self::sortByName($category->dictionaries)->values()),
        ];
    }

    /**
     * 用語を、旧の一覧の画面と同じ名前の順にする。旧の画面(Dictionary/index.vue の sortedList)は、全角の英数字を
     * 半角に、カタカナをひらがなにそろえた名前を、文字のコードの順に並べていた。同じ名前は ID の順。
     *
     * @param  Collection<int, Dictionary>  $dictionaries
     * @return Collection<int, Dictionary>
     */
    private static function sortByName(Collection $dictionaries): Collection
    {
        $key = fn (Dictionary $dictionary): string => mb_convert_kana($dictionary->name, 'rnc', 'UTF-8');

        return $dictionaries->sort(
            fn (Dictionary $a, Dictionary $b): int => strcmp($key($a), $key($b)) ?: $a->id <=> $b->id,
        );
    }
}
