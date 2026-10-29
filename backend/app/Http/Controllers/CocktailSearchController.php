<?php

namespace App\Http\Controllers;

use App\Http\Requests\CocktailSearchRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Models\Cocktail;
use App\Services\Search\CocktailSearch;
use App\Services\Search\CocktailSearchSort;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class CocktailSearchController extends Controller
{
    /**
     * 条件に当たるカクテルを、一覧の表の行の形で 1 ページずつ返す。当たらなければ空の data。
     *
     * 並びは `sort`・`order` で選ぶ(省くと ID の昇順)。同じ値のものは ID の昇順。値の無いもの(甘さ・評価・英名が無い、
     * 動画が無い)は、向きによらず最後。旧は全件を返して画面で並べ替えていた(docs/search.md の「返すもの」「並べ替え(画面)」)。
     * 最後のページより後は、空の data を返す。
     */
    public function __invoke(CocktailSearchRequest $request, CocktailSearch $search, CocktailSearchSort $sort): AnonymousResourceCollection
    {
        // 方式は検証で or・having だけを受けている
        $query = $request->isHaving()
            ? $search->queryMakeable($request->categoryIds(), $request->methodIds(), $request->isEasy())
            : $search->query($request->categoryIds(), $request->methodIds(), $request->isEasy());
        $ids = $sort->sortedIds($query, $request->sort(), $request->order());

        $page = $request->page();
        $perPage = $request->perPage();
        // 最後のページより後は、件数との掛け算で桁があふれないよう、切り出さずに空にする
        $pageIds = $page - 1 <= intdiv(count($ids), $perPage)
            ? array_slice($ids, ($page - 1) * $perPage, $perPage)
            : [];

        $position = array_flip($pageIds);
        $cocktails = Cocktail::query()
            ->with(['baseCategory', 'method'])
            ->whereIn('id', $pageIds)
            ->get()
            ->sortBy(fn (Cocktail $cocktail): int => $position[$cocktail->id])
            ->values();

        $paginator = new LengthAwarePaginator(
            $cocktails,
            count($ids),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return CocktailSummaryResource::collection($paginator);
    }
}
