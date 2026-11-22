<?php

namespace App\Http\Resources\Admin;

use App\Models\Dictionary;
use App\Models\DictionaryCategory;
use App\Support\AdminListName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面の用語辞典の一覧の、分類 1 つと、その用語(GET /api/admin/dictionaries。#315)。
 * 公開の一覧(#46)の分類のリソースと名前が重ならないよう、List を付けている。
 *
 * 用語は、旧の管理画面の一覧(旧 DictionaryList.vue の sortedList)と同じ名前の順にする。全角の英数字を半角に、
 * カタカナをひらがなにそろえた名前を(小文字にはせず)比べ、同じ名前は ID の順。
 *
 * @property DictionaryCategory $resource
 */
class DictionaryListCategoryResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, name_eng: string, dictionaries: list<array{id: int, name: string, name_eng: string}>}
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        $dictionaries = array_values($category->dictionaries
            ->sortBy('id')
            ->sortBy(fn (Dictionary $d): string => AdminListName::caseSensitiveSortKey($d->name), SORT_STRING)
            ->map(fn (Dictionary $d): array => ['id' => $d->id, 'name' => $d->name, 'name_eng' => $d->name_eng])
            ->all());

        return [
            'id' => $category->id,
            'name' => $category->name,
            'name_eng' => $category->name_eng,
            // 用語(名前の順)。用語の無い分類は空の並び
            /** @var list<array{id: int, name: string, name_eng: string}> */
            'dictionaries' => $dictionaries,
        ];
    }
}
