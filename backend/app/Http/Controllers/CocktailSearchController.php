<?php

namespace App\Http\Controllers;

use App\Http\Requests\CocktailSearchRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Services\Search\CocktailSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CocktailSearchController extends Controller
{
    /**
     * 条件に当たるカクテルを、一覧の表の行の形で ID の昇順にすべて返す。当たらなければ空の配列。
     *
     * ページングと並べ替えは #167 で足す。旧も条件に合うものを全件返していた(docs/search.md の「返すもの」)。
     */
    public function __invoke(CocktailSearchRequest $request, CocktailSearch $search): AnonymousResourceCollection
    {
        // 方式は検証で or・and だけを受けている
        $query = $request->isAnd()
            ? $search->queryEach($request->categoryGroups())
            : $search->query($request->categoryIds());
        $cocktails = $query
            ->with(['baseCategory', 'method'])
            ->get();

        return CocktailSummaryResource::collection($cocktails);
    }
}
