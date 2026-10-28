<?php

namespace App\Http\Controllers;

use App\Http\Resources\DictionaryCategoryResource;
use App\Models\DictionaryCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DictionaryIndexController extends Controller
{
    /**
     * 用語辞典の一覧。分類を ID の順に、分類ごとの用語を名前の順で返す(docs/decisions/dictionary.md)。
     *
     * 用語の無い分類も、旧と同じく空の並びで返す(出すかどうかは画面が決める)。
     */
    public function __invoke(): AnonymousResourceCollection
    {
        $categories = DictionaryCategory::query()
            ->with('dictionaries')
            ->orderBy('id')
            ->get();

        return DictionaryCategoryResource::collection($categories);
    }
}
