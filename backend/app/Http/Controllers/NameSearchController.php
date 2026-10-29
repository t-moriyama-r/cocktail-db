<?php

namespace App\Http\Controllers;

use App\Http\Requests\NameSearchRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Http\Resources\NameSearchBrandResource;
use App\Http\Resources\NameSearchNodeResource;
use App\Services\Search\NameSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NameSearchController extends Controller
{
    /**
     * 名前で、カクテル・ノード・銘柄を種類ごとに探す。
     *
     * 英数字だけの入力なら英名(name_eng)と検索用の名前(name_search)、それ以外は検索用の名前を部分一致で探す。
     * 空白(半角・全角)で分けた語は、どれかに当たれば一致(旧と同じ OR)。語は検索用の名前と同じ形にそろえてから
     * 探す。下書きのカクテルと、木の根(旧の種別)は返さない。種類ごとに ID の順で、ページに分けない(旧と同じ)。
     *
     * @return array{cocktails: AnonymousResourceCollection, nodes: AnonymousResourceCollection, brands: AnonymousResourceCollection}
     */
    public function __invoke(NameSearchRequest $request, NameSearch $search): array
    {
        $found = $search->search($request->string('q')->toString());

        return [
            'cocktails' => CocktailSummaryResource::collection($found['cocktails']),
            'nodes' => NameSearchNodeResource::collection($found['nodes']),
            'brands' => NameSearchBrandResource::collection($found['brands']),
        ];
    }
}
