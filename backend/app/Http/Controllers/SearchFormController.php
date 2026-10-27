<?php

namespace App\Http\Controllers;

use App\Http\Resources\SearchFormMethodResource;
use App\Http\Resources\SearchFormNodeResource;
use App\Models\Method;
use App\Services\CategoryTree;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchFormController extends Controller
{
    /**
     * 検索フォームに出すカテゴリの木と技法の一覧を返す。
     *
     * 木は根から一番深い段まで入れ子で返す(docs/schema.md の「検索フォーム(#95)に出す深さ」)。根も子も兄弟の並び順。
     * カクテルに使われているかでは絞らず、下書きのカクテルでしか使っていないノードも返す(旧の getKindsForForm も
     * すべてのカテゴリ・ミドルを返していた)。技法は ID の順。
     *
     * @return array{categories: AnonymousResourceCollection, methods: AnonymousResourceCollection}
     */
    public function __invoke(CategoryTree $tree): array
    {
        return [
            'categories' => SearchFormNodeResource::collection($tree->forest()),
            'methods' => SearchFormMethodResource::collection(Method::query()->orderBy('id')->get()),
        ];
    }
}
