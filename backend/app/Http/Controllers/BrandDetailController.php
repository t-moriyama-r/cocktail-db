<?php

namespace App\Http\Controllers;

use App\Http\Resources\BrandDetailResource;
use App\Models\Brand;

class BrandDetailController extends Controller
{
    /**
     * 銘柄の詳細を返す。基本情報・所属ノードの根からのパンくずと、銘柄の一覧 2 つ。
     *
     * 銘柄の一覧は、同じノードのほかの銘柄と、親ノードの配下で別のノードに所属する銘柄(docs/decisions/public-detail.md の決まったこと 2)。
     * 無い銘柄は 404(旧は 500 だった。docs/decisions/brand-detail.md)。
     */
    public function __invoke(int $brand): BrandDetailResource
    {
        return BrandDetailResource::make(Brand::query()->findOrFail($brand));
    }
}
