<?php

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 名前の横断検索(GET /api/search。#172)で見つかった銘柄 1 つ。
 *
 * 旧の FromNameSearch\BrandResource が返していた項目のうち、銘柄の表(旧の Results/Tables/Brands.vue)が使うもの。
 * 旧のカテゴリとミドルの 2 つは、銘柄がぶら下がるノード 1 つになる。
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
        $brand = $this->resource->loadMissing('category');

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_eng' => $brand->name_eng,
            'volume' => (float) $brand->volume,
            'youtube' => $brand->youtube,
            'category' => NodeRefResource::make($brand->category),
        ];
    }
}
