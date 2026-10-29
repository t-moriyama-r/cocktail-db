<?php

namespace App\Http\Resources\Admin;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理画面の銘柄の一覧の 1 行(GET /api/admin/brands。#314)。
 *
 * 所属のパンくずと有名な銘柄かは、一覧の 1 ページ分をまとめて引いたもの(BrandPlacement)を、
 * 銘柄の属性 placement に入れて渡す。
 *
 * @property Brand $resource
 */
class BrandListItemResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, name_eng: string, category_path: list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}>, is_famous_brand: bool}
     */
    public function toArray(Request $request): array
    {
        /** @var array{path: list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}>, is_famous_brand: bool} $placement */
        $placement = $this->resource->getAttribute('placement');

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'name_eng' => $this->resource->name_eng,
            // 所属ノードのパンくず(根を除き、浅い順)。ノードごとに、その有名な銘柄がこの銘柄か
            /** @var list<array{id: int, name: string, name_eng: string, is_famous_brand: bool}> */
            'category_path' => $placement['path'],
            // どこかのノードの有名な銘柄になっているか
            /** @var bool */
            'is_famous_brand' => $placement['is_famous_brand'],
        ];
    }
}
