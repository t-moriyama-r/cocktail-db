<?php

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * 名前の横断検索(GET /api/search。#172)で見つかった銘柄 1 つ。
 *
 * 旧の FromNameSearch\BrandResource が返していた項目のうち、銘柄の表(旧の Results/Tables/Brands.vue)が使うもの。
 * 旧のカテゴリとミドルの 2 つは、銘柄がぶら下がるノード 1 つになる。
 * 結果の画面(#52)の銘柄の表が所属の列を出せるよう、所属ノードの根からのパンくず(path)も返す。
 *
 * @property Brand $resource
 */
class NameSearchBrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource->loadMissing(['category.ancestors' => fn (BelongsToMany $ancestors) => $ancestors->orderByPivot('depth', 'desc')]);
        // 所属ノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $brand->category ?? throw new LogicException("銘柄 {$brand->id} の所属ノードが無い");

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_eng' => $brand->name_eng,
            'volume' => (float) $brand->volume,
            'youtube' => $brand->youtube,
            'category' => NodeRefResource::make($node),
            // 根が先頭で、所属ノードが末尾(BrandSummaryResource の path と同じ並び)
            'path' => NodeRefResource::collection($node->ancestors->concat([$node])),
        ];
    }
}
