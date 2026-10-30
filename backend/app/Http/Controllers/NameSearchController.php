<?php

namespace App\Http\Controllers;

use App\Http\Requests\NameSearchRequest;
use App\Http\Resources\CocktailSummaryResource;
use App\Http\Resources\NameSearchBrandResource;
use App\Http\Resources\NameSearchCocktailResource;
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
     * カクテルは別名(#82。旧に無い)の検索用の名前でも探す。名前では当たらず別名で当たったカクテルは、当たった別名を
     * matched_alias に返す(2 つ以上当たれば先に登録した方。名前で当たったカクテルは null)。
     *
     * ingredient_matches は、語を材料に使うカクテル(旧のインテリジェンスサーチ。#173)。語ごとに、名前が語そのものか
     * 語に「リキュール」(英数字だけの入力なら英名で、語に liqueur)を付けたものと完全一致するノード・銘柄を探し、
     * ノードならそのノードと一致する材料(配下と、根を除く祖先)を、銘柄ならその銘柄を使うカクテルを返す。
     * 語の間は AND で、どれかの語で何も見つからなければ 0 件。
     *
     * @return array{cocktails: AnonymousResourceCollection, nodes: AnonymousResourceCollection, brands: AnonymousResourceCollection, ingredient_matches: AnonymousResourceCollection}
     */
    public function __invoke(NameSearchRequest $request, NameSearch $search): array
    {
        $found = $search->search($request->string('q')->toString());

        return [
            'cocktails' => NameSearchCocktailResource::collection($found['cocktails']),
            'nodes' => NameSearchNodeResource::collection($found['nodes']),
            'brands' => NameSearchBrandResource::collection($found['brands']),
            'ingredient_matches' => CocktailSummaryResource::collection($found['ingredient_matches']),
        ];
    }
}
