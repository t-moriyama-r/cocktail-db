<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowAffiliateRequest;
use App\Http\Resources\AffiliateResource;
use App\Models\Affiliate;
use App\Models\Category;

class AffiliateController extends Controller
{
    /**
     * ノード・銘柄の詳細の、商品の欄に出すアフィリエイトの 1 件を返す。
     *
     * 選び方は旧(AffiliateController@getAffiliateData)と同じ。銘柄を指定したらその銘柄の行。指定しなければ、ノードに
     * 有名な銘柄があればその銘柄の行、無ければノードの行。行の無いノード・銘柄、無い ID は 404(旧は 500 だった)。
     */
    public function __invoke(ShowAffiliateRequest $request): AffiliateResource
    {
        $brandId = $request->brandId()
            ?? Category::query()->findOrFail($request->categoryId())->famous_brand_id;

        $affiliate = $brandId !== null
            ? Affiliate::query()->where('brand_id', $brandId)->firstOrFail()
            : Affiliate::query()->where('category_id', $request->categoryId())->whereNull('brand_id')->firstOrFail();

        return AffiliateResource::make($affiliate);
    }
}
