<?php

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * ノードの詳細(#42)・銘柄の詳細(#43)の銘柄の一覧に出す銘柄 1 つ。
 *
 * 旧の銘柄の表(Results/Tables/Brands.vue。旧 BrandResource)が出していた項目。旧は所属をカテゴリ・ミドルの 2 段の
 * 項目で返していたが、木の深さは決まっていないので、所属ノードの根からのパンくずで返す(カクテルの詳細の材料と同じ)。
 * どの段を画面に出すかは画面が決める(docs/decisions/public-detail.md の決まったこと 3)。
 *
 * 一覧の銘柄は、withPath で所属ノードとその祖先をまとめて引いてから渡す(引いていなければここで引く)。
 *
 * @property Brand $resource
 */
class BrandSummaryResource extends JsonResource
{
    /**
     * $query の銘柄に、所属ノードとその祖先(根から順)を一緒に引かせる。
     *
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public static function withPath(Builder $query): Builder
    {
        return $query->with(['category.ancestors' => fn (BelongsToMany $ancestors) => $ancestors->orderByPivot('depth', 'desc')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource->loadMissing(['category.ancestors' => fn (BelongsToMany $ancestors) => $ancestors->orderByPivot('depth', 'desc')]);
        // 所属ノードは外部キー(NOT NULL・RESTRICT)で必ずある
        $node = $brand->category ?? throw new LogicException("銘柄 {$brand->id} の所属ノードが無い");
        // 同じノードの銘柄はノードを共有するので、引いた祖先の並びは書き換えない(concat は写しを返す)
        $path = $node->ancestors->concat([$node]);

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_eng' => $brand->name_eng,
            // 度数(%)
            'strength' => (float) $brand->volume,
            'youtube' => $brand->youtube,
            // 根が先頭で、所属ノードが末尾
            'path' => NodeRefResource::collection($path),
        ];
    }
}
